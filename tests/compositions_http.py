#!/usr/bin/env python3
"""Composition form, CSRF and ownership checks against isolated loopback8095."""
import json
import os
import subprocess
from catalogue_variants_http import client, request, form, login

if os.getenv('APP_ENV') != 'local' or os.getenv('DB_NAME') != 'verdant_test_phase012':
    raise RuntimeError('Use only the isolated Phase012 database.')
accounts = json.loads(subprocess.check_output(['php', 'tests/catalogue_variants_http_fixture.php'], text=True))
try:
    metadata = json.loads(subprocess.check_output(['php', '-r', "require 'app/bootstrap.php'; $db=Verdant\\Persistence\\ConnectionFactory::connect(Verdant\\Persistence\\DatabaseConfig::fromEnvironment()); echo json_encode(array_column($db->query(\"SELECT sku, product_id FROM products WHERE sku IN ('PL-MON-S','PL-MON-L','FL-ROSE','POT-STONE-S')\")->fetch_all(MYSQLI_ASSOC),'product_id','sku'));"], text=True))
    count = 0

    def check(condition, label):
        global count
        if not condition:
            raise RuntimeError(label)
        count += 1

    guest, owner, other = client(), client(), client()
    check(request(guest, '/collections')[0] == 200, 'Collection index route')
    check(request(guest, '/collections/desk-greenery')[0] == 200, 'Single-segment collection detail route')
    check(request(guest, '/collections/desk-greenery/extra')[0] == 404, 'Collection route rejects extra path segments')
    status, _, body = request(guest, '/compositions?code=starter-custom-plant-arrangement')
    check(status == 200 and 'configuration-media-preview' in body and 'checked' in body,
          'Arrangement opens with a default selection and preview')
    path = '/compositions?code=starter-custom-plant-arrangement'
    check(request(guest, '/compositions')[0] == 200, 'Arrangement listing')
    status, _, body = request(guest, path)
    check(status == 200 and 'selection[3][product_id]' in body, 'Multiple component slots')
    fields = {'csrf': form(body, '/compositions/quote')['csrf'], 'code': 'starter-custom-plant-arrangement'}
    for i, sku in enumerate(['PL-MON-S', 'PL-MON-L']):
        fields.update({f'selection[{i}][{key}]': value for key, value in {
            'component_key': 'plant', 'product_id': metadata[sku], 'mode': 'sale', 'quantity': '1'}.items()})
    status, _, body = request(guest, '/compositions/quote', fields)
    check(status == 200 and 'Estimated total:' in body, 'Multi-component quote')
    check(request(guest, '/compositions/quote', fields | {'csrf': 'invalid'})[0] == 419, 'CSRF')
    check(request(guest, '/compositions/quote', fields | {'selection[0][quantity]': 'invalid'})[0] == 422, 'Malformed quantity form error')
    check(request(guest, '/compositions/prepare', fields)[0] == 303, 'Anonymous preparation blocked')
    check(request(guest, '/compositions/prepare')[0] == 405, 'Preparation POST only')
    check(login(owner, accounts['customer_email'], accounts['customer_password']) == 303, 'Customer login')
    check(login(other, accounts['admin_email'], accounts['admin_password'], True) == 303, 'Other identity login')
    check(request(guest, '/admin/compositions')[0] == 303, 'Admin requires sign-in')
    check(request(owner, '/admin/compositions')[0] == 303, 'Customer cannot edit definitions')
    status, _, admin_body = request(other, '/admin/compositions')
    check(status == 200 and '<h1>Arrangements</h1>' in admin_body and '/admin/compositions?edit=' in admin_body, 'Admin definitions route')
    import re
    definition_id = re.search(r'/admin/compositions\?edit=(\d+)', admin_body).group(1)
    _, _, admin_body = request(other, '/admin/compositions?edit=' + definition_id)
    admin_token = form(admin_body, '/admin/composition/save')['csrf']
    check(request(other, '/admin/composition/save', {'csrf': 'invalid'})[0] == 419, 'Admin CSRF')
    status, _, admin_body = request(other, '/admin/composition/save', {'csrf': admin_token,
        'definition_id': definition_id, 'name[]': 'bad', 'status': 'active', 'components[0]': 'bad'})
    check(status == 422 and 'Warning' not in admin_body and 'Fatal error' not in admin_body,
        'Malformed admin form must render usable422')
    check(login(other, accounts['admin_email'], accounts['admin_password']) == 303, 'Other identity store login')
    fields['csrf'] = form(request(owner, path)[2], '/compositions/quote')['csrf']
    status, headers, _ = request(owner, '/compositions/save', fields | {'name': 'Two plants'})
    check(status == 303 and 'saved=' in headers.get('Location', ''), 'Save selection')
    saved_id = headers['Location'].split('saved=')[1]
    status, _, body = request(owner, '/compositions?saved_id=' + saved_id)
    check(status == 200 and body.count('selected') >= 2, 'Saved choice reload')
    check(request(other, '/compositions?saved_id=' + saved_id)[0] == 404, 'Saved owner scope')
    other_csrf = form(request(other, path)[2], '/compositions/quote')['csrf']
    check(request(other, '/compositions/saved/archive', {'csrf': other_csrf, 'saved_composition_id': saved_id})[0] == 404, 'Other identity cannot archive')
    status, headers, _ = request(owner, '/compositions/prepare', fields)
    check(status == 303, 'Prepare arrangement')
    checkout_path = headers['Location']
    check(request(owner, checkout_path)[0] == 200, 'Owner checkout reload')
    check(request(other, checkout_path)[0] == 404, 'Checkout owner scope')
    check(request(owner, '/compositions/prepare', fields)[1].get('Location') == checkout_path, 'Idempotent replay')
    cancel_fields = fields | {'selection[0][quantity]': '2'}
    status, headers, _ = request(owner, '/compositions/prepare', cancel_fields)
    check(status == 303, 'Second unstarted composition checkout')
    cancel_path = headers['Location']
    status, _, cancel_body = request(owner, cancel_path)
    check(status == 200 and 'Cancel arrangement checkout' in cancel_body and 'Pay for arrangement' in cancel_body,
          'Pending checkout offers owner-scoped payment and cancellation actions')
    checkout_id = re.search(r'name="composition_checkout_id" value="(\d+)"', cancel_body).group(1)
    check(request(owner, '/compositions/payment/start')[0] == 405, 'Aggregate payment start is POST only')
    check(request(owner, '/compositions/payment/start', {'csrf': 'invalid', 'composition_checkout_id': checkout_id})[0] == 419,
          'Aggregate payment start enforces CSRF before provider initialization')
    other_start_token = form(request(other, path)[2], '/compositions/quote')['csrf']
    check(request(other, '/compositions/payment/start', {'csrf': other_start_token,
          'composition_checkout_id': checkout_id})[0] == 404, 'Other customer cannot start aggregate payment')
    cancel_token = form(cancel_body, '/compositions/cancel')['csrf']
    check(request(owner, '/compositions/cancel', {'csrf': 'invalid', 'composition_checkout_id': checkout_id})[0] == 419,
          'Composition cancellation enforces CSRF')
    check(request(other, '/compositions/cancel', {'csrf': form(request(other, path)[2], '/compositions/quote')['csrf'],
          'composition_checkout_id': checkout_id})[0] == 404, 'Other customer cannot cancel composition checkout')
    status, headers, _ = request(owner, '/compositions/cancel', {'csrf': cancel_token, 'composition_checkout_id': checkout_id})
    check(status == 303 and headers.get('Location') == cancel_path, 'Owner cancellation releases the unstarted composition checkout')
    check('cancelled' in request(owner, cancel_path)[2].lower(), 'Cancelled checkout remains visible as history')
    check(request(owner, '/compositions/saved/archive', {'csrf': fields['csrf'], 'saved_composition_id': saved_id})[0] == 303, 'Owner archive')
    check(request(owner, '/compositions?saved_id=' + saved_id)[0] == 404, 'Archived save inaccessible')
    fields = {k: v for k, v in fields.items() if not k.startswith('selection[1]')}
    status, _, body = request(owner, '/compositions/options', fields | {'code': 'starter-plant-compatible-pot'})
    check(status == 200 and 'Stone' in body, 'Compatible pot refresh without complete quote')
    from datetime import date, timedelta
    start = (date.today() + timedelta(days=10)).isoformat()
    end = (date.today() + timedelta(days=12)).isoformat()
    mixed = {'csrf': fields['csrf'], 'code': 'starter-bouquet-rental-container'}
    for i, (key, sku, mode) in enumerate([('flower', 'FL-ROSE', 'sale'), ('container', 'POT-STONE-S', 'rent')]):
        mixed.update({f'selection[{i}][{field}]': value for field, value in {
            'component_key': key, 'product_id': metadata[sku], 'mode': mode, 'quantity': '1',
            'start_date': start, 'end_date_inclusive': end}.items()})
    status, _, body = request(owner, '/compositions/quote', mixed)
    check(status == 200 and 'return required' in body and 'no rental return' in body and 'Warning' not in body,
          'Mixed quote must distinguish return duties and inclusive dates without warnings')
    check(request(owner, '/compositions/quote', mixed | {'selection[0][mode]': 'rent'})[0] == 422, 'Flowers cannot be rented')
finally:
    subprocess.check_call(['php', 'tests/compositions_http_cleanup.php', accounts['customer_email']])
print(f'Composition HTTP checks: {count} passed, 0 failed.')
