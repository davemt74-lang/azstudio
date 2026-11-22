<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){fwrite(STDERR,"SQLite PDO unavailable\n");exit(2);}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');
$GLOBALS['az_database']=$db;
$db->exec("CREATE TABLE studio_schema(id INTEGER PRIMARY KEY,version INTEGER); INSERT INTO studio_schema VALUES(1,34);
CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,email TEXT,status TEXT,revision INTEGER,created_at TEXT,last_login_at TEXT);
CREATE TABLE user_roles(user_id INTEGER,role TEXT,PRIMARY KEY(user_id,role));
CREATE TABLE user_role_requests(user_id INTEGER,role TEXT,status TEXT);
CREATE TABLE model_profiles(id TEXT PRIMARY KEY,linked_user_id INTEGER,status TEXT,stage_name TEXT);
CREATE TABLE personal_media(id TEXT PRIMARY KEY,user_id INTEGER NOT NULL,duration_ms INTEGER,mime_type TEXT,size_bytes INTEGER,file_name TEXT,storage_name TEXT,cover_storage_name TEXT,cover_mime_type TEXT,revision INTEGER);
CREATE TABLE model_videos(id TEXT PRIMARY KEY,user_id INTEGER NOT NULL,media_id TEXT NOT NULL,title TEXT,description TEXT,status TEXT,review_note TEXT,public_credit TEXT,rights_confirmed INTEGER,revision INTEGER,submitted_at TEXT,published_at TEXT,reviewed_at TEXT,reviewed_by INTEGER,created_at TEXT,updated_at TEXT,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE);
CREATE TABLE published_video_engagement_daily(video_kind TEXT,video_id TEXT,view_day TEXT,play_starts INTEGER,player_completions INTEGER,watched_seconds INTEGER,PRIMARY KEY(video_kind,video_id,view_day));
CREATE TABLE finished_videos(id TEXT PRIMARY KEY,interview_id TEXT,owner_id INTEGER,model_id TEXT,fan_published_at TEXT,archived_at TEXT,fan_title TEXT);
CREATE TABLE interviews(id TEXT PRIMARY KEY,owner_id INTEGER,status TEXT);
CREATE TABLE agent_workspace_events(id TEXT PRIMARY KEY,recipient_id INTEGER,actor_id INTEGER,kind TEXT,title TEXT,detail TEXT,href TEXT,created_at TEXT,read_at TEXT);");
$db->exec("INSERT INTO users VALUES(1,'Model One','one@test.invalid','active',1,'now',NULL),(2,'Model Two','two@test.invalid','active',1,'now',NULL),(3,'Administrator','admin@test.invalid','active',1,'now',NULL);
INSERT INTO user_roles VALUES(1,'model'),(2,'model'),(3,'admin');");
$vid=str_repeat('a',32);$vid2=str_repeat('b',32);$media=str_repeat('c',32);$media2=str_repeat('d',32);
$db->prepare('INSERT INTO personal_media VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$media,1,5000,'video/mp4',32,'clip.mp4',$media.'.bin',null,null,1]);
$db->prepare('INSERT INTO personal_media VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$media2,2,5000,'video/mp4',32,'clip2.mp4',$media2.'.bin',null,null,1]);
$db->prepare("INSERT INTO model_videos VALUES(?,?,?,?,?,'pending_review','',?,1,1,?,NULL,NULL,NULL,?,?)")
   ->execute([$vid,1,$media,'Creator One','A verified video','',az_now(),az_now(),az_now()]);
$db->prepare("INSERT INTO model_videos VALUES(?,?,?,?,?,'published','',?,1,2,?,?,?,3,?,?)")
   ->execute([$vid2,2,$media2,'Creator Two','Another verified video','Stage Two',az_now(),az_now(),az_now(),az_now(),az_now()]);
az_prepare_storage();$file=AZ_STORAGE.'/media/'.$media.'.bin';$file2=AZ_STORAGE.'/media/'.$media2.'.bin';file_put_contents($file,'fixture');file_put_contents($file2,'fixture');
$okCount=0;$ok=static function(bool $value,string $description)use(&$okCount){$okCount++;if(!$value)throw new RuntimeException('Publishing integration FAIL: '.$description);};
try {
    az_upgrade_agent_publishing_schema($db);$ok(az_schema_version($db)===35,'schema migrated');az_upgrade_agent_publishing_schema($db);$ok(az_schema_version($db)===35,'migration replay safe');
    $row=az_model_video_any_row($vid);
    $future=(new DateTimeImmutable('+1 day',new DateTimeZone('America/Phoenix')))->format('Y-m-d\TH:i');
    $scheduled=az_model_video_moderate(['id'=>$vid,'revision'=>1,'decision'=>'schedule','publishAt'=>$future],3);
    $ok($scheduled['status']==='scheduled'&&$scheduled['scheduledAt']!==null,'admin approval creates scheduled private post');
    $private=false;try{az_model_video_public_row($vid);}catch(AzHttpError $e){$private=$e->status===404;}$ok($private,'scheduled is not publicly playable');
    $q=$db->prepare('UPDATE publishing_agent_schedules SET publish_at=? WHERE video_id=?');$q->execute([gmdate('Y-m-d\TH:i:s\Z',time()-60),$vid]);
    $scan=az_publishing_scan();$ok($scan['published']===1,'cron publishes exactly one previously approved video');
    $ok(az_model_video_public_row($vid)['status']==='published','public only after cron');
    $again=az_publishing_scan();$ok($again['published']===0,'cron replay idempotent');
    $privateUser=az_publishing_analytics(['id'=>2,'roles'=>['model']]);$ok($privateUser['current']['starts']===0,'other model analytics scoped');
    $promo=az_publishing_promo_create(['videoId'=>$vid,'channel'=>'onsite','headline'=>'Featured interview','copy'=>'A verified story on AZHottie.'],3);
    $ok($promo['state']==='draft' && az_publishing_public_feature()===null,'draft never appears in Watch');
    $approved=az_publishing_promo_action(['id'=>$promo['id'],'decision'=>'approve'],3);
    $ok($approved['state']==='featured' && az_publishing_public_feature()['headline']==='Featured interview','feature needs explicit approval');
    $ok(count(az_publishing_promo_list(['id'=>2,'roles'=>['model']]))===0,'other model sees no private promotion drafts');
    $ok(count(az_publishing_promo_list(['id'=>3,'roles'=>['admin']]))===1,'admin can review promotion');
    $db->prepare('UPDATE model_videos SET status=? WHERE id=?')->execute(['draft',$vid]);
    $ok(az_publishing_public_feature()===null,'withdrawal immediately removes feature from public feed');
    $retired=az_publishing_promo_action(['id'=>$promo['id'],'decision'=>'retire'],3);$ok($retired['state']==='retired','feature retired');
    $db->prepare("UPDATE model_videos SET status='pending_review',revision=10,rights_confirmed=1 WHERE id=?")->execute([$vid]);
    $future=(new DateTimeImmutable('+2 days',new DateTimeZone('America/Phoenix')))->format('Y-m-d\TH:i');
    $scheduled=az_model_video_moderate(['id'=>$vid,'revision'=>10,'decision'=>'schedule','publishAt'=>$future],3);
    $withdrawn=az_model_video_withdraw(['id'=>$vid,'revision'=>$scheduled['revision']],1);
    $ok($withdrawn['status']==='draft' && az_publishing_schedule_lookup($vid)['state']==='cancelled','owner withdrawal revokes scheduled approval');
    $q=$db->prepare("UPDATE publishing_agent_schedules SET publish_at=? WHERE video_id=?");$q->execute([gmdate('Y-m-d\TH:i:s\Z',time()-60),$vid]);
    $ok(az_publishing_scan()['published']===0,'cancelled release never publishes');
    $db->prepare("UPDATE model_videos SET status='pending_review',rights_confirmed=1,revision=20 WHERE id=?")->execute([$vid]);
    $scheduled=az_model_video_moderate(['id'=>$vid,'revision'=>20,'decision'=>'schedule','publishAt'=>$future],3);
    $db->prepare("UPDATE publishing_agent_schedules SET publish_at=? WHERE video_id=?")->execute([gmdate('Y-m-d\TH:i:s\Z',time()-60),$vid]);
    unlink($file);
    $bad=az_publishing_scan();$ok($bad['blocked']===1,'missing source prevents publication');
    $ok(az_publishing_schedule_lookup($vid)['state']==='blocked','blocked schedule recorded once');
    $ok(az_publishing_scan()['blocked']===0,'blocked release not retried indefinitely');
    $q=$db->query('SELECT COUNT(*) FROM agent_workspace_events');$ok((int)$q->fetchColumn()>0,'History records real changes');
    echo "Publishing specialist PDO SQLite integration: $okCount passed\n";
} finally{if(is_file($file))unlink($file);if(is_file($file2))unlink($file2);}
