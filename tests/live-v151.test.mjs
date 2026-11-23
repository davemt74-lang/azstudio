import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {resetViewerForRoomChange,deferForegroundPhoneRepair,recordingAutoSaveAllowed,videoProbeReady} from '../assets/live-v151-guards.js';
import {verifyRecordedVideo} from '../assets/live-record-verify.js';
const read=path=>readFileSync(new URL('../'+path,import.meta.url),'utf8');

test('in-flight fan Join is invalidated when current room goes offline or changes',()=>{
 assert.equal(resetViewerForRoomChange({roomChanged:true,connecting:true}),true);
 assert.equal(resetViewerForRoomChange({roomChanged:true,hasPeer:true}),true);
 assert.equal(resetViewerForRoomChange({roomChanged:true,hasViewer:true}),true);
 assert.equal(resetViewerForRoomChange({roomChanged:true,recovering:true}),true);
 assert.equal(resetViewerForRoomChange({roomChanged:false,connecting:true}),false);
 assert.equal(resetViewerForRoomChange({roomChanged:true}),false);
 const viewer=read('assets/live-viewer.js');
 assert.match(viewer,/if\(resetViewerForRoomChange\(\{roomChanged:true,connecting,hasViewer:Boolean\(viewerId\),recovering:recoveryPending,hasPeer:Boolean\(peer\)\}\)\)clearPlayer\(true\)/);
 assert.match(viewer,/lateViewerJoinNeedsLeave\(joined,version,epoch\)/);
 assert.match(viewer,/request\('leave',\{viewerId:joined\.viewerId\}\)/);
 assert.match(viewer,/healthPreviousFrames=0;healthPreviousTime=performance\.now\(\)/);
});

test('tab visibility recovery gives a foreground grace period only to automatic phone repair',()=>{
 assert.equal(deferForegroundPhoneRepair({visible:false}),true);
 assert.equal(deferForegroundPhoneRepair({foregroundAgeMs:0}),true);
 assert.equal(deferForegroundPhoneRepair({foregroundAgeMs:6999}),true);
 assert.equal(deferForegroundPhoneRepair({foregroundAgeMs:7000}),false);
 assert.equal(deferForegroundPhoneRepair({foregroundAgeMs:Infinity}),false);
 const director=read('assets/live-director.js');
 assert.match(director,/if\(!manual&&deferForegroundPhoneRepair\(/);
 assert.match(director,/foregroundResumeAt=performance\.now\(\)/);
 assert.match(director,/if\(sources\.get\(item\.id\)===item&&!document\.hidden&&item\.health\?\.update\(\)==='stalled'\)/);
 assert.match(director,/if\(item\.kind==='local'\)item\.stream\.getTracks\(\)\.forEach\(t=>t\.stop\(\)\)/);
});

test('never auto-upload interrupted, corrupt, or unverified recording',()=>{
 assert.equal(recordingAutoSaveAllowed({bytes:10000,localPlaybackVerified:true}),true);
 for(const item of [
  {bytes:0,localPlaybackVerified:true},
  {bytes:1000,localPlaybackVerified:true},
  {bytes:10000,localPlaybackVerified:false},
  {bytes:10000,recorderError:true,localPlaybackVerified:true},
  {bytes:NaN,localPlaybackVerified:true}
 ])assert.equal(recordingAutoSaveAllowed(item),false);
 assert.equal(videoProbeReady({videoWidth:1280,videoHeight:720,readyState:2}),true);
 assert.equal(videoProbeReady({videoWidth:1280,videoHeight:720,readyState:1}),false);
 assert.equal(videoProbeReady({videoWidth:0,videoHeight:0,readyState:4}),false);
 const director=read('assets/live-director.js');
 assert.match(director,/recorder\.onerror=e=>\{recorderFailed=true/);
 assert.match(director,/invalidRecording=!recordingAutoSaveAllowed/);
 assert.match(director,/if\(!invalidRecording\)await saveRecording\(file,title,startedRoom\)/);
 assert.match(director,/Saved bytes match, but served video playback is unverified/);
 assert.match(director,/verifyRecordedVideo\(persisted\.videoUrl/);
 assert.match(director,/recordingFinalizing=true;updateRecordControls\(\)/);
 assert.match(director,/const startedRecorder=recorder,startedRoom=recordRoom/);
});

test('first-frame video probe succeeds for decoded frame and cleans preview',async()=>{
 class FakeVideo {
  constructor(){this.callbacks=new Map();this.videoWidth=1280;this.videoHeight=720;this.readyState=0;this.src='';this.paused=false;this.cleared=false;}
  addEventListener(e,fn){this.callbacks.set(e,fn);}
  removeEventListener(e){this.callbacks.delete(e);}
  removeAttribute(){this.src='';this.cleared=true;}
  pause(){this.paused=true;}
  load(){if(this.src){queueMicrotask(()=>{this.readyState=2;this.callbacks.get('loadeddata')?.();});}}
 }
 const v=new FakeVideo();
 const result=await verifyRecordedVideo('blob:record-test',{makeVideo:()=>v,timeoutMs:500});
 assert.equal(result.ok,true);assert.equal(result.videoWidth,1280);assert.equal(v.src,'');assert.equal(v.cleared,true);assert.equal(v.callbacks.size,0);
});

test('video probe never calls metadata-only or decode error proof of playback',async()=>{
 class FakeVideo {
  constructor(){this.callbacks=new Map();this.videoWidth=1280;this.videoHeight=720;this.readyState=1;this.src='';}
  addEventListener(e,fn){this.callbacks.set(e,fn);}removeEventListener(e){this.callbacks.delete(e);}removeAttribute(){this.src='';}pause(){}
  load(){if(this.src)queueMicrotask(()=>this.callbacks.get('error')?.());}
 }
 const fail=await verifyRecordedVideo('/private/video',{makeVideo:()=>new FakeVideo(),timeoutMs:500});
 assert.equal(fail.ok,false);assert.match(fail.reason,/could not decode/);
 const missing=await verifyRecordedVideo('');assert.equal(missing.ok,false);
});

test('mobile shortcuts delegate to existing guarded Director actions',()=>{
 const html=read('live-director.php');
 const js=read('assets/live-director.js');
 for(const id of ['live-mobile-cameras','live-mobile-next','live-mobile-record','live-mobile-audio'])assert.match(html,new RegExp('id="'+id+'"'));
 assert.match(js,/wireMobileDirectorShortcuts\(/);
 assert.match(js,/sourcePanel:document\.querySelector\('\.azh-director-sources'\)/);
 assert.match(js,/canTake:\(\)=>sourceIds\(\)\.length>0/);
 assert.match(read('assets/live-studio.css'),/\.azh-mobile-director-bar/);
 assert.match(read('assets/live-mobile-controls.js'),/showRecording\(\)/);
});
