import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {dirname,join} from 'node:path';
import {normalizeTrim,personalMediaMime,uploadIdentity,originalSelectionDuration,formatClock} from '../assets/personal-media-core.js';
const root=join(dirname(fileURLToPath(import.meta.url)),'..');
const read=name=>readFileSync(join(root,name),'utf8');
const php=code=>spawnSync('php',['-d','display_errors=1','-r',`require ${JSON.stringify(join(root,'includes/bootstrap.php'))}; ${code}`],{encoding:'utf8'});

test('Trim points are precise, non-destructive, and reject invalid intervals',()=>{
 assert.deepEqual(normalizeTrim(1.1,2.2,5),{durationMs:5000,trimStartMs:1100,trimEndMs:2200});
 assert.deepEqual(normalizeTrim(0,0,5),{durationMs:5000,trimStartMs:0,trimEndMs:0});
 for(const [start,end,duration] of [[2,1,5],[5,5,5],[-1,2,5],[0,4.9,3],[0.2,0.21,4],[0,1,0],[NaN,1,2]]) {
   assert.throws(()=>normalizeTrim(start,end,duration));
 }
 assert.equal(originalSelectionDuration(10000,1000,2500),1.5);
 assert.equal(formatClock(90),'01:30');
});
test('Video upload classification accepts real video formats including codec parameters, and denies unrelated formats',()=>{
 for(const mime of ['video/webm','video/mp4;codecs=avc1.42E01E,mp4a.40.2','video/quicktime','video/ogg','','application/octet-stream'])assert.equal(personalMediaMime(mime),true,mime);
 for(const mime of ['image/svg+xml','text/html','audio/mpeg','application/x-php'])assert.equal(personalMediaMime(mime),false,mime);
 assert.equal(uploadIdentity('abcdef0123456789abcdef0123456789',500,'take.mp4'),true);
 assert.equal(uploadIdentity('short',500,'take.mp4'),false);
 assert.equal(uploadIdentity('abcdef0123456789abcdef0123456789',0,'take.mp4'),false);
});
test('Header has exactly one signed-in navigation toggle, before the brand, with links in the sidebar',()=>{
 const header=read('includes/universal-header.php'),side=read('includes/member-layout.php');
 const start=header.indexOf('<header id="azh-global-header"');
 const memberButton=header.indexOf('id="azh-member-menu-button"',start);
 const brand=header.indexOf('azh-global-header__brand',memberButton);
 assert.ok(memberButton>start && memberButton<brand);
 assert.match(header,/if \(\$signedIn\)/);
 assert.match(header,/aria-controls="azh-side-navigation"/);
 assert.match(header,/if \(\$signedIn && !\$hasWorkspace\)/);
 assert.match(side,/My media gallery/);
 assert.match(side,/href="watch\.php"/);
 assert.match(side,/azh-side-signout/);
 assert.doesNotMatch(side,/azh-side-toggle/);
 const js=read('assets/member-layout.js');
 assert.match(js,/azh-member-menu-button/);assert.match(js,/azh-floating-sidebar/);
 assert.match(js,/Escape/);assert.match(js,/aria-expanded/);
});
test('Authenticated header has only logo and account dropdown, not duplicate top navigation',()=>{
 const s=php(`require ${JSON.stringify(join(root,'includes/universal-header.php'))};$_SESSION=[]; $u=['id'=>7,'name'=>'Model Test','roles'=>['model']];foreach(['dashboard','index','watch','apply'] as $page){ob_start();az_render_universal_header($page,$u);$html=ob_get_clean();$head=substr($html,0,strpos($html,'</header>'));if(str_contains($head,'azh-global-header__nav')||!str_contains($head,'id="azh-account-menu"')||!str_contains($head,'id="azh-member-menu-button"'))exit(31);if(!str_contains($head,'Account settings')||!str_contains($head,'My profile'))exit(32);}ob_start();az_render_universal_header('index',null);$public=ob_get_clean();if(!str_contains($public,'azh-global-navigation'))exit(33);echo 'OK';`);
 assert.equal(s.status,0,s.stderr+' '+s.stdout);assert.equal(s.stdout.trim(),'OK');
});
test('Personal gallery is private to its owner and does not grant models the admin interview editor',()=>{
 const backend=read('includes/personal-media.php');
 assert.match(backend,/user_id=\?/);assert.match(backend,/az_personal_media_role/);
 assert.match(backend,/az_detect_media_type/);assert.match(backend,/flock\(\$file,LOCK_EX\)/);
 assert.match(backend,/last_chunk_sha256/);assert.match(backend,/az_personal_media_quota/);
 assert.match(read('personal-media-api.php'),/az_require_csrf\(\)/);
 assert.match(read('personal-media-file.php'),/az_personal_media_row\(\$id,\(int\)\$user\['id'\]\)/);
 assert.match(read('editor.php'),/az_redirect\('my-media.php'\)/);
 assert.match(read('includes/app-shell.php'),/az_require_admin/);
 const roles=php(`az_personal_media_role(['roles'=>['model']]);az_personal_media_role(['roles'=>['professional']]);az_personal_media_role(['roles'=>['admin']]);try{az_personal_media_role(['roles'=>['fan']]);exit(42);}catch(AzHttpError $e){if($e->status!==403)exit(43);}echo 'OK';`);
 assert.equal(roles.status,0,roles.stderr+' '+roles.stdout);assert.equal(roles.stdout.trim(),'OK');
});
test('Private media schema creates both personal gallery and chunk-upload tables during fresh install and upgrade',()=>{
 const media=read('includes/personal-media.php');
 const db=read('includes/database.php');
 assert.match(media,/CREATE TABLE IF NOT EXISTS personal_media \(/);
 assert.match(media,/CREATE TABLE IF NOT EXISTS personal_media_uploads \(/);
 assert.match(media,/version = 18/);
 assert.match(media,/UNIQUE\(user_id, client_token\)/);
 assert.match(media,/FOREIGN KEY \(user_id\) REFERENCES users/);
 assert.equal((db.match(/az_upgrade_personal_media_schema/g)||[]).length,4);
 assert.match(read('storage/.htaccess'),/Require all denied/);
});
test('Model editor uploads in resumable chunks and uses existing compositor to save a trimmed copy',()=>{
 const js=read('assets/personal-media.js');
 for(const phrase of ['beginUpload','uploadChunk','finishUpload','retry','Export &amp; save','renderer.export','saveUpload(file','Upload','status','personal-media-file.php']){
   assert.ok(js.includes(phrase)||read('my-media.php').includes(phrase)||read('includes/personal-media.php').includes(phrase),phrase);
 }
 assert.match(read('my-media.php'),/personal-media\.js/);
 assert.match(read('my-media.php'),/az_require_roles\(\['model','professional','admin'\]\)/);
});
