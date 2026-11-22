#!/usr/bin/env python3
"""Test Admin Brain Live Health UI using real CSS/JS with synthetic API fixtures.
Also verifies non-admin views are not shown private diagnostics.
"""
import pathlib,re,json
from playwright.sync_api import sync_playwright
root=pathlib.Path(__file__).resolve().parents[1]
html=(root/'tests/fixtures/agent_followthrough_v124.html').read_text()
html=re.sub(r'<script[^>]*>.*?</script>', '', html, flags=re.S)
html=re.sub(r'<link rel="stylesheet"[^>]*>', '<style>'+ (root/'assets/universal-header.css').read_text()+'</style>',html)
html=html.replace('<head>','<head><base href="https://azhottie.example/">')
js=(root/'assets/agent-workspace.js').read_text().replace('export function ','function ')
health={'state':'attention','room':{'title':'AZHottie Friday Live'},'director':{'cameraCount':2,'healthyCameras':1,'sentFps':0,'directRelays':1},'viewers':{'reporting':1,'playing':0,'stalled':1},'issues':[{'code':'viewer_freeze','title':'Fan playback reports frozen frames','advice':'Compare camera and Program output FPS.'}], 'notes':['Direct relay bypasses overlays.']}
with sync_playwright() as pw:
    browser=pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    for role in ('admin','model'):
      for width in (1440,768,390,320):
        context=browser.new_context(viewport={'width':width,'height':900},device_scale_factor=1)
        page=context.new_page();errors=[]
        page.on('pageerror',lambda e:errors.append(str(e)))
        page.set_content(html)
        brain={'basis':'Verified source-of-truth production health.','cards':[],'summary':{'liveAlerts':1 if role=='admin' else 0},'assignOptions':[],'followthrough':{},'liveHealth':health if role=='admin' else None}
        state={'ok':True,'data':{'role':role,'unreadCount':0,'notifications':[],'history':[],'brain':brain,'checkedAt':'2026-11-21T19:00:00Z'}}
        page.evaluate('''state=>{window.__requests=[];window.fetch=async (url,opts={})=>{let action=new URL(url).searchParams.get('action');window.__requests.push(action);return {ok:true,status:200,json:async()=>action==='badge'?{ok:true,data:{unreadCount:0}}:state};}}''',state)
        page.add_script_tag(content=js)
        page.locator('#azh-aw-bell').click();page.locator('[data-aw-tab="brain"]').click()
        section=page.locator('.azh-aw-live-health')
        if role=='admin':
          section.get_by_text('Fan playback reports frozen frames').wait_for()
          assert section.get_by_text('AZHottie Friday Live').count()==1
          assert section.get_by_text('1/2').count()==1
          assert section.get_by_text('0',exact=True).count()>=1
          assert section.get_by_text('Open Live Director').count()==1
          page.get_by_role('button',name='Recheck live health').click()
          assert page.evaluate("window.__requests.filter(x=>x==='state').length")>=2
          if width in (1440,390):
            page.locator('#azh-aw-content').evaluate('(el)=>el.scrollTop=0')
            page.screenshot(path=str(root.parent.parent/f'AZHottie_v1.25.0_live_agent_{"desktop" if width==1440 else "mobile"}.png'),full_page=True)
        else:
          assert section.count()==0
          assert page.get_by_text('Fan playback reports frozen frames').count()==0
        size=page.evaluate('({scroll:document.documentElement.scrollWidth,width:document.documentElement.clientWidth})')
        assert size['scroll']<=size['width']+1,(role,width,size)
        assert not errors,(role,width,errors)
        print('Browser PASS',role,width)
        context.close()
    browser.close()
