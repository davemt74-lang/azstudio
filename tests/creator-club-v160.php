<?php
declare(strict_types=1);
/** Behavior tests for durable reads, authorized pagination, drafts and due releases. */
require_once __DIR__.'/../includes/bootstrap.php';
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){fwrite(STDERR,"Missing SQLite\n");exit(2);}

function c160_ok(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException('Creator Club v1.60: '.$message);
}
function c160_denied(callable $fn,int $status,string $message):void
{
    try{$fn();}catch(AzHttpError $e){c160_ok($e->status===$status,$message.' (HTTP '.$e->status.')');return;}
    throw new RuntimeException('Creator Club v1.60: expected rejection: '.$message);
}
function c160_row(PDO $db,string $id):array
{
    $q=$db->prepare('SELECT * FROM creator_club_posts WHERE id=?');$q->execute([$id]);return $q->fetch();
}
function c160_scalar(PDO $db,string $sql,array $args=[]):mixed
{
    $q=$db->prepare($sql);$q->execute($args);return $q->fetchColumn();
}
function c160_actor(int $id,string $role):array{return ['id'=>$id,'roles'=>[$role],'status'=>'active'];}
function c160_db(bool $upgrade=true):PDO
{
    $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys=ON');$GLOBALS['az_database']=$db;
    $GLOBALS['az_config_cache']=['features'=>['creator_club_test_grants'=>false]];
    $db->exec("CREATE TABLE studio_schema(id INTEGER PRIMARY KEY,version INTEGER NOT NULL);INSERT INTO studio_schema VALUES(1,50);
        CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,status TEXT);
        CREATE TABLE user_roles(user_id INTEGER,role TEXT,PRIMARY KEY(user_id,role));
        CREATE TABLE user_social_profiles(user_id INTEGER PRIMARY KEY,visibility TEXT,tagline TEXT,avatar_storage_name TEXT);
        CREATE TABLE model_profiles(id TEXT PRIMARY KEY,linked_user_id INTEGER,status TEXT,stage_name TEXT);
        CREATE TABLE personal_media(id TEXT PRIMARY KEY,user_id INTEGER,storage_name TEXT,file_name TEXT,mime_type TEXT,revision INTEGER NOT NULL DEFAULT 1);
        CREATE TABLE commerce_activity(event_key CHAR(64) PRIMARY KEY,actor_id INTEGER,recipient_id INTEGER,kind TEXT,title TEXT,detail TEXT,href TEXT,announce INTEGER,created_at TEXT,delivered_at TEXT NULL);
        INSERT INTO users VALUES(1,'Creator One','active'),(2,'Fan One','active'),(3,'Administrator','active'),(4,'Fan Two','active'),(5,'Private Legal Name','active'),(6,'Muted Fan','active');
        INSERT INTO user_roles VALUES(1,'model'),(2,'fan'),(3,'admin'),(4,'fan'),(5,'model'),(6,'fan');
        INSERT INTO user_social_profiles VALUES(1,'members','Desert studio journal',NULL),(5,'members','Cactus inspiration',NULL);
        INSERT INTO model_profiles VALUES('55555555555555555555555555555555',5,'active','Cactus Muse');");
    az_upgrade_creator_memberships_schema($db);
    $db->exec('UPDATE studio_schema SET version=54 WHERE id=1');az_upgrade_creator_club_workspace_schema($db);
    if($upgrade){az_upgrade_creator_club_publishing_schema($db);az_upgrade_creator_club_feed_schema($db);}
    return $db;
}
function c160_publish(PDO $db,array $creator,array $staff,string $title,string $audience='followers',string $tier=''):array
{
    $saved=az_creator_club_save_post($creator,['title'=>$title,'body'=>'Private body for '.$title,'audience'=>$audience,'tierId'=>$tier,'rightsConfirmed'=>true],true,$db);
    return az_creator_club_review($staff,'post',$saved['id'],$saved['revision'],'approve',$db);
}
function c160_seed_post(PDO $db,int $creator,string $title,string $date,array $overrides=[]):string
{
    $row=array_replace(['id'=>az_id(),'creator_id'=>$creator,'tier_id'=>null,'media_id'=>null,'title'=>$title,'body'=>'Private body '.$title,'rights_confirmed'=>1,'state'=>'approved','revision'=>2,'created_at'=>$date,'updated_at'=>$date,'reviewed_at'=>$date,'reviewed_by'=>3,'audience'=>'followers','published_at'=>$date],$overrides);
    $columns=array_keys($row);$q=$db->prepare('INSERT INTO creator_club_posts('.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($columns),'?')).')');$q->execute(array_values($row));return $row['id'];
}
function c160_pages(callable $get,string $key):array
{
    $cursor=null;$seen=[];$cursors=[];
    do{
        $page=$get($cursor);c160_ok(isset($page[$key])&&array_key_exists('nextCursor',$page),'page exposes rows and a continuation cursor');
        foreach($page[$key] as $row){c160_ok(!isset($seen[(string)$row['id']]),'pagination never repeats an item');$seen[(string)$row['id']]=$row;}
        $cursor=$page['nextCursor'];
        if($cursor!==null&&$cursor!==''){c160_ok(!isset($cursors[$cursor]),'pagination makes progress');$cursors[$cursor]=true;}
        c160_ok(count($cursors)<100,'pagination is bounded');
    }while($cursor!==null&&$cursor!=='');
    return array_values($seen);
}

