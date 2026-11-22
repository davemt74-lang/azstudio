import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import {editVersionRequest,mountEditableVersions} from '../assets/edit-versions.js';

const original=fs.readFileSync(new URL('../assets/app.js',import.meta.url),'utf8');
const source=original.slice(0,original.lastIndexOf('\nmain().catch(')).replace(/^import .*;\n/gm,'');
function fixture(request=async()=>({}),api=async()=>({})) {
  const nodes=new Map();const node=id=>{if(!nodes.has(id))nodes.set(id,{id,value:'',textContent:'',hidden:false,disabled:false,innerHTML:'',pause(){},setAttribute(){},removeAttribute(){},append(){},prepend(){},replaceChildren(){},querySelector(){return null;},classList:{add(){},remove(){},toggle(){}},addEventListener(){},scrollIntoView(){}});return nodes.get(id);};
  let n=0;const context=vm.createContext({console:{...console,error(){}},structuredClone,Blob,URL,DOMException,Map,Set,
    document:{body:{dataset:{page:'editor'}},getElementById:node,querySelector:()=>null,querySelectorAll:()=>[],createElement:t=>node(t),addEventListener(){}},
    window:{addEventListener(){},dispatchEvent(){}},crypto:{randomUUID:()=>`req-${++n}`},setTimeout:()=>1,clearTimeout(){},QUESTIONS:[],
    editVersionRequest:request,editRequestToken:()=>`request-token-${++n}`,api,buildTimeline:()=>({totalDuration:1}),
    pendingPut:async()=>{},pendingList:async()=>[],pendingDelete:async()=>{},uploadVideo:async()=>({})});
  vm.runInContext(source+'\nglobalThis.h={set(i){interview=i;boot={csrf:"csrf"};},get(){return interview;},normalize,markDirty,saveInterview,runEdit,applyEditableTimeline,setRenderer(r){renderer=r;},dirty(){return dirty;},recovery(){return timelineRecoveryRequest;}};',context);
  for(const [id,value]of Object.entries({'card-duration':'3','fade-duration':'.45','aspect-ratio':'16:9','export-resolution':'720','video-fit':'contain','card-mode':'none'}))node(id).value=value;
  return {context,node,h:context.h};
}
const take=id=>({id,questionId:'q',questionText:'Opening',durationMs:2000,answerStartMs:0,reviewStatus:'shortlisted',isPrivate:false,createdAt:'2026-01-01',mediaUrl:`media.php?id=${id}`});
function session(){return{id:'interview',title:'Project',participantName:'Project',revision:1,state:{edit:{order:['take1'],segments:{take1:{included:true,title:'First',trimStart:0,trimEnd:1,showCard:false}},settings:{cardMode:'none'}}},takes:[take('take1')],production:{id:'project',kind:'production',unavailableSelectedIds:[]},timeline:{id:'timeline',title:'Named cut',revision:1,latestRevision:1,targetDurationSeconds:30,status:'active',historical:false}};}
const tick=()=>new Promise(resolve=>setImmediate(resolve));

test('newly accepted sources stay excluded from a named cut while legacy production defaults remain',()=>{
  const f=fixture(),i=session();i.takes.push(take('take2'),{...take('unknown'),durationMs:0});f.h.set(i);f.h.normalize();
  assert.equal(i.state.edit.segments.take1.included,true);assert.equal(i.state.edit.segments.take2.included,false);
  assert.equal(i.state.edit.order.includes('unknown'),false);assert.equal(i.state.edit.segments.unknown,undefined,'Unknown-duration bin footage cannot poison a named-cut save');
  const legacy=session();delete legacy.timeline;legacy.takes.push(take('take2'));f.h.set(legacy);f.h.normalize();assert.equal(legacy.state.edit.segments.take2.included,true);
});

test('named cut saves serialize revisions and never overwrite the legacy interview edit',async()=>{
  const requests=[];let legacyWrites=0;
  const f=fixture((action,body)=>new Promise(resolve=>requests.push({action,body,resolve})),async()=>{legacyWrites++;});
  const i=session();f.h.set(i);f.h.normalize();
  const first=f.h.saveInterview();await tick();i.state.edit.segments.take1.trimStart=.2;const second=f.h.saveInterview();await tick();
  assert.equal(requests.length,1);assert.equal(requests[0].action,'timeline.save');assert.equal(requests[0].body.revision,1);
  requests[0].resolve({...i.timeline,revision:2,latestRevision:2});await first;await tick();
  assert.equal(requests[1].body.revision,2);assert.equal(requests[1].body.edit.segments.take1.trimStart,.2);
  requests[1].resolve({...i.timeline,revision:3,latestRevision:3});await second;
  assert.equal(f.h.get().timeline.revision,3);assert.equal(legacyWrites,0);
});

test('a lost cut-save receipt retries exactly the original body before saving later changes',async()=>{
  const bodies=[];let attempts=0;const i=session();
  const f=fixture(async(action,body)=>{bodies.push(structuredClone(body));attempts++;if(attempts===1)throw new Error('Lost response');return{...i.timeline,revision:attempts===2?2:3,latestRevision:attempts===2?2:3};});
  f.h.set(i);f.h.normalize();await assert.rejects(f.h.saveInterview(),/Lost response/);assert.equal(f.h.dirty(),true);
  i.state.edit.segments.take1.trimStart=.3;await f.h.saveInterview();
  assert.deepEqual(bodies[0],bodies[1]);assert.equal(bodies[2].revision,2);assert.notEqual(bodies[2].requestToken,bodies[1].requestToken);assert.equal(f.h.recovery(),null);
});

