/* Admin-only fulfillment workspace; address is retrieved only on an explicit staff click. */
const shippingRoot=document.getElementById('azh-shipping-admin');
if(shippingRoot){
  const csrf=shippingRoot.dataset.csrf||'',el=id=>document.getElementById(id);
  const endpoint=new URL('commerce-fulfillment-api.php',document.baseURI);
  let rows=[],working=false;
  const money=c=>new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(Number(c||0)/100);
  async function api(action,data=null){
    const url=new URL(endpoint);url.searchParams.set('action',action);
    const res=await fetch(url,{method:data===null?'GET':'POST',credentials:'same-origin',cache:'no-store',
      headers:data===null?{}:{'Content-Type':'application/json','X-CSRF-Token':csrf},
      ...(data===null?{}:{body:JSON.stringify(data)})});
    const result=await res.json().catch(()=>null);
    if(!res.ok||!result?.ok)throw new Error(result?.error?.message||'Shipping request failed.');
    return result.data;
  }
  const message=(s,err=false)=>{el('azh-shipping-status').textContent=s;el('azh-shipping-status').dataset.error=err?'true':'false';};
  const make=(tag,value='',className='')=>{const node=document.createElement(tag);node.textContent=value;node.className=className;return node;};
  const add=(parent,tag,value='',className='')=>{const node=make(tag,value,className);parent.append(node);return node;};
  const control=(parent,label,cb)=>{
    const b=add(parent,'button',label,'azh-commerce-small');b.type='button';
    b.addEventListener('click',()=>void cb());return b;
  };
  async function action(row,decision,data={},extraConfirmation=false){
    if(working)return;
    if(extraConfirmation&&!window.confirm('Confirm this shipment status from actual staff evidence? No carrier events will be checked automatically.'))return;
    working=true;message('Updating shipment…');
    try{
      await api('transition',{id:row.id,revision:row.revision,decision,...data});
      el('azh-shipping-private').hidden=true;el('azh-shipping-private').replaceChildren();
      await refresh();message('Shipping status updated in the shared Commerce Agent.');
    }catch(e){message(e.message,true);}finally{working=false;}
  }
  function render(){
    const list=el('azh-shipping-orders');list.replaceChildren();
    const waiting=rows.filter(r=>r.status==='awaiting_dispatch'||r.status==='packed').length,
      transit=rows.filter(r=>r.status==='shipped').length,
      problem=rows.filter(r=>r.status==='exception').length;
    el('azh-shipping-counts').textContent=waiting+' to pack/ship · '+transit+' marked shipped · '+problem+' exceptions';
    for(const row of rows){
      const card=add(list,'article','','azh-commerce-card');
      add(card,'span',row.status.replaceAll('_',' ').toUpperCase(),'azh-shipping-shipment-status');
      add(card,'h3',row.title);
      add(card,'p',row.quantity+' item(s) · '+row.provider+' '+row.paymentState+
        ' · Shipping charged '+money(row.shippingFeeCents)+' · '+row.id.slice(0,10).toUpperCase());
      if(row.trackingNumber){
        const line=add(card,'p','Carrier: '+(row.carrier||'other').toUpperCase()+
          ' · Tracking: '+row.trackingNumber);
        if(['usps','ups','fedex'].includes(row.carrier) && /^[A-Za-z0-9][A-Za-z0-9_-]{5,69}$/.test(row.trackingNumber)){
          const links={usps:'https://tools.usps.com/go/TrackConfirmAction?tLabels=',
            ups:'https://www.ups.com/track?tracknum=',
            fedex:'https://www.fedex.com/fedextrack/?trknbr='};
          const a=add(line,'a',' Open carrier tracking');a.href=links[row.carrier]+encodeURIComponent(row.trackingNumber);
          a.target='_blank';a.rel='noopener noreferrer';
        }
      }
      const controls=add(card,'div','','azh-commerce-actions');
      control(controls,'View private shipping address',async()=>{
        const panel=el('azh-shipping-private');panel.hidden=false;panel.replaceChildren();
        try{
          const result=await api('details',{id:row.id});const a=result.address;
          add(panel,'h3','Shipping destination · private staff view');
          for(const key of ['name','street','unit','city','state','zip','country']){
            if(a[key])add(panel,'p',key[0].toUpperCase()+key.slice(1)+': '+a[key]);
          }
          add(panel,'p','Personal delivery data is never sent to creators, Brain or Agent Chat.');
          control(panel,'Hide address',()=>{panel.hidden=true;panel.replaceChildren();});
          panel.scrollIntoView({behavior:'smooth',block:'nearest'});
        }catch(e){panel.textContent=e.message;}
      });
      if(row.status==='awaiting_dispatch'){
        control(controls,'Mark packed',()=>action(row,'pack'));
      }
      if(row.status==='packed'&&row.carrierIntegration?.label?.status==='purchased'){
        control(controls,'Confirm handed to carrier',async()=>{
          const label=row.carrierIntegration.label;
          if(window.confirm('Has staff physically handed this labeled parcel to '+label.carrier+'?'))
            await action(row,'ship',{
              carrier:label.carrier==='FedEx'?'fedex':label.carrier.toLowerCase(),
              trackingNumber:label.trackingNumber
            });
        });
      }
      if(row.status==='packed'&&!row.carrierIntegration?.label){
        const form=add(card,'form','','azh-shipping-form');
        const field=(name,label)=>{
          const wrap=add(form,'label',label),input=document.createElement(name==='carrier'?'select':'input');
          input.name=name;wrap.append(input);return input;
        };
        const carrier=field('carrier','Carrier');
        for(const [name,label] of [['usps','USPS'],['ups','UPS'],['fedex','FedEx'],['other','Other']])carrier.add(new Option(label,name));
        const tracking=field('trackingNumber','Tracking number');
        tracking.required=true;tracking.minLength=6;tracking.maxLength=70;tracking.pattern='[A-Za-z0-9][A-Za-z0-9_-]{5,69}';
        const button=add(form,'button','Mark shipped','azh-commerce-small');button.type='submit';
        form.addEventListener('submit',e=>{
          e.preventDefault();
          if(window.confirm('Did staff hand this parcel to the carrier? Tracking details are supplied manually.'))
            void action(row,'ship',{carrier:carrier.value,trackingNumber:tracking.value.trim()});
        });
      }
      if(row.status==='shipped'){
        control(controls,'Mark delivered (staff confirmation)',()=>action(row,'deliver',{confirmDelivered:true},true));
      }
      if(['awaiting_dispatch','packed','shipped'].includes(row.status)){
        control(controls,'Flag an exception',()=>action(row,'exception',{},true));
      }
      if(row.status==='exception'){
        add(card,'p','Requires manual review. Verify the carrier and customer before reopening.');
        control(controls,'Reopen for packing',()=>action(row,'reopen',{},true));
      }
      const providerBox=add(card,'section','','azh-carrier-workflow');
      if(typeof window.azCarrierEnhance==='function')
        window.azCarrierEnhance(providerBox,row,()=>void refresh());
    }
    if(!rows.length)add(list,'p','No Stripe-paid shipping orders yet. Shipments appear only after signed payment events and a valid address.','azh-commerce-card');
  }
  async function refresh(){
    try{rows=await api('shipments');render();message('Shipment records updated.');}
    catch(e){message(e.message,true);}
  }
  el('azh-shipping-refresh').addEventListener('click',()=>void refresh());
  el('azh-shipping-edit-products').addEventListener('click',()=>{
    document.querySelector('[data-tab=products]')?.click();
    document.getElementById('azh-product-shipping-settings')?.scrollIntoView({behavior:'smooth',block:'center'});
  });
  document.querySelector('[data-tab=shipping]')?.addEventListener('click',()=>void refresh());
  setInterval(()=>{
    const pane=shippingRoot.closest('[data-panel=shipping]');
    if(pane&&!pane.hidden&&document.visibilityState==='visible'&&!working
       &&el('azh-shipping-private').hidden)void refresh();
  },30000);
}
