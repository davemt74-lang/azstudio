import test, {afterEach} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const source = (await readFile(new URL('../assets/capture.js', import.meta.url), 'utf8'))
  .replace("import { getVideoMetadata, supportedRecordingType } from './media-engine.js';",
    "const getVideoMetadata = (...args) => globalThis.captureTestMetadata(...args); const supportedRecordingType = () => 'video/webm';");
const {InterviewCapture} = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));
const original = new Map(['navigator','performance','MediaRecorder','captureTestMetadata','MediaStream','document','requestAnimationFrame','cancelAnimationFrame'].map(key=>[key,Object.getOwnPropertyDescriptor(globalThis,key)]));
afterEach(() => {
  for (const [key,descriptor] of original) {
    if (descriptor) Object.defineProperty(globalThis,key,descriptor);
    else delete globalThis[key];
  }
});

class Track extends EventTarget {
  constructor(kind) { super(); this.kind=kind; this.readyState='live'; }
  stop() { this.readyState='ended'; }
  unplug() { this.readyState='ended'; this.dispatchEvent(new Event('ended')); }
}
class Stream {
  constructor(tracks = [new Track('video'),new Track('audio')]) { this.tracks=tracks; }
  getTracks() { return this.tracks; }
  getVideoTracks() { return this.tracks.filter(t=>t.kind==='video'); }
  getAudioTracks() { return this.tracks.filter(t=>t.kind==='audio'); }
}
function setup({metadata=async()=>{throw new Error('no metadata');},stopDelay=0,constructionFails=false,captureOptions={}}={}) {
  let time=100;
  const recorders=[];
  class Recorder {
    static isTypeSupported(type) { return type.startsWith('video/webm'); }
    constructor(stream) {
      this.stream = stream;
      if (constructionFails) throw new Error('Encoder unavailable');
      this.state='inactive'; this.mimeType='video/webm'; recorders.push(this);
    }
    start() { this.state='recording'; queueMicrotask(()=>this.onstart?.()); }
    stop() {
      if(this.state==='inactive')throw new Error('Already stopped');
      this.state='inactive';
      const deliver=()=>{this.ondataavailable?.({data:new Blob(['test encoded media'],{type:this.mimeType})});this.onstop?.();};
      if(stopDelay)setTimeout(deliver,stopDelay);else queueMicrotask(deliver);
    }
  }
  Object.defineProperty(globalThis,'performance',{configurable:true,value:{now:()=>time}});
  Object.defineProperty(globalThis,'MediaRecorder',{configurable:true,value:Recorder});
  Object.defineProperty(globalThis,'captureTestMetadata',{configurable:true,value:metadata});
  const stream=new Stream();
  Object.defineProperty(globalThis,'navigator',{configurable:true,value:{mediaDevices:{getUserMedia:async()=>stream,enumerateDevices:async()=>[]}}});
  const video={srcObject:null,play:async()=>{}};
  const capture=new InterviewCapture(video,captureOptions);
  return {capture,video,stream,recorders,setTime:value=>time=value};
}
const tick=()=>new Promise(resolve=>setTimeout(resolve,0));

test('Stop cancels a countdown immediately and leaves capture available',async()=>{
  const {capture,recorders}=setup();
  await capture.enable();
  const pending=capture.start({countdown:3});
  capture.stop();
  await assert.rejects(pending,{name:'AbortError'});
  assert.equal(recorders.length,0);
  assert.equal(capture.busy,false);
  assert.equal(capture.operation,null);
});

test('duration fallback stops at the Stop click rather than encoder finalization',async()=>{
  const {capture,setTime}=setup({stopDelay:30});
  await capture.enable();
  const pending=capture.start({countdown:0});
  await tick();
  setTime(1100);
  capture.markAnswerStart();
  setTime(2100);
  capture.stop();
  setTime(15000);
  const result=await pending;
  assert.equal(result.durationMs,2000);
  assert.equal(result.answerStartMs,1000);
  assert.equal(result.durationEstimated,true);
  assert.equal(result.interrupted,false);
  assert.equal(capture.busy,false);
});

