<?php
declare(strict_types=1);
/**
 * v1.33: per-product shipping settings, stock reservations and manual carrier
 * fulfillment. No carrier accounts, label purchases or tracking polling.
 * All customer shipping contact stays encrypted and admin-only.
 */
function az_upgrade_commerce_fulfillment_schema(PDO $db): void
{
    if(az_schema_version($db)>=39 || az_schema_version($db)<38)return;
    az_prepare_storage();
    $lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Fulfillment migration lock unavailable.');
    try{
        if(az_schema_version($db)>=39)return;
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $uid=$mysql?'BIGINT UNSIGNED':'INTEGER';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_product_fulfillment (
            product_id CHAR(32) PRIMARY KEY,
            sku VARCHAR(64) NOT NULL DEFAULT '',
            stock_mode VARCHAR(16) NOT NULL DEFAULT 'unlimited',
            on_hand BIGINT NOT NULL DEFAULT 0,
            reserved BIGINT NOT NULL DEFAULT 0,
            low_stock_threshold INTEGER NOT NULL DEFAULT 3,
            shipping_enabled INTEGER NOT NULL DEFAULT 0,
            shipping_rate VARCHAR(12) NOT NULL DEFAULT 'flat',
            shipping_fee_cents BIGINT NOT NULL DEFAULT 0,
            free_above_cents BIGINT NOT NULL DEFAULT 0,
            weight_grams INTEGER NOT NULL DEFAULT 0,
            length_mm INTEGER NOT NULL DEFAULT 0,
            width_mm INTEGER NOT NULL DEFAULT 0,
            height_mm INTEGER NOT NULL DEFAULT 0,
            handling_days INTEGER NOT NULL DEFAULT 3,
            ship_country CHAR(2) NOT NULL DEFAULT 'US',
            revision INTEGER NOT NULL DEFAULT 1,
            updated_at VARCHAR(32) NOT NULL,
            FOREIGN KEY(product_id) REFERENCES commerce_products(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_stock_reservations (
            stock_key CHAR(64) PRIMARY KEY,
            product_id CHAR(32) NOT NULL,
            order_id CHAR(32) NULL,
            checkout_attempt_id CHAR(32) NULL,
            source VARCHAR(12) NOT NULL,
            quantity INTEGER NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'held',
            created_at VARCHAR(32) NOT NULL,
            updated_at VARCHAR(32) NOT NULL,
            FOREIGN KEY(product_id) REFERENCES commerce_products(id),
            FOREIGN KEY(order_id) REFERENCES commerce_orders(id),
            FOREIGN KEY(checkout_attempt_id) REFERENCES commerce_checkout_attempts(id))$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_inventory_events (
            id CHAR(32) PRIMARY KEY, product_id CHAR(32) NOT NULL,
            actor_id $uid NULL, event_kind VARCHAR(28) NOT NULL,
            quantity_delta BIGINT NOT NULL,
            detail VARCHAR(240) NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL,
            FOREIGN KEY(product_id) REFERENCES commerce_products(id),
            FOREIGN KEY(actor_id) REFERENCES users(id))$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_shipments (
            order_id CHAR(32) PRIMARY KEY,
            contact_ciphertext TEXT NOT NULL,
            product_title VARCHAR(160) NOT NULL,
            shipping_fee_cents BIGINT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'awaiting_dispatch',
            carrier VARCHAR(20) NOT NULL DEFAULT '',
            tracking_number VARCHAR(100) NOT NULL DEFAULT '',
            revision INTEGER NOT NULL DEFAULT 1,
            created_at VARCHAR(32) NOT NULL,
            updated_at VARCHAR(32) NOT NULL,
            shipped_at VARCHAR(32) NULL,
            delivered_at VARCHAR(32) NULL,
            last_actor_id $uid NULL,
            FOREIGN KEY(order_id) REFERENCES commerce_orders(id) ON DELETE CASCADE,
            FOREIGN KEY(last_actor_id) REFERENCES users(id))$suffix");
        // No automatic migration of legacy orders into shipments. Address must be
        // explicitly available from Stripe checkout or entered by an admin.
        foreach([
            ['commerce_checkout_attempts','shipping_fee_cents','BIGINT NOT NULL DEFAULT 0'],
            ['commerce_checkout_attempts','fulfillment_method',"VARCHAR(12) NOT NULL DEFAULT 'none'"]
        ] as [$table,$column,$definition]){
            if(!az_stripe_has_column($db,$table,$column))
                $db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
        }
        if($mysql){
            $q=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
            foreach([
                ['commerce_stock_reservations','commerce_reserve_by_product','(product_id,status)'],
                ['commerce_stock_reservations','commerce_reserve_by_attempt','(checkout_attempt_id)'],
                ['commerce_stock_reservations','commerce_reserve_by_order','(order_id)'],
                ['commerce_inventory_events','commerce_inventory_history','(product_id,created_at)'],
                ['commerce_shipments','commerce_shipments_queue','(status,updated_at)']
            ] as [$table,$name,$columns]){
                $q->execute([$table,$name]);
                if(!(int)$q->fetchColumn())$db->exec("CREATE INDEX $name ON $table $columns");
            }
        }else{
            $db->exec('CREATE INDEX IF NOT EXISTS commerce_reserve_by_product ON commerce_stock_reservations(product_id,status)');
            $db->exec('CREATE INDEX IF NOT EXISTS commerce_reserve_by_attempt ON commerce_stock_reservations(checkout_attempt_id)');
            $db->exec('CREATE INDEX IF NOT EXISTS commerce_reserve_by_order ON commerce_stock_reservations(order_id)');
            $db->exec('CREATE INDEX IF NOT EXISTS commerce_inventory_history ON commerce_inventory_events(product_id,created_at)');
            $db->exec('CREATE INDEX IF NOT EXISTS commerce_shipments_queue ON commerce_shipments(status,updated_at)');
        }
        $db->exec('UPDATE studio_schema SET version=39 WHERE id=1');
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function az_fulfillment_row(string $productId): array
{
    if(!az_valid_id($productId))throw new AzHttpError(404,'Product unavailable.','not_found');
    $q=az_db()->prepare('SELECT * FROM commerce_product_fulfillment WHERE product_id=?');
    $q->execute([$productId]);$row=$q->fetch(PDO::FETCH_ASSOC);
    return $row?:[
        'product_id'=>$productId,'sku'=>'','stock_mode'=>'unlimited','on_hand'=>0,
        'reserved'=>0,'low_stock_threshold'=>3,'shipping_enabled'=>0,'shipping_rate'=>'flat',
        'shipping_fee_cents'=>0,'free_above_cents'=>0,'weight_grams'=>0,
        'length_mm'=>0,'width_mm'=>0,'height_mm'=>0,'handling_days'=>3,
        'ship_country'=>'US','revision'=>0,'updated_at'=>''
    ];
}
function az_fulfillment_public(array $product): array
{
    $row=az_fulfillment_row((string)$product['id']);
    $available=$row['stock_mode']==='tracked'?
        max(0,(int)$row['on_hand']-(int)$row['reserved']):null;
    return ['shippingEnabled'=>(int)$row['shipping_enabled']===1,
        'stockMode'=>$row['stock_mode'],'inStock'=>$available===null||$available>0,
        'lowStock'=>$available!==null && $available<=(int)$row['low_stock_threshold'],
        'available'=>$available,'handlingDays'=>(int)$row['handling_days'],
        'shippingRate'=>$row['shipping_rate'],
        'shippingFeeCents'=>(int)$row['shipping_fee_cents'],
        'freeAboveCents'=>(int)$row['free_above_cents'],
        'shipCountry'=>$row['ship_country'],
        'carrierCheckoutAvailable'=>$row['shipping_rate']==='carrier'?az_customer_ship_ready():false];
}
function az_fulfillment_admin(array $user,string $productId): array
{
    az_commerce_admin($user);
    $row=az_fulfillment_row($productId);
    $p=az_fulfillment_public(['id'=>$productId]);
    return $p+['sku'=>$row['sku'],'revision'=>(int)$row['revision'],
        'onHand'=>(int)$row['on_hand'],'reserved'=>(int)$row['reserved'],
        'lowStockThreshold'=>(int)$row['low_stock_threshold'],
        'weightGrams'=>(int)$row['weight_grams'],
        'lengthMm'=>(int)$row['length_mm'],'widthMm'=>(int)$row['width_mm'],
        'heightMm'=>(int)$row['height_mm']];
}
function az_fulfillment_prepare(array $body): array
{
    $mode=az_text($body['stockMode']??'unlimited',16,'Inventory mode');
    if(!in_array($mode,['tracked','unlimited','made_to_order'],true))
        throw new AzHttpError(400,'Invalid inventory mode.','invalid_inventory_mode');
    $sku=az_text($body['sku']??'',64,'SKU');
    if($sku!==''&&!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D',$sku))
        throw new AzHttpError(400,'SKU must use letters, numbers, dots, dashes or underscores.','invalid_sku');
    $ship=az_bool($body['shippingEnabled']??false,'Shipping available');
    $rate=az_text($body['shippingRate']??'flat',12,'Shipping price method');
    if(!in_array($rate,['flat','free','carrier'],true))throw new AzHttpError(400,'Choose flat, free or live carrier shipping.','invalid_shipping_rate');
    $country=az_text($body['shipCountry']??'US',2,'Shipping country');
    if($country!=='US')throw new AzHttpError(400,'This release only supports US shipping.','unsupported_country');
    $fields=[
        'stockOnHand'=>[0,100000000],'stockRevision'=>[0,PHP_INT_MAX],
        'lowStockThreshold'=>[0,100000],
        'shippingFeeCents'=>[0,1000000],'freeAboveCents'=>[0,100000000],
        'weightGrams'=>[0,100000],
        'lengthMm'=>[0,3000],'widthMm'=>[0,3000],'heightMm'=>[0,3000],
        'handlingDays'=>[1,30],
    ];
    $out=['stockMode'=>$mode,'sku'=>$sku,'shippingEnabled'=>$ship,
        'shippingRate'=>$rate,'shipCountry'=>$country];
    foreach($fields as $key=>[$min,$max])$out[$key]=az_integer($body[$key]??($key==='handlingDays'?3:0),$min,$max,$key);
    $reason=az_text($body['stockAdjustmentReason']??'',180,'Stock adjustment reason');
    $out['stockAdjustmentReason']=$reason;
    if($ship&&$mode==='made_to_order'&&$out['handlingDays']<2)
        throw new AzHttpError(400,'Made-to-order shipping requires at least two handling days.','invalid_handling');
    if($ship&&$rate==='flat'&&$out['shippingFeeCents']===0)
        throw new AzHttpError(400,'Choose a flat shipping amount or select Free shipping.','invalid_shipping_fee');
    if($ship&&($out['weightGrams']===0||$out['lengthMm']===0||$out['widthMm']===0||$out['heightMm']===0))
        throw new AzHttpError(400,'Enter shipping weight and all three package dimensions.','missing_package');
    return $out;
}
function az_fulfillment_save(PDO $db,string $id,array $settings,int $actor): void
{
    $previous=az_fulfillment_row($id);
    $oldRev=(int)$previous['revision'];
    if($settings['stockRevision']!==$oldRev)
        throw new AzHttpError(409,'Inventory or shipping settings changed. Reload before saving.','revision_conflict');
    $oldReserved=(int)$previous['reserved'];
    if($settings['stockMode']==='tracked'&&$settings['stockOnHand']<$oldReserved)
        throw new AzHttpError(409,'Stock cannot be set below existing held reservations.','stock_reserved');
    if($oldRev!==0 && $previous['stock_mode']!==$settings['stockMode']&&$oldReserved>0)
        throw new AzHttpError(409,'Change inventory mode only after reserved orders settle.','stock_reserved');
    $delta=$settings['stockOnHand']-(int)$previous['on_hand'];
    if($oldRev>0&&$delta!==0&&mb_strlen(trim($settings['stockAdjustmentReason']))<6)
        throw new AzHttpError(400,'Record why the product inventory quantity changed.','stock_reason_required');
    $columns=[
        $settings['sku'],$settings['stockMode'],$settings['stockOnHand'],
        $settings['lowStockThreshold'],$settings['shippingEnabled']?1:0,
        $settings['shippingRate'],$settings['shippingFeeCents'],$settings['freeAboveCents'],
        $settings['weightGrams'],$settings['lengthMm'],$settings['widthMm'],
        $settings['heightMm'],$settings['handlingDays'],$settings['shipCountry']
    ];
    $now=az_now();
    if($oldRev===0){
        $q=$db->prepare('INSERT INTO commerce_product_fulfillment(product_id,sku,stock_mode,on_hand,
            low_stock_threshold,shipping_enabled,shipping_rate,shipping_fee_cents,free_above_cents,
            weight_grams,length_mm,width_mm,height_mm,handling_days,ship_country,updated_at)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute(array_merge([$id],$columns,[$now]));
    }else{
        $q=$db->prepare('UPDATE commerce_product_fulfillment SET sku=?,stock_mode=?,on_hand=?,
            low_stock_threshold=?,shipping_enabled=?,shipping_rate=?,shipping_fee_cents=?,free_above_cents=?,
            weight_grams=?,length_mm=?,width_mm=?,height_mm=?,handling_days=?,ship_country=?,
            revision=revision+1,updated_at=? WHERE product_id=? AND revision=?');
        $q->execute(array_merge($columns,[$now,$id,$oldRev]));
        if($q->rowCount()!==1)throw new AzHttpError(409,'Inventory changed while editing.','revision_conflict');
    }
    if($delta!==0){
        $db->prepare('INSERT INTO commerce_inventory_events(id,product_id,actor_id,event_kind,quantity_delta,detail,created_at) VALUES(?,?,?,?,?,?,?)')
            ->execute([az_id(),$id,$actor,'stock_adjustment',$delta,
                $oldRev===0?'Initial stock':$settings['stockAdjustmentReason'],$now]);
    }
}
function az_inventory_reserve(PDO $db,string $productId,int $quantity,string $stockKey,string $source,?string $orderId=null,?string $attemptId=null): void
{
    $stock=az_fulfillment_row($productId);
    if($stock['stock_mode']!=='tracked')return;
    $q=$db->prepare('SELECT status FROM commerce_stock_reservations WHERE stock_key=?');
    $q->execute([$stockKey]);
    if($q->fetchColumn()!==false)return; // Idempotent retry; source identity is stable.
    $available=(int)$stock['on_hand']-(int)$stock['reserved'];
    if($quantity>$available)throw new AzHttpError(409,'This product no longer has sufficient stock.','insufficient_stock');
    $updated=$db->prepare('UPDATE commerce_product_fulfillment SET reserved=reserved+?,revision=revision+1,updated_at=? WHERE product_id=? AND stock_mode=? AND on_hand-reserved>=0+?');
    $updated->execute([$quantity,az_now(),$productId,'tracked',$quantity]);
    if($updated->rowCount()!==1)throw new AzHttpError(409,'Inventory changed before your order.','insufficient_stock');
    $db->prepare("INSERT INTO commerce_stock_reservations(stock_key,product_id,order_id,checkout_attempt_id,
       source,quantity,status,created_at,updated_at) VALUES(?,?,?,?,?,?,'held',?,?)")
       ->execute([$stockKey,$productId,$orderId,$attemptId,$source,$quantity,az_now(),az_now()]);
}
function az_inventory_resolve(PDO $db,string $stockKey,string $resolution): bool
{
    if(!in_array($resolution,['consumed','released'],true))throw new InvalidArgumentException('Unsupported reservation resolution.');
    $q=$db->prepare('SELECT * FROM commerce_stock_reservations WHERE stock_key=?');
    $q->execute([$stockKey]);$r=$q->fetch(PDO::FETCH_ASSOC);
    if(!$r)return false; // Unlimited / made-to-order product.
    if($r['status']===$resolution)return false;
    if($r['status']!=='held')throw new AzHttpError(409,'Inventory reservation was already resolved.','stock_reservation_conflict');
    $qty=(int)$r['quantity'];
    $change=$resolution==='consumed'?'on_hand=on_hand-?,reserved=reserved-?':'reserved=reserved-?';
    $sql='UPDATE commerce_product_fulfillment SET '.$change.',revision=revision+1,updated_at=? WHERE product_id=? AND reserved>=?';
    $args=$resolution==='consumed'?[$qty,$qty,az_now(),$r['product_id'],$qty]:[$qty,az_now(),$r['product_id'],$qty];
    if($resolution==='consumed'){$sql.=' AND on_hand>=?';$args[]=$qty;}
    $updated=$db->prepare($sql);$updated->execute($args);
    if($updated->rowCount()!==1)throw new AzHttpError(409,'Inventory reservation could not be reconciled.','stock_reservation_conflict');
    $db->prepare('UPDATE commerce_stock_reservations SET status=?,updated_at=? WHERE stock_key=? AND status=?')
       ->execute([$resolution,az_now(),$stockKey,'held']);
    $db->prepare('INSERT INTO commerce_inventory_events(id,product_id,actor_id,event_kind,quantity_delta,detail,created_at) VALUES(?,?,NULL,?,?,?,?)')
       ->execute([az_id(),$r['product_id'],$resolution==='consumed'?'sale_consumed':'reservation_released',
           $resolution==='consumed'?-$qty:0,'Reservation '.$resolution,az_now()]);
    return true;
}
function az_fulfillment_shipping_fee(string $productId,int $subtotal): int
{
    $r=az_fulfillment_row($productId);
    if((int)$r['shipping_enabled']!==1)throw new AzHttpError(409,'Shipping is not enabled for this product.','shipping_unavailable');
    if($r['shipping_rate']==='carrier')throw new AzHttpError(409,'A server-verified live carrier quote is required before checkout.','carrier_rate_required');
    if($r['shipping_rate']==='free')return 0;
    if((int)$r['free_above_cents']>0&&$subtotal>=(int)$r['free_above_cents'])return 0;
    return (int)$r['shipping_fee_cents'];
}
function az_fulfillment_shipping_address(array $session): array
{
    // Modern Stripe versions expose collected_information.shipping_details;
    // legacy event payloads use shipping_details at top level.
    $collected=$session['collected_information']??null;
    $details=is_array($collected)?($collected['shipping_details']??null):null;
    if(!is_array($details))$details=$session['shipping_details']??null;
    if(!is_array($details)||!is_array($details['address']??null))
        throw new AzHttpError(409,'Stripe shipping address is missing. Retry this signed webhook.','shipping_address_missing');
    $raw=$details['address'];
    $name=az_local_clean($details['name']??'',120,'Shipping recipient name',true);
    $country=strtoupper(az_local_clean($raw['country']??'',2,'Shipping country'));
    if($country!=='US')throw new AzHttpError(409,'Only US delivery is supported.','shipping_country_mismatch');
    $zip=az_local_clean($raw['postal_code']??'',12,'Postal code',true);
    if(!preg_match('/^[0-9]{5}(?:-[0-9]{4})?$/D',$zip))
        throw new AzHttpError(409,'The Stripe shipping address needs a valid US ZIP code.','shipping_address_invalid');
    $street=az_local_clean($raw['line1']??'',180,'Street address',true);
    $unit=az_local_clean($raw['line2']??'',100,'Unit');
    $city=az_local_clean($raw['city']??'',120,'City',true);
    $state=strtoupper(az_local_clean($raw['state']??'',2,'State',true));
    if(!preg_match('/^[A-Z]{2}$/D',$state)||mb_strlen($street)<3)
        throw new AzHttpError(409,'Stripe shipping address is incomplete.','shipping_address_invalid');
    return ['name'=>$name,'street'=>$street,'unit'=>$unit,'city'=>$city,'state'=>$state,
        'zip'=>$zip,'country'=>'US'];
}
function az_fulfillment_create_shipment(PDO $db,string $orderId,array $attempt,array $session,string $title): void
{
    if(($attempt['fulfillment_method']??'none')!=='shipping')return;
    $address=az_fulfillment_shipping_address($session);
    $fee=(int)$attempt['shipping_fee_cents'];
    $now=az_now();
    $db->prepare("INSERT INTO commerce_shipments(order_id,contact_ciphertext,product_title,shipping_fee_cents,status,created_at,updated_at)
         VALUES(?,?,?,?,'awaiting_dispatch',?,?)")
       ->execute([$orderId,az_local_encrypt($address),mb_safe_trim($title,160),$fee,$now,$now]);
}
function az_fulfillment_shipments(array $user): array
{
    az_commerce_admin($user);
    $q=az_db()->query("SELECT s.order_id,s.product_title,s.shipping_fee_cents,s.status,s.carrier,s.tracking_number,
        s.revision,s.created_at,s.updated_at,s.shipped_at,s.delivered_at,
        s.carrier_tracking_status,s.carrier_tracking_updated_at,
        o.quantity,o.state AS payment_state,o.provider
        FROM commerce_shipments s JOIN commerce_orders o ON o.id=s.order_id
        ORDER BY CASE WHEN s.status IN ('awaiting_dispatch','packed','exception') THEN 0 ELSE 1 END,s.updated_at DESC LIMIT 150");
    return array_map(static fn($r)=>[
        'id'=>$r['order_id'],'title'=>$r['product_title'],'status'=>$r['status'],
        'carrier'=>$r['carrier'],'trackingNumber'=>$r['tracking_number'],'revision'=>(int)$r['revision'],
        'createdAt'=>$r['created_at'],'updatedAt'=>$r['updated_at'],'shippedAt'=>$r['shipped_at'],'deliveredAt'=>$r['delivered_at'],
        'quantity'=>(int)$r['quantity'],'paymentState'=>$r['payment_state'],'provider'=>$r['provider'],
        'shippingFeeCents'=>(int)$r['shipping_fee_cents'],
        'carrierTrackingStatus'=>$r['carrier_tracking_status'],
        'carrierTrackingUpdatedAt'=>$r['carrier_tracking_updated_at'],
        'carrierIntegration'=>az_carrier_order_snapshot($r['order_id']),
        'parcel'=>az_carrier_default_parcel($r['order_id']),
        'customerSelectedRate'=>az_customer_ship_order_summary($r['order_id'])
    ],$q->fetchAll(PDO::FETCH_ASSOC));
}
function az_fulfillment_shipment_details(array $user,string $orderId): array
{
    az_commerce_admin($user);
    if(!az_valid_id($orderId))throw new AzHttpError(404,'Shipment not found.','not_found');
    $q=az_db()->prepare('SELECT contact_ciphertext FROM commerce_shipments WHERE order_id=?');
    $q->execute([$orderId]);$cipher=$q->fetchColumn();
    if($cipher===false)throw new AzHttpError(404,'Shipment not found.','not_found');
    return ['id'=>$orderId,'address'=>az_local_decrypt((string)$cipher)];
}
function az_fulfillment_tracking_url(string $carrier,string $number): ?string
{
    // Tracking number is a carrier reference only; never invent carrier events.
    if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{5,69}$/D',$number))return null;
    $encoded=rawurlencode($number);
    return match($carrier){
        'usps'=>'https://tools.usps.com/go/TrackConfirmAction?tLabels='.$encoded,
        'ups'=>'https://www.ups.com/track?tracknum='.$encoded,
        'fedex'=>'https://www.fedex.com/fedextrack/?trknbr='.$encoded,
        default=>null
    };
}
function az_fulfillment_shipment_action(array $user,array $body): array
{
    az_commerce_admin($user);
    $id=az_text($body['id']??'',32,'Shipment');
    if(!az_valid_id($id))throw new AzHttpError(404,'Shipment not found.','not_found');
    $revision=az_integer($body['revision']??0,1,PHP_INT_MAX,'Shipment revision');
    $action=az_text($body['decision']??'',20,'Shipment action');
    if(!in_array($action,['pack','ship','deliver','exception','reopen'],true))
        throw new AzHttpError(400,'Unknown shipment action.','invalid_shipment_action');
    $carrier=az_text($body['carrier']??'',20,'Shipping carrier');
    $tracking=az_text($body['trackingNumber']??'',100,'Tracking number');
    if($action==='ship'&&(!in_array($carrier,['usps','ups','fedex','other'],true)
        ||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{5,69}$/D',$tracking)))
        throw new AzHttpError(400,'Choose a carrier and valid tracking number.','invalid_tracking');
    if($action==='deliver' && ($body['confirmDelivered']??false)!==true)
        throw new AzHttpError(400,'Confirm actual delivery before changing shipment status.','confirmation_required');
    $actor=(int)$user['id'];
    return az_local_write(static function(PDO $db)use($id,$revision,$action,$carrier,$tracking,$actor):array{
        $q=$db->prepare('SELECT s.*,o.state AS order_state,o.provider,p.title,p.owner_id
            FROM commerce_shipments s JOIN commerce_orders o ON o.id=s.order_id
            JOIN commerce_products p ON p.id=o.product_id WHERE s.order_id=?');
        $q->execute([$id]);$r=$q->fetch();
        if(!$r)throw new AzHttpError(404,'Shipment not found.','not_found');
        if((int)$r['revision']!==$revision)
            throw new AzHttpError(409,'Shipment changed. Reload before updating.','revision_conflict');
        if($r['provider']!=='stripe'||!in_array($r['order_state'],['paid','partially_refunded'],true))
            throw new AzHttpError(409,'Only paid, non-disputed Stripe orders can ship.','payment_required');
        if($action==='ship'){
            $label=az_carrier_label_row($id);
            if($label){
                if($label['status']!=='purchased')
                    throw new AzHttpError(409,'Label outcome must be reconciled before dispatch.','label_pending');
                if($tracking!==$label['tracking_number']
                    ||$carrier!==($label['carrier']==='FedEx'?'fedex':strtolower($label['carrier'])))
                    throw new AzHttpError(409,'Use the purchased label tracking information.','label_tracking_mismatch');
            }
        }
        $allowed=['pack'=>['awaiting_dispatch'],'ship'=>['packed'],'deliver'=>['shipped'],
            'exception'=>['awaiting_dispatch','packed','shipped'],'reopen'=>['exception']];
        if(!in_array($r['status'],$allowed[$action],true))
            throw new AzHttpError(409,'Shipment cannot make that transition.','invalid_transition');
        $status=match($action){'pack'=>'packed','ship'=>'shipped','deliver'=>'delivered','exception'=>'exception','reopen'=>'awaiting_dispatch'};
        $now=az_now();
        $q=$db->prepare('UPDATE commerce_shipments SET status=?,carrier=?,tracking_number=?,shipped_at=?,
            delivered_at=?,revision=revision+1,updated_at=?,last_actor_id=? WHERE order_id=? AND revision=?');
        $q->execute([$status,$action==='ship'?$carrier:$r['carrier'],
            $action==='ship'?$tracking:$r['tracking_number'],
            $action==='ship'?$now:$r['shipped_at'],$action==='deliver'?$now:$r['delivered_at'],
            $now,$actor,$id,$revision]);
        if($q->rowCount()!==1)throw new AzHttpError(409,'Shipment changed.','revision_conflict');
        az_commerce_queue_admins('shipment:'.$id.':'.$revision.':'.$action,$actor,'shipment_'.$action,
            'Shipment '.($action==='exception'?'needs attention':$status),
            $r['title'].' · Staff recorded a shipment state change. No carrier scan or label purchase was performed.',
            $action==='exception'||$action==='ship');
        return ['id'=>$id,'status'=>$status,'revision'=>$revision+1,
            'trackingUrl'=>az_fulfillment_tracking_url($action==='ship'?$carrier:$r['carrier'],$action==='ship'?$tracking:$r['tracking_number'])];
    });
}
function az_fulfillment_briefing(array $user): array
{
    if(!az_has_role($user,'admin'))return ['cards'=>[],'counts'=>[]];
    $db=az_db();$q=$db->query("SELECT status,COUNT(*) AS quantity FROM commerce_shipments
         WHERE status IN ('awaiting_dispatch','packed','shipped','exception') GROUP BY status");
    $counts=['shippingAwaitingDispatch'=>0,'shippingPacked'=>0,'shippingInTransit'=>0,'shippingExceptions'=>0];
    foreach($q->fetchAll() as $r){
        $key=match($r['status']){
            'awaiting_dispatch'=>'shippingAwaitingDispatch','packed'=>'shippingPacked',
            'shipped'=>'shippingInTransit',default=>'shippingExceptions'
        };
        $counts[$key]=(int)$r['quantity'];
    }
    $low=$db->query("SELECT COUNT(*) FROM commerce_product_fulfillment f
        JOIN commerce_products p ON p.id=f.product_id AND p.state='active'
        WHERE f.stock_mode='tracked' AND f.on_hand-f.reserved<=f.low_stock_threshold")->fetchColumn();
    $counts['lowStockProducts']=(int)$low;
    // Do not silently discard stock held for an unknown Stripe checkout state.
    // The seller must reconcile the provider first; stale holds are an alert.
    $cutoff=gmdate('Y-m-d\TH:i:s\Z',time()-48*3600);
    $q=$db->prepare("SELECT COUNT(*) FROM commerce_stock_reservations r
        JOIN commerce_checkout_attempts a ON a.id=r.checkout_attempt_id
        WHERE r.status='held' AND a.state IN ('open','creating') AND a.created_at<=?");
    $q->execute([$cutoff]);
    $counts['staleStockHolds']=(int)$q->fetchColumn();
    $labelRisk=(int)$db->query("SELECT COUNT(*) FROM commerce_carrier_labels WHERE status IN ('pending','needs_review')")->fetchColumn();
    $counts['carrierLabelsNeedReview']=$labelRisk;
    $cards=[];
    if($labelRisk)$cards[]=az_agent_workspace_card('commerce','Shipping label purchases need reconciliation',
        $labelRisk.' EasyPost label operations have an uncertain outcome. Check the provider before purchasing again.',
        'commerce.php',1);
    if($counts['lowStockProducts'])$cards[]=az_agent_workspace_card('commerce','Product inventory running low',
        $counts['lowStockProducts'].' products are below their configured stock thresholds. Review quantities in Commerce.',
        'commerce.php',1);
    if($counts['shippingAwaitingDispatch']||$counts['shippingPacked'])
        $cards[]=az_agent_workspace_card('commerce','Paid orders awaiting shipment',
            ($counts['shippingAwaitingDispatch']+$counts['shippingPacked']).' paid parcels need packing or carrier handoff.',
            'commerce.php',1);
    if($counts['staleStockHolds'])$cards[]=az_agent_workspace_card('commerce','Unreconciled checkout inventory holds',
        $counts['staleStockHolds'].' Stripe checkout holds are older than 48 hours. Verify provider payment/expiry before adjusting stock.',
        'commerce.php',1);
    if($counts['shippingExceptions'])$cards[]=az_agent_workspace_card('commerce','Shipping exceptions require follow-up',
        $counts['shippingExceptions'].' shipments were flagged by staff for manual resolution.',
        'commerce.php',1);
    return ['cards'=>$cards,'counts'=>$counts];
}
function az_fulfillment_scan(): array
{
    $cutoff=gmdate('Y-m-d\TH:i:s\Z',time()-48*3600);
    $q=az_db()->prepare("SELECT s.order_id,s.status,s.product_title,p.owner_id
        FROM commerce_shipments s JOIN commerce_orders o ON o.id=s.order_id
        JOIN commerce_products p ON p.id=o.product_id
        WHERE s.updated_at<=? AND s.status IN ('awaiting_dispatch','packed','shipped','exception') LIMIT 100");
    $q->execute([$cutoff]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r)az_commerce_queue_admins(
        'shipment-followup:'.$r['order_id'].':'.$r['status'],
        (int)$r['owner_id'],'shipment_attention','Shipping order requires follow-up',
        $r['product_title'].' · Shipment has been in its current recorded state over 48 hours. Verify the carrier separately.',
        true);
    $stale=$db=az_db();
    $q=$db->prepare("SELECT a.id,p.title,p.owner_id FROM commerce_stock_reservations r
        JOIN commerce_checkout_attempts a ON a.id=r.checkout_attempt_id
        JOIN commerce_products p ON p.id=r.product_id
        WHERE r.status='held' AND a.state IN ('open','creating') AND a.created_at<=? LIMIT 100");
    $q->execute([$cutoff]);$pending=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach($pending as $hold)az_commerce_queue_admins(
        'stale-checkout-hold:'.$hold['id'],(int)$hold['owner_id'],
        'inventory_hold_attention','Checkout inventory needs provider reconciliation',
        $hold['title'].' · An older unpaid checkout still holds stock. Confirm payment or expiry with Stripe before manual action.',true);
    return ['checked'=>count($rows),'staleHolds'=>count($pending),'dispatch'=>az_commerce_dispatch(100)];
}