$creator=c160_actor(1,'model');$fan=c160_actor(2,'fan');$admin=c160_actor(3,'admin');$other=c160_actor(4,'fan');$creator2=c160_actor(5,'model');$muted=c160_actor(6,'fan');

// A deployed v1.59 database gains publication timestamps without altering approved content.
$db=c160_db(false);
$legacyMedia=str_repeat('a',32);
$db->prepare("INSERT INTO personal_media(id,user_id,storage_name,file_name,mime_type,revision) VALUES(?,1,'legacy.bin','legacy.mp4','video/mp4',3)")->execute([$legacyMedia]);
$legacy=az_creator_club_post($creator,'',$legacyMedia,'Legacy approved','Legacy private body',$db,true,'followers');
az_creator_club_review($admin,'post',$legacy['id'],$legacy['revision'],'approve',$db);
$legacyPending=az_creator_club_post($creator,'',null,'Legacy pending','Pending private body',$db,true,'followers');
$before=c160_row($db,$legacy['id']);
az_upgrade_creator_club_publishing_schema($db);c160_ok(az_schema_version($db)===56,'55 to 56 publication migration');
az_upgrade_creator_club_feed_schema($db);c160_ok(az_schema_version($db)===57,'56 to 57 read-state migration');
az_upgrade_creator_club_publishing_schema($db);az_upgrade_creator_club_feed_schema($db);
$after=c160_row($db,$legacy['id']);
foreach(['title','body','state','revision','audience','media_id','tier_id'] as $column)c160_ok($after[$column]===$before[$column],'migration preserves '.$column);
c160_ok($after['published_at']===$before['reviewed_at']&&c160_row($db,$legacyPending['id'])['published_at']===null,'only approved legacy posts receive release timestamps');
c160_ok((int)$after['media_revision']===3,'migration pins an existing approved post to its owned media revision');
c160_ok($db->query('PRAGMA foreign_key_check')->fetchAll()===[],'migration preserves foreign keys and replays safely');

// More than sixty hidden posts cannot crowd eligible updates out of the combined feed.
$db=c160_db();
az_creator_club_follow($fan,1,true,$db);az_creator_club_follow($fan,5,true,$db);az_creator_club_follow($other,1,true,$db);
$tier=az_creator_club_tier($creator,'Exact tier','Exact access only',1000,$db);az_creator_club_review($admin,'tier',$tier['id'],1,'approve',$db);
$eligible=[];
for($i=0;$i<67;$i++)$eligible[]=c160_seed_post($db,$i%3===0?5:1,'AVAILABLE_'.$i,gmdate('Y-m-d\TH:i:s\Z',1704067200+$i));
for($i=0;$i<73;$i++)c160_seed_post($db,1,'SECRET_LOCKED_'.$i,'2025-01-01T00:00:00Z',['audience'=>'tier','tier_id'=>$tier['id']]);
foreach(['draft','pending','scheduled','withdrawn','rejected'] as $state)c160_seed_post($db,1,'SECRET_'.$state,'2025-02-01T00:00:00Z',['state'=>$state,'published_at'=>null]);
$page=az_creator_club_feed_page($fan,['view'=>'all','limit'=>7],$db);
c160_ok(count($page['posts'])===7&&$page['unreadCount']===67,'feed fills an eligible page and counts only unread eligible posts');
c160_ok(!str_contains(json_encode($page),'SECRET_'),'page payload contains no hidden title or body');
$all=c160_pages(fn($cursor)=>az_creator_club_feed_page($fan,['view'=>'all','cursor'=>$cursor,'limit'=>7],$db),'posts');
c160_ok(count($all)===67&&count(array_diff($eligible,array_column($all,'id')))===0,'combined feed traverses every eligible post beyond the former cap');
$byCreator=c160_pages(fn($cursor)=>az_creator_club_feed_page($fan,['creator'=>5,'cursor'=>$cursor,'limit'=>5],$db),'posts');
c160_ok(count($byCreator)===23&&array_unique(array_column($byCreator,'creator_id'))===[5],'creator filter applies before pagination');
c160_denied(fn()=>az_creator_club_feed_page($fan,['cursor'=>'malformed!'], $db),400,'malformed feed cursor is rejected');
c160_denied(fn()=>az_creator_club_feed_page($fan,['view'=>'unread','cursor'=>$page['nextCursor']],$db),400,'cursor cannot be reused with a different feed filter');
c160_denied(fn()=>az_creator_club_feed_page($other,['cursor'=>$page['nextCursor']],$db),400,'cursor is scoped to the account that requested it');

