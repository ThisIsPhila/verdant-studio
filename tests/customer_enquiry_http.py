#!/usr/bin/env python3
"""Real support submission/access checks against isolated Phase016 data."""
import os
import secrets
import subprocess
from urllib.parse import urlsplit
import information_pages_http as http

if os.getenv('APP_ENV') != 'local' or os.getenv('DB_NAME') != 'verdant_test_phase017':
    raise RuntimeError('Use the isolated verdant_test_phase017 database in local mode.')
if urlsplit(http.BASE).hostname not in {'localhost', '127.0.0.1', '::1'}:
    raise RuntimeError('Use a loopback test server.')
marker = 'enquiry-http-' + secrets.token_hex(8)
php = r'''require 'app/bootstrap.php';
$db = Verdant\Persistence\ConnectionFactory::connect(Verdant\Persistence\DatabaseConfig::fromEnvironment());
$sql = match ($argv[1]) {
    'clean' => 'DELETE FROM customer_enquiries WHERE subject = ?',
    'guest_id' => 'SELECT enquiry_id FROM customer_enquiries WHERE subject = ? AND customer_id IS NULL ORDER BY enquiry_id LIMIT 1',
    'account_id' => 'SELECT enquiry_id FROM customer_enquiries WHERE subject = ? AND customer_id IS NOT NULL ORDER BY enquiry_id DESC LIMIT 1',
    default => 'SELECT COUNT(*) FROM customer_enquiries WHERE subject = ?',
};
$q=$db->prepare($sql); $q->execute([$argv[2]]); if ($argv[1] !== 'clean') echo $q->get_result()->fetch_row()[0];'''
def records(action='count'):
    return subprocess.check_output(['php', '-r', php, action, marker], text=True).strip()
checks=0
def check(value, description):
    global checks
    if not value: raise AssertionError(description)
    checks+=1
    print('PASS:',description)
