<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
function check_management(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);}
$now=strtotime('2026-11-23T14:00:00Z');
$stamp=static fn(int $hours):string=>gmdate('Y-m-d\TH:i:s\Z',$now-$hours*3600);
$candidate=['id'=>str_repeat('a',32),'status'=>'new','updated_at'=>$stamp(25),
    'name'=>'PRIVATE_APPLICANT','answers_json'=>'PRIVATE_FORM'];
$rule=az_admin_intelligence_rule('casting',$candidate,$now);
check_management($rule!==null&&$rule['stage']==='new_24h'&&$rule['priority']===1,'24-hour casting warning missing');
$candidate['updated_at']=$stamp(73);
check_management(az_admin_intelligence_rule('casting',$candidate,$now)['stage']==='new_72h','72-hour casting escalation missing');
$candidate['updated_at']=$stamp(2);
check_management(az_admin_intelligence_rule('casting',$candidate,$now)===null,'Recent casting profile incorrectly escalated');
$candidate['status']='screening';$candidate['updated_at']=$stamp(168);
check_management(az_admin_intelligence_rule('casting',$candidate,$now)['stage']==='screening_7d','Stalled screening rule missing');
$candidate['status']='approved';
check_management(az_admin_intelligence_rule('casting',$candidate,$now)===null,'Approved applicant incorrectly screened');
$video=['id'=>str_repeat('b',32),'status'=>'pending_review','submitted_at'=>$stamp(25),'updated_at'=>$stamp(2)];
check_management(az_admin_intelligence_rule('video',$video,$now)['stage']==='video_24h','Pending video age should use submission date');
$video['submitted_at']=$stamp(73);
check_management(az_admin_intelligence_rule('video',$video,$now)['stage']==='video_72h','72-hour moderation reminder missing');
$video['status']='published';
check_management(az_admin_intelligence_rule('video',$video,$now)===null,'Published video incorrectly flagged');
$dashboard=[
    'casting'=>['new'=>3],'submissions'=>['pending'=>2],'pendingProviders'=>2,'pendingOrders'=>1,'pendingPayouts'=>1,
    'commerce'=>['checkout'=>['started'=>20,'paid'=>6]],
    'audience'=>['playStarts30d'=>90,'videoCompletions30d'=>10,'liveProductImpressions30d'=>50,'liveProductClicks30d'=>1]
];
$opportunities=az_admin_intelligence_opportunities($dashboard);
check_management(count($opportunities)===3,'Evidence-based commerce, video and live signals missing');
$ids=implode(' ',array_column($opportunities,'title'));
check_management(str_contains($ids,'Stripe checkout')&&str_contains($ids,'video engagement')&&str_contains($ids,'live-shopping'),'Opportunity categories not grounded');
$priorities=az_admin_intelligence_priorities($dashboard,['items'=>[]]);
check_management(count($priorities)>=5&&$priorities[0]['priority']===0,'Financial reconciliation not prioritized');
check_management($priorities[0]['href']==='commerce.php?tab=providers','Unsafe financial destination');
if(!in_array('sqlite',PDO::getAvailableDrivers(),true))throw new RuntimeException('pdo_sqlite is required');
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE studio_schema(id INTEGER PRIMARY KEY,version INTEGER NOT NULL)');
$db->exec('INSERT INTO studio_schema VALUES(1,44)');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT NOT NULL)');
$db->exec("INSERT INTO users VALUES(1,'Studio Administrator')");
$db->exec('CREATE TABLE casting_leads(id TEXT PRIMARY KEY,status TEXT,updated_at TEXT,private_answers TEXT)');
$db->exec('CREATE TABLE model_videos(id TEXT PRIMARY KEY,status TEXT,submitted_at TEXT,updated_at TEXT,private_video TEXT)');
$db->exec('CREATE TABLE agent_workspace_events(id TEXT PRIMARY KEY,recipient_id INTEGER,actor_id INTEGER,kind TEXT,title TEXT,detail TEXT,href TEXT,created_at TEXT,read_at TEXT)');
az_upgrade_admin_intelligence_schema($db);
check_management(az_schema_version($db)===45,'Database schema 44 to 45 migration failed');
az_upgrade_admin_intelligence_schema($db);
$ins=$db->prepare('INSERT INTO casting_leads VALUES(?,?,?,?)');
$ins->execute([str_repeat('a',32),'new',$stamp(94),'SECRET_APPLICANT_ANSWERS']);
$ins->execute([str_repeat('b',32),'new',$stamp(29),'SECRET_APPLICANT_ANSWERS']);
$ins->execute([str_repeat('c',32),'screening',$stamp(180),'SECRET_APPLICANT_ANSWERS']);
$ins->execute([str_repeat('d',32),'new',$stamp(1),'SECRET_APPLICANT_ANSWERS']);
$videoIns=$db->prepare('INSERT INTO model_videos VALUES(?,?,?,?,?)');
$videoIns->execute([str_repeat('e',32),'pending_review',$stamp(81),$stamp(10),'PRIVATE_VIDEO_METADATA']);
$videoIns->execute([str_repeat('f',32),'pending_review',$stamp(30),$stamp(12),'PRIVATE_VIDEO_METADATA']);
$videoIns->execute([str_repeat('0',32),'published',$stamp(100),$stamp(20),'PRIVATE_VIDEO_METADATA']);
$items=az_admin_intelligence_candidates($db,$now);
check_management(count($items['items'])===5,'Aged workflow detection missed expected rows');
check_management($items['items'][0]['priority']===0,'Urgent workflows must rank first');
$serialized=json_encode($items,JSON_THROW_ON_ERROR);
check_management(!str_contains($serialized,'SECRET_APPLICANT')&&!str_contains($serialized,'PRIVATE_VIDEO'),'Sensitive application or media content leaked');
$GLOBALS['az_database']=$db;
$admin=['id'=>1,'status'=>'active','roles'=>['admin']];
$report=az_admin_intelligence_briefing($admin,$dashboard,$db,$now);
check_management($report['role']==='admin'&&!$report['automatedDecisions'],'Report must never claim approvals');
check_management($report['counts']['agedWorkflowItems']===5,'Admin aged workflow aggregate incorrect');
$json=json_encode(az_admin_intelligence_chat_snapshot($admin,$report),JSON_THROW_ON_ERROR);
check_management(!str_contains($json,'SECRET_APPLICANT')&&!str_contains($json,'PRIVATE_VIDEO'),'Private applicant or video details entered Chat');
try{az_admin_intelligence_briefing(['id'=>2,'roles'=>['model']],$dashboard,$db,$now);throw new RuntimeException('Creator accessed administrative briefing');}
catch(AzHttpError $e){check_management($e->status===403,'Unexpected role denial');}
$first=az_admin_intelligence_scan(1,200,$now,$db);
check_management($first['delivered']===5,'First scan should deliver all five aged reminders');
$again=az_admin_intelligence_scan(1,200,$now,$db);
check_management($again['delivered']===0,'Repeated scan duplicated notifications');
$events=$db->query("SELECT * FROM agent_workspace_events")->fetchAll(PDO::FETCH_ASSOC);
check_management(count($events)===5,'Notifications did not land in shared Agent workspace');
foreach($events as $event){
    check_management(str_starts_with($event['kind'],'admin_intelligence_')&&$event['read_at']===null,'Agent attribution/unread semantics broken');
    check_management(!str_contains(json_encode($event),'PRIVATE_')&&!str_contains(json_encode($event),'SECRET_'),'Notification leaked sensitive data');
}
$escalate=az_admin_intelligence_scan(1,200,$now+48*3600,$db);
check_management($escalate['delivered']===3,'Two 72-hour escalations plus a newly aged 24-hour casting case should generate three reminders');
$third=az_admin_intelligence_scan(1,200,$now+48*3600,$db);
check_management($third['delivered']===0,'Escalated status duplicated');
check_management((int)$db->query('SELECT COUNT(*) FROM admin_intelligence_deliveries')->fetchColumn()===8,'Durable delivery ledger count incorrect');
print "Management Intelligence rules, privacy, schema 45, unique alerts and escalation: PASS\n";
