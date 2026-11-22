import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {dirname,resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const read=path=>readFileSync(resolve(root,path),'utf8');
const page=read('dashboard.php'),module=read('includes/member-dashboard.php'),eng=read('includes/member-engagement.php');

test('engagement stats represent actual tracked plays without claiming unique viewers',()=>{
  for(const expected of ['Engagement trends','Video play sessions','Player completions','Minutes watched','Profile visits','Video starts','az_member_engagement_summary'])
    assert.ok((page+module).includes(expected),expected);
  assert.match(page,/not unique viewers/);
  assert.match(page,/Only currently published videos credited to you/);
  assert.match(page,/chartPeak/);
  assert.doesNotMatch(page,/\+([0-9]+)%\s*engagement|fake|random\(/i);
});
test('playback ingestion requires publication, session CSRF, and excludes self/admin',()=>{
  const endpoint=read('watch-engagement.php');
  assert.match(endpoint,/POST required/);
  assert.match(endpoint,/az_require_csrf\(\)/);
  assert.match(endpoint,/az_member_watch_event\(/);
  assert.match(eng,/az_model_video_public_row\(\$id\)/);
  assert.match(eng,/az_public_video_row\(\$id\)/);
  assert.match(eng,/az_has_role\(\$viewer,'admin'\)/);
  assert.match(eng,/\$viewer\['id'\] === \$source\['owner_id'\]/);
  assert.match(eng,/startedAt/);
  assert.match(eng,/>= 1800/);
  assert.match(eng,/watched_seconds/);
  assert.doesNotMatch(eng,/REMOTE_ADDR|HTTP_X_FORWARDED_FOR|HTTP_COOKIE|user_agent VARCHAR|visitor_id/);
});
test('public watch playback events are wired, but never block video playback',()=>{
  const view=read('includes/watch-views.php');
  const js=read('assets/watch-engagement.js');
  assert.match(view,/data-engagement-kind/);
  assert.match(view,/watch-engagement\.js\?v=1\.22\.0/);
  for(const event of ['playing','timeupdate','pause','ended','pagehide']) assert.match(js,new RegExp(event));
  assert.match(js,/\.catch\(\(\)=>\{\}\)/);
  assert.match(js,/credentials:'same-origin'/);
  assert.match(js,/X-CSRF-Token/);
});
test('dashboard deadlines use direct assignment links and exclude submitted work',()=>{
  for(const text of ['Assignment deadlines','due in the next 7 days','Overdue','my-work.php?assignmentId=','az_dash_deadline_list'])
    assert.ok((page+module).includes(text),text);
  assert.match(module,/if\(!in_array\(\$assignment\['status'\],\['accepted','assigned','changes_requested'\]/);
  assert.match(module,/if\(\$state==='submitted'\) continue/);
  assert.match(module,/owner\.status='active'/);
});
test('action feed is responsive, scoped to requests and linked to review pages',()=>{
  for(const text of ['Action required','Changes requested','Due soon','Admin review','my-videos.php?videoId=','user-submissions.php'])
    assert.ok((page+module).includes(text),text);
  assert.match(module,/WHERE user_id=\? AND status='changes_requested'/);
  assert.match(module,/\$isAdmin\?az_dash_count/);
  assert.match(page,/az_h\(\$action\['title'\]\)/);
  assert.match(page,/az_h\(\$action\['href'\]\)/);
  assert.match(read('assets/model-videos.js'),/new URLSearchParams\(location.search\)\.get\('videoId'\)/);
});
test('layout is light, mobile, and single-document-scroll-friendly',()=>{
  const css=read('assets/dashboard.css');
  for(const needle of ['.azh-dash-focus-grid','.azh-engagement-summary','.azh-engagement-chart','.azh-dash-deadlines','.azh-attention-row','.azh-deadline-row'])assert.ok(css.includes(needle));
  assert.match(css,/@media\(max-width:600px\)/);
  assert.doesNotMatch(css,/\.azh-dash-focus-grid[^}]*overflow\s*:\s*(scroll|auto)/);
  assert.match(page,/dashboard\.css\?v=1\.22\.0/);
});
