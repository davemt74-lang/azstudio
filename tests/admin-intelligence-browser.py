#!/usr/bin/env python3
"""Real Chrome management briefing and safe opt-in scan on desktop/tablet/phone."""
from pathlib import Path
import json,os
from urllib.parse import parse_qs,urlparse
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('RUNNER_TEMP','/tmp'))/'azh_admin_intelligence_browser'
OUT.mkdir(parents=True,exist_ok=True)
php=(ROOT/'admin-studio.php').read_text(encoding='utf-8')
assert "az_admin_intelligence_briefing($user,$data)" in php
assert "<?php if($isAdmin): ?>" in php
assert 'id="azd-management-check"' in php
assert 'az_h($item[' in php
assert 'assets/admin-intelligence.js?v=1.42.0' in php
HTML='''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">
<base href="https://azhottie.test/"></head><body style="margin:0;background:#fcf9fb;padding:8px">
<main class="azd" id="azh-admin-command" data-csrf="csrf-fixture-token" style="width:100%;box-sizing:border-box">
<section class="azd-panel azd-agent-center" id="azd-management">
<div class="azd-panel-heading azd-agent-heading">
<div><p class="azd-kicker">YOUR MANAGEMENT SPECIALIST</p><h2>Agent Director Briefing</h2>
<p class="azd-muted">Recorded workflow snapshot; no automatic decisions.</p></div>
<div class="azd-agent-tools"><button type="button" id="azd-management-check" class="azd-agent-check">Check follow-ups</button>
<a class="azd-link" href="agent-chat.php?topic=studio">Discuss with Agent</a></div></div>
<div class="azd-agent-stats">
<div><strong>3</strong><span>New applications</span></div>
<div><strong>5</strong><span>Video reviews</span></div>
<div><strong>2</strong><span>Provider exceptions</span></div>
<div><strong>4</strong><span>Aged workflow items</span></div></div>
<div class="azd-agent-columns">
<div class="azd-agent-priorities"><div class="azd-agent-subhead"><h3>Prioritized follow-ups</h3><span>Staff decisions</span></div>
<ol class="azd-agent-actions">
<li><a href="commerce.php?tab=providers"><span class="azd-agent-priority" data-level="0">Urgent</span>
<span><strong>Reconcile original provider operations</strong>
<small>Review existing Stripe, EasyPost or Connect records before another financial action.</small></span><span class="azd-agent-arrow">↗</span></a></li>
<li><a href="casting.php?status=new"><span class="azd-agent-priority" data-level="1">Review</span>
<span><strong>New casting applications waiting</strong><small>Review questionnaire responses in Casting CRM before any decision.</small></span><span class="azd-agent-arrow">↗</span></a></li>
<li><a href="user-submissions.php"><span class="azd-agent-priority" data-level="1">Review</span>
<span><strong>Video submissions waiting</strong><small>Manually inspect the video and rights before publication.</small></span><span class="azd-agent-arrow">↗</span></a></li></ol>
<p class="azd-agent-next"><strong>Recommended next step</strong> Review original provider records.</p></div>
<div class="azd-agent-opportunities"><div class="azd-agent-subhead"><h3>Opportunities & watchpoints</h3><span>Evidence-based</span></div>
<a class="azd-agent-opportunity" href="commerce.php?tab=analytics"><strong>Investigate Stripe checkout completion</strong>
<small>6 of 20 checkout sessions recorded paid.</small></a>
<a class="azd-agent-opportunity" href="videos.php"><strong>Review video engagement patterns</strong>
<small>Recorded starts are not unique viewers.</small></a>
<p class="azd-agent-next"><strong>Open question</strong> Which workflows need staff review first?</p></div></div>
<p id="azd-management-status" class="azd-agent-status" role="status" aria-live="polite">
Recommendations are based on records; no automated decisions.</p>
</section></main></body></html>'''
def run():
 with sync_playwright() as p:
  opts={'headless':True,'args':['--no-sandbox']}
  if Path('/usr/bin/chromium').exists():opts['executable_path']='/usr/bin/chromium'
  else:opts['channel']='chrome'
  browser=p.chromium.launch(**opts)
  for width in (1440,1024,768,390,320):
   page=browser.new_page(viewport={'width':width,'height':900})
   calls=[];errors=[]
   page.on('pageerror',lambda error:errors.append(str(error)))
   page.on('dialog',lambda dialog:dialog.accept())
   def route_response(route):
    req=route.request
    action=parse_qs(urlparse(req.url).query).get('action',[''])[0]
    calls.append((req.method,action,req.post_data,req.headers.get('x-csrf-token')))
    assert req.method=='POST' and action=='checkManagement',calls
    assert json.loads(req.post_data or 'null')=={} and req.headers.get('x-csrf-token')=='csrf-fixture-token',calls
    route.fulfill(status=200,content_type='application/json',body=json.dumps({'ok':True,
      'data':{'checked':12,'delivered':3,'truncated':False}}))
   page.route('**/agent-workspace-api.php?*',route_response)
   page.set_content(HTML)
   page.add_style_tag(path=str(ROOT/'assets/admin-command.css'))
   page.add_script_tag(path=str(ROOT/'assets/admin-intelligence.js'))
   assert page.get_by_role('heading',name='Agent Director Briefing').count()==1
   assert page.locator('.azd-agent-actions li').count()==3
   assert page.get_by_role('link',name='Discuss with Agent').count()==1
   assert not calls,('read-only initial screen unexpectedly contacted server',width,calls)
   assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),('initial horizontal overflow',width)
   page.get_by_role('button',name='Check follow-ups').click()
   page.wait_for_function("document.getElementById('azd-management-status').textContent.includes('3 new Agent reminders')",timeout=8000)
   assert len(calls)==1 and calls[0][:2]==('POST','checkManagement')
   assert page.get_by_role('button',name='Check follow-ups').is_enabled()
   assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),('post-action overflow',width)
   assert not errors,(width,errors)
   page.screenshot(path=str(OUT/f'admin_management_{width}.png'),full_page=True)
   print('Admin Director Briefing responsive / explicit CSRF check PASS',width)
   page.close()
  # Editors must receive neither briefing controls nor a network scan.
  editor=browser.new_page()
  editor.set_content('<main id="azh-admin-command"></main>')
  editor.add_script_tag(path=str(ROOT/'assets/admin-intelligence.js'))
  assert editor.locator('#azd-management-check').count()==0
  editor.close()
  browser.close()
if __name__=='__main__':run()