// Reading is an explicit, per-user write that survives new requests and remains reversible.
$readId=$page['posts'][0]['id'];
az_creator_club_post_row($readId,$fan,$db);az_creator_club_feed_page($fan,[], $db);
c160_ok((int)c160_scalar($db,'SELECT COUNT(*) FROM creator_club_post_reads')===0,'viewing a feed or opening a post never silently marks it read');
az_creator_club_mark_read($fan,$readId,true,$db);az_creator_club_mark_read($fan,$readId,true,$db);
c160_ok((int)c160_scalar($db,'SELECT COUNT(*) FROM creator_club_post_reads WHERE user_id=2 AND post_id=?',[$readId])===1,'repeat mark-read is idempotent');
$unread=az_creator_club_feed_page($fan,['view'=>'unread','limit'=>7],$db);
c160_ok($unread['unreadCount']===66&&!in_array($readId,array_column($unread['posts'],'id'),true),'unread filter applies persisted reads before its limit');
$readAgain=az_creator_club_feed_page(c160_actor(2,'fan'),['view'=>'all','limit'=>7],$db);
c160_ok((bool)$readAgain['posts'][0]['is_read'],'a fresh viewer request loads persisted read state');
c160_ok(az_creator_club_feed_page($other,['view'=>'unread'], $db)['unreadCount']===44,'another fan has independent read state');
$locked=(string)c160_scalar($db,"SELECT id FROM creator_club_posts WHERE title='SECRET_LOCKED_0'");
c160_denied(fn()=>az_creator_club_mark_read($fan,$locked,true,$db),404,'cannot record a hidden post as read');
c160_denied(fn()=>az_creator_club_mark_read($fan,str_repeat('f',32),false,$db),404,'unknown post does not expose or create read records');
az_creator_club_mark_read($fan,$readId,false,$db);c160_ok(az_creator_club_feed_page($fan,['view'=>'unread'], $db)['unreadCount']===67,'mark-unread removes only the caller read');
az_creator_club_mark_read($fan,$readId,true,$db);$db->prepare('UPDATE creator_club_posts SET revision=revision+1 WHERE id=?')->execute([$readId]);
c160_ok(az_creator_club_feed_page($fan,['view'=>'unread'], $db)['unreadCount']===67,'a newly approved publication revision becomes unread again');

