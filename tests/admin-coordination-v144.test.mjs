import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const read=(s)=>readFileSync(new URL('../'+s,import.meta.url),'utf8');

test('Coordination uses verified Work and Calendar source tables only',()=>{
 const src=read('includes/admin-coordination.php');
 for(const name of ['shot_assignments','production_shots','production_projects','schedule_events','schedule_appointments','admin_action_tasks','admin_action_history'])assert.match(src,new RegExp(name));
 assert.match(src,/az_coordination_tables_ready/);
 assert.match(src,/az_admin_action_assert\(\$user\)/);
 assert.match(src,/az_coordination_source_active/);
 for(const forbidden of ['az_work_assign','az_sched_save','az_production_plan_save','az_stripe','buyReturnLabel','sendConnectTransfer'])assert.ok(!src.includes(forbidden));
});
test('Production source is revalidated and tracked in existing Action Center',()=>{
 const actions=read('includes/admin-actions.php');
 assert.match(actions,/'queue','production'/); // source becomes a recognized Action Center kind
 assert.match(actions,/az_coordination_candidates\(\$db,\$now,100\)/);
 assert.match(actions,/az_coordination_source_active\(\$db,\$task/);
 assert.match(actions,/az_admin_action_scan/);
 assert.match(actions,/az_coordination_candidates\(\$db,\$now,80\)/);
 assert.match(actions,/az_admin_action_create\(\$db,\$candidate,\$actor,\$now,'automatic'\)/);
});
test('Management briefing is staff-only and uses existing Agent Brain and Chat',()=>{
 const workspace=read('includes/agent-workspace.php');
 const chat=read('includes/agent-chat.php');
 const page=read('admin-studio.php');
 assert.match(workspace,/'coordination'=>\$coordination/);
 assert.match(workspace,/az_coordination_briefing\(\$user\)/);
 assert.match(chat,/'coordination'\]=az_coordination_chat_snapshot/);
 assert.match(page,/id="azd-coordination"/);
 assert.match(page,/data-kind="production"/);
 assert.match(page,/az_h\(\$case\['detail'\]\)/);
 assert.match(page,/az_h\(\$staff\['name'\]\)/);
 assert.match(page,/assets\/admin-command\.css\?v=1\.44\.0/);
});
test('No schema change and no new external money movement',()=>{
 const version=JSON.parse(read('package.json')).version;
 assert.match(version,/^1\.\d+\.\d+$/);
 assert.ok(read('includes/bootstrap.php').includes("AZ_VERSION = '"+version+"'"));
 const tracker=read('assets/admin-action-center.js');
 assert.match(tracker,/request\('actionTrack'/);
 for(const bad of ['initiateRefund','buyReturnLabel','sendConnectTransfer','reassignShot','changeBooking']){
   assert.ok(!tracker.includes(bad),'No execution function in shared Action Center: '+bad);
 }
});
