"""Connected support journeys on the isolated local PHP application."""
import os, secrets, subprocess
from urllib.parse import urlsplit
import information_pages_http as http
if os.getenv('DB_NAME') != 'verdant_test_phase017' or urlsplit(http.BASE).hostname not in {'127.0.0.1','localhost'}:
    raise RuntimeError('Isolated loopback Phase017 app required.')
count=0
marker='support-connected-'+secrets.token_hex(8)
def check(ok,message):
    global count
    if not ok: raise AssertionError(message)
    count+=1
    print('PASS:',message)
php=r'''require 'app/bootstrap.php'; $db=Verdant\Persistence\ConnectionFactory::connect(Verdant\Persistence\DatabaseConfig::fromEnvironment()); $s=$db->prepare($argv[1]==='clean'?'DELETE FROM customer_enquiries WHERE subject=?':'SELECT enquiry_id FROM customer_enquiries WHERE subject=?');$s->execute([$argv[2]]);if($argv[1]!=='clean')echo $s->get_result()->fetch_row()[0]??'';'''
def fixture(action='find'):
    return subprocess.check_output(['php','-r',php,action,marker],text=True).strip()
admin,customer,anon=http.client(),http.client(),http.client()
try:
    check(http.login(admin,'admin@example.test',True)==303,'Administrator signs in')
    check(http.login(admin,'admin@example.test')==303,'Administrator storefront session signs in')
    check(http.request(admin,'/account')[0]==200,'Administrator account landing does not load customer-only support')
    check(http.login(customer,'customer@example.test')==303,'Customer signs in')
    status,_,body=http.request(customer,'/contact')
    form=http.get_form(body,'/contact/enquiry')
    fields=dict(form['fields']);fields.update(subject=marker,message='Please help with this order <script>bad()</script>',topic='customer')
    check(http.request(customer,'/contact/enquiry',fields)[0]==303,'Customer submits enquiry')
    id=fixture();check(id.isdigit(),'Enquiry persisted')
    path='/admin/enquiry?id='+id
    status,_,body=http.request(admin,'/admin/enquiries?q='+marker)
    check(status==200 and marker in body and path in body,'Searchable inbox links to conversation')
    status,_,body=http.request(admin,path)
    check(status==200 and '&lt;script&gt;' in body and '<script>bad()' not in body,'Conversation escapes customer content')
    reply=http.get_form(body,'/admin/enquiry/reply')
    check(reply is not None,'Administrator has reply form')
    check(http.request(admin,'/admin/enquiry/reply',dict(reply['fields'],body='We can help.'))[0]==303,'Administrator saves reply')
    status,_,body=http.request(customer,'/account')
    check(status==200 and 'account-support' in body and marker in body and 'New reply' in body,'Account support tab shows unread reply')
    status,_,body=http.request(customer,'/account/enquiry?id='+id)
    check(status==200 and 'We can help.' in body,'Customer reads real reply')
    reply=http.get_form(body,'/account/enquiry/reply')
    check(http.request(customer,'/account/enquiry/reply',dict(reply['fields'],body='Thank you.'))[0]==303,'Customer replies in place')
    check('Thank you.' in http.request(admin,path)[2],'Customer reply reaches administrator')
    code, headers, _ = http.request(customer,path)
    check(code==303 and headers.get('Location','').startswith('/login'),'Customer cannot open admin conversation')
    check(http.request(anon,'/account/enquiry?id='+id)[0]==303,'Anonymous account conversation requires sign-in')
    check(http.request(customer,'/account/enquiry?id=999999999')[0]==404,'Unknown conversation is unavailable')
    check(http.request(customer,'/account/enquiry?id[]=1')[0]==404,'Malformed conversation id is controlled')
    check(http.request(customer,'/account/enquiry/reply',{'enquiry_id':id,'body':'bad'})[0]==419,'Reply requires CSRF')
    check(http.request(customer,'/account/enquiry/reply')[0]==405,'Reply requires POST')
    status,headers,body=http.request(anon,'/support/access')
    check(status==200 and 'no-store' in headers.get('Cache-Control','') and headers.get('Referrer-Policy')=='no-referrer','Guest landing has private cache/referrer policy')
    check('fonts.googleapis.com' not in body and 'access_token' in body,'Guest landing has no third-party resources')
    confirm=http.get_form(body,'/support/access/confirm')
    check(confirm is not None,'Guest exchange requires explicit confirmation POST')
    check(http.request(anon,'/support/access/confirm',dict(confirm['fields'],access_token='0'*64))[0]==404,'Invalid guest token cannot authenticate')
    check(http.request(anon,'/support/guest?id='+id)[0]==404,'Unverified guest cannot read conversation')
    check(http.request(admin,'/admin/enquiry/reply')[0]==405,'Admin reply requires POST')
    check(http.request(admin,'/admin/enquiry/state',{})[0]==419,'Status update requires CSRF')
    guest=http.client()
    guestform=http.get_form(http.request(guest,'/contact')[2],'/contact/enquiry')
    guestfields=dict(guestform['fields']);guestfields.update(name='Guest Reviewer',email='customer@thisisphila.co.za',subject=marker,message='Guest support request',topic='customer')
    check(http.request(guest,'/contact/enquiry',guestfields)[0]==303,'Guest enquiry persists')
    token_php=r"""require 'app/bootstrap.php';$db=Verdant\Persistence\ConnectionFactory::connect(Verdant\Persistence\DatabaseConfig::fromEnvironment());$s=$db->prepare('SELECT e.enquiry_id,o.context_json FROM customer_enquiries e JOIN enquiry_messages m ON m.enquiry_id=e.enquiry_id JOIN email_outbox o ON o.message_id=m.message_id WHERE e.subject=? AND e.customer_id IS NULL AND o.template_key=\'support_guest_access\' ORDER BY o.outbox_id DESC LIMIT 1');$s->execute([$argv[1]]);$r=$s->get_result()->fetch_assoc();$c=json_decode($r['context_json'],true);echo json_encode(['id'=>$r['enquiry_id'],'token'=>Verdant\Support\SupportTokenCipher::open($c['access_token_sealed'])]);"""
    import json
    access=json.loads(subprocess.check_output(['php','-r',token_php,marker],text=True))
    landing=http.request(guest,'/support/access')[2]
    confirm=http.get_form(landing,'/support/access/confirm')
    check(http.request(guest,'/support/access/confirm',dict(confirm['fields'],access_token=access['token']))[0]==303,'Valid token exchanges through POST')
    guestpath='/support/guest?id='+str(access['id'])
    status,_,body=http.request(guest,guestpath)
    check(status==200 and 'Guest support request' in body,'Verified guest reads only their own conversation')
    reply=http.get_form(body,'/support/guest/reply')
    check(http.request(guest,'/support/guest/reply',dict(reply['fields'],body='Guest follow-up'))[0]==303,'Verified guest replies')
    check(http.request(guest,'/support/guest?id='+id)[0]==404,'Guest session cannot read account conversation')
    check(http.request(guest,'/support/access/confirm',dict(confirm['fields'],access_token=access['token']))[0]==404,'Consumed token cannot replay')
    print(f'Support HTTP checks: {count} passed')
finally:
    fixture('clean')
