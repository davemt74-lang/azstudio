import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const member=readFileSync(new URL('../includes/member-layout.php',import.meta.url),'utf8');
const css=readFileSync(new URL('../assets/agent-chat.css',import.meta.url),'utf8');
const js=readFileSync(new URL('../assets/agent-chat.js',import.meta.url),'utf8');

test('shared model and admin chat dock has only the accessible composer and send button',()=>{
  assert.doesNotMatch(member,/Ask AZHottie Agent|Private member chat|AI-generated replies may be inaccurate|Open chat/);
  assert.doesNotMatch(member,/azh-agent-input-label|azh-agent-dock-meta/);
  assert.match(member,/id="azh-agent-compose" class="azh-agent-compose" aria-label="AZHottie message composer"/);
  assert.match(member,/id="azh-agent-input"[^>]*aria-label="Message AZHottie Agent"/);
  assert.match(member,/type="submit" id="azh-agent-send" aria-label="Send message"/);
  assert.match(member,/assets\/agent-chat\.css\?v=1\.29\.2/);
  assert.match(member,/assets\/agent-chat\.js\?v=1\.29\.2/);
});

test('clean styling and usable error reporting when temporary storage is blocked',()=>{
  assert.doesNotMatch(css,/\.azh-agent-input-label|\.azh-agent-dock-meta/);
  assert.match(css,/\.azh-agent-compose-error\{/);
  assert.doesNotMatch(js,/querySelector\('\.azh-agent-dock-meta/);
  assert.match(js,/sessionStorage\.setItem\(STORE_KEY/);
  assert.match(js,/location\.assign\('agent-chat\.php'\)/);
  assert.match(js,/error\.setAttribute\('role','alert'\)/);
  assert.match(js,/compose\.append\(error\)/);
});
