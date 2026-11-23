<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
function learn_assert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function learn_reject(callable $fn,string $code):void{
    try{$fn();throw new RuntimeException('Expected rejection '.$code);}
    catch(AzHttpError $e){learn_assert($e->errorCode===$code,'Wrong error: '.$e->errorCode);}
}
learn_assert(az_ops_learning_median([])===null,'Empty median must stay unknown');
learn_assert(az_ops_learning_median([6,2,10])===6.0,'Odd-count median incorrect');
learn_assert(az_ops_learning_median([2,3,8,9])===5.5,'Even-count median incorrect');
learn_reject(static fn()=>az_ops_learning_days(14),'invalid_period');
foreach([7,30,90] as $days)learn_assert(az_ops_learning_days($days)===$days,'Supported period rejected');
[$kind,$label]=az_ops_learning_family('production','production_assignment_due');
learn_assert($kind==='production'&&$label==='Production deadlines','Source category normalization incorrect');
learn_assert(az_ops_learning_family('production','PRIVATE_UNTRUSTED_STAGE')[1]==='Other production follow-ups',
    'Untrusted source stage leaked to report');
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE admin_action_tasks(id TEXT PRIMARY KEY,source_kind TEXT,source_stage TEXT,state TEXT,created_at TEXT,due_at TEXT, private_note TEXT)');
$db->exec('CREATE TABLE admin_action_history(id TEXT PRIMARY KEY,task_id TEXT,action TEXT,created_at TEXT,note TEXT)');
$time=strtotime('2026-11-23T12:00:00Z');
$at=static fn(string $input):string=>gmdate('Y-m-d\TH:i:s\Z',strtotime($input));
$tasks=[
  ['1','production','production_assignment_due','resolved','2026-11-17 02:00','2026-11-21 10:00'],
  ['2','production','production_assignment_due','resolved','2026-11-18 01:00','2026-11-22 08:00'],
  ['3','production','production_assignment_due','resolved','2026-11-19 01:00','2026-11-22 08:00'],
  ['4','casting','new_72h','open','2026-11-20 01:00','2026-11-22 08:00'],
  ['5','video','video_72h','open','2026-11-21 01:00','2026-11-22 08:00'],
  ['6','production','production_assignment_due','open','2026-11-22 01:00','2026-11-23 01:00'],
  ['7','production','production_assignment_due','acknowledged','2026-11-23 01:00','2026-11-24 12:00'],
  ['8','queue','provider_exception','resolved','2026-11-16 01:00','2026-11-17 09:00']
];
$tq=$db->prepare('INSERT INTO admin_action_tasks VALUES(?,?,?,?,?,?,?)');
foreach($tasks as $t){
  $tq->execute([str_repeat($t[0],32),$t[1],$t[2],$t[3],$at($t[4]),$at($t[5]),'SECRET_CLIENT_AND_APPLICANT_NOTES']);
}
$history=[
 ['1','resolve','2026-11-20 03:00'],
 ['2','resolve','2026-11-22 01:00'],
 ['3','resolve','2026-11-23 06:00'],
 ['4','resolve','2026-11-21 04:00'],
 ['4','reopen','2026-11-22 03:00'],
 ['5','resolve','2026-11-22 06:00'],
 ['5','reopen','2026-11-23 06:00'],
 ['8','resolve','2026-11-19 09:00'],
 ['7','acknowledge','2026-11-23 02:00']
];
$hq=$db->prepare('INSERT INTO admin_action_history VALUES(?,?,?,?,?)');
foreach($history as $index=>$h)
    $hq->execute([str_pad((string)($index+1),32,'f',STR_PAD_LEFT),
        str_repeat($h[0],32),$h[1],$at($h[2]),'SECRET_STAFF_RESOLUTION_NOTE']);
