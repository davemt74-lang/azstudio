/* Private US shipping details are sent to the quote API only; not stored locally. */
if(document.querySelector('[data-stripe-checkout]')){
const dialog=document.createElement('dialog');dialog.className='azh-smart-shipping-dialog';
dialog.setAttribute('aria-labelledby','azh-smart-shipping-heading');
dialog.innerHTML=`
<header class="azh-smart-shipping-head"><div><small>AZHOTTIE · SHIPPING CHECKOUT</small>
<h2 id="azh-smart-shipping-heading">Choose shipping<span>.</span></h2></div>
<button class="azh-smart-shipping-close" type="button" aria-label="Close">×</button></header>
<p id="azh-smart-shipping-product"></p>
<p class="azh-smart-shipping-notice">Rates are quoted for the address you enter. You must confirm the same address in Stripe Checkout. Any applicable sales tax is calculated there; carrier quotes expire in 10 minutes.</p>
<form id="azh-smart-shipping-form" class="azh-smart-shipping-fields">
<label>Quantity<input name="quantity" type="number" min="1" max="10" step="1" value="1" required></label>
<label>Full name<input name="name" autocomplete="name" maxlength="120" required></label>
<label>Phone<input name="phone" type="tel" autocomplete="tel" maxlength="24" required></label>
<label>Street address<input name="street" autocomplete="shipping address-line1" maxlength="180" required></label>
<label>Apartment or unit<input name="unit" autocomplete="shipping address-line2" maxlength="100"></label>
<label>City<input name="city" autocomplete="shipping address-level2" maxlength="120" required></label>
<div class="azh-smart-shipping-columns">
<label>State<input name="state" autocomplete="shipping address-level1" maxlength="2" placeholder="AZ" required></label>
<label>ZIP<input name="zip" autocomplete="shipping postal-code" maxlength="10" pattern="[0-9]{5}(-[0-9]{4})?" placeholder="85001" required></label></div>
<button type="submit" class="azh-smart-shipping-primary">Get live USPS / UPS / FedEx rates</button>
</form>
<p id="azh-smart-shipping-status" role="status" aria-live="polite"></p>
<section id="azh-smart-shipping-rates" hidden><h3>Choose your carrier</h3>
<div id="azh-smart-shipping-rate-list"></div><p id="azh-smart-shipping-total"></p></section>`;
document.body.append(dialog);
const $=id=>document.getElementById(id);
const form=$('azh-smart-shipping-form'),status=$('azh-smart-shipping-status'),
rates=$('azh-smart-shipping-rates'),rateList=$('azh-smart-shipping-rate-list');
let active=null,quote=null,quoteToken='',selectedRate='',busy=false,focusReturn=null;
const fresh=()=>{const a=new Uint8Array(16);crypto.getRandomValues(a);return [...a].map(n=>n.toString(16).padStart(2,'0')).join('');};
const money=v=>new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(Number(v||0)/100);
const announce=(text,error=false)=>{status.textContent=text;status.dataset.error=error?'true':'false';};
function reset(){quote=null;selectedRate='';quoteToken=fresh();rates.hidden=true;rateList.replaceChildren();if(active)active.dataset.checkoutToken='';}
function open(checkout){
 if(!checkout||checkout.dataset.shippingMode!=='carrier')return;
 active=checkout;focusReturn=document.activeElement;form.reset();reset();announce('');
 $('azh-smart-shipping-product').textContent=(checkout.dataset.productTitle||'Product')+' · '+money(checkout.dataset.priceCents)+' each';
 if(!dialog.open)dialog.showModal();document.body.classList.add('azh-smart-shipping-open');
 form.elements.namedItem('name').focus();
}
window.azShippingModalOpen=open;
dialog.querySelector('.azh-smart-shipping-close').addEventListener('click',()=>dialog.close());
dialog.addEventListener('close',()=>{document.body.classList.remove('azh-smart-shipping-open');focusReturn?.focus?.();focusReturn=null;});
form.addEventListener('input',()=>{if(quote){reset();announce('Destination changed. Refresh shipping rates.');}});
function render(){
  rates.hidden=false;rateList.replaceChildren();
  const subtotal=Number(active?.dataset.priceCents||0)*Number(quote.quantity||1);
  for(const item of quote.rates){
    const row=document.createElement('article');row.className='azh-smart-shipping-rate';
    const info=document.createElement('div'),name=document.createElement('strong'),
    detail=document.createElement('p');
    name.textContent=item.carrier+' · '+item.service;
    detail.textContent=money(item.rateCents)+' shipping'+(item.deliveryDays===null?'':' · ~'+item.deliveryDays+' transit days');
    info.append(name,detail);
    const b=document.createElement('button');b.type='button';b.textContent='Choose '+item.carrier;
    b.addEventListener('click',()=>void pay(item));
    row.append(info,b);rateList.append(row);
  }
  $('azh-smart-shipping-total').textContent='Merchandise '+money(subtotal)+' + selected shipping + tax at Stripe Checkout.';
}
form.addEventListener('submit',async e=>{
 e.preventDefault();if(busy||!active)return;
 busy=true;const button=form.querySelector('[type=submit]');button.disabled=true;
 announce('Requesting real shipping rates…');
 try{
  const details=Object.fromEntries(new FormData(form).entries());
  const qty=Number(details.quantity);
  if(!Number.isInteger(qty)||qty<1||qty>10)throw new Error('Choose between 1 and 10 items.');
  const url=new URL('commerce-shipping-quote-api.php',document.baseURI);
  url.searchParams.set('action','quote');
  const res=await fetch(url,{method:'POST',credentials:'same-origin',cache:'no-store',
    headers:{'Content-Type':'application/json','X-CSRF-Token':active.dataset.csrf||''},
    body:JSON.stringify({...details,quantity:qty,productId:active.dataset.productId,
      country:'US',quoteToken})});
  const response=await res.json().catch(()=>null);
  if(!res.ok||!response?.ok)throw new Error(response?.error?.message||'Live shipping rates unavailable.');
  if(!response.data?.quoteId||!Array.isArray(response.data.rates)||!response.data.rates.length)
    throw new Error('No carrier returned a rate for this address.');
  quote=response.data;render();announce('Choose a service to continue to secure checkout.');
 }catch(err){reset();announce(err?.message||'Carrier rates unavailable.',true);}
 finally{busy=false;button.disabled=false;}
});
async function pay(rate){
 if(busy||!quote||!active)return;
 if(Date.parse(quote.expiresAt)<=Date.now()){reset();announce('Shipping quote expired. Get fresh rates.',true);return;}
 if(typeof window.azCheckoutStart!=='function'){announce('Stripe Checkout unavailable. Refresh the page.',true);return;}
 if(selectedRate!==rate.id){active.dataset.checkoutToken='';selectedRate=rate.id;}
 busy=true;for(const b of rateList.querySelectorAll('button'))b.disabled=true;
 const subtotal=Number(active.dataset.priceCents||0)*Number(quote.quantity);
 announce('Preparing '+rate.carrier+' Checkout · '+money(subtotal+rate.rateCents)+' before tax…');
 try{
   await window.azCheckoutStart(active,{shippingQuoteId:quote.quoteId,
     shippingRateId:rate.id,quantity:Number(quote.quantity)});
 }catch(err){announce(err?.message||'Checkout could not be started.',true);}
 finally{busy=false;for(const b of rateList.querySelectorAll('button'))b.disabled=false;}
}
}
