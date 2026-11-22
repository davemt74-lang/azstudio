const container=document.getElementById('azh-live-commerce');
if(container){
  const title=container.querySelector('[data-commerce-title]'),desc=container.querySelector('[data-commerce-description]');
  const price=container.querySelector('[data-commerce-price]'),checkout=container.querySelector('[data-commerce-checkout]');
  const shipping=container.querySelector('[data-commerce-shipping]');
  const form=container.querySelector('[data-stripe-checkout]');
  const cashButton=container.querySelector('[data-local-order]');
  let last='';
  async function refresh(){
    try{
      const url=new URL('commerce-api.php',document.baseURI);url.searchParams.set('action','liveFeature');
      const res=await fetch(url,{credentials:'same-origin',cache:'no-store'}),body=await res.json();
      const item=body?.ok?body.data:null;
      if(!res.ok||!item){container.hidden=true;last='';return;}
      const signature=item.roomId+item.id+(item.promotionId||'')+'|'+JSON.stringify(item.fulfillment)+'|'+String(item.localEnabled)+'|'+String(item.stripeReady)+'|'+String(item.checkoutUrl||'');
      if(signature===last)return;
      last=signature;title.textContent=item.title;desc.textContent=item.description;
      price.textContent=new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(item.priceCents/100);
      checkout.hidden=!(item.checkoutMode==='external'&&Boolean(item.checkoutUrl));
      const liveCarrier=item.fulfillment?.shippingRate==='carrier';
      form.hidden=!item.stripeReady||!item.fulfillment?.inStock||(liveCarrier&&!item.fulfillment?.carrierCheckoutAvailable);
      form.dataset.shippingMode=item.fulfillment?.shippingRate||'flat';
      form.dataset.productTitle=item.title;form.dataset.priceCents=String(item.priceCents);
      form.querySelector('button[type=submit]').textContent=liveCarrier?'Choose Shipping & Checkout':'Secure Stripe Checkout';
      form.dataset.checkoutToken='';
      cashButton.dataset.localEnabled=item.localEnabled&&item.fulfillment?.inStock?'1':'0';
      cashButton.hidden=!(item.localEnabled&&item.fulfillment?.inStock);
      shipping.textContent=!item.fulfillment?.inStock?'Out of stock':item.fulfillment?.shippingEnabled
        ?'US shipping available · '+(item.fulfillment.shippingRate==='carrier'?'Live carrier rates before checkout':
          item.fulfillment.shippingRate==='free'?'Free':
          new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(item.fulfillment.shippingFeeCents/100))
        :'Local pickup or seller fulfillment';
      if(item.localEnabled){
        cashButton.dataset.productId=item.id;cashButton.dataset.productTitle=item.title;
        cashButton.dataset.priceCents=String(item.priceCents);
        cashButton.dataset.roomId=item.roomId;cashButton.dataset.promotionId=item.promotionId||'';
      }
      document.dispatchEvent(new Event('azh:local-product-updated'));
      if(item.stripeReady&&item.fulfillment?.inStock&&(!liveCarrier||item.fulfillment?.carrierCheckoutAvailable)){
        form.dataset.productId=item.id;form.dataset.roomId=item.roomId;
        form.dataset.promotionId=item.promotionId||'';
      }else checkout.href=item.checkoutUrl;
      container.hidden=false;
    }catch{container.hidden=true;}
  }
  void refresh();setInterval(()=>{if(document.visibilityState==='visible')void refresh();},10000);
}
