import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {addMedia,trimClipEdge,splitClip,duplicateClip,detachClipAudio,changeDetachedAudio,renderSegments,totalSeconds,DEFAULT_SETTINGS,changeClip} from '../assets/personal-timeline-core.js';
import {insertAudioKeyframe,normalizeAudioKeyframes,volumeAt,rebaseAudioKeyframes,setAudioKeyframe,removeAudioKeyframe} from '../assets/personal-audio-envelope.js';
import {normalizeSettings,audioGainAt,buildTimeline} from '../assets/media-engine.js';
const media={id:'a'.repeat(32),title:'Owned recording',durationMs:8000,videoUrl:'/private/owned-video.mp4'};
const source=path=>readFileSync(new URL('../'+path,import.meta.url),'utf8');

test('inserting a volume keyframe preserves the interpolated level at that instant',()=>{
  const before=[{timeMs:500,volume:1},{timeMs:1500,volume:0}];
  const inserted=insertAudioKeyframe(before,1000,2000,0.4);
  assert.equal(inserted.length,3);
  assert.equal(inserted[1].timeMs,1000);
  assert.equal(inserted[1].volume,0.5);
  assert.deepEqual(before,[{timeMs:500,volume:1},{timeMs:1500,volume:0}], 'original envelope remains unchanged');
  assert.equal(insertAudioKeyframe(inserted,1000,2000,0.4).length,3);
  assert.deepEqual(setAudioKeyframe(inserted,1,0.2,2000)[1],{timeMs:1000,volume:0.2});
  assert.equal(removeAudioKeyframe(inserted,1,2000).length,2);
});

test('volume keyframe input must be ordered, finite, bounded and 32 points or fewer',()=>{
  assert.throws(()=>normalizeAudioKeyframes([{timeMs:100,volume:0.2},{timeMs:100,volume:.8}],8000),/increasing/);
  assert.throws(()=>normalizeAudioKeyframes([{timeMs:-1,volume:0.2}],8000),/increasing/);
  assert.throws(()=>normalizeAudioKeyframes([{timeMs:8100,volume:0.2}],8000),/increasing/);
  assert.throws(()=>normalizeAudioKeyframes([{timeMs:100,volume:Infinity}],8000),/increasing/);
  assert.throws(()=>normalizeAudioKeyframes(Array.from({length:33},(_,i)=>({timeMs:i,volume:1})),8000),/Too many/);
});

test('timed trim of source video rebases keyframes and never changes source media duration',()=>{
  const raw=addMedia([],media,8000);
  const keyframes=[{timeMs:0,volume:0},{timeMs:4000,volume:1},{timeMs:8000,volume:0}];
  const edited=changeClip(raw,raw[0].id,{audioKeyframes:keyframes});
  const first=trimClipEdge(edited,edited[0].id,'start',2000);
  const next=trimClipEdge(first,edited[0].id,'end',6000);
  assert.equal(next[0].durationMs,8000);
  assert.equal(next[0].trimStartMs,2000);
  assert.equal(next[0].trimEndMs,6000);
  assert.equal(next[0].audioKeyframes[0].timeMs,0);
  assert.equal(next[0].audioKeyframes[0].volume,0.5);
  assert.equal(next[0].audioKeyframes.at(-1).volume,0.5);
  assert.equal(totalSeconds(next),4);
  assert.equal(edited[0].trimStartMs,0);
});

test('splitting and duplicating a clip preserve independent envelope values',()=>{
  const raw=addMedia([],media,8000);
  const original=changeClip(raw,raw[0].id,{audioKeyframes:[{timeMs:0,volume:0},{timeMs:8000,volume:1}]});
  const next=splitClip(original,raw[0].id,3000);
  assert.equal(next.length,2);
  assert.equal(next[0].audioKeyframes.at(-1).volume,0.375);
  assert.equal(next[1].audioKeyframes[0].volume,0.375);
  const duplicated=duplicateClip(next,next[1].id);
  assert.equal(duplicated.length,3);
  assert.notEqual(duplicated[2].id,duplicated[1].id);
  assert.notEqual(duplicated[2].audioKeyframes,duplicated[1].audioKeyframes);
  assert.equal(totalSeconds(duplicated),13);
});