test('historical and archived cuts cannot silently autosave or render over the latest revision',async()=>{
  for(const change of [{historical:true},{status:'archived'}]){
    let writes=0;const f=fixture(async()=>{writes++;});const i=session();Object.assign(i.timeline,change);f.h.set(i);f.h.normalize();f.h.markDirty();
    await assert.rejects(f.h.saveInterview(),/new cut/);await assert.rejects(f.h.runEdit(true),/new cut/);assert.equal(writes,0);
  }
});

test('failed source refresh while switching cuts leaves the original edit and dirty changes intact',async()=>{
  const f=fixture(async()=>({}),async()=>{throw new Error('Network interrupted');});const i=session();f.h.set(i);f.h.normalize();f.h.markDirty();
  const previous=structuredClone(i.state.edit);await assert.rejects(f.h.applyEditableTimeline({id:'other',edit:{order:[],segments:{},settings:{}}}),/Network interrupted/);
  assert.equal(i.timeline.id,'timeline');assert.deepEqual(structuredClone(i.state.edit),previous);assert.equal(f.h.dirty(),true);
});

test('source withdrawal between timeline and media reads requires acknowledgment instead of silent loss',async()=>{
  const i=session();const f=fixture(async()=>({}),async()=>({...i,revision:2,takes:[]}));f.h.set(i);f.h.normalize();f.h.setRenderer({showCard(){}});
  const next={...i.timeline,edit:structuredClone(i.state.edit),selectedTakeIds:['take1'],unavailableSelectedIds:[]};
  await f.h.applyEditableTimeline(next);
  assert.deepEqual(Array.from(i.state.edit.order),[]);assert.deepEqual(Array.from(i.production.unavailableSelectedIds),['take1']);
  await assert.rejects(f.h.runEdit(false),/acknowledge/);
});

test('editable API sends CSRF and preserves structured conflict errors',async()=>{
  const oldFetch=globalThis.fetch,oldLocation=globalThis.location;globalThis.location={href:'https://example.invalid/studio/editor.php'};
  try{
    let captured;globalThis.fetch=async(url,options)=>{captured={url:String(url),options};return{ok:false,status:409,json:async()=>({ok:false,error:{code:'revision_conflict',message:'Reload this cut.'}})};};
    await assert.rejects(editVersionRequest('timeline.save',{id:'x'},{},'csrf-value'),e=>e.status===409&&e.code==='revision_conflict');
    assert.match(captured.url,/edit-api.php\?action=timeline.save/);assert.equal(captured.options.headers['X-CSRF-Token'],'csrf-value');assert.equal(captured.options.cache,'no-store');
  }finally{globalThis.fetch=oldFetch;globalThis.location=oldLocation;}
});

test('cut-name drafts participate in dirty state and are saved before switching versions',async()=>{
  const old={document:globalThis.document,location:globalThis.location,fetch:globalThis.fetch,history:globalThis.history,confirm:globalThis.confirm};
  const nodes=new Map();const node=key=>{if(!nodes.has(key))nodes.set(key,{value:'',innerHTML:'',textContent:'',hidden:false,disabled:false,dataset:{},events:{},addEventListener(k,fn){this.events[k]=fn;},setAttribute(){},removeAttribute(){}});return nodes.get(key);};
  const panel={events:{},innerHTML:'',querySelector:node,querySelectorAll:()=>[...nodes.values()],contains:()=>true,addEventListener(k,fn){this.events[k]=fn;},setAttribute(){},removeAttribute(){}};
  const interview=session(),other={...interview.timeline,id:'second',title:'Second cut',revision:4,latestRevision:4,edit:structuredClone(interview.state.edit),history:[],renders:[]};
  let savedName,applied;
  try{
    globalThis.location={href:'https://example.invalid/editor.php?id=interview&timelineId=timeline'};
    globalThis.document={createElement:()=>panel,querySelector:()=>({before(){}}),querySelectorAll:()=>[]};
    globalThis.history={replaceState(_a,_b,url){globalThis.location.href=String(url);}};globalThis.confirm=()=>true;
    globalThis.fetch=async url=>({ok:true,status:200,json:async()=>({ok:true,data:new URL(url).searchParams.get('action')==='list'?{timelines:[interview.timeline,other]}:other})});
    const controller=await mountEditableVersions({interview,csrf:'csrf',getEdit:()=>interview.state.edit,apply:async t=>{applied=t;interview.timeline=t;},beforeChange:async()=>{savedName=interview.timeline.title;},isBusy:()=>false,notify:()=>{}});
    node('#editable-cut-name').value='Renamed before switching';node('#editable-cut-name').events.input();assert.equal(controller.isDirty(),true);
    node('#editable-cut-list').value='second';node('#editable-cut-list').events.change({target:node('#editable-cut-list')});
    for(let n=0;n<6;n++)await tick();
    assert.equal(savedName,'Renamed before switching');assert.equal(applied.id,'second');assert.equal(controller.isDirty(),false);
    assert.match(globalThis.location.href,/timelineRevision=4/);
  }finally{for(const[key,value]of Object.entries(old))globalThis[key]=value;}
});
