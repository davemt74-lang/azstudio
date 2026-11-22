import test from 'node:test';import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';import {fileURLToPath} from 'node:url';import {dirname,resolve} from 'node:path';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const read=f=>readFileSync(resolve(root,f),'utf8');
test('live director is full viewport and does not load member sidebar or bread crumbs',()=>{
 const page=read('live-director.php'),css=read('assets/live-studio.css');
 assert.doesNotMatch(page,/az_render_member_layout_open|az_render_member_layout_close|azh-canvas-topline/);
 assert.match(page,/azh-director-workspace/);assert.ok(page.indexOf('class="azh-director-sources"')<page.indexOf('class="azh-director-program"'));
 assert.match(css,/body\.azh-director-body[^\n]*height:100dvh/);assert.match(css,/\.azh-director-app \.azh-director-sources \{[^}]*overflow:auto/);
 assert.match(css,/\.azh-director-app \.azh-director-program \{[^}]*overflow:auto/);
});
test('fan page is video-first and recorded live videos follow the live player',()=>{
 const page=read('live.php');
 assert.doesNotMatch(page,/Watch Live|<h1>Watch/);assert.match(page,/id="fan-live-video"/);
 assert.ok(page.indexOf('id="fan-live-video"')<page.indexOf('id="fan-archive-grid"'));
 assert.match(page,/fan-replay-video/);
});
test('archive endpoint only selects explicitly published rows and an active owner',()=>{
 const php=read('includes/live.php');
 assert.match(php,/WHERE r\.published_at IS NOT NULL/);assert.match(php,/u\.status='active'/);
 assert.match(php,/az_live_recording_row\(string \$id,int \$actorId,bool \$admin=false\)/);
 assert.match(php,/empty\(\$row\['published_at'\]\)/);
});
test('live recording media uses account authorization and range parsing',()=>{
 const page=read('live-recording-media.php');
 assert.match(page,/az_require_auth\(true\)/);assert.match(page,/az_live_recording_row/);
 assert.match(page,/HTTP_RANGE/);assert.match(page,/Accept-Ranges: bytes/);assert.match(page,/az_media_path/);
});
test('only admins can publish but editors can edit their own saved production',()=>{
 const api=read('live-api.php');assert.match(api,/az_live_require_editor\(true\)/);
 assert.match(api,/publishRecording.*az_has_role\(\$actor,'admin'\)/);
 assert.match(api,/updateRecording.*az_live_recording_update/);
 assert.match(read('live-productions.php'),/data-can-publish/);
});
test('cover upload is verified, revision checked and not stored in public assets',()=>{
 const php=read('includes/live.php');
 assert.match(php,/az_live_recording_cover_upload/);assert.match(php,/az_uploaded_file\('cover'\)/);
 assert.match(php,/getimagesize/);assert.match(php,/move_uploaded_file/);assert.match(php,/az_media_path/);
 assert.match(php,/revision=revision\+1/);
});
test('admin studio offers a saved live productions destination',()=>{
 assert.match(read('admin-studio.php'),/live-productions.php/);
 assert.match(read('includes/member-layout.php'),/Saved live productions/);
 assert.match(read('assets/live-productions.js'),/uploadCover/);
});
test('fan archive has no unpublished source ID or direct personal-media access',()=>{
 const js=read('assets/live-viewer.js');
 assert.match(js,/action','archive'/);assert.match(js,/videoUrl/);
 assert.doesNotMatch(js,/personal-media-file\.php/);
});
test('original program recording stays private until explicitly published',()=>{
 const php=read('includes/live.php');
 assert.match(php,/INSERT INTO az_live_recordings\(id,room_id,owner_id,media_id,recorded_at,title,description,updated_at,revision\)/);
 assert.match(php,/published_at.*NULL/);assert.match(php,/az_live_recording_publish/);
});
