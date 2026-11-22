const checkoutButtons=document.querySelectorAll('[data-stripe-checkout]');
const freshToken=()=>{
  const bytes=new Uint8Array(16);crypto.getRandomValues(bytes);
  return Array.from(bytes,x=>x.toString(16).padStart(2,'0')).join('');
};
for(const form of checkoutButtons){
  let submitting=false;
  form.addEventListener('submit',async e=>{
    e.preventDefault();if(submitting)return;
    const button=form.querySelector('button[type=submit]'),status=form.querySelector('[role=status]');
    const token=freshToken();submitting=true;button.disabled=true;
    if(status)status.textContent='Opening secure Stripe Checkout…';
    try{
      const url=new URL('commerce-checkout-api.php',document.baseURI);
      const body={productId:form.dataset.productId,channel:form.dataset.channel||'shop',
        videoId:form.dataset.videoId||'',promotionId:form.dataset.promotionId||'',
        roomId:form.dataset.roomId||'',quantity:1,clientToken:token};
      const res=await fetch(url,{method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{'Content-Type':'application/json','X-CSRF-Token':form.dataset.csrf||''},
        body:JSON.stringify(body)});
      const answer=await res.json().catch(()=>null);
      if(!res.ok||!answer?.ok)throw new Error(answer?.error?.message||'Unable to create checkout');
      const destination=new URL(answer.data?.checkoutUrl||'');
      if(destination.protocol!=='https:'||destination.hostname!=='checkout.stripe.com')throw new Error('Unexpected checkout destination.');
      window.location.assign(destination.href);
    }catch(error){
      if(status)status.textContent=error.message||'Checkout is temporarily unavailable.';
      button.disabled=false;submitting=false;
    }
  });
}
