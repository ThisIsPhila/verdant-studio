"""Read-only discovery contracts: preserve filters across date search; no preparation/payment writes."""
from datetime import date, timedelta
from html.parser import HTMLParser
from urllib.request import urlopen
from urllib.parse import urlencode
from urllib.error import HTTPError

BASE = 'http://127.0.0.1:8088'
checks = 0
class DateForm(HTMLParser):
    def __init__(self):
        super().__init__(); self.active = False; self.fields = {}
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'form': self.active = 'vision-rental-date-form' in attrs.get('class', '').split()
        elif tag == 'input' and self.active and attrs.get('name'): self.fields[attrs['name']] = attrs.get('value', '')
    def handle_endtag(self, tag):
        if tag == 'form': self.active = False

def get(path):
    try: response = urlopen(BASE + path, timeout=20)
    except HTTPError as error: response = error
    body = response.read().decode()
    assert not any(text in body for text in ('Fatal error:', 'Warning:', 'Deprecated:')), path
    return response.status, body

def check(condition, label):
    global checks
    assert condition, label
    checks += 1

status, body = get('/catalogue?mode=rent&kind=plant')
form = DateForm(); form.feed(body)
check(status == 200 and form.fields.get('kind') == 'plant' and form.fields.get('mode') == 'rent', 'Date form retains the chosen rental category.')
start = date.today() + timedelta(days=14)
form.fields.update(start_date=start.isoformat(), end_date=(start + timedelta(days=2)).isoformat())
status, body = get('/catalogue?' + urlencode(form.fields))
check(status == 200 and 'for one item' in body, 'Real rental quotes render for selected dates.')
check('name="kind" value="plant"' in body and form.fields['start_date'] in body, 'Date results preserve type and date state.')
check('action="/cart/add"' not in body, 'Rental-only results do not offer retail basket writes.')
status, body = get('/catalogue?mode=rent&kind=plant&start_date=invalid&end_date=invalid')
check(status == 422 and 'role="alert"' in body, 'Invalid rental dates remain a validation error.')
status, body = get('/collections/homes')
check(status == 200 and '/assets/css/vision/discovery.css' in body and 'homes-title' in body, 'Homes renders its registered PHP presentation.')
status, body = get('/collections')
check(status == 200 and '/collections/homes' in body, 'Collection directory points to the existing Homes journey.')
status, body = get('/collections/not-an-existing-collection')
check(status == 404, 'Unknown collections stay unavailable.')
print(f'Discovery transfer HTTP checks: {checks} passed, 0 failed.')
