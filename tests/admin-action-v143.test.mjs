import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const read=(path)=>readFileSync(new URL('../'+path,import.meta.url),'utf8');

test('Action Center routes retain administrator-only role gate and CSRF',()=>{
  const api=read('agent-workspace-api.php');
  assert.match(api,/\$method==='GET'&&\$action==='actionCenter'/);
  assert.match(api,/if\(!az_has_role\(\$user,'admin'\)\)/);
  assert.match(api,/az_require_csrf\(\);/);
  for(const action of ['actionTrack','actionChange','actionSync'])assert.ok(api.includes("'"+action+"'"));
});
test('Management recommendations are server-owned and Source IDs never come from an arbitrary client title',()=>{
  const a=read('includes/admin-actions.php');
  const intel=read('includes/admin-intelligence.php');
  assert.match(a,/az_admin_intelligence_briefing\(\$user,null,\$db,\$now\)/);
  assert.match(a,/recommendation_changed/);
  assert.match(intel,/'sourceKind'=>'queue'/);
  assert.match(intel,/'sourceKind'=>\$item\['kind'\]/);
  assert.match(a,/revision=revision\+1/);
  assert.match(a,/UNIQUE\(source_kind,source_id\)/);
  assert.match(a,/UNIQUE\(task_id,recipient_id,reminder_key\)/);
});
test('Task UI never makes financial or casting/moderation execution calls',()=>{
  const ui=read('assets/admin-action-center.js');
  assert.match(ui,/data-actions-list/);
  assert.match(ui,/actionChange/);
  assert.match(ui,/actionSync/);
  assert.match(ui,/X-CSRF-Token/);
  for(const op of ['initiateRefund','sendConnectTransfer','buyReturnLabel','castingAdvance',
    'reviewPromotion','publishVideo','approveApplication']){
    assert.ok(!ui.includes(op),'No financial, publishing or casting mutations in Action Center: '+op);
  }
});
test('Version, migration, dashboard, Brain and cron point to same Action Center',()=>{
  assert.match(read('includes/bootstrap.php'),/AZ_VERSION = '1\.43\.0'/);
  assert.match(read('includes/database.php'),/az_upgrade_admin_action_schema/);
  assert.match(read('includes/agent-followthrough-cron.php'),/az_admin_action_scan/);
  assert.match(read('includes/agent-workspace.php'),/'actionCenter'=>\$actionCenter/);
  assert.match(read('includes/agent-chat.php'),/'actionCenter'\]=az_admin_action_chat_summary/);
  assert.match(read('admin-studio.php'),/id="azd-action-center"/);
});
