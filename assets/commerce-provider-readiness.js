/* Staff-only, read-only provider readiness snapshot; it never triggers financial operations. */
const readinessRoot=document.getElementById('azh-provider-readiness');
if(readinessRoot){
  const output=readinessRoot.querySelector('[data-readiness-results]');
  const status=readinessRoot.querySelector('[data-readiness-status]');
  const refreshButton=readinessRoot.querySelector('[data-readiness-refresh]');
  const make=(tag,value,cls='')=>{
    const el=document.createElement(tag);el.textContent=String(value??'');
    if(cls)el.className=cls;return el;
  };
  const add=(parent,tag,value,cls='')=>{const el=make(tag,value,cls);parent.append(el);return el;};
  const money=cents=>new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(Number(cents||0)/100);
  let loading=false;
  function openTab(tab){
    if(!['returns','payouts'].includes(tab))return;
    document.querySelector('#azh-commerce [data-tab="'+tab+'"]')?.click();
  }
  function render(data){
    output.replaceChildren();
    const intro=add(output,'section','','azh-readiness-intro');
    add(intro,'h3','Provider launch gate');
    add(intro,'p',data.liveActionsEnabled
      ?'WARNING: Live financial actions appear enabled in private settings. Disable them until independently verified and approved.'
      :data.configurationReady
        ?'Configuration looks complete. Hosted TEST transactions are still unverified; production approval remains blocked.'
        :'Configuration or unresolved financial operations need attention before hosted acceptance can begin.');
    if(data.liveActionsEnabled)intro.dataset.risk='live';
    add(intro,'p','Stripe: '+(data.environment?.stripe||'unconfigured')+
      ' · EasyPost: '+(data.environment?.easypost||'unconfigured')+
      ' · Unresolved provider operations: '+Number(data.pendingTotal||0),'azh-readiness-meta');
    const checks=add(output,'section','','azh-readiness-section');
    add(checks,'h3','Configuration & safeguards');
    const grid=add(checks,'div','','azh-readiness-grid');
    for(const check of data.checks||[]){
      const card=add(grid,'div','','azh-readiness-item');
      const heading=add(card,'div','','azh-readiness-heading');
      add(heading,'strong',check.label);
      const chip=add(heading,'span',check.state,'azh-readiness-chip');
      chip.dataset.state=check.state;
      add(card,'p',check.detail);
    }
    const queue=add(output,'section','','azh-readiness-section');
    add(queue,'h3','Reconciliation queue');
    add(queue,'p','These are database records requiring verification, not a count of completed provider payments.');
    const items=add(queue,'div','','azh-readiness-queue');
    for(const row of data.counts||[]){
      const item=add(items,'div','','azh-readiness-queue-row');
      add(item,'strong',String(row.count)+' · '+row.label);
      const nav=add(item,'button','Open '+row.tab,'azh-commerce-small');
      nav.type='button';nav.addEventListener('click',()=>openTab(row.tab));
    }
    const tests=add(output,'section','','azh-readiness-section');
    add(tests,'h3','Required hosted TEST acceptance');
    const steps=add(tests,'ol','','azh-readiness-tests');
    for(const step of data.requiredTests||[])add(steps,'li',step);
    const warning=add(output,'p',data.notice||'Hosted TEST provider verification is required.','azh-readiness-warning');
    warning.setAttribute('role','note');
    status.textContent='Read-only snapshot updated. All hosted TEST acceptance steps remain unverified.';
  }
  async function refresh(){
    if(loading)return;
    loading=true;refreshButton.disabled=true;status.textContent='Checking configuration and outstanding provider records…';
    try{
      const url=new URL('commerce-provider-api.php',document.baseURI);
      url.searchParams.set('action','readiness');
      const response=await fetch(url,{method:'GET',credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}});
      const payload=await response.json().catch(()=>null);
      if(!response.ok||!payload?.ok)throw new Error(payload?.error?.message||'Unable to load readiness snapshot.');
      render(payload.data);
    }catch(err){status.textContent=err?.message||'Could not retrieve readiness snapshot.';output.replaceChildren();}
    finally{loading=false;refreshButton.disabled=false;}
  }
  refreshButton.addEventListener('click',()=>void refresh());
  void refresh();
}
