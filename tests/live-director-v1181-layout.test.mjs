import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const file=p=>readFileSync(resolve(root,p),'utf8');
const html=file('live-director.php');
const css=file('assets/live-studio.css');
const director=file('assets/live-director.js');
const camera=file('assets/personal-camera.js');
const cameraPage=file('my-camera.php');

test('live director has ONE application toolbar, not a second global header or sidebar',()=>{
 assert.equal((html.match(/<header class="azh-director-toolbar">/g)||[]).length,1);
 assert.doesNotMatch(html,/az_render_universal_header\s*\(|id="azh-global-header"|azh-member-sidebar|azh-director-saved/);
 assert.match(html,/class="azh-director azh-director-app"/);
});
test('director uses full-bleed fixed viewport with no exterior padding or page scroll',()=>{
 assert.match(css,/body\.azh-director-body \.azh-director-app\{[\s\S]*?padding:0!important;gap:0!important/);
 assert.match(css,/body\.azh-director-body\{width:100%;margin:0;overflow:hidden\}/);
 assert.match(css,/\.azh-director-app \.azh-director-workspace\{[\s\S]*?padding:0!important/);
 assert.match(css,/\.azh-director-app \.azh-director-program\{[\s\S]*?padding:0!important/);
});
test('program video is above tabbed controls and only active section is displayed',()=>{
 assert.ok(html.indexOf('class="azh-director-monitor"')<html.indexOf('class="azh-director-tabs"'));
 for(const name of ['switching','audio','overlays','scenes','recording']){
  assert.match(html,new RegExp(`data-live-tab="${name}"`));
  assert.match(html,new RegExp(`id="live-panel-${name}"`));
 }
 assert.match(director,/function setDirectorTab\(name,focus=false\)/);
 assert.match(director,/if\(panel\)panel\.hidden=!active/);
 assert.match(css,/\.azh-director-tabpanel\[hidden\]\{display:none!important\}/);
});
test('phone column shows individual moving previews or inactive slates, with Broadcast now actions',()=>{
 assert.match(html,/class="azh-phone-camera-grid"/);
 assert.doesNotMatch(html,/live-local-cameras|live-scan-cameras|live-preview-video/);
 assert.match(director,/function createSourceCard\(item\)/);
 assert.match(director,/function createIdlePhoneCard\(device\)/);
 assert.match(director,/wrap\.append\(item\.video,inactive,indicator,resume\)/);
 assert.match(director,/tile\.inactive\.hidden=ready;tile\.indicator\.hidden=!ready/);
 assert.match(director,/Broadcast now/);
 assert.match(director,/tile\.take\.disabled=!ready\|\|\(!mediaProgramActive&&activeLayout\.type==='single'&&selected===id\)/);
 assert.match(director,/const list=\$\('live-sources'\)/);
});
test('phone preview is not automatically broadcast and Program is the only output media',()=>{
 assert.match(director,/Never expose a newly/);
 assert.match(director,/selected=id;syncCameraAudio\(\);showSources\(\)/);
 assert.match(director,/stage\.captureStream\(24\)/);
 assert.match(director,/peer\.addTrack\(track,programStream\)/);
 assert.doesNotMatch(director,/peer\.addTrack\(track,item\.stream\)/);
});
test('Director-invited phone has visible camera preview-on-record choice, never auto-shares microphone',()=>{
 assert.match(cameraPage,/id="phone-record-share-choice"/);
 assert.match(cameraPage,/id="phone-share-on-record"/);
 assert.match(camera,/mode==='paired'&&device\?\.liveProduction&&\$\('phone-share-on-record'\)\?\.checked/);
 assert.match(camera,/for\(const track of stream\.getVideoTracks\(\)\)peer\.addTrack/);
 assert.match(camera,/if\(\$\('phone-share-audio'\)\?\.checked\)/);
});
test('shared masthead is wider, member logo aligns with the sidebar gutter',()=>{
 const css=file('assets/universal-header.css');
 assert.match(css,/width:min\(100%,1640px\)/);
 assert.match(css,/body:has\(\.azh-member-layout\) #azh-global-header \.azh-global-header__inner\{[\s\S]*?padding-left:24px/);
 assert.match(file('includes/app-shell.php'),/assets\/universal-header\.css\?v=1\.18\.1/);
});
test('no permanent status ribbon at bottom of the screen',()=>{
 assert.match(html,/id="live-status"[^>]*hidden/);
 assert.match(css,/\.azh-director-toast\[hidden\]\{display:none!important\}/);
 assert.doesNotMatch(html,/Select an approved camera to take it to Program/);
});
