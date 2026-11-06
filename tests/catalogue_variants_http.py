#!/usr/bin/env python3
"""Admin family/compatibility route checks for the isolated Phase 012 loopback app."""
import http.cookiejar
import json
import os
import re
import secrets
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser

BASE = 'http://127.0.0.1:8095'


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Forms(HTMLParser):
    def __init__(self):
        super().__init__()
        self.forms = []
        self.current = None

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'form':
            self.current = {'action': attrs.get('action', '')}
        elif tag == 'input' and self.current is not None and attrs.get('name'):
            self.current[attrs['name']] = attrs.get('value', '')

    def handle_endtag(self, tag):
        if tag == 'form' and self.current is not None:
            self.forms.append(self.current)
            self.current = None


def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())


STORE_HOST = '127.0.0.1:8088'
ADMIN_HOST = 'admin.verdant.localhost:8088'


def request(c, path, fields=None, host=None):
    data = None if fields is None else urllib.parse.urlencode(fields).encode()
    if host is None:
        host = ADMIN_HOST if (path.startswith('/admin') ) else STORE_HOST
    try:
        response = c.open(urllib.request.Request(BASE + path, data=data, headers={'Host': host}), timeout=8)
    except urllib.error.HTTPError as error:
        response = error
    return response.code, response.headers, response.read().decode()


def parse_forms(body):
    parser = Forms()
    parser.feed(body)
    return parser.forms


def form(body, action):
    return next((item for item in parse_forms(body) if item['action'] == action), {})


def login(c, email, password, admin_host=False):
    host = ADMIN_HOST if admin_host else STORE_HOST
    _, _, body = request(c, '/login', None, host)
    token = form(body, '/login').get('csrf', '')
    status, headers, _ = request(c, '/login', {'csrf': token, 'email': email, 'password': password}, host)
    location = headers.get('Location', '')
    if location in ('/admin/2fa', '/admin/2fa/setup'):
        return complete_admin_2fa(c, email, location)
    return status


def complete_admin_2fa(c, email, location):
    import admin_2fa
    db = os.getenv('HTTP_TEST_DATABASE')
    if location == '/admin/2fa/setup':
        status, _, body = request(c, '/admin/2fa/setup')
        assert status == 200 and 'Set up two-factor' in body, 'Two-factor setup did not load.'
        secret = admin_2fa.credential_secret(db, email)
        token = form(body, '/admin/2fa/setup').get('csrf', '')
        status, _, body = request(c, '/admin/2fa/setup',
                                  {'csrf': token, 'code': admin_2fa.current_code(secret)})
        assert status == 200 and 'Save your backup codes' in body, 'Two-factor setup did not complete.'
    status, headers, body = request(c, '/admin/2fa')
    if status == 303:
        return status
    assert status == 200, 'Two-factor challenge did not load.'
    secret = admin_2fa.credential_secret(db, email)
    token = form(body, '/admin/2fa').get('csrf', '')
    status, headers, _ = request(c, '/admin/2fa', {'csrf': token, 'code': admin_2fa.current_code(secret)})
    assert status == 303 and headers.get('Location') == '/admin', 'Two-factor challenge did not sign in.'
    return status


def main():
    if os.getenv('APP_ENV') != 'local' or os.getenv('HTTP_TEST_DATABASE') != 'verdant_test_phase012':
        raise RuntimeError('Use only the explicitly named isolated Phase 012 HTTP test database.')
    fixture = json.loads(subprocess.check_output(
        ['php', os.path.join(os.path.dirname(__file__), 'catalogue_variants_http_fixture.php')], text=True, timeout=10,
    ))

    checks = 0

    def check(condition, label):
        nonlocal checks
        if not condition:
            raise AssertionError(label)
        checks += 1
        print('PASS:', label)

    paths = ['/admin/families', '/admin/compatibility']
    anonymous = client()
    for path in paths:
        code, headers, _ = request(anonymous, path)
        check(code == 303 and headers.get('Location') == '/login', 'Anonymous access redirects: ' + path)

    customer = client()
    check(login(customer, fixture['customer_email'], fixture['customer_password']) == 303, 'Synthetic customer signs in.')
    _, _, account = request(customer, '/account')
    customer_csrf = form(account, '/logout').get('csrf', '')
    check(bool(customer_csrf), 'Customer has a session CSRF token.')
    for path in paths:
        code, headers, _ = request(customer, path)
        check(code == 303 and headers.get('Location', '').startswith('/login'), 'Customer cannot read: ' + path)
    _, _, customer_admin_login = request(customer, '/login', None, ADMIN_HOST)
    customer_admin_token = form(customer_admin_login, '/login').get('csrf', '')
    code, headers, _ = request(customer, '/admin/family/save',
                               {'csrf': customer_admin_token, 'name': 'Denied', 'product_kind': 'plant'})
    check(code == 303 and headers.get('Location', '').startswith('/login'),
          'Customer with valid CSRF cannot mutate a family.')

    admin = client()
    check(login(admin, fixture['admin_email'], fixture['admin_password'], True) == 303, 'Synthetic administrator signs in.')
    for path in paths:
        check(request(admin, path)[0] == 200, 'Administrator reads: ' + path)
    _, _, product_form = request(admin, '/admin/product')
    check('name="family_id"' in product_form and 'name="variant_label"' in product_form
          and 'name="accessory_subtype"' in product_form,
          'Product form exposes family assignment, variant label and explicit accessory type.')

    post_paths = ['/admin/family/save', '/admin/family/archive', '/admin/compatibility/save', '/admin/compatibility/archive']
    for path in post_paths:
        check(request(admin, path)[0] == 405, 'Mutation rejects GET: ' + path)
        check(request(admin, path, {})[0] == 419, 'Mutation rejects missing CSRF: ' + path)

    code, _, body = request(admin, '/admin/families')
    csrf = form(body, '/admin/family/save').get('csrf', '')
    family_code = 'http-variant-' + secrets.token_hex(5)
    created, headers, _ = request(admin, '/admin/family/save', {
        'csrf': csrf, 'family_code': family_code, 'name': 'Synthetic HTTP family', 'product_kind': 'plant',
    })
    check(code == 200 and created == 303 and headers.get('Location') == '/admin/families?saved=1',
          'Administrator creates a product family through the protected form.')
    _, _, body = request(admin, '/admin/families')
    check('Synthetic HTTP family' in body, 'Created family appears in the protected family list.')
    row = re.search(
        r'<tr><th scope="row">Synthetic HTTP family</th><td>' + re.escape(family_code)
        + r'</td>.*?</tr>', body, re.DOTALL,
    )
    family_id = re.search(r'name="family_id" value="([0-9]+)"', row.group(0)) if row else None
    archive_form = next((item for item in parse_forms(body)
                         if item['action'] == '/admin/family/archive' and item.get('archive') == '1'
                         and family_id is not None and item.get('family_id') == family_id.group(1)), {})
    check(bool(archive_form), 'New unassigned family has a protected archive form.')
    check(request(admin, '/admin/family/archive', archive_form)[0] == 303,
          'Administrator archives an unassigned family without deleting its history.')
    archive_form['archive'] = '0'
    check(request(admin, '/admin/family/archive', archive_form)[0] == 303,
          'Administrator restores the archived family.')

    print(f'Catalogue variant HTTP checks: {checks} passed, 0 failed.')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('Catalogue variant HTTP checks failed:', error, file=sys.stderr)
        sys.exit(1)
