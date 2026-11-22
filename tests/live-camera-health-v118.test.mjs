import test from 'node:test';
import assert from 'node:assert/strict';
import {CameraHealth,cameraSummary,cameraPermissionMessage} from '../assets/live-camera-health.js';
function mock(){
 const handlers=new Map();const make=()=>({addEventListener(name,fn){let x=handlers.get(this)||[];x.push([name,fn]);handlers.set(this,x);},removeEventListener(name,fn){handlers.set(this,(handlers.get(this)||[]).filter(x=>x[0]!==name||x[1]!==fn));}});
 const track=Object.assign(make(),{readyState:'live',muted:false}), video=Object.assign(make(),{paused:false,readyState:3,videoWidth:1280,currentTime:0});
 return {track,video,stream:{getVideoTracks:()=>[track]},emit(target,event){for(const [name,fn] of handlers.get(target)||[])if(name===event)fn();}};
}
test('camera health only becomes live after a decoded frame, not merely an open track',()=>{
 let now=0;const m=mock();const h=new CameraHealth(m.video,m.stream,{now:()=>now}).start();
 assert.equal(h.snapshot(),'starting');assert.equal(h.playable(),false);
 now=200;h.frame();assert.equal(h.snapshot(),'live');assert.equal(h.playable(),true);
 now=4900;assert.equal(h.snapshot(),'stalled');assert.equal(h.playable(),false);
 now=5100;h.frame();assert.equal(h.playable(),true);
 h.stop();
});
test('missing frames, playback rejection, mute and lost connection report visible states',()=>{
 let now=0;const m=mock();const h=new CameraHealth(m.video,m.stream,{now:()=>now}).start();
 now=9200;assert.equal(h.snapshot(),'stalled');h.playRejected();assert.equal(h.snapshot(),'blocked');
 h.playbackError=false;h.frame();m.track.muted=true;assert.equal(h.snapshot(),'muted');
 m.track.muted=false;m.track.readyState='ended';assert.equal(h.snapshot(),'offline');h.stop();
});
test('legacy browsers confirm advancing source frames via timeupdate, not fake readyState',()=>{
 let now=100;const m=mock();const h=new CameraHealth(m.video,m.stream,{now:()=>now}).start();
 assert.equal(h.frames,0);m.video.currentTime=.4;m.emit(m.video,'timeupdate');assert.equal(h.frames,1);
 now=200;m.emit(m.video,'timeupdate');assert.equal(h.frames,1);
 m.video.currentTime=.6;m.emit(m.video,'timeupdate');assert.equal(h.frames,2);h.stop();
});
test('diagnostics summarize live feeds and clarify camera-permission failures',()=>{
 assert.deepEqual(cameraSummary(['live','starting','stalled','live']),{total:4,live:2,pending:1,issues:1,text:'2 live / 4 cameras · 1 need attention'});
 assert.match(cameraPermissionMessage({name:'NotAllowedError'}),/site-permissions/);
 assert.match(cameraPermissionMessage({name:'NotReadableError'}),/other camera apps/);
});
