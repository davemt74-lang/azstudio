<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){fwrite(STDERR,"PDO SQLite missing\n");exit(2);}
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
session_id('fulfillment-fixture-session');session_start();
$GLOBALS['az_config_cache']=['app_secret'=>str_repeat('A',64)];
putenv('AZH_STRIPE_SECRET_KEY=sk_test_shipping_fixture_long_enough');
putenv('AZH_STRIPE_WEBHOOK_SECRET=whsec_shipping_fixture_long_enough');
putenv('AZH_PUBLIC_BASE_URL=https://store.example.invalid');
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('PRAGMA foreign_keys=ON');
$GLOBALS['az_database']=$db;
$db->exec("CREATE TABLE studio_schema(id INTEGER PRIMARY KEY,version INTEGER); INSERT INTO studio_schema VALUES(1,35);
CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,email TEXT,status TEXT,revision INTEGER,created_at TEXT,last_login_at TEXT);
CREATE TABLE user_roles(user_id INTEGER,role TEXT,PRIMARY KEY(user_id,role));
CREATE TABLE user_role_requests(user_id INTEGER,role TEXT,status TEXT);
CREATE TABLE auth_attempts(attempt_key TEXT PRIMARY KEY,attempt_count INTEGER,window_started BIGINT);
CREATE TABLE model_videos(id TEXT PRIMARY KEY,user_id INTEGER,media_id TEXT,title TEXT,status TEXT,revision INTEGER,rights_confirmed INTEGER,published_at TEXT,updated_at TEXT);
CREATE TABLE az_live_rooms(id TEXT PRIMARY KEY,owner_id INTEGER,status TEXT,heartbeat_epoch INTEGER,started_epoch INTEGER);
CREATE TABLE agent_workspace_events(id TEXT PRIMARY KEY,recipient_id INTEGER,actor_id INTEGER,kind TEXT,title TEXT,detail TEXT,href TEXT,created_at TEXT,read_at TEXT);
CREATE TABLE agent_chat_threads(id TEXT PRIMARY KEY,user_id INTEGER,title TEXT,created_at TEXT,updated_at TEXT);
CREATE TABLE agent_chat_messages(id TEXT PRIMARY KEY,thread_id TEXT,user_id INTEGER,position INTEGER,role TEXT,content TEXT,client_token TEXT,state TEXT,created_at TEXT,updated_at TEXT,UNIQUE(thread_id,position));
INSERT INTO users VALUES(1,'Model A','one@example.invalid','active',1,'now',NULL),
(2,'Model B','two@example.invalid','active',1,'now',NULL),
(3,'Admin','admin@example.invalid','active',1,'now',NULL);
INSERT INTO user_roles VALUES(1,'model'),(2,'model'),(3,'admin');");
$admin=['id'=>3,'status'=>'active','roles'=>['admin']];
$model=['id'=>1,'status'=>'active','roles'=>['model']];
$checks=0;
$assert=static function(bool $yes,string $label)use(&$checks):void{
    if(!$yes)throw new RuntimeException('Shipping/inventory integration FAIL: '.$label);
    $checks++;
};
$reject=static function(callable $fn,string $code)use($assert):void{
    try{$fn();$assert(false,'expected '.$code);}catch(AzHttpError $e){$assert($e->errorCode===$code,'expected '.$code.' got '.$e->errorCode);}
};
az_upgrade_commerce_schema($db);
az_upgrade_commerce_stripe_schema($db);
az_upgrade_commerce_local_schema($db);
az_upgrade_commerce_fulfillment_schema($db);
$assert(az_schema_version($db)===39,'v1.33 migration');
az_upgrade_commerce_carrier_schema($db);$assert(az_schema_version($db)===40,'carrier schema v40');
az_upgrade_commerce_customer_shipping_schema($db);$assert(az_schema_version($db)===41,'customer carrier quote schema v41');
az_upgrade_commerce_settlement_schema($db);$assert(az_schema_version($db)===42,'return and settlement schema v42');
az_upgrade_commerce_provider_schema($db);$assert(az_schema_version($db)===43,'provider financial operation schema v43');
az_upgrade_commerce_fulfillment_schema($db);
$assert(az_schema_version($db)===43,'older schema migration replay preserves v43');
$settings=[
    'sku'=>'CLOTH-001','stockMode'=>'tracked','stockOnHand'=>2,'stockRevision'=>0,
    'stockAdjustmentReason'=>'','lowStockThreshold'=>1,'shippingEnabled'=>true,
    'shippingRate'=>'flat','shippingFeeCents'=>375,'freeAboveCents'=>5000,
    'weightGrams'=>400,'lengthMm'=>220,'widthMm'=>150,'heightMm'=>70,
    'handlingDays'=>2,'shipCountry'=>'US'
];
$created=az_commerce_product_save($admin,['title'=>'Limited shipped item','description'=>'Physical merchandise',
    'priceCents'=>1599,'commissionBps'=>1000,'state'=>'active','checkoutMode'=>'stripe',
    'fulfillmentSettings'=>$settings]);
