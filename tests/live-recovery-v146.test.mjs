import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {FanRecoveryBudget,PhoneRepairBudget,fanRecoveryReason} from '../assets/live-recovery.js';
const load=name=>readFileSync(new URL('../'+name,import.meta.url),'utf8');

test('fan retry budget bounds failed peers, resets only after prolonged healthy playback or a fresh room',()=>{
  let now=1000;
  const budget=new FanRecoveryBudget({now:()=>now});
  const roomA='a'.repeat(32),roomB='b'.repeat(32);
  budget.enterRoom(roomA);
  assert.deepEqual([budget.next()?.delayMs,budget.next()?.delayMs,budget.next()?.delayMs], [1200,3000,7000]);
  assert.equal(budget.next(),null,'No infinite reconnect loops');
  budget.observeHealthy(true);now+=44000;budget.observeHealthy(true);
  assert.equal(budget.next(),null,'Brief recovery must not reset retry budget');
  budget.observeHealthy(true);now+=45000;budget.observeHealthy(true);
  assert.equal(budget.next().attempt,1,'Sustained moving playback resets budget');
  budget.leave();assert.equal(budget.next(),null,'Explicit Leave disables automatic reconnect');
  budget.manualJoin();assert.equal(budget.next().attempt,1,'Manual Join re-enables with reset');
  budget.enterRoom(roomB);assert.equal(budget.next().attempt,1,'A new broadcast resets budget');
  now+=121000;assert.equal(budget.next().attempt,1,'Rolling window allows bounded later retries');
});

test('fan recovery requires confirmed transport or decoding failure and skips background and blocked autoplay',()=>{
  assert.equal(fanRecoveryReason({connection:'connected',hasVideo:true,canMeasure:true,frameAgeMs:8500}),null);
  assert.equal(fanRecoveryReason({connection:'connected',hasVideo:true,canMeasure:true,frameAgeMs:12500}),'frozen_video');
  assert.equal(fanRecoveryReason({connection:'connected',hasVideo:true,canMeasure:false,frameAgeMs:20000}),null,
    'Do not falsely declare a frozen stream without decoded-frame observation');
  assert.equal(fanRecoveryReason({connection:'connecting',hasVideo:false,elapsedMs:30001}),'no_video');
  assert.equal(fanRecoveryReason({connection:'disconnected',disconnectedMs:6001}),'peer_disconnected');
  assert.equal(fanRecoveryReason({connection:'failed'}),'peer_failed');
  assert.equal(fanRecoveryReason({connection:'closed',paused:true}),'peer_failed');
  assert.equal(fanRecoveryReason({connection:'connected',hasVideo:true,canMeasure:true,frameAgeMs:25000,paused:true}),null);
  assert.equal(fanRecoveryReason({connection:'connected',hasVideo:true,canMeasure:true,frameAgeMs:25000,autoplayBlocked:true}),null);
  assert.equal(fanRecoveryReason({visible:false,connection:'failed'}),null,'Background tab should not churn reconnects');
});

test('phone frozen-frame repair is per-phone, cooldown bounded and separately reset by the operator',()=>{
  let now=0;const budget=new PhoneRepairBudget({now:()=>now});
  assert.deepEqual(budget.next('phone-a'),{attempt:1,maxAttempts:2});
  assert.equal(budget.next('phone-a').attempt,2);
  assert.equal(budget.next('phone-a'),null);
  assert.equal(budget.next('phone-b').attempt,1,'Phone A failure must not block other phones');
  budget.healthy('phone-a',true);now+=40000;budget.healthy('phone-a',true);
  assert.equal(budget.next('phone-a'),null,'A brief good frame must not rearm failed feed');
  budget.healthy('phone-a',true);now+=46000;budget.healthy('phone-a',true);
  assert.equal(budget.next('phone-a').attempt,1,'Sustained healthy frames permit new bounded retries');
  assert.equal(budget.next('phone-b',{manual:true}).attempt,1,'Operator explicitly resets only selected feed');
  budget.clear('phone-a');assert.equal(budget.next('phone-a').attempt,1);
});

test('fan player reconnects only a viewer peer; never switches Program or restarts a recording',()=>{
  const viewer=load('assets/live-viewer.js');const live=load('live.php');
  assert.match(viewer,/scheduleRecovery\('peer_failed'\)/);
  assert.match(viewer,/scheduleRecovery\('frozen_video'\)/);
  assert.match(viewer,/scheduleRecovery\('signaling_interrupted'\)/);
  assert.match(viewer,/recovery\.leave\(\);clearPlayer\(true\)/);
  assert.match(viewer,/clearPlayer\(true\);/);
  assert.match(live,/fan-live-recovery/);
  assert.ok(live.includes('live-viewer.js?v='+JSON.parse(load('package.json')).version));
  assert.doesNotMatch(viewer,/az_commerce_order_record|purchase|refund|startRecord|takeSource/);
});

test('Director restarts only stale approved phone relays; retains explicit Take and recording safeguards',()=>{
  const director=load('assets/live-director.js');const page=load('live-director.php');
  assert.match(director,/item\.health\?\.update\(\)==='stalled'/);
  assert.match(director,/phones\.remove\(phoneId,true\)/);
  assert.match(director,/phoneRequest\('resetFeed',\{id:phoneId,nonce:oldNonce\}\)/);
  assert.match(director,/phoneRepair\.next\(phoneId,\{manual\}\)/);
  assert.match(director,/document\.hidden&&!manual/);
  assert.match(director,/Reconnect feed/);
  assert.match(director,/let invalidRecording=file\.size<1024\|\|recorderFailed/);
  assert.match(director,/recordingAutoSaveAllowed\(\{bytes:file\.size,recorderError:recorderFailed,localPlaybackVerified\}\)/);
  assert.match(director,/if\(!invalidRecording\)await saveRecording\(file,title,startedRoom\)/);
  assert.ok(page.includes('live-director.js?v='+JSON.parse(load('package.json')).version));
  assert.doesNotMatch(director,/az_admin_action_change|approveCasting|sendConnectTransfer/);
});

test('phone signaling repair is authenticated, exact-nonce scoped, and cannot revoke approval',()=>{
  const api=load('personal-phone-api.php'),backend=load('includes/personal-phone.php');
  assert.match(api,/'resetFeed'/);
  assert.match(api,/az_require_csrf\(\)/);
  assert.match(api,/az_personal_phone_live_reset\(\$owner/);
  assert.match(backend,/DELETE FROM personal_phone_live WHERE device_id=\? AND nonce=\?/);
  assert.match(backend,/owner_id=\?/);
  assert.match(backend,/status='approved'/);
  assert.match(backend,/approvalUnchanged/);
  assert.doesNotMatch(backend,/function az_personal_phone_live_reset[\s\S]{0,1000}(?:UPDATE personal_phone_devices|DELETE FROM personal_phone_devices)/);
  const phone=load('assets/personal-camera.js');
  assert.match(phone,/signal\('poll',null,\{nonce:current\}\)/);
  assert.match(phone,/catch\(e\)\{retryLive\(e\);\}/);
});

test('visibility-safe fan controls always honor hidden after session cleanup',()=>{
  const css=load('assets/live-studio.css');
  assert.match(css,/#azh-fan-live \[hidden\]\{display:none !important\}/);
});
