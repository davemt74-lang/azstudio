#!/usr/bin/env python3
"""Actual Live Viewer source under a delayed Join API response.

Simulates the user cancelling while signaling is pending, a fresh manual Join,
then a late old Join response. The old lease must be LEFT, the new viewer stays
active, and neither can take a camera to Program. No real media or hardware.
"""
from pathlib import Path
from base64 import b64encode
from urllib.parse import urlparse,parse_qs
import os
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('RUNNER_TEMP','/tmp'))/'azh_live_v149_browser'
OUT.mkdir(parents=True,exist_ok=True)
HTML='''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">
<base href="https://azhottie.test/"></head><body style="margin:0;padding:12px;box-sizing:border-box;background:#fbf8fa">
<main class="azh-fan-live" id="azh-fan-live" data-csrf="fan-v149-csrf" style="max-width:1050px;margin:auto">
<section class="azh-fan-player"><div class="azh-fan-screen">
<video id="fan-live-video" playsinline autoplay muted controls hidden></video>
<div id="fan-live-offline" class="azh-fan-offline"><strong>Director on air</strong>
<p>Join for Program playback.</p></div><span id="fan-live-pill" class="azh-fan-pill" hidden>● LIVE</span></div>
<div class="azh-fan-bar"><div><strong id="fan-live-title">Live session</strong>
<p id="fan-live-status" role="status" aria-live="polite">Checking</p></div>
<button type="button" id="fan-live-sound" hidden>Unmute sound</button>
<span id="fan-live-recovery" hidden role="status" aria-live="polite"></span>
<button type="button" id="fan-live-join" hidden>Join live</button>
<button type="button" id="fan-live-leave" hidden>Leave</button>
</div></section></main></body></html>'''
def viewer_source():
    root=(ROOT/'assets/live-viewer.js').read_text(encoding='utf-8')
    for name in ('phone-live.js','live-agent-health.js','personal-phone-core.js',
                 'live-core.js','live-viewer-core.js','live-recovery.js','live-production-guards.js'):
        content=(ROOT/'assets'/name).read_bytes()
        url='data:text/javascript;base64,'+b64encode(content).decode('ascii')
        anchor="from './"+name+"'"
        assert anchor in root,anchor
        root=root.replace(anchor,"from '"+url+"'")
    return root
