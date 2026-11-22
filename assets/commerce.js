const root=document.getElementById('azh-commerce');
if(root){
  const admin=root.dataset.role==='admin';
  let state=null;
  const $=id=>document.getElementById(id);
  const section=key=>root.querySelector('[data-panel="'+key+'"]');
  const make=(tag,cls='',value='')=>{const el=document.createElement(tag);el.className=cls;if(value)el.textContent=value;return el;};
  const status=(message,problem=false)=>{const el=$('commerce-status');el.textContent=message;el.dataset.error=problem?'true':'false';};
  const money=n=>new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(Number(n||0)/100);
  const text=(parent,tag,value,cls='')=>{const el=make(tag,cls,String(value??''));parent.append(el);return el;};
  const link=(parent,url,label)=>{const a=make('a','azh-commerce-link',label);a.href=url;parent.append(a);return a;};
  const choice=(parent,label,callback)=>{const b=make('button','azh-commerce-small',label);b.type='button';b.addEventListener('click',()=>void callback());parent.append(b);return b;};
  const card=(parent,title,eyebrow,body)=>{const wrap=make('article','azh-commerce-card');text(wrap,'span',eyebrow,'azh-commerce-eyebrow');text(wrap,'h3',title);if(body)text(wrap,'p',body);parent.append(wrap);return wrap;};
  const formData=id=>Object.fromEntries(new FormData($(id)).entries());
  async function call(action,body=null){
    const url=new URL('commerce-api.php',document.baseURI);url.searchParams.set('action',action);
    const response=await fetch(url,{method:body?'POST':'GET',credentials:'same-origin',cache:'no-store',headers:body?{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf}:{},...(body?{body:JSON.stringify(body)}:{})});
    const payload=await response.json().catch(()=>null);
    if(!response.ok||!payload?.ok)throw new Error(payload?.error?.message||'Commerce request failed.');
    return payload.data;
  }
  async function mutate(action,body,message){
    try{status('Saving…');await call(action,body);await load();status(message||'Updated. Notifications and Agent Chat follow the recorded activity.');}
    catch(e){status(e.message,true);}
  }
  const setOptions=(selector,items,placeholder)=>{
    for(const select of root.querySelectorAll(selector)){
      const selected=select.value;select.replaceChildren();
      const empty=new Option(placeholder,'');select.add(empty);
      for(const [value,label] of items)select.add(new Option(label,value));
      if([...select.options].some(o=>o.value===selected))select.value=selected;
    }
  };
  function renderProducts(){
    const list=$('commerce-products-list');list.replaceChildren();
    for(const p of state.products){
      const c=card(list,p.title,p.state.toUpperCase()+' · '+money(p.priceCents),p.description||'No description');
      const meta=make('div','azh-commerce-actions');text(meta,'small','Creator commission '+(p.commissionBps/100).toFixed(2)+'%');
      if(p.state==='active'&&p.checkoutUrl)link(meta,p.checkoutUrl,'Hosted checkout ↗');
      if(admin)choice(meta,'Edit product',()=>{
        const f=$('commerce-product-form');for(const k of ['id','title','description','checkoutUrl','state','revision'])f.elements.namedItem(k).value=p[k]??'';
        f.elements.priceDollars.value=(p.priceCents/100).toFixed(2);f.elements.commissionPercent.value=(p.commissionBps/100).toFixed(2);
        f.scrollIntoView({behavior:'smooth',block:'start'});
      });
      c.append(meta);
    }
    if(!state.products.length)card(list,'No products yet','GET STARTED',admin?'Create a product to open a creator brief.':'Creator briefs appear after they are approved.');
  }
  function renderBriefs(){
    const list=$('commerce-brief-list');list.replaceChildren();
    for(const p of state.products){
      if(!p.brief&&!admin)continue;
      const b=p.brief;
      const c=card(list,b?.title||p.title,b?.state||'NO BRIEF',b?.instructions||'Create a product brief to invite models.');
      const row=make('div','azh-commerce-actions');
      text(row,'small',p.title+' · '+money(p.priceCents)+' · '+(p.commissionBps/100).toFixed(2)+'% commission');
      if(admin)choice(row,'Edit brief',()=>{
        const form=$('commerce-brief-form');form.elements.productId.value=p.id;
        form.elements.title.value=b?.title||p.title;form.elements.instructions.value=b?.instructions||'';
        form.elements.state.value=b?.state||'closed';form.dataset.revision=b?.revision||'';
        form.scrollIntoView({behavior:'smooth',block:'start'});
      });
      else {
        text(row,'small','Your status: '+(p.myInterest||'not expressed'));
        if(!p.myInterest||p.myInterest==='withdrawn')choice(row,'I’m interested',()=>mutate('interest',{productId:p.id,decision:'interested'},'Interest sent to the admin.'));
        else if(p.myInterest==='interested')choice(row,'Withdraw interest',()=>mutate('interest',{productId:p.id,decision:'withdrawn'},'Interest withdrawn.'));
      }
      c.append(row);
    }
    if(admin){
      const interests=$('commerce-interest-list');interests.replaceChildren();
      for(const i of state.interests){
        const c=card(interests,i.product_title,i.state,'Model: '+i.model_name);
        if(i.state==='interested'){
          const a=make('div','azh-commerce-actions');choice(a,'Approve',()=>mutate('reviewInterest',{productId:i.product_id,modelId:Number(i.model_id),decision:'approved'},'Creator interest approved.'));
          choice(a,'Decline',()=>mutate('reviewInterest',{productId:i.product_id,modelId:Number(i.model_id),decision:'declined'},'Creator interest declined.'));c.append(a);
        }
      }
      if(!state.interests.length)card(interests,'No creator requests','UP TO DATE','New model interests will appear here.');
    }
    if(!list.childElementCount)card(list,'No open briefs','CREATOR OPPORTUNITIES','Check again after a creator brief opens.');
  }
  function renderPromotions(){
    const list=$('commerce-promotion-list');list.replaceChildren();
    for(const p of state.promotions){
      const c=card(list,p.product_title,p.state.toUpperCase(),'Video: '+p.video_title);
      const a=make('div','azh-commerce-actions');
      if(p.state==='pending'&&admin){
        choice(a,'Approve video',()=>mutate('reviewPromotion',{id:p.id,decision:'approved'},'Video affiliation approved.'));
        choice(a,'Reject',()=>mutate('reviewPromotion',{id:p.id,decision:'rejected'},'Video affiliation rejected.'));
      }
      if(p.state==='approved'&&admin)choice(a,'Retire',()=>mutate('reviewPromotion',{id:p.id,decision:'retired'},'Promotion retired.'));
      if(p.state==='approved'&&p.video_status!=='published')text(a,'small','Video no longer public');
      c.append(a);
    }
    if(!list.childElementCount)card(list,'No affiliate video submissions yet','VIDEO AFFILIATES','Approved models can propose published, consented videos.');
  }
  function renderOrders(){
    const list=$('commerce-orders-list');list.replaceChildren();
    let pending=0,commission=0;
    for(const o of state.orders){
      if(o.state==='recorded')pending++;
      if(o.state==='paid')commission+=Number(o.commission_cents);
      const c=card(list,o.product_title,o.state.toUpperCase()+' · '+money(o.amount_cents),
        'Quantity '+o.quantity+(admin?' · Order '+o.external_reference:'')+' · Creator commission '+money(o.state==='paid'?o.commission_cents:0));
      if(admin&&(['recorded','paid'].includes(o.state))){
        const a=make('div','azh-commerce-actions');
        const reconcile=decision=>{
          const evidence=decision==='cancelled'?'':window.prompt('Enter the verified external payment/refund reference:','');
          if(evidence===null)return;if(decision!=='cancelled'&&evidence.trim().length<6){status('A provider evidence reference (at least six characters) is required.',true);return;}
          void mutate('reviewOrder',{id:o.id,decision,evidenceReference:evidence},'Order state reconciled.');
        };
        if(o.state==='recorded'){choice(a,'Mark paid (provider evidence)',()=>reconcile('paid'));choice(a,'Cancel unconfirmed',()=>reconcile('cancelled'));}
        else choice(a,'Record full refund',()=>reconcile('refunded'));
        c.append(a);
      }
    }
    if(!list.childElementCount)card(list,'No orders recorded','ORDER HISTORY','Orders appear here after the admin records an external provider order.');
    if(!admin){
      const info=make('div','azh-commerce-summary');text(info,'strong','Recorded paid-order commissions: '+money(commission));
      text(info,'p','These are unpaid estimates from admin-reconciled external orders. Refunded orders are excluded. No disbursement has been made.');list.prepend(info);
    }else{
      const info=make('div','azh-commerce-summary');text(info,'strong',pending+' orders awaiting external reconciliation');
      list.prepend(info);
    }
  }
  function render(){
    renderProducts();renderBriefs();renderPromotions();renderOrders();
    if(admin){
      const products=state.products;
      setOptions('[data-products]',products.map(p=>[p.id,p.title]),'Choose product');
      setOptions('[data-active-products]',products.filter(p=>p.state==='active').map(p=>[p.id,p.title]),'Choose active product');
      setOptions('[data-approved-products]',products.filter(p=>p.myInterest==='approved').map(p=>[p.id,p.title]),'Choose approved product');
      setOptions('[data-promotions]',[['','No attribution'],...state.promotions.filter(p=>p.state==='approved').map(p=>[p.id,p.product_title+' · '+p.video_title])],'No attribution');
      $('commerce-live-current').textContent=state.liveFeature?'Featured now: '+state.liveFeature.title:'No product currently featured in a live broadcast.';
    } else {
      setOptions('[data-approved-products]',state.products.filter(p=>p.myInterest==='approved').map(p=>[p.id,p.title]),'Choose approved product');
      setOptions('[data-videos]',state.videos.map(v=>[v.id,v.title]),'Choose a published video');
    }
  }
  async function load(){state=await call('state');render();status('Commerce records updated.');}
  root.querySelectorAll('[data-tab]').forEach(tab=>tab.addEventListener('click',()=>{
    for(const button of root.querySelectorAll('[data-tab]'))button.setAttribute('aria-selected',String(button===tab));
    for(const panel of root.querySelectorAll('[data-panel]'))panel.hidden=panel.dataset.panel!==tab.dataset.tab;
  }));
  if(admin){
    $('commerce-product-form').addEventListener('submit',e=>{
      e.preventDefault();const d=formData('commerce-product-form');
      const cents=Math.round(Number(d.priceDollars)*100),bps=Math.round(Number(d.commissionPercent)*100);
      if(!Number.isSafeInteger(cents)||!Number.isSafeInteger(bps)){status('Enter valid decimal amounts.',true);return;}
      void mutate('saveProduct',{id:d.id||'',revision:d.revision?Number(d.revision):undefined,title:d.title,description:d.description,
        priceCents:cents,commissionBps:bps,checkoutUrl:d.checkoutUrl,state:d.state},'Product saved.');
    });
    $('commerce-product-form').addEventListener('reset',()=>{const form=$('commerce-product-form');form.elements.id.value='';form.elements.revision.value='';});
    $('commerce-brief-form').addEventListener('submit',e=>{
      e.preventDefault();const f=$('commerce-brief-form'),d=formData('commerce-brief-form');
      const current=state.products.find(p=>p.id===d.productId)?.brief;
      void mutate('saveBrief',{productId:d.productId,title:d.title,instructions:d.instructions,state:d.state,revision:current?.revision},'Creator brief saved.');
    });
    $('commerce-order-form').addEventListener('submit',e=>{
      e.preventDefault();const d=formData('commerce-order-form');void mutate('recordOrder',{productId:d.productId,externalReference:d.externalReference,quantity:Number(d.quantity),promotionId:d.promotionId},'External order recorded; payment is still unverified.');
    });
    $('commerce-live-form').addEventListener('submit',e=>{
      e.preventDefault();const d=formData('commerce-live-form');void mutate('featureLive',{productId:d.productId},'Live product updated.');
    });
  }else{
    $('commerce-promotion-form').addEventListener('submit',e=>{
      e.preventDefault();void mutate('requestPromotion',formData('commerce-promotion-form'),'Video sent for administrator review.');
    });
  }
  void load().catch(e=>status(e.message,true));
}
