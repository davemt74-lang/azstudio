<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
function coord_ok(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function coord_reject(callable $fn,string $code):void{
    try{$fn();throw new RuntimeException('Expected '.$code);}
    catch(AzHttpError $e){coord_ok($e->errorCode===$code,'Wrong response: '.$e->errorCode);}
}
$now=time();$at=static fn(int $sec):string=>gmdate('Y-m-d\TH:i:s\Z',$now+$sec);
$pid=str_repeat('a',32);$sid=str_repeat('b',32);$aid=str_repeat('c',32);
$base=['id'=>$aid,'project_id'=>$pid,'status'=>'assigned',
   'updated_at'=>$at(-3*86400),'submitted_at'=>null,'due_at'=>$at(5*86400)];
$r=az_coordination_assignment_rule($base,$now);
coord_ok($r['sourceStage']==='production_response_waiting'&&$r['priority']===1,'Stalled assignment response not identified');
$r=az_coordination_assignment_rule(array_merge($base,['status'=>'accepted','due_at'=>$at(12*3600)]),$now);
coord_ok($r['sourceStage']==='production_assignment_due'&&$r['priority']===1,'Upcoming deadline missing');
$r=az_coordination_assignment_rule(array_merge($base,['status'=>'accepted','due_at'=>$at(-3600)]),$now);
coord_ok($r['priority']===0,'Overdue assignment must be urgent');
$r=az_coordination_assignment_rule(array_merge($base,['status'=>'submitted','submitted_at'=>$at(-2*86400)]),$now);
coord_ok($r['sourceStage']==='production_review_waiting','Submitted work waiting review missing');
$r=az_coordination_assignment_rule(array_merge($base,['status'=>'changes_requested']),$now);
coord_ok($r['sourceStage']==='production_retake_waiting','Retake delay missing');
coord_ok(az_coordination_assignment_rule(array_merge($base,['status'=>'approved']),$now)===null,'Approved assignment falsely blocked');
coord_ok(az_coordination_unstaffed_rule(['id'=>$sid,'project_id'=>$pid,'due_at'=>$at(8*86400)],$now)===null,
 'Unstaffed shot beyond seven days should not alert');
if(!in_array('sqlite',PDO::getAvailableDrivers(),true))throw new RuntimeException('pdo_sqlite required');
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE studio_schema(id INTEGER PRIMARY KEY,version INTEGER NOT NULL)');
$db->exec('INSERT INTO studio_schema VALUES(1,45)');
$db->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,status TEXT)");
$db->exec("INSERT INTO users VALUES(1,'Studio Operator','active'),(2,'Operations Lead','active'),(3,'Model Account','active')");
$db->exec('CREATE TABLE user_roles(user_id INTEGER,role TEXT,PRIMARY KEY(user_id,role))');
$db->exec("INSERT INTO user_roles VALUES(1,'admin'),(2,'admin'),(3,'model')");
$db->exec('CREATE TABLE agent_workspace_events(id TEXT PRIMARY KEY,recipient_id INTEGER,actor_id INTEGER,kind TEXT,title TEXT,detail TEXT,href TEXT,created_at TEXT,read_at TEXT)');
$db->exec('CREATE TABLE casting_leads(id TEXT PRIMARY KEY,status TEXT,updated_at TEXT)');
$db->exec('CREATE TABLE model_videos(id TEXT PRIMARY KEY,status TEXT,submitted_at TEXT,updated_at TEXT)');
$db->exec('CREATE TABLE production_projects(id TEXT PRIMARY KEY,status TEXT,owner_id INTEGER,title TEXT)');
$db->exec('CREATE TABLE production_shots(id TEXT PRIMARY KEY,project_id TEXT,title TEXT,status TEXT,mode TEXT,due_at TEXT)');
$db->exec('CREATE TABLE shot_assignments(id TEXT PRIMARY KEY,shot_id TEXT,user_id INTEGER,status TEXT,updated_at TEXT,submitted_at TEXT,reviewed_at TEXT)');
$db->exec('CREATE TABLE schedule_events(id TEXT PRIMARY KEY,status TEXT,start_at TEXT)');
$db->exec('CREATE TABLE schedule_appointments(id TEXT PRIMARY KEY,status TEXT,professional_id INTEGER,start_at TEXT,end_at TEXT)');
az_upgrade_admin_action_schema($db);
$GLOBALS['az_database']=$db;
$db->prepare('INSERT INTO production_projects VALUES(?,?,?,?)')->execute([$pid,'active',1,'PRIVATE_PRODUCTION_TITLE']);
$pid2=str_repeat('d',32);
$db->prepare('INSERT INTO production_projects VALUES(?,?,?,?)')->execute([$pid2,'archived',1,'Archived']);
$shots=[
 [$sid,$pid,'Shot A','active','assigned',$at(-3600)],
 [str_repeat('e',32),$pid,'Shot B','active','assigned',$at(2*86400)],
 [str_repeat('f',32),$pid,'Shot C','active','assigned',$at(5*86400)],
 [str_repeat('0',32),$pid,'Shot D','active','assigned',$at(6*86400)],
 [str_repeat('1',32),$pid,'Shot E','active','assigned',$at(5*86400)],
 [str_repeat('2',32),$pid,'Unstaffed shot','active','assigned',$at(48*3600)],
 [str_repeat('3',32),$pid,'Inactive shot','archived','assigned',$at(100)],
 [str_repeat('4',32),$pid2,'Archived project shot','active','assigned',$at(100)]
];
$shotQ=$db->prepare('INSERT INTO production_shots VALUES(?,?,?,?,?,?)');
foreach($shots as $row)$shotQ->execute($row);
$assignments=[
 [$aid,$sid,3,'accepted',$at(-3600),null,null],
 [str_repeat('5',32),str_repeat('e',32),3,'submitted',$at(-100),$at(-2*86400),null],
 [str_repeat('6',32),str_repeat('f',32),3,'changes_requested',$at(-3*86400),null,null],
 [str_repeat('7',32),str_repeat('0',32),3,'assigned',$at(-3*86400),null,null],
 [str_repeat('8',32),str_repeat('1',32),3,'approved',$at(-3600),null,$at(-86400)]
];
$assignmentQ=$db->prepare('INSERT INTO shot_assignments VALUES(?,?,?,?,?,?,?)');
foreach($assignments as $row)$assignmentQ->execute($row);
$db->prepare('INSERT INTO schedule_events VALUES(?,?,?)')->execute([str_repeat('9',32),'published',$at(2*86400)]);
$appointments=[
 [str_repeat('a',32),'confirmed',2,$at(2*3600),$at(4*3600)],
 [str_repeat('b',32),'confirmed',2,$at(3*3600),$at(5*3600)],
 [str_repeat('c',32),'confirmed',3,$at(3*3600),$at(5*3600)],
 [str_repeat('d',32),'cancelled',2,$at(3*3600),$at(5*3600)]
];
$appQ=$db->prepare('INSERT INTO schedule_appointments VALUES(?,?,?,?,?)');
foreach($appointments as $row)$appQ->execute($row);
$cases=az_coordination_candidates($db,$now);
coord_ok($cases['available']===true,'Coordination unexpectedly unavailable');
coord_ok(count($cases['items'])===5,'Expected four active assignment cases and one unstaffed shot');
coord_ok($cases['items'][0]['priority']===0,'Most urgent assignment should rank first');
$caseJson=json_encode($cases,JSON_THROW_ON_ERROR);
coord_ok(!str_contains($caseJson,'PRIVATE_PRODUCTION_TITLE'),'Private production title leaked to Agent coordination');
$counts=az_coordination_metrics($db,$now);
coord_ok($counts['counts']['activeProductions']===1,'Archived production wrongly counted');
coord_ok($counts['counts']['assignmentsApproved7d']===1,'Approved work completion metric incorrect');
coord_ok($counts['counts']['appointmentOverlapPairs7d']===1,'Appointment overlap counting incorrect');
coord_ok($counts['counts']['upcomingAppointments7d']===3,'Confirmed appointments count incorrect');
coord_ok($counts['counts']['upcomingEvents7d']===1,'Event count incorrect');
$admin=['id'=>1,'status'=>'active','roles'=>['admin']];
$model=['id'=>3,'status'=>'active','roles'=>['model']];
coord_reject(static fn()=>az_coordination_briefing($model,$db,$now),'role_required');
$brief=az_coordination_briefing($admin,$db,$now);
coord_ok($brief['counts']['productionAlerts']===5&&$brief['counts']['unassignedShots']===1,'Admin coordination totals incorrect');
coord_ok(count(az_coordination_chat_snapshot($admin,$brief)['followups'])===3,'Agent Chat coordination excerpt missing');
$tracked=az_admin_action_track($admin,['sourceKind'=>'production','sourceId'=>$aid,'sourceStage'=>'production_assignment_due'],$db,$now);
coord_ok($tracked['created']===true,'Production recommendation could not be tracked');
coord_ok(az_admin_action_track($admin,['sourceKind'=>'production','sourceId'=>$aid,'sourceStage'=>'production_assignment_due'],$db,$now)['created']===false,
 'Tracking the same source twice must be idempotent');
