#!/usr/bin/env python3
"""Real Chrome 7/30/90-day, responsive, read-only Operations Learning acceptance."""
from datetime import datetime, timedelta, timezone
from pathlib import Path
from urllib.parse import urlparse,parse_qs
import json,os
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('RUNNER_TEMP','/tmp'))/'azh_ops_learning_browser'
OUT.mkdir(parents=True,exist_ok=True)
PHP=(ROOT/'admin-studio.php').read_text(encoding='utf-8')
ENDPOINT=(ROOT/'agent-workspace-api.php').read_text(encoding='utf-8')
assert 'id="azd-ops-learning"' in PHP and 'data-ops-results' in PHP
assert '<?php if($isAdmin): ?>' in PHP
assert 'az_admin_action_assert($user)' in ENDPOINT
HTML='''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">
<base href="https://azhottie.test/"></head><body style="margin:0;padding:8px;box-sizing:border-box;background:#fbf8fa">
<main id="azh-admin-command" class="azd" style="width:100%;box-sizing:border-box">
<section class="azd-panel azd-ops-learning" id="azd-ops-learning">
<div class="azd-panel-heading azd-ops-heading"><div><p class="azd-kicker">RECORDED OUTCOMES</p>
<h2>Agent Operations & Performance Learning</h2>
<p class="azd-muted">Workflow events, not individual staff performance scoring.</p></div>
<div class="azd-ops-tools"><label>Reporting period
<select data-ops-period aria-label="Operations reporting period">
<option value="7">Last 7 days</option><option value="30" selected>Last 30 days</option>
<option value="90">Last 90 days</option></select></label>
<button type="button" data-ops-refresh>Refresh analysis</button></div></div>
<p data-ops-status role="status" class="azd-ops-status">Loading…</p>
<div data-ops-results></div></section></main></body></html>'''
def report(days):
 end=datetime(2026,11,23,tzinfo=timezone.utc)
 dates=[(end-timedelta(days=days-1-i)).strftime('%Y-%m-%d') for i in range(days)]
 rows=[{'day':day,'created':i%4,'resolvedEvents':i%3,'reopenedEvents':int(i%8==0)}
       for i,day in enumerate(dates)]
 return {
  'role':'admin','periodDays':days,'periodStart':dates[0]+'T00:00:00Z',
  'generatedAt':'2026-11-23T11:00:00Z','automatedActions':False,
  'totals':{'created':23,'cohortResolvedNow':9,'cohortResolvedPct':39.1,
    'resolvedEvents':15,'resolvedTasks':14,'reopenedTasks':3,'reopenEvents':3,
    'acknowledgmentEvents':12,'openNow':11,'overdueNow':5,
    'medianResolutionHours':81.5,'resolutionSamples':14,'sampleTruncated':False},
  'trend':rows,'patterns':[
   {'key':'abc','kind':'production','label':'Production deadlines','count':12,'active':6,
    'href':'productions.php'},
   {'key':'def','kind':'casting','label':'Unreviewed model applications','count':5,'active':2,
    'href':'casting.php'}],
  'recommendations':[
   {'key':'overdue','title':'Review overdue Action Center','evidence':'5 active tasks are overdue.',
    'nextStep':'Review original task sources.','priority':0,'href':'admin-studio.php'},
   {'key':'escape','title':'<img src=x onerror=alert(1)>','evidence':'3 reopenings observed.',
    'nextStep':'Do not trust injected HTML.','priority':1,'href':'https://bad.example/'}
  ],
  'hasEvidence':True,'nextStep':'Review overdue staff follow-ups',
  'basis':'Read-only task records and immutable history; never creator performance scores.'
 }
def run():
 with sync_playwright() as p:
  options={'headless':True,'args':['--no-sandbox']}
  if Path('/usr/bin/chromium').exists():options['executable_path']='/usr/bin/chromium'
  else:options['channel']='chrome'
  browser=p.chromium.launch(**options)
  for width in (1440,1024,768,390,320):
   page=browser.new_page(viewport={'width':width,'height':950})
   requests=[];errors=[]
   page.on('pageerror',lambda error:errors.append(str(error)))
   def route_report(route):
    req=route.request
    query=parse_qs(urlparse(req.url).query)
    assert query.get('action')==['operationsLearning'],query
    days=int(query.get('days',['30'])[0])
    assert days in (7,30,90),days
    assert req.method=='GET',req.method
    requests.append((days,req.method))
    route.fulfill(status=200,content_type='application/json',
                  body=json.dumps({'ok':True,'data':report(days)}))
   page.route('**/agent-workspace-api.php?*',route_report)
   page.set_content(HTML)
   page.add_style_tag(path=str(ROOT/'assets/admin-command.css'))
   page.add_style_tag(path=str(ROOT/'assets/admin-ops-learning.css'))
   page.add_script_tag(path=str(ROOT/'assets/admin-ops-learning.js'))
   page.wait_for_function("document.querySelector('[data-ops-status]').textContent.includes('refreshed')",timeout=8000)
   assert requests==[(30,'GET')],requests
   assert page.locator('.azd-ops-stat').count()==6
   assert page.locator('.azd-ops-chart-day').count()==5 # Five 7-day buckets, no scrollbars
   assert page.get_by_role('heading',name='Evidence-backed recommendations').count()==1
   assert page.get_by_text('Production deadlines').count()==1
   assert page.locator('img').count()==0,'Untrusted source text interpreted as HTML'
   assert page.get_by_text('<img src=x onerror=alert(1)>').count()==1
   assert page.locator('.azd-ops-recommendation a').count()==1,'External recommendation link allowed'
   page.locator('[data-ops-period]').select_option('7')
   page.wait_for_function("document.querySelectorAll('.azd-ops-chart-day').length===7",timeout=8000)
   page.locator('[data-ops-period]').select_option('90')
   page.wait_for_function("document.querySelectorAll('.azd-ops-chart-day').length===13",timeout=8000)
   page.locator('[data-ops-refresh]').click()
   page.wait_for_function("document.querySelector('[data-ops-status]').textContent.includes('refreshed')",timeout=8000)
   assert requests==[(30,'GET'),(7,'GET'),(90,'GET'),(90,'GET')],requests
   assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),('horizontal overflow',width)
   assert all(method=='GET' for _,method in requests)
   assert not errors,(width,errors)
   page.screenshot(path=str(OUT/f'operations_learning_{width}.png'),full_page=True)
   print('Operations learning responsive/role/read-only/reporting periods PASS',width)
   page.close()
  # Non-admin markup does not contain the reporting section.
  editor=browser.new_page()
  editor.set_content('<main id="azh-admin-command"></main>')
  editor.add_script_tag(path=str(ROOT/'assets/admin-ops-learning.js'))
  assert editor.locator('[data-ops-period]').count()==0
  editor.close()
  browser.close()
if __name__=='__main__':run()
