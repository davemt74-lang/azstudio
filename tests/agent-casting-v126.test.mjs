import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {safeAgentHref,validWorkAction} from '../assets/agent-workspace.js';
import {castingChatPrompt,safeCastingAction,castingStatusCounts} from '../assets/agent-casting-chat.js';
const read=p=>readFileSync(new URL('../'+p,import.meta.url),'utf8');
const src=read('includes/agent-casting.php');

test('casting deep links stay on approved same-origin workspaces',()=>{
  for(const good of ['casting.php','casting.php?tab=onboarding','casting.php?lead='+'a'.repeat(32),'account.php','agent-chat.php?topic=casting'])assert.equal(safeAgentHref(good),good);
  for(const bad of ['https://example.org','casting.php?next=https://evil.com','casting.php#evil','../../casting.php','casting.php?lead=x<script>'])assert.equal(safeAgentHref(bad),null);
});
test('casting stage actions are explicit, revisioned, and restricted to supported safe stages',()=>{
  const action={type:'casting_stage',id:'a'.repeat(32),revision:4,next:'contacted'};
  assert.ok(validWorkAction(action));assert.ok(safeCastingAction(action));
  for(const stage of ['approved','converted','declined','invited'])assert.equal(safeCastingAction({...action,next:stage}),false);
  assert.equal(safeCastingAction({...action,revision:0}),false);
  assert.equal(safeCastingAction({...action,id:'invalid'}),false);
  assert.match(src,/\$allowed=\['new'=>'contacted','contacted'=>'screening','screening'=>'interview'\]/);
  assert.match(src,/WHERE id=\? AND status=\? AND revision=\?/);
  assert.match(read('agent-workspace-api.php'),/az_require_csrf\(\)/);
  assert.match(read('assets/agent-workspace.js'),/window\.confirm\('Confirm moving this casting application/);
  assert.match(read('assets/agent-casting-chat.js'),/window\.confirm/);
});
test('Brain and Chat receive casting context without raw application answers, contacts, or notes',()=>{
  const chat=read('includes/agent-chat.php');
  assert.match(chat,/az_agent_casting_chat_snapshot/);
  assert.match(src,/function az_agent_casting_chat_snapshot/);
  assert.match(src,/counts.*\$brief\['counts'\]/s);
  assert.doesNotMatch(src.slice(src.indexOf('function az_agent_casting_chat_snapshot'),src.indexOf('function az_agent_casting_scan_status')),/answers_json|admin_notes|email|phone|social_handles/);
  assert.match(chat,/no action tools/);
  assert.match(read('agent-chat.php'),/id="azh-casting-chat-panel"/);
  assert.match(read('assets/agent-casting-chat.js'),/data-casting-body/);
});
test('Chat helper uses safe static prompts and ignores invalid metrics',()=>{
  assert.match(castingChatPrompt('admin'),/pipeline/);assert.match(castingChatPrompt('model'),/onboarding/);
  const c=castingStatusCounts({counts:{newApplications:3,converted:2,onboardingPending:0,bad:-1,injection:'<script>'}});
  assert.deepEqual(c.map(x=>x.value),[3,2,0]);
  assert.deepEqual(c.map(x=>x.label),['New applications','Converted models','Onboarding incomplete']);
});
test('the database is additive, idempotent and includes scoped dedupe and controlled onboarding',()=>{
  assert.match(src,/CREATE TABLE IF NOT EXISTS agent_casting_deliveries/);
  assert.match(src,/UNIQUE \(lead_id,recipient_id,rule,fingerprint\)/);
  assert.match(src,/CREATE TABLE IF NOT EXISTS model_onboarding_checkpoints/);
  assert.match(src,/PRIMARY KEY \(model_user_id,checkpoint\)/);
  assert.match(src,/version=32/);
  assert.match(read('includes/database.php'),/az_upgrade_agent_casting_schema/);
  assert.match(src,/JOIN user_roles r ON r\.user_id=u\.id AND r\.role='model'/);
  assert.match(src,/if\(!\$check\|\|!\$check\['ready'\]\)continue;/); // No incomplete onboarding in assignment match suggestions.
  assert.match(src,/if\(!az_has_role\(\$admin,'admin'\)\)/);
});
test('scheduled follow-through is included in existing private cron but cannot auto-approve or assign',()=>{
  const cron=read('includes/agent-followthrough-cron.php');
  assert.match(cron,/PHP_SAPI !== 'cli'/);
  assert.match(cron,/az_agent_casting_scan\(\)/);
  assert.match(src,/INSERT INTO agent_casting_deliveries/);
  assert.match(src,/az_agent_casting_fingerprint/);
  assert.doesNotMatch(src,/UPDATE shot_assignments SET|UPDATE model_videos SET/);
});
