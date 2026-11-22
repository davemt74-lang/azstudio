import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
const base=new URL('../',import.meta.url);
const read=name=>readFileSync(new URL(name,base),'utf8');
const php=code=>spawnSync('php',['-d','display_errors=1','-r',`require ${JSON.stringify(new URL('includes/bootstrap.php',base).pathname)}; ${code}`],{encoding:'utf8'});

test('social profile visibility is private by default, members-only by choice, public by opt-in',()=>{
 const code=`$target=['id'=>7];$self=['id'=>7,'roles'=>['model']];$other=['id'=>8,'roles'=>['fan']];$admin=['id'=>9,'roles'=>['admin']];$p=az_social_empty(7);if(az_social_visible($p,null,$target)||az_social_visible($p,$other,$target))exit(21);if(!az_social_visible($p,$self,$target)||!az_social_visible($p,$admin,$target))exit(22);$p['visibility']='members';if(az_social_visible($p,null,$target)||!az_social_visible($p,$other,$target))exit(23);$p['visibility']='public';if(!az_social_visible($p,null,$target))exit(24);echo 'OK';`;
 const result=php(code);assert.equal(result.status,0,result.stderr+result.stdout);assert.equal(result.stdout.trim(),'OK');
});
test('social page is a full profile while the old account form has a separate settings route',()=>{
 const page=read('user.php'),settings=read('includes/account-views.php');
 for(const part of ['social-cover','social-avatar-wrap','social-profile-head','social-role-badges','social-posts','social-about','social-video-grid','Edit profile','user-media.php','account-settings.php'])assert.ok(page.includes(part),part);
 assert.match(read('account.php'),/az_redirect\('user\.php'\)/);
 assert.match(read('account-settings.php'),/az_render_account_app\('settings'\)/);
 assert.match(settings,/Social profile &amp; visibility/);assert.match(settings,/Who can see this profile/);
 assert.match(settings,/Remove cover image/);assert.match(settings,/Remove profile picture/);
 assert.match(read('assets/social-settings.js'),/request\('save',body\)/);
 assert.match(page,/<base href=/);
 assert.match(read('.htaccess'),/RewriteRule \^user\//);
});
test('uploaded avatar and cover have private storage, image type checks, and gated streaming',()=>{
 const m=read('includes/social-profiles.php'),api=read('social-api.php'),media=read('user-media.php');
 assert.match(m,/CREATE TABLE IF NOT EXISTS user_social_profiles/);
 assert.match(m,/version=21/);
 assert.match(read('includes/database.php'),/az_upgrade_social_schema/);
 assert.match(m,/az_social_visible/);assert.match(media,/az_social_visible\(\$profile,\$viewer,\$target\)/);
 assert.match(media,/preg_match\('\/\\A\[a-f0-9\]/);assert.match(media,/Content-Type:/);
 for(const mime of ['image/jpeg','image/png','image/webp'])assert.ok(m.includes(mime));
 assert.doesNotMatch(m,/image\/svg\+xml/);
 assert.match(m,/is_uploaded_file/);assert.match(m,/move_uploaded_file/);
 assert.match(api,/az_require_auth\(true\)/);assert.match(api,/az_require_csrf\(\)/);
 assert.match(api,/az_community_lock\(\)/);
 assert.match(read('assets/social-profile.js'),/dataset\.imageKind/);
});
test('profile publishes only existing Watch videos and does not expose private gallery or personal data',()=>{
 const m=read('includes/social-profiles.php'),view=read('user.php'),header=read('includes/universal-header.php');
 assert.match(m,/az_public_video_conditions/);assert.match(m,/v\.model_id=\?/);
 assert.doesNotMatch(view,/\$target\['email'\]|\$target\['password_hash'\]|\$model\['availability'\]|private_notes/);
 assert.match(view,/private uploads will not appear automatically/i);
 assert.match(header,/azh-account-menu/);assert.match(header,/account-settings\.php/);assert.match(header,/user\.php/);
 assert.match(read('assets/universal-header.css'),/azh-account-menu__panel/);
});
test('camera guest login-free and upload retry logic remain present',()=>{
 const cam=read('my-camera.php'),js=read('assets/personal-camera.js'),core=read('assets/personal-phone-core.js');
 assert.match(cam,/No login is required/);assert.match(cam,/azh-phone-capture/);assert.match(cam,/phone-upload-drawer/);
 assert.match(js,/action==='uploadStatus'/);assert.match(core,/preferredChunkBytes/);
 assert.match(js,/mode==='signed-in'/);assert.match(js,/device\?\.inviteId!==inviteId/);
});