// Authorization changes remove both content and counts immediately, including cursor pages.
$GLOBALS['az_config_cache']['features']['creator_club_test_grants']=true;
$grant=az_creator_club_test_grant($admin,2,1,$tier['id'],7,$db);
c160_ok(az_creator_club_feed_page($fan,[], $db)['unreadCount']===140,'exact live tier entitlement adds only its authorized posts');
$db->prepare("UPDATE creator_club_tiers SET state='suspended' WHERE id=?")->execute([$tier['id']]);
c160_denied(fn()=>az_creator_club_post_row($locked,$fan,$db),404,'tier suspension blocks direct private post lookup');
c160_ok(az_creator_club_feed_page($fan,[], $db)['unreadCount']===67,'suspended tier posts disappear from feed and unread count');
$db->prepare("UPDATE creator_club_tiers SET state='approved' WHERE id=?")->execute([$tier['id']]);
az_creator_club_revoke_access($admin,$grant['id'],$db);
c160_denied(fn()=>az_creator_club_post_row($locked,$fan,$db),404,'revocation blocks direct private post lookup');
c160_ok(az_creator_club_feed_page($fan,[], $db)['unreadCount']===67,'revoked entitlement cannot refill the feed');
$db->exec("UPDATE user_social_profiles SET visibility='private' WHERE user_id=1");
c160_ok(az_creator_club_feed_page($fan,[], $db)['unreadCount']===23,'private creators do not crowd visible creators or leak counts');
$db->exec("UPDATE users SET status='disabled' WHERE id=5");
c160_ok(az_creator_club_feed_page($fan,[], $db)['posts']===[],'disabled creators disappear from combined feed');

// Per-creator pagination also traverses more than sixty actual posts without duplication.
$db=c160_db();az_creator_club_follow($fan,1,true,$db);
$postIds=[];for($i=0;$i<65;$i++)$postIds[]=c160_seed_post($db,1,'ARCHIVE_'.$i,'2024-01-01T00:00:00Z');
$postPages=c160_pages(fn($cursor)=>az_creator_club_posts_page(1,$fan,['limit'=>9,'cursor'=>$cursor],$db),'posts');
c160_ok(count($postPages)===65&&count(array_diff($postIds,array_column($postPages,'id')))===0,'equal-time cursor tie breaks retain all older creator posts');

// Discovery remains useful beyond eighty profiles and never searches a hidden legal name.
$insertUser=$db->prepare("INSERT INTO users(id,name,status) VALUES(?,?,'active')");
$insertRole=$db->prepare("INSERT INTO user_roles(user_id,role) VALUES(?,'model')");
$insertSocial=$db->prepare("INSERT INTO user_social_profiles(user_id,visibility,tagline) VALUES(?,'members',?)");
for($i=10;$i<96;$i++){$insertUser->execute([$i,'Archive Creator '.$i]);$insertRole->execute([$i]);$insertSocial->execute([$i,'Night sky journal '.$i]);}
$discovery=c160_pages(fn($cursor)=>az_creator_club_discovery_page($fan,['filter'=>'all','cursor'=>$cursor,'limit'=>11],$db),'creators');
c160_ok(count($discovery)===88&&in_array(1,array_column($discovery,'id'),true),'discovery includes older creators after more than eighty newer profiles');
$search=az_creator_club_discovery_page($fan,['q'=>'Cactus Muse'], $db);
c160_ok(count($search['creators'])===1&&(int)$search['creators'][0]['id']===5&&$search['creators'][0]['name']==='Cactus Muse','discovery searches the displayed stage name');
c160_ok(az_creator_club_discovery_page($fan,['q'=>'Private Legal Name'], $db)['creators']===[],'search cannot reveal a creator private legal name');
c160_ok(count(az_creator_club_discovery_page($fan,['q'=>'Desert studio'], $db)['creators'])===1,'discovery searches the creator tagline');
$following=az_creator_club_discovery_page($fan,['filter'=>'following'], $db);
c160_ok(array_map('intval',array_column($following['creators'],'id'))===[1],'Following discovery only includes actual follows');
$first=az_creator_club_discovery_page($fan,['limit'=>3],$db);
c160_denied(fn()=>az_creator_club_discovery_page($fan,['q'=>'Night','cursor'=>$first['nextCursor']],$db),400,'search changes reject an incompatible continuation cursor');
$db->exec("UPDATE user_social_profiles SET visibility='private' WHERE user_id>=10");
c160_ok(count(az_creator_club_discovery_page($fan,['limit'=>3], $db)['creators'])===2,'hidden newer profiles do not crowd older discoverable creators');

