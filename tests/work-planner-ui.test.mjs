import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';
import {webcrypto} from 'node:crypto';

const source=(await readFile(new URL('../assets/work-planner.js',import.meta.url),'utf8')).replace(/^export /gm,'');
const tick=()=>new Promise(resolve=>setImmediate(resolve));
function deferred(){let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return {promise,resolve,reject};}
function storage(){const values=new Map();return{getItem:key=>values.get(key)||null,setItem:(key,value)=>values.set(key,String(value)),removeItem:key=>values.delete(key),values};}
const settings={enabled:true,configured:true,hasKey:false,model:'sample-model',dailyLimit:20,revision:1};
const proposal={id:'proposal-one',projectId:'project-one',state:'ready',summary:'Sample proposal',shots:[{title:'Opening shot',brief:'A natural introduction.',orientation:'portrait',targetDurationSeconds:20,takesRequested:2}]};
function setup(api,saved=storage(),userId=1){const nodes=new Map(),handlers=new Map();const node=name=>{if(!nodes.has(name))nodes.set(name,{textContent:'',innerHTML:'',hidden:false,disabled:false,value:'',checked:false,setAttribute(){}});return nodes.get(name);};const generateForm={elements:{shotCount:{value:'2',disabled:false},direction:{value:'Sample direction',disabled:false}},matches:selector=>selector==='[data-generate-form]'};const applyForm={matches:selector=>selector==='[data-apply-form]'};const shotControls=Object.fromEntries(Object.entries(proposal.shots[0]).map(([name,value])=>[name,{name,value:String(value),disabled:false}]));const shot={querySelector:selector=>shotControls[selector.match(/name=(.+)\]/)[1]]};const controls=[...Object.values(generateForm.elements),...Object.values(shotControls),node('[data-generate]'),node('refresh-button')];const container={hidden:true,innerHTML:'',shots:[shot],setAttribute(){},querySelector(selector){if(selector==='[data-generate-form]')return generateForm;return node(selector);},querySelectorAll(selector){return selector==='[data-proposed-shot]'?this.shots:controls;},addEventListener(type,callback){handlers.set(type,callback);}};const errors=[];const context={console,URL,location:{href:'https://studio.example.test/productions.php'},sessionStorage:saved,AbortController,crypto:webcrypto,confirm:()=>true};vm.runInNewContext(source+'\nglobalThis.mount=mountWorkPlanner;',context);const mounted=context.mount(container,{project:{id:'project-one',title:'A production',brief:'An actual saved brief',status:'active',canManage:true},csrf:'sample-csrf',user:{id:userId},api,onError:error=>errors.push(error.message)});return {mounted,container,node,nodes,controls,generateForm,applyForm,shotControls,handlers,errors,storage:saved,submit:form=>handlers.get('submit')({target:form,preventDefault(){}})};}

test('initial settings load and pending generation lock inputs as well as buttons',async()=>{const initial=deferred(),generation=deferred();const ui=setup(action=>action==='settings'?initial.promise:action==='latest'?Promise.resolve(null):generation.promise);assert.equal(ui.mounted.isBusy(),true);assert.ok(ui.controls.every(control=>control.disabled));initial.resolve(settings);await tick();assert.equal(ui.mounted.isBusy(),false);assert.equal(ui.generateForm.elements.shotCount.disabled,false);const pending=ui.submit(ui.generateForm);assert.equal(ui.mounted.isBusy(),true);assert.ok(ui.controls.every(control=>control.disabled));generation.resolve(proposal);await pending;assert.equal(ui.mounted.isBusy(),false);assert.equal(ui.generateForm.elements.shotCount.disabled,false);});
test('a lost generation receipt reuses its request token after reopening the view',async()=>{const saved=storage(),tokens=[];let attempts=0;const api=async(action,payload)=>{if(action==='settings')return settings;if(action==='latest')return null;if(action==='generate'){tokens.push(payload.requestToken);if(++attempts===1)throw new Error('Sample connection stopped');return proposal;}throw new Error('Unexpected action');};const first=setup(api,saved);await tick();await first.submit(first.generateForm);assert.equal(first.errors.length,1);first.mounted.destroy();const second=setup(api,saved);await tick();await second.submit(second.generateForm);assert.equal(tokens.length,2);assert.ok(tokens[0]);assert.equal(tokens[0],tokens[1]);assert.match(second.node('[data-proposal]').innerHTML,/Opening shot/);});
test('editable proposal text survives view destruction and is scoped to the current admin',async()=>{const saved=storage(),api=async action=>action==='settings'?settings:proposal;const first=setup(api,saved,7);await tick();first.shotControls.title.value='Keep this carefully edited opening';first.handlers.get('input')({target:{closest:()=>first.applyForm}});assert.equal(first.mounted.isDirty(),true);first.mounted.destroy();const second=setup(api,saved,7);await tick();assert.match(second.node('[data-proposal]').innerHTML,/Keep this carefully edited opening/);assert.equal(second.mounted.isDirty(),true);const other=setup(api,saved,8);await tick();assert.doesNotMatch(other.node('[data-proposal]').innerHTML,/Keep this carefully edited opening/);assert.equal(other.mounted.isDirty(),false);});
test('provider controls live in Admin and a refresh picks up a changed provider',async()=>{
  let active={...settings,provider:'openai'};
  const ui=setup(async action=>action==='settings'?active:null);
  await tick();
  assert.match(ui.container.innerHTML,/href="admin.php"/);
  assert.doesNotMatch(ui.container.innerHTML,/name="apiKey"|data-settings-form/);
  assert.match(ui.node('[data-planner-status]').textContent,/OpenAI/);
  active={...settings,provider:'anthropic',model:'sample-claude'};
  await ui.handlers.get('click')({target:{closest:selector=>selector==='[data-refresh]'?{}:null}});
  assert.match(ui.node('[data-planner-status]').textContent,/Claude/);
  assert.match(ui.node('[data-enable-help]').textContent,/sample-claude/);
});
test('a changed-request conflict releases the old token for the next generation intent',async()=>{const tokens=[];let count=0;const ui=setup(async(action,payload)=>{if(action==='settings')return settings;if(action==='latest')return null;if(action==='generate'){tokens.push(payload.requestToken);if(++count===1){const error=new Error('The prior token belongs to different direction.');error.code='request_conflict';throw error;}return proposal;}});await tick();await ui.submit(ui.generateForm);ui.generateForm.elements.direction.value='Revised direction';await ui.submit(ui.generateForm);assert.equal(tokens.length,2);assert.notEqual(tokens[0],tokens[1]);});

test('failed settings load keeps generation disabled until settings recover',async()=>{
  let recovered=false,generateCalls=0;
  const ui=setup(async action=>{if(action==='settings'){if(!recovered)throw new Error('Settings unavailable');return settings;}if(action==='generate'){generateCalls++;return proposal;}return null;});
  await tick();
  assert.equal(ui.node('[data-generate]').disabled,true);
  await ui.submit(ui.generateForm);
  assert.equal(generateCalls,0);
  recovered=true;
  await ui.handlers.get('click')({target:{closest:selector=>selector==='[data-refresh]'?{}:null}});
  assert.equal(ui.node('[data-generate]').disabled,false);
  await ui.submit(ui.generateForm);
  assert.equal(generateCalls,1);
});
