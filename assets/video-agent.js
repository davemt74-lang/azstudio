/** Video specialist editing proposals. The server owns permissions and revisions. */
export function videoAgentDuration(ms){return `${(Math.max(0,Number(ms)||0)/1000).toFixed(1)}s`;}
export function videoAgentCanApply(plan,revision){return plan?.status==='proposed'&&plan.projectRevision===revision;}
export function videoAgentCanRevert(plan,revision){return plan?.status==='applied'&&plan.appliedRevision===revision;}
function mount(){
  const root=document.querySelector('.personal-timeline');
  if(!root||!document.getElementById('vagent-pane'))return;
  const $=id=>document.getElementById(id),make=(tag,label,cls='')=>{const n=document.createElement(tag);if(cls)n.className=cls;n.textContent=label;return n;};
  let plan=null,plans=[],notes=[],busy=false,mediaId='',projectId='',revision=0,seq=0;
  const status=(s,error=false)=>{$('vagent-status').textContent=s;$('vagent-status').style.color=error?'#ff9ebd':'#c9edf7';};
  async function api(action,body=null,params={}){
    const url=new URL('video-agent-api.php',document.baseURI);url.searchParams.set('action',action);
    for(const [k,v] of Object.entries(params))url.searchParams.set(k,String(v));
    const opts={credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}};
    if(body!==null){opts.method='POST';opts.headers['Content-Type']='application/json';opts.headers['X-CSRF-Token']=root.dataset.csrf;opts.body=JSON.stringify(body);}
    const res=await fetch(url,opts);const data=await res.json().catch(()=>null);
    if(!res.ok||!data?.ok)throw Error(data?.error?.message||`Video Agent request failed (${res.status})`);
    return data.data;
  }
  async function run(task){if(busy)return;busy=true;for(const name of ['vagent-generate','vagent-approve','vagent-discard','vagent-revert','vagent-note-add'])$(name).disabled=true;
    try{await task();}catch(e){status(e.message,true);}finally{busy=false;for(const name of ['vagent-generate','vagent-approve','vagent-discard','vagent-revert','vagent-note-add'])$(name).disabled=false;render();}}
  function render(){
    $('vagent-generate').disabled=busy||!projectId;
    $('vagent-note-add').disabled=busy||!mediaId;
    const noteTarget=$('vagent-note-list');noteTarget.replaceChildren();
    const listed=notes.filter(n=>n.mediaId===mediaId);
    if(!mediaId)noteTarget.append(make('p','Select a timeline clip to see its private source notes.','vs-hint'));
    else if(!listed.length)noteTarget.append(make('p','No source notes for this clip yet.','vs-hint'));
    for(const n of listed){const card=make('div','','vagent-card');card.append(make('strong',`${n.kind} · ${videoAgentDuration(n.startMs)}–${videoAgentDuration(n.endMs)}`),make('p',n.note));
      const remove=make('button','Remove note','vs-btn');remove.type='button';remove.addEventListener('click',()=>void run(async()=>{await api('removeMoment',{id:n.id});notes=notes.filter(x=>x.id!==n.id);status('Source note removed.');}));card.append(remove);noteTarget.append(card);}
    const history=$('vagent-history');history.replaceChildren();
    if(!projectId)history.append(make('p','Save an edit before generating plans.','vs-hint'));
    else if(!plans.length)history.append(make('p','No saved proposals for this project yet.','vs-hint'));
    for(const p of plans){const card=make('div','','vagent-card');card.append(make('strong',`${p.goal} · ${p.status}`),make('p',`${p.targetSeconds}s target · source revision ${p.projectRevision} · ${p.proposal?.selectedCount||0} clips`));
      const open=make('button','Review plan','vs-btn');open.type='button';open.addEventListener('click',()=>void run(async()=>{plan=await api('plan',null,{id:p.id});status('Loaded saved proposal for review.');}));card.append(open);history.append(card);}
    const preview=$('vagent-preview');preview.hidden=!plan;if(!plan)return;
    const item=plan.proposal||{};$('vagent-preview-title').textContent=`${plan.goal.toUpperCase()} · ${plan.status}`;
    $('vagent-preview-detail').textContent=`${item.selectedCount||0} of ${item.sourceCount||0} clips · ${videoAgentDuration(item.durationMs)}. ${item.basis||''}`;
    const seq=$('vagent-preview-clips');seq.replaceChildren();for(const clip of (item.timeline?.clips||[]))seq.append(make('li',`${videoAgentDuration(clip.trimStartMs)}–${videoAgentDuration(clip.trimEndMs)} · Clip ${String(clip.id).slice(0,6)}`));
    const warns=$('vagent-warnings');warns.replaceChildren();for(const msg of item.warnings||[])warns.append(make('p',msg));
    $('vagent-approve').hidden=!videoAgentCanApply(plan,revision);
    $('vagent-discard').hidden=plan.status!=='proposed';
    $('vagent-revert').hidden=!videoAgentCanRevert(plan,revision);
    if(plan.status==='proposed'&&!videoAgentCanApply(plan,revision))warns.append(make('p','This proposal is for a different project revision. Reload or generate a new plan.'));
  }
  async function load(){const current=++seq;
    if(!projectId){plans=[];notes=[];plan=null;render();return;}
    try{const data=await api('state',null,{projectId});if(current!==seq)return;plans=data.plans||[];notes=data.moments||[];
      if(plan&&!plans.some(x=>x.id===plan.id))plan=null;
      if(!plan&&plans.length){
        const requested=new URL(location.href).searchParams.get('planId');
        const first=plans.find(p=>p.id===requested)||plans.find(p=>p.status==='proposed')||plans[0];
        plan=await api('plan',null,{id:first.id});if(current!==seq)return;
      }
      render();}
    catch(e){if(current===seq)status(e.message,true);}
  }
  function selected(){mediaId=root.dataset.videoMediaId||'';$('vagent-note-source').textContent=mediaId?`Selected source ${mediaId.slice(0,8)} · enter original source seconds`:'Select a video clip in V1 before adding a note.';
    if(mediaId){$('vagent-note-start').value=(Number(root.dataset.videoStartMs||0)/1000).toFixed(1);$('vagent-note-end').value=(Number(root.dataset.videoEndMs||0)/1000).toFixed(1);}
    render();}
  window.addEventListener('azh:video-editor-clip',selected);
  window.addEventListener('azh:video-editor-project',event=>{
    const next=event.detail?.projectId||'',rev=event.detail?.revision||0;
    if(next!==projectId){projectId=next;revision=rev;plan=null;void load();}else{revision=rev;render();}
    status(next?'Saved timeline ready for proposals.':'Save the current timeline before generating a proposal.');
  });
  $('vagent-generate').addEventListener('click',()=>void run(async()=>{
    if(!projectId)throw Error('Save your edit first.');
    if(root.dataset.videoDirty==='true')throw Error('Save your unsaved timeline changes first.');
    const created=await api('generate',{projectId,revision,targetSeconds:Number($('vagent-target').value),goal:$('vagent-goal').value,useCaptions:$('vagent-use-captions').checked});
    plan=created;await load();status('Proposed cut saved. Review all clip ranges before approval.');
  }));
  $('vagent-note-add').addEventListener('click',()=>void run(async()=>{
    if(!mediaId)throw Error('Select a V1 clip first.');
    const note=await api('addMoment',{mediaId,kind:$('vagent-note-kind').value,startMs:Math.round(Number($('vagent-note-start').value)*1000),endMs:Math.round(Number($('vagent-note-end').value)*1000),note:$('vagent-note-text').value.trim()});
    notes.push(note);$('vagent-note-text').value='';status('Private time-stamped source note saved.');
  }));
  $('vagent-approve').addEventListener('click',()=>void run(async()=>{
    if(!videoAgentCanApply(plan,revision))throw Error('Editing revision changed. Reload this proposal.');
    if(root.dataset.videoDirty==='true')throw Error('Save or discard your current unsaved edits first.');
    if(!window.confirm('Approve this exact cut? A new private edit revision will be saved. The original footage remains unchanged.'))return;
    const result=await api('apply',{planId:plan.id,approve:true});plan=result.plan;revision=result.project.revision;
    window.dispatchEvent(new CustomEvent('azh:video-agent-saved',{detail:{projectId}}));await load();status('Approved cut saved. Review the new timeline, audio and final render before submitting.');
  }));
  $('vagent-discard').addEventListener('click',()=>void run(async()=>{if(!plan||!window.confirm('Discard this proposal? Saved footage and timeline remain unchanged.'))return;plan=await api('discard',{planId:plan.id});await load();status('Proposal discarded.');}));
  $('vagent-revert').addEventListener('click',()=>void run(async()=>{
    if(!videoAgentCanRevert(plan,revision))throw Error('Newer edits exist; reverting automatically would overwrite them.');
    if(root.dataset.videoDirty==='true')throw Error('Save or discard unsaved editor changes first.');
    if(!window.confirm('Restore the original timeline for this plan? This creates another saved revision.'))return;
    const result=await api('revert',{planId:plan.id});plan=result.plan;revision=result.project.revision;
    window.dispatchEvent(new CustomEvent('azh:video-agent-saved',{detail:{projectId}}));await load();status('Previous timeline restored as a new private revision.');
  }));
  projectId=root.dataset.videoProjectId||'';revision=Number(root.dataset.videoRevision||0);selected();void load();
}
if(typeof document!=='undefined')mount();