// The publication journey begins with a genuinely incomplete private draft.
$db=c160_db();az_creator_club_follow($fan,1,true,$db);az_creator_club_follow($muted,1,true,$db);az_creator_club_notification_preference($muted,1,false,$db);
$draft=az_creator_club_save_post($creator,['audience'=>'tier','title'=>'','body'=>'','rightsConfirmed'=>false],false,$db);
c160_ok($draft['state']==='draft'&&$draft['revision']===1,'incomplete draft saves without a title, body, tier or rights confirmation');
c160_ok(c160_row($db,$draft['id'])['published_at']===null&&az_creator_club_review_queue($db)===[],'draft saves neither publish nor enter staff review');
c160_ok((int)c160_scalar($db,"SELECT COUNT(*) FROM commerce_activity WHERE kind IN ('club_review_needed','club_post_published')")===0,'draft saves send no review or publication notifications');
c160_denied(fn()=>az_creator_club_post_row($draft['id'],$fan,$db),404,'draft content is private from followers');
c160_denied(fn()=>az_creator_club_save_post($creator,['id'=>$draft['id'],'revision'=>1,'audience'=>'followers'],true,$db),400,'submit requires complete content and rights');
c160_denied(fn()=>az_creator_club_save_post($creator2,['id'=>$draft['id'],'revision'=>1,'title'=>'Hijack'],false,$db),404,'another creator cannot edit a draft');
c160_denied(fn()=>az_creator_club_save_post($fan,['title'=>'Fan draft'],false,$db),403,'fan cannot create a creator draft');
$saved=az_creator_club_save_post($creator,['id'=>$draft['id'],'revision'=>1,'audience'=>'followers','title'=>'PRIVATE_SCHEDULED_TITLE','body'=>'PRIVATE_SCHEDULED_BODY','rightsConfirmed'=>true,'publishLocal'=>'2030-07-01T12:00','publishTimezone'=>'America/New_York'],false,$db);
$row=c160_row($db,$draft['id']);c160_ok($row['state']==='draft'&&$row['publish_at']==='2030-07-01T16:00:00Z'&&$row['publish_timezone']==='America/New_York','IANA local schedule converts to stored UTC without publishing');
c160_denied(fn()=>az_creator_club_save_post($creator,['id'=>$draft['id'],'revision'=>1,'title'=>'Stale overwrite'],false,$db),409,'stale draft saves are rejected');
c160_ok(c160_row($db,$draft['id'])['body']==='PRIVATE_SCHEDULED_BODY','stale saves preserve the durable draft contents');
$submitted=az_creator_club_save_post($creator,['id'=>$saved['id'],'revision'=>$saved['revision'],'audience'=>'followers','title'=>$row['title'],'body'=>$row['body'],'rightsConfirmed'=>true,'publishLocal'=>'2030-07-01T12:00','publishTimezone'=>'America/New_York'],true,$db);
c160_ok($submitted['state']==='pending'&&count(az_creator_club_review_queue($db))===1,'explicit submission enters staff review');
$reviewed=az_creator_club_review($admin,'post',$submitted['id'],$submitted['revision'],'approve',$db);
c160_ok($reviewed['state']==='scheduled'&&c160_row($db,$submitted['id'])['published_at']===null,'approval respects a future schedule');
c160_denied(fn()=>az_creator_club_post_row($submitted['id'],$fan,$db),404,'scheduled post remains private from followers');
c160_ok(az_creator_club_feed_page($fan,[], $db)['posts']===[],'scheduled approval adds no premature feed card');
c160_ok((int)c160_scalar($db,"SELECT COUNT(*) FROM commerce_activity WHERE kind='club_post_published'")===0,'scheduled approval sends no premature fan notification');
c160_denied(fn()=>az_creator_club_save_post($creator,['id'=>$reviewed['id'],'revision'=>$reviewed['revision'],'title'=>'Changed scheduled'],false,$db),409,'approved scheduled content requires withdrawal before editing');
$notDue=az_creator_club_publish_due($db,50,'manual');c160_ok($notDue['published']===0,'due job leaves future schedules private');
$health=az_creator_club_publish_status($db);c160_ok(empty($health['completedAt']),'manual checks never claim the cron heartbeat is active');
$db->prepare("UPDATE creator_club_posts SET publish_at='2020-01-01T00:00:00Z' WHERE id=?")->execute([$submitted['id']]);
az_creator_club_feed_page($fan,[], $db);az_creator_club_post_row($submitted['id'],$creator,$db);
c160_ok(c160_row($db,$submitted['id'])['state']==='scheduled','opening pages does not implicitly publish an overdue schedule');
$due=az_creator_club_publish_due($db,50,'cron');
c160_ok($due['published']===1&&c160_row($db,$submitted['id'])['published_at']!==null,'due cron releases the approved revision');
c160_ok(az_creator_club_post_row($submitted['id'],$fan,$db)['title']==='PRIVATE_SCHEDULED_TITLE','authorized follower can open the due publication');
$alerts=$db->query("SELECT recipient_id,title,detail,href FROM commerce_activity WHERE kind='club_post_published'")->fetchAll();
c160_ok(count($alerts)===1&&(int)$alerts[0]['recipient_id']===2&&!str_contains(json_encode($alerts),'PRIVATE_SCHEDULED'),'publication notifies only eligible unmuted followers with generic text');
az_creator_club_mark_read($fan,$submitted['id'],true,$db);c160_ok(az_creator_club_feed_page($fan,['view'=>'unread'],$db)['posts']===[],'published post can be marked read persistently');
$again=az_creator_club_publish_due($db,50,'cron');c160_ok($again['published']===0&&(int)c160_scalar($db,"SELECT COUNT(*) FROM commerce_activity WHERE kind='club_post_published'")===1,'repeated cron does not republish or notify twice');
c160_ok(!empty(az_creator_club_publish_status($db)['completedAt']),'completed cron records a real heartbeat');