test('encoded duration takes priority and bounds the answer marker',async()=>{
  const {capture,setTime}=setup({metadata:async()=>({duration:1.2,width:1280,height:720})});
  await capture.enable();
  const pending=capture.start({countdown:0});
  await tick();
  setTime(1800);capture.markAnswerStart();setTime(2000);capture.stop();
  const result=await pending;
  assert.equal(result.durationMs,1200);
  assert.equal(result.answerStartMs,1050);
  assert.equal(result.durationEstimated,false);
});

test('losing only the microphone finishes and preserves an interrupted take',async()=>{
  const {capture,stream,setTime}=setup();
  await capture.enable();
  const pending=capture.start({countdown:0});
  await tick();
  setTime(3100);
  stream.getAudioTracks()[0].unplug();
  const result=await pending;
  assert.equal(result.interrupted,true);
  assert.match(result.interruptionReason,/microphone disconnected/);
  assert.ok(result.blob.size>0);
  assert.equal(capture.busy,false);
});

test('a recorder error preserves partial output and releases the busy lock',async()=>{
  const {capture,recorders}=setup();
  await capture.enable();
  const pending=capture.start({countdown:0});
  await tick();
  recorders[0].onerror({error:new Error('Encoding interrupted')});
  const result=await pending;
  assert.equal(result.interrupted,true);
  assert.equal(result.interruptionReason,'Encoding interrupted');
  assert.equal(capture.recorder,null);
});

test('overlapping enable requests stop stale streams without replacing the chosen device',async()=>{
  const {capture,video}=setup();
  const resolvers=[];
  navigator.mediaDevices.getUserMedia=()=>new Promise(resolve=>resolvers.push(resolve));
  const first=capture.enable('first').catch(error=>error);
  const second=capture.enable('second');
  const wanted=new Stream(), stale=new Stream();
  resolvers[1](wanted);await second;
  resolvers[0](stale);
  assert.equal((await first).name,'AbortError');
  assert.equal(capture.stream,wanted);
  assert.equal(video.srcObject,wanted);
  assert.ok(stale.getTracks().every(track=>track.readyState==='ended'));
  capture.disable();
  assert.equal(capture.stream,null);
  assert.equal(video.srcObject,null);
  assert.ok(wanted.getTracks().every(track=>track.readyState==='ended'));
});

test('constructor failure restores state and keeps the camera available for retry',async()=>{
  const {capture,stream}=setup({constructionFails:true});
  await capture.enable();
  await assert.rejects(capture.start({countdown:0}),/Encoder unavailable/);
  assert.equal(capture.busy,false);
  assert.equal(capture.recorder,null);
  assert.ok(stream.getTracks().every(track=>track.readyState==='live'));
});

test('disable during a take stops recording and all device tracks',async()=>{
  const {capture,stream}=setup();
  await capture.enable();
  const pending=capture.start({countdown:0});
  await tick();
  capture.disable();
  const result=await pending;
  assert.equal(result.interrupted,true);
  assert.equal(capture.stream,null);
  assert.ok(stream.getTracks().every(track=>track.readyState==='ended'));
});


function addSwitchingSupport(context, deviceIds = ['cam-a', 'cam-b']) {
  const canvasTracks = [], drawCalls = [], canvases=[];
  Object.defineProperty(globalThis, 'MediaStream', {configurable: true, value: Stream});
  Object.defineProperty(globalThis, 'document', {configurable: true, value: {
    createElement(tag) {
      assert.equal(tag, 'canvas');
      const canvas = {
        getContext: () => ({fillRect() {}, drawImage(...args) { drawCalls.push(args); }}),
        captureStream() { const track = new Track('video'); canvasTracks.push(track); return new Stream([track]); },
      };
      canvases.push(canvas);return canvas;
    },
  }});
  context.video.videoWidth = 1920; context.video.videoHeight = 1080;
  navigator.mediaDevices.enumerateDevices = async () => deviceIds.map(id => ({kind: 'videoinput', deviceId: id, label: id.toUpperCase()}));
  return {canvasTracks, drawCalls,canvases};
}
const settle = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); };

