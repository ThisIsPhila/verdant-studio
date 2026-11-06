"""Session-only pair tests on the isolated Phase 014 server (no orders or stock mutations)."""
from http.cookiejar import CookieJar
from urllib.request import build_opener, HTTPCookieProcessor, Request
from urllib.parse import urlencode
from urllib.error import HTTPError
import re
import os
import subprocess
import json

BASE = 'http://127.0.0.1:8097'
checks = 0
if os.environ.get('DB_NAME') != 'verdant_test_phase014':
    raise SystemExit('Use the isolated verdant_test_phase014 database environment.')
fixture = json.loads(subprocess.check_output(['php', '-r', "require 'app/bootstrap.php'; $db=\\Verdant\\Persistence\\ConnectionFactory::connect(\\Verdant\\Persistence\\DatabaseConfig::fromEnvironment()); echo json_encode($db->query(\"SELECT sku, product_id FROM products WHERE sku IN ('PL-MON-S','PL-MON-L','POT-STONE-S','POT-STONE-L')\")->fetch_all(MYSQLI_ASSOC));"], text=True))
by_sku = {row['sku']: str(row['product_id']) for row in fixture}
ids = dict(zip(('3008', '3009', '3023', '3024'), (by_sku[s] for s in ('PL-MON-S', 'PL-MON-L', 'POT-STONE-S', 'POT-STONE-L'))))

def normalize(html):
    return re.sub(r'value="(\d+)"', lambda m: 'value="' + next((key for key, value in ids.items() if value == m[1]), m[1]) + '"', html)


def check(condition, message):
    global checks
    assert condition, message
    checks += 1

def client():
    return build_opener(HTTPCookieProcessor(CookieJar()))

def get(opener, route):
    route = re.sub(r'(?<=id=)(3008|3009|3023|3024)\b', lambda m: ids[m[0]], route)
    return normalize(opener.open(Request(BASE + route, headers={'Host': '127.0.0.1:8088'}), timeout=10).read().decode())

def post(opener, fields):
    fields = {key: ids.get(value, value) if key in ('product_id', 'pot_id') else value for key, value in fields.items()}
    try:
        response = opener.open(Request(BASE + '/cart/add', urlencode(fields).encode(), headers={'Host': '127.0.0.1:8088'}), timeout=10)
        return response.status, normalize(response.read().decode())
    except HTTPError as error:
        return error.code, normalize(error.read().decode())

def token(html):
    return re.search(r'name="csrf" value="([^"]+)"', html).group(1)

def items(html):
    return set(re.findall(r'name="product_id" value="(\d+)"', html))

shopper = client()
product = get(shopper, '/product?id=3008')
csrf = token(product)
status, basket = post(shopper, {'csrf': csrf, 'product_id': '3008', 'pot_id': '3023', 'quantity': '1'})
check(status == 200 and items(basket) == {'3008', '3023'}, 'One add submits both actual selected products')
check('R330.00' in basket, 'Basket computes pair price from server catalogue values')
status, rejected = post(shopper, {'csrf': csrf, 'product_id': '3009', 'pot_id': '3023', 'quantity': '1'})
check(status == 422 and items(rejected) == {'3008', '3023'}, 'Incompatible pot rejection does not partially add the new plant')
for pot in ('999999999', 'invalid', '3008'):
    status, rejected = post(shopper, {'csrf': csrf, 'product_id': '3009', 'pot_id': pot, 'quantity': '1'})
    check(status == 422 and items(rejected) == {'3008', '3023'}, 'Unknown/malformed/non-pot identity leaves basket unchanged')
status, _ = post(shopper, {'csrf': 'invalid-token', 'product_id': '3009', 'pot_id': '3024', 'quantity': '1'})
check(status == 419 and items(get(shopper, '/cart')) == {'3008', '3023'}, 'CSRF rejection leaves basket unchanged')
empty = client()
csrf2 = token(get(empty, '/product?id=3008'))
status, rejected = post(empty, {'csrf': csrf2, 'product_id': '3008', 'pot_id': '3023', 'quantity': '6'})
check(status == 422 and items(rejected) == set(), 'Pot stock failure cannot leave the plant partially added')
status, basket = post(empty, {'csrf': csrf2, 'product_id': '3009', 'quantity': '1'})
check(status == 422 and items(basket) == set(), 'Plant without a pot is rejected without changing basket')
check('Plant only' not in product, 'No plant-only choice is offered')
zero = get(shopper, '/product?id=3008&pot_id=0')
check('name="pot_id" value="3023"' in zero, 'Old zero-pot URL resolves to compatible default')
try:
    get(shopper, '/product?id=3009&pot_id=3023')
    raise AssertionError('Incompatible pot unexpectedly accepted on product route')
except HTTPError as error:
    check(error.code == 422, 'Forged product choice rejected before presentation')
print(f'Product pair HTTP checks: {checks} passed, 0 failed.')
