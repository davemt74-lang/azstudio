import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const read=p=>readFileSync(new URL('../'+p,import.meta.url),'utf8');
test('publication remains explicitly administrator approved, and schedules are private',()=>{
 const c=read('includes/model-videos.php'),s=read('includes/agent-publishing.php');
 assert.match(c,/in_array\(\$decision,\['approve','schedule'/);
 assert.match(s,/status='scheduled',published_at=NULL/);
 assert.match(s,/status='published',published_at=\?/);
 assert.match(s,/status='scheduled' AND revision=\?/);
 assert.match(s,/az_publishing_video_eligible\(\$row\)/);
 assert.match(s,/az_publishing_cancel_schedule/);
 assert.match(c,/az_publishing_cancel_schedule\(\$row\['id'\]\)/);
});
test('promotions are controlled and public Watch validates consent',()=>{
 const s=read('includes/agent-publishing.php'),w=read('includes/watch-views.php');
 assert.match(s,/state='featured' AND p\.channel='onsite'/);
 assert.match(s,/v\.rights_confirmed=1/);
 assert.match(s,/SELECT 1 FROM user_roles/);
 assert.match(w,/EDITOR'S FEATURED PICK/);
 assert.match(s,/channel'\]===?['"]onsite/);
});
test('publishing analytics uses real aggregated video counters and separates users',()=>{
 const s=read('includes/agent-publishing.php');
 assert.match(s,/published_video_engagement_daily/);
 assert.match(s,/AND p\.user_id=\?/);
 assert.match(s,/\$previous/);
 assert.match(s,/insufficient/);
 assert.doesNotMatch(s,/SELECT.*ip_address/);
});
test('API remains authenticated and CSRF guarded; no external sends',()=>{
 const api=read('publishing-agent-api.php'),cron=read('includes/agent-followthrough-cron.php');
 assert.match(api,/az_require_auth\(true\)/);
 assert.match(api,/az_require_csrf\(\)/);
 assert.match(api,/az_has_role\(\$user,'admin'\)/);
 assert.match(cron,/az_publishing_scan\(\)/);
 assert.doesNotMatch(api,/curl_exec|mail\(/);
});
test('all publishing controls are responsive and disclose manual social sharing',()=>{
 const page=read('publishing.php'),css=read('assets/publishing-agent.css'),js=read('assets/publishing-agent.js');
 assert.match(page,/id="pg-draft"/);assert.match(page,/id="pg-check"/);
 assert.match(page,/No automatic social or email posting/);
 assert.match(css,/@media\(max-width:840px\)/);
 assert.match(js,/confirm\(/);
});
