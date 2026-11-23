/** Existing Commerce page / Agent Brain companion: no external financial operations. */
const root=document.getElementById('azh-commerce-agent-briefing');
if(root){
  const results=root.querySelector('[data-agent-results]');
  const status=root.querySelector('[data-agent-status]');
  const refresh=root.querySelector('[data-agent-refresh]');
  const scan=root.querySelector('[data-agent-scan]');
  const make=(tag,value='',css='')=>{const el=document.createElement(tag);if(css)el.className=css;el.textContent=String(value??'');return el;};
  const add=(parent,tag,value='',css='')=>{const el=make(tag,value,css);parent.append(el);return el;};
  const allowedHref=url=>typeof url==='string'&&/^commerce\.php\?tab=(?:agent|products|briefs|promotions|orders|payouts|returns|shipping|providers|local|analytics)$/.test(url)?url:null;
  function renderSection(title,items){
    const box=add(results,'section','','azh-commerce-agent-section');add(box,'h3',title);
    if(!items.length){add(box,'p','No items to review.');return;}
    const list=add(box,'ul','','azh-commerce-agent-list');for(const item of items)add(list,'li',item);
  }
  function render(data){
    results.replaceChildren();
    const intro=add(results,'div','','azh-commerce-agent-summary');
    add(intro,'p',data.role==='admin'?'Administrator operational view':'Your creator earnings and opportunities','azh-commerce-agent-eyebrow');
    add(intro,'p',data.basis,'azh-commerce-agent-basis');
    renderSection('Key findings',data.keyFindings||[]);
    const actions=add(results,'section','','azh-commerce-agent-section');add(actions,'h3','Actions & follow-ups');
    const grid=add(actions,'div','','azh-commerce-agent-grid');
    for(const item of data.actionsFollowups||[]){
      const card=add(grid,'article','','azh-commerce-agent-card');
      add(card,'strong',item.title);add(card,'p',item.detail);
      const href=allowedHref(item.href);
      if(href){const link=add(card,'a','Review in Commerce','azh-commerce-agent-link');link.href=href;}
    }
    if(!grid.children.length)add(actions,'p','No follow-up suggestions.');
    renderSection('Open questions',data.openQuestions||[]);
    const next=add(results,'section','','azh-commerce-agent-section');
    add(next,'h3','Next step');add(next,'p',data.nextStep||'Review current Commerce records.');
    renderSection('Questions for you',data.questionsForYou||[]);
    status.textContent='Commerce Agent briefing updated. No financial actions were performed.';
  }
  async function request(endpoint,action,body=null){
    const url=new URL(endpoint,document.baseURI);url.searchParams.set('action',action);
    const options={method:body===null?'GET':'POST',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}};
    if(body!==null){options.headers['Content-Type']='application/json';options.headers['X-CSRF-Token']=document.getElementById('azh-commerce')?.dataset.csrf||'';options.body=JSON.stringify(body);}
    const response=await fetch(url,options);
    const data=await response.json().catch(()=>null);
    if(!response.ok||!data?.ok)throw new Error(data?.error?.message||'Commerce Agent could not retrieve its report.');
    return data.data;
  }
  let busy=false;
  async function load(){
    if(busy)return;
    busy=true;refresh.disabled=true;if(scan)scan.disabled=true;
    status.textContent='Reviewing recorded Commerce activity…';
    try{render(await request('commerce-api.php','agentBriefing'));}
    catch(error){status.textContent=error?.message||'Could not retrieve Commerce briefing.';results.replaceChildren();}
    finally{busy=false;refresh.disabled=false;if(scan)scan.disabled=false;}
  }
  refresh.addEventListener('click',()=>void load());
  if(scan)scan.addEventListener('click',async()=>{
    if(busy||!window.confirm('Check for deduplicated Commerce follow-ups? This may send in-app Agent notifications but will not initiate refunds, purchases or transfers.'))return;
    busy=true;scan.disabled=true;refresh.disabled=true;
    status.textContent='Checking existing provider and payout records…';
    try{
      const outcome=await request('agent-workspace-api.php','checkCommerce',{});
      status.textContent='Checked '+Number(outcome.checked||0)+' eligible follow-up records; '+Number(outcome.delivery?.delivered||0)+' notices delivered.';
    }catch(error){status.textContent=error?.message||'Follow-up check failed.';}
    finally{busy=false;scan.disabled=false;refresh.disabled=false;}
  });
  void load();
}
