#!/usr/bin/env python3
"""Isolated same-origin password recovery HTTP checks for Phase 020."""
import http.cookiejar
import json
import os
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DATABASE = 'verdant_test_phase020'
STORE_HOST = 'verdant.localhost:8088'
ADMIN_HOST = 'admin.verdant.localhost:8088'
CUSTOMER_EMAIL = 'customer@thisisphila.co.za'
ADMIN_EMAIL = 'admin@thisisphila.co.za'
OLD_TEST_PASSWORD = 'LocalRecoveryPassphrase2026'
NEW_TEST_PASSWORD = 'NewLocalRecoveryPassphrase2026'


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def client():
    jar = http.cookiejar.CookieJar()
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect())


def request(opener, base, host, path, fields=None):
    data = None if fields is None else urllib.parse.urlencode(fields).encode()
    req = urllib.request.Request(base + path, data=data, headers={'Host': host})
    try:
        response = opener.open(req, timeout=8)
    except urllib.error.HTTPError as error:
        response = error
    body = response.read().decode('utf-8', errors='replace')
    if re.search(r'(?:Warning|Fatal error|Deprecated):', body):
        raise AssertionError('PHP diagnostics leaked into ' + path)
    return response.code, response.headers, body


def fixture(env, action, payload=None):
    result = subprocess.run(
        ['php', 'tests/password_recovery_http_fixture.php', action], cwd=ROOT, env=env,
        input='' if payload is None else json.dumps(payload), text=True, capture_output=True,
        timeout=20,
    )
    if result.returncode != 0:
        raise RuntimeError('Recovery HTTP fixture failed: ' + result.stderr[-1000:])
    if action in {'restore', 'set-state'}:
        return result.stdout.strip()
    return json.loads(result.stdout)


def csrf(body):
    match = re.search(r'name="csrf" value="([a-f0-9]{64})"', body)
    if match is None:
        raise AssertionError('Expected a form CSRF token.')
    return match.group(1)


def reset_form(opener, base, host):
    status, headers, body = request(opener, base, host, '/password-reset/request')
    if status != 200:
        raise AssertionError(f'Recovery request form did not load (HTTP {status}).')
    return csrf(body), headers, body


def post_request(opener, base, host, email, csrf_token):
    status, headers, body = request(opener, base, host, '/password-reset/request', {
        'csrf': csrf_token, 'email': email,
    })
    return status, headers, body


