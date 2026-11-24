<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){fwrite(STDERR,"Missing SQLite\n");exit(2);}
function c159_ok(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('Creator Club v1.59: '.$message);}
function c159_denied(callable $fn,int $status,string $message):void{try{$fn();}catch(AzHttpError $e){c159_ok($e->status===$status,$message.' (HTTP '.$e->status.')');return;}throw new RuntimeException('Creator Club v1.59: expected rejection: '.$message);}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db->exec('PRAGMA foreign_keys=ON');$GLOBALS['az_database']=$db;
$db->exec("CREATE TABLE studio_schema(id INTEGER PRIMARY KEY,version INTEGER NOT NULL);INSERT INTO studio_schema VALUES(1,50);
CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,status TEXT);
CREATE TABLE user_roles(user_id INTEGER,role TEXT,PRIMARY KEY(user_id,role));
CREATE TABLE user_social_profiles(user_id INTEGER PRIMARY KEY,visibility TEXT);
CREATE TABLE personal_media(id TEXT PRIMARY KEY,user_id INTEGER,storage_name TEXT,file_name TEXT,mime_type TEXT);
CREATE TABLE commerce_activity(event_key CHAR(64) PRIMARY KEY,actor_id INTEGER,recipient_id INTEGER,kind TEXT,title TEXT,detail TEXT,href TEXT,announce INTEGER,created_at TEXT,delivered_at TEXT NULL);
INSERT INTO users VALUES(1,'Creator One','active'),(2,'Fan One','active'),(3,'Admin','active'),(4,'Fan Two','active'),(5,'Creator Two','active');
INSERT INTO user_roles VALUES(1,'model'),(2,'fan'),(3,'admin'),(4,'fan'),(5,'model');
INSERT INTO user_social_profiles VALUES(1,'members'),(5,'members');");
$creator=['id'=>1,'roles'=>['model'],'status'=>'active'];$creator2=['id'=>5,'roles'=>['model'],'status'=>'active'];$fan=['id'=>2,'roles'=>['fan'],'status'=>'active'];$fan2=['id'=>4,'roles'=>['fan'],'status'=>'active'];$admin=['id'=>3,'roles'=>['admin'],'status'=>'active'];
$GLOBALS['az_config_cache']=['features'=>['creator_club_test_grants'=>true]];
az_upgrade_creator_memberships_schema($db);
$tier=az_creator_club_tier($creator,'Original tier','Original benefits',900,$db);az_creator_club_review($admin,'tier',$tier['id'],1,'approve',$db);
$old=az_creator_club_post($creator,$tier['id'],null,'SECRET_LEGACY_TITLE','SECRET_LEGACY_BODY',$db,true);az_creator_club_review($admin,'post',$old['id'],1,'approve',$db);
$grant=az_creator_club_test_grant($admin,2,1,$tier['id'],7,$db);az_creator_club_follow($fan,1,true,$db);
$db->exec('UPDATE studio_schema SET version=54 WHERE id=1');az_upgrade_creator_club_workspace_schema($db);
c159_ok(az_schema_version($db)===55,'54→55 migration');az_upgrade_creator_club_workspace_schema($db);c159_ok(az_schema_version($db)===55,'migration replay safe');
$oldRow=az_creator_club_post_row($old['id'],$fan,$db);c159_ok($oldRow['title']==='SECRET_LEGACY_TITLE'&&$oldRow['audience']==='tier'&&$oldRow['tier_id']===$tier['id'],'migration preserves approved posts and private exact-tier access');
c159_ok($db->query('PRAGMA foreign_key_check')->fetchAll()===[],'migrated foreign keys intact');
c159_ok(az_valid_id((string)$db->query('SELECT follow_token FROM creator_club_follows')->fetchColumn()),'existing follow gains dedup token');

