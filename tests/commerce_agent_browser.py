#!/usr/bin/env python3
"""Chrome verification of Commerce Agent briefing, creator privacy and safe follow-ups."""
from pathlib import Path
from urllib.parse import urlparse, parse_qs
import json, os
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('RUNNER_TEMP','/tmp'))/'azh_commerce_agent_browser'
OUT.mkdir(parents=True,exist_ok=True)
ADMIN={
 'role':'admin','keyFindings':['2 Stripe refunds need reconciliation','1 return requires inspection'],
 'actionsFollowups':[{'title':'Reconcile original refund','detail':'No duplicate Stripe refund permitted.',
                      'href':'commerce.php?tab=providers','priority':0},
                    {'title':'Review returns','detail':'Inspected status is not a completed refund.',
                      'href':'commerce.php?tab=returns','priority':1}],
 'openQuestions':['Is there signed provider evidence?'],
 'nextStep':'Reconcile the original Stripe refund before any retry.',
 'questionsForYou':['Which exception should the Agent investigate?'],
 'counts':{'refundsNeedsReconciliation':2},
 'basis':'Role-scoped staff records. No provider action taken.'}
MODEL={
 'role':'model','keyFindings':['Eligible commissions: 25.00 USD','Commissions on hold: 50.00 USD',
                               'A platform transfer does not confirm a bank deposit.'],
 'actionsFollowups':[{'title':'Review commission holds','detail':'Await payout evidence.',
                      'href':'commerce.php?tab=payouts','priority':2}],
 'openQuestions':[],'nextStep':'Review your own payout status.',
 'questionsForYou':['Would you like an explanation of your holds?'],
 'counts':{'eligibleCommissionCents':2500,'heldCommissionCents':5000},
 'basis':'Your account only, no other creators or private provider data.'}
def html(admin):
 return '''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">
 <base href="https://azhottie.test/"></head><body style="margin:0;padding:12px;box-sizing:border-box">
 <main id="azh-commerce" class="azh-commerce" data-csrf="valid-fixture-token"><div class="azh-commerce-panel">
 <section class="azh-commerce-editor" id="azh-commerce-agent-briefing" data-role="'''+('admin' if admin else 'model')+'''">
 <h2>Commerce Agent briefing</h2><div class="azh-commerce-actions"><button data-agent-refresh>Refresh briefing</button>
 '''+('<button data-agent-scan>Check follow-ups</button>' if admin else '')+'''
 </div><p data-agent-status role="status"></p><div data-agent-results></div></section></div></main></body></html>'''
def run():
 with sync_playwright() as p:
  options={'headless':True,'args':['--no-sandbox']}
  if Path('/usr/bin/chromium').exists():options['executable_path']='/usr/bin/chromium'
  else:options['channel']='chrome'
  browser=p.chromium.launch(**options)
  for width in (1440,768,390,320):
   for admin in (True,False):
    page=browser.new_page(viewport={'width':width,'height':850})
    calls=[];errors=[]
    page.on('pageerror',lambda e:errors.append(str(e)))
    page.on('dialog',lambda d:d.accept())
    def intercept(route):
     req=route.request
     action=parse_qs(urlparse(req.url).query).get('action',[''])[0]
     calls.append((req.method,action,req.post_data))
     if action=='agentBriefing':
      assert req.method=='GET',calls
      body={'ok':True,'data':ADMIN if admin else MODEL}
     elif action=='checkCommerce':
      assert admin and req.method=='POST' and json.loads(req.post_data or '{}')=={},calls
      assert req.headers.get('x-csrf-token')=='valid-fixture-token'
      body={'ok':True,'data':{'checked':2,'delivery':{'delivered':1}}}
     else: raise AssertionError('Unexpected API action '+action)
     route.fulfill(status=200,content_type='application/json',body=json.dumps(body))
    page.route('**/*-api.php?*',intercept)
    page.set_content(html(admin))
    page.add_style_tag(path=str(ROOT/'assets/commerce.css'))
    page.add_style_tag(path=str(ROOT/'assets/commerce-agent.css'))
    page.add_script_tag(path=str(ROOT/'assets/commerce-agent-briefing.js'))
    page.wait_for_function("document.querySelector('[data-agent-status]').textContent.includes('updated')",timeout=10000)
    assert page.get_by_text('Key findings').count()==1,(width,admin)
    assert page.get_by_text('Next step').count()==1,(width,admin)
    assert page.get_by_text('Questions for you').count()==1,(width,admin)
    assert page.locator('.azh-commerce-agent-card').count()==(2 if admin else 1)
    if admin:
     assert page.get_by_text('2 Stripe refunds need reconciliation').count()==1
     page.get_by_role('button',name='Check follow-ups').click()
     page.wait_for_function("document.querySelector('[data-agent-status]').textContent.includes('notices delivered')",timeout=10000)
     assert len([x for x in calls if x[0]=='POST'])==1
    else:
     assert page.get_by_role('button',name='Check follow-ups').count()==0
     assert page.get_by_text('Eligible commissions: 25.00 USD').count()==1
     assert not any('2 Stripe refunds' in x.text_content() for x in page.locator('p').all()),'Creator saw admin finance data'
     assert all(x[0]=='GET' for x in calls),calls
    page.get_by_role('button',name='Refresh briefing').click()
    page.wait_for_function("document.querySelector('[data-agent-status]').textContent.includes('updated')",timeout=10000)
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth+2'),(width,admin,'horizontal overflow')
    assert not errors,(width,admin,errors)
    assert not any(name in ('initiateRefund','buyReturnLabel','sendConnectTransfer') for _,name,_ in calls),calls
    page.screenshot(path=str(OUT/f'commerce_agent_{"admin" if admin else "creator"}_{width}.png'),full_page=True)
    print('Commerce Agent browser PASS', 'admin' if admin else 'creator',width)
    page.close()
  browser.close()
if __name__=='__main__':run()
