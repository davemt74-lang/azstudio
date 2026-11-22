#!/usr/bin/env python3
"""Isolated real-Commerce-JS browser test; synthetic data, never a claim of live payment acceptance."""
from pathlib import Path
import json
import os
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('RUNNER_TEMP','/tmp'))/'azh_commerce_browser'
OUT.mkdir(parents=True,exist_ok=True)
PID='a'*32
VID='b'*32
PROMO='c'*32
ORDER='d'*32
def html_for(role):
    admin=role=='admin'
    parts=['''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">
<base href="http://azhottie.test/"></head><body style="margin:0">
<main class="azh-commerce" id="azh-commerce" data-csrf="test-token" data-role="'''+role+'''">
<header class="azh-commerce-hero"><h1>Commerce</h1></header>
<p id="commerce-status"></p><nav class="azh-commerce-tabs">
<button data-tab="products">Products</button><button data-tab="briefs">Creator briefs</button>
<button data-tab="promotions">Video affiliates</button><button data-tab="orders">Orders</button>
<button data-tab="live">Live shopping</button><button data-tab="analytics">Sales analytics</button></nav>
<section data-panel="products">''']
    if admin:
        parts.append('''<form id="commerce-product-form" class="azh-commerce-form"><input name="id"><input name="revision">
<input name="title" required><textarea name="description"></textarea>
<input name="priceDollars" type="number" step="0.01"><input name="commissionPercent" type="number" step="0.01">
<input name="checkoutUrl"><select name="checkoutMode"><option value="external">External</option><option value="stripe">Stripe</option></select><select name="state"><option value="active">Active</option></select>
<details id="azh-product-local-settings"><summary>Cash and local fulfillment per product</summary>
<input name="localRevision" value="0">
<input name="localEnabled" type="checkbox"><input name="pickupEnabled" type="checkbox"><input name="deliveryEnabled" type="checkbox">
<input name="pickupLocation"><input name="pickupAddress"><input name="pickupHours"><input name="pickupInstructions">
<input name="deliveryZips"><input name="deliveryState" value="AZ">
<input name="deliveryFeeDollars" type="number" value="0"><input name="deliveryMinDollars" type="number" value="0">
<input name="deliveryInstructions"></details>
<details id="azh-product-shipping-settings"><summary>Inventory and shipping</summary>
<input name="stockRevision" value="0"><input name="sku">
<select name="stockMode"><option value="unlimited">Unlimited</option><option value="tracked">Tracked</option><option value="made_to_order">Made to order</option></select>
<input name="stockOnHand" type="number" step="1" value="0"><input name="lowStockThreshold" type="number" value="2">
<input name="stockAdjustmentReason"><input name="shippingEnabled" type="checkbox">
<select name="shippingRate"><option value="flat">Flat</option><option value="free">Free</option></select>
<input name="shippingFeeDollars" type="number" step="0.01" value="0.00">
<input name="freeAboveDollars" type="number" step="0.01" value="0.00">
<input name="weightGrams" type="number" value="0"><input name="lengthMm" type="number" value="0">
<input name="widthMm" type="number" value="0"><input name="heightMm" type="number" value="0">
<input name="handlingDays" type="number" value="3"><input name="shipCountry" value="US">
<p id="azh-shipping-stock-snapshot"></p></details>
<button type="submit">Save</button><button type="reset" data-reset-product>Reset</button></form>''')
    parts.append('''<div id="commerce-products-list"></div></section>
<section data-panel="briefs" hidden>''')
    if admin:
        parts.append('''<form id="commerce-brief-form" class="azh-commerce-form"><select name="productId" data-products></select><input name="title">
<textarea name="instructions"></textarea><select name="state"><option value="active">Active</option></select>
<button type="submit">Save brief</button></form>''')
    parts.append('<div id="commerce-brief-list"></div>')
    if admin:parts.append('<div id="commerce-interest-list"></div>')
    parts.append('</section><section data-panel="promotions" hidden>')
    if not admin:
        parts.append('''<form id="commerce-promotion-form" class="azh-commerce-form"><select name="productId" data-approved-products></select>
<select name="videoId" data-videos></select><button type="submit">Request</button></form>''')
    parts.append('<div id="commerce-promotion-list"></div></section><section data-panel="orders" hidden>')
    if admin:
        parts.append('''<form id="commerce-order-form" class="azh-commerce-form"><select name="productId" data-products></select>
<input name="externalReference"><select name="promotionId" data-promotions></select>
<input name="quantity" type="number" value="1"><button type="submit">Record</button></form>''')
    parts.append('<div id="commerce-orders-list"></div></section><section data-panel="analytics" hidden><div id="commerce-analytics"></div></section>')
    if admin:
        parts.append('''<section data-panel="live" hidden><form id="commerce-live-form" class="azh-commerce-form">
<select name="productId" data-active-products></select><select name="promotionId" data-live-promotions></select><button type="submit">Feature Live</button></form>
<p id="commerce-live-current"></p></section>''')
    parts.append('</main></body></html>')
    return ''.join(parts)

