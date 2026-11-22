const root=document.getElementById('director-commerce');
if(root){
  const select=document.getElementById('director-commerce-product'),form=document.getElementById('director-commerce-form');
  const status=document.getElementById('director-commerce-status'),preview=document.getElementById('director-commerce-preview');
  const title=document.getElementById('director-commerce-title'),description=document.getElementById('director-commerce-description');
  const price=document.getElementById('director-commerce-price');
  let products=[],liveRoom='';
  const endpoint=new URL('commerce-api.php',document.baseURI);
  async function api(action,body=null){
    const url=new URL(endpoint);url.searchParams.set('action',action);
    const res=await fetch(url,{method:body?'POST':'GET',credentials:'same-origin',cache:'no-store',
      headers:body?{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf}:{},
      ...(body?{body:JSON.stringify(body)}:{})});
    const payload=await res.json().catch(()=>null);
    if(!res.ok||!payload?.ok)throw new Error(payload?.error?.message||'Unable to access commerce.');
    return payload.data;
  }
  function showSelected(item){
    preview.hidden=!item;
    if(item){title.textContent=item.title;description.textContent=item.description||'';price.textContent=new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(item.priceCents/100);}
  }
  async function refresh(){
    try{
      const data=await api('state');products=data.products.filter(p=>p.state==='active'&&p.checkoutUrl);
      const saved=select.value;select.replaceChildren();
      select.add(new Option(products.length?'Select a product':'No active products',''));
      for(const p of products)select.add(new Option(p.title+' · '+new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(p.priceCents/100),p.id));
      if(products.some(p=>p.id===saved))select.value=saved;
      const featured=await api('liveFeature');
      liveRoom=featured?.roomId||'';
      if(featured){
        select.value=featured.id;showSelected(featured);status.textContent='On air · '+featured.title+' appears beside the viewer player.';
      }else{showSelected(null);status.textContent='Start a live session, then choose an active checkout-ready product.';}
    }catch(e){status.textContent=e.message;showSelected(null);}
  }
  select.addEventListener('change',()=>showSelected(products.find(p=>p.id===select.value)||null));
  form.addEventListener('submit',async e=>{
    e.preventDefault();if(!select.value){status.textContent='Choose a product first.';return;}
    const button=form.querySelector('button[type=submit]');button.disabled=true;status.textContent='Updating live product…';
    try{await api('featureLive',{productId:select.value});await refresh();}
    catch(e){status.textContent=e.message;}finally{button.disabled=false;}
  });
  void refresh();
  const tab=document.getElementById('live-tab-shopping');
  tab?.addEventListener('click',()=>void refresh());
  setInterval(()=>{if(document.visibilityState==='visible'&&!document.getElementById('live-panel-shopping').hidden)void refresh();},20000);
}
