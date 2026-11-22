/* Verified Casting & Onboarding companion in the existing Agent Chat canvas.
 * No AI-suggested text can call a mutation: all transitions are server-created,
 * revision-checked, role-scoped and require a separate explicit confirmation.
 */
export function castingChatPrompt(role) {
  return role==='admin'
    ? 'Summarize the verified casting pipeline and onboarding priorities. What should I review next, and which decisions require my approval?'
    : 'What is my verified onboarding progress, and what should I complete next?';
}
export function safeCastingAction(action) {
  return !!action && action.type==='casting_stage' && /^[a-f0-9]{32}$/i.test(action.id||'')
    && Number.isSafeInteger(action.revision) && action.revision>0
    && ['contacted','screening','interview'].includes(action.next);
}
export function castingStatusCounts(data) {
  const counts=data?.counts||{};
  return Object.entries(counts)
    .filter(([key,value])=>Object.prototype.hasOwnProperty.call(counts,key)&&Number.isSafeInteger(value)&&value>=0)
    .map(([key,value])=>({label:({newApplications:'New applications',screening:'Screening',interviews:'Interviews',approved:'Approved, awaiting invitation',converted:'Converted models',onboardingPending:'Onboarding incomplete',onboardingComplete:'Completed steps',onboardingTotal:'Total steps'})[key]||key,value}));
}
export function mountCastingChat(root=document) {
  const panel=root.getElementById('azh-casting-chat-panel');
  if(!panel)return;
  const body=panel.querySelector('[data-casting-body]'),note=panel.querySelector('[data-casting-status]');
  const csrf=panel.dataset.csrf||'';const role=panel.dataset.role||'';
  const el=(tag,cls='',content='')=>{const out=root.createElement(tag);if(cls)out.className=cls;out.textContent=String(content);return out;};
  const actionBtn=(title,click)=>{const b=el('button','azh-casting-chat-button',title);b.type='button';b.addEventListener('click',click);return b;};
  const a=(title,href)=>{const out=el('a','azh-casting-chat-link',title);out.href=href;return out;};
  let busy=false;
  const message=(text)=>{note.textContent=text;};
  async function call(action,payload=null) {
    const uri=new URL('agent-workspace-api.php',location.href);uri.searchParams.set('action',action);
    const opts={credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}};
    if(payload!==null){opts.method='POST';opts.headers['Content-Type']='application/json';opts.headers['X-CSRF-Token']=csrf;opts.body=JSON.stringify(payload);}
    const result=await fetch(uri,opts);const data=await result.json();
    if(!result.ok||!data.ok)throw new Error(data.error?.message||'Casting workspace unavailable.');
    return data.data;
  }
  async function saveStage(action) {
    if(busy||!safeCastingAction(action)||role!=='admin')return;
    if(!window.confirm(`Move the casting application to “${action.next}”? Read its answers first. This does not approve or invite the applicant.`))return;
    busy=true;message('Saving the confirmed stage change…');
    try{await call('castingAdvance',{leadId:action.id,revision:action.revision,nextStage:action.next});message('Stage updated in Casting CRM and Agent History.');await refresh();}
    catch(error){message(error.message);}finally{busy=false;}
  }
  function ask() {
    const composer=root.getElementById('azh-agent-input');if(!composer)return;
    composer.value=castingChatPrompt(role);composer.dispatchEvent(new Event('input',{bubbles:true}));
    composer.focus();composer.scrollIntoView({block:'nearest',behavior:'smooth'});
    message('Question ready. Press Send to ask AZHottie Agent.');
  }
  async function refresh() {
    message('Reading verified casting and onboarding records…');
    try{
      const data=await call('castingBriefing');body.replaceChildren();panel.hidden=false;
      const counts=el('div','azh-casting-chat-counts');
      for(const item of castingStatusCounts(data)){
        const card=el('div','azh-casting-chat-stat');card.append(el('strong','',item.value),el('span','',item.label));counts.append(card);
      }
      body.append(counts);
      if(data.report){
        const report=el('section','azh-casting-chat-report');
        const fields=[['keyFindings','Key findings'],['actionsFollowups','Actions & follow-ups'],['openQuestions','Open questions'],['nextStep','Next step'],['questionsForYou','Questions for you']];
        for(const [key,label] of fields){const value=data.report[key];if(!value||(Array.isArray(value)&&!value.length))continue;
          const item=el('div');item.append(el('strong','',label));
          for(const line of (Array.isArray(value)?value:[value]))item.append(el('p','',line));report.append(item);
        }body.append(report);
      }
      for(const item of (data.cards||[]).slice(0,6)){
        const card=el('article','azh-casting-chat-card');
        card.append(el('strong','',item.title),el('p','',item.detail));
        const controls=el('div','azh-casting-chat-actions');
        const href=String(item.href||'');
        if(/^(?:casting|account|productions|agent-chat)\.php(?:\?[a-zA-Z0-9_=%&.\-]{1,120})?$/.test(href))controls.append(a('Open record ↗',href));
        if(role==='admin'&&safeCastingAction(item.action))controls.append(actionBtn('Move to '+item.action.next,()=>void saveStage(item.action)));
        card.append(controls);body.append(card);
      }
      if(role==='admin'){
        body.append(a('Casting CRM ↗','casting.php'),a('Model onboarding ↗','casting.php?tab=onboarding'));
      }else{
        if(data.onboarding){const progress=el('div','azh-casting-chat-card');
          for(const step of data.onboarding.items||[])progress.append(el('p','',(step.done?'✓ ':'○ ')+step.label));body.append(progress);
        }
        body.append(a('Update my model profile ↗','account.php'));
      }
      body.append(actionBtn('Ask Agent about this',ask));
      message('Current workspace records · Casting decisions require administrator confirmation.');
    }catch(error){panel.hidden=false;message(error.message);}
  }
  if(new URLSearchParams(location.search).get('topic')==='casting') {
    panel.classList.add('is-focused');
    const input=root.getElementById('azh-agent-input');
    if(input && !input.value.trim()) {
      input.value=castingChatPrompt(role);input.dispatchEvent(new Event('input',{bubbles:true}));
    }
  }
  void refresh();
}
if(typeof document!=='undefined')mountCastingChat(document);
