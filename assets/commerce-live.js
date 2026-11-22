const container=document.getElementById('azh-live-commerce');
if(container){
  const title=container.querySelector('[data-commerce-title]'),desc=container.querySelector('[data-commerce-description]');
  const price=container.querySelector('[data-commerce-price]'),checkout=container.querySelector('[data-commerce-checkout]');
  const form=container.querySelector('[data-stripe-checkout]');
  const cashButton=container.querySelector('[data-local-order]');
  let last='';
  async function refresh(){
    try{
      const url=new URL('commerce-api.php',document.baseURI);url.searchParams.set('action','liveFeature');
      const res=await fetch(url,{credentials:'same-origin',cache:'no-store'}),body=await res.json();
      const item=body?.ok?body.data:null;
      if(!res.ok||!item){container.hidden=true;last='';return;}
      if(item.roomId+item.id+(item.promotionId||'')===last)return;
      last=item.roomId+item.id+(item.promotionId||'');title.textContent=item.title;desc.textContent=item.description;
      price.textContent=new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(item.priceCents/100);
      checkout.hidden=!(item.checkoutMode==='external'&&Boolean(item.checkoutUrl));
      form.hidden=!item.stripeReady;
      cashButton.dataset.localEnabled=item.localEnabled?'1':'0';cashButton.hidden=!item.localEnabled;
      if(item.localEnabled){
        cashButton.dataset.productId=item.id;cashButton.dataset.productTitle=item.title;
        cashButton.dataset.priceCents=String(item.priceCents);
        cashButton.dataset.roomId=item.roomId;cashButton.dataset.promotionId=item.promotionId||'';
      }
      document.dispatchEvent(new Event('azh:local-product-updated'));
      if(item.stripeReady){
        form.dataset.productId=item.id;form.dataset.roomId=item.roomId;
        form.dataset.promotionId=item.promotionId||'';
      }else checkout.href=item.checkoutUrl;
      container.hidden=false;
    }catch{container.hidden=true;}
  }
  void refresh();setInterval(()=>{if(document.visibilityState==='visible')void refresh();},10000);
}
