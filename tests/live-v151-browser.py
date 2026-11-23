#!/usr/bin/env python3
"""v1.51 real Chromium: cancel a Join on room turnover, mobile shortcuts,
first-frame recording probe. Media/network are simulated; real-phone tests remain.
"""
from pathlib import Path
from base64 import b64encode
import os
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('RUNNER_TEMP','/tmp'))/'azh_live_v151_browser'
OUT.mkdir(parents=True,exist_ok=True)

def viewer_source():
    source=(ROOT/'assets/live-viewer.js').read_text(encoding='utf-8')
    for name in ('phone-live.js','live-agent-health.js','personal-phone-core.js','live-core.js',
                 'live-viewer-core.js','live-recovery.js','live-production-guards.js','live-v151-guards.js'):
        url='data:text/javascript;base64,'+b64encode((ROOT/'assets'/name).read_bytes()).decode('ascii')
        anchor="from './"+name+"'"
        assert anchor in source,anchor
        source=source.replace(anchor,"from '"+url+"'")
    return source

HTML='''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><base href="https://azhottie.test/"></head><body>
<main id="azh-fan-live" data-csrf="v151-csrf" style="max-width:1050px;margin:0 auto">
<video id="fan-live-video" playsinline muted hidden></video><div id="fan-live-offline">Offline</div>
<span id="fan-live-pill" hidden>Live</span><strong id="fan-live-title">Live</strong>
<p id="fan-live-status" role="status">Checking…</p><span id="fan-live-recovery" hidden></span>
<button id="fan-live-sound" hidden>Unmute sound</button>
<button id="fan-live-join" hidden>Join live</button><button id="fan-live-leave" hidden>Leave</button>
</main></body></html>'''

STUB=r'''window.__room='a'.repeat(32);window.__requests=[];window.__releaseOld=null;window.__joinIndex=0;
const realInterval=window.setInterval.bind(window);
window.setInterval=(handler,ms,...args)=>{
 if(ms===4500&&String(handler).includes('check()')){window.__statusCheck=handler;return 9999;}
 return realInterval(handler,ms,...args);
};
class FakePeer{
 constructor(){this.connectionState='connecting';this.iceGatheringState='complete';this.localDescription=null;this.currentRemoteDescription=null;}
 addTransceiver(){}createOffer(){return Promise.resolve({type:'offer',sdp:'v=0\r\n'});}
 setLocalDescription(offer){this.localDescription=offer;return Promise.resolve();}
 setRemoteDescription(answer){this.currentRemoteDescription=answer;return Promise.resolve();}
 close(){this.connectionState='closed';}
}
window.RTCPeerConnection=FakePeer;
window.fetch=async(input,options={})=>{
 const url=new URL(input,document.baseURI),action=url.searchParams.get('action'),body=options.body?JSON.parse(options.body):null;
 window.__requests.push({action,body});
 const ok=data=>new Response(JSON.stringify({ok:true,data}),{status:200,headers:{'Content-Type':'application/json'}});
 if(action==='status')return ok({room:window.__room?{id:window.__room,title:'Active test program'}:null,iceServers:[]});
 if(action==='join'){
  window.__joinIndex++;
  if(window.__joinIndex===1)return await new Promise(resolve=>{
    window.__releaseOld=()=>resolve(ok({viewerId:'c'.repeat(32)}));
  });
  return ok({viewerId:'d'.repeat(32)});
 }
 if(action==='leave')return ok({left:true});
 if(action==='poll')return ok({answer:null});
 if(action==='healthReport')return ok({recorded:true});
 throw new Error('Unexpected API call '+action);
};void 0;'''

