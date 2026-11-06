"""Protected email activity HTTP checks on an isolated loopback server."""
import os
import re
from urllib.parse import urlsplit
import information_pages_http as http
if os.getenv('DB_NAME') != 'verdant_test_phase017' or urlsplit(http.BASE).hostname not in {'127.0.0.1','localhost'}:
    raise RuntimeError('Use the isolated Phase017 database and loopback server.')
count = 0
def check(ok, message):
    global count
    if not ok: raise AssertionError(message)
    count += 1
anon, customer, admin = http.client(), http.client(), http.client()
check(http.request(anon, '/admin/email')[0] == 303, 'Anonymous must sign in.')
check(http.login(customer, 'customer@example.test') == 303, 'Customer login.')
code, headers, _ = http.request(customer, '/admin/email')
check(code == 303 and headers.get('Location', '').startswith('/login'), 'Customer denied private delivery data.')
check(http.login(admin, 'admin@example.test', True) == 303, 'Admin login.')
for path in ['/admin/email','/admin/email?status=held','/admin/email?status=invalid']:
    status, _, body = http.request(admin, path)
    check(status == 200 and 'Email activity' in body and not re.search(r'Fatal error:|Warning:|Undefined array', body), path)
status, _, body = http.request(admin, '/admin/email')
match = re.search(r'href="(/admin/email\?id=\d+)"', body)
check(match is not None, 'Existing isolated notification links to detail.')
status, _, detail = http.request(admin, match.group(1))
check(status == 200 and 'Message preview' in detail, 'Protected message preview renders.')
code, headers, _ = http.request(customer, match.group(1))
check(code == 303 and headers.get('Location', '').startswith('/login'), 'Detail access denied to customer.')
check(http.request(admin, '/admin/email?id=999999999')[0] == 404, 'Unknown delivery stays unavailable.')
check(http.request(admin, '/admin/email/retry')[0] == 405, 'Retry requires POST.')
check(http.request(admin, '/admin/email/retry', {'outbox_id':'1','updated_at':'2026-10-23 00:00:00'})[0] == 419, 'Retry requires CSRF.')
print(f'Email activity HTTP checks: {count} passed')
