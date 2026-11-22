import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

// Exercise the actual page controller's save queue and editor inclusion rules.
// Network, media devices, and DOM nodes are small fixtures; no browser is started.
const original = fs.readFileSync(new URL('../assets/app.js', import.meta.url), 'utf8');
const controller = original.slice(0, original.lastIndexOf('\nmain().catch(')).replace(/^import .*;\n/gm, '');
function fixture(api = async () => ({}), page = 'interview') {
  const nodes = new Map();
  const node = id => {
    if (!nodes.has(id)) nodes.set(id, { id, value: '', textContent: '', innerHTML: '', hidden: false, disabled: false,
      classList: {remove(){},add(){},toggle(){}}, pause(){}, removeAttribute(){}, setAttribute(){}, scrollIntoView(){}, addEventListener(){},querySelector(){return null;},append(){},prepend(){},replaceChildren(){} });
    return nodes.get(id);
  };
  const q = {id:'q1',number:1,category:'Interview',text:'Introduce the show.',options:[],kind:'open',private:false};
  const context = vm.createContext({
    console, structuredClone, Blob, URL, DOMException, Map, Set,
    crypto:{randomUUID:()=> 'test-draft'}, setTimeout:()=>1, clearTimeout(){},
    document:{body:{dataset:{page}},getElementById:node,querySelectorAll:()=>[],querySelector:()=>null,createElement:tag=>node(`created-${tag}`),addEventListener(){}},
    window:{dispatchEvent(){},addEventListener(){}}, api, QUESTIONS:[q],
    pendingPut:async()=>{},pendingDelete:async()=>{},pendingList:async()=>[],
    buildTimeline:()=>({totalDuration:2}), getVideoMetadata:async()=>({duration:2}), uploadVideo:async()=>({id:'uploaded-take',questionId:'q1',questionText:q.text,durationMs:2000,answerStartMs:0,createdAt:'2026-01-01',isPrivate:false,reviewStatus:'unreviewed',reviewNotes:[],mediaUrl:'media.php?id=uploaded-take'})
  });
  vm.runInContext(controller + '\nglobalThis.hooks={set(value){interview=value;chosen=QUESTIONS[0];},get(){return interview;},normalize,segments,saveInterview,importFile,mutateTakeReview,syncEditorSettings,saveFinishedVideo,runEdit,renderEditor,setRenderer(value){renderer=value;},setExport(value){latestExport=value;drafts.set(value.id,value);},getExport(){return latestExport;},isDirty(){return dirty;},isBusy(){return busy;}};', context);
  return {context,nodes,node,h:context.hooks,q};
}
function session() {
  return {id:'session',title:'Saved interview',participantName:'Alex',modelId:null,revision:1,notes:'',state:{responses:{},customQuestions:[],edit:{order:[],segments:{},settings:{}}},takes:[]};
}
function take(id, reviewStatus='unreviewed', isPrivate=false) {
  return {id,questionId:'q1',questionText:'Introduce the show.',durationMs:2000,answerStartMs:0,createdAt:'2026-01-01',reviewStatus,isPrivate,reviewNotes:[],mediaUrl:`media.php?id=${id}`};
}
const tick=()=>new Promise(resolve=>setImmediate(resolve));

test('a saved included=true cannot put a Private only take back into an edit',()=>{
  const f=fixture(), value=session();value.takes=[take('private','private'),take('public')];
  value.state.edit={order:['private','public'],segments:{private:{included:true,trimStart:0,trimEnd:2},public:{included:true,trimStart:0,trimEnd:2}},settings:{}};
  f.h.set(value);assert.equal(f.h.normalize(),true);
  assert.equal(f.h.get().state.edit.segments.private.included,false);
  assert.equal(f.h.segments().length,1);assert.equal(f.h.segments()[0].id,'public');
  value.state.edit.segments.private.included=true;
  assert.equal(f.h.segments().length,1,'Selection also checks current review labels, independent of saved edit state');
  const fresh=session();fresh.takes=[take('casting','unreviewed',true)];f.h.set(fresh);f.h.normalize();
  assert.equal(f.h.get().state.edit.segments.casting.included,false,'Private casting footage remains excluded by default');
});

test('queued interview saves use each acknowledged revision and reject stale-tab overwrites',async()=>{
  const requests=[];
  const f=fixture((action,payload)=>new Promise((resolve,reject)=>requests.push({action,payload,resolve,reject})));
  f.h.set(session());f.h.normalize();
  f.h.get().notes='First change';const first=f.h.saveInterview();await tick();
  f.h.get().notes='Second change';const second=f.h.saveInterview();await tick();
  assert.equal(requests.length,1);assert.equal(requests[0].payload.revision,1);
  requests[0].resolve({revision:2,updatedAt:'first'});await first;await tick();
  assert.equal(requests.length,2);assert.equal(requests[1].payload.revision,2);
  assert.equal(requests[1].payload.notes,'Second change');
  requests[1].resolve({revision:3,updatedAt:'second'});await second;
  assert.equal(f.h.get().revision,3);
  const conflict=f.h.saveInterview();await tick();
  const error=new Error('This interview changed in another tab.');error.status=409;
  requests[2].reject(error);await assert.rejects(conflict,/changed in another tab/);
  assert.equal(f.h.isDirty(),true);assert.equal(f.h.get().revision,3);
});