def browser():
    with sync_playwright() as pw:
        opts={'headless':True,'args':['--no-sandbox']}
        if Path('/usr/bin/chromium').exists():opts['executable_path']='/usr/bin/chromium'
        else:opts['channel']='chrome'
        b=pw.chromium.launch(**opts)
        src=viewer_source()
        for width in (1440,390,320):
            p=b.new_page(viewport={'width':width,'height':820})
            failures=[];p.on('pageerror',lambda e:failures.append(str(e)))
            p.set_content(HTML);p.add_style_tag(path=str(ROOT/'assets/live-studio.css'))
            p.evaluate(STUB);p.add_script_tag(content=src,type='module')
            p.get_by_role('button',name='Join live').wait_for(state='visible')
            p.get_by_role('button',name='Join live').click()
            p.wait_for_function("window.__joinIndex===1&&typeof window.__releaseOld==='function'")
            # Real new server status while Join response is still pending.
            p.evaluate("window.__room=null;window.__statusCheck()")
            p.wait_for_function("document.getElementById('fan-live-status').textContent.includes('offline')")
            assert p.get_by_role('button',name='Leave').is_hidden(),width
            assert p.get_by_role('button',name='Join live').is_hidden(),width
            p.evaluate("window.__room='b'.repeat(32);window.__statusCheck()")
            p.get_by_role('button',name='Join live').wait_for(state='visible')
            p.get_by_role('button',name='Join live').click()
            p.wait_for_function("window.__joinIndex===2 && document.getElementById('fan-live-leave').hidden===false")
            p.evaluate("window.__releaseOld()")
            p.wait_for_function("window.__requests.some(x=>x.action==='leave'&&x.body?.viewerId==='c'.repeat(32))")
            assert not p.evaluate("window.__requests.some(x=>x.action==='leave'&&x.body?.viewerId==='d'.repeat(32))"),width
            assert p.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),width
            assert not failures,(width,failures)
            p.screenshot(path=str(OUT/f'fan_room_turnover_{width}.png'))
            p.close();print('Fan in-flight Join room turnover and stale lease cleanup PASS',width)

        nav=(ROOT/'live-director.php').read_text()
        nav=nav[nav.index('<nav class="azh-mobile-director-bar"'):nav.index('</nav>',nav.index('<nav class="azh-mobile-director-bar"'))+6]
        js=(ROOT/'assets/live-mobile-controls.js').read_text()+'\nwindow.__wire=wireMobileDirectorShortcuts;'
        for width in (1440,390,320):
            p=b.new_page(viewport={'width':width,'height':800})
            p.set_content('<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><body class="azh-director-body"><main id="azh-director" class="azh-director azh-director-app"><header class="azh-director-toolbar"><strong>Live Director</strong></header><div class="azh-director-workspace"><section class="azh-director-program"><div class="azh-director-monitor">PROGRAM</div><section class="azh-director-tabs">Studio controls</section></section><aside class="azh-director-sources"><button id="live-invite">Connect phone</button></aside></div>'+nav+'</main></body>')
            p.add_style_tag(path=str(ROOT/'assets/live-studio.css'))
            p.add_script_tag(content=js,type='module')
            p.wait_for_function('typeof window.__wire === "function"')
            p.evaluate('''()=>{window.__actions=[];window.__record={canStart:true,canStop:false,backupPending:false};
              window.__controls=window.__wire({root:document.getElementById('azh-director'),sourcePanel:document.querySelector('.azh-director-sources'),
              nextCamera:()=>window.__actions.push('take'),startRecord:()=>window.__actions.push('start'),stopRecord:()=>window.__actions.push('stop'),
              showAudio:()=>window.__actions.push('audio'),showRecording:()=>window.__actions.push('review'),
              canTake:()=>true,recordStatus:()=>window.__record});}''')
            if width<=850:
                assert p.locator('#live-mobile-record').is_visible(),width
                p.get_by_role('button',name='Take next').click()
                p.get_by_role('button',name='Start Program recording').click()
                p.evaluate("window.__record={canStart:false,canStop:true,backupPending:false};window.__controls.refresh()")
                p.get_by_role('button',name='Stop Program recording').click()
                p.get_by_role('button',name='Audio').click()
                p.evaluate("window.__record={canStart:false,canStop:false,backupPending:true};window.__controls.refresh()")
                p.get_by_role('button',name='Review unsaved Program recording').click()
                assert p.evaluate('window.__actions')==['take','start','stop','audio','review'],(width,p.evaluate('window.__actions'))
            else:assert not p.locator('#live-mobile-record').is_visible(),width
            assert p.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),width
            if width<=850: assert p.evaluate('document.getElementById("live-mobile-record").getBoundingClientRect().bottom > innerHeight - 110'),width
            p.screenshot(path=str(OUT/f'mobile_director_{width}.png'))
            p.close();print('Director mobile shortcuts and viewport PASS',width)

        p=b.new_page()
        rec=(ROOT/'assets/live-record-verify.js').read_text()
        helper='data:text/javascript;base64,'+b64encode((ROOT/'assets/live-v151-guards.js').read_bytes()).decode('ascii')
        rec=rec.replace("from './live-v151-guards.js'","from '"+helper+"'")+"\nwindow.__probe=verifyRecordedVideo;"
        p.set_content('<!doctype html><title>Video verification</title>')
        p.add_script_tag(content=rec,type='module')
        p.wait_for_function('typeof window.__probe === "function"')
        result=p.evaluate('''async()=>{
            const canvas=document.createElement('canvas');canvas.width=320;canvas.height=180;
            const c=canvas.getContext('2d');const stream=canvas.captureStream(15);
            if(!window.MediaRecorder?.isTypeSupported('video/webm;codecs=vp8'))return {skipped:true,reason:'WebM MediaRecorder unavailable'};
            const rec=new MediaRecorder(stream,{mimeType:'video/webm;codecs=vp8'}),parts=[];
            rec.ondataavailable=e=>{if(e.data.size)parts.push(e.data)};
            const timer=setInterval(()=>{c.fillStyle='#d36d9b';c.fillRect(0,0,320,180);c.fillStyle='#fff';c.fillRect(Math.random()*250,60,40,40)},60);
            rec.start(200);await new Promise(r=>setTimeout(r,1100));
            const stopped=new Promise(r=>rec.onstop=r);rec.stop();await stopped;clearInterval(timer);stream.getTracks().forEach(t=>t.stop());
            const blob=new Blob(parts,{type:'video/webm'}),url=URL.createObjectURL(blob);
            const check=await window.__probe(url,{timeoutMs:9000});URL.revokeObjectURL(url);
            return {...check,bytes:blob.size};
        }''')
        assert result.get('skipped') or (result['ok'] and result['bytes']>1024),(result)
        p.close();b.close()
        print('Browser-decoded recording first frame PASS',result)

if __name__=='__main__':browser()
