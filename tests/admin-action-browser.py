#!/usr/bin/env python3
"""Responsive Action Center acceptance in real Chrome, with a strict read-only load and CSRF mutations."""
from pathlib import Path
import json,os
from urllib.parse import parse_qs,urlparse
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('RUNNER_TEMP','/tmp'))/'azh_admin_action_browser'
OUT.mkdir(parents=True,exist_ok=True)
PAGE=(ROOT/'admin-studio.php').read_text(encoding='utf-8')
API=(ROOT/'agent-workspace-api.php').read_text(encoding='utf-8')
assert 'id="azd-action-center"' in PAGE and 'data-action-track' in PAGE and 'data-actions-list' in PAGE
assert 'assets/admin-action-center.js?v=1.43.0' in PAGE
assert "if($method==='GET'&&$action==='actionCenter')" in API
assert "if($action==='actionChange')" in API and "if($action==='actionTrack')" in API
TASK_ID='a'*32
HTML='''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">
<base href="https://azhottie.test/"></head><body style="margin:0;padding:8px;box-sizing:border-box;background:#fdf9fb">
<main id="azh-admin-command" class="azd" data-csrf="safe-csrf-fixture" style="box-sizing:border-box;width:100%">
<section class="azd-panel azd-action-center" id="azd-action-center">
<div class="azd-panel-heading azd-action-head"><div><h2>Agent Action Center</h2><p>Staff-owned follow-ups and resolution history</p></div>
<div class="azd-agent-tools">
<button type="button" class="azd-agent-check" data-actions-sync>Check & track open issues</button>
<button type="button" class="azd-action-button" data-actions-refresh>Refresh tasks</button></div></div>
<div class="azd-action-counts" data-actions-counts></div>
<div class="azd-action-suggest"><h3>Track a current management recommendation</h3>
<div class="azd-action-suggest-list"><div class="azd-action-suggest-item"><span>Pending video review</span>
<button type="button" class="azd-action-button" data-action-track
data-kind="queue" data-source-id="cccccccccccccccccccccccccccccccc" data-stage="video_queue">Track</button></div></div></div>
<div class="azd-action-list-head"><h3>Staff-owned tasks</h3><label>Show <select data-actions-filter>
<option value="active">Open & acknowledged</option><option value="all">All tasks</option>
<option value="open">Open</option><option value="acknowledged">Acknowledged</option><option value="resolved">Resolved</option>
</select></label></div>
<p class="azd-action-summary" data-actions-status role="status"></p>
<div class="azd-action-list" data-actions-list></div></section></main></body></html>'''
def run():
  with sync_playwright() as p:
    settings={'headless':True,'args':['--no-sandbox']}
    if Path('/usr/bin/chromium').exists():settings['executable_path']='/usr/bin/chromium'
    else:settings['channel']='chrome'
    browser=p.chromium.launch(**settings)
    for width in (1440,1024,768,390,320):
      page=browser.new_page(viewport={'width':width,'height':900})
      calls=[];errors=[]
      state={'tasks':[{
        'id':TASK_ID,'sourceKind':'casting','title':'Review overdue casting application',
        'detail':'Staff must review the source questionnaire before making a decision.',
        'href':'casting.php?lead='+'b'*32,'state':'open','assigneeId':1,'assigneeName':'Studio Admin',
        'dueAt':'2026-11-24T12:00:00Z','priority':1,'revision':1,'updatedAt':'2026-11-23T12:00:00Z',
        'resolvedAt':None
      }],'staff':[{'id':1,'name':'Studio Admin'},{'id':2,'name':'Second Administrator'}],
       'counts':{'open':1,'acknowledged':0,'resolved':0,'overdue':0},'history':{TASK_ID:[
         {'action':'created','from':'','to':'open','actor':'Studio Admin',
          'note':'Created from existing aged workflow','createdAt':'2026-11-23T12:00:00Z'}]}}
      page.on('pageerror',lambda err:errors.append(str(err)))
      page.on('dialog',lambda dialog:dialog.accept())
      def route_action(route):
        request=route.request
        action=parse_qs(urlparse(request.url).query).get('action',[''])[0]
        body=json.loads(request.post_data or '{}') if request.method=='POST' else None
        calls.append((request.method,action,body))
        assert action in ('actionCenter','actionTrack','actionChange','actionSync'),calls
        if request.method=='POST':
          assert request.headers.get('x-csrf-token')=='safe-csrf-fixture',calls
          assert request.headers.get('content-type')=='application/json'
        if action=='actionCenter':
          assert request.method=='GET'
          data=state
        elif action=='actionTrack':
          assert request.method=='POST' and body=={
            'sourceKind':'queue','sourceId':'c'*32,'sourceStage':'video_queue'},body
          data={'id':TASK_ID,'created':False,'message':'This recommendation is already tracked.'}
        elif action=='actionSync':
          assert request.method=='POST' and body=={},body
          data={'created':0,'reminded':0,'truncated':False}
        else:
          assert request.method=='POST'
          task=state['tasks'][0]
          assert body['id']==TASK_ID and body['revision']==task['revision'],body
          previous=task['state'];op=body['operation']
          if op=='acknowledge':task['state']='acknowledged'
          elif op=='assign':
            assert body['assigneeId']==2 and body['dueAt'].endswith('Z'),body
            task['assigneeId']=2;task['assigneeName']='Second Administrator';task['dueAt']=body['dueAt']
          elif op=='resolve':
            assert len(body.get('note',''))>=10,body
            task['state']='resolved';task['resolvedAt']='2026-11-23T18:00:00Z'
          elif op=='reopen':
            assert len(body.get('note',''))>=10,body
            task['state']='open';task['resolvedAt']=None
          else:raise AssertionError('Unexpected operation '+op)
          task['revision']+=1
          state['counts']={'open':int(task['state']=='open'),'acknowledged':int(task['state']=='acknowledged'),
                           'resolved':int(task['state']=='resolved'),'overdue':0}
          state['history'][TASK_ID].insert(0,{'action':op,'from':previous,'to':task['state'],
            'actor':'Studio Admin','createdAt':'2026-11-23T18:00:00Z','note':body.get('note') or 'Task updated'})
          data={'id':TASK_ID,'revision':task['revision'],'state':task['state']}
        route.fulfill(status=200,content_type='application/json',body=json.dumps({'ok':True,'data':data}))
      page.route('**/agent-workspace-api.php?*',route_action)
      page.set_content(HTML)
      page.add_style_tag(path=str(ROOT/'assets/admin-command.css'))
      page.add_script_tag(path=str(ROOT/'assets/admin-action-center.js'))
      page.wait_for_function("document.querySelectorAll('.azd-action-task').length===1",timeout=10000)
      assert all(method=='GET' for method,_,__ in calls),'View load performed mutations'
      assert page.locator('.azd-action-count').count()==4
      assert page.locator('.azd-action-history').count()==1
      assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),('initial overflow',width)
      page.get_by_role('button',name='Acknowledge').click()
      page.wait_for_function("document.querySelector('.azd-action-status').textContent.includes('acknowledged')")
      page.locator('.azd-action-select').select_option('2')
      page.locator('.azd-action-date').fill('2026-11-26T10:30')
      page.get_by_role('button',name='Save owner & due date').click()
      page.wait_for_function("document.querySelector('.azd-action-meta').textContent.includes('Second Administrator')")
      page.locator('.azd-action-note').fill('Checked the original workflow and documented next steps.')
      page.get_by_role('button',name='Resolve with note').click()
      page.wait_for_function("document.querySelector('.azd-action-counts').textContent.includes('1') && document.querySelectorAll('.azd-action-task').length===0")
      page.locator('[data-actions-filter]').select_option('resolved')
      page.wait_for_function("document.querySelectorAll('.azd-action-task').length===1")
      page.locator('.azd-action-note').fill('Reopened because a staff source follow-up remains.')
      page.get_by_role('button',name='Reopen with note').click()
      page.wait_for_function("document.querySelectorAll('.azd-action-task').length===0")
      page.locator('[data-actions-filter]').select_option('active')
      page.wait_for_function("document.querySelectorAll('.azd-action-task').length===1")
      page.locator('.azd-action-history summary').click()
      assert page.locator('.azd-action-history-list li').count()==5,('audit trail',width)
      page.get_by_role('button',name='Track').click()
      page.wait_for_function("document.querySelector('[data-actions-status]').textContent.includes('already tracked')",timeout=10000)
      page.get_by_role('button',name='Check & track open issues').click()
      page.wait_for_function("document.querySelector('[data-actions-status]').textContent.includes('0 new tasks tracked')",timeout=10000)
      assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),('final overflow',width)
      assert not errors,(width,errors)
      assert not any(action in ('initiateRefund','buyReturnLabel','sendConnectTransfer','castingAdvance') for _,action,__ in calls)
      page.screenshot(path=str(OUT/f'admin_action_{width}.png'),full_page=True)
      print('Action Center responsive, history, staff ownership, CSRF, state transitions: PASS',width)
      page.close()
    editor=browser.new_page()
    editor.set_content('<main id="azh-admin-command"></main>')
    editor.add_script_tag(path=str(ROOT/'assets/admin-action-center.js'))
    assert editor.locator('#azd-action-center').count()==0
    editor.close()
    browser.close()
if __name__=='__main__':run()