test('imported footage clears busy state and restores per-question record buttons',async()=>{
  const f=fixture(async(action,payload)=>({revision:(payload.revision||1)+1,updatedAt:'saved'}));
  f.h.set(session());f.h.normalize();
  const file=new Blob(['video'],{type:'video/webm'});file.name='imported.webm';
  await f.h.importFile(file,f.q);
  assert.equal(f.h.get().takes.length,1);assert.equal(f.h.isBusy(),false);
  assert.match(f.node('question-list').innerHTML,/data-record-question="q1"/);
  assert.doesNotMatch(f.node('question-list').innerHTML,/disabled/);
});

test('take review mutations are serialized so older responses cannot undo a new private label',async()=>{
  const requests=[];const f=fixture((action,payload)=>new Promise(resolve=>requests.push({action,payload,resolve})));
  const value=session();value.takes=[take('t1')];f.h.set(value);f.h.normalize();
  const favorite=f.h.mutateTakeReview('updateTakeReview',{takeId:'t1',isFavorite:true});
  const privacy=f.h.mutateTakeReview('updateTakeReview',{takeId:'t1',reviewStatus:'private'},true);
  await tick();assert.equal(requests.length,1);
  requests[0].resolve({...take('t1'),isFavorite:true});await favorite;await tick();
  assert.equal(requests.length,2);
  requests[1].resolve({...take('t1','private'),isFavorite:true});await privacy;
  assert.equal(f.h.get().takes[0].reviewStatus,'private');assert.equal(f.h.get().takes[0].isFavorite,true);
  assert.equal(f.h.segments().length,0);
});


test('visible numeric settings sync without a blur and incomplete input cannot replace a valid duration',()=>{
  const f=fixture(async()=>({}),'editor');f.h.set(session());f.h.normalize();
  for(const [id,value] of Object.entries({'card-duration':'1','fade-duration':'0.4','aspect-ratio':'16:9','export-resolution':'720','video-fit':'contain'}))f.node(id).value=value;
  assert.equal(f.h.syncEditorSettings(true),true);
  assert.equal(f.h.get().state.edit.settings.cardDuration,1);
  assert.equal(f.h.get().state.edit.settings.fadeDuration,0.4);
  f.node('card-duration').value='';
  assert.equal(f.h.syncEditorSettings(),false);
  assert.equal(f.h.get().state.edit.settings.cardDuration,1);
  assert.throws(()=>f.h.syncEditorSettings(true),/between 0.5 and 15/);
});

test('a source withdrawn just before Preview or Export leaves a usable acknowledgment state',async()=>{
  for(const exporting of [false,true]) {
    let mediaRuns=0;
    const value=session();value.production={id:'project',title:'Product edit',kind:'production',acceptedCount:1,unavailableSelectedIds:[]};value.takes=[take('approved','shortlisted')];
    const f=fixture(async(action,payload)=>{
      if(action==='updateInterview'){assert.equal(Object.hasOwn(payload,'modelId'),false);return {revision:2,updatedAt:'saved'};}
      if(action==='interview')return {...value,revision:2,takes:[],production:{...value.production,acceptedCount:0,unavailableSelectedIds:['approved']}};
      throw new Error(`Unexpected action ${action}`);
    },'editor');
    f.context.console={...console,error(){}};f.h.set(value);f.h.normalize();
    f.h.setRenderer({showCard(){},play(){mediaRuns++;},export(){mediaRuns++;}});
    for(const [id,text] of Object.entries({'card-duration':'2','fade-duration':'0.4','aspect-ratio':'16:9','export-resolution':'720','video-fit':'contain','card-mode':'none'}))f.node(id).value=text;
    await assert.doesNotReject(f.h.runEdit(exporting));
    assert.equal(mediaRuns,0);assert.equal(f.h.get().takes.length,0);
    assert.deepEqual(Array.from(f.h.get().state.edit.order),[]);
    assert.deepEqual(Array.from(f.h.get().production.unavailableSelectedIds),['approved']);
    assert.match(f.node('export-status').textContent,/no longer approved/);
    assert.equal(f.node('preview-edit').disabled,true);
    assert.doesNotThrow(()=>f.h.renderEditor());
  }
});

test('saving a finished movie locks immediately while browser recovery storage is pending',async()=>{
  const f=fixture();f.h.set(session());f.h.normalize();
  const draft={id:'finished-draft',purpose:'finished',interviewId:'session',blob:new Blob(['movie'],{type:'video/webm'}),title:'Rendered movie',description:'',status:'draft',fileName:'finished.webm'};
  f.h.setExport(draft);f.node('finished-title').value='Rendered movie';f.node('finished-description').value='';f.node('finished-status').value='draft';
  let releaseRecovery,uploads=0;
  f.context.pendingPut=()=>new Promise(resolve=>{releaseRecovery=resolve;});
  f.context.uploadVideo=async()=>{uploads++;return {video:{id:'saved-movie',title:'Rendered movie',description:'',status:'draft',version:1}};};
  const first=f.h.saveFinishedVideo({preventDefault(){}});
  assert.equal(f.h.isBusy(),true);assert.equal(f.node('save-finished-video').disabled,true);
  assert.match(f.node('finished-save-status').textContent,/Preparing/);
  await assert.rejects(f.h.saveFinishedVideo({preventDefault(){}}),/Finish the current recording or render first/);
  assert.equal(uploads,0);
  f.context.pendingPut=async()=>{};releaseRecovery();await first;
  assert.equal(uploads,1);assert.equal(f.h.getExport().completed,true);assert.equal(f.h.isBusy(),false);
});
