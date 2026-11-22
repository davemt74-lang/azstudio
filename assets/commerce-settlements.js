/* Role-scoped commission settlements: all transfers happen outside AZHottie.
 * Never label estimates paid until an authorized staff member enters evidence.
 */
const payoutsRoot=document.getElementById('azh-payout-workspace');
if(payoutsRoot){
  const admin=payoutsRoot.dataset.admin==='1';
  const endpoint=new URL('commerce-payout-api.php',document.baseURI);
  const csrf=payoutsRoot.dataset.csrf||'';
  const status=document.getElementById('azh-payout-status');
  const summary=document.getElementById('azh-payout-summary');
  const batches=document.getElementById('azh-payout-batches');
  const models=document.getElementById('azh-payout-models');
  const draftForm=document.getElementById('azh-payout-draft');
  const cash=c=>new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(Number(c||0)/100);
  let snapshot=null,busy=false;
  const say=(message,error=false)=>{status.textContent=message;status.dataset.error=error?'true':'false';};
  const create=(tag,label='',css='')=>{
    const el=document.createElement(tag);el.textContent=String(label);
    if(css)el.className=css;return el;
  };
  const add=(parent,tag,label='',css='')=>{const node=create(tag,label,css);parent.append(node);return node;};
  function button(parent,title,handler){
    const b=add(parent,'button',title,'azh-commerce-small');b.type='button';
    b.addEventListener('click',()=>void handler());return b;
  }
  async function call(action,data=null){
    const url=new URL(endpoint);url.searchParams.set('action',action);
    const response=await fetch(url,{method:data===null?'GET':'POST',credentials:'same-origin',cache:'no-store',
      headers:data===null?{}:{'Content-Type':'application/json','X-CSRF-Token':csrf},
      ...(data===null?{}:{body:JSON.stringify(data)})});
    const parsed=await response.json().catch(()=>null);
    if(!response.ok||!parsed?.ok)throw new Error(parsed?.error?.message||'Commission settlement unavailable.');
    return parsed.data;
  }
  async function mutate(action,data,success){
    if(busy)return;busy=true;say('Rechecking payment, returns and commissions…');
    try{await call(action,data);await refresh();say(success);}
    catch(error){say(error?.message||'Commission action failed.',true);}
    finally{busy=false;}
  }
  function metric(parent,label,value){
    const card=add(parent,'article','','azh-return-kpi');
    add(card,'small',label);add(card,'strong',value);
  }
  function showSummary(){
    summary.replaceChildren();models?.replaceChildren();
    if(!snapshot)return;
    if(!admin){
      const b=snapshot.balance||{};
      metric(summary,'Eligible after holds',cash(b.eligibleCents));
      metric(summary,'Held by policy or returns',cash(b.heldCents));
      metric(summary,'Reserved in payout drafts',cash(b.reservedCents));
      metric(summary,'External payouts recorded',cash(Math.max(0,Number(b.recordedPaidCents||0)-Number(b.connectTransferredCents||0))));
      metric(summary,'Stripe Connect platform transfers',cash(b.connectTransferredCents||0));
      add(summary,'p','Connect transfers are not proof of a creator bank deposit. Stripe controls bank settlement.','azh-return-subtle');
      if(b.overpaidCents>0)add(summary,'p',
        'A refund or dispute reduced commissions after payout. Further settlements are blocked pending review: '+cash(b.overpaidCents),
        'azh-return-payout-banner');
      const info=document.getElementById('azh-payout-model-details');info.replaceChildren();
      for(const order of (b.orders||[]).slice(0,45)){
        const item=add(info,'article','','azh-commerce-card');
        add(item,'h3',order.title);
        add(item,'p','Recorded commission '+cash(order.commissionCents)+
          ' · Eligible '+cash(order.availableCents)+
          ' · Already allocated '+cash(order.allocatedCents));
        if(order.holdReason)add(item,'small','On hold: '+order.holdReason,'azh-return-subtle');
        if(order.overpaidCents>0)add(item,'p','Post-refund overpayment to reconcile: '+cash(order.overpaidCents));
      }
      return;
    }
    const list=snapshot.models||[];
    const eligible=list.reduce((n,m)=>n+Number(m.eligibleCents||0),0);
    const held=list.reduce((n,m)=>n+Number(m.heldCents||0),0);
    const overpaid=list.reduce((n,m)=>n+Number(m.overpaidCents||0),0);
    metric(summary,'Eligible commissions',cash(eligible));
    metric(summary,'Held commissions',cash(held));
    metric(summary,'Refund-adjusted overpayments',cash(overpaid));
    metric(summary,'Payout batches',(snapshot.batches||[]).length);
    const select=draftForm?.elements.namedItem('modelId');
    if(select){
      const current=select.value;select.replaceChildren();select.add(new Option('Choose creator',''));
      for(const m of list)select.add(new Option(m.name+' · '+cash(m.eligibleCents)+' eligible',m.id));
      if([...select.options].some(o=>o.value===current))select.value=current;
    }
    for(const model of list){
      const card=add(models,'article','','azh-commerce-card');
      add(card,'h3',model.name);
      add(card,'p','Eligible '+cash(model.eligibleCents)+
        ' · Held '+cash(model.heldCents)+
        ' · Reserved '+cash(model.reservedCents)+
        ' · External paid '+cash(Math.max(0,Number(model.paidCents||0)-Number(model.connectTransferredCents||0)))+
        ' · Connect sent '+cash(model.connectTransferredCents||0));
      window.azProviderModelEnhance?.(card,model,()=>refresh());
      if(model.overpaidCents>0)add(card,'p',
        'Further payout blocked: refund-adjusted overpayment '+cash(model.overpaidCents),
        'azh-return-payout-banner');
    }
    if(!list.length)add(models,'p','No eligible affiliated creators have been recorded.','azh-commerce-card');
  }
  function actionButtons(parent,batch){
    if(!admin)return;
    const actions=add(parent,'div','','azh-commerce-actions');
    if(batch.status==='draft'){
      button(actions,'Approve eligible commissions',()=>{
        if(window.confirm('Approve '+cash(batch.amountCents)+
           ' for an EXTERNAL creator payment? The server will recheck returns, delivery and refund state. No money will be transferred.'))
          void mutate('change',{id:batch.id,revision:batch.revision,decision:'approve',
            confirmApproval:true},'Payout approved. An external payment is still required.');
      });
      button(actions,'Cancel draft',()=>{
        if(window.confirm('Cancel draft and release its reserved commissions?'))
          void mutate('change',{id:batch.id,revision:batch.revision,decision:'cancel'},
            'Draft cancelled. Reservations released.');
      });
    }
    if(batch.status==='approved'){
      button(actions,'Record completed external payment',()=>{
        const channel=window.prompt('Payment channel: bank, cash, paypal, or other','bank');
        if(channel===null)return;
        if(!['bank','cash','paypal','other'].includes(channel.trim())){
          say('Choose a supported external payment channel.',true);return;
        }
        const reference=window.prompt('Enter the real bank/transfer/receipt reference (at least 8 characters):','');
        if(reference===null)return;
        if(reference.trim().length<8){say('Enter a verified external payment reference.',true);return;}
        if(window.confirm('Did the external transfer actually complete for '+cash(batch.amountCents)+
          '? This marks a payout paid in the ledger; AZHottie does NOT send the funds.'))
          void mutate('change',{id:batch.id,revision:batch.revision,decision:'paid',
            confirmPaid:true,paymentChannel:channel.trim(),evidenceReference:reference.trim()},
            'External payout evidence recorded. The creator can see the paid ledger entry.');
      });
      button(actions,'Cancel approved payout',()=>{
        if(window.confirm('Confirm no external transfer was sent. Cancel approval and release the allocations?'))
          void mutate('change',{id:batch.id,revision:batch.revision,decision:'cancel'},
            'Approved batch cancelled. Verify no external payment was sent.');
      });
    }
  }
  function showBatches(){
    batches.replaceChildren();
    for(const item of snapshot?.batches||[]){
      const card=add(batches,'article','','azh-commerce-card');
      add(card,'span',item.status.toUpperCase(),'azh-commerce-eyebrow');
      add(card,'h3',item.modelName+' · '+cash(item.amountCents));
      add(card,'p','Created '+item.createdAt+
        (item.confirmedAt?' · Recorded paid '+item.confirmedAt:'')+
        (item.paymentChannel?' · '+item.paymentChannel:''));
      if(item.evidenceReference)add(card,'small','External payment evidence: '+item.evidenceReference);
      actionButtons(card,item);
      window.azProviderPayoutEnhance?.(card,item,()=>refresh());
    }
    if(!(snapshot?.batches||[]).length)
      add(batches,'p','No commission settlement batches yet.','azh-commerce-card');
  }
  async function refresh(){
    try{
      snapshot=await call('state');
      showSummary();showBatches();
      say('Balances calculated from recorded orders, refunds, return holds and external payout evidence.');
    }catch(e){say(e?.message||'Commission balances are unavailable.',true);}
  }
  draftForm?.addEventListener('submit',event=>{
    event.preventDefault();
    const model=Number(draftForm.elements.namedItem('modelId').value);
    if(!Number.isInteger(model)||model<1){say('Choose an eligible creator.',true);return;}
    if(window.confirm('Create a draft reserving currently eligible commissions? No money is sent.'))
      void mutate('draft',{modelId:model},'Payout draft created. Staff approval is still required.');
  });
  document.getElementById('azh-payout-refresh')?.addEventListener('click',()=>void refresh());
  document.querySelector('[data-tab=payouts]')?.addEventListener('click',()=>void refresh());
  void refresh();
  setInterval(()=>{
    const pane=payoutsRoot.closest('[data-panel=payouts]');
    if(pane&&!pane.hidden&&document.visibilityState==='visible'&&!busy)void refresh();
  },45000);
}
