#!/usr/bin/env python3
"""Chromium checks using real v1.26 front-end JS and CSS with role-scoped mocked API data.
No real member content, production database or remote network is accessed.
"""
import json,re
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
fixture=(root/'tests/fixtures/agent_followthrough_v124.html').read_text()
fixture=re.sub(r'<script[^>]*>.*?</script>','',fixture,flags=re.S)
fixture=re.sub(r'<link rel="stylesheet"[^>]*>','',fixture)
style=(root/'assets/style.css').read_text()+'\n'+(root/'assets/universal-header.css').read_text()+'\n'+(root/'assets/agent-casting-chat.css').read_text()
fixture=fixture.replace('</head>','<base href="https://azhottie.example/"><style>'+style+'</style></head>')
brain_js=(root/'assets/agent-workspace.js').read_text().replace('export function ','function ')
chat_js=(root/'assets/agent-casting-chat.js').read_text().replace('export function ','function ').replace('location.href','document.baseURI').replace('location.search',"'?topic=casting'")
LEAD='a'*32
MODEL=23

def onboarding():
    return {'modelId':MODEL,'label':'Taylor Studio','active':True,'complete':2,'total':4,'ready':False,
      'items':[{'key':'profile','label':'Complete model bio','done':True,'canConfirm':False},
               {'key':'availability','label':'Set availability','done':True,'canConfirm':False},
               {'key':'studio_briefing','label':'Studio orientation completed','done':False,'canConfirm':True},
               {'key':'production_ready','label':'Ready for production assignments','done':False,'canConfirm':True}]}

def casting(role):
    if role=='admin':
        return {'counts':{'newApplications':2,'screening':1,'interviews':1,'approved':0,'converted':1,'onboardingPending':1},
          'cards':[{'kind':'casting','title':'Review new casting application','detail':'Application waiting for review','href':'casting.php?lead='+LEAD,
                    'priority':1,'action':{'type':'casting_stage','id':LEAD,'revision':2,'next':'contacted'}}],
          'onboarding':None,'onboardingModels':[onboarding()],
          'matches':[{'shotId':'b'*32,'shot':'Opening scene','modelId':MODEL,'model':'Taylor Studio','reason':'Active roster; availability requires confirmation'}],
          'scan':{'completedAt':None}}
    return {'counts':{'onboardingComplete':2,'onboardingTotal':4},'cards':[{'kind':'onboarding','title':'Complete your model onboarding',
      'detail':'Two steps need attention','href':'account.php','priority':2,'action':None}],
      'onboarding':onboarding(),'matches':[],'scan':None}

def state(role):
    c=casting(role)
    return {'role':role,'unreadCount':1,'notifications':[{'id':'c'*32,'kind':'casting_new_application' if role=='admin' else 'casting_onboarding','title':'Casting update',
      'detail':'Casting status changed','href':'casting.php?lead='+LEAD if role=='admin' else 'account.php',
      'actor':'AZHottie Agent','createdAt':'2026-11-21T15:00:00Z','readAt':None}],
      'history':[],'brain':{'basis':'Verified casting and assignments','summary':c['counts'],'cards':c['cards'],
                          'casting':c,'assignOptions':[],'followthrough':{},'liveHealth':{'state':'off_air','issues':[]}},'checkedAt':'2026-11-21T15:00:00Z'}

stub='''(config)=>{
 window.__calls=[];window.confirm=()=>true;
 window.fetch=async (raw,opts={})=>{
   const url=new URL(raw,document.baseURI),action=url.searchParams.get('action');
   window.__calls.push({action,method:opts.method||'GET',body:opts.body||null,csrf:opts.headers?.['X-CSRF-Token']||null});
   let data;
   if(action==='badge')data={unreadCount:1};
   else if(action==='state')data=config.state;
   else if(action==='castingBriefing')data=config.casting;
   else if(action==='checkCasting')data={checked:3,delivered:1,truncated:false};
   else if(action==='castingAdvance')data={confirmed:true,stage:'contacted',revision:3};
   else if(action==='castingCheckpoint')data=config.casting.onboardingModels?.[0]||{};
   else data={};
   return new Response(JSON.stringify({ok:true,data}),{status:200,headers:{'Content-Type':'application/json'}});
 };
}'''

