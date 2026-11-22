import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {fanVideoState,directorObservation} from '../assets/live-agent-health.js';
import {safeAgentHref} from '../assets/agent-workspace.js';
const source=(path)=>readFileSync(new URL('../'+path,import.meta.url),'utf8');

test('fan monitor differentiates real frozen frames from background throttling, startup and a play gesture',()=>{
  const base={connected:true,visible:true,hasVideo:true,canMeasure:true,secondsSinceFrame:9,paused:false};
  assert.equal(fanVideoState(base),'stalled');
  assert.equal(fanVideoState({...base,secondsSinceFrame:3}),'playing');
  assert.equal(fanVideoState({...base,visible:false}),'connecting');
  assert.equal(fanVideoState({...base,canMeasure:false}),'connecting');
  assert.equal(fanVideoState({...base,hasVideo:false}),'connecting');
  assert.equal(fanVideoState({...base,connected:false}),'connecting');
  assert.equal(fanVideoState({...base,paused:true}),'connecting');
  assert.equal(fanVideoState({...base,paused:true,playRejected:true}),'blocked');
});
test('director metrics clamp and validate all client measurements',()=>{
  const m=directorObservation({cameraCount:300,healthyCameras:-1,viewerCount:900,sentFps:Infinity,stalledOutbound:9,directRelays:10,programMode:'<script>',pageHidden:true});
  assert.equal(m.cameraCount,20);assert.equal(m.healthyCameras,0);assert.equal(m.viewerCount,6);
  assert.equal(m.sentFps,null);assert.equal(m.stalledOutbound,6);assert.equal(m.directRelays,6);
  assert.equal(m.programMode,'slate');assert.equal(m.pageHidden,true);
});
test('authorized agent deep links include live Director but reject redirects',()=>{
  assert.equal(safeAgentHref('live-director.php'),'live-director.php');
  for(const href of ['https://bad.site/live-director.php','live-director.php#out','live-director.php?next=//bad.site'])assert.equal(safeAgentHref(href),null);
  assert.match(source('includes/agent-workspace.php'),/live-director\)\\.php/);
});
test('live reporter validates session ownership and does not accept viewer camera metrics',()=>{
  const api=source('live-api.php'),php=source('includes/live-agent.php');
  assert.match(api,/action==='healthReport'/);
  assert.match(api,/az_require_csrf\(\)/);
  assert.match(php,/az_live_host_room|owner_id.*actorId/);
  assert.match(php,/az_live_viewer_row\(\$actorId,\$connectionId\)/);
  assert.match(php,/\$viewer\['room_id'\]!==\$roomId/);
  assert.match(php,/\$viewer\['status'\]!=='connected'/);
  assert.match(php,/foreach \(\['camera_count','camera_healthy'/);
  assert.doesNotMatch(php,/REMOTE_ADDR|HTTP_USER_AGENT|offer_sdp\s+\$/);
});
test('live health is measured in both Director and fan browser without disrupting playback',()=>{
  const director=source('assets/live-director.js'),fan=source('assets/live-viewer.js');
  assert.match(director,/liveRequest\('healthReport'/);
  assert.match(director,/latestDelivery=\{viewerCount:liveCount,sentFps:/);
  assert.match(director,/setInterval\(\(\)=>void reportLiveHealth\(\),12000\)/);
  assert.match(director,/window\.confirm\('Send the selected phone directly/);
  assert.match(director,/window\.confirm\('Return all fan viewers/);
  assert.match(fan,/request\('healthReport'/);
  assert.match(fan,/fanVideoState\(/);
  assert.match(fan,/catch\{\/\* Diagnostics must never interrupt fan playback/);
});
test('UI shows real health state and missing telemetry as unverified',()=>{
  const brain=source('assets/agent-workspace.js'),server=source('includes/live-agent.php');
  assert.match(brain,/LIVE DIRECTOR SPECIALIST/);
  assert.match(brain,/Unverified · waiting for fresh Director telemetry/);
  assert.match(brain,/live\.issues/);
  assert.match(brain,/Open Live Director/);
  assert.match(server,/'state'=>\$issues\?'attention':\(!\$director\?'unverified'/);
  assert.match(server,/NEVER operates a camera, changes Program/);
});
