import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const load=(path)=>readFileSync(new URL('../'+path,import.meta.url),'utf8');

test('v1.47 release and migration use the existing Live Director infrastructure',()=>{
  const version=JSON.parse(load('package.json')).version;
  assert.match(version,/^1\.\d+\.\d+$/);
  assert.ok(load('includes/bootstrap.php').includes("AZ_VERSION = '"+version+"'"));
  assert.match(load('includes/database.php'),/az_upgrade_live_acceptance_schema/);
  assert.match(load('includes/bootstrap.php'),/live-acceptance\.php/);
  const php=load('includes/live-acceptance.php');
  assert.match(php,/az_schema_version\(\$db\)>=47/);
  assert.match(php,/UPDATE studio_schema SET version=47/);
  assert.match(php,/az_live_acceptance_checks/);
  assert.match(php,/az_live_acceptance_events/);
});

test('only active administrator and owned broadcast can access private acceptance or save attestations',()=>{
  const php=load('includes/live-acceptance.php');
  assert.match(php,/az_has_role\(\$actor,'admin'\)/);
  assert.match(php,/status.*active/);
  assert.match(php,/FROM az_live_rooms WHERE id=\? AND owner_id=\?/);
  assert.match(php,/SELECT \* FROM az_live_rooms WHERE owner_id=\?/);
  assert.match(php,/revision=revision\+1/);
  assert.match(php,/revision_conflict/);
  assert.match(php,/test_window_expired/);
  assert.match(php,/observation_required/);
  assert.match(php,/hostedIndependentlyVerified'=>false/);
  assert.match(php,/az_live_agent_fresh_rows\(\$db,\$roomId,\$now\)/);
});

test('API uses existing authentication and CSRF, and exposes no mutable media actions',()=>{
  const api=load('live-api.php');
  assert.match(api,/az_require_auth\(true\)/);
  assert.match(api,/if \(\$method==='POST'\)az_require_csrf\(\)/);
  assert.match(api,/if \(\$action==='acceptanceStatus'\)/);
  assert.match(api,/if \(\$action==='acceptanceMark'\)/);
  assert.match(api,/az_live_acceptance_status\(\$actor/);
  assert.match(api,/az_live_acceptance_mark\(\$actor/);
  const backend=load('includes/live-acceptance.php');
  for(const prohibited of [
    'az_live_stop(', 'az_live_start(', 'az_live_host_answer(', 'az_personal_phone_live_reset(',
    'az_live_recording_publish(', 'az_live_recording_set_audience(', 'az_connect_send_transfer(',
    'UPDATE personal_phone_devices','UPDATE az_live_recordings','DELETE FROM az_live_viewers'
  ])assert.ok(!backend.includes(prohibited),'Diagnostic path must not modify live/media/financial state: '+prohibited);
});

test('Director Acceptance tab appears only for admins, and reuses existing tab switching',()=>{
  const page=load('live-director.php');
  const director=load('assets/live-director.js');
  assert.match(page,/id="live-tab-acceptance"/);
  assert.match(page,/id="live-panel-acceptance"/);
  assert.match(page,/id="azh-live-acceptance"/);
  assert.match(page,/if\(az_has_role\(\$user,'admin'\)\): \?>/);
  assert.ok(page.includes('live-acceptance.js?v='+JSON.parse(load('package.json')).version));
  assert.match(director,/document\.querySelectorAll\('\[data-live-tab\]'\)/);
  assert.match(director,/panel\.hidden=!active/);
});

test('Operator UI renders external observations as text and never auto-submits outcomes',()=>{
  const frontend=load('assets/live-acceptance.js');
  assert.match(frontend,/textContent=String/);
  assert.match(frontend,/window\.confirm\(/);
  assert.match(frontend,/X-CSRF-Token/);
  assert.match(frontend,/action==='acceptanceMark'|request\('acceptanceMark'/);
  assert.match(frontend,/request\('acceptanceStatus'/);
  assert.match(frontend,/hostedIndependentlyVerified|independent/);
  assert.doesNotMatch(frontend,/innerHTML|outerHTML|document\.write/);
  for(const prohibited of [
    'startRecord(', 'stopRecord(', 'startBroadcast(', 'switchProgram(', 'approvePhone(',
    'approveApplication(', 'sendConnectTransfer(', 'buyReturnLabel(', 'initiateRefund('
  ]) assert.ok(!frontend.includes(prohibited),'Acceptance UI must never control Program, approval, or financial actions');
});
