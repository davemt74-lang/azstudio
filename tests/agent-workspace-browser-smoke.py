#!/usr/bin/env python3
"""Actual header and drawer DOM tested in Chromium with simulated API payloads.
No network access, persistent user data, or live AZHottie backend is used.
"""
import subprocess
from pathlib import Path
import re, json
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]

STYLE=(root/'assets/style.css').read_text().replace('@import url("./form-light.css");','')+'\n'+(root/'assets/universal-header.css').read_text()
SCRIPT=(root/'assets/agent-workspace.js').read_text().replace('export function','function')

def fixture(role):
    roles="['admin']" if role=='admin' else "['model']"
    name='Director Admin' if role=='admin' else 'Jordan Model'
    code=f'$_SERVER["SCRIPT_NAME"]="/dashboard.php";$user=["id"=>1,"roles"=>{roles},"name"=>"{name}","status"=>"active"];require "includes/bootstrap.php";require "includes/universal-header.php";az_render_universal_header("dashboard",$user);'
    header=subprocess.check_output(['php','-r',code],cwd=root,stderr=subprocess.STDOUT).decode()
    header=re.sub(r'<script[^>]+src="[^"]+"[^>]*></script>','',header)
    return ('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><base href="https://azhottie.invalid/"><style>'+STYLE+'</style></head><body>'
      +header+'<main style="padding:40px;max-width:980px;margin:auto"><h1>Your studio dashboard</h1><p>Active productions, assignments and approved videos.</p></main></body></html>')

def payload(role):
    shot='b'*32
    if role=='admin':
        events=[{'id':'c'*32,'kind':'video_submitted','title':'A model submitted a video','detail':'Awaiting review',
                 'createdAt':'2026-11-21T18:00:00Z','readAt':None,'href':'user-submissions.php','actor':'Jordan Model'}]
        cards=[{'kind':'review','title':'Review a model video','detail':'Review the new submission.','href':'user-submissions.php','action':None}]
        choices=[{'shotId':shot,'label':'Studio session · Opening scene','models':[{'id':2,'name':'Jordan Model'}]}]
        counts={'pendingReviews':1,'awaitingResponses':1}
    else:
        events=[{'id':'c'*32,'kind':'video_submitted','title':'Video sent for admin review','detail':'Weekend studio video',
                 'createdAt':'2026-11-21T18:00:00Z','readAt':None,'href':'my-videos.php','actor':'You'},
                {'id':'work:'+'d'*32,'kind':'assignment','title':'New shot assigned','detail':'Studio opening scene',
                 'createdAt':'2026-11-21T17:00:00Z','readAt':None,'href':'my-work.php','actor':'Production team'}]
        cards=[{'kind':'assignment','title':'Respond to: Intro shot','detail':'Review and respond.',
                'href':'my-work.php?assignmentId='+shot,'action':{'type':'respond','id':shot,'revision':4}},
               {'kind':'opportunity','title':'Claim an available shot','detail':'Optional open shot.',
                'href':'my-work.php?shotId='+shot,'action':{'type':'claim','id':shot}}]
        choices=[];counts={'awaitingResponse':1,'availableShots':1}
    return {'role':role,'unreadCount':len(events),'notifications':events,'history':events,
            'brain':{'basis':'Verified assignments, submissions and status changes.','summary':counts,'cards':cards,
                     'assignOptions':choices,'generatedAt':'2026-11-21T19:00:00Z'},'checkedAt':'2026-11-21T19:00:00Z'}

STUB_FETCH="""initial=>{
 window.__state=initial;window.__calls=[];
 window.fetch=(raw,opts={})=>{
   const url=new URL(raw,document.baseURI),action=url.searchParams.get('action');let data;
   if(url.pathname.endsWith('work-api.php')){
     window.__calls.push([action,JSON.parse(opts.body||'{}')]);data={status:'accepted'};
   } else if(action==='badge')data={unreadCount:window.__state.unreadCount};
   else if(action==='markRead'){
     const body=JSON.parse(opts.body||'{}');
     for(const e of window.__state.history)if(body.all||body.ids?.includes(e.id))e.readAt='2026-11-21T20:00:00Z';
     window.__state.notifications=window.__state.history.filter(e=>!e.readAt);
     window.__state.unreadCount=window.__state.notifications.length;data=window.__state;
   }else data=window.__state;
   return Promise.resolve(new Response(JSON.stringify({ok:true,data}),{status:200,headers:{'Content-Type':'application/json'}}));
 };
}"""

with sync_playwright() as playwright:
    browser=playwright.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage'])
    for role in ('model','admin'):
        html=fixture(role)
        for width in (1440,768,390,320):
            page=browser.new_page(viewport={'width':width,'height':820},device_scale_factor=1)
            errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
            page.on('dialog',lambda d:d.accept())
            page.set_default_timeout(7500)
            page.route('**/*', lambda route: route.abort())
            page.set_content(html,wait_until='domcontentloaded',timeout=7500)
            page.evaluate(STUB_FETCH,payload(role))
            page.add_script_tag(content=SCRIPT)
            page.wait_for_function("document.querySelector('#azh-aw-badge')?.textContent.length>0")
            assert page.locator('#azh-aw-drawer').is_hidden(),(role,width)
            page.locator('#azh-aw-bell').click()
            page.locator('.azh-aw-event').first.wait_for()
            stats=page.evaluate("""()=>({scrollWidth:document.documentElement.scrollWidth,viewport:innerWidth,
                     drawerWidth:document.querySelector('.azh-aw-drawer').getBoundingClientRect().width,
                     bg:getComputedStyle(document.querySelector('.azh-aw-event')).backgroundColor})""")
            assert stats['scrollWidth']<=width+1 and stats['drawerWidth']<=width+1,(role,width,stats)
            assert stats['bg']=='rgb(255, 255, 255)',(role,width,stats)
            assert page.evaluate('window.__calls')==[],(role,width)
            page.locator('#azh-aw-tab-history').click()
            assert page.locator('.azh-aw-event').count()>=1,(role,width)
            page.locator('#azh-aw-tab-brain').click()
            assert page.locator('.azh-aw-brain-card').count()>=1,(role,width)
            if role=='model':
                page.get_by_role('button',name='Accept assignment').click()
                page.wait_for_function("window.__calls.length>0")
                calls=page.evaluate('window.__calls')
                assert calls[0][0]=='respond' and calls[0][1]['revision']==4 and calls[0][1]['decision']=='accept',calls
            else:
                page.get_by_role('button',name='Assign selected model').click()
                page.wait_for_function("window.__calls.length>0")
                calls=page.evaluate('window.__calls')
                assert calls[0][0]=='assign' and calls[0][1]['userId']==2,calls
            if width in (1440,390):
                output=f'/mnt/data/AZHottie_v1.23.0_agent_{role}_{"desktop" if width==1440 else "mobile"}_preview.png'
                page.screenshot(path=output)
                print(output)
            # Acknowledging activity changes only this signed-in user's read status.
            page.locator('#azh-aw-tab-notifications').click()
            page.locator('#azh-aw-read-all').click()
            page.wait_for_function("window.__state.unreadCount===0 && document.querySelector('#azh-aw-badge').hidden",timeout=7000)
            page.locator('#azh-aw-tab-history').click()
            assert page.locator('.azh-aw-event').count()>=1,(role,width)
            page.keyboard.press('Escape')
            assert page.locator('#azh-aw-drawer').is_hidden(),(role,width)
            assert not errors,(role,width,errors)
            print('PASS',role,width,'responsive, permissions, notifications, history, brain and assignment controls')
            page.close()
    browser.close()
