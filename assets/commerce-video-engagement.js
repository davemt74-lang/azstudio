/* Capture approved product CTA taps on Watch without delaying navigation or payments.
 * Aggregate only; never transmit viewer identifiers, addresses or cart details. */
const section=document.querySelector('.azh-commerce-watch[data-commerce-video]');
if(section){
  const recorded=new Set();
  const url=new URL('commerce-video-engagement.php',document.baseURI);
  const track=(card)=>{
    const videoId=section.dataset.videoId,productId=card.dataset.productId,promotionId=card.dataset.promotionId;
    if(!videoId||!productId||!promotionId)return;
    const key=`${videoId}:${productId}:${promotionId}`;
    if(recorded.has(key))return;
    recorded.add(key);
    void fetch(url,{method:'POST',credentials:'same-origin',cache:'no-store',keepalive:true,
      headers:{'Content-Type':'application/json','X-CSRF-Token':section.dataset.csrf||''},
      body:JSON.stringify({event:'click',videoId,productId,promotionId})
    }).then(response=>{if(!response.ok)recorded.delete(key);}).catch(()=>recorded.delete(key));
  };
  section.addEventListener('click',event=>{
    if(!event.target.closest('a.azh-commerce-cta,button.azh-commerce-cta,[data-stripe-checkout] button,[data-local-order]'))return;
    const card=event.target.closest('[data-video-product]');
    if(card)track(card);
  },true);
  section.addEventListener('submit',event=>{
    if(!event.target.matches('[data-stripe-checkout]'))return;
    const card=event.target.closest('[data-video-product]');
    if(card)track(card);
  },true);
}
