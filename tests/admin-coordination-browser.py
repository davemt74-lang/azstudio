#!/usr/bin/env python3
"""Real Chromium coordination panel + shared Action Center tracking at five viewport widths."""
from pathlib import Path
from urllib.parse import urlparse,parse_qs
import json,os
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('RUNNER_TEMP','/tmp'))/'azh_coordination_browser'
OUT.mkdir(parents=True,exist_ok=True)
SOURCE=(ROOT/'admin-studio.php').read_text(encoding='utf-8')
BACK=(ROOT/'includes/admin-actions.php').read_text(encoding='utf-8')
assert "az_coordination_briefing($user)" in SOURCE
assert 'id="azd-coordination"' in SOURCE
assert 'data-kind="production"' in SOURCE
assert 'az_h($staff[' in SOURCE and "az_h($case['" in SOURCE
assert "az_coordination_candidates($db,$now,80)" in BACK
CASE_ID='a'*32
HTML='''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">
<base href="https://azhottie.test/"></head><body style="margin:0;padding:8px;background:#fcf9fb">
<main class="azd" id="azh-admin-command" data-csrf="fixture-csrf-verified" style="box-sizing:border-box;width:100%">
<section class="azd-panel azd-coordination" id="azd-coordination" aria-label="Studio coordination">
<header class="azd-panel-heading azd-coord-head"><div><p class="azd-kicker">ASSIGNMENTS · SCHEDULE · STAFF CAPACITY</p>
<h2>Agent Task Coordination</h2><p class="azd-muted">Evidence-based review only.</p></div>
<a class="azd-link" href="productions.php">Production workspace</a></header>
<div class="azd-coord-stats">
<div><strong>4</strong><span>Active productions</span></div><div><strong>2</strong><span>Urgent work alerts</span></div>
<div><strong>1</strong><span>Unstaffed shots due soon</span></div><div><strong>1</strong><span>Appointment overlap pairs</span></div>
<div><strong>3</strong><span>Assignments approved · 7d</span></div><div><strong>7</strong><span>Task resolution events · 7d</span></div></div>
<div class="azd-coord-columns">
<div class="azd-coord-block">
<div class="azd-coord-subhead"><h3>Production blockers & follow-ups</h3><span>Due-date based</span></div>
<div class="azd-coord-cases">
<article class="azd-coord-case"><div class="azd-coord-case-top">
<span class="azd-coord-urgency" data-priority="0">Urgent</span><span class="azd-coord-due">Due Nov 23</span></div>
<strong>Production assignment past its due date</strong>
<p>Confirm the original assignment and creator availability.</p>
<div class="azd-coord-actions"><a href="productions.php?assignmentId=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa">Review source</a>
<button type="button" class="azd-action-button" data-action-track data-kind="production"
data-source-id="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa" data-stage="production_assignment_due">Track in Action Center</button>
</div></article></div></div>
<div class="azd-coord-side">
<div class="azd-coord-block"><div class="azd-coord-subhead"><h3>Staff workload</h3><span>Action Center ownership</span></div>
<div class="azd-coord-staff"><div class="azd-coord-staff-row"><strong>Studio Administrator</strong>
<span>4 active · 2 due in 7d</span><small>1 overdue</small></div></div></div>
<div class="azd-coord-block"><div class="azd-coord-subhead"><h3>Scheduling pressure · next 7d</h3><span>Confirmed records</span></div>
<div class="azd-coord-schedule"><div><strong>6</strong><span>Confirmed appointments</span></div>
<div><strong>3</strong><span>Published events</span></div></div>
<p class="azd-coord-warning">Potential overlapping appointments need a calendar check.</p>
<a class="azd-link" href="calendar.php">Review Calendar</a></div></div></div>
</section>
<section class="azd-panel azd-action-center" id="azd-action-center">
<div class="azd-action-head"><h2>Agent Action Center</h2>
<div class="azd-agent-tools"><button data-actions-sync type="button">Check open issues</button>
<button data-actions-refresh type="button">Refresh tasks</button></div></div>
<div data-actions-counts class="azd-action-counts"></div><div class="azd-action-list-head">
<select data-actions-filter><option value="active">Active</option><option value="resolved">Resolved</option></select></div>
<p data-actions-status role="status"></p><div data-actions-list class="azd-action-list"></div>
</section></main></body></html>'''
def run():
 with sync_playwright() as p:
  opt={'headless':True,'args':['--no-sandbox']}
  if Path('/usr/bin/chromium').exists():opt['executable_path']='/usr/bin/chromium'
  else:opt['channel']='chrome'
  browser=p.chromium.launch(**opt)
  for width in (1440,1024,768,390,320):
   page=browser.new_page(viewport={'width':width,'height':920})
   calls=[];errs=[];tasks=[]
   page.on('pageerror',lambda e:errs.append(str(e)))
   def respond(route):
    req=route.request;action=parse_qs(urlparse(req.url).query).get('action',[''])[0]
    body=json.loads(req.post_data or '{}') if req.method=='POST' else None
    calls.append((req.method,action,body))
    if action=='actionCenter':
      assert req.method=='GET'
      data={'tasks':tasks,'history':{},'staff':[{'id':1,'name':'Studio Administrator'}],
        'counts':{'open':len(tasks),'acknowledged':0,'overdue':0,'resolved':0}}
    elif action=='actionTrack':
      assert req.method=='POST' and req.headers.get('x-csrf-token')=='fixture-csrf-verified',calls
      assert body=={'sourceKind':'production','sourceId':CASE_ID,'sourceStage':'production_assignment_due'}
      tasks.append({'id':'f'*32,'sourceKind':'production','title':'Production assignment past due',
        'detail':'Review real source status.','href':'productions.php?assignmentId='+CASE_ID,
        'priority':0,'state':'open','assigneeId':1,'assigneeName':'Studio Administrator',
        'dueAt':'2090-11-23T18:00:00Z','revision':1,'updatedAt':'2026-11-23T10:00:00Z','resolvedAt':None})
      data={'created':True,'id':'f'*32,'message':'Added to Action Center.'}
    else:raise AssertionError('Unexpected financial or production mutation: '+action)
    route.fulfill(status=200,content_type='application/json',body=json.dumps({'ok':True,'data':data}))
   page.route('**/agent-workspace-api.php?*',respond)
   page.set_content(HTML)
   page.add_style_tag(path=str(ROOT/'assets/admin-command.css'))
   page.add_script_tag(path=str(ROOT/'assets/admin-action-center.js'))
   page.wait_for_function("document.querySelector('[data-actions-status]').textContent.includes('loaded')",timeout=10000)
   assert page.locator('.azd-coord-stats>div').count()==6
   assert page.locator('.azd-coord-staff-row').count()==1
   assert page.get_by_role('heading',name='Agent Task Coordination').count()==1
   assert page.get_by_role('link',name='Review Calendar').count()==1
   assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),('initial overflow',width)
   assert len(calls)==1 and calls[0][:2]==('GET','actionCenter'),calls
   page.get_by_role('button',name='Track in Action Center').click()
   page.wait_for_function("document.querySelectorAll('.azd-action-task').length===1",timeout=10000)
   assert calls[-2][:2]==('POST','actionTrack')
   assert not any(x[1] in ('featureLive','reassign','reschedule','initiateRefund','buyReturnLabel','sendConnectTransfer') for x in calls)
   assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),('final overflow',width)
   assert not errs,(width,errs)
   page.screenshot(path=str(OUT/f'coordination_{width}.png'),full_page=True)
   print('Coordinator desktop/mobile and shared task track PASS',width)
   page.close()
  browser.close()
if __name__=='__main__':run()
