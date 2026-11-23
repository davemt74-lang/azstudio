<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
function ck(bool $assert,string $message):void{if(!$assert)throw new RuntimeException($message);}
$denied=['id'=>5,'status'=>'active','roles'=>['model']];
try{az_commerce_admin($denied);throw new RuntimeException('Model received admin financial access');}
catch(AzHttpError $e){ck($e->status===403,'Unexpected role error');}
$carrier=['configured'=>true,'mode'=>'test','allowPurchase'=>true,'webhookConfigured'=>true,
 'key'=>'EZTK_SHOULD_NOT_LEAK','from'=>['name'=>'PRIVATE ADDRESS']];
$stripe=['ready'=>true,'mode'=>'test','key'=>'sk_test_PRIVATE_KEY','webhook'=>'whsec_PRIVATE'];
$features=['refundsEnabled'=>false,'connectEnabled'=>false,'returnLabelPurchasesEnabled'=>true];
$snapshot=az_provider_readiness_snapshot($stripe,$carrier,$features,[]);
$label=array_values(array_filter($snapshot['checks'],static fn($check)=>$check['id']==='labels'))[0];
ck($label['state']==='enabled','Independent return label switch is incorrectly disabled');
ck(!$snapshot['hostedTestsVerified']&&!$snapshot['productionApproved'],'Hosted acceptance falsely approved');
ck(strpos(json_encode($snapshot,JSON_THROW_ON_ERROR),'PRIVATE')===false,'Private data leaked into readiness');

if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){
    throw new RuntimeException('PHP pdo_sqlite is required for the provider triage fixture');
}
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach([
 'commerce_provider_refunds'=>['return_id','verified_at'],
 'commerce_return_labels'=>['return_id','purchased_at'],
 'commerce_connect_transfers'=>['batch_id','reconciled_at']
] as $table=>[$id,$date]){
 $db->exec("CREATE TABLE $table ($id CHAR(32) PRIMARY KEY,status VARCHAR(32),mode VARCHAR(18),updated_at VARCHAR(32),$date VARCHAR(32))");
}
$now=strtotime('2026-11-23T08:00:00Z');
$fmt=static fn(int $ago):string=>gmdate('Y-m-d\TH:i:s\Z',$now-$ago);
$q=$db->prepare('INSERT INTO commerce_provider_refunds VALUES(?,?,?,?,?)');
$q->execute([str_repeat('a',32),'needs_review','test',$fmt(7200),null]);
$q->execute([str_repeat('b',32),'provider_pending','test',$fmt(900),null]);
$q->execute([str_repeat('c',32),'ledger_verified','test',$fmt(600),$fmt(600)]);
$q=$db->prepare('INSERT INTO commerce_return_labels VALUES(?,?,?,?,?)');
$q->execute([str_repeat('d',32),'pending','test',$fmt(90000),null]);
$q->execute([str_repeat('e',32),'purchased','test',$fmt(600),$fmt(600)]);
$q=$db->prepare('INSERT INTO commerce_connect_transfers VALUES(?,?,?,?,?)');
$q->execute([str_repeat('f',32),'pending','test',$fmt(240),null]);
$q->execute([str_repeat('0',32),'transferred','test',$fmt(600),$fmt(600)]);
$triage=az_provider_triage_snapshot($db,24,$now);
ck(count($triage['items'])===4,'Pending queue count incorrect');
ck($triage['items'][0]['kind']==='return_label'&&$triage['items'][0]['urgency']==='overdue',
 'Stale postage purchase was not placed first');
ck($triage['items'][1]['kind']==='refund'&&$triage['items'][1]['status']==='needs_review',
 'Ambiguous refund not prioritized');
ck($triage['items'][2]['status']==='provider_pending'&&$triage['items'][3]['kind']==='connect_transfer',
 'Recent pending ordering incorrect');
ck($triage['items'][0]['ageMinutes']===1500,'Aging calculation incorrect');
ck($triage['items'][0]['reference']==='DDDDDDDD','Opaque references should show last 8 hex digits only');
ck(count(az_provider_triage_snapshot($db,1,$now)['items'])===1,
 'Page size limits not enforced');
ck(az_provider_triage_snapshot($db,1,$now)['truncated'],'Truncation was not disclosed');
$evidence=az_provider_test_evidence($db);
ck(count($evidence)===3,'Test evidence types missing');
foreach($evidence as $row){
 ck($row['observedCount']===1,'Expected one test-mode provider ledger record');
 ck($row['hostedAccepted']===false,'Local provider record treated as hosted acceptance');
}
$serialized=json_encode(['triage'=>$triage,'testEvidence'=>$evidence],JSON_THROW_ON_ERROR);
foreach(['payment_intent_id','stripe_refund_id','account_id','tracking_number','label_url','sk_test_PRIVATE_KEY','PRIVATE ADDRESS'] as $secret){
 ck(!str_contains($serialized,$secret),'Private financial fields leaked: '.$secret);
}
// A production-mode record is never counted as TEST acceptance evidence.
$db->prepare('INSERT INTO commerce_connect_transfers VALUES(?,?,?,?,?)')
  ->execute([str_repeat('1',32),'transferred','live',$fmt(100),$fmt(100)]);
ck(az_provider_test_evidence($db)[2]['observedCount']===1,'Production transfer counted as TEST evidence');
print "Provider reconciliation triage, TEST evidence, privacy and role gates: PASS\n";
