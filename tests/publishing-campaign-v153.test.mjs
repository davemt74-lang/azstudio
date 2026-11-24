import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const read=p=>readFileSync(new URL('../'+p,import.meta.url),'utf8');
test('v1.53 campaign API uses existing admin guard and CSRF before mutation',()=>{
 const s=read('publishing-campaign-api.php');
 assert.match(s,/az_require_admin\(true\)/);assert.match(s,/az_require_csrf\(\)/);
 assert.match(s,/az_community_lock\(\)/);assert.doesNotMatch(s,/externalSend|curl_exec|mail\(/);
});
test('campaign shortlinks only resolve to local Watch and source authorization on each open',()=>{
 const s=read('includes/publishing-campaigns.php');
 assert.match(s,/function az_campaign_resolve_link/);assert.match(s,/az_campaign_source_usable\(\$row\)/);
 assert.match(s,/return 'watch\.php\?id='\.\$row\['video_id'\]/);
 assert.match(s,/session_status\(\)===PHP_SESSION_ACTIVE/);
 assert.doesNotMatch(s,/visitor_ip|user_agent|external_redirect/);
});
test('campaign UI creates only manual drafts and share-card art with local approved cover',()=>{
 const s=read('assets/publishing-campaigns.js');
 assert.match(s,/source\.value|pg-campaign-source'\)\.value/);
 assert.match(s,/new URL\(row\.coverUrl,document\.baseURI\)/);
 assert.match(s,/url\.origin!==location\.origin/);
 assert.match(s,/canvas\.width=1080;canvas\.height=1920/);
 assert.doesNotMatch(s,/fetch\(['"]https:\/\//);
});
test('other features stay installed and v1.53 assets are versioned',()=>{
 assert.match(read('publishing.php'),/id="pg-campaign-workspace"/);
 assert.match(read('includes/bootstrap.php'),/require_once __DIR__ \. '\/publishing-campaigns\.php'/);
 const version=JSON.parse(read('package.json')).version;
 assert.match(version,/^1\.(?:5[3-9]|[6-9][0-9])\.\d+$/);
 assert.ok(read('live.php').includes('live-viewer.js?v='+version));
 assert.ok(read('live-director.php').includes('live-director.js?v='+version));
});

test('installed-site first database boot migrates in order before returning connection',()=>{
 const db=read('includes/database.php');const first=db.slice(db.indexOf('function az_db()'),db.indexOf('function az_create_schema('));
 const creator=first.indexOf('az_upgrade_creator_analytics_schema(');const campaign=first.indexOf('az_upgrade_publishing_campaign_schema(');const done=first.lastIndexOf('return $GLOBALS');
 assert.ok(creator>0 && campaign>creator && done>campaign,'normal first boot must run both schema 48 and 49 migrations');
 assert.match(first,/if \(isset\(\$GLOBALS\['az_database'\]\)\) \{\s*return \$GLOBALS\['az_database'\]/);
});

