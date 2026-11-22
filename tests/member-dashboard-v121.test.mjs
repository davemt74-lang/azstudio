import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {dirname,resolve} from 'node:path';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const read=f=>readFileSync(resolve(root,f),'utf8');
const php=read('dashboard.php'),data=read('includes/member-dashboard.php'),boot=read('includes/bootstrap.php');
test('dashboard replaces photo hero with four accountable metrics and actionable work panels',()=>{
  for(const label of ['Profile views','Published interviews','Live show appearances','My published videos','Active video productions','Open &amp; active assignments','Upcoming events &amp; appointments'])assert.match(php,new RegExp(label));
  assert.doesNotMatch(php,/hero-angelic-studio|Welcome back|Explore your space|Keep the studio moving/);
  assert.match(php,/az_dashboard_data\(\$user\)/);
  assert.match(php,/not yet tracked/);
  assert.match(php,/dashboard\.css\?v=1\.22\.0/);
});
test('dashboard analytics are owner scoped and visibility counts avoid personal visitor tracking',()=>{
  assert.match(boot,/member-dashboard\.php/);
  assert.match(data,/CREATE TABLE IF NOT EXISTS member_profile_views_daily/);
  assert.match(data,/PRIMARY KEY \(user_id,view_day\)/);
  assert.match(data,/FROM member_profile_views_daily WHERE user_id=\?/);
  assert.match(data,/mp\.linked_user_id=\?/);
  assert.match(data,/WHERE a\.user_id=\?/);
  assert.match(data,/r\.event_id=e\.id AND r\.user_id=\?/);
  assert.match(data,/\$now-\$last<1800/);
  assert.match(data,/az_social_visible\(\$profile,\$viewer,\$target\)/);
  assert.match(data,/az_has_role\(\$viewer,'admin'\)/);
  assert.doesNotMatch(data,/REMOTE_ADDR|HTTP_X_FORWARDED_FOR|HTTP_COOKIE/);
});
test('dashboard cards have explicit light surface and readable text',()=>{
  const css=read('assets/dashboard.css');
  for(const selector of ['azh-stats-hero','azh-stat-card','azh-dash-panel','azh-dash-review']){
    assert.match(css,new RegExp(`\\.${selector}\\{[^}]*background:[^;]*#fff`));
  }
  assert.match(css,/@media\(max-width:600px\)/);
});
