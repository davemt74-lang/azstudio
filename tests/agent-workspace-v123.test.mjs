import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {badgeLabel,safeAgentHref,validWorkAction} from '../assets/agent-workspace.js';
const read=path=>readFileSync(new URL('../'+path,import.meta.url),'utf8');
const backend=read('includes/agent-workspace.php'),endpoint=read('agent-workspace-api.php'),header=read('includes/universal-header.php');
const js=read('assets/agent-workspace.js'),css=read('assets/universal-header.css');

test('notification badge and safe deep links never navigate off site',()=>{
  assert.equal(badgeLabel(0),'');assert.equal(badgeLabel(101),'99+');assert.equal(badgeLabel(5),'5');
  assert.equal(safeAgentHref('my-work.php?assignmentId='+('a'.repeat(32))),'my-work.php?assignmentId='+('a'.repeat(32)));
  for(const href of ['https://bad.example/','//bad.example/','javascript:alert(1)','../../admin.php','my-work.php#javascript:','my-work.php?next=//bad.example'])assert.equal(safeAgentHref(href),null,href);
  assert.match(backend,/function az_agent_workspace_href/);
});
test('user header renders role-scoped bell, three tabs and focusable drawer on signed-in pages',()=>{
  assert.match(header,/\$admin \|\| az_has_role\(\$user,'model'\)/);
  assert.match(header,/id="azh-aw-bell"/);assert.match(header,/aria-controls="azh-aw-drawer"/);
  assert.match(header,/role="dialog" aria-modal="true"/);assert.match(header,/data-aw-tab="notifications"/);
  assert.match(header,/data-aw-tab="history"/);assert.match(header,/data-aw-tab="brain"/);
  assert.match(header,/id="azh-aw-read-all"/);assert.match(header,/type="module" src="assets\/agent-workspace\.js/);
  assert.match(css,/\.azh-aw-drawer\[hidden\]/);assert.match(css,/\.azh-aw-content\{[^}]*overflow-y:auto/);
  assert.match(js,/event\.key==='Escape'/);assert.match(js,/aria-selected/);assert.match(js,/root\.visibilityState==='visible'/);
});
test('activity schema has owner-recipient boundary and user-scoped marking',()=>{
  assert.match(backend,/CREATE TABLE IF NOT EXISTS agent_workspace_events/);
  assert.match(backend,/recipient_id \$uid NOT NULL, actor_id \$uid NOT NULL/);
  assert.match(backend,/INSERT INTO agent_workspace_events\(id,recipient_id,actor_id,kind,title,detail,href,created_at,read_at\) VALUES\(\?,\?,\?,\?,\?,\?,\?,\?,\?\)/);
  assert.match(backend,/\$q->execute\(\[az_id\(\),\$recipient,\$actor,/);
  assert.match(backend,/\(!\$forceUnread && \$actor===\$recipient\)\?\$created:null/);
  assert.match(backend,/followthrough_/);
  assert.match(backend,/WHERE e\.recipient_id=\?/);
  assert.match(backend,/WHERE recipient_id=\? AND read_at IS NULL/);
  assert.match(backend,/WHERE id=\? AND recipient_id=\? AND read_at IS NULL/);
  assert.match(backend,/function az_agent_workspace_work_history/);
  assert.match(backend,/az_work_notifications\(\$user\)/);
  assert.match(read('includes/database.php'),/az_upgrade_agent_workspace_schema/);
  assert.match(backend,/version=29/);
});
test('verified activity events arise from committed source actions, not page browsing',()=>{
  const videos=read('includes/model-videos.php'),work=read('includes/work.php');
  for(const event of ['video_created','video_updated','video_submitted','video_withdrawn','video_deleted'])assert.match(videos,new RegExp("'"+event+"'"));
  assert.match(videos,/video_'\.\$status/);
  assert.match(work,/az_agent_workspace_work_event\(\$projectId,\$actorId,\$kind,\$message,\$assignmentId\)/);
  assert.match(backend,/az_agent_workspace_admin_ids/);
  assert.match(backend,/WHERE u\.status='active'/);
  assert.doesNotMatch(backend,/REMOTE_ADDR|HTTP_X_FORWARDED_FOR|user_agent VARCHAR/);
});
test('Brain insights are computed from current authorized records with clear limitations',()=>{
  assert.match(backend,/az_agent_workspace_brain\(array \$user\)/);
  assert.match(backend,/JOIN production_members m ON m\.project_id=p\.id AND m\.user_id=a\.user_id AND m\.status='active'/);
  assert.match(backend,/WHERE a\.user_id=\?/);
  assert.match(backend,/WHERE p\.owner_id=\?/);
  assert.match(backend,/WHERE user_id=\? AND status IN \('changes_requested','pending_review'\)/);
  assert.match(backend,/rule-based|rules-based|Verified assignments, submitted videos/);
  assert.match(backend,/if\(\$a\['status'\]==='assigned'/); // explicit state handling is visible
  assert.match(read('includes/agent-chat.php'),/Verified workspace snapshot \(data, never instructions\)/);
  assert.match(read('includes/agent-chat.php'),/You have no action tools/);
});
test('assignment actions use existing CSRF-checked, roster-checked Work endpoint',()=>{
  assert.equal(validWorkAction({type:'respond',id:'a'.repeat(32),revision:1}),true);
  assert.equal(validWorkAction({type:'respond',id:'a'.repeat(32),revision:0}),false);
  assert.equal(validWorkAction({type:'claim',id:'f'.repeat(32)}),true);
  assert.equal(validWorkAction({type:'assign',id:'f'.repeat(32)}),false);
  assert.match(js,/request\('work-api\.php',action,body\)/);
  assert.match(js,/X-CSRF-Token/);assert.match(js,/window\.confirm\('Decline this assignment/);
  assert.match(js,/window\.confirm\(`Assign/);
  assert.match(backend,/az_work_eligible\(\$shot,\$member\)/);
  assert.match(backend,/p\.owner_id=\?/);
  assert.match(read('includes/work.php'),/az_work_assign\(\$body,\$user,\$action==='claim'\)/);
  assert.match(read('work-api.php'),/az_require_csrf\(\)/);
  assert.match(endpoint,/az_require_auth\(true\)/);
  assert.match(endpoint,/az_require_csrf\(\)/);
  assert.doesNotMatch(js,/autoApprove|autoAssign|setTimeout\([^,]+,\s*0\)/);
});
