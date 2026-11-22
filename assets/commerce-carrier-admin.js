/* v1.34: EasyPost rates & labels, confined to the existing staff Shipping cards.
 * The application never purchases postage without a specific staff click.
 */
const carrierRoot=document.getElementById('azh-shipping-admin');
if(carrierRoot){
  const endpoint=new URL('commerce-carrier-api.php',document.baseURI);
  const csrf=carrierRoot.dataset.csrf||'';
  const money=c=>new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format((Number(c)||0)/100);
  let capabilities=null,working=false;
  const message=(value,error=false)=>{
    const field=document.getElementById('azh-shipping-status');
    if(field){field.textContent=value;field.dataset.error=error?'true':'false';}
  };
  async function request(action,body=null){
    const url=new URL(endpoint);url.searchParams.set('action',action);
    const response=await fetch(url,{method:body===null?'GET':'POST',credentials:'same-origin',cache:'no-store',
      headers:body===null?{}:{'Content-Type':'application/json','X-CSRF-Token':csrf},
      ...(body===null?{}:{body:JSON.stringify(body)})});
    const result=await response.json().catch(()=>null);
    if(!response.ok||!result?.ok)
      throw new Error(result?.error?.message||'Carrier provider operation failed.');
    return result.data;
  }
  const make=(tag,value='',css='')=>{
    const element=document.createElement(tag);element.textContent=String(value);
    if(css)element.className=css;
    return element;
  };
  const add=(parent,tag,value='',css='')=>{
    const element=make(tag,value,css);parent.append(element);return element;
  };
  const button=(parent,label,handler)=>{
    const node=add(parent,'button',label);node.type='button';
    node.addEventListener('click',()=>void handler());return node;
  };
  async function submit(action,body,refresh,success){
    if(working)return;
    working=true;message('Contacting EasyPost carrier service…');
    try{
      const result=await request(action,body);
      await refresh();
      message(success);
      return result;
    }catch(error){
      message(error?.message||'Carrier service is unavailable.',true);
    }finally{working=false;}
  }
  function quoteFields(host,row){
    const form=add(host,'form','','azh-carrier-workflow');
    const caption=add(form,'p','Verify the packed parcel weight and dimensions before requesting real rates. Multiple items may need a larger box.','azh-carrier-hint');
    const fields=add(form,'div','','azh-carrier-fields');
    const parcel=row.parcel||{};
    const mapping=[
      ['weightGrams','Weight (g)',parcel.weightGrams||0],
      ['lengthMm','Length (mm)',parcel.lengthMm||0],
      ['widthMm','Width (mm)',parcel.widthMm||0],
      ['heightMm','Height (mm)',parcel.heightMm||0]
    ];
    for(const [key,label,initial] of mapping){
      const holder=add(fields,'label',label);
      const input=document.createElement('input');
      input.name=key;input.type='number';input.min='1';input.max=key==='weightGrams'?'150000':'5000';
      input.step='1';input.required=true;input.value=String(initial);holder.append(input);
    }
    const confirm=add(form,'label','','azh-carrier-confirm');
    const checked=document.createElement('input');checked.type='checkbox';
    confirm.append(checked,document.createTextNode('I checked this physical parcel’s actual weight, dimensions, and ship-from address.'));
    const btn=add(form,'button',row.carrierIntegration?.quote?'Refresh live carrier rates':'Get live USPS / UPS / FedEx rates');
    btn.type='submit';
    form.addEventListener('submit',async e=>{
      e.preventDefault();
      if(!checked.checked){message('Verify the physical parcel details before quoting.',true);return;}
      const body={id:row.id,confirmParcel:true,refresh:!!row.carrierIntegration?.quote};
      for(const field of fields.querySelectorAll('input'))body[field.name]=Number(field.value);
      if(!Number.isInteger(body.weightGrams)||body.weightGrams<=0){
        message('Enter a positive package weight.',true);return;
      }
      await submit('quote',body,()=>refreshCard(row),'Carrier rates updated from the connected provider.');
    });
    return form;
  }
  // The parent card owns refresh; pass it without placing addresses into state.
  let activeRefresh=()=>{};
  function refreshCard(){return activeRefresh();}
  function decorate(host,row,refresh){
    activeRefresh=refresh;
    host.replaceChildren();
    add(host,'h4','Carrier rates & labels','azh-carrier-title');
    if(!capabilities?.configured){
      add(host,'p','EasyPost is not configured. Set a private API key and ship-from address to enable carrier quotes. Existing manual tracking remains available.','azh-carrier-hint');
      return;
    }
    add(host,'p','EasyPost '+capabilities.mode.toUpperCase()+
      ' · Carrier rates are actual API results from the account’s enabled carriers.'+
      (capabilities.mode==='test'?' Test labels are not valid for real postage.':' Production labels may incur real charges.'),
      'azh-carrier-hint');
    const label=row.carrierIntegration?.label;
    if(label){
      add(host,'p','Label: '+label.status.replaceAll('_',' ')+' · '+label.carrier+' / '+label.service+
        ' · '+money(label.rateCents)+' · '+label.mode,'azh-carrier-hint');
      if(label.status==='purchased'){
        if(label.labelUrl){
          const a=add(host,'a','Open purchased shipping label','azh-carrier-label-link');
          a.href=label.labelUrl;a.target='_blank';a.rel='noopener noreferrer external nofollow';
        }
        if(label.trackingNumber)add(host,'p','Tracking: '+label.trackingNumber+' · Carrier status: '+
          (row.carrierTrackingStatus||label.trackingStatus||'unknown'),'azh-carrier-hint');
        add(host,'p','Printing the label does not mark the package shipped. Confirm physical carrier handoff separately.','azh-carrier-hint');
      }else{
        add(host,'p','The provider charge may have succeeded even if the response timed out. Never purchase this label again until the existing shipment is reconciled.','azh-carrier-hint');
        button(host,'Reconcile existing EasyPost label',async()=>{
          await submit('reconcile',{id:row.id},refresh,'Provider shipment checked without making another purchase.');
        });
      }
      return;
    }
    if(!['awaiting_dispatch','packed'].includes(row.status)){
      add(host,'p','Carrier rating is unavailable after shipment dispatch.','azh-carrier-hint');
      return;
    }
    const quote=row.carrierIntegration?.quote;
    if(row.status==='awaiting_dispatch')
      add(host,'p','You can compare rates now; pack and verify the parcel before buying a label.','azh-carrier-hint');
    quoteFields(host,row);
    if(!quote)return;
    const expired=Date.parse(quote.expiresAt)<=Date.now();
    if(expired){
      add(host,'p','These rates have expired. Confirm the parcel and refresh the carrier quote.','azh-carrier-hint');
      return;
    }
    const rates=add(host,'div','','azh-carrier-rates');
    add(rates,'p','Quoted carrier options · '+quote.mode+' · expires '+quote.expiresAt,
      'azh-carrier-hint');
    for(const rate of quote.rates||[]){
      const panel=add(rates,'div','','azh-carrier-rate');
      const label=add(panel,'div','','');
      add(label,'strong',rate.carrier+' '+rate.service);
      add(label,'p',money(rate.rateCents)+
        (rate.deliveryDays===null?' · Delivery estimate unavailable':' · Approximately '+rate.deliveryDays+' days'),
        'azh-carrier-hint');
      const gap=rate.rateCents-Number(row.shippingFeeCents||0);
      add(label,'small',gap>0?
        'Label costs '+money(gap)+' more than customer’s shipping charge.':
        'Label cost compared with collected shipping: '+money(gap),'azh-carrier-hint');
      const buy=button(panel,'Buy '+rate.carrier+' label · '+money(rate.rateCents),async()=>{
        if(!capabilities.purchaseEnabled){
          message('Label purchases are disabled in private EasyPost settings.',true);return;
        }
        const descriptor=capabilities.mode==='production'?'REAL POSTAGE CHARGE':'TEST LABEL';
        const sure=window.confirm('Authorize '+descriptor+' of '+money(rate.rateCents)+
          ' for '+rate.carrier+' '+rate.service+'?\nThis is a carrier-account charge, separate from the customer’s checkout amount.');
        if(!sure)return;
        await submit('buy',{id:row.id,rateId:rate.id,expectedCents:rate.rateCents,
          revision:quote.revision,confirmPurchase:true},refresh,
          'Carrier label recorded. Print it and confirm actual carrier handoff.');
      });
      buy.disabled=working||row.status!=='packed'||!capabilities.purchaseEnabled||
        rate.rateCents>capabilities.maxLabelCents;
      if(rate.rateCents>capabilities.maxLabelCents)buy.title='Above your private label purchase limit.';
      else if(row.status!=='packed')buy.title='Mark parcel packed before purchasing.';
    }
  }
  window.azCarrierEnhance=decorate;
  void request('status').then(state=>{
    capabilities=state;
    const status=document.getElementById('azh-carrier-status');
    if(status)status.textContent=state.configured
      ?'EasyPost '+state.mode.toUpperCase()+' configured · Label buying '+(state.purchaseEnabled?'enabled (staff approval required)':'disabled')+
        ' · Tracking webhook '+(state.webhookConfigured?'configured':'not configured')
      :'EasyPost carrier rates are OFF. Configure server-side credentials before quoting.';
    document.getElementById('azh-shipping-refresh')?.click();
  }).catch(err=>{
    const status=document.getElementById('azh-carrier-status');
    if(status)status.textContent='Carrier configuration could not be checked. '+err.message;
  });
}
