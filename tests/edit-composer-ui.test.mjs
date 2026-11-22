import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';
import {webcrypto} from 'node:crypto';

// Exercises the production controller against a small DOM/event adapter. This
// is behavioral logic verification, not a visual browser or hardware test.
const source=(await readFile(new URL('../assets/edit-composer.js',import.meta.url),'utf8')).replace(/^export /gm,'');
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const plain=value=>JSON.parse(JSON.stringify(value));
function deferred(){let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return{promise,resolve,reject};}
function store(){const values=new Map();return{getItem:key=>values.get(key)??null,setItem:(key,value)=>values.set(key,String(value)),removeItem:key=>values.delete(key),values};}
const settings={enabled:true,configured:true,dailyLimit:20,usage:{daily:1,pending:0}};
const ready={id:'proposal-one',projectId:'project-one',state:'ready',stale:false,fingerprint:'source-fingerprint',
  summary:'Open with the reviewed introduction.',edit:{order:['take-one'],segments:{'take-one':{included:true,title:'Opening',trimStart:.3,trimEnd:1,showCard:false}},settings:{cardMode:'none'}},
  items:[{takeId:'take-one',startMs:300,endMs:1000}],durationMs:700,targetDurationMs:30000};
function error(code,message='A controlled test failure.',status=409){return Object.assign(new Error(message),{code,status});}

function setup(api,{saved=store(),userId=1,projectId='project-one',snapshot,canRequest=()=>true,onProposal=async()=>{}}={}){
  const nodes=new Map(),handlers=new Map(),formHandlers=new Map(),calls=[];
  const node=name=>{
    if(!nodes.has(name))nodes.set(name,{value:'',textContent:'',innerHTML:'',hidden:false,disabled:false,attributes:{},
      setAttribute(key,value){this.attributes[key]=String(value);}});
    return nodes.get(name);
  };
  const register=(map,type,callback,options={})=>{const list=map.get(type)||[];list.push({callback,signal:options.signal});map.set(type,list);};
  const form={elements:{preset:node('preset'),seconds:node('seconds'),direction:node('direction')},
    querySelectorAll:()=>Object.values(form.elements),addEventListener:(type,callback,options)=>register(formHandlers,type,callback,options)};
  const container={innerHTML:'',attributes:{},setAttribute(key,value){this.attributes[key]=String(value);},
    querySelector(selector){
      if(selector==='[data-composer-form]')return form;
      if(selector==='[data-use-proposal]'&&!node('[data-composer-result]').innerHTML.includes('data-use-proposal'))return null;
      return node(selector);
    },addEventListener:(type,callback,options)=>register(handlers,type,callback,options)};
  let director=snapshot||{fingerprint:'source-fingerprint',project:{id:projectId,status:'active'}};
  const context={console,URL,location:{href:'https://studio.example.test/director.php?projectId='+projectId},AbortController,
    crypto:webcrypto,structuredClone,sessionStorage:saved};
  vm.runInNewContext(source+'\nglobalThis.mount=mountEditComposer;globalThis.canUse=composerCanUse;',context);
  const mounted=context.mount(container,{project:{id:projectId,interviewId:'interview-one'},csrf:'test-csrf',user:{id:userId},
    storage:saved,getDirector:()=>director,canRequest,onProposal,
    api:(action,payload,query,csrf)=>{calls.push({action,payload:payload===null?null:plain(payload),query:plain(query),csrf});return api(action,payload,query,csrf);}});
  const dispatch=async(map,type,event)=>{for(const {callback,signal}of map.get(type)||[])if(!signal?.aborted)await callback(event);};
  return {mounted,container,form,node,calls,saved,key:`azhottie-edit-assistant:${userId}:${projectId}`,
    setSnapshot:value=>{director=value;},
    submit:()=>dispatch(formHandlers,'submit',{preventDefault(){}}),
    input:()=>dispatch(handlers,'input',{target:form.elements.direction}),
    click:selector=>dispatch(handlers,'click',{target:{closest:match=>match===selector?node(selector):null}})};
}

