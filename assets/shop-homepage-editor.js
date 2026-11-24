/** Shop2.1 draft editor: all writes require admin CSRF and revision lease. */
const editor=document.getElementById('azh-shop-editor');
if(editor){
  const $=id=>document.getElementById(id),form=$('azh-shop-editor-form'),csrf=editor.dataset.csrf;
  let state=null,order=[],dirty=false,busy=false;
  const status=(message,bad=false)=>{const label=$('azh-shop-editor-status');label.textContent=message;label.dataset.error=bad?'true':'false';};
  async function api(action,body=null){
    const uri=new URL('shop-homepage-api.php',document.baseURI);uri.searchParams.set('action',action);
    const upload=body instanceof FormData;
    const headers=body?{'X-CSRF-Token':csrf}:{};
    if(body&&!upload)headers['Content-Type']='application/json';
    const response=await fetch(uri,{credentials:'same-origin',cache:'no-store',method:body?'POST':'GET',headers,...(body?{body:upload?body:JSON.stringify(body)}:{})});
    const data=await response.json().catch(()=>null);
    if(!response.ok||!data?.ok)throw Error(data?.error?.message||'Shop configuration request failed.');
    return data.data;
  }
  function fields(){
    const draft={...state.draft,sections:{...state.draft.sections},categories:{...state.draft.categories},order:[...order]};
    for(const key of ['eyebrow','heading','accent','description','ctaText','ctaHref','categoriesTitle',
      'featuredTitle','catalogTitle','heroImage','backgroundImage','heroPosition','heroAlignment','fontStyle',
      'textColor','accentColor','buttonColor','backgroundColor'])draft[key]=form.elements.namedItem(key).value;
    for(const key of ['heroHeight','headingSize','overlayStrength'])draft[key]=Number(form.elements.namedItem(key).value);
    for(const key of ['storeEnabled','sampleEnabled'])draft[key]=form.elements.namedItem(key).checked;
    for(const section of Object.keys(state.sections))draft.sections[section]=Boolean(form.querySelector(`[data-section-enable="${section}"]`)?.checked);
    for(const category of Object.keys(state.categories))draft.categories[category]=Boolean(form.querySelector(`[data-category-enable="${category}"]`)?.checked);
    return draft;
  }
  function messagePreview(){
    if(!state)return;
    const win=$('azh-shop-preview')?.contentWindow;
    if(location.origin!=='null')win?.postMessage({type:'azh-shop-draft-preview',design:fields()},location.origin);
    for(const [name,id,suffix] of [['heroHeight','azh-hero-height-value','px'],['headingSize','azh-headline-size-value','px'],['overlayStrength','azh-overlay-value','%']])
      $(id).textContent=form.elements.namedItem(name).value+suffix;
  }
  function imageOptions(){
    const options=[{id:'default',name:'AZHottie editorial hero (image two)',src:'assets/shop-editorial-hero-v157.webp'},
      {id:'none',name:'No image',src:''},...state.images.map(i=>({id:i.id,name:i.alt_text,src:i.src}))];
    for(const name of ['heroImage','backgroundImage']){
      const el=form.elements.namedItem(name),value=state.draft[name];el.replaceChildren();
      for(const opt of options)el.add(new Option(opt.name,opt.id));el.value=value;
    }
    const gallery=$('azh-edit-upload-list');gallery.replaceChildren();
    for(const image of state.images){
      const cell=document.createElement('div');cell.className='azh-edit-image-preview';
      const im=document.createElement('img');im.src=image.src;im.alt=image.alt_text;cell.append(im);
      const btn=document.createElement('button');btn.type='button';btn.textContent='Delete unused';
      btn.addEventListener('click',async()=>{
        if(!confirm('Delete this uploaded image? It must not be used by a draft or published homepage.'))return;
        try{btn.disabled=true;await api('deleteImage',{id:image.id});status('Unused image removed.');
          const pending=fields();state=await api('state');state.draft=pending;render();}
        catch(e){status(e.message,true);}finally{btn.disabled=false;}
      });cell.append(btn);gallery.append(cell);
    }
  }
  function createChoice(name,slug,title,enabled){
    const row=document.createElement('div');row.className='azh-shop-edit-choice';row.dataset.slug=slug;
    const label=document.createElement('label');
    const input=document.createElement('input');input.type='checkbox';input.checked=enabled;
    input.dataset[name==='section'?'sectionEnable':'categoryEnable']=slug;
    label.append(input,document.createTextNode(title));row.append(label);
    input.addEventListener('change',()=>{dirty=true;status('Unpublished edits · Save Draft or Publish to apply.');messagePreview();});
    if(name==='section'){
      const group=document.createElement('div');group.className='azh-choice-controls';
      for(const [delta,symbol] of [[-1,'↑'],[1,'↓']]){
        const btn=document.createElement('button');btn.type='button';btn.textContent=symbol;btn.setAttribute('aria-label',delta<0?'Move '+title+' up':'Move '+title+' down');
        btn.addEventListener('click',()=>{
          const i=order.indexOf(slug),next=i+delta;if(next<0||next>=order.length)return;
          [order[i],order[next]]=[order[next],order[i]];dirty=true;renderChoices();status('Section order changed — draft not saved.');messagePreview();
        });group.append(btn);
      }row.append(group);
    }
    return row;
  }
  function renderChoices(){
    const sections=$('azh-edit-section-list'),categories=$('azh-edit-category-list');sections.replaceChildren();categories.replaceChildren();
    const current=(()=>{try{return fields();}catch{return state.draft;}})();
    for(const key of order)sections.append(createChoice('section',key,state.sections[key],Boolean(current.sections[key])));
    for(const [key,name] of Object.entries(state.categories))categories.append(createChoice('category',key,name,Boolean(current.categories[key])));
  }
  function render(){
    order=[...state.draft.order];
    for(const key of ['eyebrow','heading','accent','description','ctaText','ctaHref','categoriesTitle',
      'featuredTitle','catalogTitle','heroPosition','heroAlignment','fontStyle','textColor','accentColor','buttonColor','backgroundColor',
      'heroHeight','headingSize','overlayStrength'])form.elements.namedItem(key).value=state.draft[key];
    for(const key of ['storeEnabled','sampleEnabled'])form.elements.namedItem(key).checked=Boolean(state.draft[key]);
    imageOptions();renderChoices();messagePreview();
  }
  async function reload(){state=await api('state');render();dirty=false;status('Ready · published revision '+state.publishedRevision);}
  async function save(mode){
    if(busy||!state)return;
    if(mode==='publish'&&!window.confirm('Publish these shop homepage changes to the public website?'))return;
    busy=true;for(const button of editor.querySelectorAll('[data-action]'))button.disabled=true;
    try{
      const before=fields();const result=await api('save',{revision:state.revision,action:mode==='publish'?'publish':'saveDraft',design:before});
      state=await api('state');render();dirty=false;
      status(result.published?'Homepage published successfully.':'Draft saved · not visible to the public.');
      if(mode==='preview'||mode==='publish')$('azh-shop-preview').src='shop.php?preview=1&v='+state.revision;
    }catch(e){status(e.message,true);}finally{busy=false;for(const button of editor.querySelectorAll('[data-action]'))button.disabled=false;}
  }
  for(const [selector,mode] of [['save','saveDraft'],['preview','preview'],['publish','publish']]){
    editor.querySelector(`[data-action="${selector}"]`).addEventListener('click',()=>void save(mode));
  }
  editor.querySelector('[data-action="reset"]').addEventListener('click',async()=>{
    if(!state||!window.confirm('Discard saved draft and restore the current published design?'))return;
    try{await api('save',{revision:state.revision,action:'resetDraft'});await reload();$('azh-shop-preview').src='shop.php?preview=1&v='+state.revision;}
    catch(e){status(e.message,true);}
  });
  form.addEventListener('submit',e=>{e.preventDefault();void save('saveDraft');});
  form.addEventListener('input',()=>{if(!state)return;dirty=true;status('Unpublished changes · save draft or publish.');messagePreview();});
  form.addEventListener('change',()=>{if(!state)return;dirty=true;messagePreview();});
  window.addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue='';}});
  $('azh-shop-preview').addEventListener('load',messagePreview);
  $('azh-edit-desktop').addEventListener('click',()=>{ $('azh-preview-wrap').classList.remove('is-phone');$('azh-edit-desktop').setAttribute('aria-pressed','true');$('azh-edit-phone').setAttribute('aria-pressed','false');});
  $('azh-edit-phone').addEventListener('click',()=>{ $('azh-preview-wrap').classList.add('is-phone');$('azh-edit-phone').setAttribute('aria-pressed','true');$('azh-edit-desktop').setAttribute('aria-pressed','false');});
  $('azh-edit-upload').addEventListener('click',async()=>{
    const file=$('azh-edit-upload-file').files[0],target=$('azh-edit-upload-target').value;
    const alt=form.elements.namedItem('uploadAlt').value.trim();
    if(!file||!alt){$('azh-edit-upload-status').textContent='Select an image and enter a description.';return;}
    const button=$('azh-edit-upload');button.disabled=true;
    try{
      const pending=fields();const fd=new FormData();fd.set('image',file);fd.set('alt',alt);
      const result=await api('upload',fd);state=await api('state');pending[target]=result.id;state.draft=pending;
      render();dirty=true;$('azh-edit-upload-status').textContent='Image uploaded. Save draft and publish to make it public.';
    }catch(e){$('azh-edit-upload-status').textContent=e.message;status(e.message,true);}finally{button.disabled=false;}
  });
  reload().catch(e=>status(e.message,true));
}
