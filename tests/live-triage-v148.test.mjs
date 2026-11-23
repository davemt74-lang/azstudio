import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const file=(path)=>readFileSync(new URL('../'+path,import.meta.url),'utf8');

test('v1.48 triage derives from the owned acceptance endpoint without new financial actions',()=>{
  const pkg=JSON.parse(file('package.json'));
  assert.equal(pkg.version,'1.48.0');
  assert.ok(file('includes/bootstrap.php').includes("AZ_VERSION = '"+pkg.version+"'"));
  assert.match(file('includes/bootstrap.php'),/live-triage\.php/);
  const backend=file('includes/live-acceptance.php');
  assert.match(backend,/az_live_triage_analyze\(\$roomStatus,\$diag,\$checks\)/);
  assert.match(backend,/az_live_triage_support_summary\(\$roomStatus,\$diag,\$triage,\$completion\)/);
  assert.match(backend,/'diagnostics'=>\$diag/);
  const triage=file('includes/live-triage.php');
  for(const name of ['phone_unapproved','phone_no_offer','director_missing','preview_frozen','preview_missing',
    'holding_slate','sender_stalled','sender_low_fps','fan_frozen','fan_autoplay','fan_telemetry_missing',
    'fan_test_needed','operator_failed_'])assert.ok(triage.includes(name),name);
  for(const bad of ['INSERT INTO','UPDATE az_live','DELETE FROM','az_live_start(','az_live_stop(',
    'az_personal_phone_live_reset(','az_connect_send_transfer(','az_stripe_request(']){
    assert.ok(!triage.includes(bad),'Triage must not mutate broadcaster or financial state: '+bad);
  }
});

test('redacted support export is explicit allowlist and never exposes raw data or approval',()=>{
  const src=file('includes/live-triage.php');
  assert.match(src,/function az_live_triage_support_summary/);
  assert.match(src,/hostedIndependentlyVerified'=>false/);
  assert.match(src,/issueCodes/);
  assert.match(src,/operatorUntested/);
  for(const unsafe of ["'roomId'=>","'roomTitle'=>","'notes'=>","'viewerId'=>","'connection_id'=>",
    "'sdp'=>","'ipAddress'=>","'accessToken'=>"]){
    assert.ok(!src.includes(unsafe),'Unexpected unsafe export field: '+unsafe);
  }
});

test('Acceptance tab follows the newest broadcast instead of polling a stale room',()=>{
  const ui=file('assets/live-acceptance.js');
  assert.match(ui,/const data=await request\('acceptanceStatus'\)/);
  assert.doesNotMatch(ui,/currentRoom\?\{roomId:currentRoom\}/);
  assert.match(ui,/const roomChanged=/);
  assert.match(ui,/if\(rerender\|\|roomChanged\)/);
  assert.match(ui,/renderTriage\(data\)/);
  assert.match(ui,/renderSupport\(data\)/);
});

test('stage links only focus checklist; export copies the redacted object with no HTML rendering',()=>{
  const ui=file('assets/live-acceptance.js');
  assert.match(ui,/triageFindings\.replaceChildren\(\)/);
  assert.match(ui,/target\.scrollIntoView/);
  assert.match(ui,/target\.querySelector\('select'\)\?\.focus/);
  assert.match(ui,/snapshot\.supportSummary/);
  assert.match(ui,/navigator\.clipboard\.writeText\(supportTextarea\.value\)/);
  assert.match(ui,/supportTextarea\.select\(\)/);
  assert.match(ui,/textContent=String/);
  assert.doesNotMatch(ui,/\.innerHTML|\.outerHTML|document\.write/);
  for(const bad of ['sendConnectTransfer','initiateRefund','buyReturnLabel','takeSource(',
    'startBroadcast(','stopBroadcast(','approvePhone(']){
    assert.ok(!ui.includes(bad),'No camera/payment actions in diagnostics: '+bad);
  }
  const html=file('live-director.php');
  assert.match(html,/id="live-triage-findings"/);
  assert.match(html,/id="live-support-summary"/);
  assert.match(html,/id="live-support-copy"/);
  assert.match(html,/live-acceptance\.js\?v=1\.48\.0/);
});
