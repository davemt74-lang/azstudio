/* Cash-only local order requests. No card capture or automatic payment confirmation. */
const localEndpoint=new URL('commerce-local-api.php',document.baseURI);
const localButtons=()=>[...document.querySelectorAll('[data-local-order]')];
const money=c=>new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(Number(c||0)/100);
const token=()=>{const a=new Uint8Array(16);crypto.getRandomValues(a);return Array.from(a,b=>b.toString(16).padStart(2,'0')).join('');};
async function localApi(action,body=null,csrf=''){
  const u=new URL(localEndpoint);u.searchParams.set('action',action);
  const res=await fetch(u,{method:body===null?'GET':'POST',credentials:'same-origin',cache:'no-store',
    headers:body===null?{}:{'Content-Type':'application/json',...(csrf?{'X-CSRF-Token':csrf}:{})},
    ...(body!==null?{body:JSON.stringify(body)}:{})});
  const parsed=await res.json().catch(()=>null);
  if(!res.ok||!parsed?.ok)throw new Error(parsed?.error?.message||'Local cash order service unavailable.');
  return parsed.data;
}
const localDialog=document.createElement('dialog');
localDialog.className='azh-local-dialog';
localDialog.setAttribute('aria-labelledby','azh-local-heading');
localDialog.innerHTML=[
'<div class="azh-local-head"><div><p>AZHOTTIE · LOCAL SHOPPING</p><h2 id="azh-local-heading">Pay with cash<span>.</span></h2></div><button type="button" class="azh-local-close" aria-label="Close local checkout">×</button></div>',
'<section id="azh-local-order-area"><p id="azh-local-product"></p><p class="azh-local-notice">No online payment. Staff must confirm availability, taxes, and the final amount before pickup or delivery.</p>',
'<form id="azh-local-form"><div class="azh-local-fields">',
'<label>Pickup or delivery<select name="method" id="azh-local-method" required></select></label>',
'<label>Quantity<input name="quantity" type="number" min="1" max="10" value="1" required></label>',
'<label>Full name<input name="name" autocomplete="name" maxlength="120" required></label>',
'<label>Phone number<input name="phone" type="tel" autocomplete="tel" maxlength="32" required></label>',
'<label>Email (optional)<input name="email" type="email" autocomplete="email" maxlength="254"></label>',
'<div id="azh-local-address" class="azh-local-address" hidden><p>Local delivery address</p>',
'<label>Street address<input name="street" autocomplete="address-line1" maxlength="180"></label>',
'<label>Apartment / unit (optional)<input name="unit" autocomplete="address-line2" maxlength="80"></label>',
'<label>City<input name="city" autocomplete="address-level2" maxlength="100"></label>',
'<div class="azh-local-columns"><label>State<input name="state" autocomplete="address-level1" maxlength="2"></label>',
'<label>ZIP code<input name="zip" autocomplete="postal-code" inputmode="numeric" maxlength="5" pattern="[0-9]{5}"></label></div></div>',
'<label>Optional instructions<textarea name="note" rows="2" maxlength="500" placeholder="Pickup or delivery instructions. Never include card details."></textarea></label></div>',
'<p id="azh-local-service" class="azh-local-service"></p><p id="azh-local-estimate" class="azh-local-estimate" aria-live="polite"></p>',
'<p id="azh-local-form-status" class="azh-local-status" role="status" aria-live="polite"></p>',
'<button type="submit" class="azh-local-primary">Request cash order</button></form></section>',
'<section id="azh-local-receipt-area" hidden><h3>Cash order request received</h3><p id="azh-local-receipt-summary"></p>',
'<p>Save this private code to check updates or cancel before acceptance. No email or text is automatically sent.</p>',
'<textarea id="azh-local-receipt-token" readonly rows="3" aria-label="Private receipt code"></textarea>',
'<div class="azh-local-buttons"><button id="azh-local-copy" type="button">Copy receipt code</button>',
'<button id="azh-local-check-receipt" type="button">Check this order</button></div></section>',
'<section id="azh-local-status-area" hidden><p>Enter your private cash order receipt code to check status, final cash amount, or cancel before acceptance.</p>',
'<form id="azh-local-status-form"><label>Private receipt code<textarea id="azh-local-status-token" rows="3" required minlength="64" maxlength="64"></textarea></label>',
'<button type="submit" class="azh-local-primary">Check order</button></form>',
'<p id="azh-local-check-message" class="azh-local-status" role="status" aria-live="polite"></p>',
'<div id="azh-local-status-result" class="azh-local-status-result" hidden></div>',
'<button type="button" id="azh-local-cancel" hidden>Cancel pending unpaid request</button></section>'
].join('');
document.body.append(localDialog);
const localEl=id=>document.getElementById(id);
const localOrderArea=localEl('azh-local-order-area'),localReceiptArea=localEl('azh-local-receipt-area');
const localStatusArea=localEl('azh-local-status-area'),localForm=localEl('azh-local-form');
const localMethod=localEl('azh-local-method'),localAddress=localEl('azh-local-address');
let localOptions=null,localActive=null,localToken='',localReturnFocus=null;
const localOpen=mode=>{
  localReturnFocus=document.activeElement;
  localOrderArea.hidden=mode!=='order';localReceiptArea.hidden=mode!=='receipt';localStatusArea.hidden=mode!=='status';
  if(!localDialog.open)localDialog.showModal();
  document.body.classList.add('azh-local-dialog-open');
};
localDialog.querySelector('.azh-local-close').addEventListener('click',()=>localDialog.close());
localDialog.addEventListener('close',()=>{document.body.classList.remove('azh-local-dialog-open');localReturnFocus?.focus?.();});
function localRefreshButtons(){
  for(const button of localButtons())button.hidden=!(localOptions?.available&&button.dataset.localEnabled==='1');
}
document.addEventListener('azh:local-product-updated',localRefreshButtons);
function localPrice(){
  const method=localMethod.value;localAddress.hidden=method!=='delivery';
  for(const field of localAddress.querySelectorAll('[name=street],[name=city],[name=state],[name=zip]'))
    field.required=method==='delivery';
  const q=Math.max(1,Math.min(10,Number(localForm.elements.namedItem('quantity').value||1)));
  const price=Number(localActive?.dataset.priceCents||0)*q;
  const delivery=method==='delivery'?Number(localOptions?.deliveryFeeCents||0):0;
  localEl('azh-local-estimate').textContent='Estimate before tax: '+money(price+delivery)+
    ' ('+money(price)+' products'+(delivery?' + '+money(delivery)+' delivery':'')+
    '). Staff confirm tax and the final cash amount before acceptance.';
  localEl('azh-local-service').textContent=method==='pickup'
    ?[localOptions?.pickupLocation,localOptions?.pickupAddress,localOptions?.pickupHours,localOptions?.pickupInstructions]
      .filter(Boolean).join(' · ')+' · Do not arrive before staff confirmation.'
    :'Delivery area: '+(localOptions?.deliveryState||'')+' ZIP codes '+(localOptions?.deliveryZips||[]).join(', ')+
      ' · Minimum: '+money(localOptions?.deliveryMinCents)+'. '+(localOptions?.deliveryInstructions||'');
}
function localBegin(button){
  if(!localOptions?.available||button.dataset.localEnabled!=='1')return;
  localActive=button;localToken=token();localForm.reset();localEl('azh-local-form-status').textContent='';
  localEl('azh-local-product').textContent=button.dataset.productTitle||'Local product';
  localMethod.replaceChildren();
  if(localOptions.pickupEnabled)localMethod.add(new Option('Pay cash at pickup','pickup'));
  if(localOptions.deliveryEnabled)localMethod.add(new Option('Pay cash on delivery','delivery'));
  localForm.elements.namedItem('state').value=localOptions.deliveryState||'AZ';
  localPrice();localOpen('order');localForm.elements.namedItem('name').focus();
}
document.addEventListener('click',e=>{
  const button=e.target.closest?.('[data-local-order]');
  if(button){e.preventDefault();localBegin(button);}
  const statusButton=e.target.closest?.('[data-local-status]');
  if(statusButton){
    localEl('azh-local-status-result').hidden=true;localEl('azh-local-cancel').hidden=true;
    localEl('azh-local-check-message').textContent='';localEl('azh-local-status-token').value='';
    localActive=null;localOpen('status');
  }
});
localForm.addEventListener('input',()=>{localToken=token();localPrice();});
localMethod.addEventListener('change',localPrice);
localForm.addEventListener('submit',async e=>{
  e.preventDefault();if(!localActive||!localOptions?.available)return;
  const send=localForm.querySelector('[type=submit]');send.disabled=true;
  localEl('azh-local-form-status').textContent='Submitting unpaid cash order request…';
  try{
    const d=Object.fromEntries(new FormData(localForm).entries());
    const result=await localApi('create',{
      ...d,productId:localActive.dataset.productId,channel:localActive.dataset.channel||'shop',
      promotionId:localActive.dataset.promotionId||'',videoId:localActive.dataset.videoId||'',
      roomId:localActive.dataset.roomId||'',quantity:Number(d.quantity),clientToken:localToken
    },localActive.dataset.csrf||'');
    localEl('azh-local-receipt-token').value=result.receiptToken;
    localEl('azh-local-status-token').value=result.receiptToken;
    localEl('azh-local-receipt-summary').textContent=result.order.reference+' · '+result.order.title+
      ' · '+result.order.method+' · '+money(result.order.estimatedBeforeTaxCents)+' before tax. Awaiting staff confirmation.';
    localOpen('receipt');
  }catch(error){localEl('azh-local-form-status').textContent=error.message;}
  finally{send.disabled=false;}
});
localEl('azh-local-copy').addEventListener('click',async()=>{
  const value=localEl('azh-local-receipt-token').value;
  try{await navigator.clipboard.writeText(value);localEl('azh-local-copy').textContent='Copied';}
  catch{localEl('azh-local-receipt-token').focus();localEl('azh-local-receipt-token').select();}
});
localEl('azh-local-check-receipt').addEventListener('click',()=>{
  localOpen('status');localEl('azh-local-status-form').requestSubmit();
});
localEl('azh-local-status-form').addEventListener('submit',async e=>{
  e.preventDefault();const code=localEl('azh-local-status-token').value.trim();
  localEl('azh-local-check-message').textContent='Checking cash order…';
  const panel=localEl('azh-local-status-result');panel.hidden=true;
  localEl('azh-local-cancel').hidden=true;
  try{
    const order=await localApi('status',{receiptToken:code});
    panel.replaceChildren();
    for(const [tag,label] of [
      ['h3',order.reference+' · '+order.title],
      ['p','Fulfillment: '+order.status.replaceAll('_',' ')+' · Cash payment: '+order.paymentStatus],
      ['p','Estimate before tax: '+money(order.estimatedBeforeTaxCents)],
      ...(order.totalCashDueCents===null?[]:[['p','Confirmed cash due including tax: '+money(order.totalCashDueCents)]]),
      ...(order.pickupDetails?[['p','Pickup: '+order.pickupDetails]]:[]),['p',order.notice]
    ]){const el=document.createElement(tag);el.textContent=label;panel.append(el);}
    panel.hidden=false;
    localEl('azh-local-cancel').hidden=!(order.status==='pending'&&order.paymentStatus==='due');
    localEl('azh-local-check-message').textContent='Status updated.';
  }catch(error){localEl('azh-local-check-message').textContent=error.message;}
});
localEl('azh-local-cancel').addEventListener('click',async()=>{
  if(!window.confirm('Cancel this pending unpaid cash order request?'))return;
  const csrf=localActive?.dataset.csrf||document.querySelector('[data-local-csrf]')?.dataset.localCsrf||'';
  try{await localApi('cancel',{receiptToken:localEl('azh-local-status-token').value.trim()},csrf);
    localEl('azh-local-check-message').textContent='Order cancelled.';
    localEl('azh-local-status-form').requestSubmit();
  }catch(error){localEl('azh-local-check-message').textContent=error.message;}
});
void localApi('options').then(o=>{localOptions=o;localRefreshButtons();})
  .catch(()=>{localOptions=null;localRefreshButtons();});
