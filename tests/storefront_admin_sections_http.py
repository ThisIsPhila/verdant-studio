#!/usr/bin/env python3
"""Snapshot/restored storefront editor HTTP checks against the Phase 013 loopback fixture."""
import http.cookiejar
import json
import os
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser

BASE = os.getenv('STOREFRONT_HTTP_BASE', 'http://127.0.0.1:8095')


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
            self.current = {'action': attrs.get('action', ''), 'method': attrs.get('method', 'get')}
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


def request(opener, path, fields=None, host=None):
    data = None if fields is None else urllib.parse.urlencode(fields).encode()
    if host is None:
        host = ADMIN_HOST if (path.startswith('/admin') ) else STORE_HOST
    try:
        response = opener.open(urllib.request.Request(BASE + path, data=data, headers={'Host': host}), timeout=8)
    except urllib.error.HTTPError as error:
        response = error
    return response.code, response.headers, response.read().decode()


def forms(body):
    parser = Forms()
    parser.feed(body)
    return parser.forms


def form(body, action):
    return next((entry for entry in forms(body) if entry['action'] == action), {})


def login(opener, email, admin_host=False):
    host = ADMIN_HOST if admin_host else STORE_HOST
    _, _, body = request(opener, '/login', None, host)
    token = form(body, '/login').get('csrf', '')
    status, headers, _ = request(opener, '/login', {'csrf': token, 'email': email, 'password': 'VerdantDemo!2026'}, host)
    location = headers.get('Location', '')
    if location in ('/admin/2fa', '/admin/2fa/setup'):
        return complete_admin_2fa(opener, email, location)
    return status


def complete_admin_2fa(opener, email, location):
    import admin_2fa
    db = os.getenv('DB_NAME')
    if location == '/admin/2fa/setup':
        status, _, body = request(opener, '/admin/2fa/setup')
        assert status == 200 and 'Set up two-factor' in body, 'Two-factor setup did not load.'
        secret = admin_2fa.credential_secret(db, email)
        token = form(body, '/admin/2fa/setup').get('csrf', '')
        status, _, body = request(opener, '/admin/2fa/setup',
                                  {'csrf': token, 'code': admin_2fa.current_code(secret)})
        assert status == 200 and 'Save your backup codes' in body, 'Two-factor setup did not complete.'
    status, headers, body = request(opener, '/admin/2fa')
    if status == 303:
        return status
    assert status == 200, 'Two-factor challenge did not load.'
    secret = admin_2fa.credential_secret(db, email)
    token = form(body, '/admin/2fa').get('csrf', '')
    status, headers, _ = request(opener, '/admin/2fa', {'csrf': token, 'code': admin_2fa.current_code(secret)})
    assert status == 303 and headers.get('Location') == '/admin', 'Two-factor challenge did not sign in.'
    return status


def fixture(action, snapshot=None):
    command = ['php', 'tests/storefront_admin_sections_fixture.php', action]
    result = subprocess.run(command, input='' if snapshot is None else json.dumps(snapshot),
                           text=True, capture_output=True, timeout=10, check=True)
    return json.loads(result.stdout) if action == 'snapshot' else result.stdout.strip()


