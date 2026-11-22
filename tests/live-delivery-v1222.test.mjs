import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname,resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { liveVideoTrack, ViewerVideoRoute, outgoingFrameSample } from '../assets/live-program-delivery.js';
import { appendLiveTrack } from '../assets/live-viewer-core.js';

const dir=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const text=path=>readFileSync(resolve(dir,path),'utf8');
const canvas={kind:'video',id:'composite',readyState:'live'};
const phone={kind:'video',id:'approved-phone-video',readyState:'live',muted:false};
const source={kind:'phone',stream:{getVideoTracks:()=>[phone]}};
function route({hidden=true,selected='phone:approved',mediaProgramActive=false,layout='single',slot='phone:approved',sourceMap=new Map([['phone:approved',source]]),track=canvas}={}) {
 return liveVideoTrack({hidden,selected,mediaProgramActive,activeLayout:{type:layout,slots:[slot]},sources:sourceMap,canvasTrack:track});
}
test('visible Director always streams the composite canvas, preserving scene overlays and recording',()=>{
 assert.equal(route({hidden:false}),canvas);
 assert.equal(route({hidden:false,layout:'split'}),canvas);
});
test('hidden Director relays only a previously selected approved single-camera source',()=>{
 assert.equal(route(),phone);
 assert.equal(route({selected:'phone:another'}),canvas);
 assert.equal(route({slot:''}),canvas);
 assert.equal(route({sourceMap:new Map()}),canvas);
 assert.equal(route({sourceMap:new Map([['phone:approved',{...source,kind:'local'}]])}),canvas);
});
test('media playback, slate and multicamera compositions never leak a private phone source',()=>{
 assert.equal(route({mediaProgramActive:true}),canvas);
 assert.equal(route({layout:'split'}),canvas);
 assert.equal(route({layout:'grid'}),canvas);
 assert.equal(route({selected:null,slot:''}),canvas);
 assert.equal(route({track:null}),null);
});
test('muted or ended phone tracks are not relayed, even from a selected camera',()=>{
 phone.muted=true;assert.equal(route(),canvas);phone.muted=false;
 phone.readyState='ended';assert.equal(route(),canvas);phone.readyState='live';
 assert.equal(route(),phone);
});
test('viewer transport replaces one sender track rather than renegotiating or creating a new peer',async()=>{
 const calls=[],sender={replaceTrack:async track=>{calls.push(track.id);}};
 const router=new ViewerVideoRoute(sender,canvas);
 await router.setTarget(phone);
 assert.deepEqual(calls,['approved-phone-video']);assert.equal(router.current,phone);
 await router.setTarget(canvas);
 assert.deepEqual(calls,['approved-phone-video','composite']);assert.equal(router.current,canvas);
 router.close();await router.setTarget(phone);assert.equal(router.current,canvas);
});
test('rapid hide/show changes are serialized so stale replacements cannot win',async()=>{
 let resolveFirst;const calls=[];
 const sender={replaceTrack:async track=>{calls.push(track.id);if(calls.length===1)await new Promise(resolve=>{resolveFirst=resolve;});}};
 const router=new ViewerVideoRoute(sender,canvas);
 const first=router.setTarget(phone);
 const second=router.setTarget(canvas);
 resolveFirst();await Promise.all([first,second]);
 assert.equal(router.current,canvas);assert.deepEqual(calls,['approved-phone-video','composite']);
});
test('failed track routing reports the failure without claiming the fallback succeeded',async()=>{
 const errors=[];const sender={replaceTrack:async()=>{throw Error('invalid modification');}};
 const router=new ViewerVideoRoute(sender,canvas,e=>errors.push(e.message));
 await router.setTarget(phone);
 assert.equal(router.current,canvas);assert.deepEqual(errors,['invalid modification']);
});
test('viewer keeps the same video srcObject when the audio track arrives later',()=>{
 const tracks=[];const stream={getTracks:()=>tracks,addTrack:t=>tracks.push(t)};
 const video={srcObject:null};const v={kind:'video',id:'video'};const a={kind:'audio',id:'audio'};
 let active=appendLiveTrack(video,{track:v,streams:[]},null,()=>stream);
 assert.equal(video.srcObject,stream);assert.deepEqual(stream.getTracks(),[v]);
 active=appendLiveTrack(video,{track:a,streams:[]},active,()=>{throw Error('wrong stream created');});
 assert.equal(active,stream);assert.equal(video.srcObject,stream);assert.deepEqual(stream.getTracks(),[v,a]);
 appendLiveTrack(video,{track:a,streams:[]},active);assert.equal(stream.getTracks().length,2);
});
test('existing WebRTC stream with both tracks is accepted without duplicating either',()=>{
 const videoTrack={id:'v'},audioTrack={id:'a'},stream={getTracks:()=>[videoTrack,audioTrack],addTrack:()=>{throw Error('duplicate');}};
 const video={srcObject:null};const active=appendLiveTrack(video,{track:videoTrack,streams:[stream]},null);
 assert.equal(active,stream);appendLiveTrack(video,{track:audioTrack,streams:[stream]},active);
 assert.equal(video.srcObject,stream);
});
test('source frame watchdog does not wrongly take hidden, still-connected camera off program',()=>{
 const director=text('assets/live-director.js');
 assert.match(director,/document\.hidden&&state==='stalled'/);
 assert.match(director,/document\.addEventListener\('visibilitychange'/);
 assert.match(director,/if\(document\.hidden&&room\)renderProgram/);
 assert.match(director,/new ViewerVideoRoute\(sender,track/);
 assert.match(director,/routeViewerVideo\(\)/);
});
test('fan playback is muted to allow autoplay and exposes explicit sound control, with frame health feedback',()=>{
 const viewer=text('assets/live-viewer.js'),markup=text('live.php');
 assert.match(markup,/<video id="fan-live-video" playsinline autoplay muted controls/);
 assert.match(markup,/id="fan-live-sound"/);
 assert.match(viewer,/video\.muted=true;video\.defaultMuted=true;/);
 assert.match(viewer,/playerStream=appendLiveTrack\(video,e,playerStream\)/);
 assert.match(viewer,/no new video frames are arriving/);
 assert.doesNotMatch(viewer,/video\.srcObject=e\.streams\[0\]/);
});

test('outgoing encoder stats distinguish phone preview FPS from real frames sent to fans',()=>{
 const start=outgoingFrameSample(null,0,1000);
 assert.equal(start.state,'starting');
 const running=outgoingFrameSample(start,72,4000);
 assert.equal(running.state,'sending');assert.equal(running.fps,24);
 const pause=outgoingFrameSample(running,72,8000);
 assert.equal(pause.state,'waiting');
 const stalled=outgoingFrameSample(pause,72,13500);
 assert.equal(stalled.state,'stalled');
 const recovered=outgoingFrameSample(stalled,90,14500);
 assert.equal(recovered.state,'sending');assert.equal(recovered.fps,18);
 assert.equal(outgoingFrameSample(recovered,NaN,16000).state,'unknown');
});
test('Director detects stalled outbound frames without publishing raw media or changing access control',()=>{
 const js=text('assets/live-director.js'),html=text('live-director.php');
 assert.match(js,/item\.router\.sender\.getStats\(\)/);
 assert.match(js,/item\.forceDirect=true;item\.quality=null;routeViewerVideo\(\)/);
 assert.match(js,/document\.hidden\|\|Boolean\(viewer\?\.forceDirect\)/);
 assert.match(html,/id="live-delivery-state"/);
 assert.match(js,/clearInterval\(deliveryTimer\)/);
});
