#!/usr/bin/env python3
"""Real Chrome acceptance of v1.48 stage triage and redacted support workflow.
Uses only fake, in-memory acceptance status; no physical cameras/network actions.
"""
from pathlib import Path
from urllib.parse import urlparse, parse_qs
import json, os
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('RUNNER_TEMP','/tmp'))/'azh_live_triage_browser'
OUT.mkdir(parents=True,exist_ok=True)
SOURCE=(ROOT/'live-director.php').read_text(encoding='utf-8')
assert 'id="live-triage-findings"' in SOURCE
assert 'id="live-support-summary"' in SOURCE
assert 'live-acceptance.js?v=1.48.0' in SOURCE
STEPS={
 'phone_pairing':{'title':'Approved phone connection','instruction':'Approve real phone QR pairing'},
 'moving_preview':{'title':'Moving phone preview','instruction':'Verify decoded moving camera frames'},
 'program_switch':{'title':'Program source switch','instruction':'Take an approved phone to Program'},
 'remote_fan':{'title':'Remote fan playback','instruction':'Watch from separate real network'},
 'fan_audio':{'title':'Fan audio playback','instruction':'Listen on a separate device'},
 'recording_playback':{'title':'Saved recording playback','instruction':'Verify saved recording and sound'},
 'interruption_recovery':{'title':'Interrupted-feed recovery','instruction':'Test feed interruption and recovery'}
}
HTML='''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">
<base href="https://azhottie.test/"></head>
<body style="margin:0;padding:8px;background:#fbf8fa;box-sizing:border-box">
<main class="azh-director" id="azh-director" data-csrf="valid-acceptance-fixture-token"
style="width:100%;max-width:1070px;margin:auto;box-sizing:border-box">
<button id="live-tab-acceptance" type="button">Broadcast checks</button>
<div id="live-panel-acceptance" hidden style="height:auto;overflow:visible">
<section id="azh-live-acceptance" class="azh-live-acceptance">
<header class="azh-acceptance-header"><div><h2>Broadcast acceptance & diagnostics</h2>
<p>Real device attestations, separate from browser telemetry.</p></div>
<button id="live-acceptance-refresh" type="button">Refresh observations</button></header>
<p id="live-acceptance-status" role="status"></p>
<div id="live-acceptance-summary" class="azh-acceptance-summary"></div>
<section class="azh-live-triage">
<div class="azh-live-subhead"><h3>Connection troubleshooting</h3><span>Read only</span></div>
<p id="live-triage-status" role="status"></p><div id="live-triage-findings" class="azh-live-triage-findings"></div>
</section>
<h3>Recent measurements</h3><div id="live-acceptance-diagnostics" class="azh-acceptance-diagnostics"></div>
<div class="azh-acceptance-checkhead"><h3>Real-device test checklist</h3></div>
<div id="live-acceptance-checks" class="azh-acceptance-checklist"></div>
<details class="azh-live-support">
<summary>Redacted diagnostic summary for technical support</summary>
<textarea id="live-support-summary" readonly rows="7"></textarea>
<button id="live-support-copy" type="button">Copy redacted summary</button></details>
<details class="azh-acceptance-history"><summary>History</summary>
<div id="live-acceptance-history"></div></details>
</section></div></main></body></html>'''
def payload(room_id,status):
    is_new=room_id=='b'*32
    is_live=status=='live'
    issue={
      'code':'fan_frozen' if is_new else 'phone_no_offer',
      'priority':0 if is_new else 1,'severity':'critical' if is_new else 'warning',
      'stage':'remote_fan' if is_new else 'moving_preview',
      'title':'Fan video frames are frozen' if is_new else 'Approved phone has no recent camera offer',
      'evidence':'1 frozen fan sample' if is_new else '1 phone approved, 0 recent offers',
      'nextCheck':'Inspect Program send FPS, then retry on a second network' if is_new else 'Restart Share live on the approved phone'
    }
    d={'phoneApproved':1 if is_live else 0,'phoneOffering':0,
       'directorFresh':is_live,'healthyCameras':1,'cameraCount':1,
       'programMode':'single','sentFps':24.3,'viewerCount':1 if is_live else 0,
       'outboundStalls':0,'fanReporting':1 if is_live else 0,
       'fanPlaying':0 if is_new else 1,'fanStalled':1 if is_new else 0,
       'fanBlocked':0,'directorAgeSeconds':2 if is_live else None,
       'savedRecordings':1,'largestSavedBytes':2600000}
    triage={
      'state':'urgent' if is_new else 'attention','criticalCount':1 if is_new else 0,
      'attentionCount':0 if is_new else 1,'findings':[issue],
      'nextStep':issue['title']+': '+issue['nextCheck'],'nextChecklistStep':issue['stage'],
      'automatedRecoveryTriggered':False,'independentlyVerified':False,
      'basis':'Browser samples and operator observations do not certify physical acceptance.'
    }
    packet={
       'format':'azhottie-live-support-v1','roomState':status,'telemetryFresh':is_live,
       'programMode':'single' if is_live else None,
       'measurements':{'phoneApproved':1 if is_live else None,'sentFps':24.3 if is_live else None,
            'fanStalled':1 if is_new and is_live else None,
            'savedRecordings':1,'largestSavedBytes':2600000},
       'issueCodes':[issue['code']],'operatorPassed':0,'operatorFailed':0,
       'operatorUntested':7,'hostedIndependentlyVerified':False,
       'disclosure':'No names, stream IDs, SDP, IPs or private notes.'
    }
    return {
       'room':{'id':room_id,'title':'Fixture show '+('B' if is_new else 'A'),'status':status,'startedAt':'2026-11-23T13:00:00Z'},
       'steps':STEPS,'checks':{},'history':[],
       'completion':{'total':7,'operatorPassed':0,'operatorFailed':0,'unverifiedSteps':7,
          'status':'not_started','hostedIndependentlyVerified':False,
          'basis':'Operator attestation does not imply independent network verification.'},
       'diagnostics':d,'triage':triage,'supportSummary':packet,
       'checkedAt':'2026-11-23T14:00:00Z'
    }