$admin=['id'=>1,'status'=>'active','roles'=>['admin']];
$model=['id'=>2,'status'=>'active','roles'=>['model']];
learn_reject(static fn()=>az_ops_learning_report($model,30,$db,$time),'role_required');
learn_reject(static fn()=>az_ops_learning_report($admin,4,$db,$time),'invalid_period');
$report=az_ops_learning_report($admin,7,$db,$time);
learn_assert($report['periodDays']===7&&count($report['trend'])===7,'Seven-day trend mismatch');
learn_assert($report['periodStart']==='2026-11-17T00:00:00Z','Period UTC boundary incorrect');
$totals=$report['totals'];
learn_assert($totals['created']===7,'Only seven new tasks belong in the seven-day period');
learn_assert($totals['cohortResolvedNow']===3,'Current cohort resolution status incorrect');
learn_assert($totals['cohortResolvedPct']===42.9,'New task cohort percentage incorrect');
learn_assert($totals['resolvedEvents']===6&&$totals['resolvedTasks']===6,'Resolution events / task count incorrect');
learn_assert($totals['reopenedTasks']===2&&$totals['reopenEvents']===2,'Reopen counts incorrect');
learn_assert($totals['acknowledgmentEvents']===1,'Acknowledgment event missing');
learn_assert($totals['openNow']===4&&$totals['overdueNow']===3,'Current overdue backlog wrong');
learn_assert($totals['medianResolutionHours']!==null&&$totals['medianResolutionHours']>=72,'Resolution median should reflect six observed outcomes');
learn_assert($totals['resolutionSamples']===6&&!$totals['sampleTruncated'],'Duration sample size wrong');
learn_assert(array_sum(array_column($report['trend'],'created'))===7,'Daily new task series failed to match totals');
learn_assert(array_sum(array_column($report['trend'],'resolvedEvents'))===6,'Daily resolution series failed to match totals');
learn_assert(array_sum(array_column($report['trend'],'reopenedEvents'))===2,'Daily reopen series failed to match totals');
learn_assert(count($report['recommendations'])>=3,'Evidence-based recommendations missing');
$keys=array_column($report['recommendations'],'key');
learn_assert(in_array('overdue',$keys,true)&&in_array('reopened',$keys,true)&&in_array('slow_resolution',$keys,true),
    'Overdue, reopen and slow resolution signals not detected');
learn_assert(in_array('production',array_column($report['patterns'],'kind'),true),'Recurring production pattern missing');
learn_assert($report['automatedActions']===false,'Read-only report must not trigger operations');
$json=json_encode($report,JSON_THROW_ON_ERROR);
foreach(['SECRET_CLIENT','SECRET_STAFF','PRIVATE_UNTRUSTED','payment_intent_id','stripe_secret'] as $private){
    learn_assert(!str_contains($json,$private),'Sensitive task content leaked: '.$private);
}
$chat=az_ops_learning_chat_snapshot($admin,$report);
learn_assert(count($chat['topPatterns'])<=3&&count($chat['recommendations'])<=2,'Chat summary exceeded bounded evidence');
learn_reject(static fn()=>az_ops_learning_chat_snapshot($model,$report),'role_required');
$report30=az_ops_learning_report($admin,30,$db,$time);
learn_assert($report30['totals']['created']===8&&count($report30['trend'])===30,'Thirty-day data changed or series length wrong');
$report90=az_ops_learning_report($admin,90,$db,$time);
learn_assert(count($report90['trend'])===90,'Ninety-day chart rows missing');
$empty=new PDO('sqlite::memory:');
$empty->exec('CREATE TABLE admin_action_tasks(id TEXT,source_kind TEXT,source_stage TEXT,state TEXT,created_at TEXT,due_at TEXT)');
$empty->exec('CREATE TABLE admin_action_history(id TEXT,task_id TEXT,action TEXT,created_at TEXT)');
$none=az_ops_learning_report($admin,7,$empty,$time);
learn_assert(!$none['hasEvidence']&&$none['totals']['medianResolutionHours']===null,
    'No-data report must not fabricate results');
learn_assert(!$none['recommendations']&&str_contains($none['nextStep'],'No improvement threshold'),
    'No-data report must not invent operational recommendations');
print "Operations Learning: admin isolation, 7/30/90 trends, immutable outcomes, cohort semantics, median duration, privacy, grounded recommendations PASS\n";
