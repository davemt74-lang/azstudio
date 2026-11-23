/* v1.43 Action Center: one-page staff task management using existing Agent API.
 * Read-only GET on load; mutations require explicit button clicks, CSRF and revisions.
 */
const root=document.getElementById('azd-action-center');
if(root){
  const list=root.querySelector('[data-actions-list]');
  const summary=root.querySelector('[data-actions-counts]');
  const status=root.querySelector('[data-actions-status]');
  const refresh=root.querySelector('[data-actions-refresh]');
  const sync=root.querySelector('[data-actions-sync]');
  const filter=root.querySelector('[data-actions-filter]');
  let payload={tasks:[],staff:[],history:{},counts:{}};
  let busy=false;
  function node(tag,value='',cls=''){
    const el=document.createElement(tag);
    if(cls)el.className=cls;
    el.textContent=String(value??'');
    return el;
  }
  function add(parent,tag,value='',cls=''){const el=node(tag,value,cls);parent.append(el);return el;}
  function isoLocal(iso){
    const d=new Date(iso);
    if(Number.isNaN(d.getTime()))return '';
    const date=new Date(d.getTime()-d.getTimezoneOffset()*60000);
    return date.toISOString().slice(0,16);
  }
  function relativeDate(iso){
    const d=new Date(iso);
    return Number.isNaN(d.getTime())?'Date unknown':new Intl.DateTimeFormat(undefined,
      {year:'numeric',month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}).format(d);
  }
  function safeHref(value){
    return typeof value==='string'&&/^(?:casting\.php\?lead=[a-f0-9]{32}|casting\.php\?status=new|user-submissions\.php|commerce\.php\?tab=(?:providers|orders|payouts))$/.test(value)?value:null;
  }
  async function request(action,body=null){
    const url=new URL('agent-workspace-api.php',document.baseURI);url.searchParams.set('action',action);
    const config={method:body===null?'GET':'POST',credentials:'same-origin',cache:'no-store',
      headers:{Accept:'application/json'}};
    if(body!==null){
      config.headers['Content-Type']='application/json';
      config.headers['X-CSRF-Token']=document.getElementById('azh-admin-command')?.dataset.csrf||'';
      config.body=JSON.stringify(body);
    }
    const res=await fetch(url,config);
    const json=await res.json().catch(()=>null);
    if(!res.ok||!json?.ok)throw new Error(json?.error?.message||'Action Center request failed.');
    return json.data;
  }
  function setBusy(value){busy=value;refresh.disabled=value;sync.disabled=value;
    root.querySelectorAll('[data-task-submit],[data-action-track]').forEach(b=>b.disabled=value);
  }
  function renderCounts(){
    summary.replaceChildren();
    for(const [key,label] of [['open','Open'],['acknowledged','Acknowledged'],['overdue','Past due'],['resolved','Resolved']]){
      const item=add(summary,'div','','azd-action-count');
      add(item,'strong',Number(payload.counts?.[key]||0));
      add(item,'span',label);
    }
  }
  function render(){
    renderCounts();list.replaceChildren();
    const visible=(payload.tasks||[]).filter(t=>filter.value==='all'||
      (filter.value==='active'?t.state!=='resolved':t.state===filter.value));
    if(!visible.length){
      add(list,'p',filter.value==='resolved'?'No resolved tasks yet.':'No tracked tasks for this filter. Select Track on a current management recommendation, or run Check open issues.','azd-empty');
      return;
    }
    for(const task of visible){
      const card=add(list,'article','','azd-action-task');
      const head=add(card,'div','','azd-action-task-head');
      const heading=add(head,'div','','azd-action-task-title');
      add(heading,'strong',task.title);
      add(heading,'small',task.detail);
      const badge=add(head,'span',task.state.replaceAll('_',' '),'azd-action-status');
      badge.dataset.state=task.state;
      const meta=add(card,'p','','azd-action-meta');
      add(meta,'span','Owner: '+task.assigneeName);
      add(meta,'span','Due '+relativeDate(task.dueAt));
      if(task.state!=='resolved'&&Date.parse(task.dueAt)<Date.now())
        add(meta,'strong','Past due','azd-action-pastdue');
      const source=safeHref(task.href);
      if(source){const link=add(card,'a','Review original workflow ↗','azd-action-link');link.href=source;}
      const controls=add(card,'div','','azd-action-controls');
      const ownerWrap=add(controls,'label','Owner','azd-action-label');
      const ownerSel=add(ownerWrap,'select','','azd-action-select');
      ownerSel.setAttribute('aria-label','Task owner for '+task.title);
      for(const staff of payload.staff||[]){
        const option=add(ownerSel,'option',staff.name);
        option.value=String(staff.id);
        option.selected=Number(staff.id)===Number(task.assigneeId);
      }
      if(![...ownerSel.options].some(o=>o.selected)){
        const placeholder=add(ownerSel,'option','Inactive owner — select an active admin');
        placeholder.value='';placeholder.selected=true;
      }
      const dateWrap=add(controls,'label','Due date','azd-action-label');
      const dateInput=add(dateWrap,'input','','azd-action-date');
      dateInput.type='datetime-local';dateInput.value=isoLocal(task.dueAt);
      dateInput.setAttribute('aria-label','Due date for '+task.title);
      const save=add(controls,'button','Save owner & due date','azd-action-button azd-action-save');
      save.type='button';save.dataset.taskSubmit='1';
      if(task.state==='resolved'){ownerSel.disabled=true;dateInput.disabled=true;save.disabled=true;}
      const actions=add(card,'div','','azd-action-buttons');
      const note=add(card,'textarea','','azd-action-note');
      note.rows=2;note.maxLength=500;
      note.placeholder='Resolution note (10+ characters) or optional update note';
      note.setAttribute('aria-label','Task audit note for '+task.title);
      const run=async(operation,body={})=>{
        if(busy)return;
        if(operation==='resolve'||operation==='reopen'){
          if(note.value.trim().length<10){status.textContent='Enter a reason of at least 10 characters for resolution or reopening.';note.focus();return;}
          if(!window.confirm((operation==='resolve'?'Mark this follow-up resolved':'Reopen this follow-up')+'? This changes only the Action Center task, not the source workflow.'))return;
        }
        setBusy(true);status.textContent='Saving staff follow-up…';
        try{
          await request('actionChange',{id:task.id,revision:task.revision,operation,note:note.value,...body});
          payload=await request('actionCenter');render();
          status.textContent='Action Center task saved. Original casting, publishing and financial records were not changed.';
        }catch(err){status.textContent=err?.message||'Task update failed. Refresh and retry.';}
        finally{setBusy(false);}
      };
      if(task.state!=='resolved'){
        if(task.state==='open'){
          const ack=add(actions,'button','Acknowledge','azd-action-button');
          ack.type='button';ack.dataset.taskSubmit='1';
          ack.addEventListener('click',()=>void run('acknowledge'));
        }
        const done=add(actions,'button','Resolve with note','azd-action-button azd-action-primary');
        done.type='button';done.dataset.taskSubmit='1';
        done.addEventListener('click',()=>void run('resolve'));
        save.addEventListener('click',()=>{
          if(!ownerSel.value||!dateInput.value){status.textContent='Select an active administrator and a due date.';return;}
          const parsed=new Date(dateInput.value);
          if(Number.isNaN(parsed.getTime())){status.textContent='Enter a valid due date.';return;}
          void run('assign',{assigneeId:Number(ownerSel.value),dueAt:parsed.toISOString().replace(/\.\d{3}Z$/,'Z')});
        });
      }else{
        const reopen=add(actions,'button','Reopen with note','azd-action-button');
        reopen.type='button';reopen.dataset.taskSubmit='1';
        reopen.addEventListener('click',()=>void run('reopen'));
      }
      const audit=add(card,'details','','azd-action-history');
      add(audit,'summary','Resolution and assignment history');
      const entries=add(audit,'ol','','azd-action-history-list');
      const events=payload.history?.[task.id]||[];
      if(!events.length)add(entries,'li','No recorded changes.');
      for(const e of events){
        const li=add(entries,'li','','azd-action-history-item');
        add(li,'strong',e.action+' · '+e.actor);
        add(li,'small',relativeDate(e.createdAt)+' · '+e.from+' → '+e.to);
        add(li,'p',e.note);
      }
    }
  }
  async function load(){
    if(busy)return;
    setBusy(true);status.textContent='Loading staff-owned Action Center…';
    try{
      payload=await request('actionCenter');
      render();status.textContent='Current Action Center records loaded. Changes require staff confirmation.';
    }catch(err){list.replaceChildren();status.textContent=err?.message||'Could not load staff Action Center.';}
    finally{setBusy(false);}
  }
  refresh.addEventListener('click',()=>void load());
  filter.addEventListener('change',render);
  sync.addEventListener('click',async()=>{
    if(busy||!window.confirm('Track existing overdue casting and video workflows and check due tasks? This may create in-app Agent reminders, but will not approve applicants, publish content or move money.'))return;
    setBusy(true);status.textContent='Checking source workflow status and due tasks…';
    try{
      const outcome=await request('actionSync',{});
      payload=await request('actionCenter');render();
      status.textContent=Number(outcome.created||0)+' new tasks tracked; '+Number(outcome.reminded||0)+' deduplicated due reminders delivered.'+
        (outcome.truncated?' Additional work remains in the source workspaces.':'');
    }catch(err){status.textContent=err?.message||'Action Center check failed.';}
    finally{setBusy(false);}
  });
  document.querySelectorAll('[data-action-track]').forEach(b=>{
    b.addEventListener('click',async()=>{
      if(busy)return;
      setBusy(true);status.textContent='Tracking current management recommendation…';
      try{
        const outcome=await request('actionTrack',{
          sourceKind:b.dataset.kind,sourceId:b.dataset.sourceId,sourceStage:b.dataset.stage});
        payload=await request('actionCenter');filter.value='active';render();
        status.textContent=outcome.message||'Recommendation tracked.';
      }catch(err){status.textContent=err?.message||'Could not track this recommendation.';}
      finally{setBusy(false);}
    });
  });
  void load();
}
