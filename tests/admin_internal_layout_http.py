#!/usr/bin/env python3
"""Read-only checks that admin controller success and error views choose the internal layout."""
import http.cookiejar
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser
from urllib.parse import urlsplit

EXPECTED_DATABASE = 'verdant_test_phase013_baseline'
BASE = os.getenv('HTTP_TEST_BASE_URL', 'http://127.0.0.1:8095').rstrip('/')
ADMIN_PAGES = [
    '/admin', '/admin/products', '/admin/categories', '/admin/families', '/admin/compatibility',
    '/admin/compositions', '/admin/customers', '/admin/orders', '/admin/rentals', '/admin/sections',
]
ERROR_PAGES = ['/admin/customer?id=0', '/admin/order?id=0', '/admin/rental?id=0']


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Forms(HTMLParser):
    def __init__(self):
        super().__init__()
        self.current = None
        self.forms = []
        self.body_classes = set()

    def handle_starttag(self, tag, attrs):
        values = dict(attrs)
        if tag == 'body':
            self.body_classes = set(values.get('class', '').split())
        if tag == 'form':
            self.current = {'action': values.get('action', ''), 'method': values.get('method', 'get')}
        elif tag == 'input' and self.current is not None and values.get('name'):
            self.current[values['name']] = values.get('value', '')

    def handle_endtag(self, tag):
        if tag == 'form' and self.current is not None:
            self.forms.append(self.current)
            self.current = None


def opener():
    jar = http.cookiejar.CookieJar()
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect())


STORE_HOST = '127.0.0.1:8088'
ADMIN_HOST = 'admin.verdant.localhost:8088'


def request(client, path, data=None, host=None):
    body = None if data is None else urllib.parse.urlencode(data).encode()
    if host is None:
        host = ADMIN_HOST if (path.startswith('/admin') ) else STORE_HOST
    try:
        response = client.open(urllib.request.Request(BASE + path, data=body, headers={'Host': host}), timeout=8)
    except urllib.error.HTTPError as error:
        response = error
    return response.code, response.headers, response.read().decode('utf-8', errors='replace')


def login(client, email, admin_host=False):
    host = ADMIN_HOST if admin_host else STORE_HOST
    _, _, body = request(client, '/login', None, host)
    parser = Forms()
    parser.feed(body)
    form = next((row for row in parser.forms if row['action'] == '/login'), {})
    status, headers, _ = request(client, '/login', {'csrf': form.get('csrf', ''), 'email': email,
                                                    'password': 'VerdantDemo!2026'}, host)
    location = headers.get('Location', '')
    if location in ('/admin/2fa', '/admin/2fa/setup'):
        return complete_admin_2fa(client, email, location)
    return status


def complete_admin_2fa(client, email, location):
    import admin_2fa

    def token_for(body, action):
        parser = Forms()
        parser.feed(body)
        return next((row for row in parser.forms if row['action'] == action), {}).get('csrf', '')

    if location == '/admin/2fa/setup':
        status, _, body = request(client, '/admin/2fa/setup')
        assert status == 200 and 'Set up two-factor' in body, 'Two-factor setup did not load.'
        secret = admin_2fa.credential_secret(EXPECTED_DATABASE, email)
        status, _, body = request(client, '/admin/2fa/setup',
                                  {'csrf': token_for(body, '/admin/2fa/setup'),
                                   'code': admin_2fa.current_code(secret)})
        assert status == 200 and 'Save your backup codes' in body, 'Two-factor setup did not complete.'
    status, headers, body = request(client, '/admin/2fa')
    if status == 303:
        return status
    assert status == 200, 'Two-factor challenge did not load.'
    secret = admin_2fa.credential_secret(EXPECTED_DATABASE, email)
    status, headers, _ = request(client, '/admin/2fa',
                                 {'csrf': token_for(body, '/admin/2fa'),
                                  'code': admin_2fa.current_code(secret)})
    assert status == 303 and headers.get('Location') == '/admin', 'Two-factor challenge did not sign in.'
    return status


def has_internal_layout(body):
    parser = Forms()
    parser.feed(body)
    return 'layout-internal' in parser.body_classes and body.count('class="admin-nav"') == 1


def main():
    parts = urlsplit(BASE)
    if (os.getenv('APP_ENV') != 'local' or os.getenv('DB_NAME') != EXPECTED_DATABASE
            or os.getenv('HTTP_TEST_DATABASE') != EXPECTED_DATABASE):
        raise RuntimeError('Use only APP_ENV=local and the named Phase 013 baseline fixture database.')
    if (parts.scheme != 'http' or parts.hostname != '127.0.0.1' or parts.port != 8095
            or parts.path or parts.query or parts.fragment or parts.username or parts.password):
        raise RuntimeError('Use the Phase 013 loopback HTTP server on 127.0.0.1:8095.')

    checks = 0

    def check(condition, label):
        nonlocal checks
        if not condition:
            raise AssertionError(label)
        checks += 1
        print('PASS:', label)

    anonymous, customer, admin = opener(), opener(), opener()
    for path in ADMIN_PAGES:
        code, headers, _ = request(anonymous, path)
        check(code == 303 and headers.get('Location', '').startswith('/login'), 'Anonymous admin route stays protected: ' + path)
    check(login(customer, 'customer@example.test') == 303, 'Synthetic customer signs in.')
    for path in ADMIN_PAGES:
        code, headers, _ = request(customer, path)
        check(code == 303 and headers.get('Location', '').startswith('/login'), 'Customer remains denied: ' + path)
    check(login(admin, 'admin@example.test', True) == 303, 'Synthetic administrator signs in.')
    for path in ADMIN_PAGES:
        code, _, body = request(admin, path)
        check(code == 200 and has_internal_layout(body), 'Admin page explicitly renders one internal nav: ' + path)
    for path in ERROR_PAGES:
        code, _, body = request(admin, path)
        check(code == 404 and has_internal_layout(body), 'Admin error page retains internal layout: ' + path)
    _, _, body = request(admin, '/admin')
    check('href="/admin/families"' in body and 'href="/admin/compatibility"' in body
          and 'href="/admin/sections"' in body, 'Internal navigation includes the complete admin destinations.')
    print(f'Admin internal-layout HTTP checks: {checks} passed, 0 failed.')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('Admin internal-layout HTTP checks failed:', error, file=sys.stderr)
        sys.exit(1)
