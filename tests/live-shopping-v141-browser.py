#!/usr/bin/env python3
"""Live featured product view and CTA source tests using real Chromium and mocked API responses."""
from pathlib import Path
from urllib.parse import parse_qs,urlparse
import json,os
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('RUNNER_TEMP','/tmp'))/'azh_live_shop_v141'
OUT.mkdir(parents=True,exist_ok=True)
FEATURE={'roomId':'a'*32,'id':'b'*32,'promotionId':'c'*32,'title':'Live Feature Product',
 'description':'Approved product, creator link and safe source attribution.',
 'priceCents':2899,'checkoutMode':'external','checkoutUrl':'https://vendor.example/checkout',
 'stripeReady':False,'localEnabled':True,
 'fulfillment':{'inStock':True,'shippingEnabled':False,'shippingRate':'flat','shippingFeeCents':0}}
HTML='''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><base href="https://azhottie.test/"></head>
<body style="margin:0;padding:16px;background:#fff7fa"><main style="max-width:550px;margin:0 auto">
<section id="azh-live-commerce" class="azh-live-commerce" data-csrf="fixture-token" hidden>
<h2 data-commerce-title></h2><p data-commerce-description></p><b data-commerce-price></b><p data-commerce-shipping></p>
<a data-commerce-checkout href="https://vendor.example/checkout" target="_blank">Visit seller checkout</a>
<form data-stripe-checkout hidden><button type="submit">Secure checkout</button></form>
<button type="button" data-local-order hidden>Cash pickup</button></section></main></body></html>'''
with sync_playwright() as p:
 opts={'headless':True,'args':['--no-sandbox']}
 if Path('/usr/bin/chromium').exists():opts['executable_path']='/usr/bin/chromium'
 else:opts['channel']='chrome'
 browser=p.chromium.launch(**opts)
 for width in (1440,390,320):
  page=browser.new_page(viewport={'width':width,'height':850})
  calls=[];errors=[]
  page.on('pageerror',lambda e:errors.append(str(e)))
  def intercept(route):
   req=route.request
   action=parse_qs(urlparse(req.url).query).get('action',[''])[0]
   if action=='liveFeature':
    assert req.method=='GET',req.method
    body={'ok':True,'data':FEATURE}
   elif action=='liveEngagement':
    assert req.method=='POST' and req.headers.get('x-csrf-token')=='fixture-token'
    event=json.loads(req.post_data or '{}').get('event')
    assert event in ('view','click'),event
    calls.append(event)
    body={'ok':True,'data':{'tracked':True}}
   else:raise AssertionError('Unexpected API action: '+action)
   route.fulfill(status=200,content_type='application/json',body=json.dumps(body))
  page.route('**/commerce-api.php?*',intercept)
  page.route('https://vendor.example/**',lambda route:route.fulfill(status=200,body='ok'))
  page.on('popup',lambda popup: popup.close())
  page.set_content(HTML)
  page.evaluate("""()=>document.querySelector('[data-commerce-checkout]').addEventListener('click',event=>event.preventDefault())""")
  page.add_style_tag(path=str(ROOT/'assets/commerce.css'))
  page.add_script_tag(path=str(ROOT/'assets/commerce-live.js'))
  page.wait_for_function("document.querySelector('[data-commerce-title]').textContent==='Live Feature Product'",timeout=10000)
  page.wait_for_timeout(1650)
  assert calls.count('view')==1,('visible feature was not tracked exactly once',width,calls)
  page.get_by_role('link',name='Visit seller checkout').click()
  page.wait_for_timeout(250)
  assert calls.count('click')==1,('CTA was not tracked exactly once',width,calls)
  page.get_by_role('link',name='Visit seller checkout').click()
  page.wait_for_timeout(150)
  assert calls.count('click')==1,('duplicate within same feature not de-duplicated client-side',width,calls)
  assert page.evaluate('document.documentElement.scrollWidth <= innerWidth+2'),('overflow',width)
  assert not errors,errors
  page.screenshot(path=str(OUT/f'live_shop_{width}.png'),full_page=True)
  print('Live product view/click JS, CSRF and 30m client dedup PASS',width)
  page.close()
 browser.close()
