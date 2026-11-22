#!/usr/bin/env python3
"""Desktop/mobile real JS Shipping workspace tests. Synthetic paid carrier data only."""
from pathlib import Path
from playwright.sync_api import sync_playwright
import os,json
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('RUNNER_TEMP','/tmp'))/'azh_shipping_browser'
OUT.mkdir(parents=True,exist_ok=True)
OID='a'*32
HTML='''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><base href="https://azhottie.test/"></head>
<body style="margin:0;padding:12px">
<button data-tab="products">Products</button>
<button data-tab="shipping">Shipping</button>
<section data-panel="shipping"><div id="azh-shipping-admin" data-csrf="test-csrf">
 <p id="azh-shipping-counts"></p><p id="azh-shipping-status"></p>
 <button id="azh-shipping-refresh" type="button">Refresh</button>
 <button id="azh-shipping-edit-products" type="button">Edit</button>
 <div id="azh-shipping-orders"></div>
 <section id="azh-shipping-private" hidden></section>
</div></section></body></html>'''
def run():
  with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,channel='chrome',args=['--no-sandbox'])
    for width in [1440,768,390,320]:
      page=browser.new_page(viewport={'width':width,'height':860})
      state={'status':'awaiting_dispatch','revision':1,'carrier':'','trackingNumber':''}
      actions=[];errors=[]
      page.on('pageerror',lambda e:errors.append(str(e)))
      page.on('dialog',lambda d:d.accept())
      def route_fn(route):
        from urllib.parse import urlparse,parse_qs
        q=parse_qs(urlparse(route.request.url).query)
        action=q.get('action',[''])[0]
        data=json.loads(route.request.post_data or '{}')
        actions.append((action,data))
        if action=='shipments':
          result=[{'id':OID,'title':'Verified shipping product','shippingFeeCents':375,
            'status':state['status'],'carrier':state['carrier'],'trackingNumber':state['trackingNumber'],
            'revision':state['revision'],'createdAt':'2026-11-22','updatedAt':'2026-11-22',
            'shippedAt':None,'deliveredAt':None,'quantity':1,'paymentState':'paid','provider':'stripe'}]
        elif action=='details':
          result={'id':OID,'address':{'name':'Private Recipient','street':'123 Lane',
            'city':'Phoenix','state':'AZ','zip':'85001','country':'US'}}
        elif action=='transition':
          assert data['revision']==state['revision'],('stale revision',data,state)
          state['status']={'pack':'packed','ship':'shipped','deliver':'delivered',
            'exception':'exception'}[data['decision']]
          if data['decision']=='ship':
            state['carrier']=data['carrier'];state['trackingNumber']=data['trackingNumber']
          state['revision']+=1;result={'id':OID,'status':state['status'],'revision':state['revision']}
        else: result={}
        route.fulfill(status=200,content_type='application/json',
          body=json.dumps({'ok':True,'data':result}))
      page.route('**/commerce-fulfillment-api.php?*',route_fn)
      page.set_content(HTML)
      page.add_style_tag(path=str(ROOT/'assets/commerce.css'))
      page.add_script_tag(path=str(ROOT/'assets/commerce-fulfillment-admin.js'))
      page.locator('#azh-shipping-refresh').click()
      page.get_by_text('Verified shipping product').wait_for(timeout=8000)
      assert 'Private Recipient' not in page.locator('#azh-shipping-orders').inner_text(),width
      page.get_by_role('button',name='View private shipping address').click()
      page.get_by_text('Private Recipient').wait_for(timeout=8000)
      assert any(a=='details' for a,_ in actions),('details not fetched',width)
      page.get_by_role('button',name='Hide address').click()
      page.get_by_role('button',name='Mark packed').click()
      page.get_by_role('button',name='View private shipping address').wait_for(timeout=8000)
      page.locator('[name=carrier]').select_option('ups')
      page.locator('[name=trackingNumber]').fill('1Z999AA10123456784')
      page.get_by_role('button',name='Mark shipped').click()
      page.get_by_role('button',name='Mark delivered (staff confirmation)').wait_for(timeout=8000)
      assert any(a=='transition' and d.get('decision')=='ship' and d.get('carrier')=='ups' for a,d in actions)
      page.get_by_role('button',name='Mark delivered (staff confirmation)').click()
      page.locator('#azh-shipping-orders').get_by_text('DELIVERED').wait_for(timeout=8000)
      assert state['status']=='delivered'
      assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),('overflow',width)
      assert not errors,(width,errors)
      page.screenshot(path=str(OUT/f'fulfillment_{width}.png'),full_page=True)
      print('Shipping staff Chrome fixture PASS: ',width)
      page.close()
    browser.close()
if __name__=='__main__':run()