def state_for(role):
    admin=role=='admin'
    product={'id':PID,'title':'Sample Product','description':'Creator-facing product overview',
        'priceCents':1250,'currency':'USD','checkoutUrl':'https://seller.example/checkout',
        'state':'active','checkoutMode':'external','commissionBps':1000,'revision':1,
        'localEnabled':True,'localSettings':{'revision':1,'enabled':True,'pickupEnabled':True,
        'deliveryEnabled':False,'pickupLocation':'Sample Pickup A','pickupAddress':'123 Example St, Phoenix AZ',
        'pickupHours':'By confirmation','pickupInstructions':'Wait for confirmation','deliveryZips':'',
        'deliveryState':'AZ','deliveryFeeCents':0,'deliveryMinCents':0,'deliveryInstructions':''},
        'fulfillment':{'stockMode':'tracked','inStock':True,'available':8,'lowStock':False,
            'shippingEnabled':True,'shippingFeeCents':500,'freeAboveCents':0,
            'handlingDays':2,'shippingRate':'flat'},
        'fulfillmentSettings':{'sku':'SAMPLE-001','revision':1,'stockMode':'tracked',
            'onHand':8,'reserved':0,'available':8,'stockOnHand':8,'lowStockThreshold':2,
            'shippingEnabled':True,'shippingRate':'flat','shippingFeeCents':500,
            'freeAboveCents':0,'weightGrams':500,'lengthMm':200,'widthMm':150,'heightMm':100,
            'handlingDays':2},
        'brief':{'title':'Creator Spotlight','instructions':'Create a short video review','state':'active','revision':1},
        'myInterest':None if not admin else None}
    return {'role':role,'products':[product],
        'interests':[{'product_id':PID,'model_id':1,'model_name':'Model A','state':'interested',
                     'product_title':'Sample Product'}] if admin else [],
        'promotions':[{'id':PROMO,'product_id':PID,'model_id':1,'video_id':VID,'video_revision':1,
                       'product_title':'Sample Product','video_title':'My product video','state':'pending',
                       'video_status':'published'}] if admin else [],
        'orders':[{'id':ORDER,'external_reference':'external-order-0001','product_id':PID,
                   'product_title':'Sample Product','state':'recorded','provider':'manual','quantity':1,'amount_cents':1250,
                   'commission_cents':0}] if admin else [],
        'videos':[{'id':VID,'title':'My video','revision':1}] if not admin else [],
        'liveFeature':None,'stripe':{'available':False,'mode':'test'},
        'analytics':{'periodDays':30,'basis':'Synthetic data only',
          'verified':{'orders':0,'netCents':0,'commissionCents':0,'refundedCents':0},
          'manual':{'orders':0,'netCents':0},'checkout':{'started':0,'paid':0,'paymentRatePercent':None},
          'topProducts':[],'channels':[],'trend':[]}}

