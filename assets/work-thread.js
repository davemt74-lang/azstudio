const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const newRequestId = () => crypto.randomUUID();

export async function workThreadApi(action, payload=null, query={}, csrf='') {
  const url=new URL('work-thread-api.php',location.href);url.searchParams.set('action',action);
  for(const [key,value] of Object.entries(query))if(value!==undefined&&value!==null)url.searchParams.set(key,String(value));
  const options={credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}};
  if(payload!==null)Object.assign(options,{method:'POST',headers:{...options.headers,'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(payload)});
  const response=await fetch(url,options);let body;
  try{body=await response.json();}catch{throw new Error('The reply response could not be read. Retry the same message to recover its saved result.');}
  if(!response.ok||body.ok===false){const error=new Error(body.error?.message||'The assignment conversation could not be loaded.');error.code=body.error?.code;error.status=response.status;throw error;}
  return body.data;
}

export function threadMessageMarkup(message) {
  let date='';try{date=new Intl.DateTimeFormat('en-US',{timeZone:'America/Phoenix',month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}).format(new Date(message.createdAt));}catch{}
  return '<article class="work-thread-message'+(message.isOwn?' is-own':'')+'"><header><strong>'+esc(message.isOwn?'You':message.authorName||'Production member')+'</strong><time datetime="'+esc(message.createdAt)+'">'+esc(date)+' · Arizona</time></header><p>'+esc(message.text)+'</p></article>';
}

export function mountWorkThread(container,{assignment,user,csrf='',api=workThreadApi,canMutate=()=>true,onUnread=()=>{},onError=()=>{}}={}) {
  if(!container||!assignment?.id)return{destroy(){},isBusy:()=>false,isDirty:()=>false};
  const abort=new AbortController(),storageKey='azhottie-assignment-thread:'+String(user?.id||'member')+':'+assignment.id;
  let destroyed=false,busy=false,marking=false,loaded=false,canPost=false,readOnlyReason='',messages=[],hasMore=false,unreadCount=Number(assignment.questionUnreadCount)||0,readThrough=0,request=0,visible=false,contentEpoch=0;
  let draft='',pending=null,status='',statusError=false;
  try{const saved=JSON.parse(sessionStorage.getItem(storageKey)||'null');draft=saved?.draft||'';pending=saved?.pending||null;}catch{}
  const $=selector=>container.querySelector(selector);
  const remember=()=>{if(destroyed)return;try{draft||pending?sessionStorage.setItem(storageKey,JSON.stringify({draft,pending})):sessionStorage.removeItem(storageKey);}catch{}};
  function message(text,error=false){status=text;statusError=error;if(destroyed)return;const node=$('[data-wt-status]');if(node){node.textContent=text;node.hidden=!text;node.setAttribute('role',error?'alert':'status');}}
  function sync(){if(destroyed)return;const send=$('[data-wt-send]');if(send){send.disabled=busy||!loaded||(!canPost&&!pending)||(!pending&&!draft.trim());send.textContent=pending?'Retry previous message':'Send reply';}for(const selector of ['[data-wt-refresh]','[data-wt-earlier]']){const node=$(selector);if(node)node.disabled=busy;}const mark=$('[data-wt-read]');if(mark)mark.disabled=marking||!loaded||!unreadCount;const input=$('[data-wt-input]');if(input)input.disabled=loaded&&!canPost;const count=$('[data-wt-unread]');if(count){count.textContent=String(unreadCount);count.hidden=!unreadCount;}container.setAttribute('aria-busy',String(busy));}
  function render(){if(destroyed)return;container.innerHTML='<div class="work-section-heading"><div><p class="eyebrow">KEEP THE DIRECTION TOGETHER</p><h2>Questions & replies <span class="work-count-pill" data-wt-unread'+(!unreadCount?' hidden':'')+'>'+unreadCount+'</span></h2><p class="field-help">A private conversation between the producer and this assignment’s contributor.</p></div><div class="work-actions"><button type="button" class="button button--quiet" data-wt-refresh>Refresh replies</button><button type="button" class="button button--quiet" data-wt-read>Mark read</button></div></div><div data-wt-status class="work-inline-notice" role="'+(statusError?'alert':'status')+'"'+(!status?' hidden':'')+'>'+esc(status)+'</div>'+(hasMore?'<button type="button" class="button button--quiet work-thread-earlier" data-wt-earlier>Load earlier replies</button>':'')+'<div class="work-thread-messages" data-wt-messages aria-live="polite">'+(messages.length?messages.map(threadMessageMarkup).join(''):loaded?'<p class="work-thread-empty">Ask about the script, framing, wardrobe or deadline. Replies stay with this assignment.</p>':'<p class="field-help">Loading the conversation…</p>')+'</div>'+(loaded&&!canPost?'<p class="work-inline-notice">'+esc(readOnlyReason||'This assignment conversation is read-only.')+'</p>':'')+'<form data-work-thread-form class="work-thread-form"><label class="field">Your question or reply<textarea data-wt-input rows="4" maxlength="4000" placeholder="Ask a question or clarify the direction…">'+esc(draft)+'</textarea></label>'+(pending?'<div class="work-thread-pending"><p class="field-help">The previous message may already be saved. Retry it to confirm; new text in the box stays as your next draft.</p><details><summary>Previous message</summary><p>'+esc(pending.text)+'</p></details></div>':'')+'<div class="work-actions"><button type="submit" class="button button--primary" data-wt-send>'+(pending?'Retry previous message':'Send reply')+'</button><span class="field-help">Up to 4,000 characters · no attachments</span></div></form>';sync();}
  function adopt(response,{earlier=false}={}){
    contentEpoch++;
    if(response.csrf)csrf=response.csrf;loaded=true;canPost=!!response.canPost;readOnlyReason=response.readOnlyReason||'';unreadCount=Number(response.unreadCount)||0;
    const incoming=response.messages||[];const previousOldest=messages.length?Number(messages[0].sequence):null;const previousLatest=messages.length?Number(messages.at(-1).sequence):null;
    const contiguous=previousLatest!==null&&incoming.length&&Number(incoming[0].sequence)<=previousLatest+1;
    if(earlier||contiguous){const byId=new Map([...messages,...incoming].map(item=>[item.id,item]));messages=[...byId.values()].sort((a,b)=>Number(a.sequence)-Number(b.sequence));if(earlier)hasMore=!!response.hasMore;else if(previousOldest===null||messages[0]?.sequence!==previousOldest)hasMore=!!response.hasMore;}
    else{messages=incoming.slice();hasMore=!!response.hasMore;}
    onUnread(unreadCount);
  }
  async function call(action,payload=null,query={},retry=true){try{const result=await api(action,payload,query,csrf);if(result?.csrf)csrf=result.csrf;return result;}catch(error){if(retry&&payload!==null&&error.code==='csrf_invalid'&&!destroyed){const fresh=await api('thread',null,{assignmentId:assignment.id},csrf);if(destroyed)throw error;if(fresh?.csrf)csrf=fresh.csrf;return call(action,payload,query,false);}throw error;}}
  function failed(error){if(destroyed)return;message(error.message||'The conversation request failed.',true);onError(error);sync();}
  async function markRenderedRead(){
    if(destroyed||marking||!loaded||!unreadCount||!messages.length||document.visibilityState==='hidden')return;
    const throughSequence=Math.max(...messages.map(item=>Number(item.sequence)||0));if(throughSequence<=readThrough)return;
    const epoch=contentEpoch;marking=true;sync();
    try{const response=await call('markRead',{assignmentId:assignment.id,throughSequence});if(destroyed)return;readThrough=Math.max(readThrough,Number(response.throughSequence)||throughSequence);if(contentEpoch===epoch){unreadCount=Number(response.unreadCount)||0;onUnread(unreadCount);}}
    catch(error){failed(error);}
    finally{marking=false;sync();if(!destroyed&&visible&&contentEpoch!==epoch&&unreadCount&&messages.some(item=>Number(item.sequence)>readThrough))Promise.resolve().then(markRenderedRead);}
  }
  async function refresh(earlier=false){if(destroyed||busy)return;const beforeSequence=earlier?Number(messages[0]?.sequence)||0:null;if(earlier&&(!hasMore||!beforeSequence))return;const current=++request;busy=true;sync();try{const response=await call('thread',null,{assignmentId:assignment.id,...(earlier?{beforeSequence}:{})});if(destroyed||current!==request)return;adopt(response,{earlier});message('');render();if(visible)await markRenderedRead();}catch(error){failed(error);}finally{busy=false;sync();}}
  async function send(){
    if(destroyed||busy||!loaded||(!canPost&&!pending)||!canMutate())return;
    const text=pending?.text??draft.trim();if(!text)return;if(Array.from(text).length>4000){message('Keep this reply within 4,000 characters.',true);return;}
    pending ||= {requestId:newRequestId(),text};remember();const sending={...pending};busy=true;sync();message('Saving your reply…');
    try{const response=await call('send',{assignmentId:assignment.id,text:sending.text,requestId:sending.requestId});if(destroyed)return;adopt(response);if(draft.trim()===sending.text)draft='';pending=null;remember();message('Reply saved.');render();if(visible)await markRenderedRead();}
    catch(error){failed(error);render();}
    finally{busy=false;sync();}
  }
  container.addEventListener('input',event=>{if(event.target.matches?.('[data-wt-input]')){draft=event.target.value;remember();sync();}},{signal:abort.signal});
  container.addEventListener('submit',event=>{if(!event.target.matches?.('[data-work-thread-form]'))return;event.preventDefault();send();},{signal:abort.signal});
  container.addEventListener('click',event=>{if(event.target.closest?.('[data-wt-refresh]')){event.preventDefault();refresh();}else if(event.target.closest?.('[data-wt-earlier]')){event.preventDefault();refresh(true);}else if(event.target.closest?.('[data-wt-read]')){event.preventDefault();markRenderedRead();}},{signal:abort.signal});
  let observer=null;
  if(typeof IntersectionObserver!=='undefined'){observer=new IntersectionObserver(entries=>{visible=entries.some(entry=>entry.isIntersecting);if(visible)markRenderedRead();},{threshold:.1});observer.observe(container);}
  document.addEventListener?.('visibilitychange',()=>{if(document.visibilityState==='visible'&&visible)markRenderedRead();},{signal:abort.signal});
  render();refresh();
  return {destroy(){if(destroyed)return;remember();destroyed=true;request++;abort.abort();observer?.disconnect();},isBusy:()=>busy,isDirty:()=>!!draft.trim()||!!pending,refresh,markRead:markRenderedRead};
}