coord_reject(static fn()=>az_admin_action_track($admin,['sourceKind'=>'production','sourceId'=>$aid,'sourceStage'=>'production_review_waiting'],$db,$now),
 'recommendation_changed');
coord_reject(static fn()=>az_admin_action_track($model,['sourceKind'=>'production','sourceId'=>$aid,'sourceStage'=>'production_assignment_due'],$db,$now),
 'role_required');
$taskRow=$db->query("SELECT * FROM admin_action_tasks WHERE source_id='".$aid."'")->fetch(PDO::FETCH_ASSOC);
coord_ok(az_coordination_source_active($db,$taskRow,$now),'Tracked case should be active');
$db->prepare('UPDATE shot_assignments SET status=? WHERE id=?')->execute(['approved',$aid]);
coord_ok(!az_coordination_source_active($db,$taskRow,$now),'Completion of original assignment should suppress reminders');
$scan=az_admin_action_scan(1,$db,$now);
coord_ok($scan['created']>=3,'Hourly scan failed to track production blockers');
$scanAgain=az_admin_action_scan(1,$db,$now);
coord_ok($scanAgain['created']===0,'Hourly scan created duplicate production tasks');
$all=$db->query('SELECT title,detail,href FROM admin_action_tasks')->fetchAll(PDO::FETCH_ASSOC);
coord_ok(!str_contains(json_encode($all,JSON_THROW_ON_ERROR),'PRIVATE_PRODUCTION_TITLE'),'Private production name leaked to Action Center');
$emptyDb=new PDO('sqlite::memory:');
coord_ok(az_coordination_candidates($emptyDb,$now)['available']===false,'Missing production tables should report unavailable');
print "Coordination: production blockers, deadlines, overlap checks, workload, completions, safe Action Center tracking and source closure PASS\n";
