/** Administrator-only merchandising controls. Never changes checkout prices or charge paths. */
const root=document.getElementById('azh-shop-merch');
if(root){
  const $=id=>document.getElementById(id);
  const csrf=root.dataset.csrf;
  let data=null,current=null;
  const status=(s,bad=false)=>{const el=$('azh-shop-merch-status');el.textContent=s;el.dataset.error=bad?'true':'false';};
  async function api(action,body=null){
    const endpoint=new URL('shop-catalog-api.php',document.baseURI);endpoint.searchParams.set('action',action);
    const isUpload=body instanceof FormData;
    const headers=body?{'X-CSRF-Token':csrf}:{};if(body&&!isUpload)headers['Content-Type']='application/json';
    const response=await fetch(endpoint,{method:body?'POST':'GET',credentials:'same-origin',cache:'no-store',headers,...(body?{body:isUpload?body:JSON.stringify(body)}:{})});
    const result=await response.json().catch(()=>null);
    if(!response.ok||!result?.ok)throw Error(result?.error?.message||'Merchandising request failed.');
    return result.data;
  }
  const line=(tag,label,cls='')=>{const el=document.createElement(tag);el.textContent=label;el.className=cls;return el;};
  const create=(tag,attrs={})=>{const el=document.createElement(tag);for(const [k,v] of Object.entries(attrs))el.setAttribute(k,v);return el;};
  function render(){
    if(!data)return;
    const productSelect=$('azh-shop-merch-product');const keep=productSelect.value;
    productSelect.replaceChildren(new Option('Select a saved product',''));
    for(const p of data.products)productSelect.add(new Option(p.title+' · '+p.state,p.id));
    if(data.products.some(p=>p.id===keep))productSelect.value=keep;
    else if(data.products.some(p=>p.id===current))productSelect.value=current;
    current=productSelect.value;
    const form=$('azh-shop-merch-form');form.hidden=!current;
    $('azh-shop-merch-upload').hidden=!current;
    if(!current)return;
    const p=data.products.find(p=>p.id===current);
    $('azh-shop-merch-category').value=p.category;
    $('azh-shop-merch-tagline').value=p.tagline;
    $('azh-shop-merch-featured').checked=Boolean(p.featured);
    $('azh-shop-merch-revision').value=p.revision;
    const parts=$('azh-shop-merch-components');parts.replaceChildren();
    for(const candidate of data.products.filter(v=>v.id!==p.id&&v.state==='active')){
      const label=create('label',{class:'azh-shop-merch-check'});
      const input=create('input',{type:'checkbox',value:candidate.id,name:'component'});input.checked=p.components.includes(candidate.id);
      label.append(input,document.createTextNode(candidate.title));parts.append(label);
    }
    const gallery=$('azh-shop-merch-gallery');gallery.replaceChildren();
    for(const image of p.images){
      const card=create('div',{class:'azh-shop-merch-image'});
      const img=create('img',{src:image.src,alt:image.alt,loading:'lazy'});card.append(img);
      const alt=create('input',{value:image.alt,'aria-label':'Alternative text for product image',maxlength:'180'});
      card.append(alt);
      const actions=create('div',{class:'azh-commerce-actions'});
      for(const [action,label] of [['cover','Make cover'],['alt','Save alt text'],['delete','Delete']]){
        const b=create('button',{type:'button'});b.textContent=label;
        b.addEventListener('click',async()=>{
          if(action==='delete'&&!window.confirm('Delete this product photo?'))return;
          b.disabled=true;try{await api('image',{id:image.id,action,...(action==='alt'?{alt:alt.value}:{})});status('Image updated.');await load(p.id);}catch(e){status(e.message,true);}finally{b.disabled=false;}
        });actions.append(b);
      }
      card.append(actions);gallery.append(card);
    }
    $('azh-shop-merch-photo').disabled=p.images.length>=6;
    $('azh-shop-merch-add-photo').disabled=p.images.length>=6;
    $('azh-shop-merch-image-note').textContent=p.images.length+'/6 photos · JPEG, PNG or WebP · max 4 MB';
  }
  async function load(id=null){data=await api('state');if(id)current=id;render();}
  $('azh-shop-merch-product').addEventListener('change',()=>{current=$('azh-shop-merch-product').value;render();});
  $('azh-shop-merch-form').addEventListener('submit',async event=>{
    event.preventDefault();if(!current)return;
    const components=[...root.querySelectorAll('#azh-shop-merch-components input:checked')].map(el=>el.value);
    if(components.length>4){status('Choose at most four bundle components.',true);return;}
    const button=$('azh-shop-merch-save');button.disabled=true;
    try{
      await api('save',{productId:current,category:$('azh-shop-merch-category').value,
        tagline:$('azh-shop-merch-tagline').value,featured:$('azh-shop-merch-featured').checked,
        components,revision:Number($('azh-shop-merch-revision').value)});
      status('Storefront details saved. Product prices and checkout remain unchanged.');await load(current);
    }catch(e){status(e.message,true);}finally{button.disabled=false;}
  });
  $('azh-shop-merch-upload').addEventListener('submit',async event=>{
    event.preventDefault();if(!current)return;
    const button=$('azh-shop-merch-add-photo');button.disabled=true;
    const form=event.currentTarget;const data=new FormData(form);data.set('productId',current);
    try{await api('upload',data);form.reset();status('Photo uploaded.');await load(current);}
    catch(e){status(e.message,true);}finally{button.disabled=false;}
  });
  void load().then(()=>status('Choose a saved product to manage its storefront appearance.')).catch(e=>status(e.message,true));
}
