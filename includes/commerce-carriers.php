<?php
declare(strict_types=1);
/**
 * v1.34 Carrier Specialist: EasyPost-backed rate shopping, explicit label
 * authorization, and fail-closed accounting. No API key or destination PII is
 * included in public responses, Brain, History, Chat, or server logs.
 */
function az_upgrade_commerce_carrier_schema(PDO $db): void
{
    if(az_schema_version($db)>=40 || az_schema_version($db)<39)return;
    az_prepare_storage();$lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Carrier schema lock unavailable.');
    try{
        if(az_schema_version($db)>=40)return;
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $uid=$mysql?'BIGINT UNSIGNED':'INTEGER';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_carrier_quotes (
            order_id CHAR(32) PRIMARY KEY,
            provider_shipment_id VARCHAR(100) NOT NULL UNIQUE,
            mode VARCHAR(14) NOT NULL,
            rates_json TEXT NOT NULL,
            parcel_json TEXT NOT NULL,
            quote_revision INTEGER NOT NULL DEFAULT 1,
            created_at VARCHAR(32) NOT NULL,
            expires_at VARCHAR(32) NOT NULL,
            requested_by $uid NOT NULL,
            FOREIGN KEY(order_id) REFERENCES commerce_shipments(order_id) ON DELETE CASCADE,
            FOREIGN KEY(requested_by) REFERENCES users(id))$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_carrier_labels (
            order_id CHAR(32) PRIMARY KEY,
            provider_shipment_id VARCHAR(100) NOT NULL UNIQUE,
            provider_rate_id VARCHAR(100) NOT NULL,
            rate_cents BIGINT NOT NULL,
            carrier VARCHAR(20) NOT NULL,
            service VARCHAR(100) NOT NULL,
            mode VARCHAR(14) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            tracking_number VARCHAR(100) NOT NULL DEFAULT '',
            tracker_id VARCHAR(100) NULL UNIQUE,
            label_url TEXT NULL,
            tracking_status VARCHAR(32) NOT NULL DEFAULT 'unknown',
            tracking_updated_at VARCHAR(32) NULL,
            requested_by $uid NOT NULL,
            created_at VARCHAR(32) NOT NULL,
            purchased_at VARCHAR(32) NULL,
            updated_at VARCHAR(32) NOT NULL,
            FOREIGN KEY(order_id) REFERENCES commerce_shipments(order_id) ON DELETE CASCADE,
            FOREIGN KEY(requested_by) REFERENCES users(id))$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_carrier_events (
            event_id VARCHAR(140) PRIMARY KEY,
            tracker_id VARCHAR(100) NOT NULL DEFAULT '',
            event_kind VARCHAR(80) NOT NULL,
            received_at VARCHAR(32) NOT NULL)$suffix");
        foreach([
            ['commerce_shipments','carrier_tracking_status',"VARCHAR(32) NOT NULL DEFAULT 'unknown'"],
            ['commerce_shipments','carrier_tracking_updated_at','VARCHAR(32) NULL']
        ] as [$table,$col,$type]){
            if(!az_stripe_has_column($db,$table,$col))$db->exec("ALTER TABLE $table ADD COLUMN $col $type");
        }
        if($mysql){
            $q=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
            foreach([['commerce_carrier_labels','carrier_label_state','(status,updated_at)'],
                     ['commerce_carrier_events','carrier_event_tracker','(tracker_id,received_at)']] as [$table,$name,$columns]){
                $q->execute([$table,$name]);if(!(int)$q->fetchColumn())$db->exec("CREATE INDEX $name ON $table $columns");
            }
        }else{
            $db->exec('CREATE INDEX IF NOT EXISTS carrier_label_state ON commerce_carrier_labels(status,updated_at)');
            $db->exec('CREATE INDEX IF NOT EXISTS carrier_event_tracker ON commerce_carrier_events(tracker_id,received_at)');
        }
        $db->exec('UPDATE studio_schema SET version=40 WHERE id=1');
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function az_carrier_config(): array
{
    $private=(az_config()['commerce']??[]);
    if(!is_array($private))$private=[];
    $read=static function(string $env,string $key)use($private):string{
        $v=getenv($env);
        return is_string($v)&&$v!==''?$v:(string)($private[$key]??'');
    };
    $key=$read('AZH_EASYPOST_API_KEY','easypost_api_key');
    $mode=str_starts_with($key,'EZTK')?'test':(str_starts_with($key,'EZAK')?'production':'unknown');
    $truth=static fn(string $value):bool=>filter_var($value,FILTER_VALIDATE_BOOLEAN);
    $live=$truth($read('AZH_EASYPOST_ALLOW_LIVE','easypost_allow_live'));
    $purchase=$truth($read('AZH_EASYPOST_ALLOW_LABEL_PURCHASES','easypost_allow_label_purchases'));
    $from=[
        'name'=>$read('AZH_EASYPOST_FROM_NAME','easypost_from_name'),
        'street1'=>$read('AZH_EASYPOST_FROM_STREET','easypost_from_street'),
        'city'=>$read('AZH_EASYPOST_FROM_CITY','easypost_from_city'),
        'state'=>strtoupper($read('AZH_EASYPOST_FROM_STATE','easypost_from_state')),
        'zip'=>$read('AZH_EASYPOST_FROM_ZIP','easypost_from_zip'),
        'country'=>'US',
        'phone'=>$read('AZH_EASYPOST_FROM_PHONE','easypost_from_phone')
    ];
    $originReady=$from['name']!==''&&$from['street1']!==''&&$from['city']!==''
        &&preg_match('/^[A-Z]{2}$/D',$from['state'])
        &&preg_match('/^\d{5}(?:-\d{4})?$/D',$from['zip'])
        &&preg_match('/^\+?[\d(). -]{10,22}$/D',$from['phone']);
    $configured=strlen($key)>=16&&$mode!=='unknown'&&$originReady&&($mode==='test'||$live);
    $max=$read('AZH_EASYPOST_MAX_LABEL_CENTS','easypost_max_label_cents');
    $maxCents=preg_match('/^[0-9]{1,6}$/D',$max)?min(100000,(int)$max):3000;
    $secret=$read('AZH_EASYPOST_WEBHOOK_SECRET','easypost_webhook_secret');
    return [
        'key'=>$key,'mode'=>$mode,'from'=>$from,'configured'=>(bool)$configured,
        'allowPurchase'=>$configured&&$purchase,'liveEnabled'=>$live,
        'webhookSecret'=>$secret,'maxLabelCents'=>$maxCents,
        'webhookConfigured'=>strlen($secret)>=24
    ];
}
function az_carrier_public_status(): array
{
    $c=az_carrier_config();
    return ['provider'=>'EasyPost','configured'=>$c['configured'],'mode'=>$c['mode'],
        'purchaseEnabled'=>$c['allowPurchase'],'webhookConfigured'=>$c['webhookConfigured'],
        'maxLabelCents'=>$c['maxLabelCents'],
        'carriers'=>['USPS','UPS','FedEx'],
        'notice'=>'Quotes are provider-supplied only when configured. Purchases require staff confirmation. No carrier updates are invented.'];
}
function az_carrier_require_config(bool $buy=false): array
{
    $c=az_carrier_config();
    if(!$c['configured'])throw new AzHttpError(503,'Carrier rates require a configured EasyPost account and ship-from address.','carrier_unconfigured');
    if($buy&&!$c['allowPurchase'])throw new AzHttpError(403,'Carrier label purchases are disabled in private server settings.','carrier_purchase_disabled');
    return $c;
}
function az_carrier_order_lock(string $orderId)
{
    if(!az_valid_id($orderId))throw new AzHttpError(404,'Shipment not found.','not_found');
    az_prepare_storage();
    $file=AZ_STORAGE.'/carrier-order-'.$orderId.'.lock';
    $lock=fopen($file,'c+b');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Carrier order lock unavailable.');
    return $lock;
}
function az_carrier_provider_request(string $method,string $path,?array $body,array $cfg): array
{
    if(!preg_match('#^/shipments(?:/shp_[a-zA-Z0-9_]+(?:/buy)?)?$#D',$path)
        ||!in_array($method,['GET','POST'],true))
        throw new InvalidArgumentException('Unsupported EasyPost endpoint.');
    if(PHP_SAPI==='cli' && isset($GLOBALS['az_carrier_test_transport'])
        &&is_callable($GLOBALS['az_carrier_test_transport']))
        return ($GLOBALS['az_carrier_test_transport'])($method,$path,$body);
    if(!function_exists('curl_init'))throw new AzHttpError(503,'Carrier transport unavailable on this server.','carrier_unavailable');
    $ch=curl_init('https://api.easypost.com/v2'.$path);
    $headers=['Accept: application/json','Content-Type: application/json'];
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_USERPWD=>$cfg['key'].':',
        CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_CUSTOMREQUEST=>$method
    ]);
    if($method==='POST')curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));
    $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($raw===false||$code<200||$code>=300||strlen((string)$raw)>250000){
        // Deliberately no raw provider errors: some contain recipient addresses.
        throw new AzHttpError(502,'Carrier provider request failed. Verify setup or reconcile an existing label before retrying.','carrier_provider_unavailable');
    }
    $parsed=json_decode((string)$raw,true,32,JSON_THROW_ON_ERROR);
    if(!is_array($parsed))throw new AzHttpError(502,'Carrier response was invalid.','carrier_provider_unavailable');
    return $parsed;
}
function az_carrier_shipment_row(string $id): array
{
    if(!az_valid_id($id))throw new AzHttpError(404,'Shipment not found.','not_found');
    $q=az_db()->prepare("SELECT s.order_id,s.status,s.carrier_tracking_status,
        o.quantity,o.state AS payment_state,o.provider,o.product_id,
        p.title,f.weight_grams,f.length_mm,f.width_mm,f.height_mm,f.shipping_enabled
        FROM commerce_shipments s JOIN commerce_orders o ON o.id=s.order_id
        JOIN commerce_products p ON p.id=o.product_id
        LEFT JOIN commerce_product_fulfillment f ON f.product_id=o.product_id
        WHERE s.order_id=?");
    $q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);
    if(!$r)throw new AzHttpError(404,'Shipment not found.','not_found');
    if($r['provider']!=='stripe'||!in_array($r['payment_state'],['paid','partially_refunded'],true)
        ||(int)$r['shipping_enabled']!==1)
        throw new AzHttpError(409,'Only paid, current US carrier shipments can be quoted.','shipping_ineligible');
    return $r;
}
function az_carrier_parcel(array $order,array $body): array
{
    if(($body['confirmParcel']??false)!==true)
        throw new AzHttpError(400,'Staff must verify the packed weight and dimensions.','parcel_confirmation_required');
    $g=az_integer($body['weightGrams']??((int)$order['weight_grams']*(int)$order['quantity']),
        1,150000,'Parcel weight in grams');
    $length=az_integer($body['lengthMm']??(int)$order['length_mm'],1,5000,'Parcel length in millimeters');
    $width=az_integer($body['widthMm']??(int)$order['width_mm'],1,5000,'Parcel width in millimeters');
    $height=az_integer($body['heightMm']??(int)$order['height_mm'],1,5000,'Parcel height in millimeters');
    return ['weightGrams'=>$g,'lengthMm'=>$length,'widthMm'=>$width,'heightMm'=>$height];
}
function az_carrier_rate_price(mixed $value): ?int
{
    if(!is_string($value)&&!is_numeric($value))return null;
    $amount=(string)$value;
    if(!preg_match('/^[0-9]{1,5}(?:\.[0-9]{1,2})?$/D',$amount))return null;
    [$dollars,$cents]=array_pad(explode('.',$amount,2),2,'');
    return (int)$dollars*100+(int)str_pad($cents,2,'0',STR_PAD_RIGHT);
}
function az_carrier_rates_from_shipment(array $shipment,string $mode): array
{
    if(($shipment['object']??'Shipment')!=='Shipment'
        ||($shipment['mode']??'')!==$mode
        ||!is_array($shipment['rates']??null))
        throw new AzHttpError(502,'Carrier shipment mode or rates did not match configuration.','carrier_response_mismatch');
    $rates=[];
    foreach($shipment['rates'] as $r){
        if(!is_array($r))continue;
        $id=(string)($r['id']??'');$carrier=(string)($r['carrier']??'');
        $service=(string)($r['service']??'');$currency=strtoupper((string)($r['currency']??''));
        $cents=az_carrier_rate_price($r['rate']??null);
        if(!preg_match('/^rate_[A-Za-z0-9_]{6,}$/D',$id)
            ||!in_array($carrier,['USPS','UPS','FedEx'],true)
            ||!preg_match('/^[A-Za-z0-9 _.-]{1,100}$/D',$service)
            ||$currency!=='USD'||$cents===null||$cents<1||$cents>1000000)continue;
        $days=isset($r['delivery_days'])&&is_numeric($r['delivery_days'])
            ?max(0,min(365,(int)$r['delivery_days'])):null;
        $rates[]=['id'=>$id,'carrier'=>$carrier,'service'=>$service,
            'rateCents'=>$cents,'deliveryDays'=>$days,'currency'=>'USD'];
    }
    usort($rates,static fn($a,$b)=>$a['rateCents']<=>$b['rateCents']);
    return array_slice($rates,0,30);
}
function az_carrier_quote_row(string $orderId): ?array
{
    $q=az_db()->prepare('SELECT * FROM commerce_carrier_quotes WHERE order_id=?');
    $q->execute([$orderId]);$r=$q->fetch(PDO::FETCH_ASSOC);
    return $r?:null;
}
function az_carrier_label_row(string $orderId): ?array
{
    $q=az_db()->prepare('SELECT * FROM commerce_carrier_labels WHERE order_id=?');
    $q->execute([$orderId]);$r=$q->fetch(PDO::FETCH_ASSOC);
    return $r?:null;
}
function az_carrier_quote_public(array $row): array
{
    return ['shipmentId'=>$row['provider_shipment_id'],'revision'=>(int)$row['quote_revision'],
        'mode'=>$row['mode'],'expiresAt'=>$row['expires_at'],
        'rates'=>json_decode((string)$row['rates_json'],true)?:[]];
}
function az_carrier_quote(array $user,array $body): array
{
    az_commerce_admin($user);$cfg=az_carrier_require_config();
    $id=az_text($body['id']??'',32,'Order ID');$order=az_carrier_shipment_row($id);
    if(!in_array($order['status'],['awaiting_dispatch','packed'],true))
        throw new AzHttpError(409,'Only unshipped paid orders may be quoted.','invalid_shipment_state');
    $parcel=az_carrier_parcel($order,$body);
    $lock=az_carrier_order_lock($id);
    try{
        if(az_carrier_label_row($id))throw new AzHttpError(409,'A label purchase is already recorded or awaiting reconciliation.','label_already_requested');
        $existing=az_carrier_quote_row($id);
        if($existing&&strtotime($existing['expires_at'])>time()
            &&($body['refresh']??false)!==true){
            $original=json_decode((string)$existing['parcel_json'],true);
            if($original!==$parcel)throw new AzHttpError(409,'Parcel changed. Explicitly refresh carrier rates.','parcel_changed');
            return az_carrier_quote_public($existing);
        }
        $destination=az_fulfillment_shipment_details($user,$id)['address'];
        $from=$cfg['from'];
        $to=['name'=>$destination['name'],'street1'=>$destination['street'],
            'street2'=>$destination['unit']??'','city'=>$destination['city'],
            'state'=>$destination['state'],'zip'=>$destination['zip'],'country'=>'US'];
        $grams=(int)$parcel['weightGrams'];
        $oz=max(0.1,round($grams/28.349523125,2));
        $request=['shipment'=>[
            'reference'=>'azh-'.$id,
            'from_address'=>$from,'to_address'=>$to,
            'parcel'=>[
                'weight'=>$oz,
                'length'=>round($parcel['lengthMm']/25.4,2),
                'width'=>round($parcel['widthMm']/25.4,2),
                'height'=>round($parcel['heightMm']/25.4,2)
            ]
        ]];
        $response=az_carrier_provider_request('POST','/shipments',$request,$cfg);
        $providerId=(string)($response['id']??'');
        if(!preg_match('/^shp_[A-Za-z0-9_]{6,}$/D',$providerId))
            throw new AzHttpError(502,'Carrier shipment reference invalid.','carrier_response_mismatch');
        $rates=az_carrier_rates_from_shipment($response,$cfg['mode']);
        if(!$rates)throw new AzHttpError(409,'No enabled USPS/UPS/FedEx carrier returned a usable rate. Check carrier accounts and parcel details.','carrier_rates_empty');
        $expires=gmdate('Y-m-d\TH:i:s\Z',time()+15*60);
        $db=az_db();$db->beginTransaction();
        try{
            $q=$db->prepare('SELECT quote_revision FROM commerce_carrier_quotes WHERE order_id=?');
            $q->execute([$id]);$old=$q->fetchColumn();
            if($old===false){
                $db->prepare('INSERT INTO commerce_carrier_quotes(order_id,provider_shipment_id,mode,rates_json,parcel_json,created_at,expires_at,requested_by) VALUES(?,?,?,?,?,?,?,?)')
                    ->execute([$id,$providerId,$cfg['mode'],json_encode($rates,JSON_THROW_ON_ERROR),
                        json_encode($parcel,JSON_THROW_ON_ERROR),az_now(),$expires,(int)$user['id']]);
            }else{
                $db->prepare('UPDATE commerce_carrier_quotes SET provider_shipment_id=?,mode=?,rates_json=?,parcel_json=?,quote_revision=quote_revision+1,created_at=?,expires_at=?,requested_by=? WHERE order_id=?')
                    ->execute([$providerId,$cfg['mode'],json_encode($rates,JSON_THROW_ON_ERROR),
                        json_encode($parcel,JSON_THROW_ON_ERROR),az_now(),$expires,(int)$user['id'],$id]);
            }
            $db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        $row=az_carrier_quote_row($id);
        return az_carrier_quote_public($row);
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function az_carrier_label_url(mixed $url): ?string
{
    if(!is_string($url)||strlen($url)>2000)return null;
    $parts=parse_url($url);
    if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'
        ||isset($parts['user'])||isset($parts['pass']))return null;
    $host=strtolower((string)($parts['host']??''));
    if($host==='easypost.com'||str_ends_with($host,'.easypost.com'))return $url;
    // EasyPost frequently returns presigned S3 URLs for postage label images.
    if(str_starts_with($host,'easypost-') &&
        (str_contains($host,'.s3.')||str_contains($host,'.s3-'))&&
        str_ends_with($host,'.amazonaws.com'))return $url;
    return null;
}
function az_carrier_record_purchase(PDO $db,array $label,array $shipment,array $cfg): array
{
    $id=(string)($shipment['id']??'');
    $selected=$shipment['selected_rate']??null;
    $providerRate=is_array($selected)?(string)($selected['id']??''):'';
    $price=is_array($selected)?az_carrier_rate_price($selected['rate']??null):null;
    $carrier=is_array($selected)?(string)($selected['carrier']??''):'';
    $number=(string)($shipment['tracking_code']??'');
    $postage=$shipment['postage_label']??null;
    $url=is_array($postage)?az_carrier_label_url($postage['label_url']??null):null;
    $tracker=$shipment['tracker']??null;
    $trackerId=is_array($tracker)?(string)($tracker['id']??''):'';
    if($id!==$label['provider_shipment_id']||$providerRate!==$label['provider_rate_id']
        ||$price!==(int)$label['rate_cents']||$carrier!==$label['carrier']
        ||($shipment['mode']??'')!==$label['mode']
        ||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{5,69}$/D',$number)
        ||$url===null)
        throw new AzHttpError(409,'Carrier purchase response requires manual provider reconciliation.','label_reconciliation_required');
    if($trackerId!==''&&!preg_match('/^trk_[A-Za-z0-9_]{6,}$/D',$trackerId))
        throw new AzHttpError(409,'Carrier tracker reference invalid.','label_reconciliation_required');
    $now=az_now();
    $db->prepare("UPDATE commerce_carrier_labels SET status='purchased',
        tracking_number=?,tracker_id=?,label_url=?,purchased_at=?,updated_at=? WHERE order_id=? AND status IN ('pending','needs_review')")
        ->execute([$number,$trackerId?:null,$url,$now,$now,$label['order_id']]);
    $db->prepare("UPDATE commerce_shipments SET carrier=?,tracking_number=?,updated_at=?,carrier_tracking_status='pre_transit' WHERE order_id=?")
        ->execute([$carrier==='FedEx'?'fedex':strtolower($carrier),$number,$now,$label['order_id']]);
    az_commerce_queue_admins('carrier-label:'.$label['order_id'],(int)$label['requested_by'],'label_purchased',
        'Shipping label purchased','Carrier: '.$carrier.' · Service: '.$label['service'].
        ' · Rate '.$label['rate_cents'].' cents. Label is available to authorized staff in Shipping. No carrier handoff has been confirmed.',true);
    return ['id'=>$label['order_id'],'status'=>'purchased','carrier'=>$carrier,'trackingNumber'=>$number,
        'labelUrl'=>$url,'rateCents'=>(int)$label['rate_cents'],'mode'=>$label['mode']];
}
function az_carrier_buy(array $user,array $body): array
{
    az_commerce_admin($user);$cfg=az_carrier_require_config(true);
    if(($body['confirmPurchase']??false)!==true)
        throw new AzHttpError(400,'Confirm this specific carrier label charge before purchasing.','purchase_confirmation_required');
    $id=az_text($body['id']??'',32,'Order ID');$order=az_carrier_shipment_row($id);
    if($order['status']!=='packed')throw new AzHttpError(409,'Mark the parcel packed before purchasing a label.','parcel_not_packed');
    $rateId=az_text($body['rateId']??'',100,'Carrier rate ID');
    $expected=az_integer($body['expectedCents']??0,1,1000000,'Expected label cost');
    $revision=az_integer($body['revision']??0,1,PHP_INT_MAX,'Quote revision');
    if(!preg_match('/^rate_[A-Za-z0-9_]{6,}$/D',$rateId))
        throw new AzHttpError(400,'Invalid carrier rate.','rate_invalid');
    $lock=az_carrier_order_lock($id);
    try{
        if(az_carrier_label_row($id))throw new AzHttpError(409,'Label already purchased or awaiting provider reconciliation; never buy it twice.','label_already_requested');
        $quote=az_carrier_quote_row($id);
        if(!$quote||strtotime($quote['expires_at'])<=time()||$quote['mode']!==$cfg['mode']
            ||(int)$quote['quote_revision']!==$revision)
            throw new AzHttpError(409,'Carrier rates expired or changed. Refresh rates before purchasing.','quote_expired');
        $rates=json_decode((string)$quote['rates_json'],true);
        $chosen=null;
        foreach(is_array($rates)?$rates:[] as $r)if(($r['id']??'')===$rateId){$chosen=$r;break;}
        if(!$chosen||$chosen['rateCents']!==$expected)
            throw new AzHttpError(409,'Selected carrier rate or price changed.','rate_mismatch');
        if($expected>$cfg['maxLabelCents'])
            throw new AzHttpError(403,'Label exceeds your private maximum purchase amount.','label_budget_exceeded');
        $now=az_now();$db=az_db();
        $db->prepare("INSERT INTO commerce_carrier_labels(order_id,provider_shipment_id,provider_rate_id,
            rate_cents,carrier,service,mode,status,requested_by,created_at,updated_at)
            VALUES(?,?,?,?,?,?,?,'pending',?,?,?)")
          ->execute([$id,$quote['provider_shipment_id'],$rateId,$expected,$chosen['carrier'],
            $chosen['service'],$quote['mode'],(int)$user['id'],$now,$now]);
        // The pending purchase is committed BEFORE the outbound paid operation.
        // A timed-out purchase is never reissued; staff use GET reconciliation.
        try{
            $result=az_carrier_provider_request('POST',
                '/shipments/'.$quote['provider_shipment_id'].'/buy',
                ['rate'=>['id'=>$rateId]],$cfg);
            $db->beginTransaction();
            try{
                $label=az_carrier_label_row($id);
                $response=az_carrier_record_purchase($db,$label,$result,$cfg);
                $db->commit();
            }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        }catch(Throwable $e){
            $db->prepare("UPDATE commerce_carrier_labels SET status='needs_review',updated_at=? WHERE order_id=? AND status='pending'")
              ->execute([az_now(),$id]);
            // Return an actionable failure. Charge may have happened remotely.
            throw new AzHttpError(409,'Label purchase outcome is unconfirmed. Use Reconcile Label to inspect EasyPost. Do not purchase a second label.','label_outcome_unknown');
        }
    }finally{flock($lock,LOCK_UN);fclose($lock);}
    try{az_commerce_dispatch(50);}catch(Throwable $e){error_log('Carrier activity dispatch deferred.');}
    return $response;
}
function az_carrier_reconcile(array $user,array $body): array
{
    az_commerce_admin($user);$cfg=az_carrier_require_config();
    $id=az_text($body['id']??'',32,'Order ID');
    $lock=az_carrier_order_lock($id);
    try{
        $label=az_carrier_label_row($id);
        if(!$label)throw new AzHttpError(404,'There is no carrier label purchase for this order.','label_missing');
        if($label['status']==='purchased')return az_carrier_label_public($label);
        if($label['mode']!==$cfg['mode'])throw new AzHttpError(409,'The label was created in a different provider environment.','carrier_mode_mismatch');
        $remote=az_carrier_provider_request('GET','/shipments/'.$label['provider_shipment_id'],null,$cfg);
        if(!is_array($remote['postage_label']??null)
            ||empty($remote['postage_label']['label_url'])){
            // A missing label is not proof no charge occurred. No retry.
            return ['id'=>$id,'status'=>'needs_review','notice'=>'Provider has not confirmed a purchased label; review in EasyPost before another purchase.'];
        }
        $db=az_db();$db->beginTransaction();
        try{$response=az_carrier_record_purchase($db,$label,$remote,$cfg);$db->commit();}
        catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }finally{flock($lock,LOCK_UN);fclose($lock);}
    try{az_commerce_dispatch(50);}catch(Throwable $e){error_log('Carrier activity dispatch deferred.');}
    return $response;
}
function az_carrier_label_public(array $row): array
{
    return ['status'=>$row['status'],'mode'=>$row['mode'],
        'rateCents'=>(int)$row['rate_cents'],'carrier'=>$row['carrier'],
        'service'=>$row['service'],'trackingNumber'=>$row['tracking_number'],
        'labelUrl'=>$row['status']==='purchased'?$row['label_url']:null,
        'trackingStatus'=>$row['tracking_status'],'purchasedAt'=>$row['purchased_at']];
}
function az_carrier_order_snapshot(string $id): array
{
    $quote=az_carrier_quote_row($id);$label=az_carrier_label_row($id);
    return ['quote'=>$quote?az_carrier_quote_public($quote):null,
        'label'=>$label?az_carrier_label_public($label):null];
}
