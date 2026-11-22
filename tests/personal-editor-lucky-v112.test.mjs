import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {DEFAULT_SETTINGS,addMedia,changeClip,splitClip,highlightProposal,renderSegments,totalSeconds} from '../assets/personal-timeline-core.js';
import {buildTimeline,VIDEO_FILTERS,normalizeSettings} from '../assets/media-engine.js';
const asset=new URL('../my-editor.php',import.meta.url);const html=readFileSync(asset,'utf8');
const js=readFileSync(new URL('../assets/personal-timeline.js',import.meta.url),'utf8');
const php=readFileSync(new URL('../includes/personal-edits.php',import.meta.url),'utf8');
const media={id:'b'.repeat(32),title:'Night studio footage',durationMs:18000,videoUrl:'personal-media-file.php?id='+'b'.repeat(32)};

test('editor matches Lucky split-workspace semantics and private media controls',()=>{
 for(const text of ['Media bin','Edit assistant','Filters','Text overlays','Templates','Timed titles','Background audio','Clip inspector','Render &amp; Export','T1 · Text overlays','V1 · Picture / clips','A1 · Source audio','B1 · Background audio'])assert.ok(html.includes(text),text);
 for(const id of ['pt-media','pt-phone-panel','pt-phone-qr','pt-phone-devices','pt-phone-live-feeds','pt-add-upload','pt-project-list','pt-save','pt-play','vs-preview-movie','vs-undo','vs-redo','vs-split','pt-export','pt-clip-video','vs-title-add','vs-background-apply'])assert.ok(html.includes(`id="${id}"`),id);
 assert.match(html,/az_require_roles\(\['model','professional','admin'\]\)/);
 assert.match(js,/api\('projectSave'/);assert.match(js,/api\('list'/);
});

test('split produces independent trimmed segments without changing original',()=>{
 const original=addMedia([],media,18000);const halves=splitClip(original,original[0].id,6500);
 assert.equal(original.length,1);assert.equal(original[0].trimEndMs,18000);
 assert.equal(halves.length,2);assert.equal(halves[0].trimEndMs,6500);assert.equal(halves[1].trimStartMs,6500);
 assert.notEqual(halves[0].id,halves[1].id);assert.equal(halves[1].mediaId,media.id);
 assert.throws(()=>splitClip(original,original[0].id,50),/0.1 seconds/);
});

test('clip filters and hard cuts reach real renderer',()=>{
 const original=addMedia([],media,18000);const edited=changeClip(original,original[0].id,{filter:'cinematic',transition:'cut',overlayText:'Production day'});
 assert.equal(renderSegments(edited,[media])[0].filter,'cinematic');
 const timeline=buildTimeline(renderSegments(edited,[media]),{...DEFAULT_SETTINGS,cardMode:'perClip'});
 assert.equal(timeline.segments[0].fadeDuration,0);
 assert.equal(timeline.segments[0].filter,'cinematic');
 assert.match(VIDEO_FILTERS.cinematic,/contrast/);
 assert.throws(()=>changeClip(original,original[0].id,{filter:'url(evil)'}),/Unknown video filter/);
 assert.throws(()=>changeClip(original,original[0].id,{transition:'wipe'}),/Unknown clip transition/);
});

test('timed T1 and private video-sourced B1 are normalized without URL persistence',()=>{
 const settings=normalizeSettings({...DEFAULT_SETTINGS,timedTitles:[{text:'Welcome',startMs:1200,durationMs:2500,position:'top'}],background:{url:media.videoUrl,startMs:0,trimStartMs:500,volume:.2}});
 assert.equal(settings.timedTitles[0].text,'Welcome');assert.equal(settings.timedTitles[0].position,'top');assert.equal(settings.background.volume,.2);
 assert.match(php,/az_personal_media_row\(\$media,\$owner\)/);
 assert.match(php,/az_personal_media_row\(\$media,\$owner\); \/\/ Never save a source from another account/);
 assert.match(php,/\$settings\['timedTitles'\]/);
 assert.match(php,/\$settings\['background'\]/);
});

test('local length suggestion is nondestructive and never claims to analyze video',()=>{
 const first=addMedia([],media,18000);const second=addMedia(first,media,18000);
 const proposal=highlightProposal(second,15);
 assert.equal(proposal.length,2);assert.ok(totalSeconds(proposal,{...DEFAULT_SETTINGS,cardDuration:0})<=15.01);
 assert.equal(second[0].trimEndMs,18000);assert.match(html,/does not inspect the video/);
});
