import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';
import {webcrypto} from 'node:crypto';

const source=(await readFile(new URL('../assets/production-plan.js',import.meta.url),'utf8')).replace(/^export /gm,'');
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const plain=value=>JSON.parse(JSON.stringify(value));
const decode=value=>String(value||'').replace(/&quot;/g,'"').replace(/&#39;/g,"'").replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&amp;/g,'&');
const id=n=>String(n).padStart(32,'0');
const project={id:id(1),title:'Collection launch',brief:'A short studio introduction.',status:'active',canManage:true};
const user={id:1,name:'Producer',roles:['admin']};
const member=(userId,extra={})=>({userId,name:'Model '+userId,roles:['model'],accountStatus:'active',eligible:true,...extra});
const roster={projectId:project.id,revision:4,members:[member(2),member(3),member(4,{roles:['professional']})]};
const settings={enabled:true,configured:true,provider:'openai',model:'fixture-model'};
const shot=(extra={})=>({id:id(10),title:'Introduce the collection',framing:'Medium shot.',instructions:'Introduce the product naturally.',orientation:'portrait',targetDurationSeconds:15,takesRequested:2,distribution:'unassigned',userIds:[],eligibleRoles:['model'],maxContributors:1,dueLocal:null,shotId:null,...extra});
const scene=(extra={})=>({id:id(5),title:'Opening',action:'Face the camera.',dialogue:'Here is the new collection.',props:'One sample product.',performerRole:'Presenter',shots:[shot()],locked:false,...extra});
const plan=(extra={})=>({id:project.id,projectId:project.id,revision:3,objective:'Introduce the range.',productFocus:'Clothing',format:'portrait',targetDurationSeconds:60,creativeDirection:'Warm studio lighting.',deliverables:'Main video and teaser.',scenes:[scene()],canEdit:true,...extra});
const proposal={id:id(90),projectId:project.id,state:'ready',provider:'openai',model:'fixture-model',summary:'A new closing scene.',scenes:[{title:'Closing',action:'Show the collection.',dialogue:'Explore the new collection.',props:'Product',performerRole:'Presenter',shots:[{title:'Close the video',framing:'Medium shot',instructions:'End on the product.',orientation:'portrait',targetDurationSeconds:12,takesRequested:2}]}]};
function storage(){const values=new Map();return{getItem:key=>values.get(key)||null,setItem:(key,value)=>values.set(key,String(value)),removeItem:key=>values.delete(key),values};}
function deferred(){let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return{promise,resolve,reject};}

function setup({saved=storage(),api:handler,initialPlan=plan(),initialRoster=roster,initialSettings=settings,currentUser=user,canMutate=()=>true}={}){
  let controls=[],details=[],html='',handlerFn=handler;
  const listeners=new Map(),calls=[],errors=[],special=new Map();let created=0;
  const node=(extra={})=>({dataset:{},value:'',disabled:false,hidden:false,textContent:'',isConnected:true,type:'button',setAttribute(name,value){this[name]=value;},scrollIntoView(){},...extra});
  const matches=(item,selector)=>{const attributes=[...selector.matchAll(/\[([^=\]]+)(?:="([^"]*)")?\]/g)];if(!attributes.length)return false;return attributes.every(([,name,value])=>{if(name.startsWith('data-')){const key=name.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase());return key in item.dataset&&(value===undefined||item.dataset[key]===value);}return name in item&&(value===undefined||String(item[name])===value);});};
  const specialNode=selector=>{if(!special.has(selector))special.set(selector,node());return special.get(selector);};
  const container={setAttribute(){},addEventListener(type,handler){listeners.set(type,handler);},querySelector(selector){if(selector==='[data-pp-message]'||selector==='.work-section-heading > .badge')return specialNode(selector);if(selector==='.production-plan-review')return html.includes('class="production-plan-review"')?specialNode(selector):null;return controls.find(item=>matches(item,selector))||null;},querySelectorAll(selector){if(selector==='details[data-pp-scene]')return details;if(selector==='button,input,textarea,select')return controls;return[];}};
  Object.defineProperty(container,'innerHTML',{get:()=>html,set(value){html=value;controls=[];details=[];for(const match of value.matchAll(/<(input|textarea|select|button)\b([^>]*)(?:>([\s\S]*?)<\/\1>|>)/g)){const item=node({tagName:match[1].toUpperCase()});for(const attr of match[2].matchAll(/([\w:-]+)(?:="([^"]*)")?/g)){const name=attr[1],value=decode(attr[2]);if(name.startsWith('data-'))item.dataset[name.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]=value;else item[name]=attr[2]===undefined?true:value;}if(item.tagName==='TEXTAREA')item.value=decode(match[3]);item.closest=selector=>matches(item,selector)?item:null;item.matches=selector=>matches(item,selector);controls.push(item);}for(const match of value.matchAll(/<details\b[^>]*data-pp-scene="([^"]+)"([^>]*)>/g))details.push({dataset:{ppScene:match[1]},open:/\bopen\b/.test(match[2])});}});
  const api=async(action,payload=null,query={},csrf='')=>{calls.push({action,payload:payload?plain(payload):payload,query:plain(query),csrf});if(handlerFn){const result=await handlerFn(action,payload,query,csrf);if(result!==undefined)return plain(result);}if(action==='bootstrap')return{project:plain(project),plan:plain(initialPlan),roster:plain(initialRoster),aiSettings:plain(initialSettings),latestProposal:null,csrf:'fresh-token'};if(action==='save')return plan({...plain(payload.plan),revision:payload.revision+1});if(action==='latest')return null;throw new Error('Unexpected '+action);};
  const context={console,URL,location:{href:'https://studio.example.test/productions.php'},crypto:webcrypto,AbortController,sessionStorage:saved,confirm:()=>true};
  vm.runInNewContext(source+'\nglobalThis.functions={mountProductionPlan,planReviewRows,planSceneMarkup};',context);
  const mounted=context.functions.mountProductionPlan(container,{project:plain(project),user:currentUser,csrf:'old-token',api,canMutate,onError:error=>errors.push(error),onWorkCreated:()=>{created++;}});
  return{mounted,container,calls,errors,saved,functions:context.functions,get created(){return created;},setApi(fn){handlerFn=fn;},find(selector){return container.querySelector(selector);},field(name,sceneId='',shotId=''){return controls.find(item=>item.dataset.ppField===name&&(item.dataset.sceneId||'')===sceneId&&(item.dataset.shotId||'')===shotId);},input(control,value){control.value=value;listeners.get('input')({target:control});},change(control,value){control.value=value;listeners.get('change')({target:control});},async click(action,extra={}){const target=controls.find(item=>item.dataset.ppAction===action&&Object.entries(extra).every(([key,value])=>item.dataset[key]===value))||node({dataset:{ppAction:action,...extra}});target.closest=selector=>selector==='[data-pp-action]'?target:null;target.type='button';listeners.get('click')({target,preventDefault(){}});await tick();},async save(){listeners.get('submit')({target:{matches:selector=>selector==='[data-production-plan-form]'},preventDefault(){}});await tick();}};
}

test('manual direction, scenes and casting save together with the original revision',async()=>{
  const ui=setup();await tick();ui.input(ui.field('objective'),'Show the spring range.');ui.input(ui.field('dialogue',id(5)),'A revised opening line.');
  ui.change(ui.field('distribution',id(5),id(10)),'assigned');ui.find('[data-pp-picker][data-shot-id="'+id(10)+'"]').value='2';await ui.click('add-user',{sceneId:id(5),shotId:id(10)});
  await ui.save();const saved=ui.calls.find(item=>item.action==='save');assert.equal(saved.payload.revision,3);assert.equal(saved.payload.plan.objective,'Show the spring range.');assert.equal(saved.payload.plan.scenes[0].dialogue,'A revised opening line.');assert.deepEqual(saved.payload.plan.scenes[0].shots[0].userIds,[2]);assert.ok(saved.payload.requestId);assert.equal(ui.mounted.isDirty(),false);
});

test('changing a direct shot to open clears recipient IDs before the saved request',async()=>{
  const ui=setup({initialPlan:plan({scenes:[scene({shots:[shot({distribution:'assigned',userIds:[2]})]})]})});await tick();ui.change(ui.field('distribution',id(5),id(10)),'open');await ui.save();const item=ui.calls.find(item=>item.action==='save').payload.plan.scenes[0].shots[0];assert.equal(item.distribution,'open');assert.deepEqual(item.userIds,[]);
});

test('scene shot limits stop at twelve while leaving room for another scene',async()=>{
  const ui=setup({initialPlan:plan({scenes:[scene({shots:Array.from({length:12},(_,i)=>shot({id:id(i+100)}))})]})});await tick();assert.equal(ui.find('[data-pp-action="add-shot"]').disabled,true);await ui.click('add-shot',{sceneId:id(5)});await ui.save();assert.equal(ui.calls.find(item=>item.action==='save').payload.plan.scenes[0].shots.length,12);
});

test('initial failure displays a recoverable reload control and preserves a pending local draft',async()=>{
  let fail=true;const ui=setup({api:async action=>{if(action==='bootstrap'&&fail)throw new Error('Hosting connection interrupted.');}});await tick();assert.match(ui.container.innerHTML,/Hosting connection interrupted/);assert.match(ui.container.innerHTML,/Reload saved plan/);assert.equal(ui.mounted.ready(),false);fail=false;await ui.click('reload');assert.equal(ui.mounted.ready(),true);assert.match(ui.container.innerHTML,/Production plan/);
});

test('save retries retain their request ID and an interrupted response leaves edits intact',async()=>{
  const pending=deferred();let saves=0;const ui=setup({api:async(action,payload)=>{if(action==='save'){saves++;if(saves===1)return pending.promise;return plan({...plain(payload.plan),revision:4});}}});await tick();ui.input(ui.field('creativeDirection'),'Keep the careful lighting setup.');const first=ui.save();assert.equal(ui.mounted.isBusy(),true);await ui.save();assert.equal(saves,1);pending.reject(new Error('Response lost.'));await first;await tick();assert.equal(ui.mounted.isDirty(),true);assert.match(ui.container.innerHTML,/Keep the careful lighting setup/);await ui.save();const requests=ui.calls.filter(item=>item.action==='save');assert.equal(requests[0].payload.requestId,requests[1].payload.requestId);assert.equal(ui.mounted.isDirty(),false);
});

test('a stale save keeps its local draft until an explicit saved-plan reload',async()=>{
  let stale=true;const ui=setup({api:async action=>{if(action==='save'&&stale){const error=new Error('Plan changed.');error.code='revision_conflict';throw error;}}});await tick();ui.input(ui.field('objective'),'Keep this plan revision.');await ui.save();assert.equal(ui.mounted.isDirty(),true);assert.match(ui.container.innerHTML,/Keep this plan revision/);assert.equal(ui.find('[data-pp-action="save"]').disabled,true);stale=false;await ui.click('reload');assert.equal(ui.mounted.isDirty(),false);assert.equal(ui.find('[data-pp-action="save"]').disabled,false);
});

test('the apply review contains only uncreated work and sends exact plan and roster revisions',async()=>{
  const existing=scene({id:id(6),locked:true,shots:[shot({id:id(11),shotId:id(51),progress:'approved',assignments:[{id:id(61),userId:2,userName:'Model 2',status:'approved'}]})]});
  const ui=setup({initialPlan:plan({scenes:[existing,scene({shots:[shot({distribution:'assigned',userIds:[3]})]})]}),api:async(action,payload)=>action==='apply'?plan({revision:4,scenes:[existing,scene({locked:true,shots:[shot({shotId:id(52),progress:'assigned'})]})]}):undefined});await tick();await ui.click('review');assert.match(ui.container.innerHTML,/Review 1 new shot/);assert.match(ui.container.innerHTML,/Model 3/);await ui.click('apply');const applied=ui.calls.find(item=>item.action==='apply');assert.equal(applied.payload.revision,3);assert.equal(applied.payload.rosterRevision,4);assert.equal(ui.created,1);await ui.click('apply');assert.equal(ui.calls.filter(item=>item.action==='apply').length,1);
});

test('roster updates preserve unsaved script and invalidate an earlier apply review',async()=>{
  const ui=setup();await tick();await ui.click('review');assert.match(ui.container.innerHTML,/READY TO DISTRIBUTE/);ui.mounted.updateRoster({...roster,revision:5,members:[member(3)]});assert.doesNotMatch(ui.container.innerHTML,/READY TO DISTRIBUTE/);ui.input(ui.field('dialogue',id(5)),'Do not discard my line.');ui.mounted.updateRoster({...roster,revision:6});assert.match(ui.container.innerHTML,/Do not discard my line/);assert.equal(ui.mounted.isDirty(),true);
});

test('generation recovers the original request identity and does not send local model records',async()=>{
  const saved=storage();let attempts=0;const payloads=[];const api=async(action,payload)=>{if(action==='generate'){payloads.push(plain(payload));if(++attempts===1)throw new Error('Lost generation response.');return proposal;}};
  const first=setup({saved,api});await tick();await first.click('generate');assert.equal(first.mounted.isDirty(),false);assert.match(first.container.innerHTML,/Retry same request/);first.mounted.destroy();const second=setup({saved,api});await tick();await second.click('retry-generation');assert.deepEqual(payloads[0],payloads[1]);assert.doesNotMatch(JSON.stringify(payloads),/Model 2|members|userIds/);assert.match(second.container.innerHTML,/A new closing scene/);assert.match(second.container.innerHTML,/<details class="production-plan-ai" open>/);
});

test('known generation validation failure releases its identity so revised input can start a new request',async()=>{
  let count=0;const payloads=[];const ui=setup({api:async(action,payload)=>{if(action==='generate'){payloads.push(plain(payload));if(++count===1){const error=new Error('Choose fewer scenes.');error.code='plan_limit';throw error;}return proposal;}}});await tick();await ui.click('generate');assert.doesNotMatch(ui.container.innerHTML,/Retry same request/);ui.input(ui.find('[data-pp-ai="sceneCount"]'),'1');await ui.click('generate');assert.notEqual(payloads[0].requestToken,payloads[1].requestToken);assert.equal(payloads[1].sceneCount,1);
});

test('adopting an AI script adds editable scenes but does not create assignments',async()=>{
  const ui=setup({api:async(action,payload)=>{if(action==='generate')return proposal;if(action==='useProposal')return plan({revision:4,scenes:[scene(),scene({id:id(25),title:'Closing',shots:[shot({id:id(26),title:'Closing view'})]})]});}});await tick();await ui.click('generate');await ui.click('use-proposal');assert.equal(ui.calls.filter(item=>item.action==='apply').length,0);assert.equal(ui.calls.filter(item=>item.action==='useProposal').length,1);assert.match(ui.container.innerHTML,/Closing view/);assert.equal(ui.mounted.isDirty(),false);
});

test('CSRF renewal preserves the exact save intent and edited draft',async()=>{
  let saves=0;const ui=setup({api:async(action,payload)=>{if(action==='save'&&++saves===1){const error=new Error('Token expired.');error.code='csrf_invalid';throw error;}}});await tick();ui.input(ui.field('objective'),'Keep every edit through token renewal.');await ui.save();const writes=ui.calls.filter(item=>item.action==='save');assert.equal(writes.length,2);assert.deepEqual(writes[0].payload,writes[1].payload);assert.equal(writes[1].csrf,'fresh-token');assert.equal(writes[1].payload.plan.objective,'Keep every edit through token renewal.');assert.equal(ui.mounted.isDirty(),false);
});

test('destroyed views ignore a late response and completed projects reject mutations',async()=>{
  const pending=deferred();const ui=setup({api:async action=>action==='bootstrap'?pending.promise:undefined});const before=ui.container.innerHTML;ui.mounted.destroy();pending.resolve({project,plan:plan(),roster,aiSettings:settings,latestProposal:null});await tick();assert.equal(ui.container.innerHTML,before);
  const readonly=setup({initialPlan:plan({canEdit:false})});await tick();await readonly.click('add-scene');await readonly.save();assert.equal(readonly.calls.filter(item=>item.action==='save').length,0);assert.equal(readonly.find('[data-pp-action="save"]').disabled,true);
});

test('original script escaping and changed-shot notice keep current direction authoritative',()=>{
  const ui=setup();const html=ui.functions.planSceneMarkup({sceneTitle:'<b>Opening</b>',dialogue:'<script>bad()</script>',action:'Move toward camera.',props:'Product',isCurrent:false});assert.match(html,/ORIGINAL PLAN SCRIPT/);assert.match(html,/Follow the current filming instructions above/);assert.match(html,/&lt;script&gt;/);assert.doesNotMatch(html,/<script>|<b>/);ui.mounted.destroy();
});


test('read-only initial loading works inside an outer save while mutation actions remain guarded',async()=>{
  const ui=setup({canMutate:()=>false});await tick();assert.equal(ui.mounted.ready(),true);assert.equal(ui.calls.filter(item=>item.action==='bootstrap').length,1);ui.input(ui.field('objective'),'Keep this until the parent save ends.');await ui.save();assert.equal(ui.calls.filter(item=>item.action==='save').length,0);assert.equal(ui.mounted.isDirty(),true);
});

test('an interrupted generation receipt remains recoverable after the production closes',async()=>{
  const saved=storage();saved.setItem('azhottie-production-plan:1:'+project.id,JSON.stringify({revision:3,pendingGeneration:{payload:{projectId:project.id,revision:3,sceneCount:2,direction:'Original direction',requestToken:'original-request-123456'}}}));
  const ui=setup({saved,initialPlan:plan({canEdit:false}),api:async action=>action==='generate'?proposal:undefined});await tick();assert.equal(ui.find('[data-pp-action="generate"]').disabled,true);assert.equal(ui.find('[data-pp-action="retry-generation"]').disabled,false);await ui.click('retry-generation');assert.equal(ui.calls.find(item=>item.action==='generate').payload.requestToken,'original-request-123456');
});
