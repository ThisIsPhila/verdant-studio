"""Read-only configuration and session-only catalogue HTTP checks. No orders or payments."""
import re
from datetime import date, timedelta
from html.parser import HTMLParser
from http.cookiejar import CookieJar
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import build_opener, HTTPCookieProcessor
BASE='http://127.0.0.1:8088'
class Forms(HTMLParser):
    def __init__(self):
        super().__init__(); self.forms=[]; self.current=None; self.select=None
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=='form': self.current={'action':a.get('action'),'data':{}}; self.forms.append(self.current)
        elif self.current is not None:
            if tag=='input' and a.get('name') and 'disabled' not in a and (a.get('type')!='radio' or 'checked' in a): self.current['data'][a['name']]=a.get('value','')
            elif tag=='select': self.select=a.get('name')
            elif tag=='option' and self.select and 'disabled' not in a:
                if self.select not in self.current['data'] or 'selected' in a: self.current['data'][self.select]=a.get('value','')
    def handle_endtag(self,tag):
        if tag=='form': self.current=None
        if tag=='select': self.select=None
opener=build_opener(HTTPCookieProcessor(CookieJar()))
def req(path,data=None):
    try: r=opener.open(BASE+path,None if data is None else urlencode(data).encode(),timeout=20)
    except HTTPError as e: r=e
    body=r.read().decode(); assert not re.search(r'(?:Warning|Fatal error|Deprecated):',body),path
    return r.status,body
checks=0
def check(ok,label):
    global checks
    assert ok,label; checks+=1; print('PASS:',label)
def form(body):
    p=Forms();p.feed(body);return next(x['data'] for x in p.forms if x['action']=='/compositions/quote')
status,body=req('/catalogue?kind=plant&sort=price_asc');check(status==200 and '/assets/css/vision/pages.css' in body,'Catalogue uses new PHP skin and supports price ordering.')
status,_=req('/catalogue?sort='+urlencode({'x':'unsafe'})[2:]);check(status==422,'Unknown sort rejected.')
status,body=req('/configure'); data=form(body);check(status==200 and sum(k.endswith('[product_id]') for k in data)==2,'Default configuration resolves two real selected components.')
for plant_id in ['3008','3009']:
    data['selection[0][product_id]']=plant_id
    status,body=req('/compositions/options',data);data=form(body)
    check(status==200 and data.get('selection[1][product_id]') in ['3023','3024'],'Plant change retains or replaces the pot with an actual compatible variant.')
status,_=req('/compositions/options',{k:v for k,v in data.items() if k!='csrf'});check(status==419,'Configuration refresh without CSRF rejected.')
for key in list(data):
    if key.endswith('[mode]'): data[key]='rent'
for i in [0,1]:
    data[f'selection[{i}][start_date]']=(date.today()+timedelta(days=7)).isoformat()
    data[f'selection[{i}][end_date_inclusive]']=(date.today()+timedelta(days=9)).isoformat()
status,body=req('/compositions/quote',data);check(status==200 and 'Estimated total' in body and 'day' in body,'Rental estimate uses real selected dates and server quote.')
data['selection[1][product_id]']=data['selection[0][product_id]'];status,_=req('/compositions/quote',data);check(status==422,'Plant submitted as pot rejected by existing composition validation.')
status,body=req('/account');check(status==200 and 'action="/login"' in body and 'vision-auth-card' in body and 'Your orders</h2>' not in body,'Guest account opens styled sign-in without customer history.')
print(f'Page transfer HTTP: {checks} passed, 0 failed.')
