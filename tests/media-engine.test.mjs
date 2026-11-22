import test from 'node:test';
import assert from 'node:assert/strict';
// Load the browser module through its real URL so relative audio-envelope imports resolve.
import {buildTimeline, fadeAtTime, normalizeSettings, supportedRecordingType} from '../assets/media-engine.js';

test('each question card immediately precedes its trimmed answer, with contiguous time', () => {
  const input = [{id:'one',question:'Question one?',src:'/media.php?id=1',duration:12,trimStart:2,trimEnd:8}, {id:'two',question:'Question two?',src:'/media.php?id=2',duration:5,trimStart:1}];
  const before = JSON.stringify(input);
  const timeline = buildTimeline(input, {cardDuration:3,fadeDuration:0.5});
  assert.deepEqual(timeline.entries.map(entry=>[entry.type,entry.start,entry.end]), [['card',0,3],['answer',3,9],['card',9,12],['answer',12,16]]);
  assert.equal(timeline.totalDuration,16);
  assert.equal(timeline.duration,16);
  assert.equal(JSON.stringify(input),before);
});

test('short answers cannot receive overlapping fades and remain fully visible at their midpoint', () => {
  const timeline = buildTimeline([{src:'test',duration:0.4}],{fadeDuration:3});
  assert.equal(timeline.segments[0].fadeDuration,0.2);
  assert.equal(fadeAtTime(0,0.4,3),0);
  assert.equal(fadeAtTime(0.1,0.4,3),0.5);
  assert.equal(fadeAtTime(0.2,0.4,3),1);
  assert.ok(Math.abs(fadeAtTime(0.3,0.4,3)-0.5)<1e-10);
  assert.equal(fadeAtTime(0.4,0.4,3),0);
});

test('trims are bounded by the source and empty selections fail explicitly', () => {
  assert.throws(()=>buildTimeline([]),/Select at least/);
  assert.throws(()=>buildTimeline([{duration:5}]),/no video source/);
  assert.throws(()=>buildTimeline([{src:'test',duration:5,trimStart:4,trimEnd:2}]),/end time/);
  assert.throws(()=>buildTimeline([{src:'test',duration:Infinity}]),/valid video duration/);
  const timeline=buildTimeline([{src:'test',duration:5,trimStart:-2,trimEnd:20}]);
  assert.equal(timeline.segments[0].trimStart,0);
  assert.equal(timeline.segments[0].trimEnd,5);
});

test('dimensions follow landscape portrait and square export settings', () => {
  const clip=[{src:'test',duration:2}];
  assert.deepEqual([buildTimeline(clip).width,buildTimeline(clip).height],[1280,720]);
  const portrait=buildTimeline(clip,{aspect:'9:16',resolution:1080});
  assert.deepEqual([portrait.width,portrait.height],[1080,1920]);
  const square=buildTimeline(clip,{aspect:'1:1',resolution:720});
  assert.deepEqual([square.width,square.height],[720,720]);
});

test('normalization keeps user settings finite and a question card present', () => {
  const settings=normalizeSettings({cardDuration:NaN,fadeDuration:-1,aspect:'wrong',resolution:999,fit:'wrong'});
  assert.equal(settings.cardDuration,3);
  assert.equal(settings.fadeDuration,0);
  assert.equal(settings.aspect,'16:9');
  assert.equal(settings.resolution,720);
  assert.equal(settings.fit,'contain');
  assert.equal(normalizeSettings({cardDuration:0}).cardDuration,0.5);
  assert.equal(fadeAtTime(1,3,0),1);
});

test('MIME negotiation selects only an explicitly supported codec', () => {
  const original=globalThis.MediaRecorder;
  try {
    globalThis.MediaRecorder={isTypeSupported:type=>type==='video/webm;codecs=vp8,opus'};
    assert.equal(supportedRecordingType(),'video/webm;codecs=vp8,opus');
    globalThis.MediaRecorder={isTypeSupported:type=>['video/mp4','video/webm;codecs=vp8,opus'].includes(type)};
    assert.equal(supportedRecordingType(),'video/webm;codecs=vp8,opus');
    globalThis.MediaRecorder={isTypeSupported:type=>['video/mp4;codecs=avc1.42E028,mp4a.40.2','video/webm;codecs=vp8,opus'].includes(type)};
    assert.equal(supportedRecordingType(),'video/mp4;codecs=avc1.42E028,mp4a.40.2');
    globalThis.MediaRecorder={isTypeSupported:()=>false};
    assert.equal(supportedRecordingType(),'');
  } finally {
    if(original===undefined)delete globalThis.MediaRecorder;
    else globalThis.MediaRecorder=original;
  }
});

test('production sequences have no forced cards and retain exact trim order', () => {
  const timeline=buildTimeline([{id:'detail',src:'detail.webm',duration:8,trimStart:1,trimEnd:4},{id:'wide',src:'wide.webm',duration:6,trimStart:2,trimEnd:6}],{cardMode:'none',cardDuration:3,fadeDuration:.4});
  assert.deepEqual(timeline.entries.map(e=>[e.type,e.start,e.end,e.segment.id]),[['answer',0,3,'detail'],['answer',3,7,'wide']]);
  assert.equal(timeline.totalDuration,7);
  assert.ok(timeline.segments.every(s=>s.showCard===false));
});

test('mixed sequences add cards only to chosen interview clips without changing source objects', () => {
  const clips=[{id:'interview',src:'answer.webm',duration:5,showCard:true},{id:'product',src:'product.webm',duration:4,showCard:false},{id:'outro',src:'outro.webm',duration:2,showCard:true}];
  const copy=JSON.stringify(clips),timeline=buildTimeline(clips,{cardMode:'perClip',cardDuration:2});
  assert.deepEqual(timeline.entries.map(e=>[e.type,e.start,e.end]),[['card',0,2],['answer',2,7],['answer',7,11],['card',11,13],['answer',13,15]]);
  assert.equal(timeline.totalDuration,15);assert.equal(JSON.stringify(clips),copy);
});

test('old interview edits preserve their cards and unknown card modes fall back safely', () => {
  const clips=[{src:'a',duration:2,showCard:false}];
  assert.equal(buildTimeline(clips).entries[0].type,'card');
  assert.equal(buildTimeline(clips,{cardMode:'unexpected'}).settings.cardMode,'all');
  assert.equal(buildTimeline(clips,{cardMode:'perClip'}).entries.length,1);
});
