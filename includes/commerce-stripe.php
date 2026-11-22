<?php
declare(strict_types=1);
/**
 * Stripe-hosted checkout adapter. Only signed Stripe webhooks can create verified
 * orders. Merchant API keys live in server environment or protected config, never DB.
 * External-seller products continue to use manual, explicitly unverified records.
 */
function az_stripe_has_column(PDO $db,string $table,string $column): bool
{
    if(!in_array($table,['commerce_products','commerce_orders','commerce_live_features'],true))throw new InvalidArgumentException('Unsafe schema table');
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        $q=$db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
        $q->execute([$table,$column]);return (int)$q->fetchColumn()>0;
    }
    $q=$db->query('PRAGMA table_info('.$table.')');
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)if($r['name']===$column)return true;
    return false;
}
function az_upgrade_commerce_stripe_schema(PDO $db): void
{
    if (az_schema_version($db)>=37 || az_schema_version($db)<36) return;
    az_prepare_storage();
    $lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if (!$lock || !flock($lock,LOCK_EX))throw new RuntimeException('Cannot lock Commerce checkout migration.');
    try {
        if (az_schema_version($db)>=37)return;
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $uid=$mysql?'BIGINT UNSIGNED':'INTEGER';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        foreach([
            ['commerce_products','checkout_mode',"VARCHAR(12) NOT NULL DEFAULT 'external'"],
            ['commerce_orders','provider',"VARCHAR(16) NOT NULL DEFAULT 'manual'"],
            ['commerce_orders','refunded_cents','BIGINT NOT NULL DEFAULT 0'],
            ['commerce_orders','provider_total_cents','BIGINT NOT NULL DEFAULT 0'],
            ['commerce_orders','provider_tax_cents','BIGINT NOT NULL DEFAULT 0'],
            ['commerce_orders','provider_payment_id','VARCHAR(128) NULL'],
            ['commerce_live_features','promotion_id','CHAR(32) NULL']
        ] as [$table,$column,$definition]){
            // A crashed MySQL DDL is not transactional: each column is replay-safe.
            if(!az_stripe_has_column($db,$table,$column))
                $db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
        }
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_checkout_attempts (
            id CHAR(32) PRIMARY KEY, attempt_key CHAR(64) NOT NULL UNIQUE,
            product_id CHAR(32) NOT NULL, promotion_id CHAR(32) NULL,
            channel VARCHAR(12) NOT NULL, source_ref CHAR(32) NULL,
            quantity INTEGER NOT NULL, unit_cents BIGINT NOT NULL, amount_cents BIGINT NOT NULL,
            commission_bps INTEGER NOT NULL, currency CHAR(3) NOT NULL,
            mode VARCHAR(8) NOT NULL, state VARCHAR(16) NOT NULL DEFAULT 'creating',
            session_id VARCHAR(150) NULL UNIQUE, redirect_url TEXT NULL,
            created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL,
            FOREIGN KEY(product_id) REFERENCES commerce_products(id),
            FOREIGN KEY(promotion_id) REFERENCES commerce_promotions(id))$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_stripe_events (
            event_id VARCHAR(140) PRIMARY KEY, kind VARCHAR(80) NOT NULL,
            received_at VARCHAR(32) NOT NULL)$suffix");
        if ($mysql) {
            $q=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
            foreach ([['commerce_checkout_attempts','commerce_checkout_product','(product_id,created_at)'],
                      ['commerce_orders','commerce_provider_payment','(provider_payment_id)']] as [$table,$index,$cols]){
                $q->execute([$table,$index]);if(!(int)$q->fetchColumn())$db->exec("CREATE ".($index==='commerce_provider_payment'?'UNIQUE ':'')."INDEX $index ON $table $cols");
            }
        } else {
            $db->exec('CREATE INDEX IF NOT EXISTS commerce_checkout_product ON commerce_checkout_attempts(product_id,created_at)');
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS commerce_provider_payment ON commerce_orders(provider_payment_id)');
        }
        $db->exec('UPDATE studio_schema SET version=37 WHERE id=1');
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function az_stripe_config(): array
{
    $config=az_config()['commerce']??[];
    if (!is_array($config))$config=[];
    $read=static function(string $env,string $key)use($config):string {
        $value=getenv($env);
        return is_string($value)&&$value!==''?$value:(string)($config[$key]??'');
    };
    $secret=$read('AZH_STRIPE_SECRET_KEY','stripe_secret_key');
    $webhook=$read('AZH_STRIPE_WEBHOOK_SECRET','stripe_webhook_secret');
    $base=rtrim($read('AZH_PUBLIC_BASE_URL','public_base_url'),'/');
    $parts=parse_url($base);
    $safe=is_array($parts)&&strtolower((string)($parts['scheme']??''))==='https'
        &&!empty($parts['host'])&&!isset($parts['user'])&&!isset($parts['pass'])
        &&!isset($parts['query'])&&!isset($parts['fragment'])
        &&preg_match('/^(localhost|.*\.local)$/i',(string)$parts['host'])!==1;
    $live=str_starts_with($secret,'sk_live_');
    $allowedLive=filter_var(getenv('AZH_STRIPE_ALLOW_LIVE')?:($config['stripe_allow_live']??false),FILTER_VALIDATE_BOOLEAN);
    return ['key'=>$secret,'webhook'=>$webhook,'base'=>$safe?$base:'',
        'mode'=>$live?'live':'test',
        'ready'=>(str_starts_with($secret,'sk_test_')||$live)&&strlen($secret)>=16
            &&str_starts_with($webhook,'whsec_')&&strlen($webhook)>=12
            &&$safe && (!$live||$allowedLive)];
}
function az_stripe_public_status(): array
{
    $c=az_stripe_config();
    return ['available'=>$c['ready'],'mode'=>$c['mode'],'provider'=>'Stripe Checkout',
        'note'=>$c['ready']?'Payment status is confirmed by signed Stripe events, not browser redirects.':'Stripe is not configured or approved for this environment.'];
}
function az_stripe_assert_ready(): array
{
    $c=az_stripe_config();
    if(!$c['ready'])throw new AzHttpError(503,'Stripe Checkout is not configured for this site.','checkout_unavailable');
    return $c;
}
function az_stripe_product_ready(array $product): bool
{
    return $product['state']==='active' && ($product['checkout_mode']??'external')==='stripe'
        &&az_stripe_config()['ready'] && (int)$product['price_cents']>0 && strtoupper((string)$product['currency'])==='USD';
}
function az_stripe_promotion_row(string $promotionId,string $productId,?string $videoId=null): array
{
    if(!az_valid_id($promotionId))throw new AzHttpError(404,'Promotion does not exist.','promotion_missing');
    $q=az_db()->prepare("SELECT a.*,v.status AS video_status,v.revision AS current_revision,
        v.rights_confirmed,v.published_at,u.status AS model_status
        FROM commerce_promotions a JOIN model_videos v ON v.id=a.video_id AND v.user_id=a.model_id
        JOIN users u ON u.id=a.model_id
        WHERE a.id=? AND a.product_id=?");
    $q->execute([$promotionId,$productId]);$r=$q->fetch();
    if(!$r||$r['state']!=='approved'||$r['video_status']!=='published'||$r['published_at']===null
        ||(int)$r['current_revision']!==(int)$r['video_revision']
        ||(int)$r['rights_confirmed']!==1||$r['model_status']!=='active'
        ||($videoId!==null&&$r['video_id']!==$videoId))
        throw new AzHttpError(409,'This video no longer has an approved, current product link.','attribution_unavailable');
    return $r;
}
/** An attributable sale must originate from an approved visible video or live feature. */
function az_stripe_attribution(string $productId,array $body): array
{
    $channel=az_text($body['channel']??'shop',12,'Sales channel');
    if(!in_array($channel,['shop','video','live'],true))throw new AzHttpError(400,'Invalid shopping source.','invalid_channel');
    if($channel==='shop')return [null,'shop',null,0];
    if($channel==='video'){
        $video=az_text($body['videoId']??'',32,'Video');
        $promo=az_text($body['promotionId']??'',32,'Promotion');
        if(!az_valid_id($video))throw new AzHttpError(400,'Choose an eligible video.','invalid_video');
        $r=az_stripe_promotion_row($promo,$productId,$video);
        return [$r['id'],'video',$video,1];
    }
    $room=az_live_active_room();$requested=az_text($body['roomId']??'',32,'Live room');
    if(!$room||$room['id']!==$requested)throw new AzHttpError(409,'This shopping feature is no longer live.','live_ended');
    $q=az_db()->prepare('SELECT product_id,promotion_id FROM commerce_live_features WHERE room_id=?');
    $q->execute([$room['id']]);$feature=$q->fetch();
    if(!$feature||$feature['product_id']!==$productId)throw new AzHttpError(409,'This product is no longer featured in Live.','feature_changed');
    $promotionId=$feature['promotion_id']??null;
    if($promotionId)az_stripe_promotion_row($promotionId,$productId);
    return [$promotionId,'live',$room['id'],$promotionId?1:0];
}
/** Restrict transport to Stripe; tests can replace the network transport in CLI only. */
function az_stripe_create_remote(array $data,string $idempotencyKey,string $secret): array
{
    if(PHP_SAPI==='cli' && isset($GLOBALS['az_stripe_test_transport']) && is_callable($GLOBALS['az_stripe_test_transport']))
        return ($GLOBALS['az_stripe_test_transport'])($data,$idempotencyKey);
    if(!function_exists('curl_init'))throw new AzHttpError(503,'Checkout transport is not available on this host.','checkout_unavailable');
    $ch=curl_init('https://api.stripe.com/v1/checkout/sessions');
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data,'','&',PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$secret,'Idempotency-Key: azh-'.$idempotencyKey,'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>18,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false,
    ]);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    if($body===false||$status<200||$status>=300){
        error_log('Checkout provider request failed: '.($error!==''?'transport':$status));
        throw new AzHttpError(502,'Payment provider could not create a checkout. Retry shortly.','provider_unavailable');
    }
    $result=json_decode((string)$body,true);
    if(!is_array($result))throw new AzHttpError(502,'Payment provider response was invalid.','provider_unavailable');
    return $result;
}
function az_stripe_session_payload(array $attempt,array $product,array $config): array
{
    $return=$config['base'].'/shop.php?checkout=';
    $data=[
        'mode'=>'payment','payment_method_types'=>['card'],
        'line_items'=>[['price_data'=>['currency'=>'usd','unit_amount'=>(int)$attempt['unit_cents'],
            'product_data'=>['name'=>mb_safe_trim((string)$product['title'],160)]],
            'quantity'=>(int)$attempt['quantity']]],
        'client_reference_id'=>$attempt['id'],
        'metadata'=>['azh_attempt_id'=>$attempt['id']],
        'payment_intent_data'=>['metadata'=>['azh_attempt_id'=>$attempt['id']]],
        'success_url'=>$return.'returned',
        'cancel_url'=>$return.'cancelled',
        'billing_address_collection'=>'auto',
    ];
    if(($attempt['fulfillment_method']??'none')==='shipping'){
        $fee=(int)$attempt['shipping_fee_cents'];
        $data['shipping_address_collection']=['allowed_countries'=>['US']];
        $data['shipping_options']=[['shipping_rate_data'=>[
            'type'=>'fixed_amount',
            'display_name'=>$fee===0?'Free shipping':'Standard US shipping',
            'fixed_amount'=>['amount'=>$fee,'currency'=>'usd']]]];
    }
    $data['expires_at']=time()+1800; // 30 minutes; outstanding stock holds need a signed expiry event.
    $autoTax=filter_var(getenv('AZH_STRIPE_AUTOMATIC_TAX')?:((az_config()['commerce']??[])['stripe_automatic_tax']??false),FILTER_VALIDATE_BOOLEAN);
    if($autoTax)$data['automatic_tax']=['enabled'=>'true'];
    return $data;
}
/** Browser POST is CSRF-checked by the endpoint. Never accept caller-supplied prices. */
function az_stripe_checkout_start(array $body): array
{
    $c=az_stripe_assert_ready();
    az_account_throttle('stripe-checkout',18,900);
    $id=az_text($body['productId']??'',32,'Product');
    $product=az_commerce_row($id);
    if(!az_stripe_product_ready($product))throw new AzHttpError(409,'This product is not available for Stripe Checkout.','product_unavailable');
    $qty=az_integer($body['quantity']??1,1,10,'Quantity');
    if((int)$product['price_cents']*$qty>100000000)
        throw new AzHttpError(400,'Order exceeds the checkout limit.','invalid_quantity');
    $token=az_text($body['clientToken']??'',32,'Checkout token');
    if(!az_valid_id($token))throw new AzHttpError(400,'Invalid checkout token.','invalid_token');
    [$promotionId,$channel,$sourceRef,$attributed]=az_stripe_attribution($id,$body);
    $stock=az_fulfillment_row($id);
    $ship=(int)$stock['shipping_enabled']===1?'shipping':'none';
    $shippingFee=$ship==='shipping'?az_fulfillment_shipping_fee($id,(int)$product['price_cents']*$qty):0;
    $identity=session_id();
    if($identity==='')throw new AzHttpError(403,'A browser session is required.','session_required');
    $attemptKey=hash('sha256',$identity.'|'.$token);
    $lock=az_community_lock();
    try {
        $db=az_db();$db->beginTransaction();
        try {
            $q=$db->prepare('SELECT * FROM commerce_checkout_attempts WHERE attempt_key=?');
            $q->execute([$attemptKey]);$old=$q->fetch();
            if($old){
                if($old['product_id']!==$id||(int)$old['quantity']!==$qty
                    ||$old['promotion_id']!==$promotionId||$old['channel']!==$channel
                    ||$old['source_ref']!==$sourceRef)
                    throw new AzHttpError(409,'This checkout token was used for another cart.','token_reused');
                $attempt=$old;
            }else{
                $now=az_now();$attemptId=az_id();$mode=$c['mode'];
                $commission=$attributed?(int)$product['commission_bps']:0;
                $q=$db->prepare('INSERT INTO commerce_checkout_attempts(
                    id,attempt_key,product_id,promotion_id,channel,source_ref,quantity,unit_cents,amount_cents,
                    commission_bps,currency,mode,shipping_fee_cents,fulfillment_method,created_at,updated_at
                    ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $q->execute([$attemptId,$attemptKey,$id,$promotionId,$channel,$sourceRef,$qty,
                    (int)$product['price_cents'],(int)$product['price_cents']*$qty,
                    $commission,'USD',$mode,$shippingFee,$ship,$now,$now]);
                az_inventory_reserve($db,$id,$qty,hash('sha256','stripe|'.$attemptId),'stripe',null,$attemptId);
                $q=$db->prepare('SELECT * FROM commerce_checkout_attempts WHERE id=?');$q->execute([$attemptId]);
                $attempt=$q->fetch();
            }
            $db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }finally{flock($lock,LOCK_UN);fclose($lock);}
    if($attempt['state']==='open' && $attempt['redirect_url']!==null && $attempt['redirect_url']!=='')
        return ['checkoutUrl'=>$attempt['redirect_url'],'status'=>$attempt['state'],'mode'=>$attempt['mode']];
    if(in_array($attempt['state'],['expired','paid','failed'],true))
        throw new AzHttpError(409,'Checkout already completed or expired. Start a fresh checkout.','checkout_finished');
    $session=az_stripe_create_remote(az_stripe_session_payload($attempt,$product,$c),$attempt['id'],$c['key']);
    $sessionId=(string)($session['id']??'');$url=(string)($session['url']??'');
    $urlParts=parse_url($url);
    if(!preg_match('/^cs_(?:test_)?[A-Za-z0-9_]{8,}$/D',$sessionId)
        ||!is_array($urlParts)||strtolower((string)($urlParts['scheme']??''))!=='https'
        ||(string)($urlParts['host']??'')!=='checkout.stripe.com'
        ||strlen($url)>4000)
        throw new AzHttpError(502,'Payment provider returned an unexpected checkout destination.','provider_unavailable');
    $lock=az_community_lock();
    try {
        $q=az_db()->prepare("UPDATE commerce_checkout_attempts SET session_id=?,redirect_url=?,state='open',updated_at=? WHERE id=? AND state='creating'");
        $q->execute([$sessionId,$url,az_now(),$attempt['id']]);
        $q=az_db()->prepare('SELECT * FROM commerce_checkout_attempts WHERE id=?');$q->execute([$attempt['id']]);
        $saved=$q->fetch();
        if(!$saved||$saved['session_id']!==$sessionId)
            throw new AzHttpError(409,'Checkout state changed. Reload to continue.','checkout_changed');
    }finally{flock($lock,LOCK_UN);fclose($lock);}
    return ['checkoutUrl'=>$url,'status'=>'open','mode'=>$attempt['mode']];
}
/** Stripe-Signature: t=<epoch>,v1=<hex HMAC_SHA256(t.raw_body)>. */
function az_stripe_verify_signature(string $raw,string $header,string $secret,?int $now=null): bool
{
    if(strlen($raw)>262144 || strlen($header)>1600 || strlen($secret)<12)return false;
    $parts=explode(',',$header);$timestamp=null;$signatures=[];
    foreach($parts as $piece){
        $pair=explode('=',trim($piece),2);
        if(count($pair)!==2)continue;
        if($pair[0]==='t' && preg_match('/^[0-9]{10,12}$/D',$pair[1]))$timestamp=(int)$pair[1];
        if($pair[0]==='v1' && preg_match('/^[a-f0-9]{64}$/iD',$pair[1]))$signatures[]=strtolower($pair[1]);
    }
    $now=$now??time();
    if($timestamp===null||abs($now-$timestamp)>300||!$signatures)return false;
    $calculated=hash_hmac('sha256',$timestamp.'.'.$raw,$secret);
    foreach($signatures as $sig)if(hash_equals($calculated,$sig))return true;
    return false;
}
function az_stripe_webhook_receive(string $raw,string $signature): array
{
    $c=az_stripe_assert_ready();
    if(!az_stripe_verify_signature($raw,$signature,$c['webhook']))throw new AzHttpError(400,'Webhook signature invalid.','signature_invalid');
    $event=json_decode($raw,true);
    if(!is_array($event)||!is_string($event['id']??null)||!preg_match('/^evt_[A-Za-z0-9_]{4,120}$/D',$event['id'])
        ||($event['object']??'')!=='event'||!is_bool($event['livemode']??null)
        ||$event['livemode']!==($c['mode']==='live'))
        throw new AzHttpError(400,'Webhook event invalid for this environment.','webhook_invalid');
    $type=(string)($event['type']??'');
    $obj=$event['data']['object']??null;
    if(!is_array($obj))throw new AzHttpError(400,'Webhook event did not contain an object.','webhook_invalid');
    $lock=az_community_lock();$recognized=false;
    try {
        $db=az_db();$db->beginTransaction();
        try{
            $check=$db->prepare('SELECT event_id FROM commerce_stripe_events WHERE event_id=?');
            $check->execute([$event['id']]);
            if($check->fetchColumn()){
                $db->commit();return ['duplicate'=>true,'processed'=>false];
            }
            if(in_array($type,['checkout.session.completed','checkout.session.async_payment_succeeded'],true)){
                az_stripe_apply_payment($db,$obj);$recognized=true;
            }elseif($type==='checkout.session.async_payment_failed'){
                az_stripe_apply_failure($db,$obj);$recognized=true;
            }elseif($type==='checkout.session.expired'){
                az_stripe_apply_expiry($db,$obj);$recognized=true;
            }elseif($type==='charge.refunded'){
                az_stripe_apply_refund($db,$obj);$recognized=true;
            }elseif($type==='charge.dispute.created'){
                az_stripe_apply_dispute($db,$obj);$recognized=true;
            }elseif($type==='charge.dispute.closed'){
                az_stripe_apply_dispute_closed($db,$obj);$recognized=true;
            }
            $db->prepare('INSERT INTO commerce_stripe_events(event_id,kind,received_at) VALUES(?,?,?)')
                ->execute([$event['id'],substr($type,0,80),az_now()]);
            $db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }finally{flock($lock,LOCK_UN);fclose($lock);}
    try {az_commerce_dispatch(100);}catch(Throwable $e){error_log('Stripe agent dispatch deferred: '.$e->getMessage());}
    return ['duplicate'=>false,'processed'=>$recognized];
}
function az_stripe_attempt_for_session(PDO $db,array $session): array
{
    $sid=(string)($session['id']??'');
    $q=$db->prepare('SELECT * FROM commerce_checkout_attempts WHERE session_id=?');
    $q->execute([$sid]);$r=$q->fetch();
    if(!$r)throw new AzHttpError(409,'Unknown checkout session. Retry after creation is saved.','checkout_missing');
    $metadata=$session['metadata']??[];
    $details=$session['total_details']??null;
    $subtotal=$session['amount_subtotal']??null;$total=$session['amount_total']??null;
    $tax=is_array($details)?($details['amount_tax']??null):null;
    $shipping=is_array($details)?($details['amount_shipping']??null):null;
    $discount=is_array($details)?($details['amount_discount']??null):null;
    if(!is_array($metadata)||($metadata['azh_attempt_id']??'')!==$r['id']
        ||($session['client_reference_id']??'')!==$r['id']||($session['mode']??'')!=='payment'
        ||!is_int($subtotal)||$subtotal!==(int)$r['amount_cents']
        ||!is_int($total)||!is_int($tax)||!is_int($shipping)||!is_int($discount)
        ||$tax<0||$shipping<0||$discount<0||$total!==$subtotal+$tax+$shipping-$discount
        ||$shipping!==(int)$r['shipping_fee_cents']
        ||strtolower((string)($session['currency']??''))!=='usd'
        ||!is_bool($session['livemode']??null)||$session['livemode']!==($r['mode']==='live'))
        throw new AzHttpError(409,'Checkout subtotal, Stripe total, currency, mode or metadata did not match.','checkout_mismatch');
    return $r;
}
function az_stripe_apply_payment(PDO $db,array $session): void
{
    $attempt=az_stripe_attempt_for_session($db,$session);
    if(($session['payment_status']??'')!=='paid')return; // delayed payment settles later.
    $pi=$session['payment_intent']??'';
    if(!is_string($pi)||!preg_match('/^pi_[A-Za-z0-9_]{6,}$/D',$pi))
        throw new AzHttpError(409,'A verified payment intent is required.','missing_payment_intent');
    $reference='stripe:'.$attempt['id'];$q=$db->prepare('SELECT * FROM commerce_orders WHERE external_reference=?');
    $q->execute([$reference]);$old=$q->fetch();
    if($old){if($old['provider_payment_id']!==$pi)throw new AzHttpError(409,'Payment intent did not match existing order.','order_mismatch');return;}
    $price=(int)$attempt['amount_cents'];$commission=intdiv($price*(int)$attempt['commission_bps'],10000);
    $q=$db->prepare('SELECT owner_id,title FROM commerce_products WHERE id=?');$q->execute([$attempt['product_id']]);$product=$q->fetch();
    if(!$product)throw new AzHttpError(409,'Original checkout product no longer exists.','product_missing');
    $orderId=az_id();
    $db->prepare("INSERT INTO commerce_orders(id,external_reference,product_id,promotion_id,quantity,amount_cents,currency,commission_bps,commission_cents,state,evidence_reference,recorded_by,created_at,paid_at,updated_at,provider,refunded_cents,provider_payment_id,provider_total_cents,provider_tax_cents)
        VALUES(?,?,?,?,?,?,?,?,?,'paid',?,?,?,?,?,'stripe',0,?,?,?)")
      ->execute([$orderId,$reference,$attempt['product_id'],$attempt['promotion_id'],(int)$attempt['quantity'],
        $price,'USD',(int)$attempt['commission_bps'],$commission,$pi,(int)$product['owner_id'],az_now(),az_now(),az_now(),$pi,
        (int)$session['amount_total'],(int)$session['total_details']['amount_tax']]);
    // This entire payment, stock, shipping, and notification change commits or rolls back together.
    az_inventory_resolve($db,hash('sha256','stripe|'.$attempt['id']),'consumed');
    az_fulfillment_create_shipment($db,$orderId,$attempt,$session,(string)$product['title']);
    $db->prepare("UPDATE commerce_checkout_attempts SET state='paid',updated_at=? WHERE id=?")->execute([az_now(),$attempt['id']]);
    az_commerce_queue_admins('stripe-paid:'.$attempt['id'],(int)$product['owner_id'],'stripe_paid',
        'Verified Stripe sale',$product['title'].' · A signed provider event confirmed a paid checkout. Review fulfillment. ',true);
    if($attempt['promotion_id']){
        $q=$db->prepare('SELECT model_id FROM commerce_promotions WHERE id=?');$q->execute([$attempt['promotion_id']]);
        $model=(int)($q->fetchColumn()?:0);
        if($model)az_commerce_queue('stripe-creator-paid:'.$attempt['id'],(int)$product['owner_id'],$model,'stripe_commission',
            'Verified creator sale','Stripe confirmed a paid order attributed to your approved product link. Estimated commission is tracked; payout is not automatic.','commerce.php',true);
    }
}
function az_stripe_apply_failure(PDO $db,array $session): void
{
    $r=az_stripe_attempt_for_session($db,$session);
    $q=$db->prepare("UPDATE commerce_checkout_attempts SET state='failed',redirect_url=NULL,updated_at=? WHERE id=? AND state='open'");
    $q->execute([az_now(),$r['id']]);
    if($q->rowCount()===1)az_inventory_resolve($db,hash('sha256','stripe|'.$r['id']),'released');
}
function az_stripe_apply_expiry(PDO $db,array $session): void
{
    $r=az_stripe_attempt_for_session($db,$session);
    $q=$db->prepare("UPDATE commerce_checkout_attempts SET state='expired',redirect_url=NULL,updated_at=? WHERE id=? AND state='open'");
    $q->execute([az_now(),$r['id']]);
    if($q->rowCount()===1)az_inventory_resolve($db,hash('sha256','stripe|'.$r['id']),'released');
}
function az_stripe_apply_refund(PDO $db,array $charge): void
{
    $paymentId=$charge['payment_intent']??'';
    $refunded=$charge['amount_refunded']??null;
    if(!is_string($paymentId)||!preg_match('/^pi_[A-Za-z0-9_]{6,}$/D',$paymentId)
        ||!is_int($refunded)||$refunded<0)
        throw new AzHttpError(400,'Refund event invalid.','refund_invalid');
    $q=$db->prepare("SELECT o.*,p.title,p.owner_id,m.model_id FROM commerce_orders o
        JOIN commerce_products p ON p.id=o.product_id
        LEFT JOIN commerce_promotions m ON m.id=o.promotion_id
        WHERE o.provider='stripe' AND o.provider_payment_id=?");
    $q->execute([$paymentId]);$order=$q->fetch();
    if(!$order)throw new AzHttpError(409,'Payment not yet recorded. Stripe should retry.','payment_pending');
    $amount=(int)$order['amount_cents'];
    if($refunded>$amount)throw new AzHttpError(409,'Refund exceeded original paid amount.','refund_mismatch');
    $refunded=max((int)$order['refunded_cents'],$refunded);
    if($refunded===(int)$order['refunded_cents'])return;
    $remaining=$amount-$refunded;
    $state=in_array($order['state'],['disputed','reversed'],true)?$order['state']:($remaining===0?'refunded':'partially_refunded');
    $commission=in_array($state,['disputed','reversed','refunded'],true)?0:intdiv($remaining*(int)$order['commission_bps'],10000);
    $db->prepare('UPDATE commerce_orders SET state=?,refunded_cents=?,commission_cents=?,updated_at=? WHERE id=? AND provider=?')
        ->execute([$state,$refunded,$commission,az_now(),$order['id'],'stripe']);
    az_commerce_queue_admins('stripe-refund:'.$order['id'].':'.$refunded,(int)$order['owner_id'],'stripe_refund',
        $state==='refunded'?'Verified refund':'Verified partial refund',
        $order['title'].' · Signed provider refund changed recognized revenue and commission liability.',true);
    if(!empty($order['model_id']))az_commerce_queue('creator-refund:'.$order['id'].':'.$refunded,(int)$order['owner_id'],(int)$order['model_id'],
        'stripe_refund','Affiliate commission adjusted','Stripe confirmed a refund. Your estimated commission was recalculated from the remaining paid amount.','commerce.php',true);
}
function az_stripe_apply_dispute(PDO $db,array $dispute): void
{
    $paymentId=$dispute['payment_intent']??null;
    if(!is_string($paymentId)||!preg_match('/^pi_[A-Za-z0-9_]{6,}$/D',$paymentId))return;
    $q=$db->prepare("SELECT o.*,p.title,p.owner_id,m.model_id FROM commerce_orders o
        JOIN commerce_products p ON p.id=o.product_id LEFT JOIN commerce_promotions m ON m.id=o.promotion_id
        WHERE o.provider='stripe' AND o.provider_payment_id=?");
    $q->execute([$paymentId]);$order=$q->fetch();
    if(!$order)throw new AzHttpError(409,'Payment not yet recorded. Stripe should retry.','payment_pending');
    if(in_array($order['state'],['refunded','disputed'],true))return;
    $db->prepare("UPDATE commerce_orders SET state='disputed',commission_cents=0,updated_at=? WHERE id=?")
        ->execute([az_now(),$order['id']]);
    az_commerce_queue_admins('stripe-dispute:'.$order['id'],(int)$order['owner_id'],'stripe_dispute',
        'Payment disputed — needs review',
        $order['title'].' · Stripe reported a dispute. Creator commission has been held pending review.',true);
    if(!empty($order['model_id']))az_commerce_queue('creator-dispute:'.$order['id'],(int)$order['owner_id'],(int)$order['model_id'],
        'stripe_dispute','Affiliate commission temporarily held',
        'A Stripe dispute is open on an attributed order; the estimated commission is withheld.','commerce.php',true);
}

/** Resolve a Stripe dispute using only provider-signed terminal outcomes. */
function az_stripe_apply_dispute_closed(PDO $db,array $dispute): void
{
    $paymentId=$dispute['payment_intent']??null;
    if(!is_string($paymentId)||!preg_match('/^pi_[A-Za-z0-9_]{6,}$/D',$paymentId))return;
    $q=$db->prepare("SELECT o.*,p.owner_id,p.title,m.model_id FROM commerce_orders o
        JOIN commerce_products p ON p.id=o.product_id LEFT JOIN commerce_promotions m ON m.id=o.promotion_id
        WHERE o.provider='stripe' AND o.provider_payment_id=?");
    $q->execute([$paymentId]);$order=$q->fetch();
    if(!$order)throw new AzHttpError(409,'Payment not yet recorded. Stripe should retry.','payment_pending');
    if($order['state']!=='disputed')return;
    $outcome=(string)($dispute['status']??'');
    if(!in_array($outcome,['won','lost','expired'],true))return;
    $amount=(int)$order['amount_cents'];$remaining=max(0,$amount-(int)$order['refunded_cents']);
    $state=$outcome==='won'?($remaining===0?'refunded':($remaining===$amount?'paid':'partially_refunded')):'reversed';
    $commission=($outcome==='won'&&$remaining>0)?intdiv($remaining*(int)$order['commission_bps'],10000):0;
    $db->prepare('UPDATE commerce_orders SET state=?,commission_cents=?,updated_at=? WHERE id=? AND provider=? AND state=?')
       ->execute([$state,$commission,az_now(),$order['id'],'stripe','disputed']);
    az_commerce_queue_admins('stripe-dispute-closed:'.$order['id'].':'.$outcome,(int)$order['owner_id'],'stripe_dispute_closed',
        'Stripe dispute '.$outcome,$order['title'].' · Provider-confirmed dispute result updated revenue and creator commission.',true);
    if(!empty($order['model_id']))az_commerce_queue('model-dispute-closed:'.$order['id'].':'.$outcome,
        (int)$order['owner_id'],(int)$order['model_id'],'stripe_dispute_closed',
        'Affiliate dispute '.$outcome,'A signed Stripe outcome updated your estimated commission. No payout was performed.','commerce.php',true);
}
