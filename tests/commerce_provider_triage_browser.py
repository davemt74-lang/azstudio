#!/usr/bin/env python3
"""Real Chromium test of staff reconciliation, TEST evidence and mobile behavior."""
from pathlib import Path
from urllib.parse import urlparse, parse_qs
import json, os
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUTPUT=Path(os.environ.get('RUNNER_TEMP','/tmp'))/'azh_triage_browser'
OUTPUT.mkdir(exist_ok=True,parents=True)
SNAP={
 'configurationReady':False,'liveActionsEnabled':False,
 'environment':{'stripe':'test','easypost':'test'},'pendingTotal':4,
 'checks':[{'label':'Staff-only switch','state':'disabled','detail':'No live money movement.'}],
 'counts':[{'label':'Stripe refunds','count':2,'tab':'returns'},
           {'label':'Return postage labels','count':1,'tab':'returns'},
           {'label':'Connect transfers','count':1,'tab':'payouts'}],
 'triage':{'items':[
  {'kind':'return_label','reference':'DDDDDDDD','tab':'returns','status':'needs_review',
   'environment':'test','ageMinutes':1500,'urgency':'overdue',
   'nextAction':'Original EasyPost label purchase outcome unclear. Never buy twice.'},
  {'kind':'refund','reference':'AAAAAAAA','tab':'returns','status':'provider_pending',
   'environment':'test','ageMinutes':60,'urgency':'attention',
   'nextAction':'Await signed charge.refunded event and ledger confirmation.'},
  {'kind':'connect_transfer','reference':'FFFFFFFF','tab':'payouts','status':'pending',
   'environment':'test','ageMinutes':2,'urgency':'watch',
   'nextAction':'Review original Stripe Connect transfer.'}
 ],'truncated':False},
 'testEvidence':[
  {'id':'refund','label':'Stripe TEST ledger confirmation','observedCount':1,
   'latestAt':'2026-11-23T07:50:00Z','limitation':'TEST evidence only; hosted acceptance unverified.'},
  {'id':'return_label','label':'EasyPost TEST return label','observedCount':1,
   'latestAt':'2026-11-23T07:50:00Z','limitation':'TEST label response only.'},
  {'id':'connect_transfer','label':'Connect TEST platform transfer','observedCount':1,
   'latestAt':'2026-11-23T07:50:00Z','limitation':'Bank deposit unverified.'}
 ],
 'requiredTests':['Host Stripe TEST refund','Host EasyPost TEST label','Host Connect TEST transfer','Host webhook replay and timeout'],
 'notice':'Never enable live money movement until verified by staff.'
}
HTML='''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">
<base href="https://azhottie.test/"></head><body style="margin:0;padding:12px;box-sizing:border-box">
<main class="azh-commerce" id="azh-commerce"><nav class="azh-commerce-tabs">
<button type="button" data-tab="returns">Returns</button><button type="button" data-tab="payouts">Payouts</button>
</nav><div class="azh-commerce-panel"><div class="azh-commerce-editor" id="azh-provider-readiness">
<h2>Provider Production Readiness</h2><button type="button" data-readiness-refresh>Refresh</button>
<p data-readiness-status role="status"></p><div data-readiness-results></div>
</div></div></main></body></html>'''
with sync_playwright() as p:
  opts={'headless':True,'args':['--no-sandbox']}
  if Path('/usr/bin/chromium').exists():opts['executable_path']='/usr/bin/chromium'
  else:opts['channel']='chrome'
  browser=p.chromium.launch(**opts)
  for width in (1440,768,390,320):
    page=browser.new_page(viewport={'width':width,'height':860})
    errors=[];calls=[]
    page.on('pageerror',lambda e:errors.append(str(e)))
    def route_handler(route):
      action=parse_qs(urlparse(route.request.url).query).get('action',[''])[0]
      calls.append((route.request.method,action))
      assert route.request.method=='GET' and action=='readiness',calls
      route.fulfill(status=200,content_type='application/json',body=json.dumps({'ok':True,'data':SNAP}))
    page.route('**/commerce-provider-api.php?*',route_handler)
    page.set_content(HTML)
    page.add_style_tag(path=str(ROOT/'assets/commerce.css'))
    page.add_style_tag(path=str(ROOT/'assets/commerce-providers.css'))
    page.evaluate("""()=>document.querySelectorAll('[data-tab]').forEach(b=>
      b.addEventListener('click',()=>b.dataset.visited='1'))""")
    page.add_script_tag(path=str(ROOT/'assets/commerce-provider-readiness.js'))
    page.wait_for_function("document.querySelector('[data-readiness-status]').textContent.includes('updated')",timeout=8000)
    assert page.locator('.azh-readiness-item').count()==4,width
    assert page.locator('.azh-readiness-case').count()==3,width
    assert page.get_by_text('Hosted acceptance: not verified').count()==3,width
    assert page.locator('.azh-readiness-case').first.get_by_text('overdue').count()==1,width
    page.get_by_role('button',name='Review in Payouts').click()
    assert page.locator('[data-tab="payouts"]').get_attribute('data-visited')=='1',width
    page.get_by_role('button',name='Review in Returns').first.click()
    assert page.locator('[data-tab="returns"]').get_attribute('data-visited')=='1',width
    page.get_by_role('button',name='Refresh').click()
    page.wait_for_function("document.querySelectorAll('.azh-readiness-case').length===3")
    assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),('overflow',width)
    assert len(calls)>=2 and all(x[0]=='GET' for x in calls),calls
    assert not errors,errors
    page.screenshot(path=str(OUTPUT/f'provider_triage_{width}.png'),full_page=True)
    print('Responsive provider triage browser PASS',width)
    page.close()
  browser.close()
