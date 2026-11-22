/* AZHottie private member chat: one footer composer, one dedicated canvas. */
const STORE_KEY='azhottie:agent:pending:v1';
const dock=typeof document==='undefined'?null:document.getElementById('azh-agent-dock');
const canvas=typeof document==='undefined'?null:document.querySelector('[data-agent-canvas]');
const compose=typeof document==='undefined'?null:document.getElementById('azh-agent-compose');
const input=typeof document==='undefined'?null:document.getElementById('azh-agent-input');
const sendButton=typeof document==='undefined'?null:document.getElementById('azh-agent-send');
const transcript=typeof document==='undefined'?null:document.getElementById('azh-chat-messages');
const threadList=typeof document==='undefined'?null:document.getElementById('azh-chat-threads');
const status=typeof document==='undefined'?null:document.getElementById('azh-chat-status');
let currentThread=null, busy=false, lastData=null, loadVersion=0;

export function validAgentMessage(value) {return typeof value==='string' && !!value.trim() && new TextEncoder().encode(value.trim()).length<=2200;}
export function clipAgentTitle(title) {return String(title??'').slice(0,78);}
export function agentPendingPayload(text) {return {text:String(text).trim(),at:Date.now()};}
const randomId=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),x=>x.toString(16).padStart(2,'0')).join('');

async function request(action,body=null,threadId='') {
  const endpoint=new URL('agent-chat-api.php',location.href);
  endpoint.searchParams.set('action',action);
  if(threadId)endpoint.searchParams.set('threadId',threadId);
  const opts={credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}};
  if(body!==null){opts.method='POST';opts.headers['Content-Type']='application/json';opts.headers['X-CSRF-Token']=canvas?.dataset.csrf||dock?.dataset.csrf||'';opts.body=JSON.stringify(body);}
  const response=await fetch(endpoint,opts);
  let raw;try{raw=await response.json();}catch{throw new Error(`Chat could not load (${response.status}). Please check your connection.`);}
  if(!response.ok||!raw.ok)throw Object.assign(new Error(raw.error?.message||'The request failed.'),{code:raw.error?.code,status:response.status});
  return raw.data;
}
function setStatus(text='',type='') {
  if(!status)return;
  status.hidden=!text;status.textContent=text;status.dataset.kind=type;
}
function text(tag,className,value){const el=document.createElement(tag);el.className=className;el.textContent=value;return el;}
function markDisabled(value){busy=value;if(sendButton)sendButton.disabled=value;if(input)input.disabled=value;}
function addWelcome(){
  const welcome=document.createElement('div');welcome.className='azh-chat-welcome';
  welcome.append(text('span','azh-chat-welcome-star','✳'),text('h2','',"What's on your mind?"),text('p','',"Brainstorm a shoot, discuss AZHottie, or get help exploring your member space. The agent cannot change bookings or access your private media."));
  transcript.append(welcome);
}
function showMessages(data){
  if(!transcript)return;
  transcript.replaceChildren();
  if(!data.messages?.length){addWelcome();return;}
  for(const row of data.messages){
    const article=document.createElement('article');article.className=`azh-chat-message is-${row.role==='user'?'user':'assistant'}`;
    const head=text('div','azh-chat-message-who',row.role==='user'?'You':'AZHottie Agent');
    article.append(head,text('div','azh-chat-message-text',row.text));
    if(row.role==='user'&&row.state==='pending')article.append(text('div','azh-chat-message-note','Waiting for reply · Refresh to check status'));
    if(row.role==='user'&&row.state==='failed'){
      const retry=document.createElement('button');retry.type='button';retry.className='azh-chat-retry';retry.textContent='Retry this message';
      retry.addEventListener('click',()=>send(row.text,row.clientToken));
      article.append(retry);
    }
    transcript.append(article);
  }
  if(!transcript.dataset.userScrolled || transcript.dataset.userScrolled==='false') window.scrollTo({top:document.documentElement.scrollHeight,behavior:'instant'});
}
function showThreads(data){
  if(!threadList)return;
  threadList.replaceChildren();
  if(!data.threads?.length){threadList.append(text('span','azh-chat-empty-threads','No conversations yet'));return;}
  for(const row of data.threads){
    const button=text('button','azh-chat-thread'+(row.id===data.threadId?' is-active':''),clipAgentTitle(row.title));
    button.type='button';button.setAttribute('aria-current',row.id===data.threadId?'true':'false');
    button.addEventListener('click',()=>{if(!busy)void load(row.id);});
    threadList.append(button);
  }
}
function display(data){
  if(!data||!Array.isArray(data.messages)||!Array.isArray(data.threads))throw new Error('The chat response was incomplete. Reload the page.');
  lastData=data;currentThread=data.threadId||null;
  showThreads(data);showMessages(data);
  if(!data.agent?.available) setStatus('The agent is not connected yet. An administrator can enable member chat in AI settings.','warning');
  else if(data.remaining===0)setStatus("Today's message allowance is used up. Your saved conversations are still available.",'warning');
  else setStatus('');
  if(sendButton)sendButton.title=data.agent?.available?'Send message':'AI chat is not enabled';
}
async function load(threadId=''){
  if(busy)return;
  const call=++loadVersion;setStatus('Loading your private chat…');
  try{const data=await request('state',null,threadId);if(call===loadVersion)display(data);}
  catch(error){if(call===loadVersion)setStatus(error.message,'error');}
}
async function send(value,existingToken=null){
  if(busy)return;
  if(!validAgentMessage(value)){setStatus('Write a message of no more than 2,200 bytes.','error');input?.focus();return;}
  const trimmed=value.trim();
  const token=existingToken||randomId();
  markDisabled(true);setStatus('AZHottie Agent is responding…');
  try {
    const data=await request('send',{message:trimmed,threadId:currentThread||'',clientToken:token});
    if(input&&input.value.trim()===trimmed)input.value='';
    display(data);
  }catch(error){
    setStatus(error.message,'error');
    // The server may have saved the message before a lost HTTP response; reload before retrying.
    try{const data=await request('state',null,currentThread||'');showThreads(data);showMessages(data);lastData=data;currentThread=data.threadId||null;}catch{}
  }finally{markDisabled(false);input?.focus();}
}

