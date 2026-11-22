import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {dirname,resolve} from 'node:path';
import {transitionProgress,safeOverlayText} from '../assets/live-core.js';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const read=p=>readFileSync(resolve(root,p),'utf8');

test('director does not render site header, admin sidebar, or recent recording panel',()=>{
 const html=read('live-director.php');
 assert.doesNotMatch(html,/az_render_universal_header|az_render_member_layout_open|id="azh-global-header"/);
 assert.doesNotMatch(html,/Recent recordings|live-recording-list|live-record-refresh/);
 assert.match(html,/azh-director-app/);assert.match(html,/azh-director-sources/);assert.match(html,/azh-director-program/);
});
test('every connected phone has a private preview and an explicit Broadcast now action',()=>{
 const ui=read('live-director.php'),js=read('assets/live-director.js');
 for(const id of ['live-sources','live-program-canvas','live-tab-switching','live-panel-recording'])assert.match(ui,new RegExp(id));
 assert.doesNotMatch(ui,/live-preview-video|live-local-cameras|live-scan-cameras|live-phone-devices/);
 assert.match(js,/function createSourceCard\(item\)/);
 assert.match(js,/Broadcast now/);assert.match(js,/takeSource\(item\.id\)/);
 assert.match(js,/previewSelected=id/);assert.match(js,/selected=id/);
});
test('preview never reuses the published canvas and WebRTC broadcasts only programStream',()=>{
 const js=read('assets/live-director.js');
 assert.match(js,/video\.srcObject=stream/);assert.match(js,/stage\.captureStream\(24\)/);
 assert.match(js,/peer\.addTrack\(track,programStream\)/);
 assert.doesNotMatch(js,/peer\.addTrack\(track,item\.stream\)/);
});
test('transition helper clamps out-of-range time and supports zero duration',()=>{
 assert.equal(transitionProgress(100,100),0);assert.equal(transitionProgress(100,400),.5);
 assert.equal(transitionProgress(100,700),1);assert.equal(transitionProgress(100,1900),1);
 assert.equal(transitionProgress(100,80),0);assert.equal(transitionProgress(100,110,0),1);
});
test('overlay text trims controls and bounds length without HTML insertion',()=>{
 assert.equal(safeOverlayText('  Hi\n there \t!  '),'Hi there !');
 assert.equal(safeOverlayText('x'.repeat(100)), 'x'.repeat(90));
 assert.equal(safeOverlayText('<script>alert(1)</script>'),'<script>alert(1)</script>');
 const js=read('assets/live-director.js');assert.match(js,/ctx\.fillText\(overlay\.text/);
});
test('transitions and overlays are drawn inside the captured program canvas',()=>{
 const ui=read('live-director.php'),js=read('assets/live-director.js');
 for(const id of ['live-transition','live-brand-overlay','live-camera-overlay','live-custom-overlay','live-overlay-text','live-overlay-position'])assert.match(ui,new RegExp(id));
 assert.match(js,/transitionMode==='dissolve'/);assert.match(js,/transitionMode==='fade'/);
 assert.match(js,/ctx\.drawImage\(oldFrame/);assert.match(js,/ctx\.fillText\(overlay\.text/);
 assert.ok(js.indexOf("if(transitionStart)")<js.indexOf("if(room&&switchDeadline"));
});
test('director styles stay viewport-contained, with internally scrollable columns',()=>{
 const css=read('assets/live-studio.css');
 assert.match(css,/html:has\(body\.azh-director-body\) \{height:100%;overflow:hidden\}/);
 assert.match(css,/\.azh-director-app\{height:100dvh;max-height:100dvh/);
 assert.match(css,/\.azh-director-app \.azh-director-workspace \{overflow:hidden/);
 assert.match(css,/\.azh-director-app \.azh-director-sources,.azh-director-app \.azh-director-program/);
});
test('public shows page is accessible without a member login and only lists approved public shows',()=>{
 const page=read('live-shows.php'),api=read('live-public-api.php'),sql=read('includes/live.php');
 assert.match(page,/public-shows-grid/);assert.doesNotMatch(page,/az_require_auth\(/);
 assert.match(api,/az_live_open_recordings\(/);assert.doesNotMatch(api,/az_require_auth\(/);
 assert.match(sql,/function az_live_open_recordings\(/);
 assert.match(sql,/r\.published_at IS NOT NULL AND r\.publication_audience='public'/);
 assert.match(sql,/u\.status='active'/);
});
test('public media is served only when explicitly public; member-only and drafts still require auth',()=>{
 const media=read('live-recording-media.php'),sql=read('includes/live.php');
 assert.match(media,/az_live_public_media_row\(\$id\)/);
 assert.match(media,/if \(!\$row\) \{/);assert.match(media,/az_require_auth\(true\)/);
 assert.match(sql,/function az_live_public_media_row\(/);
 assert.match(sql,/r\.id=\? AND r\.published_at IS NOT NULL AND r\.publication_audience='public'/);
 assert.match(sql,/u\.status='active'/);assert.match(media,/HTTP_RANGE/);
});
test('schema migration keeps existing recordings members-only by default',()=>{
 const sql=read('includes/live.php'),boot=read('includes/database.php');
 assert.match(sql,/function az_upgrade_live_public_schema/);
 assert.match(sql,/publication_audience VARCHAR\(16\) NOT NULL DEFAULT 'members'/);
 assert.match(sql,/UPDATE studio_schema SET version=25/);
 assert.equal((boot.match(/az_upgrade_live_public_schema/g)||[]).length,4);
});
test('only administrator can change public audience, with revision and missing media checks',()=>{
 const api=read('live-api.php'),sql=read('includes/live.php');
 assert.match(api,/setAudience.*az_has_role\(\$actor,'admin'\)/);
 assert.match(sql,/function az_live_recording_set_audience/);
 assert.match(sql,/az_live_recording_check_revision\(\$body,\$row\)/);
 assert.match(sql,/if \(\$audience==='public' && !is_file\(az_media_path\(\$row\)\)\)/);
});
test('admin has explicit audience controls; no public switch from an unpublished draft',()=>{
 const js=read('assets/live-productions.js'),php=read('includes/live.php');
 assert.match(js,/Publish to members/);assert.match(js,/Make public/);assert.match(js,/setAudience/);
 assert.match(php,/Publish to members before selecting a public audience/);
 assert.match(read('live-productions.php'),/Open public shows/);
});
test('live director no longer renders recording gallery; admin page and public gallery are separate',()=>{
 assert.doesNotMatch(read('live-director.php'),/azh-director-saved|Recent recordings/);
 assert.doesNotMatch(read('live-director.php'),/href="live-productions\.php"/);
 assert.match(read('admin-studio.php'),/live-productions\.php/);
 assert.match(read('live-productions.php'),/live-library-grid/);
 assert.match(read('live-shows.php'),/public-shows-grid/);
});

test('new cameras do not silently replace the public program; a lost camera returns to holding slate',()=>{
 const source=read('assets/live-director.js');
 assert.doesNotMatch(source,/if\(!selected\)selected=id/);
 assert.match(source,/Newly connected camera to fans without an explicit Broadcast now action|Never expose a newly/);
 assert.match(source,/if\(!mediaProgramActive&&activeLayout\.type==='single'&&selected===id\)\{selected=null;activeLayout=normalizeLayout\('single',\[''\]\)/);
});
