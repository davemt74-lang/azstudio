<?php
declare(strict_types=1);
/** A Stripe Connect platform transfer is not a connected creator bank payout. */
function az_connect_account_row(int $model):?array{
 $q=az_db()->prepare('SELECT * FROM commerce_connect_accounts WHERE model_id=?');
 $q->execute([$model]);$r=$q->fetch(PDO::FETCH_ASSOC);return $r?:null;
}
function az_connect_transfer_row(string $batch):?array{
 if(!az_valid_id($batch))return null;
 $q=az_db()->prepare('SELECT * FROM commerce_connect_transfers WHERE batch_id=?');
 $q->execute([$batch]);$r=$q->fetch(PDO::FETCH_ASSOC);return $r?:null;
}
function az_connect_account_state(array $user,int $model):array{
 az_agent_workspace_assert($user);
 if(!az_has_role($user,'admin')&&(int)$user['id']!==$model)
  throw new AzHttpError(403,'Creator account access denied.','role_required');
 $a=az_connect_account_row($model);
 return ['linked'=>(bool)$a,'status'=>$a['status']??'unlinked','mode'=>$a['mode']??null,
  'lastVerified'=>$a['verified_at']??null,'accountLast4'=>$a?substr($a['account_id'],-4):null,
  'notice'=>'Stripe Connect platform transfers are not bank payout confirmations.'];
}
function az_connect_validate_account(array $data,string $id):void{
 if(($data['id']??'')!==$id||($data['object']??'')!=='account'
    ||($data['payouts_enabled']??null)!==true
    ||($data['details_submitted']??null)!==true
    ||($data['capabilities']['transfers']??'')!=='active')
  throw new AzHttpError(409,'Existing Stripe Connect account has not completed required onboarding or payout/transfer checks.','connect_not_ready');
}
function az_connect_account_link(array $user,array $body):array{
 az_commerce_admin($user);
 if(($body['confirmLink']??false)!==true)
  throw new AzHttpError(400,'Confirm ownership of the creator account.','confirmation_required');
 $model=az_integer($body['modelId']??0,1,PHP_INT_MAX,'Creator');
 az_payout_model_account($model);
 $accountId=az_text($body['accountId']??'',100,'Stripe Connect account ID');
 if(!preg_match('/^acct_[A-Za-z0-9_]{6,}$/D',$accountId))
  throw new AzHttpError(400,'A pre-existing connected Stripe account ID is required.','invalid_connect_account');
 $cfg=az_stripe_assert_ready();
 az_connect_validate_account(az_provider_stripe_request('GET','/accounts/'.$accountId,[],$cfg),$accountId);
 return az_commerce_returns_lock_write(static function(PDO $db)use($model,$accountId,$cfg,$user):array{
  $old=az_connect_account_row($model);
  if($old&&$old['account_id']!==$accountId){
   $q=$db->prepare("SELECT COUNT(*) FROM commerce_connect_transfers t
       JOIN commerce_payout_batches b ON b.id=t.batch_id WHERE b.model_id=?");
   $q->execute([$model]);
   if((int)$q->fetchColumn()>0)
    throw new AzHttpError(409,'Existing transfers must be reconciled before changing this connected account.','connect_account_locked');
  }
  $now=az_now();
  if($old)$db->prepare("UPDATE commerce_connect_accounts SET account_id=?,mode=?,status='verified',verified_at=?,verified_by=? WHERE model_id=?")
      ->execute([$accountId,$cfg['mode'],$now,(int)$user['id'],$model]);
  else $db->prepare("INSERT INTO commerce_connect_accounts(model_id,account_id,mode,status,verified_at,verified_by)
       VALUES(?,?,?,'verified',?,?)")
      ->execute([$model,$accountId,$cfg['mode'],$now,(int)$user['id']]);
  az_commerce_queue_admins('connect-account:'.$model.':'.$now,(int)$user['id'],
      'connect_account_ready','Stripe Connect account verified',
      'An existing creator connected account passed Stripe onboarding and transfers checks. Actual transfers require another approval.',false);
  return az_connect_account_state($user,$model);
 });
}
function az_connect_transfer_validate(array $transfer,array $request):string{
 $id=(string)($transfer['id']??'');
 if(!preg_match('/^tr_[A-Za-z0-9_]{6,}$/D',$id)
  ||($transfer['object']??'')!=='transfer'
  ||($transfer['destination']??'')!==$request['account_id']
  ||($transfer['amount']??null)!==(int)$request['amount_cents']
  ||strtolower((string)($transfer['currency']??''))!=='usd'
  ||($transfer['transfer_group']??'')!==$request['transfer_group']
  ||($transfer['metadata']['azh_batch_id']??'')!==$request['batch_id']
  ||!is_bool($transfer['livemode']??null)
  ||$transfer['livemode']!==($request['mode']==='live'))
  throw new AzHttpError(409,'Stripe Connect transfer response mismatch. Reconcile original operation.','connect_transfer_mismatch');
 return $id;
}
/** Only a provider-verified transfer may finalize the batch. No bank payout claim. */
function az_connect_transfer_commit(PDO $db,array $transfer,string $providerId):array{
 $batch=az_payout_batch_row($transfer['batch_id']);
 if($batch['state']!=='approved')throw new AzHttpError(409,'Payout batch changed during Stripe transfer. Manual reconciliation required.','batch_changed');
 $now=az_now();$actor=(int)$transfer['authorized_by'];
 $db->prepare("UPDATE commerce_connect_transfers SET transfer_id=?,status='transferred',reconciled_at=?,updated_at=? WHERE batch_id=?")
  ->execute([$providerId,$now,$now,$transfer['batch_id']]);
 $db->prepare("UPDATE commerce_payout_batches SET state='transferred',payment_channel='stripe_connect',
     evidence_reference=?,confirmed_by=?,confirmed_at=?,updated_at=?,revision=revision+1 WHERE id=? AND state='approved'")
  ->execute(['stripe_connect:'.$providerId,$actor,$now,$now,$transfer['batch_id']]);
 az_commerce_queue_admins('connect-transferred:'.$transfer['batch_id'],$actor,'connect_transfer',
  'Stripe Connect transfer recorded','Platform commission funds were transferred to a connected Stripe account. A subsequent bank payout is not guaranteed.',true);
 az_commerce_queue('connect-model:'.$transfer['batch_id'],$actor,(int)$batch['model_id'],
  'connect_transfer','Stripe Connect funds transferred',
  'An administrator authorized an available balance transfer to your connected account. Stripe handles the subsequent bank payout.',
  'commerce.php',true);
 return ['id'=>$transfer['batch_id'],'status'=>'transferred','stripeTransferId'=>$providerId,
  'amountCents'=>(int)$transfer['amount_cents'],'mode'=>$transfer['mode'],
  'notice'=>'Platform transfer verified, but bank deposit not independently confirmed.'];
}
function az_connect_transfer_send(array $user,array $body):array{
 az_commerce_admin($user);$limits=az_provider_action_config();
 if(!$limits['connectEnabled'])
  throw new AzHttpError(403,'Stripe Connect transfers disabled in private settings.','connect_transfers_disabled');
 if(($body['confirmTransfer']??false)!==true)
  throw new AzHttpError(400,'Explicitly confirm this connected-account transfer.','confirmation_required');
 $batchId=az_text($body['batchId']??'',32,'Payout batch');
 $revision=az_integer($body['revision']??0,1,PHP_INT_MAX,'Payout revision');
 $expected=az_integer($body['expectedCents']??0,1,10000000,'Expected transfer');
 $lock=az_provider_operation_lock('connect-transfer',$batchId);
 try{
  $cfg=az_stripe_assert_ready();$batch=az_payout_batch_row($batchId);
  $account=az_connect_account_row((int)$batch['model_id']);
  if(!$account||$account['status']!=='verified'||$account['mode']!==$cfg['mode'])
   throw new AzHttpError(409,'Creator needs an eligible Connect account in the same Stripe environment.','connect_account_unavailable');
  az_connect_validate_account(az_provider_stripe_request('GET','/accounts/'.$account['account_id'],[],$cfg),$account['account_id']);
  $request=az_commerce_returns_lock_write(static function(PDO $db)use($batchId,$revision,$expected,$account,$cfg,$limits,$user):array{
   if(az_connect_transfer_row($batchId))
    throw new AzHttpError(409,'Transfer already attempted. Reconcile it instead of sending another.','connect_transfer_existing');
   $b=az_payout_batch_row($batchId);
   if((int)$b['revision']!==$revision||$b['state']!=='approved')
    throw new AzHttpError(409,'Payout batch approval or revision changed.','batch_not_approved');
   az_payout_verify_batch($db,$b);
   if($expected!==(int)$b['amount_cents']||$expected>$limits['connectMaxCents'])
    throw new AzHttpError(403,'Transfer amount mismatched approved batch or private maximum.','connect_amount_rejected');
   $now=az_now();$group='azh-batch-'.$batchId;
   $db->prepare("INSERT INTO commerce_connect_transfers(batch_id,account_id,amount_cents,mode,transfer_group,status,authorized_by,created_at,updated_at)
       VALUES(?,?,?,?,?,'pending',?,?,?)")
    ->execute([$batchId,$account['account_id'],$expected,$cfg['mode'],$group,(int)$user['id'],$now,$now]);
   return az_connect_transfer_row($batchId);
  });
  try{
   $remote=az_provider_stripe_request('POST','/transfers',[
    'amount'=>(int)$request['amount_cents'],'currency'=>'usd','destination'=>$request['account_id'],
    'transfer_group'=>$request['transfer_group'],'metadata'=>['azh_batch_id'=>$batchId]
   ],$cfg,'azh-connect-'.$batchId);
   $id=az_connect_transfer_validate($remote,$request);
  }catch(Throwable $e){
   az_commerce_returns_lock_write(static function(PDO $db)use($batchId):array{
    $db->prepare("UPDATE commerce_connect_transfers SET status='needs_review',updated_at=? WHERE batch_id=? AND status='pending'")
     ->execute([az_now(),$batchId]);return ['id'=>$batchId];
   });
   throw new AzHttpError(409,'Connect transfer outcome uncertain. Reconcile the original Stripe transfer, never retry.','connect_outcome_unknown');
  }
  return az_commerce_returns_lock_write(static function(PDO $db)use($request,$id):array{
   return az_connect_transfer_commit($db,$request,$id);
  });
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function az_connect_transfer_reconcile(array $user,array $body):array{
 az_commerce_admin($user);$cfg=az_stripe_assert_ready();
 $batchId=az_text($body['batchId']??'',32,'Payout batch');$lock=az_provider_operation_lock('connect-transfer',$batchId);
 try{
  $r=az_connect_transfer_row($batchId);
  if(!$r)throw new AzHttpError(404,'No Connect transfer request.','connect_transfer_missing');
  if($r['mode']!==$cfg['mode'])throw new AzHttpError(409,'Transfer environment changed.','provider_mode_mismatch');
  if($r['status']==='transferred')
   return ['id'=>$batchId,'status'=>'transferred','stripeTransferId'=>$r['transfer_id']];
  $list=az_provider_stripe_request('GET','/transfers',[
    'transfer_group'=>$r['transfer_group'],'limit'=>100
  ],$cfg);
  if(($list['object']??'')!=='list'||!is_array($list['data']??null)||($list['has_more']??false)===true)
   throw new AzHttpError(409,'Incomplete transfer list. Review Stripe Dashboard.','connect_reconcile_ambiguous');
  $matches=[];
  foreach($list['data'] as $t){
   if(!is_array($t)||($t['metadata']['azh_batch_id']??'')!==$batchId)continue;
   $matches[]=az_connect_transfer_validate($t,$r);
  }
  if(count($matches)>1)throw new AzHttpError(409,'Multiple transfers require manual review.','connect_reconcile_ambiguous');
  if(!$matches)return ['id'=>$batchId,'status'=>'needs_review',
   'notice'=>'No matching provider transfer found; no new transfer was sent.'];
  return az_commerce_returns_lock_write(static function(PDO $db)use($r,$matches):array{
   return az_connect_transfer_commit($db,$r,$matches[0]);
  });
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function az_connect_transfer_status(array $user,string $batchId):?array{
 az_agent_workspace_assert($user);
 $batch=az_payout_batch_row($batchId);
 if(!az_has_role($user,'admin')&&(int)$batch['model_id']!==(int)$user['id'])
  throw new AzHttpError(403,'Payout account access denied.','role_required');
 $r=az_connect_transfer_row($batchId);
 if(!$r)return null;
 return ['status'=>$r['status'],'mode'=>$r['mode'],
  'amountCents'=>(int)$r['amount_cents'],
  'stripeTransferId'=>$r['status']==='transferred'?$r['transfer_id']:null,
  'notice'=>'Connect transfer verified separately from the connected account bank payout.'];
}
