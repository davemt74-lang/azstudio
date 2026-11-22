/* Provider-specific controls extend existing Returns and Creator Payouts cards.
 * Never initiate refunds, postage or transfers without a direct staff click.
 */
const providerWorkspace=document.getElementById('azh-payout-workspace')||document.getElementById('azh-returns-admin');
if(providerWorkspace){
  const isAdmin=document.getElementById('azh-returns-admin')!==null;
  const csrf=providerWorkspace.dataset.csrf||document.getElementById('azh-returns-admin')?.dataset.csrf||'';
  const endpoint=new URL('commerce-provider-api.php',document.baseURI);
  let features=null,processing=false;
  const cash=c=>new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(Number(c||0)/100);
  const make=(tag,value='',cls='')=>{
    const el=document.createElement(tag);el.textContent=String(value);
    if(cls)el.className=cls;return el;
  };
  const append=(parent,tag,value='',cls='')=>{
    const el=make(tag,value,cls);parent.append(el);return el;
  };
  const button=(parent,label,fn)=>{
    const el=append(parent,'button',label,'azh-commerce-small');el.type='button';
    el.addEventListener('click',()=>void fn());return el;
  };
  const message=(value,error=false)=>{
    const node=document.getElementById('azh-returns-status')||document.getElementById('azh-payout-status');
    if(node){node.textContent=value;node.dataset.error=error?'true':'false';}
  };
  async function call(action,data=null){
    const url=new URL(endpoint);url.searchParams.set('action',action);
    const response=await fetch(url,{method:data===null?'GET':'POST',credentials:'same-origin',cache:'no-store',
      headers:data===null?{}:{'Content-Type':'application/json','X-CSRF-Token':csrf},
      ...(data===null?{}:{body:JSON.stringify(data)})});
    const result=await response.json().catch(()=>null);
    if(!response.ok||!result?.ok)throw new Error(result?.error?.message||'Provider action failed.');
    return result.data;
  }
  async function run(action,data,refresh,success){
    if(processing)return;
    processing=true;message('Checking authorization and provider status…');
    try{const result=await call(action,data);await refresh();message(success);return result;}
    catch(e){message(e?.message||'Provider operation needs reconciliation.',true);}
    finally{processing=false;}
  }
  function returnPanel(card,row,refresh){
    if(!isAdmin)return;
    const box=append(card,'section','','azh-provider-card');
    append(box,'h4','Provider refunds & return postage');
    const refund=row.providerRefund;
    if(row.kind==='refund'&&row.provider==='stripe'&&row.status==='refund_approved'){
      const amount=Number(row.approvedRefundCents||0);
      if(refund){
        append(box,'p','Stripe refund: '+refund.status+' · '+cash(refund.amountCents)+
          (refund.stripeRefundId?' · '+refund.stripeRefundId:''),'azh-return-subtle');
        if(['pending','needs_review','provider_pending'].includes(refund.status))
          button(box,'Reconcile original Stripe refund (no new refund)',()=>run('reconcileRefund',
            {returnId:row.id},refresh,'Stripe refund checked without a second payment action.'));
        if(refund.status==='ledger_verified')
          append(box,'p','Signed Stripe refund is in the order ledger. Staff may close the return case.','azh-return-payout-banner');
      }else if(amount>0){
        append(box,'p','Approved merchandise-only refund: '+cash(amount)+'. Shipping and tax are not included automatically.','azh-return-subtle');
        const b=button(box,'Initiate Stripe refund · '+cash(amount),async()=>{
          if(!features?.refundInitiationEnabled){
            message('Stripe refunds are disabled in private settings.',true);return;
          }
          if(!window.confirm('Issue a '+(features.stripeMode==='live'?'REAL':'TEST')+
             ' Stripe refund of '+cash(amount)+' to the original payment? This cannot be undone.'))return;
          await run('initiateRefund',{returnId:row.id,revision:row.revision,
            expectedCents:amount,confirmRefund:true},refresh,
            'Stripe refund requested. Wait for signed provider confirmation before closing the return.');
        });
        b.disabled=!features?.refundInitiationEnabled||amount>Number(features?.stripeRefundMaxCents||0);
      }
    }
    if(row.provider!=='stripe'||row.status!=='authorized'){
      if(row.returnLabel?.label?.status==='purchased')
        append(box,'p','Return label already purchased.','azh-return-subtle');
      return;
    }
    const label=row.returnLabel?.label,quote=row.returnLabel?.quote;
    if(label){
      append(box,'p','Return label '+label.status+' · '+label.carrier+' '+label.service+
        ' · '+cash(label.rateCents),'azh-return-subtle');
      if(label.status==='purchased'&&label.labelUrl){
        const a=append(box,'a','Open label for secure sharing','azh-commerce-link');
        a.href=label.labelUrl;a.target='_blank';a.rel='noopener noreferrer external nofollow';
      }else if(['pending','needs_review'].includes(label.status)){
        button(box,'Reconcile existing return label (GET only)',()=>run('reconcileReturnLabel',
          {returnId:row.id},refresh,'Return label reconciled without making a new purchase.'));
      }
      return;
    }
    const form=append(box,'form','','azh-provider-parcel');
    append(form,'p','This label ships from the verified customer address back to the configured merchant address. Staff must verify parcel measurements.','azh-return-subtle');
    const fields=[['weightGrams','Weight (grams)',450],['lengthMm','Length (mm)',220],
      ['widthMm','Width (mm)',180],['heightMm','Height (mm)',80]];
    for(const [field,title,value] of fields){
      const label=append(form,'label',title);
      const input=document.createElement('input');
      input.name=field;input.type='number';input.min='1';input.step='1';
      input.value=String(value);input.required=true;label.append(input);
    }
    const confirmation=append(form,'label','','azh-commerce-checkbox');
    const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.required=true;
    confirmation.append(checkbox,document.createTextNode('I verified the actual return package size and customer eligibility.'));
    const submit=append(form,'button',quote?'Refresh carrier return quote':'Quote return label services','azh-commerce-small');
    submit.type='submit';
    form.addEventListener('submit',e=>{
      e.preventDefault();if(!checkbox.checked)return;
      const request={returnId:row.id,confirmParcel:true,refresh:!!quote};
      for(const field of form.querySelectorAll('input[type=number]'))request[field.name]=Number(field.value);
      void run('quoteReturnLabel',request,refresh,'Carrier return rates checked; no label purchased.');
    });
    if(quote){
      append(box,'p','Provider quote expires '+quote.expiresAt+' · '+quote.mode,'azh-return-subtle');
      if(Date.parse(quote.expiresAt)<=Date.now())return;
      for(const rate of quote.rates||[]){
        const line=append(box,'div','','azh-provider-rate');
        append(line,'span',rate.carrier+' '+rate.service+' · '+cash(rate.rateCents));
        const b=button(line,'Buy return label',()=>{
          if(!features?.returnLabelPurchasesEnabled){
            message('Return label purchasing is disabled in private settings.',true);return;
          }
          if(!window.confirm('Authorize '+(quote.mode==='production'?'REAL':'TEST')+
            ' return label charge '+cash(rate.rateCents)+' for '+rate.carrier+'? Timeouts must be reconciled, never bought again.'))return;
          void run('buyReturnLabel',{returnId:row.id,revision:quote.revision,
            rateId:rate.id,expectedCents:rate.rateCents,confirmPurchase:true},refresh,
            'Return label recorded. Share it securely after verifying the status.');
        });
        b.disabled=!features?.returnLabelPurchasesEnabled||rate.rateCents>Number(features?.labelMaxCents||0);
      }
    }
  }
  function payoutPanel(card,batch,refresh){
    if(!isAdmin)return;
    const box=append(card,'section','','azh-provider-card');
    if(batch.connectTransfer){
      append(box,'p','Stripe Connect: '+batch.connectTransfer.status+' · '+cash(batch.connectTransfer.amountCents),
        'azh-return-subtle');
      if(['pending','needs_review'].includes(batch.connectTransfer.status))
        button(box,'Reconcile Connect transfer (GET only)',()=>run('reconcileConnectTransfer',
          {batchId:batch.id},refresh,'Transfer status checked without another transfer.'));
      if(batch.connectTransfer.status==='transferred')
        append(box,'p','Funds transferred to Stripe connected account. Bank payout remains separately managed by Stripe.','azh-return-subtle');
      return;
    }
    if(batch.status!=='approved')return;
    const b=button(box,'Transfer approved commission with Stripe Connect',async()=>{
      if(!features?.connectTransfersEnabled){
        message('Connect transfers are disabled in private settings.',true);return;
      }
      if(!window.confirm('Authorize a '+(features.stripeMode==='live'?'REAL':'TEST')+
        ' Stripe Connect transfer of '+cash(batch.amountCents)+
        ' to the VERIFIED creator account? This moves funds from the platform; it is not proof of bank deposit.'))return;
      await run('sendConnectTransfer',{batchId:batch.id,revision:batch.revision,
        expectedCents:batch.amountCents,confirmTransfer:true},refresh,
        'Stripe transfer recorded or queued for provider reconciliation.');
    });
    b.disabled=!features?.connectTransfersEnabled||batch.amountCents>Number(features?.connectMaxCents||0);
  }
  function modelPanel(card,model,refresh){
    if(!isAdmin)return;
    const wrapper=append(card,'section','','azh-provider-card');
    const account=model.connectAccount;
    append(wrapper,'p',account?.linked?
      'Stripe Connect '+account.mode+' verified · account ending '+account.accountLast4:
      'No verified Stripe Connect account. Creator onboarding must be completed in Stripe first.','azh-return-subtle');
    const form=append(wrapper,'form','','azh-provider-link-form');
    const label=append(form,'label','Existing Stripe Connect account ID');
    const input=document.createElement('input');input.name='accountId';input.required=true;
    input.placeholder='acct_…';input.maxLength=100;input.pattern='acct_[A-Za-z0-9_]{6,}';
    label.append(input);
    const submit=append(form,'button','Verify linked account','azh-commerce-small');submit.type='submit';
    form.addEventListener('submit',e=>{
      e.preventDefault();
      if(!window.confirm('Have you verified this account belongs to this creator? Stripe will check onboarding and transfer capability.'))return;
      void run('linkConnectAccount',{modelId:model.id,accountId:input.value.trim(),
        confirmLink:true},refresh,'Stripe Connect account verified; transferring funds still requires approval.');
    });
  }
  window.azProviderReturnEnhance=returnPanel;
  window.azProviderPayoutEnhance=payoutPanel;
  window.azProviderModelEnhance=modelPanel;
  if(isAdmin)void call('features').then(v=>{
    features=v;document.getElementById('azh-returns-refresh')?.click();
    document.getElementById('azh-payout-refresh')?.click();
  }).catch(e=>message(e.message,true));
}