$p=$created['id'];
$stock=az_fulfillment_admin($admin,$p);
$assert($stock['stockMode']==='tracked'&&$stock['onHand']===2&&$stock['available']===2,
    'tracked stock stored on product');
$assert($stock['shippingEnabled']&&$stock['shippingFeeCents']===375&&$stock['sku']==='CLOTH-001',
    'product shipping fee and SKU saved');
$assert(az_fulfillment_shipping_fee($p,1599)===375&&az_fulfillment_shipping_fee($p,6000)===0,
    'flat shipping and free threshold evaluated from merchandise total');
$public=az_commerce_public_products();
$assert(count($public)===1&&$public[0]['fulfillment']['shippingEnabled']&&$public[0]['fulfillment']['available']===2,
    'Shop exposes safe shipping and inventory summary');
$reject(static fn()=>az_fulfillment_admin($model,$p),'role_required');
$reject(static fn()=>az_fulfillment_prepare(array_merge($settings,['stockMode'=>'tracked','shipCountry'=>'CA'])),'unsupported_country');
$reject(static fn()=>az_fulfillment_prepare(array_merge($settings,['weightGrams'=>0])),'missing_package');
$requests=[];
$GLOBALS['az_stripe_test_transport']=static function(array $payload,string $id)use(&$requests):array{
    $requests[$id]=$payload;
    if(($payload['shipping_options'][0]['shipping_rate_data']['fixed_amount']['amount']??null)!==375)
        throw new RuntimeException('Shipping fee not passed to hosted Stripe.');
    if(($payload['shipping_address_collection']['allowed_countries'][0]??'')!=='US')
        throw new RuntimeException('US shipping address collection missing.');
    return ['id'=>'cs_test_'.$id,'url'=>'https://checkout.stripe.com/c/pay/fixture'];
};
$one=az_stripe_checkout_start(['productId'=>$p,'quantity'=>1,'clientToken'=>str_repeat('a',32)]);
$assert(str_starts_with($one['checkoutUrl'],'https://checkout.stripe.com/'),'Stripe URL created');
$assert(az_fulfillment_row($p)['reserved']==1,'one checkout reserves one unit');
$retry=az_stripe_checkout_start(['productId'=>$p,'quantity'=>1,'clientToken'=>str_repeat('a',32)]);
$assert($one['checkoutUrl']===$retry['checkoutUrl']&&count($requests)===1,'replayed checkout does not create another stock hold');
$reject(static fn()=>az_stripe_checkout_start(['productId'=>$p,'quantity'=>2,'clientToken'=>str_repeat('b',32)]),'insufficient_stock');
$two=az_stripe_checkout_start(['productId'=>$p,'quantity'=>1,'clientToken'=>str_repeat('c',32)]);
$assert(az_fulfillment_row($p)['reserved']==2,'second unique checkout reserves final available unit');
$reject(static fn()=>az_stripe_checkout_start(['productId'=>$p,'quantity'=>1,'clientToken'=>str_repeat('d',32)]),'insufficient_stock');
$q=$db->query('SELECT * FROM commerce_checkout_attempts ORDER BY created_at,id');
$attempts=$q->fetchAll(PDO::FETCH_ASSOC);
$assert(count($attempts)===2,'checkout replays did not duplicate attempts');
$attempt=$attempts[0];$sid=$attempt['session_id'];
$shipPayload=['name'=>'Private Recipient One','address'=>[
    'line1'=>'146 Example Rd','line2'=>'Suite 5','city'=>'Phoenix','state'=>'AZ','postal_code'=>'85002','country'=>'US']];
