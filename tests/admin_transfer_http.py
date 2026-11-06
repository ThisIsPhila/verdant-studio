"""Admin presentation/actions regression against the isolated phase015 fixture only."""
import http.cookiejar
import json
import os
import re
import subprocess
from pathlib import Path
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser

assert os.environ.get('APP_ENV') == 'local'
assert os.environ.get('DB_NAME') == os.environ.get('HTTP_TEST_DATABASE') == 'verdant_test_phase015'
FIXTURE = json.loads(subprocess.check_output(['php', str(Path(__file__).with_name('admin_http_fixture.php'))], text=True))
BASE = 'http://127.0.0.1:8095'

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args): return None

class Forms(HTMLParser):
    def __init__(self):
        super().__init__(); self.forms = []; self.current = None
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'form':
            self.current = {'action': attrs.get('action'), 'fields': {}}
            self.forms.append(self.current)
        elif tag == 'input' and self.current is not None and attrs.get('name'):
            self.current['fields'][attrs['name']] = attrs.get('value', '')
    def handle_endtag(self, tag):
        if tag == 'form': self.current = None

def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())

STORE_HOST = '127.0.0.1:8088'
ADMIN_HOST = 'admin.verdant.localhost:8088'


def request(c, path, fields=None, host=None):
    data = None if fields is None else urllib.parse.urlencode(fields).encode()
    if host is None:
        host = ADMIN_HOST if (path.startswith('/admin') ) else STORE_HOST
    try: response = c.open(urllib.request.Request(BASE + path, data, headers={'Host': host}), timeout=8)
    except urllib.error.HTTPError as error: response = error
    return response.code, response.headers, response.read().decode()

def form(html, action):
    parser = Forms(); parser.feed(html)
    return next(f['fields'] for f in parser.forms if f['action'] == action)

def login(c, role, admin_host=False):
    host = ADMIN_HOST if admin_host else STORE_HOST
    _, _, html = request(c, '/login', None, host)
    fields = form(html, '/login')
    fields.update(email=role + '@example.test', password='VerdantDemo!2026')
    status, headers, _ = request(c, '/login', fields, host)
    location = headers.get('Location', '')
    if location in ('/admin/2fa', '/admin/2fa/setup'):
        return complete_admin_2fa(c, role + '@example.test', location)
    return status


def complete_admin_2fa(c, email, location):
    import admin_2fa
    db = os.environ.get('HTTP_TEST_DATABASE')

    def token_for(html, action):
        parser = Forms()
        parser.feed(html)
        return next((f['fields'].get('csrf', '') for f in parser.forms if f['action'] == action), '')

    if location == '/admin/2fa/setup':
        status, _, body = request(c, '/admin/2fa/setup')
        assert status == 200 and 'Set up two-factor' in body, 'Two-factor setup did not load.'
        secret = admin_2fa.credential_secret(db, email)
        status, _, body = request(c, '/admin/2fa/setup',
                                  {'csrf': token_for(body, '/admin/2fa/setup'),
                                   'code': admin_2fa.current_code(secret)})
        assert status == 200 and 'Save your backup codes' in body, 'Two-factor setup did not complete.'
    status, headers, body = request(c, '/admin/2fa')
    if status == 303:
        return status
    assert status == 200, 'Two-factor challenge did not load.'
    secret = admin_2fa.credential_secret(db, email)
    status, headers, _ = request(c, '/admin/2fa',
                                 {'csrf': token_for(body, '/admin/2fa'),
                                  'code': admin_2fa.current_code(secret)})
    assert status == 303 and headers.get('Location') == '/admin', 'Two-factor challenge did not sign in.'
    return status

checks = 0

def check(ok, label):
    global checks
    if not ok: raise AssertionError(label)
    checks += 1
    print('PASS:', label)

