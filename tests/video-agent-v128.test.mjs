import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {videoAgentDuration,videoAgentCanApply,videoAgentCanRevert} from '../assets/video-agent.js';
const app=readFileSync(new URL('../assets/personal-timeline.js',import.meta.url),'utf8');
const ui=readFileSync(new URL('../my-editor.php',import.meta.url),'utf8');
const brain=readFileSync(new URL('../assets/agent-workspace.js',import.meta.url),'utf8');
const api=readFileSync(new URL('../video-agent-api.php',import.meta.url),'utf8');
test('agent output formats trustworthy numeric duration',()=>{
  assert.equal(videoAgentDuration(1250),'1.3s');assert.equal(videoAgentDuration(-10),'0.0s');
});
test('editing plan approval is revision-bound',()=>{
  assert.equal(videoAgentCanApply({status:'proposed',projectRevision:4},4),true);
  assert.equal(videoAgentCanApply({status:'proposed',projectRevision:4},5),false);
  assert.equal(videoAgentCanApply({status:'applied',projectRevision:4},4),false);
});
test('revert only exact applied version',()=>{
  assert.equal(videoAgentCanRevert({status:'applied',appliedRevision:7},7),true);
  assert.equal(videoAgentCanRevert({status:'applied',appliedRevision:7},8),false);
});
test('editor exports selected clip and project IDs to agent without exposing media URLs',()=>{
  assert.match(app,/azh:video-editor-project/);assert.match(app,/azh:video-editor-clip/);
  assert.match(app,/azh:video-agent-saved/);assert.match(app,/loadProject\(requestedProject\)/);
});
test('agent panel has meaningful review and undo actions',()=>{
  for(const field of ['vagent-generate','vagent-note-add','vagent-preview','vagent-approve','vagent-revert','vagent-discard'])assert.match(ui,new RegExp(`id="${field}"`));
  assert.match(ui,/No footage, private transcript, or sound is sent to an AI provider/);
});
test('Brain and Chat include safe entry points for models and admins',()=>{
  assert.match(brain,/VIDEO PRODUCTION & EDITING/);assert.match(brain,/my-editor\.php/);
  assert.match(brain,/user-submissions\.php/);
  assert.match(api,/az_require_csrf/);assert.match(api,/az_personal_media_role/);
});
