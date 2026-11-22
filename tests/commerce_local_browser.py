#!/usr/bin/env python3
"""Real local cash dialog JavaScript in Chrome, two products with independent pickup/delivery."""
from pathlib import Path
from urllib.parse import urlparse,parse_qs
import os,json
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('RUNNER_TEMP','/tmp'))/'azh_cash_browser'
OUT.mkdir(parents=True,exist_ok=True)
A='a'*32;B='b'*32
HTML='''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><base href="https://azhottie.test/"></head>
<body data-local-csrf="fixture-csrf" style="margin:0;padding:12px">
<main><h1>Product cash checkout</h1>
<button id="pickup-a" data-local-order data-local-enabled="1" data-product-id="'''+A+'''" data-product-title="Pickup Product"
 data-price-cents="1000" data-channel="shop" data-csrf="fixture-csrf">Cash pickup for A</button>
<button id="delivery-b" data-local-order data-local-enabled="1" data-product-id="'''+B+'''" data-product-title="Delivery Product"
 data-price-cents="2000" data-channel="shop" data-csrf="fixture-csrf">Cash delivery for B</button>
<button id="disabled" data-local-order data-local-enabled="0" data-product-id="'''+A+'''" hidden>Disabled cash</button>
<button id="receipt-status" data-local-status>Check a cash order</button>
</main></body></html>'''
OPTIONS={
 A:{'productId':A,'available':True,'pickupEnabled':True,'deliveryEnabled':False,'pickupLocation':'Pickup One',
    'pickupAddress':'123 Pickup St','pickupHours':'By confirmation','pickupInstructions':'Wait for staff',
    'deliveryZips':[],'deliveryState':'','deliveryFeeCents':0,'deliveryMinCents':0,'deliveryInstructions':'',
    'notice':'Unpaid cash request'},
 B:{'productId':B,'available':True,'pickupEnabled':False,'deliveryEnabled':True,'pickupLocation':'',
    'pickupAddress':'','pickupHours':'','pickupInstructions':'',
    'deliveryZips':['85002'],'deliveryState':'AZ','deliveryFeeCents':400,
    'deliveryMinCents':2000,'deliveryInstructions':'Staff confirms timing','notice':'Unpaid cash request'}
}
RECEIPT='f'*64
def run():
    with sync_playwright() as p:
        browser=p.chromium.launch(headless=True,channel='chrome',args=['--no-sandbox'])
        for width in [1440,768,390,320]:
            page=browser.new_page(viewport={'width':width,'height':860})
            requests=[];errors=[]
            page.on('pageerror',lambda error: errors.append(str(error)))
            page.on('dialog',lambda d:d.accept())
            def intercept(route):
                req=route.request;query=parse_qs(urlparse(req.url).query)
                action=query.get('action',[''])[0];pid=query.get('productId',[''])[0]
                data=json.loads(req.post_data) if req.post_data else {}
                requests.append((action,pid,data))
                if action=='options':
                    payload=OPTIONS.get(pid,{'available':False})
                elif action=='create':
                    payload={'receiptToken':RECEIPT,'duplicate':False,
                        'order':{'reference':'AZ-CASH','title':'Pickup Product' if data.get('productId')==A else 'Delivery Product',
                        'method':data.get('method'),'estimatedBeforeTaxCents':
                         (1000 if data.get('productId')==A else 2400),'status':'pending'}}
                elif action=='status':
                    payload={'reference':'AZ-CASH','title':'A cash order','method':'pickup','status':'pending',
                      'paymentStatus':'due','estimatedBeforeTaxCents':1000,'totalCashDueCents':None,
                      'pickupDetails':'','notice':'Awaiting confirmation'}
                elif action=='cancel':payload={'cancelled':True,'reference':'AZ-CASH'}
                else: payload={}
                route.fulfill(status=200,content_type='application/json',body=json.dumps({'ok':True,'data':payload}))
            page.route('**/commerce-local-api.php?*',intercept)
            page.set_content(HTML)
            page.add_style_tag(path=str(ROOT/'assets/commerce-local.css'))
            page.add_script_tag(path=str(ROOT/'assets/commerce-local.js'))
            assert page.locator('#disabled').is_hidden(),width
            page.locator('#pickup-a').click()
            page.locator('#azh-local-form').wait_for()
            assert page.locator('#azh-local-method option').count()==1,('pickup options',width)
            assert page.locator('#azh-local-method').input_value()=='pickup',width
            assert page.locator('#azh-local-address').is_hidden(),width
            page.locator('#azh-local-form [name=name]').fill('Test Buyer A')
            page.locator('#azh-local-form [name=phone]').fill('6025550133')
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),('pickup overflow',width)
            page.locator('#azh-local-form button[type=submit]').click()
            page.locator('#azh-local-receipt-area').wait_for(state='visible',timeout=8000)
            assert page.locator('#azh-local-receipt-token').input_value()==RECEIPT
            assert any(a=='options' and pid==A for a,pid,_ in requests),('A options not product-scoped',width)
            assert any(a=='create' and data.get('productId')==A and data.get('method')=='pickup'
                       for a,_,data in requests),('pickup request missing',width)
            page.locator('#azh-local-check-receipt').click()
            page.locator('#azh-local-status-result').wait_for(state='visible',timeout=8000)
            assert 'Awaiting confirmation' in page.locator('#azh-local-status-result').inner_text()
            page.locator('.azh-local-close').click()
            page.locator('#delivery-b').click()
            page.wait_for_function("document.querySelector('#azh-local-method').value==='delivery'",timeout=8000)
            assert page.locator('#azh-local-method option').count()==1,('delivery options',width)
            assert page.locator('#azh-local-method').input_value()=='delivery',width
            assert page.locator('#azh-local-address').is_visible(),width
            page.locator('#azh-local-form [name=name]').fill('Test Buyer B')
            page.locator('#azh-local-form [name=phone]').fill('6025550144')
            page.locator('#azh-local-form [name=street]').fill('456 Delivery Ave')
            page.locator('#azh-local-form [name=city]').fill('Phoenix')
            page.locator('#azh-local-form [name=state]').fill('AZ')
            page.locator('#azh-local-form [name=zip]').fill('85002')
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),('delivery overflow',width)
            page.locator('#azh-local-form button[type=submit]').click()
            page.locator('#azh-local-receipt-area').wait_for(state='visible',timeout=8000)
            assert any(a=='options' and pid==B for a,pid,_ in requests),('B options not product-scoped',width)
            assert any(a=='create' and data.get('productId')==B and data.get('method')=='delivery'
                       and data.get('zip')=='85002' for a,_,data in requests),('delivery request missing',width)
            assert not errors,(width,errors)
            page.screenshot(path=str(OUT/f'cash_checkout_{width}.png'),full_page=True)
            print('Cash pickup/delivery Chrome fixture: ',width,'pass')
            page.close()
        browser.close()
if __name__=='__main__':run()
