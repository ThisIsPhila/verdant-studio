#!/usr/bin/env python3
"""Phase 007 HTTP checks, restricted to the named seeded loopback test database."""
import http.cookiejar
import os
import json
import subprocess
from pathlib import Path
import sys
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser

BASE = 'http://127.0.0.1:8092'
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None
class Forms(HTMLParser):
    def __init__(self):
        super().__init__(); self.forms=[]; self.current=None
    def handle_starttag(self, tag, attrs):
        a=dict(attrs)
        if tag=='form': self.current={'action':a.get('action','')}
        elif tag=='input' and self.current is not None and a.get('name'):
            if a.get('type') not in ('checkbox','radio') or 'checked' in a:
                self.current[a['name']]=a.get('value','')
    def handle_endtag(self, tag):
        if tag=='form' and self.current is not None:
            self.forms.append(self.current);self.current=None

def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),NoRedirect())
STORE_HOST='127.0.0.1:8088';ADMIN_HOST='admin.verdant.localhost:8088'
def request(c,path,fields=None,host=None):
    data=None if fields is None else urllib.parse.urlencode(fields).encode()
    if host is None: host=ADMIN_HOST if (path.startswith('/admin') ) else STORE_HOST
    try: r=c.open(urllib.request.Request(BASE+path,data=data,headers={'Host':host}),timeout=8)
    except urllib.error.HTTPError as e: r=e
    return r.code,r.headers,r.read().decode()
def forms(body):
    p=Forms();p.feed(body);return p.forms
def form(body,action):
    return next((f for f in forms(body) if f['action']==action),{})
def login(c,email,admin_host=False):
    host=ADMIN_HOST if admin_host else STORE_HOST
    code,h,body=request(c,'/login',None,host)
    token=form(body,'/login').get('csrf','')
    status,headers,_=request(c,'/login',{'csrf':token,'email':email,'password':'VerdantDemo!2026'},host)
    location=headers.get('Location','')
    if location in ('/admin/2fa','/admin/2fa/setup'):
        return complete_admin_2fa(c,email,location)
    return status
def complete_admin_2fa(c,email,location):
    import admin_2fa
    db=os.getenv('HTTP_TEST_DATABASE')
    if location=='/admin/2fa/setup':
        status,_,body=request(c,'/admin/2fa/setup')
        assert status==200 and 'Set up two-factor' in body,'Two-factor setup did not load.'
        secret=admin_2fa.credential_secret(db,email)
        token=form(body,'/admin/2fa/setup').get('csrf','')
        status,_,body=request(c,'/admin/2fa/setup',{'csrf':token,'code':admin_2fa.current_code(secret)})
        assert status==200 and 'Save your backup codes' in body,'Two-factor setup did not complete.'
    status,headers,body=request(c,'/admin/2fa')
    if status==303:
        return status
    assert status==200,'Two-factor challenge did not load.'
    secret=admin_2fa.credential_secret(db,email)
    token=form(body,'/admin/2fa').get('csrf','')
    status,headers,_=request(c,'/admin/2fa',{'csrf':token,'code':admin_2fa.current_code(secret)})
    assert status==303 and headers.get('Location')=='/admin','Two-factor challenge did not sign in.'
    return status

