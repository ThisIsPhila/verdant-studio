#!/usr/bin/env python3
"""Synthetic account acceptance, restricted to the explicitly configured local test server."""
import os,re,http.cookiejar,urllib.request,urllib.parse,urllib.error,secrets,subprocess
if os.getenv('APP_ENV')!='local' or not re.fullmatch(r'verdant_test_[a-z0-9_]+',os.getenv('HTTP_TEST_DATABASE','')):
    raise SystemExit('Use APP_ENV=local and an isolated HTTP_TEST_DATABASE.')
BASE=os.getenv('HTTP_TEST_BASE_URL','http://127.0.0.1:8095')
u=urllib.parse.urlsplit(BASE)
if u.hostname!='127.0.0.1' or u.scheme!='http':raise SystemExit('Loopback HTTP only.')
jar=http.cookiejar.CookieJar();opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
def req(path,data=None):
    q=urllib.request.Request(BASE+path,None if data is None else urllib.parse.urlencode(data).encode(),{'Host':'127.0.0.1:8088'})
    try:r=opener.open(q,timeout=15)
    except urllib.error.HTTPError as e:r=e
    body=r.read().decode();assert not re.search(r'(?:Warning|Fatal error|Deprecated):',body),path
    return r.status,body,r.url
def csrf(body):return re.search(r'name="csrf" value="([a-f0-9]{64})"',body)[1]
checks=0
def check(ok,label):
    global checks
    assert ok,label;checks+=1
status,body,_=req('/account');check(status==200 and 'action="/login"' in body,'Anonymous account redirects to sign-in.')
status,body,_=req('/register');token=csrf(body)
email='phase013-'+secrets.token_hex(8)+'@example.test';pw=secrets.token_hex(16)
status,body,url=req('/register',{'csrf':token,'name':'Phase 013 account fixture','email':email,'password':pw,'password_confirmation':pw,'phone':''})
check(status==200 and 'Check your email' in body,'Registration asks for email verification.')
def vlink(email):
    e=dict(os.environ);e.update(APP_ENV='local',DB_NAME=os.getenv('HTTP_TEST_DATABASE'));p=subprocess.run(['php',os.path.join(os.path.dirname(__file__),'verify_account_fixture.php'),email],capture_output=True,text=True,timeout=15,env=e);assert p.returncode==0,email;link=p.stdout.strip();assert link.startswith('/verify-email?token='),email;return link
status,body,_=req(vlink(email));check(status==200 and 'Email verified' in body,'Verification link signs in.')
status,body,_=req('/account');check('Hello, Phase 013 account fixture' in body,'Registration enters owned account.')
check('No purchases yet' in body and 'No rentals yet' in body,'Empty history is explicit.')
check('/compositions/saved' in body,'Saved arrangement entry exists.')
status,_,_=req('/account/order?id=999999999');check(status==404,'Unavailable/foreign order safe.')
status,_,_=req('/account/rental?id=999999999');check(status==404,'Unavailable/foreign rental safe.')
status,body,_=req('/account');req('/logout',{'csrf':csrf(body)});status,body,_=req('/account');check('action="/login"' in body,'Logout returns anonymous state.')
status,body,_=req('/login');status,body,_=req('/login',{'csrf':csrf(body),'email':email,'password':pw});check('Hello, Phase 013 account fixture' in body,'Sign-in restores account session.')
status,_,_=req('/logout',{'csrf':'0'*64});check(status==419,'Forged CSRF denied.')
print(f'Storefront account HTTP: {checks} passed, 0 failed.')