$before=(int)$db->query('SELECT COUNT(*) FROM commerce_activity')->fetchColumn();az_creator_club_follow($fan,1,true,$db);c159_ok((int)$db->query('SELECT COUNT(*) FROM commerce_activity')->fetchColumn()===$before,'repeated follow sends no duplicate alert');
az_creator_club_follow($fan2,1,true,$db);$afterFollow=(int)$db->query('SELECT COUNT(*) FROM commerce_activity')->fetchColumn();c159_ok($afterFollow===$before+2,'new follow queues creator notice and own history atomically');
az_creator_club_follow($fan2,1,true,$db);c159_ok((int)$db->query('SELECT COUNT(*) FROM commerce_activity')->fetchColumn()===$afterFollow,'new follow idempotent');
az_creator_club_notification_preference($fan2,1,false,$db);c159_denied(fn()=>az_creator_club_notification_preference($fan2,5,true,$db),409,'cannot modify another relationship');

$free=az_creator_club_post($creator,'',null,'SECRET_FREE_TITLE','SECRET_FREE_BODY',$db,true,'followers');
c159_ok(count(az_creator_club_posts(1,$fan2,$db))===0,'pending follower content remains private');
c159_denied(fn()=>az_creator_club_review($admin,'post',$free['id'],1,'reject',$db),400,'rejection requires useful feedback');
az_creator_club_review($admin,'post',$free['id'],1,'approve',$db);
$freeRow=az_creator_club_post_row($free['id'],$fan,$db);c159_ok($freeRow['tier_id']===null&&$freeRow['audience']==='followers','follower post has no artificial tier');
c159_ok(count(az_creator_club_posts(1,$fan2,$db))===1,'muting alerts keeps follower access');
$alerts=$db->query("SELECT recipient_id,title,detail,href FROM commerce_activity WHERE kind='club_post_published'")->fetchAll();
c159_ok(count($alerts)===1&&(int)$alerts[0]['recipient_id']===2,'new post alert respects per-creator mute');
c159_ok(!str_contains(json_encode($alerts),'SECRET_'),'private post title/body absent from permanent alerts');
az_creator_club_follow($fan2,1,false,$db);c159_denied(fn()=>az_creator_club_post_row($free['id'],$fan2,$db),404,'unfollow immediately removes follower access');
c159_denied(fn()=>az_creator_club_post($creator,'',null,'Invalid audience','Text',$db,true,'public'),400,'unknown audience cannot bypass approval');
c159_denied(fn()=>az_creator_club_post($fan,'',null,'Bad author','Text',$db,true,'followers'),403,'fan cannot publish');

$proposal=az_creator_club_tier($creator,'Edit me','Before review',1200,$db);
c159_denied(fn()=>az_creator_club_tier_update($creator2,$proposal['id'],1,'Hijack','Data',1200,$db),404,'cross-creator tier edit denied');
az_creator_club_review($admin,'tier',$proposal['id'],1,'reject',$db,'Describe the benefit clearly.');
$changed=az_creator_club_tier_update($creator,$proposal['id'],2,'Updated proposal','Weekly studio updates',1200,$db);c159_ok($changed['revision']===3&&$changed['state']==='pending','rejected tier edits return to review');
c159_denied(fn()=>az_creator_club_tier_update($creator,$proposal['id'],2,'Old tab','Old data',1200,$db),409,'stale tier edit rejected');
az_creator_club_review($admin,'tier',$proposal['id'],3,'approve',$db);
c159_denied(fn()=>az_creator_club_tier_update($creator,$proposal['id'],4,'Silently changed','Data',1200,$db),409,'approved tier cannot silently change');
$history=az_creator_club_history($creator,1,$db);c159_ok((bool)array_filter($history,fn($e)=>$e['note']==='Describe the benefit clearly.'),'review feedback preserved after resubmission');
c159_denied(fn()=>az_creator_club_history($fan,1,$db),403,'fan cannot read moderation history');
c159_denied(fn()=>az_creator_club_access_history($creator2,1,$db),403,'another creator cannot read access records');

