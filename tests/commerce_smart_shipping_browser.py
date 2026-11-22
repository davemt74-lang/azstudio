#!/usr/bin/env python3
"""Real Chrome: customer quotes a US parcel, selects actual carrier rate, Stripe gets that rate only."""
from pathlib import Path
from urllib.parse import urlparse,parse_qs
import os,json
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('RUNNER_TEMP','/tmp'))/'azh_smart_shipping_browser'
OUT.mkdir(parents=True,exist_ok=True)
PID='a'*32;QUOTE='b'*32
HTML='''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">
<base href="https://azhottie.test/"></head><body style="margin:0;padding:12px">
<main class="azh-commerce"><h1>Shipping product</h1>
<form data-stripe-checkout data-product-id="'''+PID+'''"
 data-product-title="Creator Shirt" data-price-cents="1999"
 data-channel="shop" data-shipping-mode="carrier" data-csrf="test-csrf">
<button type="submit">Choose Shipping & Checkout</button>
<small role="status" aria-live="polite"></small>
</form></main></body></html>'''
def run():
  with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,channel='chrome',args=['--no-sandbox'])
    for width in [1440,768,390,320]:
      page=browser.new_page(viewport={'width':width,'height':860})
      events=[];errors=[]
      page.on('pageerror',lambda e:errors.append(str(e)))
      def handle(route):
        req=route.request
        action=parse_qs(urlparse(req.url).query).get('action',[''])[0]
        body=json.loads(req.post_data or '{}')
        events.append((action,body))
        if 'commerce-shipping-quote-api.php' in req.url:
          assert action=='quote'
          assert body['productId']==PID and body['zip']=='85002'
          assert body['quantity']==2
          assert body['street']=='146 Example Rd'
          assert len(body['quoteToken'])==32
          payload={'quoteId':QUOTE,'expiresAt':'2090-01-01T00:00:00Z',
            'quantity':2,'unitCents':1999,'currency':'USD',
            'rates':[
              {'id':'rate_USPS123456789','carrier':'USPS','service':'Priority',
                'rateCents':595,'deliveryDays':3,'currency':'USD'},
              {'id':'rate_UPS1234567890','carrier':'UPS','service':'Ground',
                'rateCents':845,'deliveryDays':4,'currency':'USD'},
              {'id':'rate_FEDEX12345678','carrier':'FedEx','service':'Ground',
                'rateCents':1595,'deliveryDays':2,'currency':'USD'}
            ],'notice':'Mock quote'}
          route.fulfill(status=200,content_type='application/json',
            body=json.dumps({'ok':True,'data':payload}))
        elif 'commerce-checkout-api.php' in req.url:
          # Real Stripe must not be contacted by this synthetic browser test.
          assert body['shippingQuoteId']==QUOTE
          assert body['shippingRateId']=='rate_UPS1234567890'
          assert body['quantity']==2
          assert body['productId']==PID
          assert len(body['clientToken'])==32
          for pii in ('name','street','unit','phone','zip','city'):
            assert pii not in body,(pii,body)
          route.fulfill(status=503,content_type='application/json',
            body=json.dumps({'ok':False,'error':{'message':'Fixture stops before real payment.'}}))
        else:route.abort()
      page.route('**/commerce-shipping-quote-api.php?*',handle)
      page.route('**/commerce-checkout-api.php',handle)
      page.set_content(HTML)
      page.add_style_tag(path=str(ROOT/'assets/commerce.css'))
      page.add_style_tag(path=str(ROOT/'assets/commerce-shipping-checkout.css'))
      page.add_script_tag(path=str(ROOT/'assets/commerce-checkout.js'))
      page.add_script_tag(path=str(ROOT/'assets/commerce-shipping-checkout.js'))
      page.get_by_role('button',name='Choose Shipping & Checkout').click()
      page.locator('#azh-smart-shipping-form').wait_for(state='visible',timeout=8000)
      page.locator('[name=quantity]').fill('2')
      page.locator('[name=name]').fill('Private Buyer')
      page.locator('[name=phone]').fill('6025550157')
      page.locator('[name=street]').fill('146 Example Rd')
      page.locator('[name=unit]').fill('Suite 5')
      page.locator('[name=city]').fill('Phoenix')
      page.locator('[name=state]').fill('AZ')
      page.locator('[name=zip]').fill('85002')
      page.get_by_role('button',name='Get live USPS / UPS / FedEx rates').click()
      page.get_by_role('button',name='Choose UPS').wait_for(state='visible',timeout=8000)
      assert page.get_by_role('button',name='Choose USPS').count()==1,width
      assert '8.45' in page.locator('#azh-smart-shipping-rate-list').inner_text()
      assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),('page overflow',width)
      assert page.locator('.azh-smart-shipping-dialog').evaluate('(d)=>d.scrollWidth<=d.clientWidth+2'),('modal width',width)
      page.get_by_role('button',name='Choose UPS').click()
      page.wait_for_function(
          "document.getElementById('azh-smart-shipping-status').textContent.includes('Fixture stops before real payment.')",
          timeout=8000)
      assert len([x for x in events if x[0]=='quote'])==1,width
      assert len([x for x in events if x[0]==''])==1 or any('shippingRateId' in x[1] for x in events),events
      assert not errors,(width,errors)
      page.screenshot(path=str(OUT/f'smart_shipping_{width}.png'),full_page=True)
      print('Customer carrier shipping Chrome rate-to-Stripe fixture PASS',width)
      page.close()
    browser.close()
if __name__=='__main__':run()
