/** AZHottie activity drawer: read-only insights plus explicit, permission-checked Work actions. */
export function badgeLabel(count) {
  const value=Math.max(0,Number.parseInt(count,10)||0);
  return value>99?'99+':value?String(value):'';
}
export function safeAgentHref(href) {
  // Keep all activity and suggestion destinations on known, same-site workspace pages.
  return typeof href==='string' && /^(?:my-videos|my-work|user-submissions|productions|admin-studio|agent-chat|casting|account|live-director)\.php(?:\?[A-Za-z0-9_=%&.-]{1,120})?$/.test(href)?href:null;
}
export function validWorkAction(action) {
  if(!action || typeof action!=='object')return false;
  if(action.type==='respond')return /^[a-f0-9]{32}$/i.test(action.id||'')&&Number.isSafeInteger(action.revision)&&action.revision>0;
  if(action.type==='claim')return /^[a-f0-9]{32}$/i.test(action.id||'');
  if(action.type==='remind')return /^[a-f0-9]{32}$/i.test(action.id||'')&&Number.isSafeInteger(action.revision)&&action.revision>0;
  if(action.type==='casting_stage')return /^[a-f0-9]{32}$/i.test(action.id||'')&&Number.isSafeInteger(action.revision)&&action.revision>0&&['contacted','screening','interview'].includes(action.next);
  return false;
}