az_creator_club_tier_archive($creator,$tier['id'],2,true,$db);c159_ok(count(az_creator_club_tiers(1,false,$db))===1,'archived tier excluded from public options');
c159_ok(az_creator_club_post_row($old['id'],$fan,$db)['id']===$old['id'],'archival preserves existing exact-tier access');
c159_denied(fn()=>az_creator_club_post($creator,$tier['id'],null,'Archived submission','Body',$db,true),403,'archived tier unavailable for new posts');
c159_denied(fn()=>az_creator_club_test_grant($admin,4,1,$tier['id'],7,$db),409,'archived tier cannot receive new grants');
c159_denied(fn()=>az_creator_club_tier_archive($creator,$tier['id'],2,false,$db),409,'stale archive restore blocked');
az_creator_club_tier_archive($creator,$tier['id'],3,false,$db);
$archiveSeed=$db->prepare("INSERT INTO creator_club_tiers(id,creator_id,title,description,monthly_cents,state,revision,created_at,updated_at,archived) VALUES(?,1,'Historical archived tier','Archived proposal',100,'approved',3,'2020-01-01T00:00:00Z','2020-01-01T00:00:00Z',1)");
for($i=0;$i<32;$i++)$archiveSeed->execute([az_id()]);
$manageIds=array_column(az_creator_club_tiers(1,true,$db),'id');
c159_ok(count($manageIds)===30&&in_array($tier['id'],$manageIds,true)&&in_array($proposal['id'],$manageIds,true),'active tiers remain manageable beyond 30 archived historical tiers');
c159_ok(array_column(az_creator_club_tiers(1,false,$db),'monthly_cents')===[900,1200],'public tier list preserves approved price order and omits archives');

c159_denied(fn()=>az_creator_club_post_update($creator,$free['id'],2,'',null,'Changed live','Body',true,'followers',$db),409,'approved post cannot silently change');
az_creator_club_withdraw($creator,$free['id'],2,$db);c159_denied(fn()=>az_creator_club_post_row($free['id'],$fan,$db),404,'withdrawal removes access immediately');
$edited=az_creator_club_post_update($creator,$free['id'],3,'',null,'SECRET_EDIT_TITLE','SECRET_EDIT_BODY',true,'followers',$db);c159_ok($edited['revision']===4&&$edited['state']==='pending','withdrawn edit requires fresh approval');
c159_denied(fn()=>az_creator_club_post_row($free['id'],$fan,$db),404,'resubmission does not republish');
c159_denied(fn()=>az_creator_club_review($admin,'post',$free['id'],2,'approve',$db),409,'old moderator tab cannot publish changed post');
az_creator_club_review($admin,'post',$free['id'],4,'approve',$db);c159_ok(az_creator_club_post_row($free['id'],$fan,$db)['body']==='SECRET_EDIT_BODY','fresh approval publishes new version');

$tierPost=az_creator_club_post($creator,$proposal['id'],null,'SECRET_OTHER_TIER','SECRET_OTHER_BODY',$db,true);az_creator_club_review($admin,'post',$tierPost['id'],1,'approve',$db);
c159_denied(fn()=>az_creator_club_post_row($tierPost['id'],$fan,$db),404,'one tier never implies another tier');
$many=$db->prepare("INSERT INTO creator_club_posts(id,creator_id,tier_id,title,body,rights_confirmed,state,revision,created_at,updated_at) VALUES(?,1,?,'SECRET_LOCKED_TIER','SECRET_BODY',1,'approved',2,'2099-01-01T00:00:00Z','2099-01-01T00:00:00Z')");
for($i=0;$i<62;$i++)$many->execute([az_id(),$proposal['id']]);
c159_ok(in_array($free['id'],array_column(az_creator_club_posts(1,$fan,$db),'id'),true),'locked tier posts cannot hide an older accessible follower post behind the page limit');
c159_ok(!str_contains((string)$db->query("SELECT GROUP_CONCAT(title||detail) FROM commerce_activity")->fetchColumn(),'SECRET_'),'all notices omit private content permanently');

$postCount=(int)$db->query('SELECT COUNT(*) FROM creator_club_posts')->fetchColumn();$auditCount=(int)$db->query('SELECT COUNT(*) FROM creator_club_review_events')->fetchColumn();
$db->exec("CREATE TRIGGER club_test_outbox_failure BEFORE INSERT ON commerce_activity WHEN NEW.kind='club_review_needed' BEGIN SELECT RAISE(ABORT,'outbox unavailable'); END");
$failed=false;try{az_creator_club_post($creator,'',null,'Atomic post','Text',$db,true,'followers');}catch(PDOException){$failed=true;}
c159_ok($failed&&(int)$db->query('SELECT COUNT(*) FROM creator_club_posts')->fetchColumn()===$postCount&&(int)$db->query('SELECT COUNT(*) FROM creator_club_review_events')->fetchColumn()===$auditCount,'outbox failure rolls back source and audit together');$db->exec('DROP TRIGGER club_test_outbox_failure');