with sync_playwright() as playwright:
    browser=playwright.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage'])
    for role in ('admin','model'):
        for width in (1440,768,390,320):
            context=browser.new_context(viewport={'width':width,'height':850},device_scale_factor=1)
            page=context.new_page();page.set_default_timeout(7000)
            errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
            page.route('https://azhottie.example/**',lambda route: route.abort())
            page.set_content(fixture,wait_until='domcontentloaded')
            page.evaluate(stub,{'state':state(role),'casting':casting(role)})
            page.add_script_tag(content=brain_js)
            page.locator('#azh-aw-bell').click()
            page.locator('#azh-aw-tab-brain').click()
            page.locator('.azh-aw-followthrough').first.wait_for()
            if role=='admin':
                page.get_by_role('button',name='Check casting now').click()
                page.wait_for_function("window.__calls.some(c=>c.action==='checkCasting'&&c.method==='POST')")
                page.get_by_role('button',name='Move to contacted').click()
                page.wait_for_function("window.__calls.some(c=>c.action==='castingAdvance')")
                page.get_by_role('button',name='Confirm').first.click()
                page.wait_for_function("window.__calls.some(c=>c.action==='castingCheckpoint')")
                calls=page.evaluate('window.__calls')
                assert any(c['action']=='castingAdvance' and json.loads(c['body'])=={'leadId':LEAD,'revision':2,'nextStage':'contacted'} and c['csrf'] for c in calls)
                assert any(c['action']=='castingCheckpoint' and c['csrf'] for c in calls)
            else:
                assert page.get_by_role('button',name='Check casting now').count()==0
                assert page.get_by_role('button',name='Move to contacted').count()==0
                assert page.get_by_role('button',name='Confirm').count()==0
                assert page.get_by_text('Set availability').count()>=1
            dimensions=page.evaluate('({total:document.documentElement.scrollWidth,view:document.documentElement.clientWidth})')
            assert dimensions['total']<=dimensions['view']+1,(role,width,dimensions)
            assert not errors,(role,width,errors)
            print('Brain Chromium PASS',role,width)
            context.close()

            # Agent Chat companion: separate real JS and CSS, same API context and CSRF.
            context=browser.new_context(viewport={'width':width,'height':850},device_scale_factor=1)
            page=context.new_page();page.set_default_timeout(7000)
            errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
            html='''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>'''+style+'''</style></head><body>
            <main style="max-width:850px;margin:auto;padding:16px"><h1>AZHottie Agent</h1>
              <section id="azh-casting-chat-panel" class="azh-casting-chat-panel" data-csrf="test-csrf-token" data-role="'''+role+'''">
                <div class="azh-casting-chat-head"><h2>Casting &amp; Onboarding Brain</h2></div>
                <p data-casting-status role="status"></p><div data-casting-body></div></section>
              <textarea id="azh-agent-input" aria-label="Message AZHottie Agent"></textarea>
            </main></body></html>'''
            page.set_content(html.replace('</head>','<base href="https://azhottie.example/"></head>'),wait_until='domcontentloaded')
            page.evaluate(stub,{'state':state(role),'casting':casting(role)})
            page.add_script_tag(content=chat_js)
            page.get_by_role('button',name='Ask Agent about this').wait_for()
            assert page.locator('#azh-agent-input').input_value().startswith('Summarize' if role=='admin' else 'What is my')
            page.get_by_role('button',name='Ask Agent about this').click()
            assert page.locator('#azh-agent-input').input_value()
            if role=='admin':
                page.get_by_role('button',name='Move to contacted').click()
                page.wait_for_function("window.__calls.some(c=>c.action==='castingAdvance')")
            else:
                assert page.get_by_role('button',name='Move to contacted').count()==0
            dimensions=page.evaluate('({total:document.documentElement.scrollWidth,view:document.documentElement.clientWidth})')
            assert dimensions['total']<=dimensions['view']+1,(role,width,dimensions)
            assert not errors,(role,width,errors)
            print('Chat Chromium PASS',role,width)
            context.close()
    browser.close()
