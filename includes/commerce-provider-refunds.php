<?php
declare(strict_types=1);
/** Stripe refund initiation is exactly one POST per approved return. GET-only recovery. */
function az_provider_refund_row(string $id):?array{
 if(!az_valid_id($id))return null;
 $q=az_db()->prepare('SELECT * FROM commerce_provider_refunds WHERE return_id=?');$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);return $r?:null;
}
function az_provider_refund_public(?array $r):?array{
 if(!$r)return null;
 return ['status'=>$r['status'],'amountCents'=>(int)$r['amount_cents'],
  'stripeRefundId'=>$r['stripe_refund_id'],'mode'=>$r['mode'],'updatedAt'=>$r['updated_at'],
  'notice'=>'Signed Stripe charge.refunded events must reconcile the actual order balance. Uncertain provider requests are not reissued.'];
}
function az_provider_refund_status(array $user,string $id):?array{
 az_commerce_admin($user);return az_provider_refund_public(az_provider_refund_row($id));
}
function az_provider_refund_amount(array $case):int{
 if((int)$case['order_quantity']<1)throw new AzHttpError(409,'Refund quantity invalid.','invalid_refund_amount');
 $cents=intdiv((int)$case['amount_cents']*(int)$case['quantity'],(int)$case['order_quantity']);
 if($cents<1||$cents>10000000)throw new AzHttpError(409,'Approved merchandise refund outside limits.','invalid_refund_amount');
 return $cents;
}
/* Stripe Refund objects have no livemode property; mode is keyed to our private API key. */
function az_provider_refund_validate(array $data,array $r):array{
 $id=(string)($data['id']??'');
 if(!preg_match('/^re_[A-Za-z0-9_]{6,}$/D',$id)
    ||($data['object']??'')!=='refund'
    ||!in_array(($data['status']??''),['pending','succeeded','requires_action','failed','canceled'],true)
    ||($data['payment_intent']??'')!==$r['payment_intent_id']
    ||($data['amount']??null)!==(int)$r['amount_cents']
    ||strtolower((string)($data['currency']??''))!=='usd'
    ||($data['metadata']['azh_return_id']??'')!==$r['return_id'])
  throw new AzHttpError(409,'Refund response mismatch. Reconcile the original provider operation.','refund_response_mismatch');
 return ['id'=>$id,'status'=>$data['status']];
}
function az_provider_refund_initiate(array $user,array $body):array{
 az_commerce_admin($user);$policy=az_provider_action_config();$cfg=az_stripe_assert_ready();
 if(!$policy['refundsEnabled'])throw new AzHttpError(403,'Provider refund initiation is disabled.','provider_refunds_disabled');
 if(($body['confirmRefund']??false)!==true)throw new AzHttpError(400,'Explicit staff refund confirmation required.','confirmation_required');
 $id=az_text($body['returnId']??'',32,'Return case');
 $revision=az_integer($body['revision']??0,1,PHP_INT_MAX,'Case revision');
 $expected=az_integer($body['expectedCents']??0,1,10000000,'Expected refund');
 $lock=az_provider_operation_lock('stripe-refund',$id);
 try{
  $request=az_commerce_returns_lock_write(static function(PDO $db)use($id,$revision,$expected,$cfg,$policy,$user):array{
   if(az_provider_refund_row($id))throw new AzHttpError(409,'Refund exists or needs reconciliation.','refund_already_requested');
   $r=az_return_row($id);
   if($r['status']!=='refund_approved'||$r['kind']!=='refund'||(int)$r['revision']!==$revision)
    throw new AzHttpError(409,'Approved, current refund case required.','refund_not_approved');
   if($r['provider']!=='stripe'||!in_array($r['order_state'],['paid','partially_refunded'],true))
    throw new AzHttpError(409,'Eligible paid Stripe order required.','provider_refund_ineligible');
   $amount=az_provider_refund_amount($r);
   if($amount!==$expected||$amount>$policy['refundMaxCents'])
    throw new AzHttpError(403,'Refund amount differs or exceeds limit.','refund_amount_rejected');
   if((int)$r['provider_total_cents']-(int)$r['refunded_cents']<$amount)
    throw new AzHttpError(409,'Insufficient remaining Stripe payment.','refund_balance_low');
   $q=$db->prepare("SELECT COUNT(*) FROM commerce_provider_refunds WHERE order_id=? AND status IN ('pending','needs_review','provider_pending')");
   $q->execute([$r['order_id']]);if((int)$q->fetchColumn()>0)
    throw new AzHttpError(409,'Another refund on this order needs verification.','refund_in_progress');
   $pi=(string)($r['provider_payment_id']??'');
   if(!preg_match('/^pi_[A-Za-z0-9_]{6,}$/D',$pi))
    throw new AzHttpError(409,'Verified original Stripe payment required.','payment_intent_missing');
   $key='azh-refund-'.$id;$now=az_now();
   $db->prepare("INSERT INTO commerce_provider_refunds(return_id,order_id,payment_intent_id,amount_cents,idempotency_key,mode,status,initiated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,'pending',?,?,?)")
    ->execute([$id,$r['order_id'],$pi,$amount,$key,$cfg['mode'],(int)$user['id'],$now,$now]);
   az_commerce_queue_admins('provider-refund-intent:'.$id,(int)$user['id'],'refund_authorized',
    'Refund authorization recorded','Staff authorized '.number_format($amount/100,2).' USD; signed provider refund confirmation is still required.',true);
   return az_provider_refund_row($id);
  });
  try{
   $remote=az_provider_stripe_request('POST','/refunds',[
    'payment_intent'=>$request['payment_intent_id'],'amount'=>(int)$request['amount_cents'],
    'reason'=>'requested_by_customer',
    'metadata'=>['azh_return_id'=>$id,'azh_order_id'=>$request['order_id']]
   ],$cfg,$request['idempotency_key']);
   $validated=az_provider_refund_validate($remote,$request);
  }catch(Throwable $e){
   az_commerce_returns_lock_write(static function(PDO $db)use($id):array{
    $db->prepare("UPDATE commerce_provider_refunds SET status='needs_review',updated_at=? WHERE return_id=? AND status='pending'")
     ->execute([az_now(),$id]);return ['id'=>$id];
   });
   throw new AzHttpError(409,'Stripe refund result is uncertain. Reconcile the existing request; do not issue a second refund.','refund_outcome_unknown');
  }
  return az_commerce_returns_lock_write(static function(PDO $db)use($id,$validated,$request):array{
   $db->prepare("UPDATE commerce_provider_refunds SET stripe_refund_id=?,status='provider_pending',updated_at=? WHERE return_id=?")
     ->execute([$validated['id'],az_now(),$id]);
   az_provider_refund_signed_order($db,$request['payment_intent_id']);
   return az_provider_refund_public(az_provider_refund_row($id));
  });
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function az_provider_refund_reconcile(array $user,array $body):array{
 az_commerce_admin($user);$cfg=az_stripe_assert_ready();
 $id=az_text($body['returnId']??'',32,'Return case');$lock=az_provider_operation_lock('stripe-refund',$id);
 try{
  $r=az_provider_refund_row($id);
  if(!$r)throw new AzHttpError(404,'No provider refund request.','refund_missing');
  if($r['mode']!==$cfg['mode'])throw new AzHttpError(409,'Different Stripe environment.','provider_mode_mismatch');
  if($r['stripe_refund_id']!==null)
   $entries=[az_provider_stripe_request('GET','/refunds/'.$r['stripe_refund_id'],[],$cfg)];
  else{
   $list=az_provider_stripe_request('GET','/refunds',['payment_intent'=>$r['payment_intent_id'],'limit'=>100],$cfg);
   if(($list['object']??'')!=='list'||!is_array($list['data']??null)||($list['has_more']??false)===true)
    throw new AzHttpError(409,'Provider refund list incomplete; review Stripe Dashboard.','refund_reconcile_ambiguous');
   $entries=$list['data'];
  }
  $matches=[];
  foreach($entries as $entry){
   if(!is_array($entry)||($entry['metadata']['azh_return_id']??'')!==$id)continue;
   $matches[]=az_provider_refund_validate($entry,$r);
  }
  if(count($matches)>1)throw new AzHttpError(409,'Multiple matching refunds need manual investigation.','refund_reconcile_ambiguous');
  if(!$matches)return ['status'=>'needs_review','notice'=>'No verified match in Stripe; no second refund was sent.'];
  return az_commerce_returns_lock_write(static function(PDO $db)use($id,$matches,$r):array{
   $db->prepare("UPDATE commerce_provider_refunds SET stripe_refund_id=?,status='provider_pending',updated_at=? WHERE return_id=?")
    ->execute([$matches[0]['id'],az_now(),$id]);
   az_provider_refund_signed_order($db,$r['payment_intent_id']);
   return az_provider_refund_public(az_provider_refund_row($id));
  });
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function az_provider_refund_signed_order(PDO $db,string $paymentIntent):void{
 $q=$db->prepare("SELECT r.return_id,r.amount_cents,o.refunded_cents,t.refund_baseline_cents
    FROM commerce_provider_refunds r JOIN commerce_orders o ON o.id=r.order_id
    JOIN commerce_returns t ON t.id=r.return_id
    WHERE r.payment_intent_id=? AND r.stripe_refund_id IS NOT NULL AND r.status='provider_pending'");
 $q->execute([$paymentIntent]);
 foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
  if((int)$row['refunded_cents']-(int)$row['refund_baseline_cents']>=(int)$row['amount_cents'])
   $db->prepare("UPDATE commerce_provider_refunds SET status='ledger_verified',verified_at=?,updated_at=? WHERE return_id=?")
      ->execute([az_now(),az_now(),$row['return_id']]);
 }
}
