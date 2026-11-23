<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
function action_assert(bool $value,string $why):void{if(!$value)throw new RuntimeException($why);}
function action_reject(callable $fn,string $code):void{
    try{$fn();throw new RuntimeException('Expected rejection: '.$code);}
    catch(AzHttpError $e){action_assert($e->errorCode===$code,'Wrong error code: '.$e->errorCode);}
}
$now=strtotime('2026-11-23T16:00:00Z');
$at=static fn(int $offset):string=>gmdate('Y-m-d\TH:i:s\Z',$now+$offset);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE studio_schema(id INTEGER PRIMARY KEY,version INTEGER NOT NULL)');
$db->exec('INSERT INTO studio_schema VALUES(1,45)');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT NOT NULL,status TEXT NOT NULL)');
$db->exec("INSERT INTO users VALUES(1,'Alpha Admin','active'),(2,'Bravo Admin','active'),(3,'Model Account','active'),(4,'Inactive Admin','suspended')");
$db->exec('CREATE TABLE user_roles(user_id INTEGER,role TEXT,PRIMARY KEY(user_id,role))');
$db->exec("INSERT INTO user_roles VALUES(1,'admin'),(2,'admin'),(3,'model'),(4,'admin')");
$db->exec('CREATE TABLE casting_leads(id TEXT PRIMARY KEY,status TEXT,updated_at TEXT,private_answers TEXT)');
$db->exec('CREATE TABLE model_videos(id TEXT PRIMARY KEY,status TEXT,submitted_at TEXT,updated_at TEXT,private_file TEXT)');
$db->exec('CREATE TABLE agent_workspace_events(id TEXT PRIMARY KEY,recipient_id INTEGER,actor_id INTEGER,kind TEXT,title TEXT,detail TEXT,href TEXT,created_at TEXT,read_at TEXT)');
az_upgrade_admin_action_schema($db);
action_assert(az_schema_version($db)===46,'Schema 45->46 failed');
az_upgrade_admin_action_schema($db);
$required=['admin_action_tasks','admin_action_history','admin_action_reminders'];
foreach($required as $table)action_assert((int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='$table'")->fetchColumn()===1,'Missing '+$table);
$cast=str_repeat('a',32);$video=str_repeat('b',32);$recent=str_repeat('c',32);
$db->prepare('INSERT INTO casting_leads VALUES(?,?,?,?)')->execute([$cast,'new',$at(-100*3600),'PRIVATE_APPLICANT_NAME and screening answers']);
$db->prepare('INSERT INTO casting_leads VALUES(?,?,?,?)')->execute([$recent,'new',$at(-3600),'PRIVATE_UNREVIEWED_APPLICATION']);
$db->prepare('INSERT INTO model_videos VALUES(?,?,?,?,?)')->execute([$video,'pending_review',$at(-80*3600),$at(-7200),'PRIVATE_UNPUBLISHED_VIDEO']);
$GLOBALS['az_database']=$db;
$admin=['id'=>1,'status'=>'active','roles'=>['admin']];
$model=['id'=>3,'status'=>'active','roles'=>['model']];
action_reject(static fn()=>az_admin_action_snapshot($model,$db),'role_required');
action_reject(static fn()=>az_admin_action_change($model,['id'=>$cast,'revision'=>1,'operation'=>'resolve'],$db,$now),'role_required');
action_assert(count(az_admin_action_staff($db))===2,'Inactive or non-admin staff were exposed');
$first=az_admin_action_scan(1,$db,$now);
action_assert($first['created']===2&&$first['reminded']===0,'Initial scan did not track aged items safely');
$again=az_admin_action_scan(1,$db,$now);
action_assert($again['created']===0&&$again['reminded']===0,'Repeated scan created duplicates');
$view=az_admin_action_snapshot($admin,$db);
action_assert($view['counts']['open']===2&&count($view['tasks'])===2,'Task counts or staff view wrong');
$json=json_encode($view,JSON_THROW_ON_ERROR);
foreach(['PRIVATE_APPLICANT','PRIVATE_UNPUBLISHED','PRIVATE_UNREVIEWED'] as $secret){
    action_assert(!str_contains($json,$secret),'Sensitive workflow data copied to Action Center');
}
$byKind=[];foreach($view['tasks'] as $t)$byKind[$t['sourceKind']]=$t;
$castTask=$byKind['casting'];$videoTask=$byKind['video'];
action_assert($castTask['assigneeId']===1&&$castTask['revision']===1,'First task ownership/revision incorrect');
action_assert(count($view['history'][$castTask['id']])===1,'Creation audit trail missing');
action_reject(static fn()=>az_admin_action_date('2026-02-30T12:00:00Z',$now),'invalid_due_date');
action_reject(static fn()=>az_admin_action_date('not-a-time',$now),'invalid_due_date');
action_reject(static fn()=>az_admin_action_change($admin,[
    'id'=>$castTask['id'],'revision'=>1,'operation'=>'assign',
    'assigneeId'=>3,'dueAt'=>$at(2*3600)],$db,$now),'assignee_not_allowed');
action_reject(static fn()=>az_admin_action_change($admin,[
    'id'=>$castTask['id'],'revision'=>1,'operation'=>'assign',
    'assigneeId'=>4,'dueAt'=>$at(2*3600)],$db,$now),'assignee_not_allowed');
$ack=az_admin_action_change($admin,['id'=>$castTask['id'],'revision'=>1,'operation'=>'acknowledge'],$db,$now);
action_assert($ack['state']==='acknowledged'&&$ack['revision']===2,'Acknowledgment failed');
action_reject(static fn()=>az_admin_action_change($admin,['id'=>$castTask['id'],'revision'=>1,'operation'=>'resolve','note'=>'We checked source status.'],$db,$now),'revision_conflict');
$assign=az_admin_action_change($admin,[
 'id'=>$castTask['id'],'revision'=>2,'operation'=>'assign','assigneeId'=>2,'dueAt'=>$at(7200),
 'note'=>'Review assigned to available staff'], $db,$now);
action_assert($assign['assigneeId']===2&&$assign['dueAt']===$at(7200)&&$assign['revision']===3,'Owner/due update failed');
$atDue=az_admin_action_scan(1,$db,$now+10800);
action_assert($atDue['reminded']===1,'Due task should remind assigned staff once');
action_assert(az_admin_action_scan(1,$db,$now+10800)['reminded']===0,'Repeated due scan sent duplicate notification');
$overdue=az_admin_action_scan(1,$db,$now+28*3600);
action_assert($overdue['reminded']===2,'Overdue and independently due tasks should both remind');
$remindCount=(int)$db->query('SELECT COUNT(*) FROM admin_action_reminders')->fetchColumn();
action_assert($remindCount===3,'Durable reminder ledger unexpected');
$db->prepare('UPDATE casting_leads SET status=? WHERE id=?')->execute(['approved',$cast]);
$later=az_admin_action_scan(1,$db,$now+54*3600);
action_assert($later['reminded']===1,'Closed original casting workflow must suppress task reminders');
action_reject(static fn()=>az_admin_action_change($admin,['id'=>$castTask['id'],'revision'=>3,'operation'=>'resolve','note'=>'short'],$db,$now),'resolution_note_required');
$resolved=az_admin_action_change($admin,['id'=>$castTask['id'],'revision'=>3,'operation'=>'resolve',
 'note'=>'Reviewed and confirmed original application was handled.'],$db,$now);
action_assert($resolved['state']==='resolved'&&$resolved['revision']===4,'Resolution transition failed');
action_reject(static fn()=>az_admin_action_change($admin,['id'=>$castTask['id'],'revision'=>4,'operation'=>'acknowledge'],$db,$now),'invalid_transition');
action_reject(static fn()=>az_admin_action_change($admin,['id'=>$castTask['id'],'revision'=>4,'operation'=>'assign','assigneeId'=>2,'dueAt'=>$at(5000)],$db,$now),'invalid_transition');
$opened=az_admin_action_change($admin,['id'=>$castTask['id'],'revision'=>4,'operation'=>'reopen',
 'note'=>'Source status needs further staff review.'],$db,$now);
action_assert($opened['state']==='open'&&$opened['revision']===5,'Reopen transition failed');
$history=$db->prepare('SELECT action FROM admin_action_history WHERE task_id=? ORDER BY created_at,id');
$history->execute([$castTask['id']]);
$actions=$history->fetchAll(PDO::FETCH_COLUMN);
sort($actions);
action_assert($actions===['acknowledge','assign','created','reopen','resolve'],'Action Center audit history incomplete');
$view2=az_admin_action_snapshot($admin,$db);
action_assert($view2['counts']['open']===2&&$view2['counts']['acknowledged']===0,'Task summary was not refreshed');
$chat=az_admin_action_chat_summary($admin,$db);
action_assert(count($chat['topTasks'])===2&&isset($chat['basis']),'Safe Agent Chat summary missing');
$activity=$db->query("SELECT kind,recipient_id,read_at,detail FROM agent_workspace_events")->fetchAll(PDO::FETCH_ASSOC);
action_assert(count($activity)>=4,'Assignment/due notices missing from existing Agent activity');
foreach($activity as $entry)action_assert(!str_contains($entry['detail'],'PRIVATE_'),'Private workflow content leaked into Agent activity');
action_assert((int)$db->query('SELECT COUNT(*) FROM admin_action_tasks')->fetchColumn()===2,'Task scanner created duplicate source work');
print "Admin Action Center: schema 46, admin ownership, due dates, audit lifecycle, rev conflicts, privacy and deduplicated reminders PASS\n";