def run_checks(snapshot):
    if os.getenv('APP_ENV') != 'local' or os.getenv('DB_NAME') != 'verdant_test_phase013_baseline':
        raise RuntimeError('Use only APP_ENV=local with the named Phase 013 baseline fixture database.')
    checks = 0

    def check(condition, message):
        nonlocal checks
        if not condition:
            raise AssertionError(message)
        checks += 1
        print('PASS:', message)

    anonymous, customer, admin = client(), client(), client()
    code, headers, _ = request(anonymous, '/admin/sections')
    check(code == 303 and headers.get('Location', '').startswith('/login'), 'Anonymous admin page access redirects to sign-in.')
    check(login(customer, 'customer@example.test') == 303, 'Synthetic customer signs in.')
    code, headers, _ = request(customer, '/admin/sections')
    check(code == 303 and headers.get('Location', '').startswith('/login'), 'Customer cannot read the protected editor.')
    check(login(admin, 'admin@example.test', True) == 303, 'Synthetic administrator signs in.')

    code, _, body = request(admin, '/admin/sections?page=home')
    check(code == 200 and 'Storefront sections' in body and 'name="section_key"' in body,
          'Administrator sees the page selector and section controls.')
    check('password_hash' not in body and '$2y$' not in body, 'Editor does not expose credential data.')
    check(request(admin, '/admin/sections/save')[0] == 405, 'Save action rejects GET.')
    check(request(admin, '/admin/sections/save', {})[0] == 419, 'Save action rejects a missing CSRF token.')

    edit = form(body, '/admin/sections/save')
    check(bool(edit.get('section_key')) and bool(edit.get('csrf')), 'Editor provides a selected section and CSRF token.')
    invalid = {
        **edit,
        'heading': 'Temporary invalid-route validation check',
        'body': 'This request must not persist any changes.',
        'media_path': '',
        'media_alt': '',
        'link_label': 'Unsafe link',
        'link_path': '/outside?next=https://example.invalid',
        'collection_id': '',
        'arrangement_id': '',
    }
    code, _, body = request(admin, '/admin/sections/save', invalid)
    check(code == 422, 'Unsupported external destination returns field validation.')
    check('Temporary invalid-route validation check' in body and 'Submitted path (not approved)' in body,
          'Validation retains the safe submitted text and rejected selection.')
    check('https://example.invalid' in body and 'href="https://example.invalid"' not in body,
          'Rejected destination is shown as escaped form data, never rendered as a link.')

    section_token = form(body, '/admin/sections/save').get('csrf', '')
    malformed_actions = [
        ('/admin/sections/move', {'direction': '0'}),
        ('/admin/sections/visibility', {'is_enabled': '2'}),
        ('/admin/sections/archive', {'section_key': 'missing-section'}),
        ('/admin/sections/restore', {'section_key': 'missing-section'}),
    ]
    for path, extra in malformed_actions:
        payload = {'csrf': section_token, 'page': 'home', 'section_key': 'hero', **extra}
        check(request(admin, path, payload)[0] == 422,
              'Invalid or missing section state returns validation: ' + path)

    original_hero = next(row for row in snapshot['sections'] if row['section_key'] == 'hero')
    code, _, edit_body = request(admin, '/admin/sections?page=home&section=hero')
    edit = form(edit_body, '/admin/sections/save')
    check(code == 200 and edit.get('section_key') == 'hero', 'Existing section can be selected for editing.')
    updated_heading = 'Persistent HTTP edit T013.3'
    updated_body = 'Saved through the protected storefront editor.'
    save_payload = {
        **edit,
        'heading': updated_heading,
        'body': updated_body,
        'media_path': original_hero['media_path'] or '',
        'media_alt': original_hero['media_alt'],
        'link_label': original_hero['link_label'],
        'link_path': original_hero['link_path'] or '',
        'collection_id': str(original_hero['collection_id'] or ''),
        'arrangement_id': str(original_hero['arrangement_id'] or ''),
    }
    code, headers, _ = request(admin, '/admin/sections/save', save_payload)
    check(code == 303 and 'saved=1' in headers.get('Location', ''), 'Valid section edit uses 303 post/redirect/get.')
    code, _, reloaded = request(admin, '/admin/sections?page=home&section=hero')
    check(code == 200 and updated_heading in reloaded and updated_body in reloaded,
          'Edited heading and body persist after a fresh page load.')

    before_move = sorted((row for row in fixture('snapshot')['sections'] if row['archived_at'] is None),
                         key=lambda row: (int(row['position']), int(row['page_section_id'])))
    moved_key = before_move[1]['section_key']
    move_form = form(reloaded, '/admin/sections/move')
    move_payload = {'csrf': move_form['csrf'], 'page': 'home', 'section_key': moved_key, 'direction': '-1'}
    code, headers, _ = request(admin, '/admin/sections/move', move_payload)
    after_move = sorted((row for row in fixture('snapshot')['sections'] if row['archived_at'] is None),
                        key=lambda row: (int(row['position']), int(row['page_section_id'])))
    check(code == 303 and after_move[0]['section_key'] == moved_key
          and after_move[1]['section_key'] == before_move[0]['section_key'],
          'Successful reorder persists the requested adjacent ordering.')

    visibility_form = form(reloaded, '/admin/sections/visibility')
    visibility_base = {'csrf': visibility_form['csrf'], 'page': 'home', 'section_key': 'hero'}
    code, _, _ = request(admin, '/admin/sections/visibility', {**visibility_base, 'is_enabled': '0'})
    hidden_hero = next(row for row in fixture('snapshot')['sections'] if row['section_key'] == 'hero')
    check(code == 303 and int(hidden_hero['is_enabled']) == 0, 'Hide action persists independent visibility state.')
    code, _, _ = request(admin, '/admin/sections/visibility', {**visibility_base, 'is_enabled': '1'})
    shown_hero = next(row for row in fixture('snapshot')['sections'] if row['section_key'] == 'hero')
    check(code == 303 and int(shown_hero['is_enabled']) == 1, 'Show action restores visibility without changing content.')

    lifecycle = {'csrf': visibility_form['csrf'], 'page': 'home', 'section_key': 'hero'}
    original_id = int(original_hero['page_section_id'])
    code, _, _ = request(admin, '/admin/sections/archive', lifecycle)
    archived_hero = next(row for row in fixture('snapshot')['sections'] if row['section_key'] == 'hero')
    check(code == 303 and int(archived_hero['page_section_id']) == original_id and archived_hero['archived_at'] is not None,
          'Archive is soft and retains the existing section identity.')
    code, _, _ = request(admin, '/admin/sections/restore', lifecycle)
    restored_hero = next(row for row in fixture('snapshot')['sections'] if row['section_key'] == 'hero')
    check(code == 303 and int(restored_hero['page_section_id']) == original_id and restored_hero['archived_at'] is None
          and restored_hero['heading'] == updated_heading,
          'Restore preserves identity and the edited content.')

    _, _, customer_admin_login = request(customer, '/login', None, ADMIN_HOST)
    customer_admin_token = form(customer_admin_login, '/login').get('csrf', '')
    check(bool(customer_admin_token), 'Customer session has its own CSRF token.')
    denied = {**invalid, 'csrf': customer_admin_token}
    code, headers, _ = request(customer, '/admin/sections/save', denied)
    check(code == 303 and headers.get('Location', '').startswith('/login'),
          'Customer with a valid session CSRF token is denied before section mutation.')
    print(f'Storefront admin HTTP checks: {checks} passed, 0 failed.')


def main():
    if os.getenv('APP_ENV') != 'local' or os.getenv('DB_NAME') != 'verdant_test_phase013_baseline':
        raise RuntimeError('Use only APP_ENV=local with the named Phase 013 baseline fixture database.')
    snapshot = fixture('snapshot')
    try:
        run_checks(snapshot)
    finally:
        print(f"Fixture cleanup: {fixture('restore', snapshot)}")
    restored = fixture('snapshot')
    if restored != snapshot:
        raise AssertionError('The original home page section rows were not restored exactly.')
    print('PASS: Original home page section rows and IDs restored exactly.')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('Storefront admin HTTP checks failed:', error, file=sys.stderr)
        sys.exit(1)