STUB=r"""
window.__peers=[];
window.__calls=[];
window.__joinIndex=0;
window.__releaseOld=null;
class RTCStub{
 constructor(){this.connectionState='connecting';this.iceGatheringState='complete';
 this.localDescription=null;this.currentRemoteDescription=null;window.__peers.push(this);}
 addTransceiver(){}
 createOffer(){return Promise.resolve({type:'offer',sdp:'v=0\r\nstubOffer'});}
 setLocalDescription(offer){this.localDescription=offer;return Promise.resolve();}
 setRemoteDescription(answer){this.currentRemoteDescription=answer;return Promise.resolve();}
 close(){this.connectionState='closed';}
}
window.RTCPeerConnection=RTCStub;
window.fetch=async(input,options={})=>{
 const parsed=new URL(input,document.baseURI),action=parsed.searchParams.get('action');
 if(!parsed.pathname.endsWith('/live-api.php'))throw Error('Unexpected URL '+parsed);
 const body=options.body?JSON.parse(options.body):null;
 window.__calls.push({action,method:options.method||'GET',body});
 if(action==='status')return new Response(JSON.stringify({ok:true,data:{
    room:{id:'a'.repeat(32),title:'Test live program'},iceServers:[]}}),
    {status:200,headers:{'Content-Type':'application/json'}});
 if(action==='join'){
    if(options.method!=='POST'||options.headers['X-CSRF-Token']!=='fan-v149-csrf')
       throw Error('Join missing member CSRF');
    window.__joinIndex++;
    const id=(window.__joinIndex===1?'a':'b').repeat(32);
    if(window.__joinIndex===1) return await new Promise(resolve=>{
       window.__releaseOld=()=>{
          resolve(new Response(JSON.stringify({ok:true,data:{viewerId:id}}),
             {status:200,headers:{'Content-Type':'application/json'}}));
       };
    });
    return new Response(JSON.stringify({ok:true,data:{viewerId:id}}),
       {status:200,headers:{'Content-Type':'application/json'}});
 }
 if(action==='poll')return new Response(JSON.stringify({ok:true,data:{answer:null,status:'waiting'}}),
    {status:200,headers:{'Content-Type':'application/json'}});
 if(action==='leave'){
    if(!body||!['a'.repeat(32),'b'.repeat(32)].includes(body.viewerId))throw Error('Bad lease ID');
    return new Response(JSON.stringify({ok:true,data:{left:true}}),
        {status:200,headers:{'Content-Type':'application/json'}});
 }
 if(action==='healthReport')return new Response(JSON.stringify({ok:true,data:{recorded:true}}),
    {status:200,headers:{'Content-Type':'application/json'}});
 throw Error('Unexpected streaming action '+action);
};
"""
def run():
    src=viewer_source()
    with sync_playwright() as pw:
        options={'headless':True,'args':['--no-sandbox']}
        if Path('/usr/bin/chromium').exists():options['executable_path']='/usr/bin/chromium'
        else:options['channel']='chrome'
        browser=pw.chromium.launch(**options)
        for width in (1440,768,390,320):
            page=browser.new_page(viewport={'width':width,'height':850})
            errors=[];page.on('pageerror',lambda err:errors.append(str(err)))
            page.set_content(HTML)
            page.add_style_tag(path=str(ROOT/'assets/live-studio.css'))
            page.evaluate(STUB)
            page.add_script_tag(content=src,type='module')
            page.get_by_role('button',name='Join live').wait_for(state='visible',timeout=8000)
            page.get_by_role('button',name='Join live').click()
            page.wait_for_function("window.__joinIndex===1 && typeof window.__releaseOld==='function'",timeout=8000)
            page.get_by_role('button',name='Leave',exact=True).wait_for(state='visible',timeout=5000)
            page.get_by_role('button',name='Leave',exact=True).click()
            page.get_by_role('button',name='Join live').wait_for(state='visible',timeout=4000)
            assert page.evaluate("window.__calls.filter(x=>x.action==='leave').length") == 0, \
                'No lease ID exists before the old request returns'
            page.get_by_role('button',name='Join live').click()
            page.wait_for_function("window.__joinIndex===2 && document.getElementById('fan-live-leave').hidden===false",timeout=8000)
            assert page.locator('#fan-live-join').is_hidden()
            page.evaluate("window.__releaseOld()")
            page.wait_for_function("window.__calls.some(x=>x.action==='leave'&&x.body.viewerId==='a'.repeat(32))",timeout=5000)
            calls=page.evaluate('window.__calls')
            stale=[x for x in calls if x['action']=='leave' and x['body']['viewerId']=='a'*32]
            active=[x for x in calls if x['action']=='leave' and x['body']['viewerId']=='b'*32]
            assert len(stale)==1 and not active,('Late Join did not release only stale lease',width,calls)
            assert page.get_by_role('button',name='Leave',exact=True).is_visible()
            page.get_by_role('button',name='Leave',exact=True).click()
            page.wait_for_function("window.__calls.some(x=>x.action==='leave'&&x.body.viewerId==='b'.repeat(32))",timeout=5000)
            assert page.get_by_role('button',name='Join live').is_visible()
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),('horizontal overflow',width)
            assert not errors,(width,errors)
            page.screenshot(path=str(OUT/f'fan_join_lifecycle_{width}.png'),full_page=True)
            page.close()
            print('Delayed fan Join, early Leave, new session preserved, stale slot cleanup PASS',width)
        browser.close()
if __name__=='__main__':run()
