<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
$req=['return_id'=>str_repeat('b',32),'payment_intent_id'=>'pi_mock1234567890',
      'amount_cents'=>1250,'mode'=>'test'];
$refund=['id'=>'re_mock12345678','object'=>'refund',
    'payment_intent'=>$req['payment_intent_id'],'amount'=>1250,
    'currency'=>'usd','status'=>'succeeded',
    'metadata'=>['azh_return_id'=>$req['return_id']]];
$r=az_provider_refund_validate($refund,$req);
if($r['status']!=='succeeded')throw new RuntimeException('Valid Stripe refund rejected');
try{
    az_provider_refund_validate(array_merge($refund,['amount'=>1249]),$req);
    throw new RuntimeException('Tampered refund amount accepted');
}catch(AzHttpError $e){if($e->errorCode!=='refund_response_mismatch')throw $e;}
$transfer=['id'=>'tr_mock123456789','object'=>'transfer','amount'=>1750,
    'currency'=>'usd','destination'=>'acct_mock123456789',
    'transfer_group'=>'azh-batch-abc','metadata'=>['azh_batch_id'=>'abc'],'livemode'=>false];
$request=['account_id'=>'acct_mock123456789','amount_cents'=>1750,
    'transfer_group'=>'azh-batch-abc','batch_id'=>'abc','mode'=>'test'];
if(az_connect_transfer_validate($transfer,$request)!==$transfer['id'])
    throw new RuntimeException('Valid Connect transfer rejected');
try{
    az_connect_transfer_validate(array_merge($transfer,['destination'=>'acct_wrong123456']),$request);
    throw new RuntimeException('Tampered transfer recipient accepted');
}catch(AzHttpError $e){if($e->errorCode!=='connect_transfer_mismatch')throw $e;}
try{
    az_connect_validate_account(['id'=>'acct_mock123456789','object'=>'account','payouts_enabled'=>false,
         'details_submitted'=>true,'capabilities'=>['transfers'=>'active']],'acct_mock123456789');
    throw new RuntimeException('Unapproved account accepted');
}catch(AzHttpError $e){if($e->errorCode!=='connect_not_ready')throw $e;}
print "Provider response validation and approval safety: PASS\n";
