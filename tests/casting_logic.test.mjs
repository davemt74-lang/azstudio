import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {dirname,join} from 'node:path';
const root=join(dirname(fileURLToPath(import.meta.url)),'..');
const read=p=>readFileSync(join(root,p),'utf8');
const cli=expr=>spawnSync('php',['-d','display_errors=1','-r',`require ${JSON.stringify(join(root,'includes/bootstrap.php'))}; ${expr}`],{encoding:'utf8'});
test('Public casting questions and answers support editing, removing, changing type, and stable IDs',()=>{
 const r=cli(`$q=az_casting_default_questions();$validated=az_casting_validate_questions($q);if(count($validated)!==8)exit(10);$q[0]['options']=array_values(array_filter($q[0]['options'],fn($x)=>$x!=='Fashion and swimwear'));$q[0]['options'][]='New campaign style';unset($q[1]);$q=array_values($q);$q[]=['id'=>'new_text_question','title'=>'New question','type'=>'short','help'=>'','required'=>false,'options'=>[]];$new=az_casting_validate_questions($q);if(count($new)!==8||$new[0]['options'][count($new[0]['options'])-1]!=='New campaign style')exit(11);echo 'OK';`);
 assert.equal(r.status,0,r.stderr+' '+r.stdout);assert.equal(r.stdout.trim(),'OK');
});
test('Public casting answer validation rejects forged answers and missing required prompts',()=>{
 const r=cli(`$qs=az_casting_default_questions();$answers=['interests'=>['Fashion and swimwear'],'experience'=>'I regularly create social content','style'=>'Playful and mischievous','availability'=>['Weekends'],'collaboration'=>'Both, depending on the project','first-project'=>'A product shoot and comedy skit'];$ok=az_casting_collect_answers($qs,$answers);if(count($ok)!==8)exit(20);$answers['experience']='A forged answer';try{az_casting_collect_answers($qs,$answers);exit(21);}catch(AzHttpError $e){}unset($answers['experience']);try{az_casting_collect_answers($qs,$answers);exit(22);}catch(AzHttpError $e){}echo 'OK';`);
 assert.equal(r.status,0,r.stderr+' '+r.stdout);assert.equal(r.stdout.trim(),'OK');
});
test('The invitation grants model access only through the approved one-time redemption path',()=>{
 const code=read('includes/casting.php');
 assert.match(code,/az_casting_issue_invitation/);
 assert.match(code,/status\x27\]!==\x27approved\x27/);
 assert.match(code,/bin2hex\(random_bytes\(32\)\)/);
 assert.match(code,/hash\(\x27sha256\x27,\$token\)/);
 assert.match(code,/az_casting_redeem_invitation/);
 assert.match(code,/az_account_password\(\$password\)/);
 assert.match(code,/update casting_leads set status=\x27converted\x27/i);
 assert.doesNotMatch(read('apply.php'),/az_create_member\(|user_roles/);
});
test('The public form and CRM use shared navigation while keeping interview notes private',()=>{
 const apply=read('apply.php'),crm=read('casting.php'),hdr=read('includes/universal-header.php');
 assert.match(apply,/az_render_universal_header\('apply'/);
 assert.match(apply,/az_casting_submit_application/);
 assert.match(apply,/contactConsent/);
 assert.match(crm,/az_require_admin\(\)/);
 assert.match(crm,/az_render_member_layout_open\('casting'/);
 assert.match(crm,/az_casting_update_lead/);
 assert.match(crm,/az_casting_issue_invitation/);
 assert.match(read('includes/member-layout.php'),/Casting CRM/);
 assert.doesNotMatch(hdr,/href="casting.php"/); // logged-in navigation belongs to the sidebar
 assert.match(hdr,/Model casting/);
});
test('The question editor has add/delete question and answer controls with explicit save',()=>{
 const editor=read('assets/casting-builder.js');
 for(const text of ['Delete question','Add answer choice','remove-option','add-option','JSON.stringify(questions)','questions.length<=1','az-add-question'])assert.ok(editor.includes(text),text);
 assert.match(read('casting.php'),/az-question-editor-data/);
});
test('Landing page includes all real member routes, applications and the approved hero',()=>{
 const page=read('index.php');
 for(const path of ['watch.php','appointments.php','classes.php','calendar.php','apply.php','signup.php','hero-angelic-studio.webp'])assert.ok(page.includes(path),path);
 assert.match(page,/model-led lifestyle and entertainment community/i);
 assert.doesNotMatch(page,/\$[0-9]+\/month|Subscribe now.*checkout/);
});
