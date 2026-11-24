import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const read=x=>readFileSync(new URL('../'+x,import.meta.url),'utf8');
test('Talent Hub remains admin-only, CSRF-protected, and explicit staff action',()=>{
 const php=read('casting.php');assert.match(php,/az_require_admin\(\)/);assert.match(php,/az_require_csrf\(\)/);
 assert.match(php,/az_community_lock\(\)/);assert.match(php,/az_talent_create_followup/);assert.match(php,/az_talent_decide_followup/);
 assert.match(php,/id="casting-followup-title"/);assert.match(php,/Talent Pipeline/);
});
test('Followups have explicit state, due dates, revision protection and private audit',()=>{
 const s=read('includes/talent-pipeline.php');assert.match(s,/function az_upgrade_talent_pipeline_schema/);
 assert.match(s,/az_schema_version\(\$db\)>=50/);assert.match(s,/UPDATE casting_followups SET state=\?,resolution_note=\?,revision=revision\+1/);
 assert.match(s,/casting_followup_history/);assert.doesNotMatch(s,/curl_exec|mail\(|autoApprove|automatic_assignment/);
});
test('Talents reuse role-scoped creator analytics and onboarding rather than new competing systems',()=>{
 const s=read('casting.php');assert.match(s,/creator-analytics.php\?model=/);assert.match(s,/casting.php\?tab=onboarding/);assert.match(s,/productions.php/);
 const service=read('includes/talent-pipeline.php');assert.match(service,/az_agent_casting_onboarding/);assert.match(service,/published_video_engagement_daily/);
});
test('Updated migrations run on installed first boot and app version safely busts live scripts',()=>{
 const db=read('includes/database.php');const first=db.slice(db.indexOf('function az_db()'),db.indexOf('function az_create_schema('));
 assert.match(first,/az_upgrade_talent_pipeline_schema\(\$GLOBALS\['az_database'\]\)/);
 assert.match(db,/az_upgrade_talent_pipeline_schema\(\$db\)/);
 const version=JSON.parse(read('package.json')).version;assert.equal(version,'1.54.0');
 assert.ok(read('live.php').includes('live-viewer.js?v='+version));
 assert.ok(read('live-director.php').includes('live-director.js?v='+version));
});
