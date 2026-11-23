/* v1.47 Live Broadcast Acceptance & Diagnostics.
 * Only an administrator viewing the Live Director can see this panel.
 * Measurements are read-only. Step changes require explicit CSRF-protected clicks.
 */
const acceptanceRoot=document.getElementById('azh-live-acceptance');
if(acceptanceRoot){
  const app=document.getElementById('azh-director');
  const panel=document.getElementById('live-panel-acceptance');
  const status=document.getElementById('live-acceptance-status');
  const summary=document.getElementById('live-acceptance-summary');
  const diagnostics=document.getElementById('live-acceptance-diagnostics');
  const checklist=document.getElementById('live-acceptance-checks');
  const history=document.getElementById('live-acceptance-history');
  const refreshButton=document.getElementById('live-acceptance-refresh');
  const triageStatus=document.getElementById('live-triage-status');
  const triageFindings=document.getElementById('live-triage-findings');
  const supportTextarea=document.getElementById('live-support-summary');
  const supportCopy=document.getElementById('live-support-copy');
  let currentRoom=null,loading=false,saving=false,lastSnapshot=null,refreshTimer=null;
  const make=(tag,value='',cls='')=>{
    const el=document.createElement(tag);
    el.textContent=String(value??'');
    if(cls)el.className=cls;
    return el;
  };
  const add=(parent,tag,value='',cls='')=>{const el=make(tag,value,cls);parent.append(el);return el;};
  const count=value=>Number.isFinite(Number(value))?Math.max(0,Number(value)):0;
  const fmtDate=value=>{
    const date=new Date(value);
    return Number.isNaN(date.getTime())?'Time unknown':date.toLocaleString();
  };
  const panelVisible=()=>!document.hidden && panel && !panel.hidden;
  async function request(action,body=null,query={}){
    const url=new URL('live-api.php',document.baseURI);
    url.searchParams.set('action',action);
    for(const [key,value] of Object.entries(query))url.searchParams.set(key,String(value));
    const opts={method:body===null?'GET':'POST',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}};
    if(body!==null){
      opts.headers['Content-Type']='application/json';
      opts.headers['X-CSRF-Token']=app?.dataset.csrf||'';
      opts.body=JSON.stringify(body);
    }
    const response=await fetch(url,opts);
    const payload=await response.json().catch(()=>null);
    if(!response.ok||!payload?.ok){const error=new Error(payload?.error?.message||'Could not read broadcast acceptance.');error.status=response.status;throw error;}
    return payload.data;
  }
  function renderSummary(snapshot){
    const completed=snapshot.completion||{};
    summary.dataset.state=completed.status||'not_started';
    summary.replaceChildren();
    const title=snapshot.room
      ?(snapshot.room.status==='live'?'On air — ':'Last broadcast — ')+(snapshot.room.title||'Live studio')
      :'No recent broadcast owned by this administrator';
    add(summary,'strong',title);
    add(summary,'p',count(completed.operatorPassed)+' / '+count(completed.total||7)+' operator-attested passes · '+
      count(completed.operatorFailed)+' failed steps · '+count(completed.unverifiedSteps||7)+' untested','');
    add(summary,'p',completed.basis||'Manual checklist results are not independent production verification.');
  }
  function valueCard(label,value,detail){
    const box=add(diagnostics,'div','','azh-acceptance-diagnostic');
    add(box,'strong',value);
    add(box,'span',label);
    if(detail)add(box,'small',detail);
  }
  /** A diagnostic hint never asserts that a camera/network test is verified.
   * Clicks only navigate to the existing operator checklist, never to a
   * camera/program/recording mutation endpoint.
   */
  function renderTriage(snapshot){
    if(!triageStatus||!triageFindings)return;
    const report=snapshot.triage;
    triageFindings.replaceChildren();
    if(!report){
      triageStatus.textContent='Automated troubleshooting is not available for this broadcast. Follow the real-device checklist below.';
      return;
    }
    triageStatus.textContent=report.nextStep||
      'Review the camera and Program measurements and run the operator checks on real devices.';
    const findings=Array.isArray(report.findings)?report.findings.slice(0,12):[];
    for(const finding of findings){
      const item=add(triageFindings,'article','','azh-live-triage-item');
      item.dataset.severity=['critical','warning','info'].includes(finding.severity)?finding.severity:'info';
      add(item,'strong',finding.title||'Review broadcast evidence');
      add(item,'p',finding.evidence||'A test observation needs review.');
      add(item,'p',finding.nextCheck||'Confirm the original source state.');
      const step=String(finding.stage||'');
      if(snapshot.room&&snapshot.steps&&Object.hasOwn(snapshot.steps,step)){
        const button=add(item,'button','Go to '+snapshot.steps[step].title,'azh-live-button azh-live-secondary');
        button.type='button';
        button.addEventListener('click',()=>{
          const target=[...checklist.querySelectorAll('.azh-acceptance-step')].find(el=>el.dataset.step===step);
          if(!target){status.textContent='Open the checklist and refresh observations first.';return;}
          target.scrollIntoView({behavior:'smooth',block:'nearest'});
          target.querySelector('select')?.focus({preventScroll:true});
        });
      }
    }
    if(!findings.length){
      const good=add(triageFindings,'p','','azh-acceptance-basis');
      good.textContent='No automated exception threshold was crossed. Physical-device acceptance still requires actual phone, fan, audio, and recording tests.';
    }
  }
  /** Only a backend allowlisted support object is copied. The original room
   * reference, test notes, identities, IPs, URLs and SDP are never included.
   */
  function renderSupport(snapshot){
    if(!supportTextarea)return;
    const safe=snapshot.supportSummary;
    if(!safe||safe.format!=='azhottie-live-support-v1'){
      supportTextarea.value='A redacted support report is not available for this broadcast.';
      if(supportCopy)supportCopy.disabled=true;
      return;
    }
    supportTextarea.value=JSON.stringify(safe,null,2);
    if(supportCopy)supportCopy.disabled=false;
  }
  if(supportCopy&&supportTextarea){
    supportCopy.addEventListener('click',async()=>{
      if(supportCopy.disabled||!supportTextarea.value)return;
      try{
        if(!navigator.clipboard?.writeText)throw new Error('Clipboard permission unavailable');
        await navigator.clipboard.writeText(supportTextarea.value);
        status.textContent='Copied the redacted diagnostic summary. It contains no room IDs, private notes, viewer names, or stream tokens.';
      }catch(error){
        supportTextarea.focus();supportTextarea.select();
        status.textContent='Select and copy the highlighted redacted summary manually. No diagnostic information was sent to a server.';
      }
    });
  }
  function renderDiagnostics(snapshot){
    diagnostics.replaceChildren();
    const d=snapshot.diagnostics;
    if(!d){add(diagnostics,'p','Start a broadcast to obtain live measurements. No healthy camera or viewer is assumed.','azh-acceptance-basis');return;}
    const live=snapshot.room?.status==='live';
    valueCard('Approved phones',live?String(count(d.phoneApproved)):'—','Current device approval; not a decoded preview or a historical result');
    valueCard('Recent phone offers',live?String(count(d.phoneOffering)):'—','Recent signaling offer, not a decoded preview');
    valueCard('Director camera previews',live&&d.directorFresh&&d.healthyCameras!==null
      ?String(count(d.healthyCameras)):'Unverified','Based on the Director browser health report');
    valueCard('Program source / send FPS',live&&d.directorFresh
      ?String(d.programMode||'unknown')+' · '+(d.sentFps===null?'FPS unmeasured':count(d.sentFps).toFixed(1)+' FPS')
      :'Unverified','Sending FPS requires a connected receiving viewer');
    valueCard('Fan sessions decoding',live?String(count(d.fanPlaying)):'—',
      'Browser-reported playing sessions, not unique fans');
    valueCard('Fan connection issues',live?String(count(d.fanStalled)+count(d.fanBlocked)):'—',
      count(d.fanStalled)+' stalled · '+count(d.fanBlocked)+' autoplay blocked');
    valueCard('Fresh Director sample',live&&d.directorAgeSeconds!==null
      ?count(d.directorAgeSeconds)+'s old':'Unverified','Samples older than 35 seconds are ignored');
    valueCard('Saved recording records',String(count(d.savedRecordings)),
      count(d.largestSavedBytes)+' maximum stored bytes; playback not verified');
  }
  function renderHistory(events=[]){
    history.replaceChildren();
    if(!events.length){add(history,'p','No saved operator observations for this broadcast.');return;}
    for(const e of events){
      const line=add(history,'p','','azh-acceptance-history-row');
      add(line,'strong',String(e.step||'').replaceAll('_',' ')+': ');
      add(line,'span',String(e.from||'untested')+' → '+String(e.to||'untested')+' · '+fmtDate(e.at));
    }
  }
  function renderChecks(snapshot){
    checklist.replaceChildren();
    if(!snapshot.room){
      add(checklist,'p','No broadcast session is available yet. Start a show and refresh this tab to record a real-device test.','azh-acceptance-basis');
      return;
    }
    const steps=snapshot.steps||{};
    const checks=snapshot.checks||{};
    for(const [key,description] of Object.entries(steps)){
      const saved=checks[key]||{result:'untested',revision:0,note:''};
      const card=add(checklist,'article','','azh-acceptance-step');
      card.dataset.step=key;
      const row=add(card,'div','','azh-acceptance-stephead');
      add(row,'strong',description.title);
      const pill=add(row,'span',String(saved.result||'untested').replaceAll('_',' '),'azh-acceptance-pill');
      pill.dataset.result=saved.result||'untested';
      add(card,'p',description.instruction);
      const fields=add(card,'div','','azh-acceptance-fields');
      const resultWrap=add(fields,'label','Operator result');
      const select=add(resultWrap,'select');
      select.setAttribute('aria-label',description.title+' result');
      for(const [value,label] of [['untested','Not tested'],['pass','Observed pass'],['fail','Observed failure']]){
        const option=add(select,'option',label);
        option.value=value;
      }
      select.value=saved.result||'untested';
      const noteWrap=add(fields,'label','Observation (no personal details)');
      const note=add(noteWrap,'input');
      note.type='text';note.maxLength=240;note.value=saved.note||'';
      note.placeholder='Device, network, result; at least 12 characters for pass/fail';
      note.setAttribute('aria-label',description.title+' observation');
      const save=add(fields,'button','Save observation','azh-live-button azh-live-secondary');
      save.type='button';
      save.addEventListener('click',async()=>{
        if(saving)return;
        const result=select.value;
        const observation=note.value.trim();
        if(result!=='untested'&&observation.length<12){
          status.textContent='Describe what you actually saw or heard (at least 12 characters).';
          note.focus();return;
        }
        if(!window.confirm('Record this as your own device-test observation? This does not independently certify network or production acceptance.'))return;
        saving=true;save.disabled=true;refreshButton.disabled=true;
        status.textContent='Saving the operator observation…';
        try{
          const resultData=await request('acceptanceMark',{roomId:snapshot.room.id,step:key,
            result,revision:Number(saved.revision)||0,note:observation});
          status.textContent='Saved your observation. No live cameras, recordings, or Program controls were changed.';
          // Refresh the record to show current revision and other operator changes.
          await load({rerender:true});
        }catch(e){status.textContent=e.message||'Acceptance update failed. Refresh before retrying.';}
        finally{saving=false;save.disabled=false;refreshButton.disabled=false;}
      });
    }
  }
  async function load({rerender=true}={}){
    if(loading||saving&&!rerender)return;
    loading=true;refreshButton.disabled=true;
    try{
      // Follow the newest owned show, not the previous room's cached ID.
      // A new show must automatically receive its own test checklist and
      // transport telemetry even if the previous session ended moments ago.
      const data=await request('acceptanceStatus');
      const priorRoom=lastSnapshot?.room?.id||null;
      const roomChanged=(data.room?.id||null)!==priorRoom;
      currentRoom=data.room?.id||null;
      lastSnapshot=data;
      renderSummary(data);renderDiagnostics(data);renderTriage(data);renderSupport(data);
      if(rerender||roomChanged){renderChecks(data);renderHistory(data.history||[]);}
      status.textContent=data.room?.status==='live'
        ?'Live measurements refreshed. Operator checks are separate from transport telemetry.'
        :data.room?'This session has ended. You can review saved observations for seven days after the broadcast starts.'
          :'No owned broadcast session yet. Start a show, then refresh this tab.';
    }catch(error){
      status.textContent=error?.message||'Unable to load broadcast acceptance.';
    }finally{loading=false;refreshButton.disabled=false;}
  }
  refreshButton.addEventListener('click',()=>void load({rerender:true}));
  document.getElementById('live-tab-acceptance')?.addEventListener('click',()=>void load({rerender:true}));
  // Poll only while this tab is visible. Never overwrite fields the operator is editing.
  refreshTimer=setInterval(()=>{if(panelVisible()&&!loading&&!saving)void load({rerender:false});},15000);
  window.addEventListener('pagehide',()=>{clearInterval(refreshTimer);});
}
