/**
 * Hosted Stripe checkout connector; any dynamic shipping price is selected by
 * the server from a verified, session-bound EasyPost quote. This script never
 * reads card numbers or trusts a browser-provided shipping fee.
 */
const checkoutButtons=document.querySelectorAll('[data-stripe-checkout]');
const freshToken=()=>{
  const bytes=new Uint8Array(16);crypto.getRandomValues(bytes);
  return Array.from(bytes,x=>x.toString(16).padStart(2,'0')).join('');
};
async function azCheckoutStart(form,extra={}){
  if(form.dataset.submitting==='1')return;
  const button=form.querySelector('button[type=submit]');
  const status=form.querySelector('[role=status]');
  const token=form.dataset.checkoutToken||freshToken();
  form.dataset.checkoutToken=token;
  form.dataset.submitting='1';
  if(button)button.disabled=true;
  if(status)status.textContent='Creating your secure Stripe Checkout…';
  try{
    const quantity=Number(extra.quantity||form.dataset.quantity||1);
    if(!Number.isInteger(quantity)||quantity<1||quantity>10)
      throw new Error('Choose a valid quantity.');
    const url=new URL('commerce-checkout-api.php',document.baseURI);
    const body={
      productId:form.dataset.productId,channel:form.dataset.channel||'shop',
      videoId:form.dataset.videoId||'',promotionId:form.dataset.promotionId||'',
      roomId:form.dataset.roomId||'',quantity,clientToken:token
    };
    if(form.dataset.shippingMode==='carrier'){
      if(!extra.shippingQuoteId||!extra.shippingRateId)
        throw new Error('Select a current live carrier rate before checkout.');
      body.shippingQuoteId=extra.shippingQuoteId;
      body.shippingRateId=extra.shippingRateId;
    }
    const res=await fetch(url,{method:'POST',credentials:'same-origin',cache:'no-store',
      headers:{'Content-Type':'application/json','X-CSRF-Token':form.dataset.csrf||''},
      body:JSON.stringify(body)});
    const answer=await res.json().catch(()=>null);
    if(!res.ok||!answer?.ok)
      throw new Error(answer?.error?.message||'Unable to create checkout.');
    const destination=new URL(answer.data?.checkoutUrl||'');
    if(destination.protocol!=='https:'||destination.hostname!=='checkout.stripe.com')
      throw new Error('Unexpected secure checkout destination.');
    window.location.assign(destination.href);
  }catch(error){
    if(status)status.textContent=error?.message||'Checkout is temporarily unavailable.';
    form.dataset.submitting='0';
    if(button)button.disabled=false;
    throw error;
  }
}
window.azCheckoutStart=azCheckoutStart;
for(const form of checkoutButtons){
  form.addEventListener('submit',event=>{
    event.preventDefault();
    if(form.dataset.submitting==='1')return;
    if(form.dataset.shippingMode==='carrier'){
      if(typeof window.azShippingModalOpen==='function')
        window.azShippingModalOpen(form);
      else{
        const status=form.querySelector('[role=status]');
        if(status)status.textContent='Live carrier shipping is unavailable. Retry after refreshing the page.';
      }
      return;
    }
    void azCheckoutStart(form).catch(()=>{});
  });
}