$hub=az_creator_club_my_memberships($fan,$db);c159_ok(count($hub)===1&&$hub[0]['following']===true&&$hub[0]['access'][0]['status']==='active_test','fan hub shows actual follow and explicitly test access');
$GLOBALS['az_config_cache']=['features'=>['creator_club_test_grants'=>false]];
$hub=az_creator_club_my_memberships($fan,$db);c159_ok($hub[0]['access'][0]['status']==='test_disabled','disabled test grant is not shown as active membership');c159_denied(fn()=>az_creator_club_post_row($old['id'],$fan,$db),404,'test flag off removes private tier access');
c159_ok(az_creator_club_post_row($free['id'],$fan,$db)['id']===$free['id'],'free audience works without payment or test grants');
$GLOBALS['az_config_cache']=['features'=>['creator_club_test_grants'=>true]];
c159_denied(fn()=>az_creator_club_revoke_access($creator,$grant['id'],$db),403,'creator cannot impersonate administrator and revoke access');
az_creator_club_revoke_access($admin,$grant['id'],$db);$revokedEvents=(int)$db->query("SELECT COUNT(*) FROM creator_club_review_events WHERE event='revoked'")->fetchColumn();az_creator_club_revoke_access($admin,$grant['id'],$db);
c159_ok($revokedEvents===1&&(int)$db->query("SELECT COUNT(*) FROM creator_club_review_events WHERE event='revoked'")->fetchColumn()===1,'revocation idempotent with one audit');c159_denied(fn()=>az_creator_club_post_row($old['id'],$fan,$db),404,'revocation prevents media lookup');
c159_ok(az_creator_club_my_memberships($fan,$db)[0]['access'][0]['status']==='revoked','hub shows actual revoked access');

$db->exec("UPDATE user_social_profiles SET visibility='private' WHERE user_id=1");c159_denied(fn()=>az_creator_club_post_row($free['id'],$fan,$db),404,'private creator profile prevents follower media access');
$hub=az_creator_club_my_memberships($fan,$db);c159_ok(!$hub[0]['available']&&$hub[0]['name']==='Unavailable creator'&&$hub[0]['available_posts']===0,'unavailable creator uses generic hub details');
$db->exec("UPDATE users SET status='disabled' WHERE id=1");az_creator_club_notification_preference($fan,1,false,$db);az_creator_club_follow($fan,1,false,$db);c159_ok(!az_creator_club_is_following($fan,1,$db),'can mute and unfollow unavailable creator');
$inactiveTier=az_creator_club_tier($creator2,'Pending creator tier','Proposed benefits',1500,$db);
$inactivePost=az_creator_club_post($creator2,'',null,'Pending creator post','Waiting for review',$db,true,'followers');
$db->exec("UPDATE users SET status='disabled' WHERE id=5");
c159_denied(fn()=>az_creator_club_review($admin,'tier',$inactiveTier['id'],1,'approve',$db),404,'inactive creator tier cannot be approved');
c159_denied(fn()=>az_creator_club_review($admin,'post',$inactivePost['id'],1,'approve',$db),404,'inactive creator post cannot be published');
c159_ok(az_creator_club_review($admin,'tier',$inactiveTier['id'],1,'reject',$db,'Creator account is inactive.')['state']==='rejected','staff can reject inactive creator tier with feedback');
c159_ok(az_creator_club_review($admin,'post',$inactivePost['id'],1,'reject',$db,'Creator account is inactive.')['state']==='rejected','staff can clear inactive creator post from review');
$brain=az_creator_club_briefing($fan,$db);c159_ok(isset($brain['counts']['clubsFollowing'])&&!isset($brain['counts']['clubReviews'])&&!isset($brain['counts']['clubFollowers']),'fan briefing does not expose staff statistics');
echo "AZHottie v1.59 Creator Clubs integration: PASS\n";