def run():
    if os.getenv('APP_ENV') != 'local' or os.getenv('DB_NAME') != DATABASE:
        raise RuntimeError(f'Use only APP_ENV=local with DB_NAME={DATABASE}.')

    listener = socket.socket()
    listener.bind(('127.0.0.1', 0))
    port = listener.getsockname()[1]
    listener.close()
    base = f'http://127.0.0.1:{port}'
    temp_root = Path(tempfile.mkdtemp(prefix='verdant-phase020-http-'))
    temp_root.chmod(0o700)
    capture_dir = temp_root / 'capture'
    capture_dir.mkdir(mode=0o700)
    server_log = temp_root / 'server.log'
    env = os.environ.copy()
    env.update({'APP_ENV': 'local', 'DB_NAME': DATABASE})
    server = None
    snapshot = None
    checks = 0

    def check(condition, label):
        nonlocal checks
        if not condition:
            raise AssertionError(label)
        checks += 1
        print('PASS:', label)

    try:
        snapshot = fixture(env, 'prepare', {'test_password': OLD_TEST_PASSWORD})
        with server_log.open('w', encoding='utf-8') as log:
            server = subprocess.Popen(
                ['php', '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/router.php'],
                cwd=ROOT, env=env, stdout=log, stderr=subprocess.STDOUT,
            )
            store = client()
            admin = client()
            for _ in range(60):
                if server.poll() is not None:
                    raise RuntimeError('Isolated HTTP server exited before the recovery checks.')
                try:
                    ready, _, _ = request(store, base, STORE_HOST, '/login')
                    if ready == 200:
                        break
                except (OSError, urllib.error.URLError):
                    time.sleep(.1)
            else:
                raise RuntimeError('Isolated HTTP server did not become ready.')

            status, _, store_login = request(store, base, STORE_HOST, '/login')
            status_admin, _, admin_login = request(admin, base, ADMIN_HOST, '/login')
            check(status == 200 and 'Forgot your password?' in store_login
                  and '/password-reset/request' in store_login, 'Customer sign-in links to same-origin recovery.')
            check(status_admin == 200 and 'Forgot your password?' in admin_login
                  and '/password-reset/request' in admin_login, 'Admin sign-in links to same-origin recovery.')

            store_csrf, _, store_request_page = reset_form(store, base, STORE_HOST)
            admin_csrf, _, admin_request_page = reset_form(admin, base, ADMIN_HOST)
            check('customer support' in store_request_page and 'type="email"' in store_request_page,
                  'Customer request form explains email username and support fallback.')
            check('customer support' in admin_request_page and 'action="/password-reset/request"' in admin_request_page,
                  'Admin request form stays on the admin origin.')
            denied_request = request(store, base, STORE_HOST, '/password-reset/request', {'email': CUSTOMER_EMAIL})
            denied_request_safe = (denied_request[0] == 419
                                   and 'no-store' in denied_request[1].get('Cache-Control', '')
                                   and denied_request[1].get('Referrer-Policy') == 'no-referrer'
                                   and 'fonts.googleapis.com' not in denied_request[2])
            check(denied_request_safe,
                  'Request-form CSRF failure stays private and first-party-only.' if denied_request_safe else
                  f"Request CSRF response policy differed: status={denied_request[0]}, "
                  f"cache={denied_request[1].get('Cache-Control')}, "
                  f"referrer={denied_request[1].get('Referrer-Policy')}, "
                  f"third_party_asset={'fonts.googleapis.com' in denied_request[2]}.")

            unknown_status, _, unknown_body = post_request(
                store, base, STORE_HOST, 'unknown-recovery-user@example.test', store_csrf,
            )
            check(unknown_status == 200 and 'If an account exists for that email address' in unknown_body,
                  'Unknown email receives the generic confirmation.')

            fixture(env, 'set-state', {'email': CUSTOMER_EMAIL, 'state': 'archived'})
            archived_status, _, archived_body = post_request(store, base, STORE_HOST, CUSTOMER_EMAIL, store_csrf)
            fixture(env, 'set-state', {'email': CUSTOMER_EMAIL, 'state': 'active'})
            check(archived_status == 200 and archived_body == unknown_body,
                  'Archived account receives the same confirmation as an unknown email.')

            wrong_role_status, _, wrong_role_body = post_request(store, base, STORE_HOST, ADMIN_EMAIL, store_csrf)
            check(wrong_role_status == 200 and wrong_role_body == unknown_body,
                  'Admin identity submitted on the store receives the same generic confirmation.')

            customer_status, _, customer_body = post_request(store, base, STORE_HOST, CUSTOMER_EMAIL, store_csrf)
            check(customer_status == 200 and customer_body == unknown_body,
                  'Eligible customer receives no account-existence signal.')
            customer_capture = json.loads(subprocess.run(
                ['php', 'tests/password_recovery_http_fixture.php', 'capture'], cwd=ROOT, env=env,
                input=json.dumps({'email': CUSTOMER_EMAIL, 'capture_directory': str(capture_dir)}),
                text=True, capture_output=True, timeout=20, check=True,
            ).stdout)
            customer_token = customer_capture['token']

            prior_store_session = client()
            prior_store_login = request(prior_store_session, base, STORE_HOST, '/login')
            prior_store_status, prior_store_headers, _ = request(prior_store_session, base, STORE_HOST, '/login', {
                'csrf': csrf(prior_store_login[2]), 'email': CUSTOMER_EMAIL, 'password': OLD_TEST_PASSWORD,
            })
            prior_admin_session = client()
            prior_admin_login = request(prior_admin_session, base, ADMIN_HOST, '/login')
            prior_admin_status, _, _ = request(prior_admin_session, base, ADMIN_HOST, '/login', {
                'csrf': csrf(prior_admin_login[2]), 'email': CUSTOMER_EMAIL, 'password': OLD_TEST_PASSWORD,
            })
            check(prior_store_status == 303 and prior_store_headers.get('Location') == '/account',
                  'The account has an active store session before reset.')
            check(prior_admin_status == 422,
                  'The customer account cannot open an admin-surface session before reset.')
            check(request(prior_store_session, base, STORE_HOST, '/account')[0] == 200
                  and request(prior_admin_session, base, ADMIN_HOST, '/admin')[0] == 303,
                  'The pre-reset store session works, while the customer account holds no admin session.')

            reset_status, reset_headers, reset_body = request(store, base, STORE_HOST, '/password-reset')
            check(reset_status == 200 and 'no-store' in reset_headers.get('Cache-Control', '')
                  and reset_headers.get('Referrer-Policy') == 'no-referrer'
                  and 'fonts.googleapis.com' not in reset_body,
                  'Reset form is uncached, no-referrer and has no third-party assets.')
            check('name="token" value=""' in reset_body,
                  'Reset token is absent from the server-rendered page before the private fragment handoff.')

            validation_url = '/password-reset/validate'
            validation_fields = {'csrf': csrf(reset_body), 'token': customer_token}
            validate_status, validate_headers, validate_body = request(
                store, base, STORE_HOST, validation_url, validation_fields,
            )
            check(validate_status == 200 and json.loads(validate_body)['valid'] is True
                  and validate_headers.get('Content-Type', '').startswith('application/json'),
                  'Customer token validates by same-origin POST without consumption.')
            admin_validation_csrf, _, _ = reset_form(admin, base, ADMIN_HOST)
            cross_status, _, cross_body = request(admin, base, ADMIN_HOST, validation_url, {
                'csrf': admin_validation_csrf, 'token': customer_token,
            })
            check(cross_status == 200 and json.loads(cross_body)['valid'] is False,
                  'Customer token cannot be validated on the admin origin.')
            denied_validation = request(store, base, STORE_HOST, validation_url, {'token': customer_token})
            check(denied_validation[0] == 419 and 'no-store' in denied_validation[1].get('Cache-Control', '')
                  and denied_validation[1].get('Referrer-Policy') == 'no-referrer',
                  'Token-validation POST rejects missing CSRF without relaxing recovery headers.')

            weak_status, _, weak_body = request(store, base, STORE_HOST, '/password-reset', {
                'csrf': csrf(reset_body), 'token': customer_token,
                'password': 'short', 'password_confirmation': 'mismatch',
            })
            check(weak_status == 422 and 'at least 12 characters' in weak_body
                  and 'confirmation does not match' in weak_body,
                  'Reset enforces the registration password policy and confirmation.')
            check(json.loads(request(store, base, STORE_HOST, validation_url, validation_fields)[2])['valid'] is True,
                  'Validation errors do not consume the reset token.')
            denied_reset = request(store, base, STORE_HOST, '/password-reset', {
                'token': customer_token, 'password': NEW_TEST_PASSWORD,
                'password_confirmation': NEW_TEST_PASSWORD,
            })
            check(denied_reset[0] == 419 and 'no-store' in denied_reset[1].get('Cache-Control', '')
                  and denied_reset[1].get('Referrer-Policy') == 'no-referrer'
                  and 'fonts.googleapis.com' not in denied_reset[2],
                  'Password-reset POST rejects missing CSRF on the private recovery layout.')

            changed_status, changed_headers, _ = request(store, base, STORE_HOST, '/password-reset', {
                'csrf': csrf(reset_body), 'token': customer_token,
                'password': NEW_TEST_PASSWORD, 'password_confirmation': NEW_TEST_PASSWORD,
            })
            destination = urllib.parse.urlsplit(changed_headers.get('Location', ''))
            check(changed_status == 303 and destination.path == '/login'
                  and urllib.parse.parse_qs(destination.query) == {'password_reset': ['complete']},
                  'Valid customer reset clears the current session and redirects to same-origin sign-in.')
            reset_notice = request(store, base, STORE_HOST, '/login?password_reset=complete')
            check(reset_notice[0] == 200 and 'Your password has been changed' in reset_notice[2],
                  'Customer sign-in confirms the password change without automatic login.')
            replay_reset_form = request(store, base, STORE_HOST, '/password-reset')
            replay_check = request(store, base, STORE_HOST, validation_url, {
                'csrf': csrf(replay_reset_form[2]), 'token': customer_token,
            })
            check(replay_check[0] == 200 and json.loads(replay_check[2])['valid'] is False,
                  'Consumed customer token cannot be replayed.')
            account_status, account_headers, _ = request(store, base, STORE_HOST, '/account')
            check(account_status == 303 and account_headers.get('Location') == '/login',
                  'Password-reset response leaves the customer signed out.')

            prior_store_access = request(prior_store_session, base, STORE_HOST, '/account')
            prior_admin_access = request(prior_admin_session, base, ADMIN_HOST, '/admin')
            check(prior_store_access[0] == 303 and prior_store_access[1].get('Location') == '/login'
                  and prior_admin_access[0] == 303
                  and urllib.parse.urlsplit(prior_admin_access[1].get('Location', '')).path == '/login',
                  'Password reset invalidates the existing store session; no admin session ever existed.')

            old_customer = client()
            old_customer_status, _, _ = request(old_customer, base, STORE_HOST, '/login', {
                'csrf': csrf(request(old_customer, base, STORE_HOST, '/login')[2]),
                'email': CUSTOMER_EMAIL, 'password': OLD_TEST_PASSWORD,
            })
            new_customer = client()
            new_customer_login = request(new_customer, base, STORE_HOST, '/login')
            new_customer_status, _, _ = request(new_customer, base, STORE_HOST, '/login', {
                'csrf': csrf(new_customer_login[2]), 'email': CUSTOMER_EMAIL,
                'password': NEW_TEST_PASSWORD,
            })
            check(old_customer_status != 303 and new_customer_status == 303,
                  'New customer password authenticates; previous password is rejected.')

            admin_status, _, admin_body = post_request(admin, base, ADMIN_HOST, ADMIN_EMAIL, admin_csrf)
            check(admin_status == 200 and admin_body == unknown_body,
                  'Eligible administrator uses the same generic request response.')
            admin_capture = json.loads(subprocess.run(
                ['php', 'tests/password_recovery_http_fixture.php', 'capture'], cwd=ROOT, env=env,
                input=json.dumps({'email': ADMIN_EMAIL, 'capture_directory': str(capture_dir)}),
                text=True, capture_output=True, timeout=20, check=True,
            ).stdout)
            admin_token = admin_capture['token']
            admin_reset = request(admin, base, ADMIN_HOST, '/password-reset')
            admin_token_validation = request(admin, base, ADMIN_HOST, validation_url, {
                'csrf': csrf(admin_reset[2]), 'token': admin_token,
            })
            check(json.loads(admin_token_validation[2])['valid'] is True,
                  'Administrator reset token validates on its issuing origin.')
            check(json.loads(request(store, base, STORE_HOST, validation_url, {
                'csrf': csrf(replay_reset_form[2]), 'token': admin_token,
            })[2])['valid'] is False, 'Admin token cannot be validated on the store origin.')
            changed_admin_status, changed_admin_headers, _ = request(admin, base, ADMIN_HOST, '/password-reset', {
                'csrf': csrf(admin_reset[2]), 'token': admin_token,
                'password': NEW_TEST_PASSWORD, 'password_confirmation': NEW_TEST_PASSWORD,
            })
            changed_admin_target = urllib.parse.urlsplit(changed_admin_headers.get('Location', ''))
            check(changed_admin_status == 303 and changed_admin_target.path == '/login'
                  and urllib.parse.parse_qs(changed_admin_target.query) == {'password_reset': ['complete']}
                  and 'Your password has been changed' in request(admin, base, ADMIN_HOST,
                      '/login?password_reset=complete')[2],
                  'Administrator reset clears the current session and redirects to same-origin sign-in.')

            old_admin = client()
            old_admin_login = request(old_admin, base, ADMIN_HOST, '/login')
            old_admin_status, _, _ = request(old_admin, base, ADMIN_HOST, '/login', {
                'csrf': csrf(old_admin_login[2]), 'email': ADMIN_EMAIL, 'password': OLD_TEST_PASSWORD,
            })
            new_admin = client()
            new_admin_login = request(new_admin, base, ADMIN_HOST, '/login')
            new_admin_status, _, _ = request(new_admin, base, ADMIN_HOST, '/login', {
                'csrf': csrf(new_admin_login[2]), 'email': ADMIN_EMAIL, 'password': NEW_TEST_PASSWORD,
            })
            check(old_admin_status != 303 and new_admin_status == 303,
                  'New administrator password authenticates; previous password is rejected.')
            admin_area, admin_area_headers, _ = request(admin, base, ADMIN_HOST, '/admin')
            check(admin_area == 303
                  and urllib.parse.urlsplit(admin_area_headers.get('Location', '')).path == '/login',
                  'Reset session receives no administrator privileges.')

            unknown_host_status, _, _ = request(store, base, 'untrusted.example.test', '/password-reset/request')
            check(unknown_host_status == 400, 'Untrusted Host cannot access local recovery routes.')

            counts = fixture(env, 'counts')
            check(counts[CUSTOMER_EMAIL]['changed_messages'] >= 1
                  and counts[ADMIN_EMAIL]['changed_messages'] >= 1,
                  'Both successful resets queue password-changed notices.')
            access_log = server_log.read_text(encoding='utf-8', errors='replace')
            check(customer_token not in access_log and admin_token not in access_log,
                  'Reset tokens do not appear in local HTTP access logs.')

        print(f'Password recovery HTTP checks: {checks} passed; local capture only; no external email sent.')
    finally:
        if server is not None:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=5)
        if snapshot is not None:
            fixture(env, 'restore', snapshot)
        shutil.rmtree(temp_root, ignore_errors=True)


if __name__ == '__main__':
    run()
