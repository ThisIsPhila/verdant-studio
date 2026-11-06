"""Read-only live-local media integration; no stock/order/account mutations."""
from urllib.request import urlopen
from html.parser import HTMLParser
class Page(HTMLParser):
    def __init__(self, body):
        super().__init__(); self.in_product_image=False; self.scenes=0; self.crops=0; self.placeholders=[]; self.hrefs=[]; self.feed(body)
    def handle_starttag(self, tag, attrs):
        attrs=dict(attrs)
        if tag=='a' and 'product-image' in attrs.get('class',''): self.in_product_image=True
        if 'data-thumbnail-scene' in attrs: self.scenes+=1
        if tag=='svg' and 'product-thumbnail-crop' in attrs.get('class',''): self.crops+=1
        if self.in_product_image and tag=='img' and '/placeholders/' in attrs.get('src',''): self.placeholders.append(attrs['src'])
        if tag=='image': self.hrefs.append(attrs.get('href',''))
    def handle_endtag(self, tag):
        if tag=='a': self.in_product_image=False
checks=0
for route in ['/catalogue','/catalogue?page=2','/catalogue?mode=rent','/']:
    body=urlopen('http://127.0.0.1:8088'+route).read().decode()
    page=Page(body)
    assert page.scenes+page.crops>0, route+' contains real media'; checks+=1
    assert 'catalogue-reference.png' not in body and 'product-thumbnail-crop' not in body, route+' excludes rejected artwork and raw sheet crops';checks+=1
    for path in set(page.hrefs):
        assert urlopen('http://127.0.0.1:8088'+path).status==200;checks+=1
    if route=='/':
        assert 'hero-scenes-v02.png' in body and '0 0 836 470' in body;checks+=1
print(f'Storefront media HTTP checks: {checks} passed, 0 failed.')