function mountAgentWorkspace(root=document) {
  const bell=root.getElementById('azh-aw-bell'),drawer=root.getElementById('azh-aw-drawer'),shade=root.getElementById('azh-aw-shade');
  if(!bell||!drawer||!shade)return;
  const closeButton=root.getElementById('azh-aw-close');
  const content=root.getElementById('azh-aw-content'),status=root.getElementById('azh-aw-updated'),badge=root.getElementById('azh-aw-badge');
  const refresh=root.getElementById('azh-aw-refresh'),markAll=root.getElementById('azh-aw-read-all');
  const tabButtons=Array.from(drawer.querySelectorAll('[data-aw-tab]'));
  let active='notifications',state=null,loading=false,open=false,previousFocus=null,stamp=0;
  const text=(tag,value,cls='')=>{const el=root.createElement(tag);if(cls)el.className=cls;el.textContent=String(value??'');return el;};
  const make=(tag,cls='')=>{const el=root.createElement(tag);el.className=cls;return el;};
  function link(label,href,cls='azh-aw-link'){
    const url=safeAgentHref(href);if(!url)return text('span',label,'azh-aw-meta');
    const a=make('a',cls);a.href=url;a.textContent=label;return a;
  }
  function button(label,cls='',onClick=null){const b=make('button',cls);b.type='button';b.textContent=label;if(onClick)b.addEventListener('click',onClick);return b;}
  function empty(message,detail){const el=make('div','azh-aw-empty');el.append(text('span','✳','azh-aw-empty-symbol'),text('strong',message),text('p',detail));return el;}
  function setStatus(message,error=false){status.textContent=message;status.dataset.error=error?'true':'false';}
  function updateBadge(count){const label=badgeLabel(count);badge.hidden=!label;badge.textContent=label;bell.setAttribute('aria-label',label?`${count} unread agent notifications`:'Open agent notifications');}
  async function request(endpoint,action,body=null){
    const url=new URL(endpoint,document.baseURI);url.searchParams.set('action',action);
    const opts={credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}};
    if(body!==null){opts.method='POST';opts.headers['Content-Type']='application/json';opts.headers['X-CSRF-Token']=drawer.dataset.csrf||'';opts.body=JSON.stringify(body);}
    const res=await fetch(url,opts);let data;
    try{data=await res.json();}catch{throw new Error('The agent could not load this activity. Please retry.');}
    if(!res.ok||!data.ok)throw new Error(data.error?.message||`Request failed (${res.status}).`);
    return data.data;
  }
  async function badgeOnly(){if(open)return;try{const data=await request('agent-workspace-api.php','badge');updateBadge(data.unreadCount);}catch{/* Keep the workspace usable even when the notifications endpoint is unavailable. */}}
  function renderEvent(event,showRead=false){
    const card=make('article','azh-aw-event'+(event.readAt?' is-read':' is-unread'));
    const top=make('div','azh-aw-event-top');top.append(text('strong',event.title));
    const when=Date.parse(event.createdAt);if(Number.isFinite(when))top.append(text('time',new Intl.DateTimeFormat('en-US',{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}).format(new Date(when)),'azh-aw-meta'));
    card.append(top,text('p',event.detail,'azh-aw-event-detail'));
    if(event.actor && event.actor!=='You')card.append(text('small',`By ${event.actor}`,'azh-aw-meta'));
    const actions=make('div','azh-aw-event-actions');actions.append(link('View item ↗',event.href));
    if(!event.readAt && showRead!==true)actions.append(button('Mark read','azh-aw-tertiary',()=>void markItems([event.id])));
    card.append(actions);return card;
  }
  function renderNotifications(){
    const rows=state?.notifications||[];
    if(!rows.length){content.append(empty('All caught up','New submissions, assignment changes and admin decisions will appear here.'));return;}
    const intro=text('p',`${state.unreadCount} unread update${state.unreadCount===1?'':'s'}`,'azh-aw-section-summary');content.append(intro);
    rows.forEach(row=>content.append(renderEvent(row)));
    if(state.unreadCount>rows.length)content.append(text('p','More unread activity is available in History.','azh-aw-section-summary'));
  }
  function renderHistory(){
    const rows=state?.history||[];
    content.append(text('p','Verified activity since the new history tracker was enabled. Earlier work remains in Productions and My Videos.','azh-aw-section-summary'));
    if(!rows.length){content.append(empty('No activity recorded yet','When a model submits a video or assignments change, the history will keep a record.'));return;}
    for(const row of rows)content.append(renderEvent(row,true));
  }
  function workActionControls(card,action){
    if(!validWorkAction(action))return;
    const actions=make('div','azh-aw-brain-actions');
    if(action.type==='respond'){
      actions.append(button('Accept assignment','azh-aw-primary',()=>void executeWork('respond',{assignmentId:action.id,revision:action.revision,decision:'accept',note:''})),
        button('Decline','azh-aw-outline',()=>{if(window.confirm('Decline this assignment? The production team will be notified.'))void executeWork('respond',{assignmentId:action.id,revision:action.revision,decision:'decline',note:''});}));
    }else if(action.type==='claim')actions.append(button('Claim shot','azh-aw-primary',()=>{if(window.confirm('Claim this shot for yourself?'))void executeWork('claim',{shotId:action.id});}));
    else if(action.type==='remind' && state?.role==='admin')actions.append(button('Send reminder','azh-aw-primary',()=>{if(window.confirm('Send this model an in-app assignment reminder? Reminders are limited to once per 12 hours.'))void executeAgentAction('remindAssignment',{assignmentId:action.id,revision:action.revision});}));
    else if(action.type==='casting_stage' && state?.role==='admin')actions.append(button('Move to '+action.next,'azh-aw-primary',()=>{if(window.confirm('Confirm moving this casting application to '+action.next+'? Read the application first; this does not approve or invite the applicant.'))void executeAgentAction('castingAdvance',{leadId:action.id,revision:action.revision,nextStage:action.next});}));
    card.append(actions);
  }
  function renderAssignForm(options){
    if(!Array.isArray(options)||!options.length)return;
    const section=make('section','azh-aw-assign');section.append(text('p','ASSIGNMENTS · ADMIN','azh-aw-eyebrow'),text('h3','Assign a model to a shot'));
    section.append(text('p','Only active shots in your productions and eligible roster members are listed.','azh-aw-meta'));
    const form=make('form','azh-aw-assign-form');
    const shotLabel=make('label');shotLabel.append(text('span','Production shot'));
    const shotSelect=make('select');shotSelect.name='shot';shotSelect.required=true;
    const modelLabel=make('label');modelLabel.append(text('span','Roster member'));
    const modelSelect=make('select');modelSelect.name='model';modelSelect.required=true;
    for(const shot of options){const opt=make('option');opt.value=shot.shotId;opt.textContent=shot.label;shotSelect.append(opt);}
    const syncModels=()=>{modelSelect.replaceChildren();const row=options.find(item=>item.shotId===shotSelect.value);for(const m of (row?.models||[])){const opt=make('option');opt.value=String(m.id);opt.textContent=m.name;modelSelect.append(opt);}};
    shotSelect.addEventListener('change',syncModels);syncModels();shotLabel.append(shotSelect);modelLabel.append(modelSelect);
    form.append(shotLabel,modelLabel);
    const submit=make('button','azh-aw-primary');submit.type='submit';submit.textContent='Assign selected model';form.append(submit);
    form.addEventListener('submit',event=>{event.preventDefault();const shot=options.find(row=>row.shotId===shotSelect.value),member=shot?.models?.find(row=>String(row.id)===modelSelect.value);if(!member)return;
      if(window.confirm(`Assign “${shot.label}” to ${member.name}? This will notify the model.`))void executeWork('assign',{shotId:shot.shotId,userId:member.id});});
    section.append(form);content.append(section);
  }
  function renderBrain(){
    const brain=state?.brain||{};
    content.append(text('p',brain.basis||'Suggestions are based on verified workspace data.','azh-aw-section-summary'));
    const summary=brain.summary||{};
    if(Object.keys(summary).length){const wrap=make('div','azh-aw-counts');const labels={pendingReviews:'Pending reviews',submittedAssignments:'Submitted work',awaitingResponses:'Awaiting response',awaitingResponse:'Needs response',overdue:'Overdue',videosUnderReview:'Videos under review',availableShots:'Open shots',liveAlerts:'Live alerts',newApplications:'New casting applications',screening:'Screenings',interviews:'Interviews',approved:'Approved applications',converted:'Converted models',onboardingPending:'Onboarding needs review',onboardingComplete:'Onboarding completed',onboardingTotal:'Onboarding steps'};
      for(const [key,num] of Object.entries(summary)){const item=make('div');item.append(text('b',num),text('span',labels[key]||key));wrap.append(item);}content.append(wrap);}
    if(!brain.cards?.length)content.append(empty('Nothing urgent to review','Your current assignments and submissions are up to date.'));
    for(const item of brain.cards||[]){const card=make('article','azh-aw-brain-card');card.append(text('p',String(item.kind||'update').replaceAll('_',' ').toUpperCase(),'azh-aw-eyebrow'),text('h3',item.title),text('p',item.detail));card.append(link('Open workspace ↗',item.href));workActionControls(card,item.action);content.append(card);}
    // Casting is one specialist feeding this shared Brain, not a separate assistant.
    const casting=brain.casting||{};
    if(casting.report){
      const report=make('section','azh-aw-casting-report');
      report.append(text('p','CASTING REPORT · VERIFIED WORKFLOW','azh-aw-eyebrow'));
      for(const [name,label] of [['keyFindings','Key findings'],['actionsFollowups','Actions & follow-ups'],['openQuestions','Open questions'],['nextStep','Next step'],['questionsForYou','Questions for you']]){
        const value=casting.report[name];
        if(!value || (Array.isArray(value)&&value.length===0))continue;
        const block=make('div');block.append(text('strong',label));
        for(const item of (Array.isArray(value)?value:[value]))block.append(text('p',item));
        report.append(block);
      }
      content.append(report);
    }
    if(state.role==='admin'){
      const section=make('section','azh-aw-followthrough');
      section.append(text('p','CASTING & ONBOARDING SPECIALIST','azh-aw-eyebrow'),text('h3','Applications and model readiness'));
      const scan=casting.scan||{};
      section.append(text('p',scan.completedAt?`Last scheduled casting check: ${new Date(scan.completedAt).toLocaleString()} · ${scan.delivered} new alert(s).`:'Casting reminders have not yet run on the server. Configure the existing private hourly cron.','azh-aw-meta'));
      section.append(button('Check casting now','azh-aw-primary',()=>{if(window.confirm('Run a casting follow-up check? This may send deduplicated in-app reminders to administrators and models, but will not contact applicants or change casting decisions.'))void executeAgentAction('checkCasting',{});}));
      section.append(link('Manage casting pipeline ↗','casting.php'),link('Review onboarding ↗','casting.php?tab=onboarding'),link('Discuss with Agent Chat ↗','agent-chat.php?topic=casting'));
      for(const model of (casting.onboardingModels||[]).slice(0,4)){
        const panel=make('article','azh-aw-brain-card');
        panel.append(text('h3',model.label),text('p',`${model.complete}/${model.total} onboarding steps complete.`));
        for(const step of model.items||[]){
          const line=make('div','azh-aw-casting-check');line.append(text('span',(step.done?'✓ ':'○ ')+step.label));
          if(step.canConfirm){const next=!step.done;line.append(button(next?'Confirm':'Reopen','azh-aw-outline',()=>{
            if(window.confirm(`${next?'Confirm':'Reopen'} “${step.label}” for ${model.label}?`))void executeAgentAction('castingCheckpoint',{modelId:model.modelId,checkpoint:step.key,complete:next});
          }));}panel.append(line);
        }section.append(panel);
      }
      for(const match of (casting.matches||[]).slice(0,3)){
        const line=make('p','azh-aw-meta');line.textContent=`Production match to review: ${match.shot} · ${match.model}. ${match.reason}`;section.append(line);
      }
      content.append(section);
    }else if(casting.onboarding){
      const progress=casting.onboarding,section=make('section','azh-aw-followthrough');
      section.append(text('p','MODEL ONBOARDING','azh-aw-eyebrow'),text('h3',`${progress.complete}/${progress.total} steps complete`));
      for(const step of progress.items||[])section.append(text('p',(step.done?'✓ ':'○ ')+step.label,'azh-aw-meta'));
      section.append(link('Edit my production profile ↗','account.php'),link('Ask Agent about onboarding ↗','agent-chat.php?topic=casting'));
      content.append(section);
    }
    if(state.role==='admin'){
      const live=brain.liveHealth||{state:'unverified'},metrics=live.director||{},fan=live.viewers||{};
      const health=make('section','azh-aw-live-health');
      health.append(text('p','LIVE DIRECTOR SPECIALIST','azh-aw-eyebrow'),text('h3',live.room?.title||'Live program health'));
      const names={off_air:'Off air · no active stream',unverified:'Unverified · waiting for fresh Director telemetry',attention:'Attention required',observed:'Receiving current health measurements'};
      health.append(text('p',names[live.state]||'Health data not available','azh-aw-meta'));
      if(live.room){
        if(live.room.youAreDirectorOwner===false)health.append(text('p','You are viewing this show as an administrator. Only the originating Director can change its video feed.','azh-aw-meta'));
        const summary=make('div','azh-aw-live-grid');
        for(const [label,value] of [['Healthy phones',metrics.healthyCameras===null?'—':`${metrics.healthyCameras}/${metrics.cameraCount}`],['Program sent FPS',metrics.sentFps===null?'Not measured':String(metrics.sentFps)],['Fan sessions reporting',fan.reporting??0],['Direct relays',metrics.directRelays??'—']]){
          const cell=make('div');cell.append(text('b',value),text('span',label));summary.append(cell);
        }health.append(summary);
        for(const issue of live.issues||[]){const panel=make('div','azh-aw-live-issue');panel.append(text('strong',issue.title),text('p',issue.advice));health.append(panel);}
        for(const note of live.notes||[])health.append(text('p',note,'azh-aw-meta'));
        health.append(link('Open Live Director ↗','live-director.php'),button('Recheck live health','azh-aw-outline',()=>void load()));
      }
      content.append(health);
      const follow=brain.followthrough||{},cron=follow.cron||{},manual=follow.manual||{};
      const section=make('section','azh-aw-followthrough');
      section.append(text('p','ASSIGNMENT FOLLOW-THROUGH','azh-aw-eyebrow'),text('h3','Proactive checks'));
      section.append(text('p',cron.completedAt?`Last scheduled scan: ${new Date(cron.completedAt).toLocaleString()} · ${cron.delivered} new alert(s).`:'Scheduled scans have not run yet. Configure the private hourly server cron to enable proactive in-app reminders.','azh-aw-meta'));
      if(manual.completedAt)section.append(text('p',`Last manual check: ${new Date(manual.completedAt).toLocaleString()}${manual.truncated?' · Scan limit reached':''}.`,'azh-aw-meta'));
      section.append(button('Check assignments now','azh-aw-primary',()=>void executeAgentAction('checkFollowthrough',{})));
      content.append(section);
      renderAssignForm(brain.assignOptions||[]);
    }
    const hint=make('div','azh-aw-brain-hint');hint.append(text('strong','What the Brain does'),text('p','It reads verified assignments, casting and onboarding workflows, reviews, and browser-reported live health. The private hourly cron can send deduplicated assignment reminders when installed. It does not independently change assignments, contact users outside AZHottie, publish videos, or inspect private footage.'));content.append(hint);
  }
  function render(){
    content.replaceChildren();content.setAttribute('aria-labelledby',`azh-aw-tab-${active}`);
    for(const tab of tabButtons){const selected=tab.dataset.awTab===active;tab.classList.toggle('is-active',selected);tab.setAttribute('aria-selected',String(selected));tab.tabIndex=selected?0:-1;}
    markAll.hidden=!state?.unreadCount||active==='brain';
    if(!state){content.append(empty('Agent data unavailable','Refresh to retry.'));return;}
    if(active==='notifications')renderNotifications();else if(active==='history')renderHistory();else renderBrain();
  }
  async function load(){
    if(loading)return;
    loading=true;refresh.disabled=true;setStatus('Checking actual workspace records…');
    try{state=await request('agent-workspace-api.php','state');updateBadge(state.unreadCount);render();setStatus('Updated just now');}
    catch(error){setStatus(error.message,true);if(!state){content.replaceChildren();content.append(empty('Could not load activity',error.message));}}
    finally{loading=false;refresh.disabled=false;}
  }
  async function markItems(ids=null){
    if(loading)return;
    loading=true;markAll.disabled=true;
    try{state=await request('agent-workspace-api.php','markRead',ids?{ids}:{all:true});updateBadge(state.unreadCount);render();setStatus('Read status saved');}
    catch(error){setStatus(error.message,true);}finally{loading=false;markAll.disabled=false;}
  }
  async function executeAgentAction(action,body){
    if(loading)return;
    loading=true;setStatus(action==='checkFollowthrough'?'Checking current assignments…':action==='checkCasting'?'Checking casting records…':'Saving confirmed action…');
    let outcome=null,error=null;
    try{outcome=await request('agent-workspace-api.php',action,body);}
    catch(e){error=e;}
    finally{loading=false;await load();}
    if(error)setStatus(error.message,true);
    else if(action==='checkFollowthrough'||action==='checkCasting')setStatus(`Checked ${outcome.checked} ${action==='checkCasting'?'applications':'assignments'} · ${outcome.delivered} new notifications${outcome.truncated?' · scan limit reached':''}.`);
    else setStatus(action==='castingAdvance'?'Casting stage updated':action==='castingCheckpoint'?'Onboarding checkpoint updated':'Reminder delivered to model notifications');
  }
  async function executeWork(action,body){
    if(loading)return;loading=true;setStatus('Saving your confirmed assignment action…');
    let actionError=null;
    try{await request('work-api.php',action,body);}
    catch(error){actionError=error;}
    finally{
      loading=false;await load();
      if(actionError)setStatus(actionError.message,true);
      else if(status.dataset.error!=='true')setStatus('Assignment updated');
    }
  }
  function show(){
    if(open)return;open=true;previousFocus=root.activeElement;
    shade.hidden=false;drawer.hidden=false;drawer.setAttribute('aria-hidden','false');bell.setAttribute('aria-expanded','true');root.body.classList.add('azh-aw-open');
    drawer.focus();if(!state || Date.now()-stamp>15000){stamp=Date.now();void load();}else render();
  }
  function hide(){
    if(!open)return;open=false;shade.hidden=true;drawer.hidden=true;drawer.setAttribute('aria-hidden','true');bell.setAttribute('aria-expanded','false');root.body.classList.remove('azh-aw-open');
    (previousFocus instanceof HTMLElement?previousFocus:bell).focus();
  }
  bell.addEventListener('click',()=>open?hide():show());closeButton.addEventListener('click',hide);shade.addEventListener('click',hide);
  tabButtons.forEach(tab=>tab.addEventListener('click',()=>{active=tab.dataset.awTab;render();}));
  refresh.addEventListener('click',()=>void load());markAll.addEventListener('click',()=>void markItems());
  root.addEventListener('keydown',event=>{
    if(!open)return;
    if(event.key==='Escape'){event.preventDefault();hide();return;}
    if(event.key!=='Tab')return;
    const focusable=Array.from(drawer.querySelectorAll('button:not([disabled]):not([hidden]),a[href],select')).filter(el=>el.offsetParent!==null);
    if(!focusable.length)return;
    const first=focusable[0],last=focusable[focusable.length-1];
    if(event.shiftKey&&root.activeElement===first){event.preventDefault();last.focus();}else if(!event.shiftKey&&root.activeElement===last){event.preventDefault();first.focus();}
  });
  root.addEventListener('visibilitychange',()=>{if(root.visibilityState==='visible')void (open?load():badgeOnly());});
  window.setInterval(()=>{if(root.visibilityState==='visible')void (open?load():badgeOnly());},60000);
  void badgeOnly();
}
if(typeof document!=='undefined')mountAgentWorkspace(document);
