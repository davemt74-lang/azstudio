import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {dirname,join} from 'node:path';
import {spawnSync} from 'node:child_process';
const base=join(dirname(fileURLToPath(import.meta.url)),'..');
const read=p=>readFileSync(join(base,p),'utf8');
const presets=JSON.parse(read('includes/data/azhottie-questionnaires.json'));
const starter=JSON.parse(read('assets/questions.js').replace(/^\s*export const QUESTIONS =\s*/, '').replace(/;\s*$/, ''));

test('Original 68 questions are available as one editable saved questionnaire',()=>{
 const master=presets.find(p=>p.key==='master');
 assert.equal(master.questions.length,68);
 assert.equal(master.questions.filter(q=>q.type==='choice').length,36);
 assert.equal(master.questions.filter(q=>q.type==='open').length,32);
 assert.equal(master.questions.filter(q=>q.private).length,12);
 assert.deepEqual(master.questions.map(q=>q.text), starter.slice(0,68).map(q=>q.text));
});
test('Six presets cover the complete 80-question bank, including later Hot Seat additions',()=>{
 assert.equal(presets.length,6);
 const unique=new Set(presets.flatMap(p=>p.questions.map(q=>q.id)));
 assert.equal(unique.size,80);
 for(const p of presets) {
  assert.ok(p.name.startsWith('AZHottie'));
  assert.ok(p.questions.length>0 && p.questions.length<=200);
  for(const q of p.questions){
   assert.ok(q.text.length>0);
   assert.ok(['choice','open'].includes(q.type));
   assert.ok(typeof q.private==='boolean');
   if(q.type==='choice')assert.ok(q.choices.length>=2 && q.choices.length<=12);
  }
 }
});
test('Questionnaire seeding is independent of starter JavaScript, persisted per admin, and not overwritten',()=>{
 const s=read('includes/questionnaire-presets.php');
 assert.match(s,/questionnaire_preset_installs/);
 assert.match(s,/INSERT OR IGNORE/);
 assert.match(s,/INSERT IGNORE/);
 assert.match(s,/az_questions\(/);
 assert.doesNotMatch(s,/DELETE FROM questionnaires|UPDATE questionnaires SET/);
 const prod=read('includes/production.php');
 assert.match(prod,/az_seed_editable_questionnaires\(\$userId\)/);
});
test('All page shells call one universal header and load its common stylesheet',()=>{
 const pages=['index.php','phone.php','login.php','signup.php','install.php','director.php','admin.php','includes/app-shell.php','includes/work-shell.php','includes/schedule-shell.php','includes/account-views.php','includes/watch-views.php'];
 for(const name of pages){
  const source=read(name);
  assert.match(source,/az_render_universal_header\(/,name);
  assert.match(source,/assets\/universal-header\.css/,name);
  assert.doesNotMatch(source,/<header class="(?:site-header|landing-header|watch-header|phone-header)/,name);
 }
});
test('Header includes role-sensitive navigation and accessible mobile toggling',()=>{
 const php=read('includes/universal-header.php');
 assert.match(php,/az_has_role\(\$user, 'admin'\)/);
 assert.match(php,/az_has_role\(\$user, 'model'\)/);
 assert.match(php,/az_has_role\(\$user, 'professional'\)/);
 assert.match(php,/aria-expanded="false"/);
 const js=read('assets/universal-header.js');
 assert.match(js,/aria-expanded/);
 assert.match(js,/Escape/);
 const css=read('assets/universal-header.css');
 assert.match(css,/max-width:(1080|1240)px/);
});
test('PHP validates all presets and renders header for public, model and admin contexts',()=>{
 const quoted=JSON.stringify(join(base,'includes/bootstrap.php'));
 const hdr=JSON.stringify(join(base,'includes/universal-header.php'));
 const sidebar=JSON.stringify(join(base,'includes/member-layout.php'));
 const data=JSON.stringify(join(base,'includes/data/azhottie-questionnaires.json'));
 const php=`require ${quoted}; require ${hdr};require ${sidebar}; $_SESSION=[]; foreach(json_decode(file_get_contents(${data}),true) as $p){az_questions($p['questions']);} foreach([['index',null,false],['my-work',['id'=>2,'name'=>'Model','roles'=>['model']],true],['admin',['id'=>3,'name'=>'Admin','roles'=>['admin']],true]] as $a){ob_start();az_render_universal_header(...$a);$h=ob_get_clean();if(strpos($h,'id="azh-global-header"')===false)exit(21); if($a[0]==='my-work'&&strpos($h,'Studio <span')!==false)exit(22); if($a[0]==='admin'){ob_start();az_render_member_layout_open('admin',$a[1]);$nav=ob_get_clean();if(strpos($nav,'Questionnaires')===false || strpos($h,'Questionnaires')!==false)exit(23);}} echo 'OK';`;
 const r=spawnSync('php',['-d','display_errors=1','-r',php],{encoding:'utf8'});
 assert.equal(r.status,0,`PHP failed: ${r.stderr} ${r.stdout}`);
 assert.equal(r.stdout.trim(),'OK');
});
