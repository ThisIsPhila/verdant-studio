#!/usr/bin/env python3
"""Protected media-editor HTTP checks against the Phase 013 isolated test fixture."""
import http.cookiejar
import os
import re
import sys
import json
import subprocess
from pathlib import Path
import urllib.error
import urllib.parse
import urllib.request

DATABASE = 'verdant_test_phase014'
BASE = os.getenv('HTTP_TEST_BASE_URL', 'http://127.0.0.1:8096').rstrip('/')


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None


def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())


STORE_HOST = '127.0.0.1:8088'
ADMIN_HOST = 'admin.verdant.localhost:8088'


def request(opener, path, fields=None, host=None):
    data = None if fields is None else urllib.parse.urlencode(fields).encode()
    if host is None:
        host = ADMIN_HOST if (path.startswith('/admin') ) else STORE_HOST
    try:
        response = opener.open(urllib.request.Request(BASE + path, data=data, headers={'Host': host}), timeout=8)
    except urllib.error.HTTPError as error:
        response = error
    return response.code, response.headers, response.read().decode('utf-8', errors='replace')


def login(opener, email, admin_host=False):
    host = ADMIN_HOST if admin_host else STORE_HOST
    _, _, body = request(opener, '/login', None, host)
    token = re.search(r'<form[^>]+action="/login"[^>]*>.*?name="csrf" value="([a-f0-9]+)"', body, re.S)
    if token is None:
        raise AssertionError('Login form does not provide CSRF token.')
    status, headers, _ = request(opener, '/login', {'csrf': token.group(1), 'email': email,
                                                    'password': 'VerdantMediaHttp!2026'}, host)
    location = headers.get('Location', '')
    if location in ('/admin/2fa', '/admin/2fa/setup'):
        return complete_admin_2fa(opener, email, location)
    return status


def token_for(body, action):
    match = re.search(r'<form[^>]+action="' + re.escape(action) + r'"[^>]*>.*?name="csrf" value="([a-f0-9]+)"',
                      body, re.S)
    if match is None:
        raise AssertionError('Form did not load for ' + action + '.')
    return match.group(1)


def complete_admin_2fa(opener, email, location):
    import admin_2fa
    if location == '/admin/2fa/setup':
        status, _, body = request(opener, '/admin/2fa/setup')
        assert status == 200 and 'Set up two-factor' in body, 'Two-factor setup did not load.'
        secret = admin_2fa.credential_secret(DATABASE, email)
        status, _, body = request(opener, '/admin/2fa/setup',
                                  {'csrf': token_for(body, '/admin/2fa/setup'),
                                   'code': admin_2fa.current_code(secret)})
        assert status == 200 and 'Save your backup codes' in body, 'Two-factor setup did not complete.'
    status, headers, body = request(opener, '/admin/2fa')
    if status == 303:
        return status
    assert status == 200, 'Two-factor challenge did not load.'
    secret = admin_2fa.credential_secret(DATABASE, email)
    status, headers, _ = request(opener, '/admin/2fa',
                                 {'csrf': token_for(body, '/admin/2fa'),
                                  'code': admin_2fa.current_code(secret)})
    assert status == 303 and headers.get('Location') == '/admin', 'Two-factor challenge did not sign in.'
    return status