test('detach creates a separate A2 clip and silences the picture-track source for preview/export',()=>{
  const raw=addMedia([],media,8000);
  const clips=changeClip(raw,raw[0].id,{trimStartMs:1000,trimEndMs:5000,volume:0.65,audioKeyframes:[{timeMs:0,volume:0},{timeMs:4000,volume:0.8}]});
  const result=detachClipAudio(clips,DEFAULT_SETTINGS,raw[0].id);
  const detached=result.settings.detachedAudio[0];
  assert.equal(result.clips[0].audioDetached,true);
  assert.equal(result.clips[0].volume,0.65);
  assert.equal(detached.volume,0.65);
  assert.equal(detached.trimStartMs,1000);
  assert.equal(detached.trimEndMs,5000);
  assert.equal(detached.startMs,0);
  assert.notEqual(detached.id,raw[0].id);
  assert.equal(renderSegments(result.clips,[media])[0].volume,0);
  assert.deepEqual(renderSegments(result.clips,[media])[0].audioKeyframes,[]);
  assert.equal(renderSegments(clips,[media])[0].volume,0.65);
  assert.equal(renderSegments(result.clips,[media])[0].src,media.videoUrl);
});

test('detached audio can move/trim/change volume without moving or changing picture',()=>{
  const raw=addMedia([],media,8000);
  const result=detachClipAudio(raw,DEFAULT_SETTINGS,raw[0].id);
  const original=result.clips[0];
  const detached=result.settings.detachedAudio[0];
  const moved=changeDetachedAudio(result.settings,detached.id,{startMs:3000,trimStartMs:700,trimEndMs:6400,volume:0.2});
  assert.equal(moved.detachedAudio[0].startMs,3000);
  assert.equal(moved.detachedAudio[0].volume,0.2);
  assert.deepEqual(result.clips[0],original);
  assert.equal(totalSeconds(result.clips),8);
  assert.throws(()=>changeDetachedAudio(result.settings,detached.id,{startMs:-1}),/Invalid audio placement/);
  assert.throws(()=>detachClipAudio(result.clips,result.settings,raw[0].id),/already detached/);
});

test('renderer normalizes and uses audio automation in the preview/export timeline',()=>{
  const envelope=[{timeMs:0,volume:1},{timeMs:2000,volume:0}];
  const segment={id:'clip',src:'owned.mp4',duration:2,trimStart:0,trimEnd:2,volume:0.6,audioKeyframes:envelope};
  const timeline=buildTimeline([segment],{cardMode:'none',fadeDuration:0,detachedAudio:[{url:'owned.mp4',startMs:1000,trimStartMs:500,trimEndMs:2000,volume:.3,audioKeyframes:[{timeMs:0,volume:0.2},{timeMs:1500,volume:1}]}]});
  assert.equal(timeline.settings.detachedAudio.length,1);
  assert.equal(timeline.settings.detachedAudio[0].startMs,1000);
  assert.equal(audioGainAt(timeline.segments[0],1),0.5);
  assert.equal(audioGainAt(timeline.segments[0],2),0);
  assert.ok(Math.abs(volumeAt(timeline.settings.detachedAudio[0].audioKeyframes,750,.3)-.6)<1e-9);
  assert.deepEqual(normalizeSettings({detachedAudio:[{mediaId:media.id}]}).detachedAudio,[], 'only hydrated owned URLs can be rendered');
});

test('editor has exactly one A2 lane, context menu, two moveable inspectors and trim handles',()=>{
  const html=source('my-editor.php'),ui=source('assets/personal-timeline.js'),css=source('assets/personal-timeline.css');
  assert.equal((html.match(/id="vs-detached-audio"/g)||[]).length,1);
  for(const id of ['vs-timeline-menu','vs-inspector-drag','vs-audio-drag','vs-audio-properties','pt-detach-audio','vs-snap'])assert.match(html,new RegExp(`id="${id}"`));
  assert.match(ui,/enableTrimHandle/);
  assert.match(ui,/clipMenu\(/);
  assert.match(ui,/rebaseAudioKeyframes/);
  assert.match(ui,/insertAudioKeyframe/);
  assert.match(ui,/audioEdit/);
  assert.match(css,/\.vs-inspector,\.editor-workspace \.vs-audio-inspector\{position:fixed/);
});

test('live director and paired phone use shared relay configuration and bounded retries',()=>{
  const api=source('personal-phone-api.php'),phone=source('assets/personal-camera.js'),host=source('assets/live-director.js'),camera=source('my-camera.php');
  assert.match(api,/['"]iceServers['"]=>az_live_ice_servers\(\)/);
  assert.match(host,/phoneIceServers=Array\.isArray\(devices\.iceServers\)/);
  assert.match(host,/phones\.sync\(/);
  assert.match(phone,/new RTCPeerConnection\(\{iceServers:liveIceServers\}\)/);
  assert.match(phone,/liveRetry>=3/);
  assert.match(phone,/autoSharedByRecording&&liveWanted/);
  assert.match(camera,/\(string\)\(\$_GET\['studio'\]\?\?''\)!=='live'/);
});