test('portrait and square capture frame the recorded canvas without changing the microphone',async()=>{
  for(const [orientation,width,height] of [['portrait',720,1280],['square',720,720]]) {
    const context=setup({captureOptions:{orientation,fit:'cover'}});
    const {capture,stream,recorders}=context,{canvases,drawCalls}=addSwitchingSupport(context);
    let constraints; navigator.mediaDevices.getUserMedia=async value=>{constraints=value;return stream;};
    await capture.enable('cam-a');
    assert.equal(constraints.video.width.ideal,width);assert.equal(constraints.video.height.ideal,height);
    const pending=capture.start({countdown:0});await settle();
    assert.equal(canvases[0].width,width);assert.equal(canvases[0].height,height);
    assert.deepEqual(recorders[0].stream.getAudioTracks(),stream.getAudioTracks());
    assert.ok(drawCalls[0][1]<0,'Landscape camera is cropped to the requested portrait/square preview');
    assert.equal(drawCalls[0][2],0);assert.equal(drawCalls[0][4],height);
    capture.stop();await pending;capture.destroy();
  }
});

test('unsupported canvas capture announces the native-frame single-camera fallback',async()=>{
  const {capture}=setup({captureOptions:{orientation:'portrait',fit:'cover'}}),events=[];
  capture.addEventListener('statechange',event=>events.push(event.detail));await capture.enable();
  const pending=capture.start({countdown:0});await settle();
  const warning=events.find(e=>e.state==='switching-unavailable');
  assert.match(warning.message,/native frame/);assert.match(warning.message,/editor/);
  capture.stop();await pending;capture.destroy();
});

test('local and phone camera switches keep one recorder and the original microphone throughout the take', async () => {
  const context = setup();
  const {capture, stream, recorders, video} = context;
  const {canvasTracks} = addSwitchingSupport(context);
  await capture.enable('cam-a');
  const microphone = stream.getAudioTracks()[0];
  const secondCamera = new Stream([new Track('video')]);
  navigator.mediaDevices.getUserMedia = async constraints => { assert.equal(constraints.audio, false); return secondCamera; };
  const phone = new Stream([new Track('video')]);
  capture.addRemoteCamera('phone-1', phone, 'Phone camera');
  const events = []; capture.addEventListener('cameraswitch', event => events.push(event.detail));
  const pending = capture.start({countdown: 0}); await settle();
  const recordedStream = recorders[0].stream;
  await capture.switchCamera('cam-b');
  await capture.switchCamera('phone-1');
  assert.equal(recorders.length, 1);
  assert.equal(recorders[0].stream, recordedStream);
  assert.deepEqual(recordedStream.getAudioTracks(), [microphone]);
  assert.equal(recordedStream.getVideoTracks()[0], canvasTracks[0]);
  assert.equal(microphone.readyState, 'live');
  assert.equal(stream.getVideoTracks()[0].readyState, 'ended');
  assert.equal(video.srcObject, phone);
  assert.deepEqual(events.map(event => event.deviceId), ['cam-b', 'phone-1']);
  capture.stop(); const result = await pending;
  assert.equal(result.interrupted, false);
  assert.equal(canvasTracks[0].readyState, 'ended');
  assert.equal(microphone.readyState, 'live');
  capture.destroy();
  assert.equal(microphone.readyState, 'ended');
  assert.equal(phone.getVideoTracks()[0].readyState, 'live', 'the phone module owns its tracks');
});

test('revoked or disconnected phone camera falls back to the local camera without ending the take', async () => {
  const context = setup(); const {capture, stream, recorders} = context;
  addSwitchingSupport(context); await capture.enable('cam-a');
  const phone = new Stream([new Track('video')]);
  capture.addRemoteCamera('phone-1', phone, 'Phone');
  const pending = capture.start({countdown: 0}); await settle();
  await capture.switchCamera('phone-1');
  phone.getVideoTracks()[0].unplug(); await settle();
  assert.equal(capture.activeCameraId, 'cam-a');
  assert.equal(recorders[0].state, 'recording');
  assert.equal(stream.getAudioTracks()[0].readyState, 'live');
  const reconnected = new Stream([new Track('video')]);
  capture.addRemoteCamera('phone-1', reconnected, 'Phone'); await capture.switchCamera('phone-1');
  capture.removeRemoteCamera('phone-1'); await settle();
  assert.equal(capture.activeCameraId, 'cam-a');
  assert.equal(recorders[0].state, 'recording');
  assert.equal(reconnected.getVideoTracks()[0].readyState, 'live');
  capture.stop(); assert.equal((await pending).interrupted, false); capture.destroy();
});

