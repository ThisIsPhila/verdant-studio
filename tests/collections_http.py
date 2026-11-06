#!/usr/bin/env python3
"""Read-only collection/filter route verification on the isolated loopback app."""
import re
import urllib.error
import urllib.request

BASE = 'http://127.0.0.1:8095'
checks = 0

def get(path):
    request = urllib.request.Request(BASE + path, headers={'Host': '127.0.0.1:8088'})
    try:
        response = urllib.request.urlopen(request, timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    body = response.read().decode('utf-8')
    assert not re.search(r'(?:Warning|Fatal error|Deprecated):', body), path
    return response.status, body

def check(condition, label):
    global checks
    assert condition, label
    checks += 1

status, body = get('/collections')
check(status == 200 and all('/collections/' + slug in body for slug in ['desk-greenery', 'green-events', 'floral-inspiration']), 'Three collection entry points')
check('href="/collections"' in body, 'Shared navigation entry')
status, body = get('/collections/floral-inspiration')
check(status == 200 and 'Purchased components' in body and 'Rented components' in body, 'Mixed floral breakdown')
check('/configure?code=starter-bouquet-rental-container' in body, 'Configured offer entry')
check(get('/configure?code=starter-bouquet-rental-container')[0] == 200, 'Configured offer destination is available')
status, body = get('/collections/floral-inspiration?kind=cut_flower&mode=rent')
check(status == 200 and 'No products match these filters' in body, 'No rentable flowers')
status, body = get('/catalogue?kind=cut_flower&mode=buy')
check(status == 200 and 'value="cut_flower" checked' in body and 'name="kind"' in body, 'Extended catalogue filters')
for path in ['/collections/does-not-exist', '/collections/%3Cscript%3E']:
    status, body = get(path)
    check(status == 404 and '<script>' not in body, 'Unavailable collection safely rendered')
for path in ['/catalogue?size=unknown', '/catalogue?colour=UNKNOWN', '/collections/desk-greenery?budget=-1', '/collections/desk-greenery?kind%5B%5D=plant']:
    status, body = get(path)
    check(status == 422 and 'Please check your filters' in body, 'Invalid filter rejected: ' + path)
status, body = get('/catalogue?q=plant')
check(status == 200 and 'id="catalogue-search"' in body and 'Search: plant' in body, 'Catalogue search box and chip')
check('name="q" value="plant"' in body and 'name="product_id"' in body, 'Search preserves the query and keeps add-to-basket cards')
check('q=plant' in body, 'Search term flows into filter links')
status, body = get('/catalogue?q=zzz-no-such-plant-xyz')
check(status == 200 and 'No products match these filters' in body and 'Search: zzz-no-such-plant-xyz' in body, 'Empty search renders the empty state with its chip')
status, body = get('/catalogue?q=%25')
check(status == 200 and 'No products match these filters' in body, 'Search wildcards are escaped, not expanded')
status, body = get('/catalogue?q=' + 'x' * 121)
check(status == 422 and 'Please check your filters' in body, 'Overlong search is rejected')
status, body = get('/catalogue?mode=rent&q=plant')
check(status == 200 and 'id="rental-search"' in body and 'name="mode" value="rent"' in body, 'Rental search stays scoped to rentals')
status, body = get('/collections/desk-greenery?q=plant')
check(status == 200 and 'id="collection-search"' in body and 'Search: plant' in body, 'Collection search stays scoped to the collection')
check('vision-header__search' in body and 'id="header-search"' in body, 'Header search entry is present')
print(f'Collections HTTP: {checks} passed, 0 failed')
