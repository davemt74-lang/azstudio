import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {
  validViewerSessionId,lateViewerJoinNeedsLeave,recordingStartBlockReason,mixerReadiness
} from '../assets/live-production-guards.js';
const read=(p)=>readFileSync(new URL('../'+p,import.meta.url),'utf8');

test('late fan Join replies only release their own well-formed viewer ID',()=>{
 const old={viewerId:'a'.repeat(32)},newer={viewerId:'b'.repeat(32)};
 assert.equal(validViewerSessionId(old.viewerId),true);
 assert.equal(lateViewerJoinNeedsLeave(old,11,12),true);
 assert.equal(lateViewerJoinNeedsLeave(newer,12,12),false);
 assert.equal(lateViewerJoinNeedsLeave({viewerId:'bad-id'},11,12),false);
 assert.equal(lateViewerJoinNeedsLeave(null,11,12),false);
 assert.equal(validViewerSessionId('A'.repeat(32)),false);
});
test('recording safety refuses overlap while initial setup, stopping, upload, or unsaved backup exists',()=>{
 const fresh={onAir:true,recorderState:'inactive'};
 assert.equal(recordingStartBlockReason(fresh),null);
 for(const state of [
   {starting:true},{finalizing:true},{saving:true},{backupPending:true},
   {recorderState:'recording'},{recorderState:'paused'}
 ]){
   assert.ok(recordingStartBlockReason({...fresh,...state}),
     'Concurrent recording should be blocked: '+JSON.stringify(state));
 }
 assert.match(recordingStartBlockReason({...fresh,backupPending:true}),/Save or download the backup/);
 assert.match(recordingStartBlockReason({...fresh,finalizing:true}),/finalizing/);
 assert.match(recordingStartBlockReason({...fresh,onAir:false}),/Start a live broadcast/);
});
test('mixer readiness labels only local capability and never certifies a remote fan',()=>{
 const ready={programTrack:true,contextState:'running',hostMic:true};
 assert.equal(mixerReadiness(ready).state,'ready');
 assert.match(mixerReadiness(ready).message,/does not prove a remote fan/);
 assert.equal(mixerReadiness({...ready,hostMic:false,cameraMic:false}).state,'attention');
 assert.match(mixerReadiness({...ready,contextState:'suspended'}).message,/Resume mixer/);
 assert.equal(mixerReadiness({...ready,contextState:'interrupted'}).state,'attention');
 assert.equal(mixerReadiness({programTrack:false}).state,'unknown');
});

test('fan viewer allows cancel during slow Join and independently cleans late viewer leases',()=>{
 const src=read('assets/live-viewer.js');
 assert.match(src,/lateViewerJoinNeedsLeave\(joined,version,epoch\)/);
 assert.match(src,/request\('leave',\{viewerId:joined\.viewerId\}\)/);
 assert.match(src,/if\(epoch===version\)\{connecting=false;\$\('fan-live-join'\)\.disabled=false;\}/);
 assert.match(src,/\$\('fan-live-join'\)\.hidden=true;\$\('fan-live-leave'\)\.hidden=false;/);
 assert.match(src,/recovery\.leave\(\);clearPlayer\(true\)/);
 assert.doesNotMatch(src,/takeSource\(|startRecord\(|liveRequest\('stop'/);
});
test('Director finalizes and verifies original recording before permitting another',()=>{
 const src=read('assets/live-director.js');
 assert.match(src,/const reason=recordingStartBlockReason/);
 assert.match(src,/backupPending:Boolean\(recordedFile\)/);
 assert.match(src,/recordingStarting=true;updateRecordControls\(\)/);
 assert.match(src,/recordingFinalizing=true;updateRecordControls\(\)/);
 assert.match(src,/if\(!invalidRecording\)await saveRecording\(file,title,startedRoom\)/);
 assert.match(src,/const startedRecorder=recorder,startedRoom=recordRoom/);
 assert.match(src,/if\(recorder\?\.state==='recording'\)finishRecording\(\)/);
 assert.match(src,/Discard local copy/);
 assert.match(src,/window\.confirm\('Discard this unsaved local recording/);
 assert.match(src,/saved file verification|Saved file verification failed/i);
 assert.match(src,/beforeunload/);
});
test('Director recovery requires an explicit Take to restore a reconnected camera',()=>{
 const src=read('assets/live-director.js');
 assert.match(src,/interruptedSourceId=item\.id/);
 assert.match(src,/interruptedSourceId=id;selected=null/);
 assert.match(src,/interruptedSourceId===id\?'Restore to Program'/);
 assert.match(src,/function takeSource\(id\)/);
 assert.match(src,/cameraFaultActive=false;scheduleSwitch\(\);routeViewerVideo\(\)/);
 const page=read('live-director.php');
 assert.match(page,/id="live-audio-health"/);
 assert.match(page,/id="live-audio-resume"/);
});
test('mixer resumes only via explicit click and never claims remote audio proven',()=>{
 const director=read('assets/live-director.js');
 assert.match(director,/function reportMixerReadiness\(\)/);
 assert.match(director,/live-audio-resume'\)\?\.addEventListener\('click',async/);
 assert.match(director,/await audioCtx\.resume\(\)/);
 assert.match(director,/audioReadinessTimer=setInterval\(reportMixerReadiness,2500\)/);
 assert.match(director,/clearInterval\(audioReadinessTimer\)/);
 assert.ok(read('assets/live-studio.css').includes('.azh-program-audio-status'));
 assert.ok(read('live.php').includes('live-viewer.js?v='+JSON.parse(read('package.json')).version));
 assert.ok(read('live-director.php').includes('live-director.js?v='+JSON.parse(read('package.json')).version));
});