order = '/admin/order?id=' + str(FIXTURE['order'])
rental = '/admin/rental?id=' + str(FIXTURE['rental'])
pages = ['/admin', '/admin/orders', '/admin/rentals', order, rental, '/admin/products', '/admin/inventory', '/admin/product?id=' + str(FIXTURE['product']), '/admin/categories', '/admin/families', '/admin/compatibility', '/admin/compositions', '/admin/customers', '/admin/sections', '/admin/media', '/admin/rental-calendar']
anon, customer, admin = client(), client(), client()
for route in pages:
    check(request(anon, route)[0] == 303, 'Anonymous denied: ' + route)
check(login(customer, 'customer') == 303, 'Customer signs in')
for route in pages:
    code, headers, _ = request(customer, route)
    check(code == 303 and headers.get('Location', '').startswith('/login'), 'Customer denied: ' + route)
check(login(admin, 'admin', True) == 303, 'Administrator signs in')
for route in pages:
    code, _, html = request(admin, route)
    check(code == 200 and 'admin-sidebar' in html and html.count('aria-label="Administration"') == 1, 'Single internal shell: ' + route)
_, _, html = request(admin, order)
fields = form(html, '/admin/order/fulfil')
check(request(admin, '/admin/order/fulfil', {'order_id': FIXTURE['order']})[0] == 419, 'Fulfil CSRF required')
check(request(admin, '/admin/order/fulfil', fields)[0] == 303, 'Existing fulfil action succeeds')
check('/admin/order/fulfil' not in request(admin, order)[2], 'Fulfil action disappears after fulfilment')
for archived in ['1', '0']:
    _, _, html = request(admin, order)
    fields = form(html, '/admin/order/archive'); fields['archived'] = archived
    check(request(admin, '/admin/order/archive', fields)[0] == 303, 'Order archive/restore: ' + archived)
_, _, html = request(admin, rental)
fields = form(html, '/admin/rental/return'); fields['quantity'] = '1'
check('request_key' in fields and fields['request_key'], 'Return idempotency key retained')
check(request(admin, '/admin/rental/return', {'rental_id': FIXTURE['rental']})[0] == 419, 'Return CSRF required')
check(request(admin, '/admin/rental/return', fields)[0] == 303, 'Partial return succeeds')
check(request(admin, '/admin/rental/return', fields)[0] == 303, 'Repeated return request safely accepted')
_, _, html = request(admin, rental)
remaining = form(html, '/admin/rental/return')
check(remaining['quantity'] == '1', 'Replay does not record a second return')
for archived in ['1', '0']:
    _, _, html = request(admin, rental)
    fields = form(html, '/admin/rental/archive'); fields['archived'] = archived
    check(request(admin, '/admin/rental/archive', fields)[0] == 303, 'Rental archive/restore: ' + archived)
for route in ['/admin/order?id=0', '/admin/rental?id=0']:
    code, _, html = request(admin, route)
    check(code in [404,422] and 'admin-sidebar' in html, 'Internal error presentation: ' + route)
for route in ['/arrangements', '/rental?product_id=6', '/compositions/saved', '/account/order?id=' + str(FIXTURE['order']), '/account/rental?id=' + str(FIXTURE['rental'])]:
    code, _, html = request(customer, route)
    check(code == 200 and html.count('<main ') == 1, 'Customer page uses one main landmark: ' + route)
    check('/assets/css/vision/customer-records.css' in html, 'Customer component stylesheet: ' + route)
for route in ['/account/order?id=' + str(FIXTURE['order']), '/account/rental?id=' + str(FIXTURE['rental'])]:
    check(request(admin, route)[0] == 404, 'Owned customer records remain private: ' + route)
check(request(admin, '/admin/rental-calendar?month=2026-13')[0] == 422, 'Invalid calendar date refused')
check(request(admin, '/admin/rental-calendar?month[]=2026-10')[0] == 422, 'Invalid calendar type refused')
print(f'Admin transfer HTTP: {checks} passed; isolated phase015 only.')