test('initial settings load and pending generation lock inputs and recover after a receipt',async()=>{
  const initial=deferred(),generation=deferred();let settingsCalls=0;
  const ui=setup(action=>action==='settings'?(++settingsCalls===1?initial.promise:Promise.resolve(settings)):action==='latest'?Promise.resolve(null):generation.promise);
  assert.equal(ui.mounted.isBusy(),true);assert.ok(Object.values(ui.form.elements).every(control=>control.disabled));
  initial.resolve(settings);await tick();
  assert.equal(ui.mounted.isBusy(),false);assert.equal(ui.node('[data-generate]').disabled,false);
  ui.form.elements.preset.value='30';ui.form.elements.direction.value='Keep the opening concise.';
  const pending=ui.submit();
  assert.equal(ui.mounted.isBusy(),true);assert.equal(ui.mounted.isDirty(),true);
  assert.ok(Object.values(ui.form.elements).every(control=>control.disabled));
  assert.equal(ui.node('[data-check]').disabled,true);
  generation.resolve(ready);await pending;
  assert.equal(ui.mounted.isBusy(),false);assert.equal(ui.mounted.isDirty(),false);
  assert.ok(Object.values(ui.form.elements).every(control=>!control.disabled));
  assert.match(ui.node('[data-composer-result]').innerHTML,/reviewed introduction/);
  assert.equal(ui.calls.find(c=>c.action==='generate').csrf,'test-csrf');
});

test('lost receipt retains exact token and directions across remount and request 404',async()=>{
  const saved=store(),requests=[];let attempts=0;
  const api=async(action,payload)=>{
    if(action==='settings')return settings;
    if(action==='latest')return null;
    if(action==='request')throw error('request_not_received','The server has not saved this request.',404);
    if(action==='generate'){
      requests.push(plain(payload));
      if(++attempts===1)throw new Error('Connection interrupted after sending.');
      return ready;
    }
    throw new Error('Unexpected action '+action);
  };
  const first=setup(api,{saved});await tick();
  first.form.elements.preset.value='custom';first.form.elements.seconds.value='47';first.form.elements.direction.value='Use the favorite reaction after the product detail.';
  await first.input();await first.submit();
  assert.equal(first.mounted.isDirty(),true);assert.equal(first.form.elements.direction.disabled,true);
  first.mounted.destroy();
  const second=setup(api,{saved});await tick();
  assert.equal(second.form.elements.preset.value,'custom');assert.equal(second.form.elements.seconds.value,'47');
  assert.equal(second.form.elements.direction.value,requests[0].direction);
  assert.equal(second.mounted.isDirty(),true);assert.match(second.node('[data-composer-message]').textContent,/not saved/);
  assert.equal(second.calls.some(c=>c.action==='latest'),false);
  assert.equal(second.calls.find(c=>c.action==='request').query.requestToken,requests[0].requestToken);
  await second.submit();
  assert.equal(requests.length,2);assert.deepEqual(requests[1],requests[0]);assert.match(requests[0].requestToken,/^[a-f0-9]{32,}$/);
  assert.equal(second.mounted.isDirty(),false);
});

test('pending receipt lookup targets the exact token instead of an unrelated latest proposal',async()=>{
  const saved=store(),token='pending-original-request-token';
  saved.setItem('azhottie-edit-assistant:1:project-one',JSON.stringify({targetDurationSeconds:30,direction:'Original direction.',pending:{requestToken:token,targetDurationSeconds:30,direction:'Original direction.'}}));
  const ui=setup(async(action,payload,query)=>{
    if(action==='settings')return settings;
    if(action==='request'){assert.equal(query.requestToken,token);return{...ready,id:'pending-original',state:'pending',edit:null};}
    if(action==='latest')return{...ready,id:'unrelated-latest',summary:'Do not load this.'};
    throw new Error('Unexpected action');
  },{saved});await tick();
  assert.deepEqual(ui.calls.map(c=>c.action),['settings','request']);
  assert.equal(ui.mounted.isDirty(),true);assert.equal(ui.form.elements.preset.disabled,true);
  assert.match(ui.node('[data-composer-result]').innerHTML,/preparing this proposal/);
  assert.doesNotMatch(ui.node('[data-composer-result]').innerHTML,/Do not load this/);
});

