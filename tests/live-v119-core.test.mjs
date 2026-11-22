import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {resolve,dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
import {normalizeLayout,layoutRects,compositionReady,chooseRundownSegment,rundownPreview,safeShowText} from '../assets/live-show-core.js';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..'),file=p=>readFileSync(resolve(root,p),'utf8');
const a='phone:a'.padEnd(38,'a'),b='phone:b'.padEnd(38,'b'),c='phone:c'.padEnd(38,'c'),d='phone:d'.padEnd(38,'d');
test('one camera fills entire program, invalid dimensions make no rectangles',()=>{
 assert.deepEqual(layoutRects('single',1280,720),[{x:0,y:0,w:1280,h:720,z:0}]);assert.deepEqual(layoutRects('grid',0,720),[]);
});
test('split layouts use two equal halves and picture in picture is inset',()=>{
 const split=layoutRects('split',1280,720);assert.equal(split.length,2);assert.deepEqual(split.map(x=>x.w),[640,640]);
 const pip=layoutRects('pip',1280,720);assert.equal(pip.length,2);assert.ok(pip[1].w<pip[0].w/2);assert.ok(pip[1].x+pip[1].w<=1280);
});
test('quad layout has four nonoverlapping equally-sized cells inside Program',()=>{
 const cells=layoutRects('grid',1280,720);assert.equal(cells.length,4);assert.equal(cells.reduce((sum,r)=>sum+r.w*r.h,0),1280*720);
 assert.ok(cells.every(x=>x.x>=0&&x.y>=0&&x.x+x.w<=1280&&x.y+x.h<=720));
});
test('normalizer restricts type and slots',()=>{
 assert.deepEqual(normalizeLayout('hijack',['x']),{type:'single',slots:['x']});
 assert.deepEqual(normalizeLayout('grid',[a,b]),{type:'grid',slots:[a,b,'','']});
});
test('composition cannot go on air without EVERY distinct live feed',()=>{
 assert.equal(compositionReady(normalizeLayout('split',[a,b]),[a,b,c]),true);
 assert.equal(compositionReady(normalizeLayout('split',[a,a]),[a,b]),false);
 assert.equal(compositionReady(normalizeLayout('grid',[a,b,c,'']),[a,b,c,d]),false);
 assert.equal(compositionReady(normalizeLayout('grid',[a,b,c,d]),[a,b,c,d]),true);
 assert.equal(compositionReady(normalizeLayout('pip',[a,b]),[a]),false);
});
test('rundown preview stays a selected plan rather than executing a Take',()=>{
 assert.deepEqual(rundownPreview({kind:'media',mediaId:'123'}),{kind:'media',mediaId:'123'});
 assert.deepEqual(rundownPreview({kind:'slate'}),{kind:'slate'});
 assert.deepEqual(rundownPreview({kind:'camera',layout:'split',slots:[a,b]}),{kind:'camera',sceneId:'',layout:normalizeLayout('split',[a,b])});
 assert.equal(rundownPreview({kind:'other'}),null);
});
test('rundown navigation clamps; labels sanitize control characters',()=>{
 assert.equal(chooseRundownSegment([1,2,3],0,-1),0);
 assert.equal(chooseRundownSegment([1,2,3],2,1),2);
 assert.equal(chooseRundownSegment([1,2,3],0,1),1);
 assert.equal(chooseRundownSegment([],0,1),-1);
 assert.equal(safeShowText('  Hello\u0000\n worlds  ',12),'Hello worlds');
});
test('Director UI has all production tabs with private cue/explicit take and main program canvas',()=>{
 const html=file('live-director.php'),js=file('assets/live-director.js');
 for(const id of ['live-layout-mode','live-layout-slots','live-layout-canvas','live-layout-preview','live-layout-take','live-rundown-list','live-rundown-save','live-rundown-next','live-media-select','live-media-preview','live-media-take','live-audience-messages'])assert.match(html,new RegExp(`id="${id}"`));
 for(const tab of ['compositions','rundown','media','audience'])assert.match(html,new RegExp(`data-live-tab="${tab}"`));
 assert.match(js,/function takeComposition\(layout\)/);assert.match(js,/!compositionReady\(requested,sourceIds\(\)\)/);
 assert.match(js,/function takeMedia\(id\)/);assert.match(js,/function paintComposition/);
 assert.match(js,/peer\.addTrack\(track,programStream\)/);
 assert.doesNotMatch(js,/peer\.addTrack\(track,item\.stream\)/);
});
test('private prerecorded media must originate from the Director\'s own gallery',()=>{
 const js=file('assets/live-director.js'),api=file('personal-media-file.php');
 assert.match(js,/mediaLibrary=\(result\.items\|\|\[\]\)\.filter/);
 assert.match(js,/new URL\('personal-media-api\.php'/);
 assert.match(api,/az_personal_media_row\(\$id,\(int\)\$user\['id'\]\)/);
 assert.match(js,/await candidate\.play\(\)/);
 assert.match(js,/audioCtx\.createMediaElementSource\(video\)/);
 assert.match(js,/mediaGain\.gain\.setTargetAtTime/);
 assert.match(js,/if\(epoch!==programTakeEpoch\)/);
 assert.match(js,/const oldVideo=programVideo,oldGain=mediaGain/);
 assert.match(js,/oldVideo\?\.pause\(\)/);
 assert.match(js,/live-media-stop'\)\.addEventListener\('click',\(\)=>\{programTakeEpoch\+\+/);

});
test('API requires role and CSRF for modifying saved rundowns or moderator actions',()=>{
 const api=file('live-api.php'),php=file('includes/live-rundowns.php'),fan=file('live.php'),viewer=file('assets/live-viewer.js');
 assert.match(api,/az_live_require_editor\(true\)/);assert.match(api,/az_require_csrf\(\)/);
 for(const act of ['saveRundown','deleteRundown','directorEngagement','enableChat','removeChat'])assert.match(api,new RegExp(`'${act}'`));
 assert.match(php,/az_personal_media_row\(\$mediaId,\$owner\)/);
 assert.match(php,/revision_conflict/);assert.match(php,/flock\(\$lock,LOCK_EX\)/);
 assert.match(fan,/fan-live-engagement/);assert.match(viewer,/textContent=msg\.text/);
});
