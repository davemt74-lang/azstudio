<?php
declare(strict_types=1);
/** Returns/provider-side money movements. Migration is replay-safe after MySQL DDL crashes. */
function az_upgrade_commerce_provider_schema(PDO $db):void{
 if(az_schema_version($db)>=43||az_schema_version($db)<42)return;
 az_prepare_storage();$lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
 if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Provider migration lock unavailable.');
 try{
  if(az_schema_version($db)>=43)return;
  $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
  $uid=$mysql?'BIGINT UNSIGNED':'INTEGER';
  $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
  $db->exec("CREATE TABLE IF NOT EXISTS commerce_provider_refunds(
    return_id CHAR(32) PRIMARY KEY,order_id CHAR(32) NOT NULL,
    payment_intent_id VARCHAR(128) NOT NULL,amount_cents BIGINT NOT NULL,
    idempotency_key VARCHAR(120) NOT NULL UNIQUE,stripe_refund_id VARCHAR(128) NULL UNIQUE,
    mode VARCHAR(10) NOT NULL,status VARCHAR(22) NOT NULL DEFAULT 'pending',
    initiated_by $uid NOT NULL,created_at VARCHAR(32) NOT NULL,updated_at VARCHAR(32) NOT NULL,verified_at VARCHAR(32) NULL,
    FOREIGN KEY(return_id) REFERENCES commerce_returns(id),FOREIGN KEY(order_id) REFERENCES commerce_orders(id),
    FOREIGN KEY(initiated_by) REFERENCES users(id))$suffix");
  $db->exec("CREATE TABLE IF NOT EXISTS commerce_return_label_quotes(
    return_id CHAR(32) PRIMARY KEY,provider_shipment_id VARCHAR(100) NOT NULL UNIQUE,
    rates_json TEXT NOT NULL,parcel_json TEXT NOT NULL,mode VARCHAR(14) NOT NULL,
    revision INTEGER NOT NULL DEFAULT 1,created_at VARCHAR(32) NOT NULL,expires_at VARCHAR(32) NOT NULL,
    created_by $uid NOT NULL,FOREIGN KEY(return_id) REFERENCES commerce_returns(id),
    FOREIGN KEY(created_by) REFERENCES users(id))$suffix");
  $db->exec("CREATE TABLE IF NOT EXISTS commerce_return_labels(
    return_id CHAR(32) PRIMARY KEY,shipment_id VARCHAR(100) NOT NULL UNIQUE,
    rate_id VARCHAR(100) NOT NULL,carrier VARCHAR(20) NOT NULL,service VARCHAR(100) NOT NULL,
    rate_cents BIGINT NOT NULL,mode VARCHAR(14) NOT NULL,status VARCHAR(22) NOT NULL DEFAULT 'pending',
    tracking_number VARCHAR(100) NOT NULL DEFAULT '',label_url TEXT NULL,purchased_by $uid NOT NULL,
    created_at VARCHAR(32) NOT NULL,purchased_at VARCHAR(32) NULL,updated_at VARCHAR(32) NOT NULL,
    FOREIGN KEY(return_id) REFERENCES commerce_returns(id),FOREIGN KEY(purchased_by) REFERENCES users(id))$suffix");
  $db->exec("CREATE TABLE IF NOT EXISTS commerce_connect_accounts(
    model_id $uid PRIMARY KEY,account_id VARCHAR(100) NOT NULL UNIQUE,mode VARCHAR(10) NOT NULL,
    status VARCHAR(18) NOT NULL DEFAULT 'verified',verified_at VARCHAR(32) NOT NULL,
    verified_by $uid NOT NULL,FOREIGN KEY(model_id) REFERENCES users(id),
    FOREIGN KEY(verified_by) REFERENCES users(id))$suffix");
  $db->exec("CREATE TABLE IF NOT EXISTS commerce_connect_transfers(
    batch_id CHAR(32) PRIMARY KEY,account_id VARCHAR(100) NOT NULL,amount_cents BIGINT NOT NULL,
    mode VARCHAR(10) NOT NULL,transfer_group VARCHAR(90) NOT NULL UNIQUE,
    transfer_id VARCHAR(120) NULL UNIQUE,status VARCHAR(22) NOT NULL DEFAULT 'pending',
    authorized_by $uid NOT NULL,created_at VARCHAR(32) NOT NULL,updated_at VARCHAR(32) NOT NULL,
    reconciled_at VARCHAR(32) NULL,FOREIGN KEY(batch_id) REFERENCES commerce_payout_batches(id),
    FOREIGN KEY(authorized_by) REFERENCES users(id))$suffix");
  if($mysql){
   $q=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
   foreach([['commerce_provider_refunds','provider_refunds_by_order','(order_id,status)'],
    ['commerce_return_labels','return_labels_by_status','(status,updated_at)'],
    ['commerce_connect_transfers','connect_transfers_by_status','(status,created_at)']] as [$table,$name,$cols]){
    $q->execute([$table,$name]);if(!(int)$q->fetchColumn())$db->exec("CREATE INDEX $name ON $table $cols");
   }
  }else{
   $db->exec('CREATE INDEX IF NOT EXISTS provider_refunds_by_order ON commerce_provider_refunds(order_id,status)');
   $db->exec('CREATE INDEX IF NOT EXISTS return_labels_by_status ON commerce_return_labels(status,updated_at)');
   $db->exec('CREATE INDEX IF NOT EXISTS connect_transfers_by_status ON commerce_connect_transfers(status,created_at)');
  }
  $db->exec('UPDATE studio_schema SET version=43 WHERE id=1');
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function az_provider_operation_lock(string $scope,string $id){
 if(!az_valid_id($id)||!preg_match('/^[a-z-]{3,30}$/D',$scope))throw new InvalidArgumentException('Unsafe provider lock');
 az_prepare_storage();$f=fopen(AZ_STORAGE.'/commerce-provider-'.$scope.'-'.$id.'.lock','c+b');
 if(!$f||!flock($f,LOCK_EX))throw new RuntimeException('Provider operation lock unavailable.');
 return $f;
}
function az_provider_action_config():array{
 $config=az_config()['commerce']??[];if(!is_array($config))$config=[];
 $get=static function(string $env,string $key)use($config):string{
  $v=getenv($env);return is_string($v)&&$v!==''?$v:(string)($config[$key]??'');
 };
 $bool=static fn(string $v):bool=>filter_var($v,FILTER_VALIDATE_BOOLEAN);
 $r=$get('AZH_STRIPE_REFUND_MAX_CENTS','stripe_refund_max_cents');
 $t=$get('AZH_STRIPE_CONNECT_MAX_TRANSFER_CENTS','stripe_connect_max_transfer_cents');
 return ['refundsEnabled'=>$bool($get('AZH_STRIPE_ALLOW_REFUND_ACTIONS','stripe_allow_refund_actions'))&&az_stripe_config()['ready'],
  'connectEnabled'=>$bool($get('AZH_STRIPE_ALLOW_CONNECT_TRANSFERS','stripe_allow_connect_transfers'))&&az_stripe_config()['ready'],
  'refundMaxCents'=>preg_match('/^[0-9]{1,8}$/D',$r)?min(10000000,(int)$r):50000,
  'connectMaxCents'=>preg_match('/^[0-9]{1,8}$/D',$t)?min(10000000,(int)$t):50000];
}
function az_provider_features():array{
 $p=az_provider_action_config();$carrier=az_carrier_config();$stripe=az_stripe_config();
 return ['stripeMode'=>$stripe['mode'],'refundInitiationEnabled'=>$p['refundsEnabled'],
  'stripeRefundMaxCents'=>$p['refundMaxCents'],'connectTransfersEnabled'=>$p['connectEnabled'],
  'connectMaxCents'=>$p['connectMaxCents'],'easypostMode'=>$carrier['mode'],
  'returnLabelQuotesEnabled'=>$carrier['configured'],
  'returnLabelPurchasesEnabled'=>$carrier['allowPurchase']&&filter_var(getenv('AZH_EASYPOST_ALLOW_RETURN_LABEL_PURCHASES')?:'0',FILTER_VALIDATE_BOOLEAN),
  'labelMaxCents'=>$carrier['maxLabelCents'],
  'notice'=>'All refund, label and Connect transfer operations require human confirmation; production actions are disabled by default.'];
}
/** Strict whitelist: no arbitrary URLs, no private provider errors in HTTP responses. */
function az_provider_stripe_request(string $method,string $path,array $params,array $cfg,?string $idempotency=null):array{
 if(!in_array($method,['GET','POST'],true)||
  !preg_match('#^/(?:refunds(?:/re_[A-Za-z0-9_]{6,})?|transfers(?:/tr_[A-Za-z0-9_]{6,})?|accounts/acct_[A-Za-z0-9_]{6,})$#D',$path))
  throw new InvalidArgumentException('Unsupported Stripe API operation');
 if(PHP_SAPI==='cli'&&isset($GLOBALS['az_provider_stripe_test_transport'])&&is_callable($GLOBALS['az_provider_stripe_test_transport']))
  return ($GLOBALS['az_provider_stripe_test_transport'])($method,$path,$params,$idempotency);
 if(!function_exists('curl_init'))throw new AzHttpError(503,'Provider transport unavailable.','provider_unavailable');
 $url='https://api.stripe.com/v1'.$path;
 $encoded=http_build_query($params,'','&',PHP_QUERY_RFC3986);
 if($method==='GET'&&$encoded!=='')$url.='?'.$encoded;
 $headers=['Authorization: Bearer '.$cfg['key'],'Accept: application/json'];
 if($idempotency!==null)$headers[]='Idempotency-Key: '.$idempotency;
 $ch=curl_init($url);
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>25,
  CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>$headers]);
 if($method==='POST')curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$encoded]);
 $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
 if($raw===false||$status<200||$status>=300||strlen((string)$raw)>400000)
  throw new AzHttpError(502,'Provider outcome not confirmed. Reconcile before taking further action.','provider_outcome_unknown');
 $result=json_decode((string)$raw,true,32);
 if(!is_array($result))throw new AzHttpError(502,'Provider response malformed.','provider_outcome_unknown');
 return $result;
}
function az_provider_briefing(array $user):array{
 if(!az_has_role($user,'admin'))return ['cards'=>[],'counts'=>[]];
 $db=az_db();$counts=[];
 foreach(['refundsNeedsReconciliation'=>"SELECT COUNT(*) FROM commerce_provider_refunds WHERE status IN ('pending','needs_review','provider_pending')",
 'returnLabelsNeedReconciliation'=>"SELECT COUNT(*) FROM commerce_return_labels WHERE status IN ('pending','needs_review')",
 'connectTransfersNeedReconciliation'=>"SELECT COUNT(*) FROM commerce_connect_transfers WHERE status IN ('pending','needs_review')"] as $key=>$sql)
 $counts[$key]=(int)$db->query($sql)->fetchColumn();
 $cards=[];
 if(array_sum($counts)>0){
  $triage=az_provider_triage_snapshot($db,24);
  $overdue=count(array_filter($triage['items'],static fn(array $item):bool=>$item['urgency']==='overdue'));
  $summary=array_sum($counts).' refund, return-label or Connect transfer records need verification.';
  if($overdue>0)$summary.=' At least '.$overdue.' are overdue.';
  $summary.=' Reconcile the original provider record; do not repeat the money movement.';
  $cards[]=az_agent_workspace_card('commerce','Provider operations need reconciliation',
    $summary,'commerce.php?tab=providers',$overdue>0?0:1);
 }
 return ['cards'=>$cards,'counts'=>$counts];
}
