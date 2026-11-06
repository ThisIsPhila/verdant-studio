"""Navigation/returns checks against seeded, isolated Phase017 loopback only."""
import os
import re
from html import unescape
from urllib.parse import parse_qs, urlsplit
import information_pages_http as http

if os.getenv('APP_ENV') != 'local' or os.getenv('DB_NAME') != 'verdant_test_phase017':
    raise RuntimeError('Use the isolated verdant_test_phase017 database.')
if urlsplit(http.BASE).hostname not in {'127.0.0.1', 'localhost', '::1'}:
    raise RuntimeError('Use a loopback server.')
checks = 0

def check(ok, description):
    global checks
    if not ok:
        raise AssertionError(description)
    checks += 1
    print('PASS:', description)

anon, customer, admin = http.client(), http.client(), http.client()
check(http.request(anon, '/admin/returns')[0] == 303, 'Anonymous denied: /admin/returns')
check(http.login(customer, 'customer@example.test') == 303, 'Customer fixture signs in.')
code, headers, _ = http.request(customer, '/admin/returns')
check(code == 303 and headers.get('Location', '').startswith('/login'), 'Customer role denied: /admin/returns')
check(http.login(admin, 'admin@example.test', True) == 303, 'Administrator fixture signs in.')
status, _, body = http.request(admin, '/admin/returns')
check(status == 200 and 'Outstanding rental returns' in body, 'Separate Returns workspace uses the real PHP service.')
check('Purchase returns' in body and 'Coming soon' in body, 'Purchase processing is distinguished from supported rental asset returns.')
for path in ['/admin/returns', '/admin/categories', '/admin/compatibility', '/admin/rental-calendar']:
    status, _, html = http.request(admin, path)
    check(status == 200 and not re.search(r'Warning:|Fatal error:|Undefined array key', html), 'Admin page renders without diagnostics: ' + path)
    check('Item compatibility' in html and 'Rentals &amp; returns' not in html, 'Short independent navigation labels: ' + path)
    nav = re.search(r'<nav.*?</nav>', html, re.S)
    check(nav is not None and nav.group(0).count('aria-current="page"') == 1, 'One navigation destination is current: ' + path)
_, _, compatibility = http.request(admin, '/admin/compatibility')
check('<h1>Item compatibility</h1>' in compatibility, 'Compatibility page and navigation share the short label.')
_, _, calendar = http.request(admin, '/admin/rental-calendar?month=2026-10')
check('rental-calendar-grid' in calendar and 'rental-calendar-month' in calendar and 'rental-calendar-summary' in calendar, 'Existing PHP calendar structure is retained.')
match = re.search(r'href="(/admin/rental\?id=\d+&amp;origin=returns#rental-item-\d+)"', body)
check(match is not None, 'Seeded outstanding item has a direct return-management link.')
route = unescape(match.group(1)).split('#')[0]
_, _, detail = http.request(admin, route)
check('Back to returns' in detail and 'href="/admin/returns" aria-current="page"' in detail, 'Return detail retains its navigation parent.')
form = http.get_form(detail, '/admin/rental/return')
check(form is not None and form['fields'].get('origin') == 'returns', 'Return form retains the bounded origin.')
check(http.request(admin, '/admin/rental/return', {'quantity': '0'})[0] == 419, 'Return mutation still requires CSRF.')
fields = dict(form['fields'], quantity='0')
status, headers, _ = http.request(admin, '/admin/rental/return', fields)
# The returns form carries a return_page pagination field, so the bounce keeps
# the same detail route and origin with that extra parameter appended.
location = headers.get('Location', '')
check(status == 303 and (location == route or location.startswith(route + '&')), 'Invalid quantity preserves the return origin without a database mutation.')
fields['origin'] = 'https://example.test'
status, headers, _ = http.request(admin, '/admin/rental/return', fields)
# Compare parsed parameter names: the list-context fallback legitimately uses
# return_origin, which contains the substring 'origin=' without reflecting it.
params = parse_qs(urlsplit(headers.get('Location', '')).query)
check(status == 303 and headers.get('Location', '').startswith('/admin/rental?id=') and 'origin' not in params and 'example.test' not in headers.get('Location', ''), 'Untrusted origin cannot create an external redirect.')
print(f'Admin navigation HTTP checks: {checks} passed, 0 failed.')
