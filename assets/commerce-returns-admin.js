/* Staff return/exchange queue; all financial and inventory transitions are server-authorized. */
const returnsRoot=document.getElementById('azh-returns-admin');
if(returnsRoot){
  const csrf=returnsRoot.dataset.csrf||'',endpoint=new URL('commerce-returns-api.php',document.baseURI);
  const list=document.getElementById('azh-returns-list');
  const message=document.getElementById('azh-returns-status');
  const detail=document.getElementById('azh-returns-private');
  const form=document.getElementById('azh-return-intake');
  let cases=[],busy=false,orders=[];
  const say=(value,error=false)=>{message.textContent=value;message.dataset.error=error?'true':'false';};
  const create=(tag,value,cls='')=>{
    const node=document.createElement(tag);node.textContent=String(value);
    if(cls)node.className=cls;return node;
  };
  const add=(parent,tag,value,cls='')=>{
    const node=create(tag,value,cls);parent.append(node);return node;
  };
  function button(parent,label,callback){
    const node=add(parent,'button',label,'azh-commerce-small');node.type='button';
    node.addEventListener('click',()=>void callback());return node;
  }
  async function api(action,data=null){
    const url=new URL(endpoint);url.searchParams.set('action',action);
    const response=await fetch(url,{method:data===null?'GET':'POST',cache:'no-store',credentials:'same-origin',
      headers:data===null?{}:{'Content-Type':'application/json','X-CSRF-Token':csrf},
      ...(data===null?{}:{body:JSON.stringify(data)})});
    const body=await response.json().catch(()=>null);
    if(!response.ok||!body?.ok)throw new Error(body?.error?.message||'Return request failed.');
    return body.data;
  }
  async function mutation(action,data,notice){
    if(busy)return;busy=true;say('Recording verified staff action…');
    try{
      await api(action,data);detail.hidden=true;detail.replaceChildren();
      await refresh();say(notice);
    }catch(err){say(err?.message||'Return action could not be completed.',true);}
    finally{busy=false;}
  }
  const promptText=(label,value='')=>{
    const result=window.prompt(label,value);
    return result===null?null:result.trim();
  };
  const verifyStaff=(label)=>window.confirm(label+' This changes a return case and may hold creator commissions. Nothing is refunded automatically.');
  function controls(parent,row){
    const buttons=add(parent,'div','','azh-commerce-actions');
    const transition=(decision,extras={},label='Return case updated.')=>
      mutation('transition',{id:row.id,revision:row.revision,decision,...extras},label);
    if(row.status==='requested'){
      button(buttons,'Verify & authorize',()=>{
        const evidence=promptText('Enter the verified order and customer-identity evidence (internal reference; no card details):');
        if(evidence===null||evidence.length<8){say('Enter at least eight characters of verification evidence.',true);return;}
        if(verifyStaff('Authorize this return?'))
          void transition('authorize',{confirm:true,evidence},'Return authorized. Related creator commissions are on hold.');
      });
      button(buttons,'Reject request',()=>{
        if(window.confirm('Reject this return request without refund or inventory changes?'))
          void transition('reject',{},'Return request rejected.');
      });
    }
    if(row.status==='authorized'){
      button(buttons,'Mark goods received',()=>{
        if(window.confirm('Have the returned items physically arrived?'))
          void transition('receive',{},'Goods received. Staff inspection is required before restocking.');
      });
      button(buttons,'Cancel authorization',()=>{
        if(window.confirm('Cancel this return authorization before goods are received?'))
          void transition('cancel',{},'Return authorization cancelled.');
      });
    }
    if(row.status==='received'){
      button(buttons,'Inspect & restock',()=>{
        const condition=promptText('Condition: resellable, unopened, damaged, or other','resellable');
        if(condition===null)return;
        const accepted=['resellable','unopened','damaged','other'];
        if(!accepted.includes(condition)){say('Choose an allowed inspection condition.',true);return;}
        const quantity=promptText('How many of the '+row.quantity+' physically received units are safe to restock?','0');
        if(quantity===null)return;
        const qty=Number(quantity);
        if(!Number.isInteger(qty)||qty<0||qty>Number(row.quantity)||(condition==='damaged'&&qty>0)){
          say('Only inspected resellable units may be restocked.',true);return;
        }
        if(window.confirm('Confirm physical inspection and restock '+qty+' unit(s)? This inventory change cannot be repeated.'))
          void transition('inspect',{condition,restockQuantity:qty},'Inspection saved and eligible inventory reconciled.');
      });
      button(buttons,'Reject after receipt',()=>{
        if(window.confirm('Reject this case after receiving the goods? Confirm appropriate customer arrangements separately.'))
          void transition('reject',{},'Case rejected by staff.');
      });
    }
    if(row.status==='inspected'&&row.kind==='refund')
      button(buttons,'Approve refund',()=>{
        if(verifyStaff('Approve a refund for this inspected return? Staff must issue the payment separately.'))
          void transition('approve_refund',{confirm:true},'Refund approved. Await signed Stripe refund or separately recorded cash/manual refund.');
      });
    if(row.status==='refund_approved')
      button(buttons,'Reconcile completed refund',()=>{
        const evidence=promptText('Enter the verified refund reference from Stripe, cash receipt or external provider:');
        if(evidence===null||evidence.length<8){say('Verified refund evidence is required.',true);return;}
        if(window.confirm('Has the real payment provider already refunded this order? This action checks the order payment ledger; it never sends money.'))
          void transition('confirm_refund',{confirm:true,evidence},'The approved refund matches recorded provider evidence.');
      });
    if(row.status==='inspected'&&row.kind==='exchange')
      button(buttons,'Approve like-for-like exchange',()=>{
        if(verifyStaff('Reserve replacement inventory and authorize the same product as an exchange?'))
          void transition('approve_exchange',{confirm:true},'Replacement stock reserved. Staff must dispatch it explicitly.');
      });
    if(row.status==='exchange_approved')
      button(buttons,'Confirm replacement dispatched',()=>{
        const evidence=promptText('Enter an actual replacement/shipment or handoff reference:');
        if(evidence===null||evidence.length<8){say('Replacement reference is required.',true);return;}
        if(window.confirm('Confirm replacement goods have physically left the store?'))
          void transition('dispatch_exchange',{confirm:true,evidence},'Replacement dispatched and reserved stock consumed.');
      });
    if(row.source==='unverified'&&row.status==='requested'){
      button(buttons,'View private contact',async()=>{
        detail.hidden=false;detail.replaceChildren();
        try{
          const data=await api('contact',{id:row.id});
          add(detail,'h3','Customer contact · staff only');
          const email=data.contact?.email||'No email provided';
          add(detail,'p','Contact email: '+email,'azh-return-contact');
          add(detail,'p','Verify this customer against the original order receipt before approving. Never paste private information into Agent Chat.','azh-return-subtle');
          button(detail,'Hide contact',()=>{detail.hidden=true;detail.replaceChildren();});
        }catch(err){detail.textContent=err?.message||'Contact unavailable.';}
      });
    }
  }
  function render(){
    list.replaceChildren();
    const active=cases.filter(x=>!['rejected','cancelled','refunded','exchange_sent'].includes(x.status));
    document.getElementById('azh-return-summary').textContent=
      active.length+' open returns/exchanges · '+cases.filter(x=>x.status==='refund_approved').length+
      ' approved refunds awaiting provider evidence';
    for(const item of cases){
      const card=add(list,'article','','azh-commerce-card azh-return-case');
      add(card,'span',item.status.replaceAll('_',' ').toUpperCase()+' · '+item.kind,'azh-commerce-eyebrow');
      add(card,'h3',item.title+' · '+item.quantity+' unit(s)');
      add(card,'p','Order '+item.orderReference+' · '+(item.provider==='stripe'?'Stripe verified payment':item.provider==='cash'?'Staff-recorded cash':'External merchant'));
      add(card,'p',item.reason,'azh-return-reason');
      add(card,'small','Source: '+item.source+' · Inspected: '+item.condition+
        ' · Restocked: '+item.restockedQuantity,'azh-return-subtle');
      if(item.refundReference)add(card,'p','Verified refund reference: '+item.refundReference);
      if(item.replacementReference)add(card,'p','Replacement reference: '+item.replacementReference);
      controls(card,item);
    }
    if(!cases.length)add(list,'p','No returns have been requested yet.','azh-commerce-card');
  }
  async function refresh(){
    try{cases=await api('cases');render();say('Return cases loaded. Financial changes require explicit staff verification.');}
    catch(err){say(err?.message||'Returns are unavailable.',true);}
  }
  async function loadOrderOptions(){
    try{
      const url=new URL('commerce-api.php',document.baseURI);url.searchParams.set('action','state');
      const response=await fetch(url,{credentials:'same-origin',cache:'no-store'});
      const result=await response.json();
      if(!response.ok||!result.ok)return;
      orders=(result.data?.orders||[]).filter(x=>['paid','partially_refunded'].includes(x.state));
      const select=form.elements.namedItem('orderId');select.replaceChildren();
      select.add(new Option('Select paid order',''));
      for(const order of orders)select.add(new Option(
        order.product_title+' · '+order.provider+' · '+order.id.slice(0,10),order.id));
    }catch{ /* Public orders are never exposed on an admin fetch failure. */ }
  }
  form.addEventListener('submit',e=>{
    e.preventDefault();const data=Object.fromEntries(new FormData(form).entries());
    if(!data.orderId){say('Choose an eligible paid order.',true);return;}
    void mutation('intake',{orderId:data.orderId,kind:data.kind,
      quantity:Number(data.quantity),reason:data.reason},'Return case recorded. Staff authorization is required.');
  });
  document.getElementById('azh-returns-refresh').addEventListener('click',()=>void refresh());
  document.querySelector('[data-tab=returns]')?.addEventListener('click',()=>void refresh());
  void loadOrderOptions();
  void refresh();
  setInterval(()=>{
    const pane=returnsRoot.closest('[data-panel=returns]');
    if(pane&&!pane.hidden&&document.visibilityState==='visible'&&!busy&&detail.hidden)void refresh();
  },45000);
}