test('stale or changed-source proposals cannot be loaded into the first-cut builder',async()=>{
  const loaded=[];
  const ui=setup(async action=>action==='settings'?settings:ready,{onProposal:async proposal=>loaded.push(proposal)});await tick();
  assert.equal(ui.node('[data-use-proposal]').disabled,false);
  ui.setSnapshot({fingerprint:'changed-source',project:{status:'active'}});
  await ui.click('[data-use-proposal]');assert.equal(loaded.length,0);
  assert.equal(ui.node('[data-use-proposal]').disabled,true);assert.match(ui.node('[data-composer-message]').textContent,/stale/);
  ui.setSnapshot({fingerprint:ready.fingerprint,project:{status:'archived'}});
  await ui.click('[data-use-proposal]');assert.equal(loaded.length,0);
  ui.setSnapshot({fingerprint:ready.fingerprint,project:{status:'completed'}});
  await ui.click('[data-use-proposal]');assert.equal(loaded.length,1);
  loaded[0].summary='Changed local review';
  await ui.click('[data-use-proposal]');assert.equal(loaded.length,2);
  assert.equal(loaded[1].summary,ready.summary);
  const stale=setup(async action=>action==='settings'?settings:{...ready,stale:true},{onProposal:async p=>loaded.push(p)});await tick();
  await stale.click('[data-use-proposal]');assert.equal(loaded.length,2);
});

test('local directions and pending identities are scoped by user and project',async()=>{
  const saved=store(),api=async action=>action==='settings'?settings:null;
  const first=setup(api,{saved,userId:7});await tick();
  first.form.elements.direction.value='Admin seven editorial intent.';first.form.elements.preset.value='30';await first.input();first.mounted.destroy();
  const otherUser=setup(api,{saved,userId:8});await tick();
  assert.equal(otherUser.form.elements.direction.value,'');assert.equal(otherUser.form.elements.preset.value,'60');
  const otherProject=setup(api,{saved,userId:7,projectId:'project-two'});await tick();
  assert.equal(otherProject.form.elements.direction.value,'');
  const restored=setup(api,{saved,userId:7});await tick();
  assert.equal(restored.form.elements.direction.value,'Admin seven editorial intent.');assert.equal(restored.form.elements.preset.value,'30');
});

test('late success from a destroyed view leaves the replacement view and persistence unchanged',async()=>{
  const saved=store(),generation=deferred();
  const first=setup(action=>action==='settings'?Promise.resolve(settings):action==='latest'?Promise.resolve(null):generation.promise,{saved});await tick();
  first.form.elements.direction.value='Old generation.';const request=first.submit();first.mounted.destroy();
  const second=setup(async action=>action==='settings'?settings:ready,{saved});await tick();
  second.form.elements.direction.value='New generation direction.';await second.input();
  const persisted=saved.getItem(second.key),markup=second.node('[data-composer-result]').innerHTML;
  generation.resolve({...ready,summary:'Late obsolete result.'});await request;
  assert.equal(saved.getItem(second.key),persisted);assert.equal(second.node('[data-composer-result]').innerHTML,markup);
});

test('late rejection from a destroyed view cannot overwrite a newer pending request',async()=>{
  const saved=store(),firstGeneration=deferred(),secondGeneration=deferred();
  const first=setup(action=>action==='settings'?Promise.resolve(settings):action==='latest'?Promise.resolve(null):firstGeneration.promise,{saved});await tick();
  first.form.elements.direction.value='Old attempt.';const oldRequest=first.submit();first.mounted.destroy();
  const second=setup(action=>action==='settings'?Promise.resolve(settings):action==='request'?Promise.resolve(ready):secondGeneration.promise,{saved});await tick();
  second.form.elements.direction.value='New independent editorial request.';const newRequest=second.submit();
  const expected=saved.getItem(second.key);
  firstGeneration.reject(error('request_conflict','Old request rejected.'));await oldRequest;
  const actual=saved.getItem(second.key);
  secondGeneration.resolve(ready);await newRequest;
  assert.equal(actual,expected);
});

