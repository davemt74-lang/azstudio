const root=document.getElementById('director-commerce');
if(root){
  const select=document.getElementById('director-commerce-product'),form=document.getElementById('director-commerce-form');
  const status=document.getElementById('director-commerce-status'),preview=document.getElementById('director-commerce-preview');
  const title=document.getElementById('director-commerce-title'),description=document.getElementById('director-commerce-description');
  const price=document.getElementById('director-commerce-price');
  let products=[],liveRoom='',promotions=[];const promo=document.getElementById('director-commerce-promotion');
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
  const pulseStatus=document.getElementById('director-commerce-pulse-status');
  const pulseText=(id,value)=>{const node=document.getElementById(id);if(node)node.textContent=value;};
  const formatMoney=cents=>new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(Number(cents||0)/100);
  async function refreshPulse(){
    try{
      const data=await api('livePulse');
      const counts=data.onAir?data:null;
      pulseText('director-commerce-impressions',counts?.impressions??'—');
      pulseText('director-commerce-clicks',counts?.clicks??'—');
      pulseText('director-commerce-checkouts',counts?.checkoutStarts??'—');
      pulseText('director-commerce-paid',counts?.paidCheckoutSessions??'—');
      pulseText('director-commerce-net',counts?formatMoney(counts.verifiedNetCents):'—');
      pulseStatus.textContent=counts?(counts.featuredProduct||'Live room')+' · '+(counts.featuredCreator?'Creator: '+counts.featuredCreator+' · ':'')+'Source: live broadcast':'No active broadcast for live shopping metrics.';
    }catch{
      pulseStatus.textContent='Live commerce performance is unavailable. Try refreshing.';
    }
  }
  async function refresh(){
    try{
      const data=await api('state');void refreshPulse();promotions=data.promotions.filter(p=>p.state==='approved');products=data.products.filter(p=>p.state==='active'&&p.fulfillment?.inStock&&(p.stripeReady||p.checkoutUrl||p.localEnabled));
      const saved=select.value;select.replaceChildren();
      select.add(new Option(products.length?'Select a product':'No active products',''));
      for(const p of products)select.add(new Option(p.title+' · '+new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(p.priceCents/100),p.id));
      if(products.some(p=>p.id===saved))select.value=saved;
      const selectedProduct=select.value;promo.replaceChildren();promo.add(new Option('No creator attribution',''));
      for(const p of promotions.filter(p=>p.product_id===selectedProduct))promo.add(new Option(p.video_title,p.id));
      const featured=await api('liveFeature');
      liveRoom=featured?.roomId||'';
      if(featured){
        select.value=featured.id;
        promo.replaceChildren();promo.add(new Option('No creator attribution',''));
        for(const p of promotions.filter(p=>p.product_id===featured.id))promo.add(new Option(p.video_title,p.id));
        promo.value=featured.promotionId||'';
        showSelected(featured);status.textContent='On air · '+featured.title+' appears beside the viewer player.';
      }else{showSelected(null);status.textContent='Start a live session, then choose an active checkout-ready product.';}
    }catch(e){status.textContent=e.message;showSelected(null);}
  }
  select.addEventListener('change',()=>{showSelected(products.find(p=>p.id===select.value)||null);
    promo.replaceChildren();promo.add(new Option('No creator attribution',''));
    for(const p of promotions.filter(p=>p.product_id===select.value))promo.add(new Option(p.video_title,p.id));
  });
  form.addEventListener('submit',async e=>{
    e.preventDefault();if(!select.value){status.textContent='Choose a product first.';return;}
    const button=form.querySelector('button[type=submit]');button.disabled=true;status.textContent='Updating live product…';
    try{await api('featureLive',{productId:select.value,promotionId:promo.value});await refresh();}
    catch(e){status.textContent=e.message;}finally{button.disabled=false;}
  });
  void refresh();
  const tab=document.getElementById('live-tab-shopping');
  setInterval(()=>{if(document.visibilityState==='visible'&&!document.getElementById('live-panel-shopping').hidden)void refreshPulse();},30000);
  tab?.addEventListener('click',()=>void refresh());
  setInterval(()=>{if(document.visibilityState==='visible'&&!document.getElementById('live-panel-shopping').hidden)void refresh();},20000);
}
