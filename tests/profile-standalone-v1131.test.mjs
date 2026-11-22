import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {spawnSync} from 'node:child_process';

const root=new URL('../',import.meta.url);
const read=(path)=>readFileSync(new URL(path,root),'utf8');

test('social profiles do not mount the member sidebar or workspace breadcrumb',()=>{
 const view=read('user.php');
 assert.match(view,/<body class="azh-social-profile-body" data-page="user">/);
 assert.match(view,/az_render_universal_header\('user',\$viewer\)/);
 assert.match(view,/<main class="social-profile-page"/);
 assert.doesNotMatch(view,/az_render_member_layout_open|az_render_member_layout_close/);
 assert.doesNotMatch(view,/assets\/member-layout\.css/);
 assert.doesNotMatch(view,/azh-canvas-topline|azh-member-sidebar/);
 assert.match(view,/social-cover/);
 assert.match(view,/social-profile-grid/);
});

test('social profile starts with its cover directly under the universal header',()=>{
 const css=read('assets/social.css');
 assert.match(css,/\.azh-social-profile-body\{background:#f8f6f7/);
 assert.match(css,/\.social-profile-page\{[^\n]*padding:0 clamp\(/);
 assert.match(css,/\.social-profile-card\{[^\n]*border-top:0[^\n]*border-radius:0 0 20px 20px/);
 assert.match(css,/\.social-profile-grid\{display:grid/);
 assert.match(css,/@media\(max-width:720px\)/);
});

test('profile header keeps account menu, but shows no useless mobile sidebar button',()=>{
 const header=read('includes/universal-header.php');
 assert.match(header,/if \(\$current !== 'user' \&\& \$current !== 'live-director'/);
 const code=`function az_has_role($u,$r){return in_array($r,$u['roles'],true);}function az_h($v){return htmlspecialchars((string)$v,ENT_QUOTES);}function az_is_installed(){return false;}function az_social_empty($id){return ['avatar_storage_name'=>null];}function az_url($p=''){return $p;}function az_csrf_token(){return 'fixture-token';}require ${JSON.stringify(new URL('includes/universal-header.php',root).pathname)}; $v=['id'=>10,'name'=>'Model Example','roles'=>['model']];ob_start();az_render_universal_header('user',$v);$social=ob_get_clean();ob_start();az_render_universal_header('dashboard',$v);$workspace=ob_get_clean();echo json_encode(['social'=>$social,'workspace'=>$workspace]);`;
 const result=spawnSync('php',['-d','display_errors=1','-r',code],{encoding:'utf8'});
 assert.equal(result.status,0,result.stderr||result.stdout);
 const rendered=JSON.parse(result.stdout);
 assert.match(rendered.social,/azh-global-header/);
 assert.match(rendered.social,/azh-account-menu/);
 assert.doesNotMatch(rendered.social,/id="azh-member-menu-button"/);
 assert.doesNotMatch(rendered.social,/class="azh-floating-sidebar"/);
 assert.match(rendered.workspace,/id="azh-member-menu-button"/);
});
