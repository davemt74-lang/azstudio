import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {dirname,resolve} from 'node:path';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const read=path=>readFileSync(resolve(root,path),'utf8');
const page=read('live-director.php'),client=read('assets/live-director.js'),css=read('assets/live-studio.css'),admin=read('admin-studio.php'),layout=read('includes/member-layout.php');
const header=page.match(/<header class="azh-director-toolbar">([\s\S]*?)<\/header>/)?.[1]||'';

test('v1.22.1: admin studio has exactly one shared breadcrumb and one heading',()=>{
  assert.match(admin,/az_render_member_layout_open\('admin-studio',\$user\)/);
  assert.match(layout,/azh-canvas-topline/);
  assert.doesNotMatch(admin,/AZHOTTIE \/ CONTROL ROOM/);
  assert.equal((admin.match(/<h1\b/g)||[]).length,1);
  assert.match(admin,/<h1 id="admin-title">Admin Studio/);
  assert.match(admin,/azh-admin-status/);
  assert.match(admin,/azh-admin-grid/);
});

test('v1.22.1: Director title is in former search position, once, before broadcast controls',()=>{
  assert.ok(header.includes('id="live-title"'));
  assert.equal((header.match(/id="live-title"/g)||[]).length,1);
  assert.ok(header.indexOf('id="live-title"')>header.indexOf('azh-director-back'));
  assert.ok(header.indexOf('id="live-title"')<header.indexOf('id="live-onair-pill"'));
  assert.match(header,/aria-label="Live show and saved recording title"/);
  for(const id of ['live-title','live-onair-pill','live-view-count','live-go','live-end'])assert.match(header,new RegExp(`id="${id}"`));
  assert.doesNotMatch(header,/live-camera-search|azh-director-search|toolbar-spacer|azh-director-app-title|Member Live|Saved shows|Program title|Save title/);
  assert.match(header,/href="admin-studio\.php"/);
  assert.match(client,/liveRequest\('start',\{title:\$\('live-title'\)\.value\.trim\(\)\}\)/);
  assert.match(client,/const title=`\$\{\$\('live-title'\)\.value\.trim\(\)\|\|'AZHottie Live'\}/);
});

test('v1.22.1: Director displays all camera cards and never accesses a removed search control',()=>{
  assert.doesNotMatch(client,/live-camera-search|cameraQuery|No cameras match your search/);
  assert.match(client,/for\(const device of connectedPhones\)/);
  assert.match(client,/tile\.card\.hidden=false/);
  assert.match(client,/selected=id;syncCameraAudio\(\);showSources\(\)/);
  const sourceIds=client.match(/function sourceIds\(\)[^\n]*/)?.[0]||'';
  assert.match(sourceIds,/\.health\?\.playable\(\)/);
});

test('v1.22.1: responsive Director header keeps title field and actions',()=>{
  assert.doesNotMatch(css,/\.azh-director-search|\.azh-director-toolbar-spacer/);
  assert.match(css,/\.azh-director-toolbar \.azh-director-title-field/);
  assert.match(css,/@media\(max-width:850px\)/);
  assert.match(css,/@media\(max-width:530px\)/);
});
