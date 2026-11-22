import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';
import {webcrypto} from 'node:crypto';

const source=(await readFile(new URL('../assets/work-thread.js',import.meta.url),'utf8')).replace(/^export /gm,'');
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const plain=value=>JSON.parse(JSON.stringify(value));
const decode=value=>String(value||'').replace(/&quot;/g,'"').replace(/&#39;/g,"'").replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&amp;/g,'&');
function deferred(){let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return{promise,resolve,reject};}
function storage(){const values=new Map();return{getItem:key=>values.get(key)||null,setItem:(key,value)=>values.set(key,String(value)),removeItem:key=>values.delete(key),values};}
const msg=(sequence,extra={})=>({id:'m'+sequence,sequence,authorId:2,authorName:'Model Two',text:'Question '+sequence,createdAt:'2026-11-19T20:00:00Z',isOwn:false,...extra});
const thread=(extra={})=>({assignmentId:'assignment-a',messages:[msg(1)],hasMore:false,oldestSequence:1,latestSequence:1,unreadCount:1,canPost:true,readOnlyReason:'',csrf:'fresh-csrf',...extra});

function setup({api:handler,saved=storage(),userId=1,canMutate=()=>true}={}){
  const nodes=new Map(),listeners=new Map(),calls=[],errors=[],counts=[];let html='',observerCallback=null,handlerFn=handler;
  const node=selector=>{if(!nodes.has(selector))nodes.set(selector,{value:'',textContent:'',innerHTML:'',disabled:false,hidden:false,setAttribute(name,value){this[name]=value;},matches:other=>other===selector});return nodes.get(selector);};
  const container={setAttribute(){},querySelector:node,addEventListener(name,callback){listeners.set(name,callback);}};
  Object.defineProperty(container,'innerHTML',{get:()=>html,set(value){html=value;const text=value.match(/<textarea data-wt-input[^>]*>([\s\S]*?)<\/textarea>/);node('[data-wt-input]').value=decode(text?.[1]||'');}});
  class Observer{constructor(callback){observerCallback=callback;}observe(){}disconnect(){observerCallback=null;}}
  const api=async(action,payload=null,query={},csrf='')=>{calls.push({action,payload:payload?plain(payload):payload,query:plain(query),csrf});if(handlerFn){const result=await handlerFn(action,payload,query,csrf);if(result!==undefined)return plain(result);}if(action==='thread')return thread();if(action==='send')return thread({messages:[msg(1),msg(2,{text:payload.text,isOwn:true,authorId:userId})],latestSequence:2,sentMessageId:'m2'});if(action==='markRead')return{assignmentId:'assignment-a',throughSequence:payload.throughSequence,unreadCount:0,csrf:'fresh-csrf'};throw new Error('Unexpected action '+action);};
  const context={console,URL,Intl,Date,crypto:webcrypto,AbortController,sessionStorage:saved,IntersectionObserver:Observer,document:{visibilityState:'visible'},location:{href:'https://studio.example.test/my-work.php'}};
  vm.runInNewContext(source+'\nglobalThis.functions={mountWorkThread,threadMessageMarkup};',context);
  const mounted=context.functions.mountWorkThread(container,{assignment:{id:'assignment-a',questionUnreadCount:1},user:{id:userId},csrf:'old-csrf',api,canMutate,onUnread:count=>counts.push(count),onError:error=>errors.push(error)});
  return{mounted,container,node,calls,errors,counts,saved,document:context.document,functions:context.functions,setApi(fn){handlerFn=fn;},type(value){const input=node('[data-wt-input]');input.value=value;listeners.get('input')({target:input});},async send(){listeners.get('submit')({target:{matches:selector=>selector==='[data-work-thread-form]'},preventDefault(){}});await tick();},async click(selector){listeners.get('click')({target:{closest:value=>value===selector?{}:null},preventDefault(){}});await tick();},async show(){observerCallback?.([{isIntersecting:true}]);await tick();}};
}

test('conversation messages escape text and retain line breaks without creating HTML',async()=>{
  const ui=setup({api:async action=>action==='thread'?thread({messages:[msg(1,{authorName:'<b>Model</b>',text:'<script>unsafe()</script>\nSecond line.'})]}):undefined});await tick();assert.match(ui.container.innerHTML,/&lt;script&gt;/);assert.match(ui.container.innerHTML,/&lt;b&gt;Model/);assert.match(ui.container.innerHTML,/Second line/);assert.doesNotMatch(ui.container.innerHTML,/<script>|<b>Model/);
});

test('sending captures exact text while preserving a new draft typed during the request',async()=>{
  const response=deferred();const ui=setup({api:async action=>action==='send'?response.promise:undefined});await tick();ui.type('Which wardrobe should I use?');const save=ui.send();assert.equal(ui.node('[data-wt-send]').disabled,true);assert.equal(ui.node('[data-wt-input]').disabled,false);ui.type('Also, should the lighting stay warm?');response.resolve(thread({messages:[msg(1),msg(2,{isOwn:true,text:'Which wardrobe should I use?'})],latestSequence:2,sentMessageId:'m2'}));await save;await tick();assert.equal(ui.calls.find(item=>item.action==='send').payload.text,'Which wardrobe should I use?');assert.equal(ui.node('[data-wt-input]').value,'Also, should the lighting stay warm?');assert.equal(ui.mounted.isDirty(),true);
});

test('a lost send response preserves its text and request ID across reopening',async()=>{
  const saved=storage();let attempts=0;const api=async(action,payload)=>{if(action==='send'&&++attempts===1)throw new Error('Connection stopped.');};
  const first=setup({saved,api});await tick();first.type('Do you want a wider frame?');await first.send();const initial=first.calls.find(item=>item.action==='send').payload;assert.match(first.container.innerHTML,/Previous message/);first.mounted.destroy();const second=setup({saved,api});await tick();second.type('This is my next question.');await second.send();const retry=second.calls.find(item=>item.action==='send').payload;assert.deepEqual(retry,initial);assert.equal(second.node('[data-wt-input]').value,'This is my next question.');assert.equal(second.node('[data-wt-send]').textContent,'Send reply');
});

test('duplicate send gestures start only one request and failed requests retain drafts',async()=>{
  const response=deferred();let sends=0;const ui=setup({api:async action=>{if(action==='send'){sends++;return response.promise;}}});await tick();ui.type('Keep this filming question.');const first=ui.send();await ui.send();assert.equal(sends,1);response.reject(new Error('Sample request failed.'));await first;await tick();assert.equal(ui.mounted.isBusy(),false);assert.equal(ui.mounted.isDirty(),true);assert.equal(ui.node('[data-wt-input]').value,'Keep this filming question.');assert.equal(ui.node('[data-wt-send]').textContent,'Retry previous message');
});

test('mark read acknowledges the highest rendered message rather than an unseen latest sequence',async()=>{
  const ui=setup({api:async action=>action==='thread'?thread({messages:[msg(8),msg(9)],oldestSequence:8,latestSequence:100,hasMore:true,unreadCount:8}):undefined});await tick();assert.equal(ui.calls.filter(item=>item.action==='markRead').length,0);await ui.click('[data-wt-read]');assert.equal(ui.calls.find(item=>item.action==='markRead').payload.throughSequence,9);
});

test('earlier pagination asks for the current oldest sequence and keeps ascending message order',async()=>{
  const ui=setup({api:async(action,payload,query)=>{if(action==='thread')return query.beforeSequence?thread({messages:[msg(1),msg(2)],oldestSequence:1,latestSequence:4,hasMore:false}):thread({messages:[msg(3),msg(4)],oldestSequence:3,latestSequence:4,hasMore:true});}});await tick();await ui.click('[data-wt-earlier]');assert.equal(ui.calls.filter(item=>item.action==='thread')[1].query.beforeSequence,3);const html=ui.container.innerHTML;assert.ok(html.indexOf('Question 1')<html.indexOf('Question 2'));assert.ok(html.indexOf('Question 2')<html.indexOf('Question 3'));assert.ok(html.indexOf('Question 3')<html.indexOf('Question 4'));assert.doesNotMatch(html,/data-wt-earlier/);
});

test('late read responses cannot erase newly loaded unread counts and visible arrivals are acknowledged again',async()=>{
  const firstRead=deferred(),secondRead=deferred();let reads=0,loads=0;
  const ui=setup({api:async(action,payload)=>{if(action==='thread')return ++loads===1?thread():thread({messages:[msg(1),msg(2)],latestSequence:2,unreadCount:2});if(action==='markRead')return ++reads===1?firstRead.promise:secondRead.promise;}});await tick();await ui.show();assert.equal(reads,1);await ui.click('[data-wt-refresh]');assert.equal(ui.node('[data-wt-unread]').textContent,'2');firstRead.resolve({assignmentId:'assignment-a',throughSequence:1,unreadCount:0});await tick();assert.equal(reads,2);assert.equal(ui.node('[data-wt-unread]').textContent,'2');assert.equal(ui.calls.filter(item=>item.action==='markRead')[1].payload.throughSequence,2);secondRead.resolve({assignmentId:'assignment-a',throughSequence:2,unreadCount:0});await tick();assert.equal(ui.node('[data-wt-unread]').hidden,true);
});

test('an unseen arrival stays unread when only older messages are displayed',async()=>{
  const pending=deferred();let loads=0;const ui=setup({api:async(action,payload)=>{if(action==='thread')return ++loads===1?thread():thread({messages:[msg(1)],latestSequence:3,unreadCount:2});if(action==='markRead')return pending.promise;}});await tick();await ui.show();await ui.click('[data-wt-refresh]');pending.resolve({throughSequence:1,unreadCount:0});await tick();assert.equal(ui.calls.filter(item=>item.action==='markRead').length,1);assert.equal(ui.node('[data-wt-unread]').textContent,'2');assert.equal(ui.node('[data-wt-unread]').hidden,false);
});

test('CSRF recovery retries the same message without wiping new typing',async()=>{
  let attempts=0;const ui=setup({api:async action=>{if(action==='send'&&++attempts===1){const error=new Error('Token expired.');error.code='csrf_invalid';throw error;}}});await tick();ui.type('Use a clean background?');await ui.send();const sends=ui.calls.filter(item=>item.action==='send');assert.equal(sends.length,2);assert.deepEqual(sends[0].payload,sends[1].payload);assert.equal(sends[1].csrf,'fresh-csrf');assert.equal(ui.node('[data-wt-input]').value,'');assert.equal(ui.mounted.isDirty(),false);
});

test('read-only history blocks new messages but lets an interrupted send recover its existing receipt',async()=>{
  const saved=storage();saved.setItem('azhottie-assignment-thread:1:assignment-a',JSON.stringify({draft:'Saved before closure.',pending:{text:'Saved before closure.',requestId:'known-request-1234'}}));
  const ui=setup({saved,api:async action=>action==='thread'?thread({canPost:false,readOnlyReason:'This production is completed.'}):undefined});await tick();assert.equal(ui.node('[data-wt-send]').disabled,false);await ui.send();assert.equal(ui.calls.find(item=>item.action==='send').payload.requestId,'known-request-1234');
  const closed=setup({api:async action=>action==='thread'?thread({canPost:false,readOnlyReason:'This assignment is cancelled.'}):undefined});await tick();closed.type('A new message');await closed.send();assert.equal(closed.calls.filter(item=>item.action==='send').length,0);assert.match(closed.container.innerHTML,/This assignment is cancelled/);
});

test('draft storage is scoped to the account and destroyed views ignore late reads',async()=>{
  const saved=storage(),first=setup({saved,userId:7});await tick();first.type('Private unsent question.');first.mounted.destroy();const other=setup({saved,userId:8});await tick();assert.equal(other.node('[data-wt-input]').value,'');const same=setup({saved,userId:7});await tick();assert.equal(same.node('[data-wt-input]').value,'Private unsent question.');
  const pending=deferred(),late=setup({api:async action=>action==='thread'?pending.promise:undefined});const html=late.container.innerHTML;late.mounted.destroy();pending.resolve(thread({messages:[msg(5,{text:'Late private text.'})]}));await tick();assert.equal(late.container.innerHTML,html);
});

test('capture and parent mutation guards prevent a new send without changing its text',async()=>{
  const ui=setup({canMutate:()=>false});await tick();ui.type('Keep this until the upload finishes.');await ui.send();assert.equal(ui.calls.filter(item=>item.action==='send').length,0);assert.equal(ui.node('[data-wt-input]').value,'Keep this until the upload finishes.');
});


test('a refresh that finishes after the tab is hidden does not acknowledge unseen replies',async()=>{
  const pending=deferred();let loads=0;const ui=setup({api:async action=>{if(action==='thread')return ++loads===1?thread({unreadCount:0}):pending.promise;}});await tick();await ui.show();const refresh=ui.click('[data-wt-refresh]');ui.document.visibilityState='hidden';pending.resolve(thread({messages:[msg(1),msg(2)],latestSequence:2,unreadCount:1}));await refresh;await tick();assert.equal(ui.calls.filter(item=>item.action==='markRead').length,0);assert.equal(ui.node('[data-wt-unread]').textContent,'1');ui.document.visibilityState='visible';await ui.show();assert.equal(ui.calls.filter(item=>item.action==='markRead').length,1);assert.equal(ui.calls.find(item=>item.action==='markRead').payload.throughSequence,2);
});
