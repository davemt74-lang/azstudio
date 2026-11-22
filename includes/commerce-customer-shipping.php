<?php
declare(strict_types=1);
/**
 * v1.35 customer-selected EasyPost carrier rates BEFORE Stripe Checkout.
 * Public quote responses contain no shipping address or recipient details.
 * Strict session binding, product/stock/price revision, provider mode and TTL.
 */
function az_upgrade_commerce_customer_shipping_schema(PDO $db): void
{
    if(az_schema_version($db)>=41 || az_schema_version($db)<40)return;
    az_prepare_storage();$lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Customer shipping migration lock unavailable.');
    try{
        if(az_schema_version($db)>=41)return;
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_customer_shipping_quotes (
            id CHAR(32) PRIMARY KEY,
            request_key CHAR(64) NOT NULL UNIQUE,
            session_binding CHAR(64) NOT NULL,
            product_id CHAR(32) NOT NULL,
            quantity INTEGER NOT NULL,
            product_revision INTEGER NOT NULL,
            fulfillment_revision INTEGER NOT NULL,
            unit_cents BIGINT NOT NULL,
            provider_mode VARCHAR(14) NOT NULL,
            provider_shipment_id VARCHAR(100) NOT NULL,
            address_ciphertext TEXT NOT NULL,
            address_hash CHAR(64) NOT NULL,
            rates_json TEXT NOT NULL,
            created_at VARCHAR(32) NOT NULL,
            expires_at VARCHAR(32) NOT NULL,
            FOREIGN KEY(product_id) REFERENCES commerce_products(id))$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_customer_shipping_selections (
            attempt_id CHAR(32) PRIMARY KEY,
            quote_id CHAR(32) NOT NULL,
            provider_shipment_id VARCHAR(100) NOT NULL,
            rate_id VARCHAR(100) NOT NULL,
            carrier VARCHAR(20) NOT NULL,
            service VARCHAR(100) NOT NULL,
            rate_cents BIGINT NOT NULL,
            address_hash CHAR(64) NOT NULL,
            provider_mode VARCHAR(14) NOT NULL,
            created_at VARCHAR(32) NOT NULL,
            FOREIGN KEY(attempt_id) REFERENCES commerce_checkout_attempts(id) ON DELETE CASCADE,
            FOREIGN KEY(quote_id) REFERENCES commerce_customer_shipping_quotes(id))$suffix");
        if($mysql){
            $q=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
            foreach([
                ['commerce_customer_shipping_quotes','customer_quotes_expiry','(expires_at)'],
                ['commerce_customer_shipping_quotes','customer_quotes_product','(product_id,created_at)'],
                ['commerce_customer_shipping_selections','customer_shipping_rate','(rate_id)']
            ] as [$table,$name,$cols]){
                $q->execute([$table,$name]);if(!(int)$q->fetchColumn())$db->exec("CREATE INDEX $name ON $table $cols");
            }
        }else{
            $db->exec('CREATE INDEX IF NOT EXISTS customer_quotes_expiry ON commerce_customer_shipping_quotes(expires_at)');
            $db->exec('CREATE INDEX IF NOT EXISTS customer_quotes_product ON commerce_customer_shipping_quotes(product_id,created_at)');
            $db->exec('CREATE INDEX IF NOT EXISTS customer_shipping_rate ON commerce_customer_shipping_selections(rate_id)');
        }
        $db->exec('UPDATE studio_schema SET version=41 WHERE id=1');
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function az_customer_ship_ready(): bool
{
    $carrier=az_carrier_config();$stripe=az_stripe_config();
    return $carrier['configured']&&$stripe['ready']
        &&($stripe['mode']==='test'?$carrier['mode']==='test':$carrier['mode']==='production');
}
function az_customer_ship_public_status(): array
{
    $carrier=az_carrier_config();
    return ['available'=>az_customer_ship_ready(),'provider'=>'EasyPost',
        'environment'=>$carrier['mode'],
        'notice'=>'Carrier prices are quoted for the submitted US address and parcel, expire in ten minutes, and exclude any Stripe-calculated tax. You must confirm the same destination in Stripe Checkout.'];
}
function az_customer_ship_address(array $body): array
{
    $name=az_local_clean($body['name']??'',120,'Recipient name',true);
    $street=az_local_clean($body['street']??'',180,'Street address',true);
    $unit=az_local_clean($body['unit']??'',100,'Apartment or unit');
    $city=az_local_clean($body['city']??'',120,'City',true);
    $state=strtoupper(az_local_clean($body['state']??'',2,'State',true));
    $zip=az_local_clean($body['zip']??'',10,'ZIP code',true);
    $country=strtoupper(az_local_clean($body['country']??'US',2,'Country'));
    $phone=az_local_clean($body['phone']??'',24,'Recipient phone',true);
    $digits=preg_replace('/[(). -]+/','',$phone);
    if(mb_strlen($name)<2||mb_strlen($street)<3||mb_strlen($city)<2
        ||!preg_match('/^[A-Z]{2}$/D',$state)
        ||!preg_match('/^[0-9]{5}(?:-[0-9]{4})?$/D',$zip)
        ||!preg_match('/^\+?[0-9]{10,15}$/D',(string)$digits)||$country!=='US')
        throw new AzHttpError(400,'Enter a complete US shipping address and telephone number.','invalid_shipping_address');
    return ['name'=>$name,'street'=>$street,'unit'=>$unit,'city'=>$city,
        'state'=>$state,'zip'=>$zip,'country'=>'US','phone'=>$digits];
}
/** Address hash binds street/unit/city/state/ZIP/country, not mutable display name. */
function az_customer_ship_address_hash(array $address): string
{
    $parts=[];
    foreach(['street','unit','city','state','zip','country'] as $field){
        $value=mb_strtolower(trim((string)($address[$field]??'')));
        $parts[]=preg_replace('/\s+/u',' ',$value);
    }
    return hash_hmac('sha256','carrier-checkout-address-v1|'.implode('|',$parts),az_local_secret());
}
function az_customer_ship_session(): string
{
    $id=session_id();
    if($id==='')throw new AzHttpError(403,'A browser session is required for carrier rates.','session_required');
    return hash_hmac('sha256','carrier-session-v1|'.$id,az_local_secret());
}
function az_customer_ship_quote_lock(string $requestKey)
{
    az_prepare_storage();
    $path=AZ_STORAGE.'/customer-rate-'.$requestKey.'.lock';
    $lock=fopen($path,'c+b');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Carrier quote lock unavailable.');
    return $lock;
}
function az_customer_ship_quote_row(string $id): array
{
    if(!az_valid_id($id))throw new AzHttpError(404,'Carrier quote not found.','shipping_quote_missing');
    $q=az_db()->prepare('SELECT * FROM commerce_customer_shipping_quotes WHERE id=?');
    $q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);
    if(!$r)throw new AzHttpError(404,'Carrier quote not found.','shipping_quote_missing');
    return $r;
}
function az_customer_ship_quote_public(array $quote): array
{
    return ['quoteId'=>$quote['id'],'expiresAt'=>$quote['expires_at'],
        'currency'=>'USD','rates'=>json_decode((string)$quote['rates_json'],true)?:[],
        'quantity'=>(int)$quote['quantity'],'unitCents'=>(int)$quote['unit_cents'],
        'notice'=>'Carrier price does not include applicable Stripe-calculated tax. Confirm the same address when Stripe Checkout opens.'];
}
function az_customer_ship_quote(array $body): array
{
    az_account_throttle('carrier-checkout-quote',8,900);
    $carrier=az_carrier_require_config();
    $stripe=az_stripe_assert_ready();
    if(!az_customer_ship_ready())throw new AzHttpError(503,'Shipping rates need matching test or live merchant accounts.','carrier_checkout_unavailable');
    $product=az_commerce_row(az_text($body['productId']??'',32,'Product'));
    if(!az_stripe_product_ready($product))throw new AzHttpError(409,'Product is not checkout-ready.','product_unavailable');
    $fulfillment=az_fulfillment_row($product['id']);
    if((int)$fulfillment['shipping_enabled']!==1||$fulfillment['shipping_rate']!=='carrier')
        throw new AzHttpError(409,'This product does not offer live carrier rates.','carrier_not_enabled');
    $qty=az_integer($body['quantity']??1,1,10,'Quantity');
    if((int)$product['price_cents']*$qty>95000000)
        throw new AzHttpError(400,'Cart exceeds the supported payment limit.','invalid_quantity');
    if($fulfillment['stock_mode']==='tracked'
        &&(int)$fulfillment['on_hand']-(int)$fulfillment['reserved']<$qty)
        throw new AzHttpError(409,'Not enough stock for this quantity.','insufficient_stock');
    $address=az_customer_ship_address($body);
    $hash=az_customer_ship_address_hash($address);
    $requestToken=az_text($body['quoteToken']??'',32,'Quote request token');
    if(!az_valid_id($requestToken))throw new AzHttpError(400,'Invalid quote request token.','invalid_token');
    $session=az_customer_ship_session();
    $requestKey=hash_hmac('sha256','carrier-quote-v1|'.$session.'|'.$requestToken,az_local_secret());
    $lock=az_customer_ship_quote_lock($requestKey);
    try{
        $q=az_db()->prepare('SELECT * FROM commerce_customer_shipping_quotes WHERE request_key=?');
        $q->execute([$requestKey]);$existing=$q->fetch(PDO::FETCH_ASSOC);
        if($existing){
            if($existing['session_binding']!==$session||$existing['product_id']!==$product['id']
                ||(int)$existing['quantity']!==$qty||$existing['address_hash']!==$hash)
                throw new AzHttpError(409,'Quote token was used with different shipping details.','quote_token_reused');
            if(strtotime($existing['expires_at'])<=time())
                throw new AzHttpError(409,'This carrier rate quote expired. Request fresh rates.','quote_expired');
            return az_customer_ship_quote_public($existing);
        }
        $to=['name'=>$address['name'],'street1'=>$address['street'],'street2'=>$address['unit'],
            'city'=>$address['city'],'state'=>$address['state'],'zip'=>$address['zip'],
            'country'=>'US','phone'=>$address['phone']];
        $parcel=[
            'weight'=>max(0.1,round((int)$fulfillment['weight_grams']*$qty/28.349523125,2)),
            'length'=>round((int)$fulfillment['length_mm']/25.4,2),
            'width'=>round((int)$fulfillment['width_mm']/25.4,2),
            'height'=>round((int)$fulfillment['height_mm']/25.4,2)
        ];
        $provider=az_carrier_provider_request('POST','/shipments',[
            'shipment'=>['from_address'=>$carrier['from'],'to_address'=>$to,'parcel'=>$parcel]
        ],$carrier);
        $providerId=(string)($provider['id']??'');
        if(!preg_match('/^shp_[A-Za-z0-9_]{6,}$/D',$providerId))
            throw new AzHttpError(502,'Carrier provider shipment reference was invalid.','carrier_response_mismatch');
        $rates=az_carrier_rates_from_shipment($provider,$carrier['mode']);
        if(!$rates)throw new AzHttpError(409,'No usable USPS/UPS/FedEx shipping rate is available for this address.','carrier_rates_empty');
        $id=az_id();$now=az_now();$expires=gmdate('Y-m-d\TH:i:s\Z',time()+600);
        az_db()->prepare('INSERT INTO commerce_customer_shipping_quotes(
            id,request_key,session_binding,product_id,quantity,product_revision,
            fulfillment_revision,unit_cents,provider_mode,provider_shipment_id,
            address_ciphertext,address_hash,rates_json,created_at,expires_at
            ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
          ->execute([$id,$requestKey,$session,$product['id'],$qty,(int)$product['revision'],
            (int)$fulfillment['revision'],(int)$product['price_cents'],$carrier['mode'],
            $providerId,az_local_encrypt($address),$hash,
            json_encode($rates,JSON_THROW_ON_ERROR),$now,$expires]);
        return az_customer_ship_quote_public(az_customer_ship_quote_row($id));
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
/** Called inside the checkout transaction; never trust the shopper's price. */
function az_customer_ship_select(PDO $db,string $attemptId,array $product,array $fulfillment,
    int $qty,array $body,string $stripeMode): array
{
    if(!az_customer_ship_ready())throw new AzHttpError(503,'Carrier checkout is not configured.','carrier_checkout_unavailable');
    $quote=az_customer_ship_quote_row(az_text($body['shippingQuoteId']??'',32,'Shipping quote'));
    $rateId=az_text($body['shippingRateId']??'',100,'Carrier rate ID');
    if(!preg_match('/^rate_[A-Za-z0-9_]{6,}$/D',$rateId))
        throw new AzHttpError(400,'Invalid carrier rate.','rate_invalid');
    if($quote['session_binding']!==az_customer_ship_session()
        ||$quote['product_id']!==$product['id']||(int)$quote['quantity']!==$qty
        ||(int)$quote['product_revision']!==(int)$product['revision']
        ||(int)$quote['fulfillment_revision']!==(int)$fulfillment['revision']
        ||(int)$quote['unit_cents']!==(int)$product['price_cents']
        ||$quote['provider_mode']!==($stripeMode==='live'?'production':'test')
        ||strtotime($quote['expires_at'])<=time())
        throw new AzHttpError(409,'Rate, address or product changed. Refresh the shipping quote before checkout.','quote_expired_or_changed');
    $selected=null;
    foreach(json_decode((string)$quote['rates_json'],true)?:[] as $rate)
        if(($rate['id']??'')===$rateId){$selected=$rate;break;}
    if(!$selected||!in_array($selected['carrier'],['USPS','UPS','FedEx'],true)
       ||$selected['rateCents']<1||$selected['rateCents']>1000000)
        throw new AzHttpError(409,'Carrier rate is not part of the verified quote.','rate_mismatch');
    $db->prepare('INSERT INTO commerce_customer_shipping_selections(
        attempt_id,quote_id,provider_shipment_id,rate_id,carrier,service,rate_cents,
        address_hash,provider_mode,created_at
        ) VALUES(?,?,?,?,?,?,?,?,?,?)')
       ->execute([$attemptId,$quote['id'],$quote['provider_shipment_id'],
          $rateId,$selected['carrier'],$selected['service'],$selected['rateCents'],
          $quote['address_hash'],$quote['provider_mode'],az_now()]);
    return $selected;
}
function az_customer_ship_selection(string $attemptId): ?array
{
    if(!az_valid_id($attemptId))return null;
    $q=az_db()->prepare('SELECT * FROM commerce_customer_shipping_selections WHERE attempt_id=?');
    $q->execute([$attemptId]);$r=$q->fetch(PDO::FETCH_ASSOC);
    return $r?:null;
}
function az_customer_ship_address_matched(string $attemptId,array $stripeAddress): bool
{
    $selection=az_customer_ship_selection($attemptId);
    return $selection===null || hash_equals($selection['address_hash'],az_customer_ship_address_hash($stripeAddress));
}
/** Enqueue staff action; this is a paid order, NEVER drop the signed payment receipt. */
function az_customer_ship_inspect_address(PDO $db,string $orderId,array $attempt,array $stripeSession,string $title,int $actor): void
{
    $selection=az_customer_ship_selection($attempt['id']);
    if(!$selection)return;
    $actual=az_fulfillment_shipping_address($stripeSession);
    if(az_customer_ship_address_matched($attempt['id'],$actual))return;
    $db->prepare("UPDATE commerce_shipments SET status='exception',revision=revision+1,updated_at=? WHERE order_id=?")
       ->execute([az_now(),$orderId]);
    az_commerce_queue_admins('carrier-address-mismatch:'.$orderId,$actor,'carrier_address_review',
        'Paid order has a shipping address mismatch',
        $title.' · The address entered at Stripe differs from the carrier quote. Payment is recorded, but dispatch is blocked until staff validates a new rate and destination.','commerce.php',true);
}
function az_customer_ship_briefing(array $user): array
{
    if(!az_has_role($user,'admin'))return ['counts'=>[],'cards'=>[]];
    $db=az_db();$since=gmdate('Y-m-d\TH:i:s\Z',time()-86400);
    $q=$db->prepare('SELECT COUNT(*) FROM commerce_customer_shipping_quotes WHERE created_at>=?');
    $q->execute([$since]);$quotes=(int)$q->fetchColumn();
    $q=$db->prepare('SELECT COUNT(*) FROM commerce_customer_shipping_selections s JOIN commerce_checkout_attempts a ON a.id=s.attempt_id WHERE a.created_at>=?');
    $q->execute([$since]);$started=(int)$q->fetchColumn();
    $unresolved=(int)$db->query("SELECT COUNT(*) FROM commerce_shipments s
       JOIN commerce_customer_shipping_selections c ON c.attempt_id=SUBSTR(
          (SELECT o.external_reference FROM commerce_orders o WHERE o.id=s.order_id),8)
       WHERE s.status='exception'")->fetchColumn();
    $cards=[];
    if($unresolved)$cards[]=az_agent_workspace_card('commerce',
        'Paid shipping addresses need staff confirmation',
        $unresolved.' orders have unresolved shipping exceptions. Verify the destination and carrier rate before label purchase.','commerce.php',1);
    if($quotes>=3 && $quotes>$started+2)$cards[]=az_agent_workspace_card('commerce',
        'Customers requested carrier quotes without starting checkout',
        $quotes.' shipping quote requests and '.$started.' corresponding Checkout Sessions in the past day. Check shipping prices and customer checkout friction.','commerce.php',3);
    return ['counts'=>['carrierQuoteRequests24h'=>$quotes,'carrierQuoteCheckouts24h'=>$started,'carrierAddressExceptions'=>$unresolved],
        'cards'=>$cards];
}

/** Safe, carrier-attributed selection for staff. Never returns the customer's address. */
function az_customer_ship_order_summary(string $orderId): ?array
{
    if(!az_valid_id($orderId))return null;
    $q=az_db()->prepare("SELECT external_reference FROM commerce_orders WHERE id=? AND provider='stripe'");
    $q->execute([$orderId]);$reference=$q->fetchColumn();
    if(!is_string($reference)||!str_starts_with($reference,'stripe:'))return null;
    $selection=az_customer_ship_selection(substr($reference,7));
    if(!$selection)return null;
    return ['carrier'=>$selection['carrier'],'service'=>$selection['service'],
        'selectedCents'=>(int)$selection['rate_cents'],
        'quotedMode'=>$selection['provider_mode']];
}
function az_customer_ship_metrics(array $user): array
{
    $db=az_db();$admin=az_has_role($user,'admin');
    $since=gmdate('Y-m-d\TH:i:s\Z',time()-30*86400);
    if($admin){
        $q=$db->prepare('SELECT COUNT(*) FROM commerce_customer_shipping_quotes WHERE created_at>=?');
        $q->execute([$since]);$quotes=(int)$q->fetchColumn();
    }else $quotes=null;
    $filter=$admin?'':' JOIN commerce_promotions m ON m.id=a.promotion_id AND m.model_id=?';
    $q=$db->prepare("SELECT a.state,COUNT(*) AS n,SUM(s.rate_cents) AS cents
        FROM commerce_customer_shipping_selections s
        JOIN commerce_checkout_attempts a ON a.id=s.attempt_id $filter
        WHERE a.created_at>=? GROUP BY a.state");
    $q->execute($admin?[$since]:[(int)$user['id'],$since]);
    $started=0;$paid=0;$paidRates=0;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $n=(int)$r['n'];$started+=$n;
        if($r['state']==='paid'){$paid+=$n;$paidRates+=(int)$r['cents'];}
    }
    $q=$db->prepare("SELECT SUM(l.rate_cents) AS cost,COUNT(*) AS labels
        FROM commerce_carrier_labels l
        JOIN commerce_shipments sh ON sh.order_id=l.order_id
        JOIN commerce_orders o ON o.id=sh.order_id
        ".($admin?'':'JOIN commerce_promotions m ON m.id=o.promotion_id AND m.model_id=?')."
        WHERE l.status='purchased' AND l.purchased_at>=?");
    $q->execute($admin?[$since]:[(int)$user['id'],$since]);
    $labels=$q->fetch(PDO::FETCH_ASSOC);
    return [
        'periodDays'=>30,'liveQuoteRequests'=>$quotes,'carrierCheckoutStarts'=>$started,
        'carrierPaidOrders'=>$paid,'carrierShippingChargedCents'=>$paidRates,
        'labelPurchases'=>(int)($labels['labels']??0),
        'purchasedPostageCents'=>(int)($labels['cost']??0),
        'basis'=>'Provider shipping quotes, paid Stripe checkout selections and approved postage-label purchases. Taxes, refunds and unmatched costs must be reconciled separately.'
    ];
}
