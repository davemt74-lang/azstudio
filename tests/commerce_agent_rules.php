<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
function demand(bool $condition,string $reason):void{if(!$condition)throw new RuntimeException($reason);}
$admin=az_commerce_agent_admin_report([
 'refundsNeedsReconciliation'=>2,'returnLabelsNeedReconciliation'=>1,'connectTransfersNeedReconciliation'=>1,
 'commerceOrdersToReview'=>2,'commissionOverpaidCents'=>1500,'returnRequests'=>2,'returnRefundApproved'=>1,
 'shippingExceptions'=>1,'payoutDrafts'=>1,'payoutApproved'=>1]);
demand($admin['role']==='admin','Wrong admin role');
demand(str_contains($admin['keyFindings'][0],'4 provider'),'Provider ledger metrics not aggregated');
demand(in_array('commerce.php?tab=providers',array_column($admin['actionsFollowups'],'href'),true),'Provider reconciliation navigation missing');
demand($admin['actionsFollowups'][0]['priority']===0,'Provider reconciliation was not prioritized');
demand(str_contains($admin['nextStep'],'commission')||str_contains($admin['nextStep'],'provider'),'Agent next step not tied to financial reconciliation');
demand(isset($admin['counts']['commissionOverpaidCents']),'Commission overpayment was hidden');
$quiet=az_commerce_agent_admin_report([]);
demand(!empty($quiet['actionsFollowups']),'Quiet Commerce report needs a safe next step');
$creator=az_commerce_agent_creator_report([
 'eligibleCents'=>2250,'heldCents'=>7800,'reservedCents'=>1200,'recordedPaidCents'=>4000,
 'connectTransferredCents'=>2500,'overpaidCents'=>500,'modelId'=>2456,'orders'=>[['buyer'=>'SECRET_PERSON_NAME']]
],['draft'=>1,'approved'=>2]);
demand($creator['role']==='model','Wrong creator role');
demand($creator['actionsFollowups'][0]['title']==='Review commission adjustment','Creator overpayment warning missing');
demand($creator['counts']['eligibleCommissionCents']===2250,'Creator eligible balance was altered');
demand($creator['counts']['creatorPayoutApproved']===2,'Creator approved batches missing');
demand(str_contains(implode(' ', $creator['keyFindings']),'not evidence of bank deposits'),'Transfer/bank status distinction lost');
$json=json_encode($creator,JSON_THROW_ON_ERROR);
demand(!str_contains($json,'SECRET_PERSON_NAME')&&!str_contains($json,'2456'),'Creator report leaked record data');
demand(!str_contains($json,'account_')&&!str_contains($json,'sk_test_'),'Creator report included private keys');
foreach($creator['actionsFollowups'] as $action)demand(az_agent_workspace_href($action['href'])===$action['href'],'Unsafe creator href');
try{az_agent_workspace_assert(['id'=>10,'status'=>'suspended','roles'=>['model']]);throw new RuntimeException('Suspended model accessed agent workspace');}
catch(AzHttpError $e){demand($e->status===403,'Wrong role error');}
print "Commerce Agent admin/creator reports, payout semantics and safe links: PASS\n";