$session=[
    'id'=>$sid,'object'=>'checkout.session','mode'=>'payment','livemode'=>false,
    'client_reference_id'=>$attempt['id'],'metadata'=>['azh_attempt_id'=>$attempt['id']],
    'amount_subtotal'=>1599,'amount_total'=>2129,
    'total_details'=>['amount_tax'=>155,'amount_shipping'=>375,'amount_discount'=>0],
    'currency'=>'usd','payment_status'=>'paid','payment_intent'=>'pi_shipping00123456',
    'collected_information'=>['shipping_details'=>$shipPayload]
];
$event=static function(string $id,string $type,array $object):string{
    return json_encode(['id'=>$id,'object'=>'event','livemode'=>false,'type'=>$type,
        'data'=>['object'=>$object]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
};
$signed=static function(string $raw):string{
    $now=time();return 't='.$now.',v1='.hash_hmac('sha256',$now.'.'.$raw,'whsec_shipping_fixture_long_enough');
};
$mismatched=$session;$mismatched['total_details']['amount_shipping']=999;
$bad=$event('evt_shippingbad123456','checkout.session.completed',$mismatched);
$reject(static fn()=>az_stripe_webhook_receive($bad,$signed($bad)),'checkout_mismatch');
$noAddress=$session;unset($noAddress['collected_information']);
$badAddress=$event('evt_shippingmissing123','checkout.session.completed',$noAddress);
$reject(static fn()=>az_stripe_webhook_receive($badAddress,$signed($badAddress)),'shipping_address_missing');
$assert((int)$db->query('SELECT COUNT(*) FROM commerce_orders')->fetchColumn()===0,
    'invalid provider event never takes money or stock into ledger');
$paid=$event('evt_shippingpaid123456','checkout.session.completed',$session);
az_stripe_webhook_receive($paid,$signed($paid));
$assert((int)$db->query('SELECT COUNT(*) FROM commerce_orders')->fetchColumn()===1,
    'signed paid checkout creates one order');
$assert((int)$db->query('SELECT COUNT(*) FROM commerce_shipments')->fetchColumn()===1,
    'signed address creates encrypted shipment');
$after=az_fulfillment_row($p);
$assert((int)$after['on_hand']===1&&(int)$after['reserved']===1,
    'paid order consumes one on-hand unit and leaves second reservation');
$orders=az_fulfillment_shipments($admin);
$orderId=$orders[0]['id'];
$assert(count($orders)===1&&$orders[0]['status']==='awaiting_dispatch'&&$orders[0]['shippingFeeCents']===375,
    'admin shipment starts in pending dispatch');
$reject(static fn()=>az_fulfillment_shipments($model),'role_required');
$reject(static fn()=>az_fulfillment_shipment_details($model,$orderId),'role_required');
$private=az_fulfillment_shipment_details($admin,$orderId);
$assert($private['address']['name']==='Private Recipient One'&&$private['address']['zip']==='85002',
    'admin decrypts Stripe shipping address');
$encrypted=$db->query('SELECT contact_ciphertext FROM commerce_shipments')->fetchColumn();
$assert(!str_contains($encrypted,'Private Recipient One')&&!str_contains($encrypted,'146 Example'),
    'address protected at rest');
$events=json_encode($db->query('SELECT title,detail FROM commerce_activity')->fetchAll(),JSON_THROW_ON_ERROR);
$assert(!str_contains($events,'Private Recipient One')&&!str_contains($events,'146 Example'),
    'no personal shipping detail in shared Agent events');
$reject(static fn()=>az_fulfillment_shipment_action($model,['id'=>$orderId,'revision'=>1,'decision'=>'pack']),'role_required');
$reject(static fn()=>az_fulfillment_shipment_action($admin,['id'=>$orderId,'revision'=>1,'decision'=>'ship','carrier'=>'ups','trackingNumber'=>'1Z999AA10123456784']),'invalid_transition');
$packed=az_fulfillment_shipment_action($admin,['id'=>$orderId,'revision'=>1,'decision'=>'pack']);
$assert($packed['status']==='packed','staff marks paid parcel packed');
$reject(static fn()=>az_fulfillment_shipment_action($admin,['id'=>$orderId,'revision'=>1,'decision'=>'ship','carrier'=>'ups','trackingNumber'=>'1Z999AA10123456784']),'revision_conflict');
$reject(static fn()=>az_fulfillment_shipment_action($admin,['id'=>$orderId,'revision'=>2,'decision'=>'ship','carrier'=>'ups','trackingNumber'=>'invalid tracking!']),'invalid_tracking');
$shipped=az_fulfillment_shipment_action($admin,['id'=>$orderId,'revision'=>2,
    'decision'=>'ship','carrier'=>'ups','trackingNumber'=>'1Z999AA10123456784']);
$assert($shipped['status']==='shipped'&&str_contains($shipped['trackingUrl'],'ups.com'),
    'staff records carrier/tracking; safe official tracking link generated');
$reject(static fn()=>az_fulfillment_shipment_action($admin,['id'=>$orderId,'revision'=>3,'decision'=>'deliver']),'confirmation_required');
$done=az_fulfillment_shipment_action($admin,['id'=>$orderId,'revision'=>3,'decision'=>'deliver','confirmDelivered'=>true]);
$assert($done['status']==='delivered','admin explicitly marks parcel delivered');
$again=az_stripe_webhook_receive($paid,$signed($paid));
$assert($again['duplicate']===true&&(int)$db->query('SELECT COUNT(*) FROM commerce_shipments')->fetchColumn()===1,
    'duplicate paid webhook cannot duplicate shipment');
$q=$db->query('SELECT * FROM commerce_checkout_attempts WHERE id<>'.$db->quote($attempt['id']));
$other=$q->fetch(PDO::FETCH_ASSOC);
$expiredSession=$session;
$expiredSession['id']=$other['session_id'];
$expiredSession['client_reference_id']=$other['id'];
$expiredSession['metadata']['azh_attempt_id']=$other['id'];
$expiredSession['payment_status']='unpaid';$expiredSession['payment_intent']=null;
$expired=$event('evt_shippingexpired123','checkout.session.expired',$expiredSession);
az_stripe_webhook_receive($expired,$signed($expired));
$last=az_fulfillment_row($p);
$assert((int)$last['on_hand']===1&&(int)$last['reserved']===0,
    'signed expiry releases second checkout hold without restoring purchased stock');
$reject(static fn()=>az_stripe_checkout_start(['productId'=>$p,'quantity'=>2,'clientToken'=>str_repeat('d',32)]),'insufficient_stock');
$refund=['payment_intent'=>'pi_shipping00123456','amount_refunded'=>2129];
$refundEvent=$event('evt_shippingrefund123','charge.refunded',$refund);
az_stripe_webhook_receive($refundEvent,$signed($refundEvent));
$assert((int)$db->query("SELECT commission_cents FROM commerce_orders")->fetchColumn()===0,
    'full tax-and-shipping-inclusive refund removes creator commission');
$assert((int)az_fulfillment_row($p)['on_hand']===1,'refund never silently restocks physical merchandise');

/* CUSTOMER-SELECTED CARRIER CHECKOUT — true provider-shaped rates and signed Stripe events. */
putenv('AZH_EASYPOST_API_KEY=EZTK_dynamic_carrier_fixture_123456789');
putenv('AZH_EASYPOST_FROM_NAME=AZH Studio');
putenv('AZH_EASYPOST_FROM_STREET=25 Origin Rd');
putenv('AZH_EASYPOST_FROM_CITY=Phoenix');
putenv('AZH_EASYPOST_FROM_STATE=AZ');
putenv('AZH_EASYPOST_FROM_ZIP=85001');
putenv('AZH_EASYPOST_FROM_PHONE=6025550184');
$carrierProduct=az_commerce_product_save($admin,[
    'title'=>'Smart shipped shirt','description'=>'Product rate shopping fixture',
    'priceCents'=>1999,'commissionBps'=>1000,'state'=>'active','checkoutMode'=>'stripe',
    'fulfillmentSettings'=>[
        'sku'=>'SMART-SHIP-1','stockMode'=>'tracked','stockOnHand'=>3,'stockRevision'=>0,
        'stockAdjustmentReason'=>'','lowStockThreshold'=>1,'shippingEnabled'=>true,
        'shippingRate'=>'carrier','shippingFeeCents'=>0,'freeAboveCents'=>0,
        'weightGrams'=>450,'lengthMm'=>200,'widthMm'=>180,'heightMm'=>70,
        'handlingDays'=>2,'shipCountry'=>'US'
    ]
])['id'];
$assert(az_customer_ship_ready(),'matching Stripe TEST and EasyPost TEST are configured');
$productSummary=az_fulfillment_public(['id'=>$carrierProduct]);
$assert($productSummary['shippingRate']==='carrier'&&$productSummary['carrierCheckoutAvailable'],
    'per-product live carrier checkout enabled');
$quoteCalls=0;
$GLOBALS['az_carrier_test_transport']=static function(string $method,string $path,?array $payload)use(&$quoteCalls):array{
    if($method!=='POST'||$path!=='/shipments')throw new RuntimeException('Unexpected rate API operation.');
    $quoteCalls++;
    $shipment=$payload['shipment']??[];
    if(($shipment['to_address']['street1']??'')!=='146 Example Rd'
        ||($shipment['to_address']['zip']??'')!=='85002'
        ||($shipment['from_address']['zip']??'')!=='85001')
        throw new RuntimeException('Carrier was sent wrong destination or origin.');
    return ['id'=>'shp_SMART123456789','object'=>'Shipment','mode'=>'test','rates'=>[
        ['id'=>'rate_SMARTUSPS1234567','carrier'=>'USPS','service'=>'Priority',
         'rate'=>'5.95','currency'=>'USD','delivery_days'=>3],
        ['id'=>'rate_SMARTUPS12345678','carrier'=>'UPS','service'=>'Ground',
         'rate'=>'8.45','currency'=>'USD','delivery_days'=>4],
        ['id'=>'rate_SMARTFEDEX123456','carrier'=>'FedEx','service'=>'FEDEX_GROUND',
         'rate'=>'15.95','currency'=>'USD','delivery_days'=>2]
    ]];
};
$quoteRequest=[
 'productId'=>$carrierProduct,'quantity'=>1,'quoteToken'=>str_repeat('8',32),
 'name'=>'Private Shipping Buyer','street'=>'146 Example Rd','unit'=>'Suite 5',
 'city'=>'Phoenix','state'=>'AZ','zip'=>'85002','country'=>'US','phone'=>'6025550157'
];
$quote=az_customer_ship_quote($quoteRequest);
$assert(count($quote['rates'])===3&&$quote['rates'][0]['rateCents']===595,
    'real USPS/UPS/FedEx prices returned in cents, cheapest first');
$assert(!str_contains(json_encode($quote),'Private Shipping Buyer')
  &&!str_contains(json_encode($quote),'146 Example'),'quote response never exposes recipient address');
$repeat=az_customer_ship_quote($quoteRequest);
$assert($repeat['quoteId']===$quote['quoteId']&&$quoteCalls===1,
    'repeated customer rate request reuses same provider shipment');
$reject(static fn()=>az_customer_ship_quote(array_merge($quoteRequest,['street'=>'Other Address'])),'quote_token_reused');
$storedQuote=az_customer_ship_quote_row($quote['quoteId']);
$assert(!str_contains($storedQuote['address_ciphertext'],'146 Example')
    &&!str_contains($storedQuote['address_ciphertext'],'Private Shipping Buyer'),
    'customer address is encrypted at rest in quote ledger');
$wrongRate=[
 'productId'=>$carrierProduct,'quantity'=>1,'clientToken'=>str_repeat('9',32),
 'shippingQuoteId'=>$quote['quoteId'],'shippingRateId'=>'rate_BOGUS12345678'
];
$reject(static fn()=>az_stripe_checkout_start($wrongRate),'rate_mismatch');
$assert((int)$db->query("SELECT COUNT(*) FROM commerce_customer_shipping_selections")->fetchColumn()===0,
    'invalid rate never reserves product stock or starts Stripe Checkout');
session_write_close();
session_id('different-customer-session');session_start();
$crossSession=array_merge($wrongRate,['shippingRateId'=>'rate_SMARTUSPS1234567']);
$reject(static fn()=>az_stripe_checkout_start($crossSession),'quote_expired_or_changed');
session_write_close();session_id('fulfillment-fixture-session');session_start();
$assert(az_customer_ship_session()===$storedQuote['session_binding'],
    'carrier quote restored to original browser session');
$checkoutData=[];
$GLOBALS['az_stripe_test_transport']=static function(array $payload,string $id)use(&$checkoutData):array{
    $checkoutData=$payload;
    $price=$payload['shipping_options'][0]['shipping_rate_data']['fixed_amount']['amount']??null;
    $service=$payload['shipping_options'][0]['shipping_rate_data']['display_name']??'';
    if(!in_array([$price,$service],[[595,'USPS Priority'],[845,'UPS Ground']],true))
        throw new RuntimeException('The verified selected carrier rate and service must be sent unchanged to Stripe.');
    if(array_key_exists('name',$payload['metadata']??[]))
        throw new RuntimeException('Customer PII cannot be put in provider metadata.');
    return ['id'=>'cs_test_'.$id,'url'=>'https://checkout.stripe.com/c/pay/customer-carrier-fixture'];
};
$start=az_stripe_checkout_start($crossSession);
$assert(str_starts_with($start['checkoutUrl'],'https://checkout.stripe.com'),
    'Stripe checkout created from a selected real carrier quote');
$attemptQuery=$db->prepare('SELECT a.* FROM commerce_checkout_attempts a JOIN commerce_customer_shipping_selections s ON s.attempt_id=a.id WHERE s.quote_id=?');
$attemptQuery->execute([$quote['quoteId']]);$attempt=$attemptQuery->fetch(PDO::FETCH_ASSOC);
$assert($attempt&&(int)$attempt['shipping_fee_cents']===595
    &&$attempt['state']==='open',
    'Stripe attempt persists carrier-selected shipping fee, not a browser-supplied fee');
$chosen=az_customer_ship_selection($attempt['id']);
$assert($chosen['carrier']==='USPS'&&$chosen['service']==='Priority'
    &&(int)$chosen['rate_cents']===595,'chosen service and amount are immutable');
$retryStart=az_stripe_checkout_start($crossSession);
$assert($retryStart['checkoutUrl']===$start['checkoutUrl'],
    'repeat checkout token returns original Stripe session');
$reject(static fn()=>az_stripe_checkout_start(array_merge($crossSession,['shippingRateId'=>'rate_SMARTUPS12345678'])),'token_reused');
$correct=[
    'id'=>$attempt['session_id'],'object'=>'checkout.session','mode'=>'payment','livemode'=>false,
    'client_reference_id'=>$attempt['id'],
    'metadata'=>['azh_attempt_id'=>$attempt['id']],
    'amount_subtotal'=>1999,'amount_total'=>2750,
    'total_details'=>['amount_tax'=>156,'amount_shipping'=>595,'amount_discount'=>0],
    'currency'=>'usd','payment_status'=>'paid','payment_intent'=>'pi_dynamiccarrier123456',
    'collected_information'=>['shipping_details'=>[
        'name'=>'Stripe Buyer','address'=>['line1'=>'146 Example Rd','line2'=>'Suite 5',
            'city'=>'Phoenix','state'=>'AZ','postal_code'=>'85002','country'=>'US']
    ]]
];
$badTotal=$correct;$badTotal['total_details']['amount_shipping']=845;
$wrongPaid=$event('evt_dynamicbad1234','checkout.session.completed',$badTotal);
$reject(static fn()=>az_stripe_webhook_receive($wrongPaid,$signed($wrongPaid)),'checkout_mismatch');
$verified=$event('evt_dynamicpaid1234','checkout.session.completed',$correct);
az_stripe_webhook_receive($verified,$signed($verified));
$paidOrderQuery=$db->prepare("SELECT o.id,o.commission_cents,o.provider_total_cents,s.status
    FROM commerce_orders o JOIN commerce_shipments s ON s.order_id=o.id
    WHERE o.external_reference=?");
$paidOrderQuery->execute(['stripe:'.$attempt['id']]);$paidOrder=$paidOrderQuery->fetch(PDO::FETCH_ASSOC);
$assert($paidOrder&&$paidOrder['status']==='awaiting_dispatch'&&
    (int)$paidOrder['provider_total_cents']===2750,'matching address creates approved paid shipping order');
$assert((int)$paidOrder['commission_cents']===0,'no creator commission for an unattributed test order');
$orderSelected=az_customer_ship_order_summary($paidOrder['id']);
$assert($orderSelected['carrier']==='USPS'&&$orderSelected['selectedCents']===595,
    'staff sees exactly the carrier and rate chosen by the customer');
$agentActivity=json_encode($db->query('SELECT title,detail FROM commerce_activity')->fetchAll(),JSON_THROW_ON_ERROR);
$assert(!str_contains($agentActivity,'146 Example')
    &&!str_contains($agentActivity,'Private Shipping Buyer'),'no shipping recipient in Agent activities');
$metrics=az_customer_ship_metrics($admin);
$assert($metrics['carrierPaidOrders']>=1&&$metrics['carrierShippingChargedCents']>=595,
    'report derives carrier paid orders and shipping charges from real checkout records');
// A later quote is bound to the latest product revision and is invalid after a change.
$nextRequest=array_merge($quoteRequest,['quoteToken'=>str_repeat('a',32)]);
$nextQuote=az_customer_ship_quote($nextRequest);
$db->prepare("UPDATE commerce_customer_shipping_quotes SET expires_at='2000-01-01T00:00:00Z' WHERE id=?")
   ->execute([$nextQuote['quoteId']]);
$reject(static fn()=>az_stripe_checkout_start([
    'productId'=>$carrierProduct,'quantity'=>1,'clientToken'=>str_repeat('b',32),
    'shippingQuoteId'=>$nextQuote['quoteId'],'shippingRateId'=>'rate_SMARTUSPS1234567'
]),'quote_expired_or_changed');
// Different shipping address in the SIGNED Stripe-paid event is not rejected
// as an unpaid order; the paid order is recorded but dispatch is quarantined.
$mismatchQuote=az_customer_ship_quote(array_merge($quoteRequest,['quoteToken'=>str_repeat('c',32)]));
$start2=az_stripe_checkout_start([
    'productId'=>$carrierProduct,'quantity'=>1,'clientToken'=>str_repeat('d',32),
    'shippingQuoteId'=>$mismatchQuote['quoteId'],'shippingRateId'=>'rate_SMARTUPS12345678'
]);
$q=$db->prepare("SELECT a.* FROM commerce_checkout_attempts a JOIN commerce_customer_shipping_selections s ON s.attempt_id=a.id WHERE s.quote_id=?");
$q->execute([$mismatchQuote['quoteId']]);$attempt2=$q->fetch(PDO::FETCH_ASSOC);
$changed=$correct;
$changed['id']=$attempt2['session_id'];
$changed['client_reference_id']=$attempt2['id'];
$changed['metadata']['azh_attempt_id']=$attempt2['id'];
$changed['payment_intent']='pi_dynamiccarrier987654';
$changed['total_details']['amount_shipping']=845;
$changed['amount_total']=3000;
$changed['collected_information']['shipping_details']['address']['line1']='999 Different Rd';
$eventMismatch=$event('evt_dynamicmismatch123','checkout.session.completed',$changed);
az_stripe_webhook_receive($eventMismatch,$signed($eventMismatch));
$q=$db->prepare("SELECT s.status FROM commerce_orders o JOIN commerce_shipments s ON s.order_id=o.id WHERE o.external_reference=?");
$q->execute(['stripe:'.$attempt2['id']]);
$assert($q->fetchColumn()==='exception',
    'changed Stripe destination blocks shipping, but retains verified paid order');
$assert((int)$db->query("SELECT COUNT(*) FROM commerce_orders WHERE provider='stripe'")->fetchColumn()===3,
    'original paid orders plus two carrier paid orders accounted for');
// Never unconditionally re-use the stale shipping rate for a changed destination.
$assert(az_customer_ship_address_matched($attempt2['id'],[
    'street'=>'999 Different Rd','unit'=>'Suite 5','city'=>'Phoenix',
    'state'=>'AZ','zip'=>'85002','country'=>'US'])===false,
    'signed Stripe destination mismatch detected by server address HMAC');

echo "Smart customer-selected carrier checkout: $checks passed\n";
