/* AZHottie admin video queue. Never review or publish without authenticated admin APIs. */
const root=document.querySelector('.mv-admin');
if(root){
  const $=id=>document.getElementById(id);
  const make=(tag,cls,text)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=String(text);return n;};
  const labels={pending_review:'Awaiting review',scheduled:'Approved · scheduled',published:'Published',changes_requested:'Changes requested',rejected:'Rejected'};
  let csrf=root.dataset.csrf,items=[],busy=false;
  async function api(action,body){
    const url=new URL('model-video-review-api.php',document.baseURI);url.searchParams.set('action',action);
    const response=await fetch(url.href,{method:body?'POST':'GET',credentials:'same-origin',cache:'no-store',headers:body?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{},...(body?{body:JSON.stringify(body)}:{})});
    const data=await response.json().catch(()=>null);
    if(!response.ok||!data?.ok)throw new Error(data?.error?.message||'Review could not be completed.');
    return data.data;
  }
  function notice(message,error=false){const node=$('mv-admin-message');node.textContent=message;node.hidden=!message;node.classList.toggle('is-error',error);}
  function button(text,click,extra=''){
    const b=make('button','mv-button mv-button-small '+extra,text);b.type='button';b.disabled=busy;b.addEventListener('click',click);return b;
  }
  function render(){
    const list=$('mv-admin-list');list.replaceChildren();
    const filter=$('mv-admin-filter').value;const visible=items.filter(item=>filter==='all'||item.status===filter);
    if(!visible.length){list.append(make('p','mv-empty','No submissions in this category.'));return;}
    for(const item of visible){
      const card=make('article','mv-review-card');
      const heading=make('div','mv-review-heading');const left=make('div');left.append(make('h3','',item.title));
      left.append(make('small','',`${item.ownerName||'Model'} · Submitted ${item.submittedAt?new Date(item.submittedAt).toLocaleString():'not currently submitted'}`));
      heading.append(left,make('span','mv-badge mv-badge-'+item.status,labels[item.status]||item.status));card.append(heading);
      const cols=make('div','mv-review-columns');
      const preview=make('div','mv-cover','▷');
      if(item.coverUrl){const img=make('img');img.alt='Submission cover';img.loading='lazy';img.src=item.coverUrl;preview.replaceChildren(img);}
      const play=button('Play video to review',()=>{
        const existing=preview.querySelector('video');if(existing){existing.pause();existing.remove();play.textContent='Play video to review';return;}
        const player=make('video');player.controls=true;player.preload='metadata';player.playsInline=true;player.src=item.videoUrl;if(item.coverUrl)player.poster=item.coverUrl;
        preview.append(player);play.textContent='Close player';
      },'mv-preview');
      preview.append(play);cols.append(preview);
      const content=make('div','mv-review-content');
      content.append(make('p','',item.description||'No description supplied.'));
      content.append(make('small','mv-card-meta',`Video: ${item.durationMs?Math.round(item.durationMs/1000)+' seconds':'Length unavailable'} · Last updated ${new Date(item.updatedAt).toLocaleDateString()}`));
      if(item.reviewNote)content.append(make('div','mv-review-note','Previous admin notes: '+item.reviewNote));
      const actions=make('div','mv-review-actions');
      if(item.status==='pending_review'){
        const noteLabel=make('label','','Reviewer notes / reason (required for changes or rejection)');
        const notes=make('textarea');notes.rows=3;notes.maxLength=2000;notes.placeholder='Describe what needs to change or why this cannot be published…';notes.setAttribute('aria-label','Review notes for '+item.title);
        noteLabel.append(notes);content.append(noteLabel);
        actions.append(button('Approve & publish',()=>decide(item,'approve',notes.value.trim()),'mv-button-primary'));
        const scheduleLabel=make('label','','Arizona publication time');const when=make('input');when.type='datetime-local';when.setAttribute('aria-label','Arizona publication time for '+item.title);scheduleLabel.append(when);content.append(scheduleLabel);
        actions.append(button('Approve & schedule',()=>{if(!when.value){when.focus();notice('Choose an Arizona publication date and time.',true);return;}if(confirm('Approve this video and publish it at or after the specified Arizona time when the private cron next runs?'))void decide(item,'schedule',notes.value.trim(),when.value);},'mv-button-outline'));
        actions.append(button('Request changes',()=>{if(!notes.value.trim()){notes.focus();notice('Add a specific reason before requesting changes.',true);return;}decide(item,'request_changes',notes.value.trim());}));
        actions.append(button('Reject',()=>{if(!notes.value.trim()){notes.focus();notice('Add a specific reason before rejecting the video.',true);return;}if(confirm('Reject this submission? The model will see your review reason.'))decide(item,'reject',notes.value.trim());},'mv-button-danger'));
      }
      if(item.status==='scheduled'){
        content.append(make('small','mv-card-meta','Scheduled for '+(item.scheduledAt?new Date(item.scheduledAt).toLocaleString('en-US',{timeZone:'America/Phoenix',dateStyle:'medium',timeStyle:'short'})+' Arizona time':'a future publication time'));
        actions.append(button('Cancel scheduled release',()=>{if(confirm('Cancel this scheduled publication and return it to a private draft?'))decide(item,'unpublish','Schedule cancelled by administrator.');},'mv-button-danger'));
      }
      if(item.status==='published'){
        const view=make('a','mv-button mv-button-small mv-button-outline','View on Watch ↗');view.href=item.watchUrl;view.target='_blank';view.rel='noopener noreferrer';actions.append(view);
        actions.append(button('Unpublish',()=>{if(confirm('Remove this video from Watch?'))decide(item,'unpublish','Unpublished by administrator.');},'mv-button-danger'));
      }
      content.append(actions);cols.append(content);card.append(cols);list.append(card);
    }
  }
  async function refresh(){const data=await api('list');items=data.items||[];csrf=data.csrf||csrf;const counters=document.querySelectorAll('.mv-stats strong');for(const [i,status] of ['pending','published','changes_requested','rejected'].entries())if(counters[i])counters[i].textContent=Number(data.summary?.[status]??0);render();}
  async function decide(item,decision,note,publishAt=null){
    if(busy)return;busy=true;
    try{await api('moderate',{id:item.id,revision:item.revision,decision,note,...(publishAt?{publishAt}:{})});await refresh();notice(decision==='schedule'?'Video approved and scheduled for publication.':decision==='approve'?'Video approved and published on Watch.':decision==='unpublish'?'Video unpublished.':decision==='reject'?'Submission rejected with a reason.':'Changes requested from the model.');
    }catch(e){notice(e.message,true);try{await refresh();}catch{}}finally{busy=false;render();}
  }
  $('mv-admin-filter').addEventListener('change',render);
  refresh().catch(e=>{notice(e.message,true);$('mv-admin-list').replaceChildren(make('p','mv-empty','The submission queue could not be loaded. Refresh to retry.'));});
}
