#!/usr/bin/env python3
"""Real Chrome provider action cards, strict role controls and mobile 320px layout."""
from pathlib import Path
from urllib.parse import urlparse,parse_qs
import json,os
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('RUNNER_TEMP','/tmp'))/'azh_provider_browser'
OUT.mkdir(parents=True,exist_ok=True)
RID='a'*32;BID='b'*32;LABELID='c'*32
def html(admin):
  return '''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">
<base href="https://azhottie.test/"></head><body style="margin:0;padding:12px">
''' + ('''<section id="azh-returns-admin" data-csrf="fixture-csrf">
 <button id="azh-returns-refresh">Refresh returns</button><p id="azh-returns-status"></p>
 <div id="azh-refund-provider-card" class="azh-commerce-card"></div>
 <div id="azh-return-label-card" class="azh-commerce-card"></div>
</section>''' if admin else '') + '''<section id="azh-payout-workspace" data-admin="'''+('1' if admin else '0')+'''"
data-csrf="fixture-csrf"><button id="azh-payout-refresh">Refresh payouts</button>
<p id="azh-payout-status"></p><div id="azh-batch-provider-card" class="azh-commerce-card"></div>
<div id="azh-model-provider-card" class="azh-commerce-card"></div></section></body></html>'''
def run():
  with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,channel='chrome',args=['--no-sandbox'])
    for width in [1440,768,390,320]:
      for admin in [True,False]:
        page=browser.new_page(viewport={'width':width,'height':920})
        actions=[];errors=[]
        state={'refund':None,'label':{'quote':None,'label':None},'connect':None,'transfer':None}
        page.on('pageerror',lambda e:errors.append(str(e)))
        page.on('dialog',lambda d:d.accept(prompt_text=''))
        def route_fn(route):
          req=route.request
          action=parse_qs(urlparse(req.url).query).get('action',[''])[0]
          body=json.loads(req.post_data or '{}')
          actions.append((action,body))
          if action=='features':
            result={'stripeMode':'test','refundInitiationEnabled':True,
              'stripeRefundMaxCents':50000,'connectTransfersEnabled':True,
              'connectMaxCents':50000,'easypostMode':'test',
              'returnLabelQuotesEnabled':True,'returnLabelPurchasesEnabled':True,
              'labelMaxCents':3000}
          elif action=='initiateRefund':
            assert body=={'returnId':RID,'revision':5,'expectedCents':1500,'confirmRefund':True},body
            state['refund']={'status':'provider_pending','amountCents':1500,
              'stripeRefundId':'re_mock12345678','mode':'test'}
            result=state['refund']
          elif action=='quoteReturnLabel':
            assert body['returnId']==LABELID and body['confirmParcel'] is True
            assert body['weightGrams']==450
            state['label']['quote']={'revision':1,'mode':'test',
              'expiresAt':'2090-01-01T00:00:00Z',
              'rates':[{'id':'rate_returnmock12345678','carrier':'USPS',
                'service':'Priority','rateCents':575,'currency':'USD','deliveryDays':3}]}
            result=state['label']
          elif action=='buyReturnLabel':
            assert body['rateId']=='rate_returnmock12345678' and body['expectedCents']==575
            assert body['confirmPurchase'] is True
            state['label']['label']={'status':'purchased','carrier':'USPS','service':'Priority',
              'rateCents':575,'mode':'test','labelUrl':'https://easypost.com/label-fixture',
              'trackingNumber':'94001111111111111111'}
            result=state['label']
          elif action=='linkConnectAccount':
            assert body['modelId']==2 and body['accountId']=='acct_mock123456789'
            assert body['confirmLink'] is True
            state['connect']={'linked':True,'status':'verified','mode':'test',
              'accountLast4':'6789'}
            result=state['connect']
          elif action=='sendConnectTransfer':
            assert body=={'batchId':BID,'revision':2,'expectedCents':500,'confirmTransfer':True},body
            state['transfer']={'status':'transferred','amountCents':500,
              'stripeTransferId':'tr_mock123456789'}
            result=state['transfer']
          elif action=='reconcileRefund':result=state['refund']
          elif action=='reconcileReturnLabel':result=state['label']
          elif action=='reconcileConnectTransfer':result=state['transfer']
          else:result={}
          route.fulfill(status=200,content_type='application/json',body=json.dumps({'ok':True,'data':result}))
        page.route('**/commerce-provider-api.php?*',route_fn)
        page.set_content(html(admin))
        page.add_style_tag(path=str(ROOT/'assets/commerce.css'))
        page.add_style_tag(path=str(ROOT/'assets/commerce-returns.css'))
        page.add_style_tag(path=str(ROOT/'assets/commerce-providers.css'))
        page.add_script_tag(path=str(ROOT/'assets/commerce-provider-actions.js'))
        page.wait_for_function("typeof window.azProviderReturnEnhance==='function'",timeout=8000)
        page.evaluate("""()=>{
          window.providerMock={
            refund:{id:'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',kind:'refund',
              provider:'stripe',status:'refund_approved',approvedRefundCents:1500,
              revision:5,providerRefund:null},
            label:{id:'cccccccccccccccccccccccccccccccc',kind:'refund',
              provider:'stripe',status:'authorized',returnLabel:{quote:null,label:null}},
            batch:{id:'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',status:'approved',
              amountCents:500,revision:2,connectTransfer:null},
            model:{id:2,name:'Creator Two',connectAccount:{linked:false}}
          };
          window.refreshProviderCards=()=>{
            const x=window.providerMock;
            const refresh=()=>{refreshProviderCards();return Promise.resolve()};
            if(document.getElementById('azh-refund-provider-card')){
              const refund=document.getElementById('azh-refund-provider-card');
              refund.replaceChildren();
              x.refund.providerRefund=window.providerStates?.refund||null;
              window.azProviderReturnEnhance(refund,x.refund,refresh);
              const label=document.getElementById('azh-return-label-card');
              label.replaceChildren();
              x.label.returnLabel=window.providerStates?.label||{quote:null,label:null};
              window.azProviderReturnEnhance(label,x.label,refresh);
            }
            const batch=document.getElementById('azh-batch-provider-card');
            batch.replaceChildren();
            x.batch.connectTransfer=window.providerStates?.transfer||null;
            window.azProviderPayoutEnhance(batch,x.batch,refresh);
            const model=document.getElementById('azh-model-provider-card');
            model.replaceChildren();
            x.model.connectAccount=window.providerStates?.connect||{linked:false};
            window.azProviderModelEnhance(model,x.model,refresh);
          };
          window.providerStates={refund:null,label:null,transfer:null,connect:null};
          // The real admin UI refreshes cards after provider feature config loads.
          // Mirror that to avoid races on slower 320px Chrome runs.
          document.getElementById('azh-returns-refresh')?.addEventListener('click',refreshProviderCards);
          document.getElementById('azh-payout-refresh')?.addEventListener('click',refreshProviderCards);
          refreshProviderCards();
        }""")
        assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),('overflow initial',admin,width)
        if admin:
          # Test button is armed once the GET configuration arrives.
          page.wait_for_function("""()=>{
            const btn=[...document.querySelectorAll('button')].find(
              b=>b.textContent.includes('Initiate Stripe refund'));
            return !!btn && !btn.disabled
          }""",timeout=8000)
          page.get_by_role('button',name='Initiate Stripe refund').click()
          page.wait_for_function("document.getElementById('azh-returns-status').textContent.includes('requested')",timeout=8000)
          page.evaluate("window.providerStates.refund={status:'provider_pending',amountCents:1500,stripeRefundId:'re_mock12345678'}; refreshProviderCards()")
          assert page.get_by_role('button',name='Reconcile original Stripe refund').count()==1
          page.locator('#azh-return-label-card [name=weightGrams]').fill('450')
          page.locator('#azh-return-label-card input[type=checkbox]').check()
          page.get_by_role('button',name='Quote return label services').click()
          page.wait_for_function("document.getElementById('azh-returns-status').textContent.includes('checked')",timeout=8000)
          page.evaluate("""window.providerStates.label={
             quote:{revision:1,mode:'test',expiresAt:'2090-01-01T00:00:00Z',
              rates:[{id:'rate_returnmock12345678',carrier:'USPS',service:'Priority',rateCents:575}]},
             label:null};refreshProviderCards()""")
          page.get_by_role('button',name='Buy return label').click()
          page.wait_for_function("document.getElementById('azh-returns-status').textContent.includes('recorded')",timeout=8000)
          page.locator('#azh-model-provider-card [name=accountId]').fill('acct_mock123456789')
          page.get_by_role('button',name='Verify linked account').click()
          page.wait_for_function("document.getElementById('azh-returns-status').textContent.includes('verified')",timeout=8000)
          page.get_by_role('button',name='Transfer approved commission with Stripe Connect').click()
          page.wait_for_function("document.getElementById('azh-returns-status').textContent.includes('Stripe transfer recorded')",timeout=8000)
          assert len([a for a,_ in actions if a=='initiateRefund'])==1
          assert len([a for a,_ in actions if a=='buyReturnLabel'])==1
          assert len([a for a,_ in actions if a=='sendConnectTransfer'])==1
        else:
          assert page.get_by_role('button',name='Initiate Stripe refund').count()==0
          assert page.get_by_role('button',name='Transfer approved commission with Stripe Connect').count()==0
          assert not [a for a,_ in actions if a not in ('features',)],actions
        assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),('overflow final',admin,width)
        assert not errors,(admin,width,errors)
        page.screenshot(path=str(OUT/f'provider_{"staff" if admin else "model"}_{width}.png'),full_page=True)
        print('Provider return/transfer card browser role fixture PASS',admin,width)
        page.close()
    browser.close()
if __name__=='__main__':run()
