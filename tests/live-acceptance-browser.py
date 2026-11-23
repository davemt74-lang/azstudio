#!/usr/bin/env python3
"""Real Chromium acceptance UI: operator review vs telemetry, CSRF, notes, revisions,
read-only refresh and responsive sizing. The fixture uses NO real camera or fan.
"""
from pathlib import Path
from urllib.parse import urlparse,parse_qs
import json,os
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('RUNNER_TEMP','/tmp'))/'azh_live_acceptance_browser'
OUT.mkdir(parents=True,exist_ok=True)
SOURCE=(ROOT/'live-director.php').read_text(encoding='utf-8')
assert 'id="live-tab-acceptance"' in SOURCE
assert 'id="live-panel-acceptance"' in SOURCE
assert 'assets/live-acceptance.js?v=1.47.0' in SOURCE
STEPS={
 'phone_pairing':{'title':'Approved phone connection','instruction':'Pair approved phone and confirm its Share live status'},
 'moving_preview':{'title':'Moving phone preview','instruction':'Verify continuous decoded preview frames on Director'},
 'program_switch':{'title':'Program source switch','instruction':'Take the phone to Program and watch the Program image change'},
 'remote_fan':{'title':'Remote fan playback','instruction':'Watch from a second device on another network'},
 'fan_audio':{'title':'Fan audio playback','instruction':'Verify the remote fan hears the microphone'},
 'recording_playback':{'title':'Saved recording playback','instruction':'Play the saved private recording with audio'},
 'interruption_recovery':{'title':'Interrupted-feed recovery','instruction':'Confirm bounded recovery or safe holding slate'}
}
ROOM_ID='a'*32
HTML='''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">
<base href="https://azhottie.test/"></head>
<body style="margin:0;padding:8px;box-sizing:border-box;background:#fbf8fa">
<main class="azh-director" id="azh-director" data-csrf="valid-staff-csrf" style="max-width:1050px;box-sizing:border-box;margin:0 auto">
<button type="button" id="live-tab-acceptance" data-live-tab="acceptance">Broadcast checks</button>
<div class="azh-director-tabpanel" id="live-panel-acceptance" role="tabpanel" aria-labelledby="live-tab-acceptance" hidden style="height:auto;overflow:visible">
<section id="azh-live-acceptance" class="azh-live-acceptance" aria-label="Real-device broadcast acceptance">
<header class="azh-acceptance-header"><div><h2>Broadcast acceptance & diagnostics</h2>
<p>Operator attestations are separate from live telemetry.</p></div>
<button type="button" id="live-acceptance-refresh" class="azh-live-button azh-live-secondary">Refresh observations</button></header>
<p id="live-acceptance-status" role="status" aria-live="polite">Open this tab to inspect readiness</p>
<div id="live-acceptance-summary" class="azh-acceptance-summary"></div>
<h3>Recent measurements</h3><div id="live-acceptance-diagnostics" class="azh-acceptance-diagnostics"></div>
<p class="azh-acceptance-basis">Measurements are not independent proof of real hardware.</p>
<div class="azh-acceptance-checkhead"><h3>Real-device test checklist</h3><a href="live.php">Open fan viewer</a></div>
<div id="live-acceptance-checks" class="azh-acceptance-checklist"></div>
<details class="azh-acceptance-history"><summary>View checklist change history</summary>
<div id="live-acceptance-history"></div></details>
</section></div></main></body></html>'''
def run():
 with sync_playwright() as p:
  opts={'headless':True,'args':['--no-sandbox']}
  if Path('/usr/bin/chromium').exists():opts['executable_path']='/usr/bin/chromium'
  else:opts['channel']='chrome'
  browser=p.chromium.launch(**opts)
  for width in (1440,768,390,320):
   page=browser.new_page(viewport={'width':width,'height':890})
   errors=[];requests=[];state={'checks':{},'history':[]}
   page.on('pageerror',lambda err:errors.append(str(err)))
   page.on('dialog',lambda dialog:dialog.accept())
   def respond(route):
    req=route.request
    action=parse_qs(urlparse(req.url).query).get('action',[''])[0]
    payload=json.loads(req.post_data or '{}') if req.method=='POST' else None
    requests.append((action,req.method,payload))
    if action=='acceptanceStatus':
     assert req.method=='GET',req.method
     passed=sum(v['result']=='pass' for v in state['checks'].values())
     failed=sum(v['result']=='fail' for v in state['checks'].values())
     data={
       'room':{'id':ROOM_ID,'title':'Real-device fixture','status':'live','startedAt':'2026-11-23T13:00:00Z'},
       'steps':STEPS,'checks':state['checks'],
       'completion':{'total':7,'operatorPassed':passed,'operatorFailed':failed,
           'unverifiedSteps':7-passed-failed,
           'status':'operator_attested' if passed==7 else 'incomplete' if passed or failed else 'not_started',
           'hostedIndependentlyVerified':False,
           'basis':'Operator-attested means manually recorded, not independently verified.'},
       'diagnostics':{'phoneApproved':1,'phoneOffering':1,'directorFresh':True,'directorAgeSeconds':3,
           'healthyCameras':1,'cameraCount':1,'programMode':'single','sentFps':24.5,
           'viewerCount':1,'outboundStalls':0,'fanReporting':1,'fanPlaying':1,
           'fanStalled':0,'fanBlocked':0,'fanDecodedFpsMax':23.2,
           'savedRecordings':1,'largestSavedBytes':2300000},
       'history':state['history'],'checkedAt':'2026-11-23T14:00:00Z',
       'note':'Operator report is separate from browser measurements.'}
     route.fulfill(status=200,content_type='application/json',body=json.dumps({'ok':True,'data':data}))
    elif action=='acceptanceMark':
     assert req.method=='POST' and req.headers.get('x-csrf-token')=='valid-staff-csrf',requests
     assert payload['roomId']==ROOM_ID and payload['step'] in STEPS and payload['result'] in ('pass','fail','untested'),payload
     assert payload['revision']==state['checks'].get(payload['step'],{}).get('revision',0),payload
     assert len(payload['note'])>=12 if payload['result']!='untested' else True,payload
     prev=state['checks'].get(payload['step'],{}).get('result','untested')
     rev=payload['revision']+1
     state['checks'][payload['step']]={'result':payload['result'],'note':payload['note'],
         'revision':rev,'updatedAt':'2026-11-23T14:00:00Z'}
     state['history'].insert(0,{'step':payload['step'],'from':prev,'to':payload['result'],
                                'at':'2026-11-23T14:00:00Z'})
     data={'step':payload['step'],'result':payload['result'],'revision':rev,
           'operatorAttested':True,'independentlyVerified':False}
     route.fulfill(status=200,content_type='application/json',body=json.dumps({'ok':True,'data':data}))
    else:
     raise AssertionError('Acceptance panel sent unauthorized control action: '+str(action))
   page.route('**/live-api.php?*',respond)
   page.set_content(HTML)
   page.add_style_tag(path=str(ROOT/'assets/live-studio.css'))
   page.add_script_tag(path=str(ROOT/'assets/live-acceptance.js'),type='module')
   assert not requests,'Opening Director must not auto-post or mark tests'
   page.locator('#live-panel-acceptance').evaluate('(element)=>element.hidden=false')
   page.get_by_role('button',name='Broadcast checks').click()
   page.wait_for_function("document.querySelectorAll('.azh-acceptance-step').length===7",timeout=8000)
   assert all(method=='GET' for _,method,_ in requests),requests
   assert page.locator('.azh-acceptance-diagnostic').count()==8,width
   assert page.get_by_text('0 / 7 operator-attested passes').count()==1,width
   assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),('initial overflow',width)
   first=page.locator('.azh-acceptance-step').first
   first.locator('select').select_option('pass')
   first.locator('input').fill('Real iPhone sharing live on guest Wi-Fi')
   first.get_by_role('button',name='Save observation').click()
   page.wait_for_function("document.querySelector('[data-step=phone_pairing] .azh-acceptance-pill').textContent==='pass'",timeout=8000)
   assert page.get_by_text('1 / 7 operator-attested passes').count()==1
   assert len([call for call in requests if call[1]=='POST'])==1
   assert state['checks']['phone_pairing']['note']=='Real iPhone sharing live on guest Wi-Fi'
   page.get_by_role('button',name='Refresh observations').click()
   page.wait_for_function("document.getElementById('live-acceptance-status').textContent.includes('refreshed')",timeout=8000)
   assert len([call for call in requests if call[1]=='POST'])==1,'Refresh must not mutate checklist'
   page.locator('.azh-acceptance-history summary').click()
   assert page.locator('.azh-acceptance-history-row').count()==1
   assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),('after save overflow',width)
   assert not errors,(width,errors)
   page.screenshot(path=str(OUT/f'live_acceptance_{width}.png'),full_page=True)
   print('Live acceptance Chrome admin/manual/CSRF/telemetry/mobile PASS',width)
   page.close()
  editor=browser.new_page()
  editor.set_content('<main id="azh-director"></main>')
  editor.add_script_tag(path=str(ROOT/'assets/live-acceptance.js'),type='module')
  assert editor.locator('#azh-live-acceptance').count()==0
  editor.close()
  browser.close()
if __name__=='__main__':run()
