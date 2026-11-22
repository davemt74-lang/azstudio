import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const read = path => readFileSync(new URL('../' + path, import.meta.url), 'utf8');
const camera = read('my-camera.php');
const phone = read('assets/personal-camera.js');
const phoneStyle = read('assets/personal-phone.css');
const schedule = read('assets/schedule.css');
const member = read('includes/member-layout.php');
const chat = read('agent-chat.php');
const chatApi = read('agent-chat-api.php');
const chatPhp = read('includes/agent-chat.php');
const media = read('my-media.php');
const editorStyle = read('assets/personal-timeline.css');

test('camera logo links back to AZHottie for guests and signed-in models',()=>{
  assert.match(camera,/<a class="azh-phone-brand" href="<\?= \$user \? 'dashboard.php' : 'index.php' \?>"/);
  assert.doesNotMatch(camera,/azh-phone-foot/);
});
test('camera live control has one accessible toggle in bottom toolbar',()=>{
  assert.match(camera,/<div class="azh-phone-action-row">[\s\S]+?id="phone-live"[\s\S]+?<\/div>/);
  assert.doesNotMatch(camera,/id="phone-live-stop"|id="phone-live-section"/);
  assert.doesNotMatch(phone,/phone-live-stop|phone-live-section/);
  assert.match(phone,/aria-pressed','false'/);
  assert.match(phone,/aria-pressed','true'/);
  assert.match(phone,/autoSharedByRecording&&liveWanted\)stopLive\(\)/); // Stop even if the phone is between reconnect attempts.
});
test('camera clip trim is real video export, preserves original and resets upload identity',()=>{
  for (const control of ['phone-edit','phone-trim-in','phone-trim-out','phone-trim-preview','phone-trim-save','phone-trim-cancel','phone-trim-canvas'])
    assert.match(camera,new RegExp(`id="${control}"`));
  assert.match(phone,/await renderer\.export\(/);
  assert.match(phone,/cardMode:'none'/);
  assert.match(phone,/captured=file;uploadFile=file;uploadToken=null/);
  assert.match(phone,/getVideoMetadata\(savedClip\(\)\)/);
  assert.match(phone,/Your original recording is unchanged/);
});
test('phone full-viewport controls and bounded upload drawer are available',()=>{
  assert.match(phoneStyle, /\.azh-phone-toolbar\{margin-top:auto/);
  assert.match(phoneStyle, /\.azh-phone-upload-drawer\{bottom:calc\(100px/);
  assert.match(phoneStyle, /\.azh-phone-trim-grid/);
});
test('calendar uses light-gray grid, white form surfaces, readable events and booking slots',()=>{
  const pairs = [
    /\.schedule-workspace \.calendar-grid\{background:#f2f1f4!important/,
    /\.schedule-workspace \.calendar-weekday\{background:#e9e7ec!important/,
    /\.schedule-workspace \.calendar-cell\{background:#fff!important/,
    /\.schedule-workspace \.calendar-cell\.is-outside\{background:#f0eff3!important/,
    /\.schedule-workspace \.calendar-event\.is-class\{background:#eef3ff!important/,
    /\.schedule-workspace \.slot-choice span\{background:#fff!important/,
    /\.schedule-workspace :is\(input,select,textarea\)\{background:#fff!important/,
    /\.schedule-workspace :is\(\.schedule-metric,\.schedule-card,\.booking-aside\)\{background:#fff!important/
  ];
  for (const rule of pairs) assert.match(schedule,rule);
  assert.match(schedule,/color-scheme:light/);
});
test('member chat stays private with owner-scoped queries and no action tools',()=>{
  assert.match(member,/agent-chat\.php/);
  assert.match(member,/id="azh-agent-dock" data-agent-dock/);
  assert.match(chat, /class="azh-chat-canvas workspace"/);
  assert.match(chatApi, /az_require_auth\(true\)/);
  assert.match(chatApi, /az_require_csrf\(\)/);
  assert.match(chatPhp, /WHERE user_id=\?/);
  assert.match(chatPhp, /az_agent_owned_thread/);
  assert.match(chatPhp, /You have no action tools/);
  assert.match(chatPhp, /CREATE TABLE IF NOT EXISTS agent_chat_messages/);
});
test('gallery hero has no duplicate navigation shortcut row',()=>{
  assert.doesNotMatch(media,/pm-hero-links|pm-shortcuts/);
  assert.match(media,/class="pm-upload"/);
});
test('video editor locks viewport and only panes scroll with custom themed scrollbar',()=>{
  assert.match(editorStyle,/body\.editor-workspace\{height:100dvh;max-height:100dvh;overflow:hidden/);
  assert.match(editorStyle,/\.vs-timeline-scroll\{flex:1;min-height:0;overflow:auto/);
  assert.match(editorStyle,/scrollbar-color:#bd4d82 #20293a/);
});
