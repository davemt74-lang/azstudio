<?php
declare(strict_types=1);
/** Domestic return labels through EasyPost, only for verified delivered Stripe orders. */
function az_return_label_row(string $id):?array{
 if(!az_valid_id($id))return null;
 $q=az_db()->prepare('SELECT * FROM commerce_return_labels WHERE return_id=?');$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);return $r?:null;
}
function az_return_label_quote_row(string $id):?array{
 if(!az_valid_id($id))return null;
 $q=az_db()->prepare('SELECT * FROM commerce_return_label_quotes WHERE return_id=?');$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);return $r?:null;
}
function az_return_label_snapshot(string $id):array{
 $q=az_return_label_quote_row($id);$l=az_return_label_row($id);
 return ['quote'=>$q?['revision'=>(int)$q['revision'],'expiresAt'=>$q['expires_at'],'mode'=>$q['mode'],
   'rates'=>json_decode((string)$q['rates_json'],true)?:[]]:null,
  'label'=>$l?['status'=>$l['status'],'carrier'=>$l['carrier'],'service'=>$l['service'],
   'rateCents'=>(int)$l['rate_cents'],'trackingNumber'=>$l['tracking_number'],
   'labelUrl'=>$l['status']==='purchased'?$l['label_url']:null,
   'mode'=>$l['mode'],'updatedAt'=>$l['updated_at']]:null];
}
function az_return_label_require(array $user,string $id):array{
 az_commerce_admin($user);$r=az_return_row($id);
 if($r['status']!=='authorized'||!in_array($r['order_state'],['paid','partially_refunded'],true)||$r['provider']!=='stripe')
  throw new AzHttpError(409,'Only authorized returns from paid Stripe shipments are eligible.','return_label_ineligible');
 $q=az_db()->prepare("SELECT * FROM commerce_shipments WHERE order_id=? AND status='delivered'");
 $q->execute([$r['order_id']]);$shipment=$q->fetch(PDO::FETCH_ASSOC);
 if(!$shipment)throw new AzHttpError(409,'Original shipment must be verified delivered before return-label purchase.','original_shipment_not_delivered');
 return [$r,$shipment];
}
function az_return_label_parcel(array $body,array $case):array{
 if(($body['confirmParcel']??false)!==true)
  throw new AzHttpError(400,'Staff must verify the return parcel size.','parcel_confirmation_required');
 $p=az_fulfillment_row($case['product_id']);$values=[];
 foreach(['weightGrams','lengthMm','widthMm','heightMm'] as $key){
  $source=match($key){'weightGrams'=>'weight_grams','lengthMm'=>'length_mm','widthMm'=>'width_mm','heightMm'=>'height_mm'};
  $default=(int)$p[$source]*($key==='weightGrams'?(int)$case['quantity']:1);
  $values[$key]=az_integer($body[$key]??$default,1,$key==='weightGrams'?150000:5000,$key);
 }
 return $values;
}
function az_return_label_quote(array $user,array $body):array{
 $id=az_text($body['returnId']??'',32,'Return case');[$case,$original]=az_return_label_require($user,$id);
 $cfg=az_carrier_require_config();$parcel=az_return_label_parcel($body,$case);
 $lock=az_provider_operation_lock('return-label',$id);
 try{
  if(az_return_label_row($id))throw new AzHttpError(409,'Return label already purchased or awaiting reconciliation.','return_label_existing');
  $old=az_return_label_quote_row($id);
  if($old&&strtotime($old['expires_at'])>time()&&($body['refresh']??false)!==true){
   if(json_decode((string)$old['parcel_json'],true)!==$parcel)
    throw new AzHttpError(409,'Package dimensions changed; refresh provider rates.','parcel_changed');
   return az_return_label_snapshot($id);
  }
  $a=az_local_decrypt($original['contact_ciphertext']);
  if(($a['country']??'US')!=='US')throw new AzHttpError(409,'Only US return labels are supported.','return_country_unsupported');
  $from=['name'=>$a['name'],'street1'=>$a['street'],'street2'=>$a['unit']??'',
   'city'=>$a['city'],'state'=>$a['state'],'zip'=>$a['zip'],'country'=>'US'];
  $remote=az_carrier_provider_request('POST','/shipments',[
   'shipment'=>['reference'=>'azh-return-'.$id,'is_return'=>true,'from_address'=>$from,
    'to_address'=>$cfg['from'],
    'parcel'=>['weight'=>max(.1,round($parcel['weightGrams']/28.349523125,2)),
     'length'=>round($parcel['lengthMm']/25.4,2),
     'width'=>round($parcel['widthMm']/25.4,2),
     'height'=>round($parcel['heightMm']/25.4,2)]]
  ],$cfg);
  $shipmentId=(string)($remote['id']??'');
  if(!preg_match('/^shp_[A-Za-z0-9_]{6,}$/D',$shipmentId))
   throw new AzHttpError(502,'Carrier return shipment ID invalid.','carrier_response_mismatch');
  $rates=az_carrier_rates_from_shipment($remote,$cfg['mode']);
  if(!$rates)throw new AzHttpError(409,'No available return carrier rates.','return_rates_empty');
  $now=az_now();$expires=gmdate('Y-m-d\TH:i:s\Z',time()+900);
  return az_commerce_returns_lock_write(static function(PDO $db)use($id,$shipmentId,$rates,$parcel,$cfg,$now,$expires,$user):array{
   if(az_return_label_row($id))throw new AzHttpError(409,'Return label already purchased.','return_label_existing');
   $old=az_return_label_quote_row($id);
   if($old){
    $db->prepare('UPDATE commerce_return_label_quotes SET provider_shipment_id=?,rates_json=?,parcel_json=?,mode=?,revision=revision+1,created_at=?,expires_at=?,created_by=? WHERE return_id=?')
     ->execute([$shipmentId,json_encode($rates,JSON_THROW_ON_ERROR),json_encode($parcel,JSON_THROW_ON_ERROR),$cfg['mode'],$now,$expires,(int)$user['id'],$id]);
   }else{
    $db->prepare('INSERT INTO commerce_return_label_quotes(return_id,provider_shipment_id,rates_json,parcel_json,mode,created_at,expires_at,created_by) VALUES(?,?,?,?,?,?,?,?)')
     ->execute([$id,$shipmentId,json_encode($rates,JSON_THROW_ON_ERROR),json_encode($parcel,JSON_THROW_ON_ERROR),$cfg['mode'],$now,$expires,(int)$user['id']]);
   }
   return az_return_label_snapshot($id);
  });
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function az_return_label_validate(array $remote,array $label):array{
 $rate=$remote['selected_rate']??[];
 $price=is_array($rate)?az_carrier_rate_price($rate['rate']??null):null;
 $url=az_carrier_label_url($remote['postage_label']['label_url']??null);
 $number=(string)($remote['tracking_code']??'');
 if(($remote['id']??'')!==$label['shipment_id']||($remote['mode']??'')!==$label['mode']
    ||($rate['id']??'')!==$label['rate_id']||($rate['carrier']??'')!==$label['carrier']
    ||$price!==(int)$label['rate_cents']||$url===null
    ||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{5,69}$/D',$number))
  throw new AzHttpError(409,'Carrier return label response mismatch; reconcile original purchase.','return_label_mismatch');
 return ['url'=>$url,'tracking'=>$number];
}
function az_return_label_confirm(PDO $db,array $label,array $remote):array{
 $ok=az_return_label_validate($remote,$label);
 $db->prepare("UPDATE commerce_return_labels SET status='purchased',tracking_number=?,label_url=?,purchased_at=?,updated_at=? WHERE return_id=?")
  ->execute([$ok['tracking'],$ok['url'],az_now(),az_now(),$label['return_id']]);
 az_commerce_queue_admins('return-label-purchased:'.$label['return_id'],(int)$label['purchased_by'],
  'return_label_purchased','Return label purchased',
  'Approved return postage of '.number_format((int)$label['rate_cents']/100,2).
  ' USD was purchased. Share the HTTPS label securely with the verified buyer. Physical acceptance is not confirmed.',true);
 return az_return_label_snapshot($label['return_id']);
}
function az_return_label_buy(array $user,array $body):array{
 az_commerce_admin($user);$cfg=az_carrier_require_config(true);
 if(!filter_var(getenv('AZH_EASYPOST_ALLOW_RETURN_LABEL_PURCHASES')?:'0',FILTER_VALIDATE_BOOLEAN))
  throw new AzHttpError(403,'Return label charges are separately disabled.','return_label_purchase_disabled');
 if(($body['confirmPurchase']??false)!==true)
  throw new AzHttpError(400,'Explicitly approve the return label charge.','confirmation_required');
 $id=az_text($body['returnId']??'',32,'Return case');
 $revision=az_integer($body['revision']??0,1,PHP_INT_MAX,'Quote revision');
 $rateId=az_text($body['rateId']??'',100,'Carrier rate');
 $expected=az_integer($body['expectedCents']??0,1,1000000,'Expected cost');
 az_return_label_require($user,$id);$lock=az_provider_operation_lock('return-label',$id);
 try{
  if(az_return_label_row($id))throw new AzHttpError(409,'Return label already requested; reconcile instead.','return_label_existing');
  $quote=az_return_label_quote_row($id);
  if(!$quote||$quote['mode']!==$cfg['mode']||(int)$quote['revision']!==$revision||strtotime($quote['expires_at'])<=time())
   throw new AzHttpError(409,'Return carrier quote expired or changed.','quote_expired');
  $selected=null;
  foreach(json_decode((string)$quote['rates_json'],true)?:[] as $rate)
   if(($rate['id']??'')===$rateId){$selected=$rate;break;}
  if(!$selected||$selected['rateCents']!==$expected||$expected>$cfg['maxLabelCents'])
   throw new AzHttpError(403,'Carrier amount changed or exceeds the private limit.','return_rate_rejected');
  $label=az_commerce_returns_lock_write(static function(PDO $db)use($id,$quote,$selected,$user):array{
   if(az_return_label_row($id))throw new AzHttpError(409,'Return label already requested.','return_label_existing');
   $now=az_now();
   $db->prepare("INSERT INTO commerce_return_labels(return_id,shipment_id,rate_id,carrier,service,rate_cents,mode,status,purchased_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,'pending',?,?,?)")
     ->execute([$id,$quote['provider_shipment_id'],$selected['id'],$selected['carrier'],$selected['service'],
      $selected['rateCents'],$quote['mode'],(int)$user['id'],$now,$now]);
   return az_return_label_row($id);
  });
  try{
   $remote=az_carrier_provider_request('POST','/shipments/'.$label['shipment_id'].'/buy',['rate'=>['id'=>$label['rate_id']]],$cfg);
   return az_commerce_returns_lock_write(static function(PDO $db)use($label,$remote):array{
    return az_return_label_confirm($db,$label,$remote);
   });
  }catch(Throwable $e){
   az_commerce_returns_lock_write(static function(PDO $db)use($id):array{
    $db->prepare("UPDATE commerce_return_labels SET status='needs_review',updated_at=? WHERE return_id=? AND status='pending'")
      ->execute([az_now(),$id]);return ['id'=>$id];
   });
   throw new AzHttpError(409,'Return label charge outcome uncertain; GET reconcile before any new purchase.','return_label_outcome_unknown');
  }
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function az_return_label_reconcile(array $user,array $body):array{
 az_commerce_admin($user);$cfg=az_carrier_require_config();
 $id=az_text($body['returnId']??'',32,'Return case');$lock=az_provider_operation_lock('return-label',$id);
 try{
  $label=az_return_label_row($id);
  if(!$label)throw new AzHttpError(404,'No return label purchase.','return_label_missing');
  if($label['mode']!==$cfg['mode'])throw new AzHttpError(409,'Carrier mode differs.','provider_mode_mismatch');
  if($label['status']==='purchased')return az_return_label_snapshot($id);
  $remote=az_carrier_provider_request('GET','/shipments/'.$label['shipment_id'],null,$cfg);
  if(empty($remote['postage_label']['label_url']))
   return ['status'=>'needs_review','notice'=>'No provider label verified; no second purchase attempted.'];
  return az_commerce_returns_lock_write(static function(PDO $db)use($label,$remote):array{
   return az_return_label_confirm($db,$label,$remote);
  });
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
