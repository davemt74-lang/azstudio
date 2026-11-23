<?php
declare(strict_types=1);
/** Provider readiness must not accidentally authorize live money movement or leak secrets. */
require_once __DIR__.'/../includes/bootstrap.php';
function require_condition(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
}
$stripe=['ready'=>true,'mode'=>'test','key'=>'sk_test_SECRET_DO_NOT_LEAK',
         'webhook'=>'whsec_SECRET_DO_NOT_LEAK','base'=>'https://secret.example'];
$carrier=['configured'=>true,'mode'=>'test','allowPurchase'=>true,
          'webhookConfigured'=>true,'key'=>'EZTK_SECRET_DO_NOT_LEAK',
          'from'=>['name'=>'PRIVATE NAME','street1'=>'PRIVATE STREET']];
$actions=['refundsEnabled'=>true,'connectEnabled'=>true,'returnLabelPurchasesEnabled'=>true,
          'refundMaxCents'=>1000,'connectMaxCents'=>1000];
$clean=az_provider_readiness_snapshot($stripe,$carrier,$actions,[]);
require_condition($clean['configurationReady']===true,'Expected configuration-only readiness');
require_condition($clean['hostedTestsVerified']===false&&$clean['productionApproved']===false,
    'Snapshot must never grant production approval');
require_condition($clean['environment']===['stripe'=>'test','easypost'=>'test'],'Provider test modes lost');
require_condition(count($clean['requiredTests'])>=4,'Hosted test requirements missing');
$json=json_encode($clean,JSON_THROW_ON_ERROR);
foreach(['SECRET_DO_NOT_LEAK','PRIVATE NAME','PRIVATE STREET','secret.example'] as $secret){
    require_condition(!str_contains($json,$secret),'Readiness leaked private configuration: '.$secret);
}
$held=az_provider_readiness_snapshot($stripe,$carrier,$actions,
    ['refundsNeedsReconciliation'=>2,'returnLabelsNeedReconciliation'=>1,'connectTransfersNeedReconciliation'=>3]);
require_condition($held['pendingTotal']===6&&!$held['configurationReady'],'Unresolved operations ignored');
require_condition($held['counts'][0]['tab']==='returns'&&$held['counts'][2]['tab']==='payouts',
    'Reconciliation did not identify correct staff tabs');
$off=az_provider_readiness_snapshot(['ready'=>false,'mode'=>'test','key'=>'bad'],
    ['configured'=>false,'mode'=>'unknown','allowPurchase'=>false,'webhookConfigured'=>false],[],[]);
require_condition($off['environment']===['stripe'=>'unconfigured','easypost'=>'unconfigured'],
    'Unconfigured keys falsely identified as usable TEST environments');
require_condition(!$off['configurationReady']&&!$off['productionApproved'],
    'Missing provider settings unexpectedly allowed production');
require_condition($off['checks'][1]['state']==='disabled'&&$off['checks'][4]['state']==='disabled',
    'Financial actions must show safely disabled');
$live=az_provider_readiness_snapshot(array_merge($stripe,['mode'=>'live']),
    array_merge($carrier,['mode'=>'production']),$actions,[]);
require_condition($live['liveActionsEnabled']===true&&!$live['configurationReady'],
    'Live money movement warning must block the readiness gate');
$noCarrierWebhook=az_provider_readiness_snapshot($stripe,array_merge($carrier,['webhookConfigured'=>false]),$actions,[]);
require_condition(!$noCarrierWebhook['configurationReady'],'Missing signed webhook ignored');
try{
    az_provider_readiness_report(['id'=>4,'status'=>'active','roles'=>['model']]);
    throw new RuntimeException('Non-admin obtained provider readiness');
}catch(AzHttpError $e){require_condition($e->status===403,'Unexpected role gate error');}
print "Read-only provider readiness, private data isolation and role gate: PASS\n";
