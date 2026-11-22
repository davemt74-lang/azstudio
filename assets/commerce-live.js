const container=document.getElementById('azh-live-commerce');
if(container){
  const title=container.querySelector('[data-commerce-title]'),desc=container.querySelector('[data-commerce-description]');
  const price=container.querySelector('[data-commerce-price]'),checkout=container.querySelector('[data-commerce-checkout]');
  let last='';
  async function refresh(){
    try{
      const url=new URL('commerce-api.php',document.baseURI);url.searchParams.set('action','liveFeature');
      const res=await fetch(url,{credentials:'same-origin',cache:'no-store'}),body=await res.json();
      const item=body?.ok?body.data:null;
      if(!res.ok||!item){container.hidden=true;last='';return;}
      if(item.roomId+item.id===last)return;
      last=item.roomId+item.id;title.textContent=item.title;desc.textContent=item.description;
      price.textContent=new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(item.priceCents/100);
      checkout.href=item.checkoutUrl;container.hidden=false;
    }catch{container.hidden=true;}
  }
  void refresh();setInterval(()=>{if(document.visibilityState==='visible')void refresh();},10000);
}