test('interval switching starts only while recording and stops scheduling when the take ends', async t => {
  const context = setup(); const {capture, recorders} = context;
  addSwitchingSupport(context); await capture.enable('cam-a');
  let acquisitions = 0;
  navigator.mediaDevices.getUserMedia = async () => { acquisitions++; return new Stream([new Track('video')]); };
  t.mock.timers.enable({apis: ['setTimeout']});
  capture.configureSwitching({mode: 'interval', intervalSeconds: 30, deviceIds: ['cam-a', 'cam-b']});
  t.mock.timers.tick(60000); await settle(); assert.equal(acquisitions, 0);
  const pending = capture.start({countdown: 0}); await settle();
  t.mock.timers.tick(29999); await settle(); assert.equal(capture.activeCameraId, 'cam-a');
  t.mock.timers.tick(1); await settle(); assert.equal(capture.activeCameraId, 'cam-b');
  assert.equal(recorders.length, 1);
  capture.stop(); await pending;
  t.mock.timers.tick(120000); await settle(); assert.equal(acquisitions, 1);
  assert.equal(capture.switchTimer, null); capture.destroy();
});

test('random switching varies gaps from 15 to 45 seconds around a 30-second setting', async t => {
  const context = setup(); const {capture} = context;
  addSwitchingSupport(context); await capture.enable('cam-a');
  navigator.mediaDevices.getUserMedia = async () => new Stream([new Track('video')]);
  t.mock.timers.enable({apis: ['setTimeout']});
  const random = Math.random; Math.random = () => 0;
  try {
    capture.configureSwitching({mode: 'random', intervalSeconds: 30});
    const pending = capture.start({countdown: 0}); await settle();
    t.mock.timers.tick(14999); await settle(); assert.equal(capture.activeCameraId, 'cam-a');
    Math.random = () => 0.999;
    t.mock.timers.tick(1); await settle(); assert.equal(capture.activeCameraId, 'cam-b');
    t.mock.timers.tick(44969); await settle(); assert.equal(capture.activeCameraId, 'cam-b');
    t.mock.timers.tick(1); await settle(); assert.equal(capture.activeCameraId, 'cam-a');
    capture.stop(); await pending;
  } finally { Math.random = random; capture.destroy(); }
});

test('a late camera acquisition cannot replace a newer switch or leak camera tracks', async () => {
  const context = setup(); const {capture, video} = context;
  addSwitchingSupport(context, ['cam-a', 'cam-b', 'cam-c']); await capture.enable('cam-a');
  const resolvers = [];
  navigator.mediaDevices.getUserMedia = () => new Promise(resolve => resolvers.push(resolve));
  const first = capture.switchCamera('cam-b').catch(error => error);
  const second = capture.switchCamera('cam-c');
  const stale = new Stream([new Track('video')]), latest = new Stream([new Track('video')]);
  resolvers[1](latest); await second; resolvers[0](stale);
  assert.equal((await first).name, 'AbortError');
  assert.equal(capture.activeCameraId, 'cam-c');
  assert.equal(video.srcObject, latest);
  assert.equal(stale.getVideoTracks()[0].readyState, 'ended');
  capture.destroy(); assert.equal(latest.getVideoTracks()[0].readyState, 'ended');
});

test('canvas-unavailable browsers retain ordinary recording and reject an in-take switch', async () => {
  const context = setup(); const {capture, recorders} = context;
  await capture.enable('cam-a');
  const phone = new Stream([new Track('video')]); capture.addRemoteCamera('phone-1', phone);
  const pending = capture.start({countdown: 0}); await settle();
  await assert.rejects(capture.switchCamera('phone-1'), /cannot switch cameras during a take/);
  assert.equal(recorders[0].state, 'recording');
  capture.stop(); assert.equal((await pending).interrupted, false); capture.destroy();
});


