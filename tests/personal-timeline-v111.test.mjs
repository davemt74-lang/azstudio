import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {DEFAULT_SETTINGS,addMedia,changeClip,moveClip,totalSeconds,renderSegments} from '../assets/personal-timeline-core.js';
import {buildTimeline,fadeAtTime} from '../assets/media-engine.js';

const a={id:'a'.repeat(32),title:'Photo shoot opener',videoUrl:'personal-media-file.php?id='+ 'a'.repeat(32),durationMs:10500};
const b={id:'b'.repeat(32),title:'Candid reaction',videoUrl:'personal-media-file.php?id='+ 'b'.repeat(32),durationMs:15000};

test('models can add multiple private sources, reorder them, and retain original source IDs',()=>{
  const first=addMedia([],a,a.durationMs);
  const clips=addMedia(first,b,b.durationMs);
  assert.equal(clips.length,2);
  assert.equal(clips[0].mediaId,a.id);
  assert.equal(clips[1].mediaId,b.id);
  const arranged=moveClip(clips,clips[1].id,0);
  assert.deepEqual(arranged.map(c=>c.mediaId),[b.id,a.id]);
  assert.deepEqual(clips.map(c=>c.mediaId),[a.id,b.id]);
});

test('clip edits use independent trims, overlays, volume, and title-card flags',()=>{
  const clips=addMedia([],a,a.durationMs);
  const edited=changeClip(clips,clips[0].id,{
    trimStartMs:2250,trimEndMs:8000,overlayText:'Behind the scenes',overlayPosition:'top',volume:.4,titleCard:true,
  });
  assert.equal(clips[0].trimStartMs,0,'the original timeline object stays unchanged');
  assert.equal(edited[0].trimStartMs,2250);
  assert.equal(edited[0].trimEndMs,8000);
  assert.equal(totalSeconds(edited,{...DEFAULT_SETTINGS,cardDuration:2}),7.75);
  const source=renderSegments(edited,[a])[0];
  assert.deepEqual([source.trimStart,source.trimEnd,source.volume,source.showCard,source.overlayText],
    [2.25,8,.4,true,'Behind the scenes']);
});

test('timeline sources are strictly selected from the account-scoped library',()=>{
  const first=addMedia([],a,a.durationMs);
  assert.throws(()=>renderSegments(first,[b]),/no longer in your media library/);
  assert.throws(()=>changeClip(first,first[0].id,{trimStartMs:10000,trimEndMs:10001}),/at least 0.1 seconds/);
  assert.throws(()=>changeClip(first,first[0].id,{volume:3}),/between 0% and 100%/);
  assert.throws(()=>addMedia(new Array(48).fill(first[0]),b,b.durationMs),/up to 48 clips/);
});

test('renderer honors cards per clip, private overlays and audio levels',()=>{
  const clips=addMedia(addMedia([],a,a.durationMs),b,b.durationMs);
  const edited=changeClip(clips,clips[1].id,{titleCard:true,overlayText:'A little backstage magic',overlayPosition:'bottom',volume:0});
  const segments=renderSegments(edited,[a,b]);
  const render=buildTimeline(segments,{...DEFAULT_SETTINGS,cardMode:'perClip',cardDuration:2});
  assert.equal(render.entries.filter(e=>e.type==='card').length,1);
  assert.equal(render.entries.filter(e=>e.type==='answer').length,2);
  assert.equal(render.segments[1].volume,0);
  assert.equal(render.segments[1].overlayText,'A little backstage magic');
  assert.equal(render.totalDuration,27.5);
  assert.equal(render.width,1280);
  assert.equal(render.height,720);
});

test('transition fades remain bounded at clip boundaries',()=>{
  assert.equal(fadeAtTime(0,8,.5),0);
  assert.equal(fadeAtTime(.25,8,.5),.5);
  assert.equal(fadeAtTime(5,8,.5),1);
  assert.equal(fadeAtTime(8,8,.5),0);
});

test('personal editor saves private projects and imports only personal media',()=>{
  const api=readFileSync(new URL('../personal-media-api.php',import.meta.url),'utf8');
  const editor=readFileSync(new URL('../my-editor.php',import.meta.url),'utf8');
  assert.match(api,/az_require_auth\(true\)/);
  assert.match(api,/az_personal_media_role\(\$user\)/);
  assert.match(api,/projectSave/);
  assert.match(api,/projectDelete/);
  assert.match(editor,/pt-project-list/);
  assert.match(editor,/pt-timeline/);
  assert.match(editor,/pt-export/);
});
