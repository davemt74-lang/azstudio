/* AZHottie v1.16 — editor-owned live recordings with admin-controlled publication. */
const root=document.getElementById('azh-live-library');
if (root) {
  const list=document.getElementById('live-library-grid'),message=document.getElementById('live-library-message');
  const endpoint=new URL('live-api.php',document.baseURI),csrf=root.dataset.csrf,canPublish=root.dataset.canPublish==='1';
  const say=(value,bad=false)=>{message.textContent=value;message.classList.toggle('is-error',bad);};
  async function request(action,data=null) {
    const url=new URL(endpoint);url.searchParams.set('action',action);
    const form=data instanceof FormData;
    let response;
    try {response=await fetch(url,{credentials:'same-origin',cache:'no-store',method:data===null?'GET':'POST',headers:data===null?{}:{'X-CSRF-Token':csrf,...(form?{}:{'Content-Type':'application/json'})},...(data===null?{}:{body:form?data:JSON.stringify(data)})});}
    catch {throw Error('Network interrupted. Your saved recording is unchanged; refresh and retry.');}
    const payload=await response.json().catch(()=>null);
    if(!response.ok||!payload?.ok)throw Error(payload?.error?.message||`Request failed (${response.status}).`);
    return payload.data;
  }
  const node=(tag,className='',label='')=>{const e=document.createElement(tag);if(className)e.className=className;if(label)e.textContent=label;return e;};
  const button=(label,handler,kind='azh-live-secondary')=>{const e=node('button',`azh-live-button ${kind}`,label);e.type='button';e.addEventListener('click',handler);return e;};
  const field=(label,control)=>{const box=node('label');const text=node('span','',label);box.append(text,control);return box;};
  let loading=false;
  async function refresh() {
    if(loading)return;loading=true;list.replaceChildren();say('Loading saved productions…');
    try {
      const data=await request('recordings'),items=data.recordings||[];
      if(!items.length){say('No saved live productions yet. Record a Program in Live Director to create one.');return;}
      say(`${items.length} saved live production${items.length===1?'':'s'} · Publication requires approval.`);
      for(const item of items) list.append(render(item));
    } catch(error) {say(error.message,true);} finally {loading=false;}
  }
  async function change(action,item,body={},form=false) {
    try {
      say('Saving changes…');
      const payload=form?body:{...body,id:item.id,revision:item.revision};
      await request(action,payload);
      // The returned revision belongs to the update; reload prevents stale toggles.
      loading=false;await refresh();
    } catch(error) {say(error.message,true);}
  }
  function render(item) {
    const card=node('article','azh-live-library-item');
    const cover=node('div','azh-live-archive-cover');
    if(item.coverUrl){const img=node('img');img.src=item.coverUrl+'&v='+item.revision;img.alt='Cover for '+item.title;img.loading='lazy';cover.append(img);}
    else cover.append(node('span','','✳'));
    const body=node('div','azh-live-library-info');
    const flag=node('div','azh-live-library-status',item.published?(item.audience==='public'?'● Public · Anyone can watch':'● Published to members only'):'Private draft · Not published');
    const name=node('input');name.value=item.title;name.maxLength=180;name.required=true;
    const desc=node('textarea');desc.value=item.description||'';desc.maxLength=1000;desc.rows=3;
    const modified=node('small','',`Recorded ${new Date(item.recordedAt).toLocaleString()} · ${Math.max(0,Math.round(item.sizeBytes/1024/1024*10)/10)} MB`);
    const controls=node('div','azh-live-library-actions');
    controls.append(button('Save details',()=>void change('updateRecording',item,{title:name.value,description:desc.value})));
    const player=node('video');player.controls=true;player.preload='none';player.playsInline=true;player.src=item.videoUrl;
    player.style.width='100%';player.style.maxHeight='250px';player.style.background='#1c101a';
    if(item.coverUrl)player.poster=item.coverUrl+'&v='+item.revision;
    controls.append(button('Download',()=>{const a=node('a');a.href=item.videoUrl+'&download=1';a.click();}));
    const coverInput=node('input');coverInput.type='file';coverInput.accept='image/png,image/jpeg,image/webp';coverInput.hidden=true;
    coverInput.addEventListener('change',async()=>{
      const file=coverInput.files?.[0];if(!file)return;
      if(file.size>5242880){say('Choose a cover image smaller than 5 MB.',true);return;}
      const form=new FormData();form.set('id',item.id);form.set('revision',String(item.revision));form.set('cover',file,file.name);
      await change('uploadCover',item,form,true);
    });
    controls.append(button('Upload cover',()=>coverInput.click()));
    if(item.coverUrl)controls.append(button('Remove cover',()=>void change('removeCover',item)));
    if(canPublish){
      controls.append(button(item.published?'Unpublish':'Publish to members',()=>{
        if(item.published||window.confirm('Publish this recording for signed-in AZHottie members?'))void change(item.published?'unpublishRecording':'publishRecording',item);
      },item.published?'azh-live-secondary':''));
      if(item.published){
        controls.append(button(item.audience==='public'?'Restrict to members':'Make public',()=>{
          const audience=item.audience==='public'?'members':'public';
          if(audience==='members'||window.confirm('Make this entire recording and its cover available to EVERYONE on the public Live Shows page, including visitors without an account?')){
            void change('setAudience',item,{audience});
          }
        },item.audience==='public'?'azh-live-secondary':''));
      }
    }
    body.append(flag,field('Production title',name),field('Description',desc),modified,player,controls,coverInput);
    card.append(cover,body);return card;
  }
  void refresh();
}
