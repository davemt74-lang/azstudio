const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const requestToken = () => globalThis.crypto?.randomUUID?.().replaceAll('-', '') || Array.from(globalThis.crypto.getRandomValues(new Uint8Array(24)), b => b.toString(16).padStart(2,'0')).join('');

export async function plannerApi(action, payload = null, query = {}, csrf = '') {
  const url = new URL('work-planner-api.php', location.href); url.searchParams.set('action', action);
  for (const [name,value] of Object.entries(query)) url.searchParams.set(name,String(value));
  const options = {credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}};
  if (payload !== null) Object.assign(options,{method:'POST',body:JSON.stringify(payload),headers:{...options.headers,'Content-Type':'application/json','X-CSRF-Token':csrf}});
  let result; const response = await fetch(url,options);
  try { result = await response.json(); } catch { throw new Error('The assistant request could not be read. Use Check saved proposal before generating again.'); }
  if (!response.ok || result.ok === false) { const error = new Error(result.error?.message || 'The assistant could not complete this request.'); error.code=result.error?.code; throw error; }
  return result.data;
}

export function mountWorkPlanner(container, {project, csrf, user, onShotsAdded = () => {}, onError = () => {}, api = plannerApi, canMutate = () => true} = {}) {
  if (!container || !project?.canManage) { if(container)container.hidden=true; return {destroy(){},isBusy:()=>false,isDirty:()=>false}; }
  container.hidden=false;
  const abort = new AbortController(); let destroyed=false,busy=false,dirty=false,settings=null,proposal=null;
  const owner = user?.id || 'admin'; const storageKey=`azhottie-planner-draft:${owner}:${project.id}`;
  const call = (action,payload=null,query={}) => api(action,payload,query,csrf);
  const localRead=()=>{try{return JSON.parse(sessionStorage.getItem(storageKey)||'null');}catch{return null;}};
  const localWrite=data=>{try{data?sessionStorage.setItem(storageKey,JSON.stringify(data)):sessionStorage.removeItem(storageKey);}catch{}};
  let pendingToken=localRead()?.requestToken||'';
  container.innerHTML=`<div class="work-section-heading"><div><p class="eyebrow">OPTIONAL ASSISTANT</p><h2>Start with a shot list.</h2></div><span class="badge" data-planner-status>Loading…</span></div>
    <p class="field-help">Turn your saved brief into an editable proposal. Review the shot instructions, then add them to this project and choose contributors.</p>
    <div data-planner-message class="work-inline-notice" role="status" hidden></div>
    <p class="field-help"><a href="admin.php">Manage OpenAI and Claude in Admin → AI settings</a>. Your shot-planning provider, model and shared daily request limit are configured there. Only this project’s title, brief, existing shot titles and your additional direction are sent to the chosen provider.</p>
    <form data-generate-form class="work-form" style="margin-top:24px"><div class="work-form-grid"><label class="field">Number of shots<input name="shotCount" type="number" min="1" max="12" value="6" required></label><label class="field">Additional direction<input name="direction" maxlength="2000" placeholder="For example: a short portrait product launch"></label></div><div class="work-actions"><button type="submit" class="button button--primary" data-generate disabled>Create a shot list from this brief</button><button type="button" class="button button--quiet" data-refresh>Check saved proposal</button></div><p class="field-help" data-enable-help>Loading assistant settings…</p></form>
    <div data-proposal style="margin-top:24px"></div>`;
  const $=selector=>container.querySelector(selector);
  function message(text,isError=false){if(destroyed)return;const el=$('[data-planner-message]');el.textContent=text;el.hidden=!text;el.setAttribute('role',isError?'alert':'status');}
  function setBusy(value){busy=value;if(destroyed)return;container.setAttribute('aria-busy',String(value));for(const control of container.querySelectorAll('button,input,select,textarea'))control.disabled=value;syncSettings(false);}
  function syncSettings(){
    if(destroyed)return;
    if(!settings){$('[data-generate]').disabled=true;$('[data-planner-status]').textContent=busy?'Loading…':'Settings unavailable';$('[data-enable-help]').textContent=busy?'Loading assistant settings…':'Use Check saved proposal to reload settings before generating.';return;}
    const provider=settings.provider==='anthropic'?'Claude':'OpenAI';
    $('[data-planner-status]').textContent=settings.enabled&&settings.configured?`${provider} · configured`:'Optional · off';
    $('[data-generate]').disabled=busy||!settings.enabled||!settings.configured||!['draft','active'].includes(project.status)||!project.brief?.trim();
    $('[data-enable-help]').textContent=!['draft','active'].includes(project.status)?'Restore this project to plan more shots.':!project.brief?.trim()?'Save a project brief to use the assistant.':settings.enabled&&settings.configured?`${provider} · ${settings.model}. A generated proposal is saved here. Shots are created only when you add the reviewed proposal.`:'You can create shots manually now, or configure the optional assistant in Admin → AI settings.';
  }
  function readShots(){return [...container.querySelectorAll('[data-proposed-shot]')].map(el=>({title:el.querySelector('[name=title]').value,brief:el.querySelector('[name=brief]').value,orientation:el.querySelector('[name=orientation]').value,targetDurationSeconds:Number(el.querySelector('[name=targetDurationSeconds]').value),takesRequested:Number(el.querySelector('[name=takesRequested]').value)}));}
  function rememberDraft(){if(!proposal)return;localWrite({proposalId:proposal.id,shots:readShots(),requestToken:pendingToken});}
  function renderProposal(){
    if(destroyed)return;const target=$('[data-proposal]');
    if(!proposal){target.innerHTML='';return;}
    if(proposal.state==='pending'){target.innerHTML='<p class="work-inline-notice">A shot list is being drafted. Use Check saved proposal to recover it if the request was interrupted.</p>';return;}
    if(proposal.state==='failed'){target.innerHTML=`<p class="work-inline-notice" role="alert">${escapeHtml(proposal.error||'This proposal could not be completed. You can revise the brief or try again.')}</p>`;return;}
    if(proposal.state==='applied'){target.innerHTML=`<p class="work-inline-notice">${proposal.appliedShotIds?.length||proposal.shots.length} reviewed shots added. Assign contributors from the shot list below.</p>`;return;}
    const saved=localRead();const shots=saved?.proposalId===proposal.id&&Array.isArray(saved.shots)&&saved.shots.length?saved.shots:proposal.shots;
    dirty=!!(saved?.proposalId===proposal.id&&saved.shots);
    target.innerHTML=`<form data-apply-form class="work-form"><div><p class="eyebrow">REVIEW YOUR PROPOSAL</p><h3>Make it your production.</h3><p class="field-help">${escapeHtml(proposal.provider==='anthropic'?'Claude':'OpenAI')} · ${escapeHtml(proposal.model||'saved proposal')}</p><p class="field-help">${escapeHtml(proposal.summary)}</p></div><div data-proposed-shots>${shots.map((shot,index)=>`<fieldset data-proposed-shot style="margin-bottom:18px"><legend>Shot ${index+1}</legend><div class="work-form-grid"><label class="field field--wide">Title<input name="title" maxlength="200" required value="${escapeHtml(shot.title)}"></label><label class="field field--wide">Instructions<textarea name="brief" rows="4" maxlength="8000" required>${escapeHtml(shot.brief)}</textarea></label><label class="field">Orientation<select name="orientation">${['portrait','landscape','square','any'].map(o=>`<option${shot.orientation===o?' selected':''}>${o}</option>`).join('')}</select></label><label class="field">Target seconds<input name="targetDurationSeconds" type="number" min="0" max="3600" required value="${Number(shot.targetDurationSeconds)||0}"></label><label class="field">Requested takes<input name="takesRequested" type="number" min="1" max="20" required value="${Number(shot.takesRequested)||1}"></label><div class="work-actions"><button type="button" class="button button--quiet" data-remove-shot>Remove this shot</button></div></div></fieldset>`).join('')}</div><div class="work-actions"><button type="submit" class="button button--primary">Add reviewed shots to project</button></div><p class="field-help">These start as unassigned shots. You can add references, deadlines, collaborators or open participation afterward.</p></form>`;
  }
  async function load(){setBusy(true);try{const [s,p]=await Promise.all([call('settings'),call('latest',null,{projectId:project.id})]);if(destroyed)return;settings=s;proposal=p;if(p&&p.state!=='pending')pendingToken='';syncSettings();renderProposal();}catch(error){message(error.message,true);onError(error);}finally{setBusy(false);}}
  container.addEventListener('input',event=>{if(event.target.closest('[data-apply-form]')){dirty=true;rememberDraft();}},{signal:abort.signal});
  container.addEventListener('click',async event=>{
    const remove=event.target.closest('[data-remove-shot]');if(remove){const shots=container.querySelectorAll('[data-proposed-shot]');if(shots.length<=1){message('Keep at least one shot in the proposal.',true);return;}remove.closest('[data-proposed-shot]').remove();dirty=true;rememberDraft();return;}
    if(!event.target.closest('[data-refresh]')||busy||!canMutate())return;
    if(dirty&&!confirm('Replace your local proposal edits with the saved proposal?'))return;
    if(dirty){dirty=false;localWrite(null);}setBusy(true);try{const [freshSettings,savedProposal]=await Promise.all([call('settings'),call('latest',null,{projectId:project.id})]);if(destroyed)return;settings=freshSettings;proposal=savedProposal;if(proposal?.state!=='pending')pendingToken='';renderProposal();message(proposal?'Saved proposal loaded.':'No proposal has been generated for this project.');}catch(error){message(error.message,true);onError(error);}finally{setBusy(false);}
  },{signal:abort.signal});
  container.addEventListener('submit',async event=>{
    const form=event.target;event.preventDefault();if(busy||!canMutate())return;
    if(form.matches('[data-generate-form]')){
      if(!settings?.enabled||!settings?.configured){message('Configure the assistant in Admin → AI settings, then use Check saved proposal to reload its settings.',true);return;}
      if(dirty&&!confirm('Generate a new proposal and replace your unsaved proposal edits?'))return;
      pendingToken ||= requestToken();localWrite({requestToken:pendingToken});dirty=false;
      setBusy(true);message('Drafting your shot list… The proposal will be saved to this project.');
      try{proposal=await call('generate',{projectId:project.id,shotCount:Number(form.elements.shotCount.value),direction:form.elements.direction.value,requestToken:pendingToken});if(proposal.state!=='pending'){pendingToken='';localWrite(null);}renderProposal();message(proposal.state==='ready'?'Proposal saved. Edit the shots below before adding them.':proposal.state==='failed'?proposal.error:'The proposal is still being prepared.',proposal.state==='failed');}catch(error){if(error.code==='request_conflict'){pendingToken='';localWrite(null);}message(error.message+' Check the saved proposal before starting another request.',true);onError(error);}finally{setBusy(false);}return;
    }
    if(form.matches('[data-apply-form]')&&proposal){
      const shots=readShots();setBusy(true);message('Adding your reviewed shots…');
      try{proposal=await call('apply',{proposalId:proposal.id,shots});dirty=false;localWrite(null);renderProposal();message('Reviewed shots added to the project.');await onShotsAdded();}catch(error){message(error.message,true);onError(error);}finally{setBusy(false);}
    }
  },{signal:abort.signal});
  load();
  return {destroy(){if(dirty)rememberDraft();destroyed=true;abort.abort();},isBusy:()=>busy,isDirty:()=>dirty};
}
