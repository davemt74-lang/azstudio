#!/usr/bin/env python3
"""Chrome returns and payout workflows at desktop/tablet/phone widths, including model privacy."""
import json,os
from pathlib import Path
from urllib.parse import urlparse,parse_qs
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('RUNNER_TEMP','/tmp'))/'azh_returns_browser'
OUT.mkdir(parents=True,exist_ok=True)
OID='a'*32;RID='b'*32;BID='c'*32
def html(admin):
    role='admin' if admin else 'model'
    return f"""<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><base href="https://azhottie.test/"></head>
<body style="margin:0;padding:12px">
<nav><button data-tab="returns">Returns</button><button data-tab="payouts">Payouts</button></nav>
{'''<section data-panel="returns"><div id="azh-returns-admin" data-csrf="fixture-csrf">
  <form id="azh-return-intake"><select name="orderId" required></select>
    <select name="kind"><option value="refund">Refund</option><option value="exchange">Exchange</option></select>
    <input name="quantity" type="number" min="1" max="10" value="1"><textarea name="reason" required>Shirt arrived damaged</textarea>
    <button type="submit">Record return</button></form>
  <button id="azh-returns-refresh">Refresh</button><p id="azh-return-summary"></p>
  <p id="azh-returns-status"></p><div id="azh-returns-list"></div>
  <div id="azh-returns-private" hidden></div></div></section>''' if admin else ''}
<section data-panel="payouts"><div id="azh-payout-workspace" data-admin="{'1' if admin else '0'}" data-csrf="fixture-csrf">
 <p id="azh-payout-status"></p><button id="azh-payout-refresh">Refresh</button>
 <div id="azh-payout-summary"></div>
 {'''<form id="azh-payout-draft"><select name="modelId" required></select>
    <button type="submit">Draft payout</button></form><div id="azh-payout-models"></div>''' if admin else '<div id="azh-payout-model-details"></div>'}
 <div id="azh-payout-batches"></div>
</div></section></body></html>"""
def run():
  with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,channel='chrome',args=['--no-sandbox'])
    for width in [1440,768,390,320]:
      for admin in [True,False]:
        page=browser.new_page(viewport={'width':width,'height':900})
        errors=[];calls=[]
        page.on('pageerror',lambda error:errors.append(str(error)))
        page.on('dialog',lambda dialog:
          dialog.accept(prompt_text='verified-return-evidence-001'
            if 'verified order' in dialog.message.lower() or 'identity' in dialog.message.lower()
            else 'bank-transfer-000001' if 'reference' in dialog.message.lower()
            else 'bank' if 'channel' in dialog.message.lower()
            else 'resellable' if 'condition' in dialog.message.lower()
            else '0' if 'how many' in dialog.message.lower()
            else ''))
        state={'return_status':'requested','return_revision':1,'payout_status':None,'payout_revision':0}
        def route_fn(route):
          req=route.request
          action=parse_qs(urlparse(req.url).query).get('action',[''])[0]
          data=json.loads(req.post_data or '{}')
          calls.append((req.url,action,data))
          if 'commerce-returns-api.php' in req.url:
            if action=='cases':
              result=[{'id':RID,'orderId':OID,'orderReference':'AZ-AAAAAAAAAA',
                'title':'Return Test Shirt','kind':'refund','quantity':1,
                'reason':'Shirt arrived damaged','source':'unverified',
                'status':state['return_status'],'condition':'resellable' if state['return_status']=='inspected' else '',
                'restockedQuantity':0,'revision':state['return_revision'],
                'provider':'stripe','refundReference':'','replacementReference':''}]
            elif action=='transition':
              assert data['revision']==state['return_revision']
              state['return_status']={'authorize':'authorized','receive':'received',
                'inspect':'inspected','approve_refund':'refund_approved',
                'confirm_refund':'refunded','reject':'rejected','cancel':'cancelled',
                'approve_exchange':'exchange_approved','dispatch_exchange':'exchange_sent'}[data['decision']]
              state['return_revision']+=1
              result={'id':RID,'status':state['return_status'],'revision':state['return_revision']}
            elif action=='contact':result={'id':RID,'contact':{'email':'private-buyer@example.invalid'}}
            elif action=='intake':result={'id':RID,'status':'requested','revision':1}
            else:result={}
          elif 'commerce-payout-api.php' in req.url:
            if action=='state':
              batch=[] if state['payout_status'] is None else [{
                'id':BID,'modelId':1,'modelName':'Creator One','amountCents':450,
                'status':state['payout_status'],'paymentChannel':'bank' if state['payout_status']=='paid' else '',
                'evidenceReference':'bank-transfer-000001' if state['payout_status']=='paid' else '',
                'revision':state['payout_revision'],'createdAt':'2026-11-01','approvedAt':None,'confirmedAt':None}]
              if admin:
                result={'role':'admin','models':[{'id':1,'name':'Creator One','eligibleCents':450,
                  'heldCents':0,'reservedCents':0,'paidCents':0,'overpaidCents':0}],
                  'batches':batch,'holdDays':14}
              else:
                result={'role':'model','balance':{'eligibleCents':450,'heldCents':0,
                  'reservedCents':0,'recordedPaidCents':0,'overpaidCents':0,
                  'orders':[{'id':OID,'title':'Return Test Shirt','commissionCents':450,
                    'availableCents':450,'allocatedCents':0,'paidCents':0,'overpaidCents':0,'holdReason':None}]},
                  'batches':batch}
            elif action=='draft':
              state['payout_status']='draft';state['payout_revision']=1
              result={'id':BID,'state':'draft','amountCents':450,'revision':1}
            elif action=='change':
              assert data['revision']==state['payout_revision']
              state['payout_status']={'approve':'approved','paid':'paid','cancel':'cancelled'}[data['decision']]
              state['payout_revision']+=1
              result={'id':BID,'state':state['payout_status'],'amountCents':450,'revision':state['payout_revision']}
            else:result={}
          else:
            result={'orders':[{'id':OID,'product_title':'Return Test Shirt','state':'paid','provider':'stripe'}]}
          route.fulfill(status=200,content_type='application/json',body=json.dumps({'ok':True,'data':result}))
        page.route('**/commerce-returns-api.php?*',route_fn)
        page.route('**/commerce-payout-api.php?*',route_fn)
        page.route('**/commerce-api.php?*',route_fn)
        page.set_content(html(admin))
        page.add_style_tag(path=str(ROOT/'assets/commerce.css'))
        page.add_style_tag(path=str(ROOT/'assets/commerce-returns.css'))
        if admin:page.add_script_tag(path=str(ROOT/'assets/commerce-returns-admin.js'))
        page.add_script_tag(path=str(ROOT/'assets/commerce-settlements.js'))
        page.locator('.azh-return-kpi').first.wait_for(timeout=8000)
        assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),('payout overflow',admin,width)
        if admin:
          page.get_by_role('button',name='Verify & authorize').wait_for(timeout=8000)
          page.get_by_role('button',name='Verify & authorize').click()
          page.get_by_role('button',name='Mark goods received').wait_for(timeout=8000)
          assert state['return_status']=='authorized',(width,state)
          page.get_by_role('button',name='Mark goods received').click()
          page.get_by_role('button',name='Inspect & restock').wait_for(timeout=8000)
          page.get_by_role('button',name='Inspect & restock').click()
          page.get_by_role('button',name='Approve refund').wait_for(timeout=8000)
          assert any(action=='transition' and d.get('decision')=='inspect'
                     and d.get('restockQuantity')==0 for _,action,d in calls),(width,'restock')
          page.locator('#azh-payout-draft [name=modelId]').select_option('1')
          page.locator('#azh-payout-draft button').click()
          page.get_by_role('button',name='Approve eligible commissions').wait_for(timeout=8000)
          page.get_by_role('button',name='Approve eligible commissions').click()
          page.get_by_role('button',name='Record completed external payment').wait_for(timeout=8000)
          page.get_by_role('button',name='Record completed external payment').click()
          page.get_by_text('External payment evidence').wait_for(timeout=8000)
          assert state['payout_status']=='paid',(width,state)
        else:
          assert page.locator('#azh-payout-model-details').get_by_text('Return Test Shirt').count()==1
          assert page.locator('#azh-returns-admin').count()==0
        assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),('return overflow',admin,width)
        assert not errors,(admin,width,errors)
        page.screenshot(path=str(OUT/f'returns_payout_{"admin" if admin else "creator"}_{width}.png'),full_page=True)
        print('Return + settlement Chrome role/workflow PASS',admin,width)
        page.close()
    browser.close()
if __name__=='__main__':run()
