"""Read-only checks for the local product-led configuration entry."""
from html.parser import HTMLParser
from urllib.request import urlopen

class Page(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.selected = []
        self.options = []
        self.choices = []
        self.inputs = {}
        self.text = []
        self.hidden = 0
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        fields = dict(attrs)
        if tag in ('script', 'style'):
            self.hidden += 1
        if tag == 'a' and 'data-product-choice' in fields:
            self.choices.append(fields)
        if tag == 'input' and 'name' in fields:
            self.inputs[fields['name']] = fields.get('value', '')
        if tag == 'option':
            self.options.append(fields.get('value'))
            if 'selected' in fields:
                self.selected.append(fields.get('value'))

    def handle_endtag(self, tag):
        if tag in ('script', 'style'):
            self.hidden -= 1

    def handle_data(self, data):
        if not self.hidden:
            self.text.append(data)

def page(route):
    with urlopen('http://127.0.0.1:8088' + route, timeout=10) as response:
        assert response.status == 200
        return Page(response.read().decode())

checks = 0

def check(condition, message):
    global checks
    assert condition, message
    checks += 1

product = page('/product?id=3009')
text = ' '.join(product.text)
check('Choose your plant' in text and 'Choose size' not in text, 'Plant-led controls')
check(all(term not in text for term in ('Synthetic', 'Demonstration', 'SKU ', 'stock-bearing')), 'No internal provenance or codes in storefront copy')
check(product.inputs.get('product_id') == '3009' and any(c.get('data-choice-value') == '3008' for c in product.choices), 'Actual selected plant and one-click family options retained')
check(all(term not in text for term in ('View plant', 'Browse all plants', 'Pair with a pot', 'About this image')), 'No unnecessary configuration detours')
route = '/configure?code=starter-plant-compatible-pot&plant_id='
valid = page(route + '3009')
check('3009' in valid.selected and '3024' in valid.options and '3023' not in valid.options, 'Selected large plant uses only its eligible pot')
small = page(route + '3008')
check('3008' in small.selected and '3023' in small.options and '3024' not in small.options, 'Selected small plant resolves its own pot eligibility')
for value in ('3024', '999999999', 'invalid', '3009%22%3E%3Cscript%3E'):
    invalid = page(route + value)
    check(not any(value in invalid.selected for value in ('3008', '3009', '3024')), 'Invalid/non-plant ID cannot become a selected component')


retained = page('/product?id=3008&retain_pot_id=3024')
check(retained.inputs.get('pot_id') == '3023', 'Stone pot family follows the selected smaller plant')
plant_only = page('/product?id=3009&pot_id=0')
check(plant_only.inputs.get('pot_id') == '3024', 'Zero-pot URL selects a compatible default')


rental_pair = page(route + '3009&pot_id=3024&mode=rent')
check('3009' in rental_pair.selected and '3024' in rental_pair.selected and rental_pair.selected.count('rent') == 2, 'Rental handoff preserves both selected products and rental modes')
for plant_id in ('3004', '3005', '3006', '3007', '3008', '3009', '3010', '3011', '3012'):
    selected = page('/product?id=' + plant_id + '&pot_id=0')
    pot_id = selected.inputs.get('pot_id')
    check(bool(pot_id) and pot_id != '0' and 'Plant only' not in ' '.join(selected.text), 'Every mapped plant opens with a compatible pot')
    rental = page('/configure?code=starter-potted-plant&plant_id=' + plant_id + '&pot_id=' + pot_id + '&mode=rent')
    check(plant_id in rental.selected and pot_id in rental.selected, 'Potted rental preserves plant and pot')
retained = page('/product?id=3010&retain_pot_id=3026')
check(retained.inputs.get('pot_id') == '3026', 'Medium plant switch retains its compatible pot material')
fallback = page('/product?id=3010&retain_pot_id=3024')
check(fallback.inputs.get('pot_id') not in (None, '0', '3024'), 'Unavailable material selects a compatible default')
print(f'Product/rental selection HTTP checks: {checks} passed, 0 failed.')
