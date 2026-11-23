#!/usr/bin/env python3
"""Responsive read-only admin provider readiness UI; no POSTs, no secret keys."""
from pathlib import Path
from urllib.parse import urlparse,parse_qs
import json,os
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('RUNNER_TEMP','/tmp'))/'azh_readiness_browser'
OUT.mkdir(parents=True,exist_ok=True)
SNAP={
 'configurationReady':False,'hostedTestsVerified':False,'productionApproved':False,
 'environment':{'stripe':'test','easypost':'unconfigured'},'pendingTotal':2,
 'checks':[
  {'id':'stripe','label':'Stripe keys and webhook','state':'configured','detail':'Expected-format settings only.'},
  {'id':'labels','label':'Return-label purchasing','state':'disabled','detail':'Safely disabled.'},
  {'id':'reconciliation','label':'Provider reconciliation','state':'attention','detail':'Review original operations.'}
 ],
 'counts':[{'id':'refunds','label':'Stripe refunds','count':2,'tab':'returns'},
           {'id':'labels','label':'Return labels','count':0,'tab':'returns'},
           {'id':'transfers','label':'Connect transfers','count':0,'tab':'payouts'}],
 'requiredTests':['Stripe TEST refund','EasyPost TEST return label','Stripe Connect TEST transfer','Timeout & webhook replay'],
 'notice':'Read-only TEST verification remains required.'
}
def page_html():
 return '''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">
 <base href="https://azhottie.test/"></head><body style="margin:0;padding:12px;box-sizing:border-box">
 <main class="azh-commerce" id="azh-commerce"><nav class="azh-commerce-tabs">
 <button data-tab="returns" role="tab">Returns</button><button data-tab="payouts" role="tab">Payouts</button>
 </nav><div class="azh-commerce-panel"><div class="azh-commerce-editor" id="azh-provider-readiness">
 <h2>Provider Production Readiness</h2><button data-readiness-refresh>Refresh readiness</button>
 <p data-readiness-status role="status"></p><div data-readiness-results></div>
 </div></div></main></body></html>'''
def run():
 with sync_playwright() as p:
  launch={'headless':True,'args':['--no-sandbox']}
  if Path('/usr/bin/chromium').exists():launch['executable_path']='/usr/bin/chromium'
  else:launch['channel']='chrome'
  browser=p.chromium.launch(**launch)
  for width in [1440,768,390,320]:
   page=browser.new_page(viewport={'width':width,'height':820})
   actions=[];errors=[];tab_clicks=[]
   page.on('pageerror',lambda e:errors.append(str(e)))
   def route_provider(route):
    action=parse_qs(urlparse(route.request.url).query).get('action',[''])[0]
    actions.append((route.request.method,action))
    assert route.request.method=='GET' and action=='readiness',actions
    route.fulfill(status=200,content_type='application/json',body=json.dumps({'ok':True,'data':SNAP}))
   page.route('**/commerce-provider-api.php?*',route_provider)
   page.set_content(page_html())
   page.add_style_tag(path=str(ROOT/'assets/commerce.css'))
   page.add_style_tag(path=str(ROOT/'assets/commerce-providers.css'))
   page.evaluate("""()=>{document.querySelectorAll('[data-tab]').forEach(x=>x.addEventListener('click',()=>x.dataset.visited='1'));}""")
   page.add_script_tag(path=str(ROOT/'assets/commerce-provider-readiness.js'))
   page.wait_for_function("document.querySelector('[data-readiness-status]').textContent.includes('updated')",timeout=8000)
   assert page.locator('.azh-readiness-item').count()==3,width
   assert page.locator('.azh-readiness-tests li').count()==4,width
   assert page.get_by_text('Configuration or unresolved financial operations need attention').count()==1,width
   assert page.get_by_role('button',name='Open returns').count()==2,width
   page.get_by_role('button',name='Open payouts').click()
   assert page.locator('[data-tab=payouts]').get_attribute('data-visited')=='1',width
   page.locator('[data-readiness-refresh]').click()
   page.wait_for_function("document.querySelectorAll('.azh-readiness-item').length===3",timeout=8000)
   assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),('horizontal overflow',width)
   assert len(actions)>=2 and all(method=='GET' for method,_ in actions),actions
   assert not errors,errors
   page.screenshot(path=str(OUT/f'provider_readiness_{width}.png'),full_page=True)
   print('Provider readiness responsive safe browser: PASS',width)
   page.close()
  browser.close()
if __name__=='__main__':run()