def run():
    with sync_playwright() as p:
        browser=p.chromium.launch(headless=True,channel='chrome',args=['--no-sandbox'])
        results=[]
        for role in ('admin','model'):
            admin = role == 'admin'
            for width in (1440,768,390,320):
                page=browser.new_page(viewport={'width':width,'height':920})
                calls=[];errors=[]
                page.on('pageerror',lambda e:errors.append(str(e)))
                page.on('dialog',lambda d:d.accept('provider-evidence-0001'))
                def route_fn(route):
                    from urllib.parse import urlparse,parse_qs
                    action=parse_qs(urlparse(route.request.url).query).get('action',[''])[0]
                    if action!='state':calls.append((action,route.request.post_data))
                    result=state_for(role) if action=='state' else {'id':PROMO,'state':'recorded'}
                    route.fulfill(status=200,content_type='application/json',
                                  body=json.dumps({'ok':True,'data':result}))
                page.route('**/commerce-api.php?*',route_fn)
                page.set_content(html_for(role),wait_until='load')
                page.add_style_tag(path=str(ROOT/'assets/commerce.css'))
                page.add_script_tag(path=str(ROOT/'assets/commerce.js'))
                page.locator('#commerce-products-list .azh-commerce-card').first.wait_for(timeout=8000)
                assert page.get_by_text('Sample Product').count()>=1,(role,width,'missing product')
                assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),(role,width,'overflow')
                page.locator('[data-tab=analytics]').click()
                assert page.locator('.azh-commerce-kpi').count()==8,(role,width,'analytics cards')
                if admin:
                    page.locator('[data-tab=products]').click()
                    page.locator('#commerce-products-list .azh-commerce-card button').first.click()
                    assert page.locator('[name=localEnabled]').is_checked(),('product cash selected',width)
                    assert page.locator('[name=pickupAddress]').input_value()=='123 Example St, Phoenix AZ',('per-product pickup details',width)
                    assert page.locator('[name=sku]').input_value()=='SAMPLE-001',('product SKU load',width)
                    assert page.locator('[name=shippingEnabled]').is_checked(),('product shipping toggle',width)
                    validity=page.locator('#commerce-product-form').evaluate(
                        "(f)=>({valid:f.checkValidity(),invalid:[...f.elements].filter(e=>e.willValidate&&!e.validity.valid).map(e=>e.name)})")
                    assert validity['valid'],('product editor invalid',width,validity)
                    with page.expect_request(lambda req:'action=saveProduct' in req.url,timeout=8000) as request:
                        page.locator('#commerce-product-form button[type=submit]').click()
                    payload=json.loads(request.value.post_data)
                    assert payload['localSettings']['pickupAddress']=='123 Example St, Phoenix AZ',('per-product payload',width,payload)
                    assert payload['fulfillmentSettings']['sku']=='SAMPLE-001',('product shipping payload SKU',width,payload)
                    assert payload['fulfillmentSettings']['shippingFeeCents']==500,('product shipping fee snapshot',width,payload)
                    page.wait_for_function("document.querySelector('#commerce-status').textContent.includes('inventory and shipping')",timeout=8000)
                page.locator('[data-tab=briefs]').click()
                assert page.locator('#commerce-brief-list .azh-commerce-card').count()==1,(role,width,'brief render')
                if admin:
                    page.locator('#commerce-interest-list button').first.click()
                    page.wait_for_function("document.querySelector('#commerce-status').textContent.includes('approved')")
                    assert any(action=='reviewInterest' for action,_ in calls),calls
                    page.locator('[data-tab=orders]').click()
                    page.locator('#commerce-orders-list button').first.click()
                    page.wait_for_function("document.querySelector('#commerce-status').textContent.includes('reconciled')")
                    assert any(action=='reviewOrder' for action,_ in calls),calls
                    page.locator('[data-tab=live]').click()
                    assert page.locator('#commerce-live-form').count()==1
                else:
                    page.locator('#commerce-brief-list button').first.click()
                    page.wait_for_function("document.querySelector('#commerce-status').textContent.includes('Interest sent')")
                    assert any(action=='interest' for action,_ in calls),calls
                    page.locator('[data-tab=promotions]').click()
                    assert page.locator('[data-videos] option').count()==2
                assert not errors,(role,width,errors)
                page.screenshot(path=str(OUT/f'commerce_{role}_{width}.png'),full_page=True)
                results.append((role,width,len(calls)))
                page.close()
        browser.close()
        print('Commerce responsive real JS browser smoke: '+str(results))
if __name__=='__main__':run()