test('a phone can be the initial camera with only a host microphone and no webcam', async () => {
  const context = setup(); const {capture, video, recorders} = context;
  addSwitchingSupport(context, []);
  const audioOnly = new Stream([new Track('audio')]);
  let requested;
  navigator.mediaDevices.getUserMedia = async constraints => { requested = constraints; return audioOnly; };
  const phone = new Stream([new Track('video')]);
  capture.addRemoteCamera('phone:only', phone, 'Only phone camera');
  await capture.enable('phone:only', 'host-mic');
  assert.equal(requested.video, false);
  assert.equal(requested.audio.deviceId.exact, 'host-mic');
  assert.equal(video.srcObject, phone);
  assert.equal(capture.hasDevices(), true);
  assert.equal(capture.activeCameraId, 'phone:only');
  const pending = capture.start({countdown: 0}); await settle();
  assert.deepEqual(recorders[0].stream.getAudioTracks(), audioOnly.getAudioTracks());
  capture.removeRemoteCamera('phone:only');
  const result = await pending;
  assert.equal(result.interrupted, true);
  assert.match(result.interruptionReason, /camera disconnected/);
  capture.destroy();
  assert.equal(audioOnly.getAudioTracks()[0].readyState, 'ended');
  assert.equal(phone.getVideoTracks()[0].readyState, 'live');
});

test('a phone-first take acquires an available local camera as fallback and keeps the microphone', async () => {
  const context = setup(); const {capture, recorders} = context;
  addSwitchingSupport(context, ['cam-local']);
  const microphone = new Track('audio');
  const local = new Stream([new Track('video')]);
  navigator.mediaDevices.getUserMedia = async constraints => constraints.video === false ? new Stream([microphone]) : local;
  const phone = new Stream([new Track('video')]); capture.addRemoteCamera('phone:first', phone);
  await capture.enable('phone:first');
  const pending = capture.start({countdown: 0}); await settle();
  phone.getVideoTracks()[0].unplug(); await settle();
  assert.equal(capture.activeCameraId, 'cam-local');
  assert.equal(recorders[0].state, 'recording');
  assert.deepEqual(recorders[0].stream.getAudioTracks(), [microphone]);
  capture.stop(); assert.equal((await pending).interrupted, false); capture.destroy();
});


test('deselecting every rotation source stops automatic switches during the same take', async t => {
  const context = setup(); const {capture} = context;
  addSwitchingSupport(context); await capture.enable('cam-a');
  let acquisitions = 0;
  navigator.mediaDevices.getUserMedia = async () => { acquisitions++; return new Stream([new Track('video')]); };
  t.mock.timers.enable({apis: ['setTimeout']});
  capture.configureSwitching({mode: 'interval', intervalSeconds: 30});
  const pending = capture.start({countdown: 0}); await settle();
  capture.configureSwitching({mode: 'interval', intervalSeconds: 30, deviceIds: []});
  t.mock.timers.tick(90000); await settle();
  assert.equal(acquisitions, 0); assert.equal(capture.activeCameraId, 'cam-a');
  capture.configureSwitching({mode: 'interval', intervalSeconds: 30, deviceIds: ['cam-a', 'cam-b']});
  t.mock.timers.tick(30000); await settle(); assert.equal(capture.activeCameraId, 'cam-b');
  capture.stop(); await pending; capture.destroy();
});


test('a late failed fallback cannot interrupt a newer successful camera selection', async () => {
  const context = setup(); const {capture, recorders} = context;
  addSwitchingSupport(context, ['cam-a', 'cam-b']);
  navigator.mediaDevices.getUserMedia = async () => new Stream([new Track('audio')]);
  const phone = new Stream([new Track('video')]); capture.addRemoteCamera('phone:first', phone);
  await capture.enable('phone:first');
  const pending = capture.start({countdown: 0}); await settle();
  const resolvers = [];
  navigator.mediaDevices.getUserMedia = () => new Promise(resolve => resolvers.push(resolve));
  phone.getVideoTracks()[0].unplug();
  const chosen = capture.switchCamera('cam-b');
  resolvers[1](new Stream([new Track('video')])); await chosen;
  const stale = new Stream([new Track('video')]); resolvers[0](stale); await settle();
  assert.equal(capture.activeCameraId, 'cam-b');
  assert.equal(recorders[0].state, 'recording');
  assert.equal(stale.getVideoTracks()[0].readyState, 'ended');
  capture.stop(); assert.equal((await pending).interrupted, false); capture.destroy();
});