def run():
 with sync_playwright() as p:
  launch={'headless':True,'args':['--no-sandbox']}
  if Path('/usr/bin/chromium').exists():launch['executable_path']='/usr/bin/chromium'
  else:launch['channel']='chrome'
  browser=p.chromium.launch(**launch)
  for width in (1440,768,390,320):
   page=browser.new_page(viewport={'width':width,'height':900})
   state={'room':'a'*32,'status':'live'}
   errors=[];requests=[]
   page.on('pageerror',lambda e:errors.append(str(e)))
   def intercept(route):
    req=route.request
    qs=parse_qs(urlparse(req.url).query)
    assert qs.get('action')==['acceptanceStatus'],qs
    assert 'roomId' not in qs,('Old room ID must not pin a newer broadcast',qs)
    assert req.method=='GET',req.method
    requests.append((req.method,state['room']))
    route.fulfill(status=200,content_type='application/json',
        body=json.dumps({'ok':True,'data':payload(state['room'],state['status'])}))
   page.route('**/live-api.php?*',intercept)
   page.set_content(HTML)
   page.evaluate("""()=>{
     window.__copied='';
     Object.defineProperty(navigator,'clipboard',{configurable:true,
       value:{writeText:async t=>{window.__copied=t;}}});
   }""")
   page.add_style_tag(path=str(ROOT/'assets/live-studio.css'))
   page.add_script_tag(path=str(ROOT/'assets/live-acceptance.js'),type='module')
   page.locator('#live-panel-acceptance').evaluate('(node)=>node.hidden=false')
   page.get_by_role('button',name='Broadcast checks').click()
   page.wait_for_function("document.querySelectorAll('.azh-live-triage-item').length===1",timeout=8000)
   assert page.locator('.azh-live-triage-item strong').get_by_text('Approved phone has no recent camera offer',exact=True).count()==1
   assert requests==[('GET','a'*32)],requests
   assert page.get_by_text('0 / 7 operator-attested passes').count()==1
   page.get_by_role('button',name='Go to Moving phone preview').click()
   focused=page.locator('[data-step="moving_preview"] select').evaluate('(el)=>document.activeElement===el')
   assert focused,(width,'Checklist jump did not focus the correct stage')
   page.locator('.azh-live-support summary').click()
   page.get_by_role('button',name='Copy redacted summary').click()
   page.wait_for_function("window.__copied.includes('azhottie-live-support-v1')",timeout=4000)
   copied=page.evaluate('window.__copied')
   for private in ['a'*32,'Fixture show','remoteUser','accessToken','SDP=','192.168.']:
      assert private not in copied,(width,'Leaked support value',private,copied)
   assert json.loads(copied)['hostedIndependentlyVerified'] is False
   assert all(method=='GET' for method,_ in requests),'No mutations during triage/export'
   # New broadcast starts: latest-owner GET must replace old room even if old still exists.
   state['room']='b'*32
   page.get_by_role('button',name='Refresh observations').click()
   page.wait_for_function("document.querySelector('#live-triage-findings').textContent.includes('Fan video frames are frozen')",timeout=8000)
   assert page.locator('.azh-live-triage-item[data-severity="critical"]').count()==1
   assert requests[-1]==('GET','b'*32),'Acceptance panel stuck on prior broadcast'
   page.get_by_role('button',name='Go to Remote fan playback').click()
   assert page.locator('[data-step="remote_fan"] select').evaluate('(el)=>document.activeElement===el')
   assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),('horizontal overflow',width)
   page.screenshot(path=str(OUT/f'live_triage_{width}.png'),full_page=True)
   assert not errors,(width,errors)
   page.close()
   print('Live triage, fresh-room tracking, redaction, explicit test link, mobile: PASS',width)
  browser.close()
if __name__=='__main__':run()
