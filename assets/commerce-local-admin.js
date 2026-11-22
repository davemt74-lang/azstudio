/* Staff-only local commerce controls. Contact info is fetched on demand,
 * stays inside this admin panel and never enters Agent Brain/Chat/History. */
const localRoot=document.getElementById('azh-local-admin');
if(localRoot){
  const csrf=localRoot.dataset.csrf||'',endpoint=new URL('commerce-local-api.php',document.baseURI);
  const $=id=>document.getElementById(id);
  const money=c=>new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(Number(c||0)/100);
  const cents=value=>Math.round(Number(value)*100);
  let orders=[],busy=false,selected=null;
  function note(message,error=false){const el=$('azh-local-admin-status');el.textContent=message;el.dataset.error=error?'true':'false';}
  async function call(action,body=null){
    const url=new URL(endpoint);url.searchParams.set('action',action);
    const response=await fetch(url,{method:body===null?'GET':'POST',
      credentials:'same-origin',cache:'no-store',
      headers:body===null?{}:{'Content-Type':'application/json','X-CSRF-Token':csrf},
      ...(body===null?{}:{body:JSON.stringify(body)})});
    const data=await response.json().catch(()=>null);
    if(!response.ok||!data?.ok)throw new Error(data?.error?.message||'Cash order request failed.');
    return data.data;
  }
  function element(parent,tag,cls,value){const item=make(tag,cls,value);parent.append(item);return item;}
  const button=(parent,label,handle)=>{
    const b=make('button','azh-commerce-small',label);b.type='button';parent.append(b);
    b.addEventListener('click',()=>void handle());return b;
  };
  async function mutate(decision,row,extra={},confirmCash=false){
    if(busy)return;
    busy=true;note('Recording staff action…');
    try{
      const result=await call('transition',{id:row.id,revision:row.revision,decision,...extra,confirmCash});
      selected=null;$('azh-local-private-details').hidden=true;
      await refreshOrders();
      note('Saved: '+decision.replaceAll('_',' ')+'. Staff actions are recorded in the existing Agent History.');
      return result;
    }catch(error){note(error.message,true);}
    finally{busy=false;}
  }
  function orderActions(card,row){
    const actions=element(card,'div','azh-commerce-actions','');
    if(row.status==='pending'){
      const label=element(actions,'label','azh-local-admin-tax','Tax to confirm (USD): ');
      const input=make('input');input.type='number';input.step='0.01';input.min='0';input.max='200000';
      input.value=((row.taxCents??0)/100).toFixed(2);input.setAttribute('aria-label','Order tax amount');
      label.append(input);
      button(actions,row.quoteConfirmed?'Update quoted total':'Confirm quoted total',async()=>{
        const tax=cents(input.value);
        if(!Number.isSafeInteger(tax)||tax<0){note('Enter a valid nonnegative tax amount.',true);return;}
        if(!window.confirm('Confirm this cash order total, including tax, for the customer?'))return;
        await mutate('quote',row,{taxCents:tax});
      });
      if(row.quoteConfirmed)button(actions,'Accept cash order',async()=>{
        if(window.confirm('Confirm this pickup/delivery order can be fulfilled?'))await mutate('accept',row);
      });
    }
    if(row.status==='accepted')button(actions,row.method==='pickup'?'Mark ready for pickup':'Dispatch local delivery',async()=>{
      if(window.confirm('Confirm staff has prepared this order for '+row.method+'?'))
        await mutate(row.method==='pickup'?'ready':'out_for_delivery',row);
    });
    if(['ready','out_for_delivery'].includes(row.status)&&row.paymentStatus==='due')
      button(actions,'Cash collected',async()=>{
        if(window.confirm('Have you physically received the full confirmed cash amount? This records payment and creator commission.'))
          await mutate('collect',row,{},true);
      });
    if(['ready','out_for_delivery'].includes(row.status)&&row.paymentStatus==='collected')
      button(actions,'Mark fulfilled',async()=>{
        if(window.confirm('Confirm the customer received the item(s)?'))await mutate('complete',row);
      });
    if(row.paymentStatus==='due'&&['pending','accepted','ready','out_for_delivery'].includes(row.status))
      button(actions,'Cancel unpaid order',async()=>{
        if(window.confirm('Cancel this unpaid order request?'))await mutate('cancel',row);
      });
    if(row.paymentStatus==='collected'&&['ready','out_for_delivery','fulfilled'].includes(row.status))
      button(actions,'Record cash refund',async()=>{
        const reason=window.prompt('Why was the full cash amount returned to the customer? (minimum 8 characters)','');
        if(reason===null)return;
        if(reason.trim().length<8){note('Enter a refund reason (at least 8 characters).',true);return;}
        if(window.confirm('Confirm the full cash refund was actually handed back?'))
          await mutate('refund',row,{reason},true);
      });
    button(actions,'View private customer details',async()=>{
      const panel=$('azh-local-private-details');panel.replaceChildren();panel.hidden=false;
      try{
        const data=await call('details',{id:row.id});
        selected=row.id;
        element(panel,'h3','',row.reference+' · Private contact');
        const contact=data.contact||{};
        for(const key of ['name','phone','email','street','unit','city','state','zip','note']){
          if(contact[key])element(panel,'p','',key[0].toUpperCase()+key.slice(1)+': '+contact[key]);
        }
        if(data.pickupDetails)element(panel,'p','', 'Pickup: '+data.pickupDetails);
        element(panel,'small','','Only staff can view this information. Do not paste it into public Agent Chat.');
        button(panel,'Hide private details',()=>{panel.hidden=true;selected=null;panel.replaceChildren();});
        panel.scrollIntoView({behavior:'smooth',block:'nearest'});
      }catch(error){panel.textContent=error.message;}
    });
  }
  function paintOrders(){
    const list=$('azh-local-orders-list');list.replaceChildren();
    let pending=0,collected=0,delivery=0;
    for(const row of orders){
      if(row.status==='pending')pending++;
      if(row.paymentStatus==='collected'&&row.status!=='fulfilled')collected++;
      if(row.status==='out_for_delivery')delivery++;
      const card=element(list,'article','azh-commerce-card','');
      element(card,'small','azh-commerce-eyebrow',row.reference+' · '+row.method.toUpperCase());
      element(card,'h3','',row.title+' · '+row.quantity+' item(s)');
      element(card,'p','',row.status.replaceAll('_',' ')+' · Cash '+row.paymentStatus+
        ' · '+(row.totalDueCents===null?'Estimated '+money(row.subtotalCents+row.deliveryFeeCents)+' before tax':
          'Confirmed total '+money(row.totalDueCents)));
      if(row.method==='delivery')element(card,'small','', 'Delivery ZIP: '+row.zip);
      if(row.hasAffiliate)element(card,'small','', 'Approved creator attribution · Estimated commission '+money(row.commissionCents));
      orderActions(card,row);
    }
    if(!orders.length)element(list,'p','azh-commerce-card','No cash pickup or delivery requests yet.');
    $('azh-local-queue-summary').textContent=pending+' awaiting review · '+delivery+' delivery in progress · '+
      collected+' collected but not fulfilled';
  }
  async function refreshOrders(){
    try{orders=await call('orders');paintOrders();}
    catch(error){note(error.message,true);}
  }
  $('azh-local-refresh').addEventListener('click',()=>void refreshOrders());
  $('azh-local-edit-products').addEventListener('click',()=>{
    document.querySelector('[data-tab=products]')?.click();
    document.getElementById('commerce-product-form')?.scrollIntoView({behavior:'smooth',block:'start'});
  });
  const tab=document.querySelector('[data-tab=local]');
  tab?.addEventListener('click',()=>{void refreshOrders();});
  if(tab)void refreshOrders();
  setInterval(()=>{
    const panel=localRoot.closest('[data-panel=local]');
    if(panel&&!panel.hidden&&document.visibilityState==='visible'&&!busy&&selected===null)void refreshOrders();
  },30000);
}