function bind(){
  if(!dock||!compose||!input)return;
  input.addEventListener('input',()=>{input.style.height='auto';input.style.height=`${Math.min(156,Math.max(28,input.scrollHeight))}px`;});
  input.addEventListener('keydown',event=>{if(event.key==='Enter'&&!event.shiftKey&&!event.isComposing){event.preventDefault();compose.requestSubmit();}});
  compose.addEventListener('submit',event=>{
    event.preventDefault();const message=input.value.trim();
    if(!validAgentMessage(message)){input.focus();return;}
    if(canvas){void send(message);return;}
    try{sessionStorage.setItem(STORE_KEY,JSON.stringify(agentPendingPayload(message)));location.assign('agent-chat.php');}
    catch{const hint=dock.querySelector('.azh-agent-dock-meta span');if(hint)hint.textContent='Open Agent Chat to send your message. Your browser blocked temporary message storage.';}
  });
  if(!canvas)return;
  document.getElementById('azh-chat-new')?.addEventListener('click',()=>{
    if(busy)return;
    currentThread=null;lastData=null;
    transcript.replaceChildren();addWelcome();
    for(const b of threadList.querySelectorAll('button'))b.classList.remove('is-active');
    setStatus('New conversation · type your first message below.');input.focus();
  });
  void (async()=>{
    await load();
    let queued=null;
    try{queued=JSON.parse(sessionStorage.getItem(STORE_KEY)||'null');sessionStorage.removeItem(STORE_KEY);}catch{}
    if(queued&&validAgentMessage(queued.text)&&Date.now()-queued.at<300000){
      input.value=queued.text;await send(queued.text);
    }
  })();
}
if(typeof document!=='undefined')bind();