// Parsing refuses normalization, nonexistent and ambiguous times instead of guessing.
foreach([
    ['2030-02-30T12:00','UTC'],['2030-03-10T02:30','America/New_York'],
    ['2030-11-03T01:30','America/New_York'],['2030-07-01T12:00','Mars/Olympus'],
    ['tomorrow noon','UTC'],['2030-07-01T12:00Z','UTC'],
] as [$local,$zone])c160_denied(fn()=>az_creator_club_save_post($creator,['title'=>'Invalid schedule','audience'=>'followers','publishLocal'=>$local,'publishTimezone'=>$zone],false,$db),400,'invalid or ambiguous local schedule '.$local.' '.$zone);
$past=az_creator_club_save_post($creator,['title'=>'Late approval','body'=>'Approved after its requested time.','audience'=>'followers','rightsConfirmed'=>true,'publishLocal'=>'2020-01-01T12:00','publishTimezone'=>'UTC'],true,$db);
c160_ok(az_creator_club_review($admin,'post',$past['id'],$past['revision'],'approve',$db)['state']==='approved','approval after the scheduled time publishes immediately');

// Withdraw cancels a schedule; suspension blocks due publication until authorization is restored.
$scheduled=az_creator_club_save_post($creator,['title'=>'Cancel me','body'=>'Will be withdrawn.','audience'=>'followers','rightsConfirmed'=>true,'publishLocal'=>'2030-07-01T12:00','publishTimezone'=>'UTC'],true,$db);
$scheduled=az_creator_club_review($admin,'post',$scheduled['id'],$scheduled['revision'],'approve',$db);
az_creator_club_withdraw($creator,$scheduled['id'],$scheduled['revision'],$db);
c160_ok(c160_row($db,$scheduled['id'])['state']==='withdrawn'&&c160_row($db,$scheduled['id'])['publish_at']===null,'withdraw clears the pending release time');
$blocked=az_creator_club_save_post($creator,['title'=>'Blocked release','body'=>'Retry only after the creator is active.','audience'=>'followers','rightsConfirmed'=>true,'publishLocal'=>'2030-07-01T12:00','publishTimezone'=>'UTC'],true,$db);
$blocked=az_creator_club_review($admin,'post',$blocked['id'],$blocked['revision'],'approve',$db);
$db->prepare("UPDATE creator_club_posts SET publish_at='2020-01-01T00:00:00Z' WHERE id=?")->execute([$blocked['id']]);$db->exec("UPDATE users SET status='disabled' WHERE id=1");
$result=az_creator_club_publish_due($db,50,'cron');$blockedRow=c160_row($db,$blocked['id']);
c160_ok($result['blocked']===1&&$blockedRow['state']==='scheduled'&&$blockedRow['published_at']===null&&!empty($blockedRow['publish_error_code']),'disabled creator leaves a retryable private schedule with a useful blocker');
$db->exec("UPDATE users SET status='active' WHERE id=1");c160_ok(az_creator_club_publish_due($db,50,'cron')['published']===1,'restoring creator eligibility allows one due release');

