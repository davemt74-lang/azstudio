/* AZHottie My Videos – independent, private by default, approval-gated. */
const root=document.querySelector('.mv-page:not(.mv-admin)');
if(root){
  const $=id=>document.getElementById(id);
  const make=(tag,className,text)=>{const n=document.createElement(tag);if(className)n.className=className;if(text!==undefined)n.textContent=String(text);return n;};
  const labels={draft:'Private draft',pending_review:'Awaiting admin review',scheduled:'Approved · scheduled',published:'Published',changes_requested:'Changes requested',rejected:'Rejected'};
  let csrf=root.dataset.csrf,items=[],gallery=[],selected=null,busy=false;
  const edit=$('mv-edit-dialog'),submit=$('mv-submit-dialog');
  function notice(message,error=false){const node=$('mv-message');node.hidden=!message;node.textContent=message||'';node.classList.toggle('is-error',error);if(message)node.scrollIntoView({block:'nearest',behavior:'smooth'});}
  async function api(action,body){
    const url=new URL('model-videos-api.php',document.baseURI);url.searchParams.set('action',action);
    const response=await fetch(url.href,{method:body?'POST':'GET',credentials:'same-origin',cache:'no-store',headers:body?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{},...(body?{body:JSON.stringify(body)}:{})});
    const data=await response.json().catch(()=>null);
    if(!response.ok||!data?.ok)throw new Error(data?.error?.message||'The studio could not complete your request.');
    return data.data;
  }
  function cover(item){
    const box=make('div','mv-cover','▷');
    if(item.coverUrl){const img=make('img');img.src=item.coverUrl;img.loading='lazy';img.alt='Cover for '+item.title;box.replaceChildren(img);}
    const play=make('button','mv-preview','Preview video');play.type='button';
    play.addEventListener('click',()=>{
      const existing=box.querySelector('video');if(existing){existing.pause();existing.remove();play.textContent='Preview video';return;}
      const video=make('video');video.controls=true;video.playsInline=true;video.preload='metadata';video.src=item.videoUrl;
      if(item.coverUrl)video.poster=item.coverUrl;
      box.append(video);play.textContent='Close preview';
    });
    box.append(play);return box;
  }
  function button(text,action,kind='mv-button-outline'){
    const b=make('button','mv-button mv-button-small '+kind,text);b.type='button';b.disabled=busy;
    b.addEventListener('click',action);return b;
  }
  function render(){
    const status=$('mv-filter').value;const filtered=items.filter(item=>status==='all'||item.status===status);
    const grid=$('mv-grid');grid.replaceChildren();
    $('mv-count').textContent=`${items.length} video${items.length===1?'':'s'}`;
    if(!filtered.length)grid.append(make('p','mv-empty',items.length?'No videos match this filter.':'No videos here yet. Choose a video from your gallery above to start.'));
    for(const item of filtered){
      const card=make('article','mv-card');card.id='mv-video-'+item.id;card.tabIndex=-1;card.append(cover(item));
      const body=make('div','mv-card-content');
      body.append(make('span','mv-badge mv-badge-'+item.status,labels[item.status]||item.status));
      body.append(make('h3','',item.title));
      if(item.description)body.append(make('p','',item.description));
      body.append(make('small','mv-card-meta',`${item.durationMs?Math.round(item.durationMs/1000)+' sec · ':''}Updated ${new Date(item.updatedAt).toLocaleDateString()}`));
      if(item.status==='scheduled' && item.scheduledAt)body.append(make('small','mv-card-meta','Approved release: '+new Date(item.scheduledAt).toLocaleString('en-US',{timeZone:'America/Phoenix',dateStyle:'medium',timeStyle:'short'})+' Arizona time'));
      if(item.reviewNote){const note=make('div','mv-review-note');note.append(make('strong','',item.status==='rejected'?'Rejection reason:':'Admin notes: '),document.createTextNode(item.reviewNote));body.append(note);}
      const actions=make('div','mv-card-actions');
      actions.append(button('Edit details',()=>{selected=item;$('mv-edit-title').value=item.title;$('mv-edit-description').value=item.description;edit.showModal();}));
      if(['draft','changes_requested'].includes(item.status))actions.append(button('Submit for review',()=>{selected=item;$('mv-rights').checked=false;$('mv-submit-title').textContent=item.title;submit.showModal();},'mv-button-primary'));
      if(['pending_review','scheduled','published','changes_requested','rejected'].includes(item.status))actions.append(button(item.status==='published'?'Unpublish video':'Return to draft',async()=>{
        const note=item.status==='published'?'Remove this video from Watch and return it to a private draft?':'Withdraw this video and make it private again?';
        if(!confirm(note))return;
        await mutate('withdraw',{id:item.id,revision:item.revision},'Video returned to a private draft.');
      }));
      if(item.status==='published'&&item.watchUrl){const link=make('a','mv-button mv-button-small mv-button-outline','View on Watch ↗');link.href=item.watchUrl;link.target='_blank';link.rel='noopener noreferrer';actions.append(link);}
      actions.append(button('Remove from My Videos',async()=>{
        if(!confirm('Remove this entry from My Videos? The private source footage will remain in My Media. Published videos will disappear from Watch.'))return;
        await mutate('delete',{id:item.id,revision:item.revision},'Removed from My Videos. Your original media was preserved.');
      },'mv-button-danger'));
      body.append(actions);card.append(body);grid.append(card);
    }
  }
  function populateSources(){
    const select=$('mv-media');const previous=select.value;select.replaceChildren();
    const occupied=new Set(items.map(item=>item.mediaId));
    const available=gallery.filter(media=>(media.mimeType||'').startsWith('video/')&&!occupied.has(media.id));
    const placeholder=make('option','',available.length?'Select your video…':'No unused videos in My Media');placeholder.value='';select.append(placeholder);
    for(const media of available){const option=make('option','',media.title||'Untitled video');option.value=media.id;select.append(option);}
    if(available.some(media=>media.id===previous))select.value=previous;
    $('mv-gallery-help').textContent=available.length?'Your private footage remains unchanged.':'Upload or export a video in My Media first; then return here to create its post.';
    $('mv-add').disabled=!available.length;
  }
  let initialAnchor=true;
  async function refresh(){
    const data=await api('list');csrf=data.csrf||csrf;items=data.items||[];gallery=data.gallery||[];render();populateSources();
    if(initialAnchor){
      initialAnchor=false;
      const target=new URLSearchParams(location.search).get('videoId')||'';
      if(/^[a-f0-9]{32}$/.test(target)&&items.some(item=>item.id===target)){
        $('mv-filter').value='all';render();
        const card=document.getElementById('mv-video-'+target);
        if(card){card.scrollIntoView({block:'center'});card.focus({preventScroll:true});}
      }
    }
  }
  async function mutate(action,payload,message){
    if(busy)return false;busy=true;let success=false;try{await api(action,payload);await refresh();notice(message);success=true;}catch(e){notice(e.message,true);try{await refresh();}catch{}}finally{busy=false;render();}return success;
  }
  $('mv-filter').addEventListener('change',render);
  $('mv-media').addEventListener('change',()=>{const source=gallery.find(media=>media.id===$('mv-media').value);if(source&&!$('mv-title').value.trim())$('mv-title').value=source.title||'';});
  $('mv-create').addEventListener('submit',async e=>{
    e.preventDefault();const mediaId=$('mv-media').value;const title=$('mv-title').value.trim();if(!mediaId||!title)return;
    const created=await mutate('create',{mediaId,title,description:$('mv-description').value.trim()},'Video added as a private draft.');
    if(created){$('mv-create').reset();$('mv-gallery-help').textContent='You can submit your video for admin review when ready.';}
  });
  $('mv-edit-form').addEventListener('submit',async e=>{
    e.preventDefault();if(!selected)return;
    const payload={id:selected.id,revision:selected.revision,title:$('mv-edit-title').value.trim(),description:$('mv-edit-description').value.trim()};
    if(!payload.title){$('mv-edit-title').focus();return;}
    edit.close();await mutate('update',payload,'Video details saved as a private draft.');selected=null;
  });
  $('mv-submit-form').addEventListener('submit',async e=>{
    e.preventDefault();if(!selected||!$('mv-rights').checked)return;
    const payload={id:selected.id,revision:selected.revision,rightsConfirmed:true};
    submit.close();await mutate('submit',payload,'Video submitted for admin review. It is not public yet.');selected=null;
  });
  document.querySelectorAll('[data-mv-close]').forEach(b=>b.addEventListener('click',()=>b.closest('dialog')?.close()));
  refresh().catch(e=>{notice(e.message,true);$('mv-grid').replaceChildren(make('p','mv-empty','Could not load your videos. Refresh this page to retry.'));});
}
