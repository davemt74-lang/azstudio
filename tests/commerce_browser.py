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
<input name="priceDollars" type="number"><input name="commissionPercent" type="number">
<input name="checkoutUrl"><select name="checkoutMode"><option value="external">External</option><option value="stripe">Stripe</option></select><select name="state"><option value="active">Active</option></select>
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
        'brief':{'title':'Creator Spotlight','instructions':'Create a short video review','state':'active','revision':1},
        'myInterest':None if not admin else None}
    return {'role':role,'products':[product],
        'interests':[{'product_id':PID,'model_id':1,'model_name':'Model A','state':'interested',
                     'product_title':'Sample Product'}] if admin else [],
        'promotions':[{'id':PROMO,'product_id':PID,'model_id':1,'video_id':VID,'video_revision':1,
                       'product_title':'Sample Product','video_title':'My product video','state':'pending',
                       'video_status':'published'}] if admin else [],
        'orders':[{'id':ORDER,'external_reference':'external-order-0001','product_id':PID,
                   'product_title':'Sample Product','state':'recorded','quantity':1,'amount_cents':1250,
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
