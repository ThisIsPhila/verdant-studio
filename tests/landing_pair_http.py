"""Session-only landing cart checks; no orders, payments or database writes."""
import re
from html.parser import HTMLParser
from http.cookiejar import CookieJar
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import build_opener, HTTPCookieProcessor, HTTPRedirectHandler

BASE = 'http://127.0.0.1:8088'
class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

class Forms(HTMLParser):
    def __init__(self):
        super().__init__()
        self.forms = []
        self.form = None
        self.select = None
    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'form':
            self.form = {'attrs': a, 'inputs': {}, 'selects': {}}
            self.forms.append(self.form)
        elif self.form is not None:
            if tag == 'input' and 'name' in a and 'disabled' not in a:
                if a.get('type') != 'radio' or 'checked' in a:
                    self.form['inputs'][a['name']] = a.get('value', '')
            elif tag == 'select':
                self.select = a.get('name')
            elif tag == 'option' and self.select:
                if self.select not in self.form['selects'] or 'selected' in a:
                    self.form['selects'][self.select] = a.get('value', '')
    def handle_endtag(self, tag):
        if tag == 'form': self.form = None
        if tag == 'select': self.select = None

opener = build_opener(HTTPCookieProcessor(CookieJar()), NoRedirect())
def request(path, data=None):
    try:
        response = opener.open(BASE + path, urlencode(data).encode() if data is not None else None)
    except HTTPError as error:
        response = error
    return response.code, response.read().decode()

checks = 0
def check(ok, label):
    global checks
    if not ok: raise AssertionError(label)
    checks += 1
    print('PASS:', label)

status, home = request('/')
check(status == 200 and 'Warning:' not in home and 'Deprecated:' not in home, 'Landing renders without PHP diagnostics')
parser = Forms(); parser.feed(home)
pair = next(f for f in parser.forms if f['attrs'].get('id') == 'landing-pair-form')
selection = pair['inputs'] | pair['selects']
check(pair['attrs']['action'] == '/cart/add' and selection.get('pot_id') and selection.get('product_id'), 'Pair form submits actual plant and compatible pot IDs')
status, result = request('/cart/add', selection)
if status != 303:
    print('Pair status:', status)
    print('Messages:', re.findall(r'<(?:p|li)[^>]*>([^<]{5,200})</(?:p|li)>', result)[-14:])
check(status == 303, 'Configured pair is added through the existing cart endpoint')
_, cart = request('/cart')
quantities = re.findall(r'name="quantity"[^>]*value="(\d+)"', cart)
check(quantities == ['1', '1'], 'Pair adds exactly one plant and one pot')
status, _ = request('/cart/add', selection | {'pot_id': selection['product_id']})
check(status == 422, 'Plant supplied as its own pot is rejected')
_, after = request('/cart')
check(re.findall(r'name="quantity"[^>]*value="(\d+)"', after) == quantities, 'Rejected pair leaves basket quantities unchanged')
status, _ = request('/cart/add', {k: v for k, v in selection.items() if k != 'csrf'})
check(status == 419, 'Pair mutation without CSRF is rejected')
card = next(f for f in parser.forms if f['attrs'].get('action') == '/cart/add' and f['inputs'].get('pot_id') and f is not pair)
status, _ = request('/cart/add', card['inputs'])
check(status == 303, 'Featured plant card adds its compatible default pair')
print(f'Landing pair HTTP checks: {checks} passed, 0 failed.')