// Publication and its fan outbox are atomic: delivery storage failure cannot leak a release.
$atomic=az_creator_club_save_post($creator,['title'=>'Atomic release','body'=>'Outbox must commit with publication.','audience'=>'followers','rightsConfirmed'=>true,'publishLocal'=>'2030-07-01T12:00','publishTimezone'=>'UTC'],true,$db);
$atomic=az_creator_club_review($admin,'post',$atomic['id'],$atomic['revision'],'approve',$db);
$db->prepare("UPDATE creator_club_posts SET publish_at='2020-01-01T00:00:00Z' WHERE id=?")->execute([$atomic['id']]);
$db->exec("CREATE TRIGGER c160_outbox_failure BEFORE INSERT ON commerce_activity WHEN NEW.kind='club_post_published' BEGIN SELECT RAISE(ABORT,'outbox unavailable'); END");
$failed=az_creator_club_publish_due($db,50,'cron');
c160_ok($failed['published']===0&&c160_row($db,$atomic['id'])['state']==='scheduled'&&c160_row($db,$atomic['id'])['published_at']===null,'outbox failure rolls back publication');
$db->exec('DROP TRIGGER c160_outbox_failure');c160_ok(az_creator_club_publish_due($db,50,'cron')['published']===1,'a failed atomic publication can be retried once');

// Independent edits to a private video invalidate the reviewed version at release and on every fan read.
$db=c160_db();az_creator_club_follow($fan,1,true,$db);$mediaId=str_repeat('b',32);
$db->prepare("INSERT INTO personal_media(id,user_id,storage_name,file_name,mime_type) VALUES(?,1,'versioned.bin','versioned.mp4','video/mp4')")->execute([$mediaId]);
$versioned=az_creator_club_save_post($creator,['title'=>'Reviewed video revision','body'=>'','mediaId'=>$mediaId,'audience'=>'followers','rightsConfirmed'=>true,'publishLocal'=>'2030-07-01T12:00','publishTimezone'=>'UTC'],true,$db);
$versioned=az_creator_club_review($admin,'post',$versioned['id'],$versioned['revision'],'approve',$db);
$db->prepare('UPDATE personal_media SET revision=revision+1 WHERE id=?')->execute([$mediaId]);
$db->prepare("UPDATE creator_club_posts SET publish_at='2020-01-01T00:00:00Z' WHERE id=?")->execute([$versioned['id']]);
$mediaDue=az_creator_club_publish_due($db,50,'cron');$versionedRow=c160_row($db,$versioned['id']);
c160_ok($mediaDue['published']===0&&$mediaDue['blocked']===1&&$versionedRow['state']==='scheduled'&&$versionedRow['publish_error_code']==='media_changed','editing reviewed media blocks the scheduled release until fresh review');
c160_denied(fn()=>az_creator_club_post_row($versioned['id'],$fan,$db),404,'changed scheduled media remains private');
$freshMedia=az_creator_club_save_post($creator,['title'=>'Freshly reviewed video','body'=>'','mediaId'=>$mediaId,'audience'=>'followers','rightsConfirmed'=>true],true,$db);
$freshMedia=az_creator_club_review($admin,'post',$freshMedia['id'],$freshMedia['revision'],'approve',$db);
c160_ok((int)az_creator_club_post_row($freshMedia['id'],$fan,$db)['media_revision']===2,'fresh approval releases the exact current media revision');
$db->prepare('UPDATE personal_media SET revision=revision+1 WHERE id=?')->execute([$mediaId]);
c160_denied(fn()=>az_creator_club_post_row($freshMedia['id'],$fan,$db),404,'media edits after publication immediately revoke fan lookup');
$mediaFeed=az_creator_club_feed_page($fan,[],$db);
c160_ok($mediaFeed['posts']===[]&&$mediaFeed['unreadCount']===0,'changed published media disappears from eligible feed and unread count');

