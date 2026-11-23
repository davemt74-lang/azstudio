#!/usr/bin/env python3
"""Real Chrome: EasyPost quote/label approval and original shipping controls on four widths."""
from pathlib import Path
from playwright.sync_api import sync_playwright
from urllib.parse import urlparse,parse_qs
import os,json
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('RUNNER_TEMP','/tmp'))/'azh_carrier_browser'
OUT.mkdir(parents=True,exist_ok=True)
OID='a'*32
HTML='''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">
<base href="https://azhottie.test/"></head><body style="margin:0;padding:12px">
<button data-tab="products">Products</button><button data-tab="shipping">Shipping</button>
<section data-panel="shipping"><div id="azh-shipping-admin" data-csrf="fixture-csrf">
<p id="azh-shipping-counts"></p><p id="azh-carrier-status"></p><p id="azh-shipping-status"></p>
<button id="azh-shipping-refresh" type="button">Refresh</button>
<button id="azh-shipping-edit-products" type="button">Edit</button>
<div id="azh-shipping-orders"></div><section id="azh-shipping-private" hidden></section>
</div></section></body></html>'''
def run():
    with sync_playwright() as p:
        browser=p.chromium.launch(headless=True,channel='chrome',args=['--no-sandbox'])
        for width in [1440,768,390,320]:
            page=browser.new_page(viewport={'width':width,'height':860})
            state={'status':'awaiting_dispatch','revision':1,'carrier':'',
                'trackingNumber':'','quote':None,'label':None}
            actions=[];errors=[];buys=[]
            page.on('pageerror',lambda e:errors.append(str(e)))
            page.on('dialog',lambda d:d.accept())
            def handler(route):
                req=route.request
                q=parse_qs(urlparse(req.url).query)
                action=q.get('action',[''])[0]
                data=json.loads(req.post_data or '{}')
                actions.append((action,data))
                if 'commerce-carrier-api.php' in req.url:
                    if action=='status':
                        result={'configured':True,'mode':'test','purchaseEnabled':True,
                            'webhookConfigured':True,'maxLabelCents':3000,'carriers':['USPS','UPS','FedEx']}
                    elif action=='quote':
                        assert data['confirmParcel'] is True
                        assert data['weightGrams']==700
                        assert data['lengthMm']==200
                        state['quote']={'shipmentId':'shp_mock123456789','revision':1,
                            'mode':'test','expiresAt':'2090-01-01T00:00:00Z',
                            'rates':[
                                {'id':'rate_USPS1111111111','carrier':'USPS','service':'Priority',
                                    'rateCents':625,'currency':'USD','deliveryDays':3},
                                {'id':'rate_UPS2222222222','carrier':'UPS','service':'Ground',
                                    'rateCents':750,'currency':'USD','deliveryDays':4}
                            ]}
                        result=state['quote']
                    elif action=='buy':
                        buys.append(data)
                        assert data['confirmPurchase'] is True
                        assert data['rateId']=='rate_UPS2222222222'
                        assert data['expectedCents']==750
                        state['carrier']='ups';state['trackingNumber']='1Z999AA10123456784'
                        state['label']={'status':'purchased','mode':'test','rateCents':750,
                          'carrier':'UPS','service':'Ground','trackingNumber':state['trackingNumber'],
                          'trackingStatus':'pre_transit',
                          'labelUrl':'https://easypost-files.s3.us-west-2.amazonaws.com/mocklabel.png'}
                        result=state['label']
                    elif action=='reconcile':result=state['label'] or {'status':'needs_review'}
                    else:result={}
                else:
                    if action=='shipments':
                        result=[{'id':OID,'title':'Shoppable parcel','shippingFeeCents':550,
                            'status':state['status'],'carrier':state['carrier'],
                            'trackingNumber':state['trackingNumber'],'revision':state['revision'],
                            'createdAt':'2026-11-22','updatedAt':'2026-11-22',
                            'shippedAt':None,'deliveredAt':None,'quantity':1,
                            'paymentState':'paid','provider':'stripe',
                            'carrierTrackingStatus':'pre_transit',
                            'parcel':{'weightGrams':700,'lengthMm':200,'widthMm':150,'heightMm':80},
                            'carrierIntegration':{'quote':state['quote'],'label':state['label']}}]
                    elif action=='details':
                        result={'id':OID,'address':{'name':'Private Recipient',
                            'street':'Ship address','city':'Phoenix','state':'AZ',
                            'zip':'85001','country':'US'}}
                    elif action=='transition':
                        state['status']={'pack':'packed','ship':'shipped','deliver':'delivered',
                            'exception':'exception','reopen':'awaiting_dispatch'}[data['decision']]
                        if data['decision']=='ship':
                            state['carrier']=data['carrier'];state['trackingNumber']=data['trackingNumber']
                        state['revision']+=1;result={'id':OID,'status':state['status'],'revision':state['revision']}
                    else:result={}
                route.fulfill(status=200,content_type='application/json',
                    body=json.dumps({'ok':True,'data':result}))
            page.route('**/commerce-fulfillment-api.php?*',handler)
            page.route('**/commerce-carrier-api.php?*',handler)
            page.set_content(HTML)
            page.add_style_tag(path=str(ROOT/'assets/commerce.css'))
            page.add_script_tag(path=str(ROOT/'assets/commerce-fulfillment-admin.js'))
            page.add_script_tag(path=str(ROOT/'assets/commerce-carrier-admin.js'))
            page.get_by_role('button',name='Get live USPS / UPS / FedEx rates').wait_for(timeout=8000)
            assert 'EasyPost TEST configured' in page.locator('#azh-carrier-status').inner_text(),width
            page.locator('.azh-carrier-fields [name=weightGrams]').fill('700')
            page.locator('.azh-carrier-fields [name=lengthMm]').fill('200')
            page.locator('.azh-carrier-confirm input').check()
            page.get_by_role('button',name='Get live USPS / UPS / FedEx rates').click()
            page.get_by_role('button',name='Buy UPS label').wait_for(timeout=8000)
            assert page.get_by_role('button',name='Buy UPS label').is_disabled(),('not packed',width)
            page.get_by_role('button',name='Mark packed').click()
            page.wait_for_function(
              "(()=>{const x=[...document.querySelectorAll('button')].find(b=>b.textContent.includes('Buy UPS label'));return !!x&&!x.disabled})()",
              timeout=8000)
            assert page.get_by_role('button',name='Buy UPS label').is_enabled(),('packed',width)
            page.get_by_role('button',name='Buy UPS label').click()
            page.get_by_role('link',name='Open purchased shipping label').wait_for(timeout=8000)
            assert len(buys)==1,('unexpected label purchases',width,buys)
            assert 'Private Recipient' not in page.locator('#azh-shipping-orders').inner_text(),('address exposed',width)
            page.get_by_role('button',name='Confirm handed to carrier').click()
            page.locator('#azh-shipping-orders').get_by_text('SHIPPED', exact=True).wait_for(timeout=8000)
            assert any(a=='transition' and d.get('trackingNumber')=='1Z999AA10123456784'
                for a,d in actions),('actual label tracking not used',width)
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),('overflow',width)
            assert not errors,(width,errors)
            page.screenshot(path=str(OUT/f'carrier_{width}.png'),full_page=True)
            print('EasyPost carrier admin Chrome fixture PASS',width)
            page.close()
        browser.close()
if __name__=='__main__':run()