def main():
    parts = urllib.parse.urlsplit(BASE)
    if (os.getenv('APP_ENV') != 'local' or os.getenv('DB_NAME') != DATABASE
            or os.getenv('HTTP_TEST_DATABASE') != DATABASE):
        raise RuntimeError('Use APP_ENV=local and the named Phase 013 isolated test fixture.')
    if parts.scheme != 'http' or parts.hostname != '127.0.0.1' or parts.port != 8096 or parts.path:
        raise RuntimeError('Use only the loopback test server on 127.0.0.1:8096.')
    fixture_script = Path(__file__).with_name('admin_media_http_fixture.php')
    fixture = json.loads(subprocess.check_output(['php', str(fixture_script), 'create'], text=True, timeout=10))
    checks = 0

    def check(condition, message):
        nonlocal checks
        if not condition:
            raise AssertionError(message)
        checks += 1
        print('PASS:', message)

    try:
        anonymous, customer, admin = client(), client(), client()
        code, headers, _ = request(anonymous, '/admin/media')
        check(code == 303 and headers.get('Location', '').startswith('/login'), 'Anonymous visitor is redirected from media admin.')
        code = login(customer, fixture['emails'][0])
        check(code == 303, f'Synthetic customer signs in (HTTP {code}).')
        code, headers, _ = request(customer, '/admin/media')
        check(code == 303 and headers.get('Location', '').startswith('/login'), 'Signed-in customer cannot read media admin.')
        code = login(admin, fixture['emails'][1], True)
        check(code == 303, f'Synthetic administrator signs in (HTTP {code}).')
        code, _, body = request(admin, '/admin/media')
        title = re.search(r'<h1>(.*?)</h1>', body, re.S)
        check(code == 200 and 'class="layout-internal"' in body and 'class="admin-nav"' in body,
              f'Administrator sees the protected media editor in the internal layout (HTTP {code}; title={title.group(1) if title else "missing"}).')
        check('Registered local assets' in body and 'Assignment history' not in body,
              'Registry is visible and history is scoped to a selected assignment.')
        check('name="image_url"' not in body and 'type="file"' not in body and 'password_hash' not in body,
              'Editor contains no URL/upload control and exposes no credential fields.')
        check(request(admin, '/admin/media/save')[0] == 405, 'Save endpoint rejects GET.')
        check(request(admin, '/admin/media/save', {})[0] == 419, 'Save endpoint rejects missing CSRF.')
        csrf = re.search(r'<input type="hidden" name="csrf" value="([a-f0-9]+)"', body)
        product = re.search(r'<select id="media-product" name="product_id".*?<option value="([1-9][0-9]*)"', body, re.S)
        if csrf is None or product is None:
            raise AssertionError('Administrator editor is missing CSRF or active product choices.')
        wrong_kind = {
            'csrf': csrf.group(1), 'target_type': 'product', 'product_id': product.group(1),
            'media_kind': 'plant', 'asset_id': 'pot-stone-small-v01', 'measurement_status': 'unknown',
            'visible_height_mm': '', 'pot_height_mm': '',
        }
        code, _, body = request(admin, '/admin/media/save', wrong_kind)
        check(code == 422 and 'registered asset of the selected media kind' in body,
              'Wrong-kind asset is rejected with field feedback before persistence.')
        unsafe = {**wrong_kind, 'asset_id': 'https://example.invalid/plant.webp', 'measurement_status': 'measured'}
        code, _, body = request(admin, '/admin/media/save', unsafe)
        check(code == 422 and 'registered asset of the selected media kind' in body,
              'Arbitrary external URL is rejected and does not become image markup.')
        check('src="https://example.invalid/plant.webp"' not in body and 'value="https://example.invalid/plant.webp"' not in body,
              'Rejected remote URL is neither rendered as an image nor an option.')

        code, _, customer_body = request(customer, '/account')
        customer_csrf = re.search(r'<input type="hidden" name="csrf" value="([a-f0-9]+)"', customer_body)
        if code != 200 or customer_csrf is None:
            raise AssertionError('Synthetic customer session lacks a valid CSRF token.')
        _, _, customer_admin_login = request(customer, '/login', None, ADMIN_HOST)
        customer_admin_token = token_for(customer_admin_login, '/login')
        code, headers, _ = request(customer, '/admin/media/archive', {
            'csrf': customer_admin_token, 'binding_id': '1', 'expected_version': '1',
        })
        check(code == 303 and headers.get('Location', '').startswith('/login'),
              'Valid CSRF does not grant customer authority to archive media.')
        print(f'Admin media HTTP checks: {checks} passed, 0 failed.')
    finally:
        subprocess.run(['php', str(fixture_script), 'cleanup', json.dumps(fixture)], check=True, timeout=10)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('Admin media HTTP checks failed:', error, file=sys.stderr)
        sys.exit(1)