test('definite target validation errors unlock directions for correction while retaining typed text',async()=>{
  const tokens=[];let attempt=0;
  const ui=setup(async(action,payload)=>{
    if(action==='settings')return settings;if(action==='latest')return null;
    if(action==='generate'){tokens.push(payload.requestToken);if(++attempt===1)throw error('target_too_short','The target is too short for the selected title cards.',400);return ready;}
    throw new Error('Unexpected action');
  });await tick();
  ui.form.elements.preset.value='custom';ui.form.elements.seconds.value='1';ui.form.elements.direction.value='Keep this editorial direction.';
  await ui.submit();
  assert.equal(ui.mounted.isDirty(),false);assert.equal(ui.form.elements.seconds.disabled,false);assert.equal(ui.form.elements.direction.disabled,false);
  assert.equal(ui.form.elements.direction.value,'Keep this editorial direction.');
  ui.form.elements.seconds.value='30';await ui.submit();assert.notEqual(tokens[0],tokens[1]);
});

test('assistant-off state disables generation but permits saved-proposal handoff to manual editing',async()=>{
  const loaded=[];
  const ui=setup(async action=>action==='settings'?{...settings,enabled:false,configured:false}:ready,{onProposal:async p=>loaded.push(p)});await tick();
  assert.equal(ui.node('[data-generate]').disabled,true);assert.equal(ui.node('[data-check]').disabled,false);
  assert.equal(ui.form.elements.direction.disabled,false);assert.match(ui.node('[data-composer-status]').textContent,/manual first-cut builder is available/);
  await ui.click('[data-use-proposal]');assert.equal(loaded.length,1);
  assert.deepEqual(plain(loaded[0].edit),ready.edit);
});

test('saved proposal summaries and failure messages are escaped before HTML rendering',async()=>{
  const injection='<img src=x onerror="alert(1)"> & <script>unsafe()</script>';
  const success=setup(async action=>action==='settings'?settings:{...ready,summary:injection});await tick();
  const html=success.node('[data-composer-result]').innerHTML;
  assert.doesNotMatch(html,/<img|<script|onerror="/);assert.match(html,/&lt;img/);assert.match(html,/&amp;/);
  const failed=setup(async action=>action==='settings'?settings:{...ready,state:'failed',error:injection});await tick();
  const failedHtml=failed.node('[data-composer-result]').innerHTML;
  assert.doesNotMatch(failedHtml,/<img|<script/);assert.match(failedHtml,/&lt;script/);
});

test('unsaved Director forms block fresh generation while exact pending retries remain available',async()=>{
  let clean=false,attempt=0;const requests=[];
  const ui=setup(async(action,payload)=>{
    if(action==='settings')return settings;if(action==='latest')return null;
    if(action==='generate'){requests.push(plain(payload));if(++attempt===1)throw new Error('Receipt lost.');return ready;}
    throw new Error('Unexpected action');
  },{canRequest:()=>clean});await tick();
  await ui.submit();
  assert.equal(requests.length,0);assert.equal(ui.mounted.isDirty(),false);
  assert.match(ui.node('[data-composer-message]').textContent,/Save your coverage requirement or usable-section changes/);
  clean=true;ui.form.elements.direction.value='Saved production intent.';await ui.submit();
  assert.equal(requests.length,1);assert.equal(ui.mounted.isDirty(),true);
  clean=false;await ui.submit();
  assert.equal(requests.length,2);assert.deepEqual(requests[1],requests[0]);assert.equal(ui.mounted.isDirty(),false);
});

test('a declined first-cut handoff never announces that the proposal was loaded',async()=>{
  let reviews=0;
  const ui=setup(async action=>action==='settings'?settings:ready,{onProposal:async()=>{reviews++;return false;}});await tick();
  const before=ui.node('[data-composer-message]').textContent;
  await ui.click('[data-use-proposal]');
  assert.equal(reviews,1);assert.equal(ui.mounted.isBusy(),false);
  assert.equal(ui.node('[data-composer-message]').textContent,before);
  assert.doesNotMatch(ui.node('[data-composer-message]').textContent,/Proposal loaded/);
});