// The automatic heartbeat follows the configured host cadence; manual runs cannot make it healthy.
$db=c160_db();
$health=az_creator_club_publish_status($db);
c160_ok($health['expectedIntervalMinutes']===15&&$health['staleAfterSeconds']===2100,'default host cadence is fifteen minutes with a thirty-five-minute completion threshold');
az_creator_club_publish_due($db,50,'manual');$health=az_creator_club_publish_status($db);
c160_ok($health['status']==='not_run'&&$health['completedAt']===null&&!empty($health['manual']['completedAt']),'manual completion leaves an unverified automatic heartbeat unverified');
az_creator_club_publish_due($db,50,'cron');
$completeRunAgo=static function(int $seconds)use($db):string{
    $completedAt=gmdate('Y-m-d\TH:i:s\Z',time()-$seconds);
    $db->prepare("UPDATE creator_club_publish_runs SET running=0,started_at=?,completed_at=?,error_at=NULL WHERE scope='cron'")->execute([$completedAt,$completedAt]);
    return $completedAt;
};
$completeRunAgo(12*60);
c160_ok(az_creator_club_publish_status($db)['status']==='ok','a real twelve-minute-old cron completion remains healthy under the default fifteen-minute schedule');
$completeRunAgo(34*60);
c160_ok(az_creator_club_publish_status($db)['status']==='ok','default cadence does not become stale before its thirty-five-minute allowance');
$completeRunAgo(36*60);
c160_ok(az_creator_club_publish_status($db)['status']==='stale','default cadence reports a completion older than thirty-five minutes as stale');
foreach([[1,1,600,9*60,12*60],['1',1,600,9*60,12*60],[60,60,7500,120*60,126*60],['60',60,7500,120*60,126*60]] as [$configured,$minutes,$threshold,$healthyAge,$staleAge]){
    $GLOBALS['az_config_cache']['creator_club']=['publish_interval_minutes'=>$configured];
    $completeRunAgo($healthyAge);$health=az_creator_club_publish_status($db);
    c160_ok($health['expectedIntervalMinutes']===$minutes&&$health['staleAfterSeconds']===$threshold&&$health['status']==='ok','integer and integer-string cadence '.$configured.' preserves a timely completed job');
    $completeRunAgo($staleAge);
    c160_ok(az_creator_club_publish_status($db)['status']==='stale','configured cadence '.$configured.' still detects a genuinely overdue completion');
}
$completeRunAgo(12*60);
foreach([0,61,-1,true,false,[],['minutes'=>1],1.0,1.5,'1.0','1e1','invalid','',null] as $invalidCadence){
    $GLOBALS['az_config_cache']['creator_club']=['publish_interval_minutes'=>$invalidCadence];
    $health=az_creator_club_publish_status($db);
    c160_ok($health['expectedIntervalMinutes']===15&&$health['staleAfterSeconds']===2100&&$health['status']==='ok','invalid cadence '.json_encode($invalidCadence).' falls back to the safe fifteen-minute default');
}
foreach([true,'invalid',60] as $invalidSection){
    $GLOBALS['az_config_cache']['creator_club']=$invalidSection;$health=az_creator_club_publish_status($db);
    c160_ok($health['expectedIntervalMinutes']===15&&$health['status']==='ok','malformed creator_club configuration safely uses the default cadence');
}
$GLOBALS['az_config_cache']['creator_club']=['publish_interval_minutes'=>60];
$db->prepare("UPDATE creator_club_publish_runs SET running=1,started_at=? WHERE scope='cron'")->execute([gmdate('Y-m-d\TH:i:s\Z',time()-9*60)]);
c160_ok(az_creator_club_publish_status($db)['status']==='running','a recently started hourly job is reported as running');
$db->prepare("UPDATE creator_club_publish_runs SET started_at=? WHERE scope='cron'")->execute([gmdate('Y-m-d\TH:i:s\Z',time()-11*60)]);
c160_ok(az_creator_club_publish_status($db)['status']==='stale','a stuck job becomes stale after ten minutes even when scheduled hourly');
$GLOBALS['az_config_cache']['creator_club']=['publish_interval_minutes'=>1];$staleCompletion=$completeRunAgo(12*60);
az_creator_club_publish_due($db,50,'manual');$health=az_creator_club_publish_status($db);
c160_ok($health['status']==='stale'&&$health['completedAt']===$staleCompletion&&$health['manual']['status']==='ok','a fresh successful manual check never refreshes or hides a stale automatic heartbeat');

// Production defaults never turn test access or billing on while adding the new workflows.
$db=c160_db();$tier=az_creator_club_tier($creator,'Disabled test tier','No active checkout',1200,$db);az_creator_club_review($admin,'tier',$tier['id'],1,'approve',$db);
c160_denied(fn()=>az_creator_club_test_grant($admin,2,1,$tier['id'],7,$db),403,'test grants remain disabled by default');
c160_ok((int)c160_scalar($db,'SELECT COUNT(*) FROM creator_club_entitlements')===0,'disabled test grants create no entitlement');
echo "AZHottie v1.60 feed, discovery, durable reads, drafts and publication integration: PASS\n";
