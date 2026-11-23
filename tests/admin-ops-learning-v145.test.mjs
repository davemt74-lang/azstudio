import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const read=(path)=>readFileSync(new URL('../'+path,import.meta.url),'utf8');

test('Operations learning is versioned, wired to existing dashboard and role-gated API',()=>{
  const pkg=JSON.parse(read('package.json'));
  assert.match(pkg.version,/^1\.\d+\.\d+$/);
  assert.ok(read('includes/bootstrap.php').includes("AZ_VERSION = '"+pkg.version+"'"));
  assert.match(read('includes/bootstrap.php'),/admin-ops-learning\.php/);
  const api=read('agent-workspace-api.php');
  assert.match(api,/if\(\$method==='GET'&&\$action==='operationsLearning'\)/);
  assert.match(api,/az_admin_action_assert\(\$user\)/);
  assert.match(api,/in_array\(\$days,\['7','30','90'\],true\)/);
  assert.match(api,/session_write_close\(\)/);
  assert.match(api,/az_ops_learning_report\(\$user,\(int\)\$days\)/);
  const page=read('admin-studio.php');
  assert.match(page,/id="azd-ops-learning"/);
  assert.match(page,/data-ops-results/);
  assert.match(page,/data-ops-period/);
  assert.match(page,/admin-ops-learning\.js\?v=1\.45\.0/);
  assert.match(page,/admin-ops-learning\.css\?v=1\.45\.0/);
});

test('Operations learning only reads immutable existing ledger tables',()=>{
  const php=read('includes/admin-ops-learning.php');
  assert.match(php,/FROM admin_action_tasks/);
  assert.match(php,/FROM admin_action_history/);
  assert.match(php,/COUNT\(DISTINCT task_id\)/);
  assert.match(php,/az_admin_action_assert\(\$user\)/);
  assert.match(php,/WHERE created_at>=\? AND created_at<\?/);
  assert.match(php,/az_ops_learning_median/);
  assert.match(php,/az_ops_learning_family/);
  for(const mutation of ['INSERT INTO','UPDATE admin_action','DELETE FROM','az_admin_action_change(',
      'az_admin_action_scan(','az_connect_send_transfer(','az_easypost_request(',
      'az_stripe_refund','az_casting_advance(']){
    assert.ok(!php.includes(mutation),'Unexpected write/action: '+mutation);
  }
});

test('Brain and Agent Chat share a bounded admin-only learning summary',()=>{
  const workspace=read('includes/agent-workspace.php');
  const chat=read('includes/agent-chat.php');
  const panel=read('assets/agent-workspace.js');
  assert.match(workspace,/az_ops_learning_chat_snapshot\(\$user\)/);
  assert.match(workspace,/'operationsLearning'=>\$operationsLearning/);
  assert.match(workspace,/operations_learning/);
  assert.match(panel,/brain\.operationsLearning/);
  assert.match(panel,/Operations Learning/);
  assert.match(chat,/'operationsLearning'=>az_has_role\(\$member,'admin'\)/);
  assert.match(chat,/deterministic history analysis/);
});

test('Dashboard fetches read-only data, inserts dynamic text safely, with no financial controls',()=>{
  const ui=read('assets/admin-ops-learning.js');
  assert.match(ui,/fetch\(url,\{method:'GET'/);
  assert.match(ui,/textContent=String/);
  assert.match(ui,/validDays=new Set\(\['7','30','90'\]\)/);
  assert.match(ui,/safeHref/);
  assert.doesNotMatch(ui,/innerHTML|outerHTML|document\.write/);
  for(const action of ['buyReturnLabel','initiateRefund','sendConnectTransfer','approveApplication',
                      'publishVideo','actionChange','actionTrack','actionSync']){
    assert.ok(!ui.includes(action),'Reporting UI must not mutate '+action);
  }
});