anon, admin, customer=http.client(),http.client(),http.client()
try:
    status,_,body=http.request(anon,'/contact')
    form=http.get_form(body,'/contact/enquiry')
    check(status==200 and form is not None,'Customer card contains a real enquiry form.')
    fields=dict(form['fields'])
    fields.update(name='Test Shopper',email='shopper@example.test',subject=marker,order_reference='TEST-123',message='Support request <script>alert(1)</script>')
    check(http.request(anon,'/contact/enquiry')[0]==405,'Enquiry endpoint rejects GET.')
    check(http.request(anon,'/contact/enquiry',{})[0]==419,'Missing CSRF is rejected.')
    bad=dict(fields,email='invalid')
    check(http.request(anon,'/contact/enquiry',bad)[0]==303,'Invalid enquiry returns to the form.')
    invalid_body=http.request(anon,'/contact')[2]
    check('Enter a valid email address.' in invalid_body and marker in invalid_body and records()=='0','Validation preserves input without storing an enquiry.')
    check(http.request(anon,'/contact/enquiry',dict(fields,topic='trade'))[0]==303,'Out-of-scope Trade submission is rejected.')
    check(records()=='0','Rejected Trade request is not stored.')
    status,headers,_=http.request(anon,'/contact/enquiry',fields)
    check(status==303 and headers['Location']=='/contact#contact-form-customer','Successful submission redirects to the separate enquiry panel.')
    check(records()=='1','Successful enquiry exists in MySQL.')
    receipt=http.request(anon,'/contact')[2]
    check('Your enquiry has been received.' in receipt,'Receipt confirms actual persistence.')
    check(http.request(anon,'/contact/enquiry',fields)[0]==303 and records()=='1','Repeated POST does not create a duplicate.')
    check(http.request(anon,'/admin/enquiries')[0]==303,'Anonymous inbox access redirects to sign-in.')
    check(http.login(customer,'customer@example.test')==303,'Fixture customer signs in.')
    code, headers, _ = http.request(customer,'/admin/enquiries')
    check(code==303 and headers.get('Location','').startswith('/login'),'Customers cannot access enquiries.')
    signed_contact=http.request(customer,'/contact')[2]
    check('Sending as' in signed_contact and 'We’ll use the details on your account.' in signed_contact
          and 'name="name"' not in signed_contact and 'name="email"' not in signed_contact,
          'Signed-in Contact shows account identity without editable identity fields.')

    # Save a guest draft into the server session, authenticate, and return to
    # Contact through the fixed intent. The draft is not submitted as an enquiry.
    draft_client=http.client()
    _,_,contact_body=http.request(draft_client,'/contact')
    draft_form=http.get_form(contact_body,'/contact/enquiry')
    draft=dict(draft_form['fields'])
    draft.update(name='Draft Shopper',email='draft@example.test',subject='Draft support',
                 order_reference='ORDER-OPTIONAL',message='Saved while signing in.')
    status,headers,_=http.request(draft_client,'/contact/sign-in',draft)
    check(status==303 and headers['Location']=='/login?intent=support','Contact draft uses the fixed support sign-in return.')
    status,_,login_body=http.request(draft_client,'/login?intent=support')
    login_form=http.get_form(login_body,'/login')
    login_fields=dict(login_form['fields'])
    login_fields.update(email='customer@example.test',password='VerdantDemo!2026')
    status,headers,_=http.request(draft_client,'/login',login_fields)
    check(status==303 and headers['Location']=='/contact?intent=support#contact-form-customer','Successful authentication returns to Contact only for the fixed support intent.')
    status,_,restored=http.request(draft_client,headers['Location'])
    check(status==200 and 'Your message has been restored.' in restored and 'Saved while signing in.' in restored
          and 'ORDER-OPTIONAL' in restored,'Session-only enquiry draft is restored after sign-in.')
    check(records()=='1','Saving and restoring a draft does not submit an enquiry.')
    restored_form=http.get_form(restored,'/contact/enquiry')
    restored_fields=dict(restored_form['fields'])
    restored_fields['subject']=marker
    check(http.request(draft_client,'/contact/enquiry',restored_fields)[0]==303,
          'Restored signed-in draft can be submitted.')
    after_send=http.request(draft_client,'/contact?intent=support')[2]
    check('Your message has been restored.' not in after_send and records()=='2',
          'Successful submission clears the saved draft.')

    check(http.login(admin,'admin@example.test',True)==303,'Fixture admin signs in.')
    status,_,inbox=http.request(admin,'/admin/enquiries')
    check(status==200 and marker in inbox and 'TEST-123' in inbox,'Admin inbox shows the stored support enquiry and order reference.')
    check('&lt;script&gt;alert(1)&lt;/script&gt;' in inbox and '<script>alert(1)</script>' not in inbox,'Enquiry message is escaped in the inbox.')
    guest_id=records('guest_id')
    status,_,guest_thread=http.request(admin,'/admin/enquiry?id='+guest_id)
    reply_form=http.get_form(guest_thread,'/admin/enquiry/reply')
    reply_fields=dict(reply_form['fields']); reply_fields['body']='Admin reply saved over HTTP.'
    check(status==200 and marker in guest_thread and reply_form is not None,'Admin opens a private conversation and sees its message history.')
    check(http.request(admin,'/admin/enquiry/reply',reply_fields)[0]==303,'Admin replies with an explicit CSRF-protected idempotent POST.')
    status,_,guest_thread_after=http.request(admin,'/admin/enquiry?id='+guest_id)
    check(status==200 and 'Admin reply saved over HTTP.' in guest_thread_after,'Admin reply appears in the plain-text conversation history.')

    account_id=records('account_id')
    status,_,account_inbox=http.request(customer,'/account/support')
    check(status==200 and marker in account_inbox,'Account support lists the signed-in customer’s owned thread.')
    status,_,account_thread=http.request(customer,'/account/enquiry?id='+account_id)
    check(status==200 and 'Saved while signing in.' in account_thread,'Customer opens their own conversation from Account.')
    check(http.request(customer,'/account/enquiry?id='+guest_id)[0]==404,'Account endpoint rejects a guessed guest conversation ID.')

    status,headers,guest_access=http.request(anon,'/support/access')
    check(status==200 and 'no-store' in headers.get('Cache-Control','') and headers.get('Referrer-Policy')=='no-referrer',
          'Guest link landing is private, uncached and has a no-referrer policy.')
    check('fonts.googleapis.com' not in guest_access and '/assets/js/support/access.js' in guest_access,
          'Guest landing uses only local resources and its fragment-transfer module.')
    access_form=http.get_form(guest_access,'/support/access/confirm')
    bad_status,bad_headers,_=http.request(anon,'/support/access/confirm',dict(access_form['fields'],access_token='invalid'))
    check(bad_status==404 and 'no-store' in bad_headers.get('Cache-Control',''),
          'Invalid guest token confirmation is generic and remains uncached.')
    resend_form=http.get_form(guest_access,'/support/access/resend')
    resend_fields=dict(resend_form['fields']); resend_fields.update(enquiry_id=guest_id,email='shopper@example.test')
    valid_resend=http.request(anon,'/support/access/resend',resend_fields)
    unknown_fields=dict(resend_form['fields']); unknown_fields.update(enquiry_id='999999999',email='shopper@example.test')
    unknown_resend=http.request(anon,'/support/access/resend',unknown_fields)
    check(valid_resend[0]==200 and unknown_resend[0]==200 and valid_resend[2]==unknown_resend[2],
          'Guest resend returns the same public response for valid and unknown enquiries.')
    guest_without_access=http.request(anon,'/support/guest?id='+guest_id)
    check(guest_without_access[0]==404 and 'no-store' in guest_without_access[1].get('Cache-Control',''),
          'Guest route denies guessed IDs without an exchanged token session.')
    print(f'Customer enquiry HTTP checks: {checks} passed, 0 failed.')
finally:
    records('clean')
    if records()!='0': raise AssertionError('Test enquiry cleanup failed.')
