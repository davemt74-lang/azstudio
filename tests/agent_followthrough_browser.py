#!/usr/bin/env python3
"""Offline Chromium smoke with the real v1.24 drawer JS/CSS and mocked API responses.
Network-free because browser localhost connections are unavailable in this environment.
"""
import json,pathlib,re
from playwright.sync_api import sync_playwright
ROOT=pathlib.Path(__file__).resolve().parents[1]
html=(ROOT/'tests/fixtures/agent_followthrough_v124.html').read_text()
html=re.sub(r'<script[^>]*>.*?</script>', '', html, flags=re.S)
html=re.sub(r'<link rel="stylesheet"[^>]*>', '<style>'+ (ROOT/'assets/universal-header.css').read_text()+'</style>',html)
html=html.replace('<head>','<head><base href="https://azhottie.example/">')
js=(ROOT/'assets/agent-workspace.js').read_text().replace('export function ','function ')
ID='a'*32

def api_state(role):
    notices=[{'id':'c'*32,'kind':'followthrough_unanswered_48h','title':'Unanswered assignment: Opening scene','detail':'Model has not responded for 48 hours','href':'productions.php?assignmentId='+ID,'createdAt':'2026-11-21T12:00:00Z','readAt':None,'actor':'AZHottie Agent'}]
    cards=[{'kind':'followup','title':'Follow up: Opening scene','detail':'Model · Show · No response over 24 hours','href':'productions.php?assignmentId='+ID,'priority':1,'action':{'type':'remind','id':ID,'revision':3}}] if role=='admin' else [{'kind':'assignment','title':'Respond to: Opening scene','detail':'Review assignment','href':'my-work.php?assignmentId='+ID,'priority':1,'action':{'type':'respond','id':ID,'revision':3}}]
    brain={'basis':'Verified assignments and reviews. Server cron required for scheduled scans.','summary':{'awaitingResponses':1,'overdue':0},'cards':cards,'assignOptions':[],'followthrough':{'cron':{'completedAt':None,'checked':0,'delivered':0},'manual':{'completedAt':None,'checked':0,'delivered':0}}}
    return {'ok':True,'data':{'role':role,'unreadCount':1,'notifications':notices,'history':notices,'brain':brain,'checkedAt':'2026-11-21T12:00:00Z'}}

with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    for role in ('admin','model'):
      for width in (1440,768,390,320):
        context=browser.new_context(viewport={'width':width,'height':900},device_scale_factor=1)
        page=context.new_page();errors=[]
        page.on('pageerror',lambda e:errors.append(str(e)))
        page.set_content(html)
        state=api_state(role)
        page.evaluate('''(state)=>{
          window.__mockCalls=[];window.confirm=()=>true;
          window.fetch=async (url,opts={})=>{
            const u=new URL(url);const action=u.searchParams.get('action');
            window.__mockCalls.push({action,method:opts.method||'GET',data:opts.body||null,csrf:opts.headers?.['X-CSRF-Token']||null});
            let output;
            if(action==='badge')output={ok:true,data:{unreadCount:1}};
            else if(action==='state')output=state;
            else if(action==='checkFollowthrough')output={ok:true,data:{checked:11,delivered:3,truncated:false,completedAt:'2026-11-21T12:00:00Z'}};
            else if(action==='remindAssignment')output={ok:true,data:{sent:true,assignmentId:'a'.repeat(32)}};
            else output={ok:false,error:{message:'Unknown request'}};
            return {ok:true,status:200,json:async()=>output};
          };
        }''',state)
        page.add_script_tag(content=js)
        page.locator('#azh-aw-bell').click()
        page.locator('[data-aw-tab="brain"]').click()
        page.get_by_text('Follow up: Opening scene' if role=='admin' else 'Respond to: Opening scene').wait_for()
        if role=='admin':
            page.get_by_role('button',name='Check assignments now').click()
            page.get_by_text('Checked 11 assignments').wait_for()
            page.get_by_role('button',name='Send reminder').click()
            page.get_by_text('Reminder delivered to model notifications').wait_for()
            calls=page.evaluate('window.__mockCalls')
            assert any(c['action']=='checkFollowthrough' and c['method']=='POST' and c['csrf'] for c in calls)
            assert any(c['action']=='remindAssignment' and json.loads(c['data'])=={'assignmentId':ID,'revision':3} and c['csrf'] for c in calls)
        else:
            assert page.get_by_role('button',name='Check assignments now').count()==0
            assert page.get_by_role('button',name='Send reminder').count()==0
        size=page.evaluate('({sw:document.documentElement.scrollWidth,cw:document.documentElement.clientWidth})')
        assert size['sw']<=size['cw']+1,(role,width,size)
        assert not errors,(role,width,errors)
        if role=='admin' and width in (1440,390):
            path=ROOT.parent.parent/f'AZHottie_v1.24.0_agent_followthrough_{"desktop" if width==1440 else "mobile"}.png'
            page.screenshot(path=str(path),full_page=True)
        print('Chromium PASS',role,width,'calls',len(page.evaluate('window.__mockCalls')),'errors',len(errors))
        context.close()
    browser.close()
