#!/usr/bin/env python3
"""Actual fan viewer module in Chromium with isolated in-memory WebRTC signaling.
No external website or real camera is required. Proves fresh negotiations,
shutdown of stale viewers, manual Leave, and phone-size accessibility.
"""
from pathlib import Path
from base64 import b64encode
import json,os
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('RUNNER_TEMP','/tmp'))/'azh_live_recovery_browser'
OUT.mkdir(parents=True,exist_ok=True)
HTML='''<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1">
<base href="https://azhottie.test/"></head>
<body style="margin:0;padding:12px;box-sizing:border-box;background:#fcf8fa;min-width:0">
<main class="azh-fan-live" id="azh-fan-live" data-csrf="fan-fixture-token" style="max-width:1050px;margin:auto">
<section class="azh-fan-player"><div class="azh-fan-screen">
<video id="fan-live-video" autoplay muted controls playsinline hidden></video>
<div id="fan-live-offline" class="azh-fan-offline"><strong>Director is on air</strong>
<p>Join this live show to see the Program.</p></div><span id="fan-live-pill" class="azh-fan-pill" hidden>● LIVE</span></div>
<div class="azh-fan-bar"><div><strong id="fan-live-title">AZHottie Live</strong>
<p id="fan-live-status" role="status" aria-live="polite">Checking broadcast…</p></div>
<button id="fan-live-sound" type="button" aria-pressed="false" hidden>Unmute sound</button>
<span id="fan-live-recovery" class="azh-fan-recovery" role="status" aria-live="polite" hidden></span>
<button id="fan-live-join" type="button" class="azh-live-button" hidden>Join live</button>
<button id="fan-live-leave" type="button" class="azh-live-button" hidden>Leave</button>
</div></section></main></body></html>'''
# All imports are local source modules. Embed them as data: URLs so the test
# still runs in network-restricted environments and does not rely on a host.
def bundled_viewer():
 root=(ROOT/'assets/live-viewer.js').read_text(encoding='utf-8')
 for name in ('phone-live.js','live-agent-health.js','personal-phone-core.js',
              'live-core.js','live-viewer-core.js','live-recovery.js'):
  contents=(ROOT/'assets'/name).read_bytes()
  uri='data:text/javascript;base64,'+b64encode(contents).decode('ascii')
  old="from './"+name+"'"
  assert old in root,name
  root=root.replace(old,"from '"+uri+"'")
 return root
STUB='''
window.__peers=[];window.__calls=[];window.__room='a'.repeat(32);
class FakeRTC {
 constructor(){this.connectionState='connecting';this.iceGatheringState='complete';this.localDescription=null;
  this.currentRemoteDescription=null;window.__peers.push(this);}
 addTransceiver(){}
 createOffer(){return Promise.resolve({type:'offer',sdp:'v=0\\r\\nFake WebRTC offer'});}
 setLocalDescription(offer){this.localDescription=offer;return Promise.resolve();}
 setRemoteDescription(answer){this.currentRemoteDescription=answer;return Promise.resolve();}
 close(){this.connectionState='closed';}
}
window.RTCPeerConnection=FakeRTC;
window.fetch=async(input,options={})=>{
 const url=new URL(String(input),document.baseURI);
 if(!url.pathname.endsWith('live-api.php'))throw Error('Unexpected URL: '+url);
 const action=url.searchParams.get('action');
 const payload=options.body?JSON.parse(options.body):null;
 window.__calls.push({action,method:options.method||'GET',payload});
 let data;
 if(action==='status')data={room:{id:window.__room,title:'Live Camera Test'},iceServers:[]};
 else if(action==='join'){
  if(options.method!=='POST'||options.headers['X-CSRF-Token']!=='fan-fixture-token')throw Error('Lost CSRF');
  if(payload.roomId!==window.__room||payload.nonce.length!==32)throw Error('Invalid fresh viewer handshake');
  data={viewerId:String(window.__calls.filter(c=>c.action==='join').length).padStart(32,'0')};
 }else if(action==='poll')data={answer:null,status:'waiting'};
 else if(action==='leave')data={left:true};
 else if(action==='healthReport')data={recorded:true};
 else throw Error('Unexpected signaling action '+action);
 return new Response(JSON.stringify({ok:true,data}),{status:200,headers:{'Content-Type':'application/json'}});
};
void 0;
'''
def run():
 source=bundled_viewer()
 with sync_playwright() as p:
  opts={'headless':True,'args':['--no-sandbox']}
  if Path('/usr/bin/chromium').exists():opts['executable_path']='/usr/bin/chromium'
  else:opts['channel']='chrome'
  browser=p.chromium.launch(**opts)
  for width in (1440,768,390,320):
   page=browser.new_page(viewport={'width':width,'height':820})
   errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
   page.set_content(HTML)
   page.add_style_tag(path=str(ROOT/'assets/live-studio.css'))
   page.evaluate(STUB)
   page.add_script_tag(content=source,type='module')
   page.get_by_role('button',name='Join live').wait_for(state='visible',timeout=8000)
   assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),('initial overflow',width)
   page.get_by_role('button',name='Join live').click()
   page.wait_for_function('window.__peers.length===1 && document.getElementById("fan-live-leave").hidden===false',timeout=8000)
   assert page.evaluate('window.__calls.filter(c=>c.action==="join").length')==1
   page.evaluate("()=>{const peer=window.__peers[0];peer.connectionState='failed';peer.onconnectionstatechange?.();}")
   page.get_by_text('Reconnecting 1/3',exact=False).wait_for(timeout=3000)
   assert page.locator('#fan-live-recovery').get_attribute('role')=='status'
   page.screenshot(path=str(OUT/f'fan_reconnecting_{width}.png'),full_page=True)
   page.wait_for_function('window.__peers.length===2 && document.getElementById("fan-live-leave").hidden===false',timeout=6000)
   calls=page.evaluate('window.__calls')
   joins=[c for c in calls if c['action']=='join']
   leaves=[c for c in calls if c['action']=='leave']
   assert len(joins)==2 and len(leaves)==1,('New handshake or stale cleanup failed',width,calls)
   assert joins[0]['payload']['nonce']!=joins[1]['payload']['nonce'],'Each retry requires a fresh nonce'
   page.get_by_role('button',name='Leave',exact=True).click()
   assert page.locator('#fan-live-leave').is_hidden()
   page.wait_for_timeout(1500)
   assert page.evaluate('window.__calls.filter(c=>c.action==="join").length')==2,'Explicit Leave must disable auto rejoin'
   page.get_by_role('button',name='Join live',exact=True).click()
   page.wait_for_function('window.__peers.length===3 && document.getElementById("fan-live-leave").hidden===false',timeout=6000)
   assert page.evaluate('window.__calls.filter(c=>c.action==="join").length')==3,'Manual Join must re-enable'
   assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),('final overflow',width)
   assert not errors,(width,errors)
   page.screenshot(path=str(OUT/f'fan_rejoined_{width}.png'),full_page=True)
   page.close()
   print('Fan WebRTC retry, stale viewer cleanup, Leave, mobile layout: PASS',width)
  browser.close()
if __name__=='__main__':run()
