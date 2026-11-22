import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const read=(name)=>readFileSync(new URL('../'+name,import.meta.url),'utf8');
const api=read('model-videos-api.php'), reviewApi=read('model-video-review-api.php');
const backend=read('includes/model-videos.php'), file=read('model-video-file.php');
const model=read('my-videos.php'), admin=read('user-submissions.php'), adminJs=read('assets/user-submissions.js'), modelJs=read('assets/model-videos.js');
const shared=read('includes/member-layout.php'),header=read('includes/universal-header.php');

test('My Videos is a standalone, model-accessible page outside production assignments',()=>{
  assert.match(model,/az_require_roles\(\['model','admin'\]\)/);
  assert.match(model,/id="mv-create"/);assert.match(model,/id="mv-media"/);assert.match(model,/id="mv-grid"/);
  assert.match(model,/my-media\.php/);assert.match(model,/my-work\.php/);
  assert.doesNotMatch(model,/work-upload-api\.php|work-api\.php/);
  assert.match(model,/id="mv-rights" type="checkbox" required/);
  for(const action of ['create','update','submit','withdraw','delete'])assert.match(modelJs,new RegExp("'"+action+"'"));
});
test('model APIs check owner boundary, consent, CSRF, revision and active model before review',()=>{
  assert.match(api,/az_require_roles\(\['model','admin'\],true\)/);
  assert.match(api,/az_require_csrf\(\)/);assert.match(api,/az_community_lock\(\)/);
  assert.match(backend,/az_personal_media_row\(\$mediaId, \$owner\)/);
  assert.match(backend,/rightsConfirmed/);
  assert.match(backend,/\$account\['status'\]!=='active'/);
  assert.match(backend,/WHERE id=\? AND user_id=\? AND revision=\?/);
  assert.match(backend,/status='pending_review'/);
  assert.match(backend,/status='draft',review_note=''/);
});
test('Admin User Submissions requires admin, supports explicit approve/request changes/reject/unpublish',()=>{
  assert.match(admin,/az_require_admin\(\)/);
  assert.match(reviewApi,/az_require_admin\(true\)/);
  assert.match(reviewApi,/az_require_csrf\(\)/);
  assert.match(backend,/\['approve','request_changes','reject','unpublish'\]/);
  assert.match(backend,/only submitted videos can be reviewed/i);
  assert.match(backend,/review reason for the model/);
  for(const action of ['approve','request_changes','reject','unpublish'])assert.match(adminJs,new RegExp("'"+action+"'"));
  assert.match(adminJs,/Play video to review/);
});
test('public Watch is approval and consent gated and never exposes private source metadata',()=>{
  assert.match(backend,/status='published' AND p\.published_at IS NOT NULL AND p\.rights_confirmed=1 AND u\.status='active'/);
  assert.match(backend,/if \(\$row\['status'\]!=='published'/);
  assert.match(backend,/AZHottie Model/);
  assert.match(backend,/UNION ALL/);
  assert.match(file,/az_model_video_public_row\(\$id\)/);
  assert.match(file,/\$isOwner=\$viewer/);
  assert.doesNotMatch(backend.split('function az_model_video_public_data')[1].split('function az_public_watch_feed')[0],/'mediaId'|'reviewNote'|'ownerId'|'fileName'/);
});
test('schema is versioned and source gallery remains independent and retained',()=>{
  assert.match(backend,/CREATE TABLE IF NOT EXISTS model_videos/);
  assert.match(backend,/UNIQUE \(user_id, media_id\)/);
  assert.match(backend,/FOREIGN KEY \(media_id\) REFERENCES personal_media\(id\) ON DELETE CASCADE/);
  assert.match(backend,/UPDATE studio_schema SET version=26/);
  assert.match(backend,/The underlying original and edited copies in personal_media are preserved/);
  assert.match(read('includes/database.php'),/az_upgrade_model_videos_schema/);
});
test('shared navigation recognizes new routes and provides model/admin access',()=>{
  assert.match(shared,/\['my-videos\.php', 'My Videos'/);
  assert.match(shared,/\['user-submissions\.php', 'User Submissions'/);
  assert.match(header,/my-videos/);assert.match(header,/user-submissions/);
  assert.match(read('dashboard.php'),/User Submissions/);
  assert.match(read('admin-studio.php'),/User Submissions/);
});
test('text containers have explicit light backgrounds and legible ink',()=>{
  const css=read('assets/model-videos.css');
  assert.match(css,/\.mv-header,\.mv-section,\.mv-stats>div,\.mv-info,\.mv-card,\.mv-review-card\{background:#fff!important;color:#302631!important/);
  assert.match(css,/\.mv-dialog\{[^}]*background:#fff!important;color:#302631!important/);
  const live=read('assets/live-studio.css'),dash=read('assets/dashboard.css');
  assert.match(live,/\.azh-admin-submissions\{background:#fff!important;color:#302631!important/);
  assert.match(dash,/\.azh-dash-review\{[^}]*background:#fff!important;color:#302631!important/);
  assert.match(css,/\.mv-cover video\{[^}]*background:#141019/);
  const sharedShell=read('assets/member-layout.css');
  assert.match(sharedShell,/\.azh-member-canvas \.interview-card,/);
  assert.match(sharedShell,/background:#fff!important;color:#2e2630!important/);
  assert.match(sharedShell,/\.question-row-text\{color:#352b35!important/);
});