def main():
    if os.getenv('APP_ENV')!='local' or os.getenv('HTTP_TEST_DATABASE')!='verdant_test_phase007_http':
        raise RuntimeError('Use only the explicitly named isolated local admin HTTP database.')
    fixture=json.loads(subprocess.check_output(['php',str(Path(__file__).with_name('admin_http_fixture.php'))],text=True,timeout=10))
    count=0
    def check(ok,label):
        nonlocal count
        if not ok: raise AssertionError(label)
        count+=1;print('PASS:',label)
    anonymous=client();customer=client();admin=client()
    pages=['/admin','/admin/products','/admin/categories','/admin/customers','/admin/customer?id=1001','/admin/orders','/admin/order?id=5001','/admin/rentals','/admin/rental?id=6001']
    for path in pages:
        code,h,b=request(anonymous,path)
        check(code==303 and h.get('Location','').startswith('/login'),'Anonymous access redirects: '+path)
    check(login(customer,'customer@example.test')==303,'Synthetic customer signs in.')
    for path in pages:
        code,h,_=request(customer,path)
        check(code==303 and h.get('Location','').startswith('/login'),'Customer cannot access: '+path)
    check(login(admin,'admin@example.test',True)==303,'Synthetic administrator signs in.')
    for path in pages:
        code,h,b=request(admin,path)
        check(code==200,'Administrator reads: '+path)
        check('password_hash' not in b and '$2y$' not in b,'No credential fields exposed: '+path)
    for path in ['/admin/product/save','/admin/product/archive','/admin/category/save','/admin/category/archive','/admin/customer/archive','/admin/order/fulfil','/admin/order/cancel','/admin/order/archive','/admin/rental/issue','/admin/rental/return','/admin/rental/cancel','/admin/rental/archive']:
        check(request(admin,path)[0]==405,'Mutation rejects GET: '+path)
        check(request(admin,path,{})[0]==419,'Mutation rejects missing CSRF: '+path)
    check(request(admin,'/admin/product?id=not-an-id')[0] in (400,404,422),'Malformed product identifier rejected.')
    check(request(admin,'/admin/customer?id=999999999')[0]==404,'Missing customer detail returns 404.')
    check(request(admin,'/admin/order?id=999999999')[0]==404,'Missing order detail returns 404.')
    check(request(admin,'/admin/rental?id=999999999')[0]==404,'Missing rental detail returns 404.')
    code,h,b=request(admin,'/admin/product?id='+str(fixture['product']))
    product_form=form(b,'/admin/product/save')
    stock_input={**product_form,'category_id':'2001','sku':fixture['sku'],'name':'HTTP fixture','description':'Synthetic original rental display',
        'light_level':'indirect','watering_level':'keep_moist','toxicity':'non_toxic','image_path':'','buy_enabled':'0','rent_enabled':'1','rental_daily_rate':'7.50','on_hand':'0'}
    check(request(admin,'/admin/product/save',stock_input)[0]==422,'Stock reduction below existing rental commitments is rejected over HTTP.')
    check(request(admin,'/admin/product/save',{**stock_input,'product_id':'invalid'})[0]==422,'Malformed mutation identifier returns field feedback.')
    code,h,b=request(admin,'/admin/categories')
    cat_form=form(b,'/admin/category/save')
    check(request(admin,'/admin/category/save',{**cat_form,'name':'Indoor Plants'})[0]==422,'Duplicate category name returns validation feedback.')
    check(request(admin,'/admin/category/archive',{'csrf':cat_form.get('csrf',''),'category_id':'2001','archive':'1'})[0]==422,'Category with active products cannot be archived.')
    code,h,b=request(admin,'/admin/order?id='+str(fixture['order']))
    fulfil=form(b,'/admin/order/fulfil')
    check(bool(fulfil),'Fresh fixture provides a fulfilment form.')
    if fulfil:
        check(request(admin,'/admin/order/fulfil',fulfil)[0]==303,'Paid seeded order can be fulfilled through its protected form.')
        check(request(admin,'/admin/order/fulfil',fulfil)[0]==303,'Repeated fulfilment is accepted idempotently.')
    code,h,b=request(admin,'/admin/rental?id='+str(fixture['rental']))
    return_form=form(b,'/admin/rental/return')
    check(bool(return_form),'Fresh fixture provides an actual return form.')
    if return_form:
        check(request(admin,'/admin/rental/return',return_form)[0]==303,'Physical return records through the protected administrator form.')
        check(request(admin,'/admin/rental/return',return_form)[0]==303,'Repeated return uses the same idempotency key.')
        code,h,b=request(admin,'/admin/rental?id='+str(fixture['rental']))
        check('already recorded' in b and not form(b,'/admin/rental/return'),'Completed return cannot release outstanding quantity twice.')
    _,_,admin_surface_login=request(customer,'/login',None,ADMIN_HOST)
    customer_csrf=form(admin_surface_login,'/login').get('csrf','')
    code,h,_=request(customer,'/admin/rental/archive',{'csrf':customer_csrf,'rental_id':'6001','archived':'1'})
    check(code==303 and h.get('Location','').startswith('/login'),'Customer with valid CSRF is still denied an admin mutation.')
    intruder=client()
    check(login(intruder,'customer@example.test',True)==422,'Customer credentials are rejected on the admin surface without a session.')
    code,h,b=request(admin,'/admin/access')
    check(code==200 and 'Access allowlist' in b and 'admin@example.test' in b,'Allowlist page lists administrators.')
    check(request(admin,'/admin/access/add')[0]==405 and request(admin,'/admin/access/remove')[0]==405,'Allowlist mutations reject GET.')
    check(request(admin,'/admin/access/add',{})[0]==419,'Allowlist add rejects missing CSRF.')
    access_form=form(b,'/admin/access/add')
    check(request(admin,'/admin/access/add',{**access_form,'email':'customer@example.test'})[0]==422,'Customer address cannot join the allowlist.')
    check(request(admin,'/admin/access/add',{**access_form,'email':'not-an-email'})[0]==422,'Malformed address cannot join the allowlist.')
    check(request(admin,'/admin/access/remove',{**form(b,'/admin/access/remove'),'email':'admin@example.test'})[0]==422,'Self-removal from the allowlist is refused.')
    code,h,_=request(admin,'/admin/access/add',{**access_form,'email':fixture['admin_email']})
    check(code==303 and 'notice=' in h.get('Location',''),'Fellow administrator joins the allowlist.')
    scratch=client()
    check(login(scratch,fixture['admin_email'],True)==303,'Newly listed administrator signs in.')
    code,h,b=request(admin,'/admin/access')
    check(fixture['admin_email'] in b,'New listing renders on the allowlist page.')
    code,h,_=request(admin,'/admin/access/remove',{**form(b,'/admin/access/remove'),'email':fixture['admin_email']})
    check(code==303,'Fellow administrator leaves the allowlist.')
    check(request(scratch,'/admin')[0]==303,'Removed administrator loses the live session.')
    removed=client()
    check(login(removed,fixture['admin_email'],True)==422,'Removed administrator can no longer sign in.')
    print(f'Admin HTTP checks: {count} passed, 0 failed.')
if __name__=='__main__':
    try:main()
    except Exception as e:print('Admin HTTP checks failed:',e,file=sys.stderr);sys.exit(1)
