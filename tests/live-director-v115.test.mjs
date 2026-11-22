import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {dirname,resolve} from 'node:path';
import {nextProgramId,switchDelay,fitCover,fanSessionState} from '../assets/live-core.js';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const file=(path)=>readFileSync(resolve(root,path),'utf8');

test('manual switching walks the ready list and wraps',()=>{
  assert.equal(nextProgramId(['a','b','c'],'a'),'b');
  assert.equal(nextProgramId(['a','b','c'],'c'),'a');
  assert.equal(nextProgramId(['a','a'],'a'),'a');
  assert.equal(nextProgramId([],null),null);
});
test('random switching never repeats the current source when other feeds are ready',()=>{
  assert.equal(nextProgramId(['a','b','c'],'a',{random:true,randomNumber:0}),'b');
  assert.equal(nextProgramId(['a','b','c'],'a',{random:true,randomNumber:.999}),'c');
  assert.equal(nextProgramId(['a','b','c'],'x',{random:true,randomNumber:.45}),'b');
});
test('fixed and randomized camera switching respect the stated timing',()=>{
  assert.equal(switchDelay('manual'),null);assert.equal(switchDelay('fixed'),30000);
  assert.equal(switchDelay('random',{randomNumber:0}),15000);
  assert.equal(switchDelay('random',{randomNumber:0.5}),30000);
  assert.equal(switchDelay('random',{randomNumber:.999999}),45000);
});
test('camera fit crops wide and portrait footage into one program canvas',()=>{
  const wide=fitCover(1920,1080,1280,720);assert.deepEqual(wide,{x:0,y:0,width:1280,height:720});
  const portrait=fitCover(720,1280,1280,720);assert.ok(portrait.y<0);assert.equal(portrait.width,1280);assert.equal(fitCover(0,100,1280,720),null);
});
test('room identity must be concrete and heartbeat fresh',()=>{
  const id='a'.repeat(32);assert.equal(fanSessionState({room:{id},hostAge:24}),true);
  assert.equal(fanSessionState({room:{id},hostAge:28}),false);
  assert.equal(fanSessionState({room:{id:'xxx'},hostAge:0}),false);
});
test('main member sidebar has only the Admin Studio entry rather than every admin link',()=>{
  const shell=file('includes/member-layout.php');
  assert.match(shell,/if \(\$adminPage\)/);assert.match(shell,/STUDIO ACCESS/);
  assert.match(shell,/\['admin-studio.php','Admin Studio'/);
  assert.doesNotMatch(shell,/if \(\$admin\): \?><p class="azh-side-label">ADMIN STUDIO/);
  assert.match(shell,/\['live.php', 'Live AZHottie'/);
});
test('live page and Director are different protected destinations',()=>{
  const api=file('live-api.php'),host=file('live-director.php'),fan=file('live.php');
  assert.match(api,/az_require_auth\(true\)/);assert.match(api,/az_live_require_editor\(true\)/);
  assert.match(host,/az_live_require_editor\(\)/);assert.match(fan,/az_require_auth\(\)/);
  assert.doesNotMatch(fan,/live-program-canvas|live-phone-devices|live-invite-url/);
});
test('the fan broadcast uses only the canvas Program media stream',()=>{
  const host=file('assets/live-director.js');
  assert.match(host,/stage\.captureStream\(24\)/);assert.match(host,/new MediaStream\(\[\.\.\.canvasStream/);
  assert.match(host,/new HostPhoneFeeds/);assert.match(host,/peer\.addTrack\(track,programStream\)/);
  assert.match(host,/requestAnimationFrame\(draw\)/);
});
test('recording saves the compiled Program to the private gallery and links the live room',()=>{
  const host=file('assets/live-director.js');
  assert.match(host,/new MediaRecorder\(stream/);assert.match(host,/uploadToPersonalGallery\(mediaRequest/);
  assert.match(host,/liveRequest\('attach'/);assert.match(host,/recordToken/);assert.match(host,/beforeunload/);
});
test('server has a hard peer-to-peer viewer cap and prevents unauthorized takeover',()=>{
  const api=file('includes/live.php');
  assert.match(api,/AZ_LIVE_MAX_VIEWERS = 6/);assert.match(api,/live_busy/);
  assert.match(api,/az_live_host_room\(\$owner/);assert.match(api,/user_id=\?/);
  assert.match(api,/viewer_limit/);assert.match(api,/status='left'/);
});
test('the editor permission is separate from full administrator privileges',()=>{
  assert.match(file('includes/accounts.php'),/const AZ_ACCOUNT_ROLES = \['admin', 'editor'/);
  assert.match(file('includes/live.php'),/az_require_roles\(\['admin','editor'\]/);
  assert.match(file('includes/accounts.php'),/return az_require_roles\(\['admin'\]/);
});
