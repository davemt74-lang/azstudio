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
az_upgrade_commerce_fulfillment_schema($db);
$assert(az_schema_version($db)===41,'older schema migration replay preserves v41');
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

/* EasyPost multi-carrier quote and explicit test-label purchase. */
putenv('AZH_EASYPOST_API_KEY=EZTK_fixture_carrier_key_123456789');
putenv('AZH_EASYPOST_WEBHOOK_SECRET=carrier-webhook-fixture-secret-123456789');
putenv('AZH_EASYPOST_FROM_NAME=Studio Fulfillment');
putenv('AZH_EASYPOST_FROM_STREET=25 Origin Example Rd');
putenv('AZH_EASYPOST_FROM_CITY=Phoenix');
putenv('AZH_EASYPOST_FROM_STATE=AZ');
putenv('AZH_EASYPOST_FROM_ZIP=85001');
putenv('AZH_EASYPOST_FROM_PHONE=6025550123');
putenv('AZH_EASYPOST_ALLOW_LABEL_PURCHASES=1');
$cfg=az_carrier_config();
$assert($cfg['configured']&&$cfg['mode']==='test'&&$cfg['allowPurchase'],'test carrier configured');
$quoteCalls=0;$buyCalls=0;$getCalls=0;
$carrierMockShipmentId='shp_TESTCARRIER123456789';
$carrierRateId='rate_UPS112233445566';
$carrierTrackId='trk_TESTCARRIER12345678';
$carrierTracking='1Z999AA10123456784';
$providerBought=false;
$GLOBALS['az_carrier_test_transport']=static function(string $method,string $path,?array $payload)
    use(&$quoteCalls,&$buyCalls,&$getCalls,$carrierMockShipmentId,$carrierRateId,$carrierTrackId,
         $carrierTracking,&$providerBought):array{
    $rates=[
        ['id'=>'rate_USPS998877665544','carrier'=>'USPS','service'=>'GroundAdvantage',
            'rate'=>'6.25','currency'=>'USD','delivery_days'=>5],
        ['id'=>$carrierRateId,'carrier'=>'UPS','service'=>'Ground',
            'rate'=>'7.50','currency'=>'USD','delivery_days'=>3],
        ['id'=>'rate_FEDEX113355779900','carrier'=>'FedEx','service'=>'FEDEX_GROUND',
            'rate'=>'9.15','currency'=>'USD','delivery_days'=>4]
    ];
    $purchased=[
        'id'=>$carrierMockShipmentId,'object'=>'Shipment','mode'=>'test',
        'selected_rate'=>['id'=>$carrierRateId,'rate'=>'7.50','carrier'=>'UPS','service'=>'Ground'],
        'tracking_code'=>$carrierTracking,
        'postage_label'=>['label_url'=>'https://easypost-files.s3.us-west-2.amazonaws.com/label.png'],
        'tracker'=>['id'=>$carrierTrackId,'object'=>'Tracker']
    ];
    if($method==='POST'&&$path==='/shipments'){
        $quoteCalls++;
        $shipment=$payload['shipment']??[];
        if(($shipment['to_address']['name']??'')!=='Private Recipient One'
            ||($shipment['from_address']['zip']??'')!=='85001'
            ||(float)($shipment['parcel']['weight']??0)<=0)
            throw new RuntimeException('Carrier quote must use actual encrypted destination, origin and parcel');
        return ['id'=>$carrierMockShipmentId,'object'=>'Shipment','mode'=>'test','rates'=>$rates];
    }
    if($method==='POST'&&$path==='/shipments/'.$carrierMockShipmentId.'/buy'){
        $buyCalls++;
        if(($payload['rate']['id']??'')!==$carrierRateId)throw new RuntimeException('Wrong purchased rate');
        $providerBought=true;return $purchased;
    }
    if($method==='GET'&&$path==='/shipments/'.$carrierMockShipmentId){
        $getCalls++;return $providerBought?$purchased:
            ['id'=>$carrierMockShipmentId,'object'=>'Shipment','mode'=>'test','rates'=>$rates];
    }
    throw new RuntimeException('Unexpected provider endpoint: '.$method.' '.$path);
};
$parcel=['id'=>$orderId,'confirmParcel'=>true,
    'weightGrams'=>400,'lengthMm'=>220,'widthMm'=>150,'heightMm'=>70];
$quote=az_carrier_quote($admin,$parcel);
$assert(count($quote['rates'])===3&&$quote['rates'][0]['carrier']==='USPS','real rates return sorted USPS UPS FedEx offers');
$quoteAgain=az_carrier_quote($admin,$parcel);
$assert($quoteAgain['shipmentId']===$quote['shipmentId']&&$quoteCalls===1,'same parcel quote is reused without another provider shipment');
$reject(static fn()=>az_carrier_quote($admin,array_merge($parcel,['weightGrams'=>800])),'parcel_changed');
$reject(static fn()=>az_carrier_quote($model,$parcel),'role_required');
$reject(static fn()=>az_carrier_buy($admin,[
    'id'=>$orderId,'rateId'=>$carrierRateId,'revision'=>$quote['revision'],'expectedCents'=>750
]),'purchase_confirmation_required');
$reject(static fn()=>az_carrier_buy($admin,[
    'id'=>$orderId,'rateId'=>$carrierRateId,'revision'=>$quote['revision'],
    'expectedCents'=>751,'confirmPurchase'=>true
]),'rate_mismatch');
putenv('AZH_EASYPOST_ALLOW_LABEL_PURCHASES=0');
$reject(static fn()=>az_carrier_buy($admin,[
    'id'=>$orderId,'rateId'=>$carrierRateId,'revision'=>$quote['revision'],
    'expectedCents'=>750,'confirmPurchase'=>true
]),'carrier_purchase_disabled');
putenv('AZH_EASYPOST_ALLOW_LABEL_PURCHASES=1');
$label=az_carrier_buy($admin,[
    'id'=>$orderId,'rateId'=>$carrierRateId,'revision'=>$quote['revision'],
    'expectedCents'=>750,'confirmPurchase'=>true
]);
$assert($label['status']==='purchased'&&$label['rateCents']===750&&$buyCalls===1,
    'explicit test-mode label purchase recorded exactly once');
$assert($label['trackingNumber']===$carrierTracking&&str_starts_with($label['labelUrl'],'https://'),
    'provider tracking and HTTPS label retained');
$reject(static fn()=>az_carrier_buy($admin,[
    'id'=>$orderId,'rateId'=>$carrierRateId,'revision'=>$quote['revision'],
    'expectedCents'=>750,'confirmPurchase'=>true
]),'label_already_requested');
$assert($buyCalls===1,'repeat purchase cannot double charge');
$carrierShipments=az_fulfillment_shipments($admin);
$assert($carrierShipments[0]['carrierIntegration']['label']['status']==='purchased',
    'carrier label available only in admin shipping view');
$assert(!str_contains(json_encode($carrierShipments),'Private Recipient One'),
    'shipping rates and labels never expose recipient PII');

$reject(static fn()=>az_fulfillment_shipment_action($admin,['id'=>$orderId,'revision'=>1,'decision'=>'ship','carrier'=>'ups','trackingNumber'=>'1Z999AA10123456784']),'revision_conflict');
$reject(static fn()=>az_fulfillment_shipment_action($admin,['id'=>$orderId,'revision'=>2,'decision'=>'ship','carrier'=>'ups','trackingNumber'=>'invalid tracking!']),'invalid_tracking');
$shipped=az_fulfillment_shipment_action($admin,['id'=>$orderId,'revision'=>2,
    'decision'=>'ship','carrier'=>'ups','trackingNumber'=>'1Z999AA10123456784']);
$assert($shipped['status']==='shipped'&&str_contains($shipped['trackingUrl'],'ups.com'),
    'staff records carrier/tracking; safe official tracking link generated');
$reject(static fn()=>az_fulfillment_shipment_action($admin,['id'=>$orderId,'revision'=>3,'decision'=>'deliver']),'confirmation_required');
$done=az_fulfillment_shipment_action($admin,['id'=>$orderId,'revision'=>3,'decision'=>'deliver','confirmDelivered'=>true]);
$assert($done['status']==='delivered','admin explicitly marks parcel delivered');

/* A purchased-label tracker webhook is authenticated, monotonic and replay safe. */
$eventFor=static function(string $id,string $status,int $when)use($carrierTrackId,$carrierTracking):string{
    return json_encode(['object'=>'Event','id'=>$id,'mode'=>'test','description'=>'tracker.updated',
        'created_at'=>gmdate('Y-m-d\TH:i:s\Z',$when),
        'result'=>['object'=>'Tracker','id'=>$carrierTrackId,'tracking_code'=>$carrierTracking,
            'status'=>$status,'updated_at'=>gmdate('Y-m-d\TH:i:s\Z',$when)]],
        JSON_THROW_ON_ERROR);
};
$sigFor=static fn(string $raw):string=>
    'hmac-sha256-hex='.hash_hmac('sha256',$raw,'carrier-webhook-fixture-secret-123456789');
$incoming=$eventFor('evt_TESTCARRIER111111','in_transit',time()-120);
$reject(static fn()=>az_carrier_webhook_receive($incoming,'hmac-sha256-hex='.str_repeat('0',64)),
    'carrier_signature_invalid');
$updated=az_carrier_webhook_receive($incoming,$sigFor($incoming));
$assert($updated['processed']===true,'signed EasyPost tracker event accepted');
$assert($db->query("SELECT status FROM commerce_shipments WHERE order_id='$orderId'")->fetchColumn()==='delivered',
    'older in-transit scan cannot demote staff-confirmed delivered status');
$againCarrier=az_carrier_webhook_receive($incoming,$sigFor($incoming));
$assert($againCarrier['duplicate']===true,'duplicate carrier webhook is ignored');
$deliveredCarrier=$eventFor('evt_TESTCARRIER222222','delivered',time());
az_carrier_webhook_receive($deliveredCarrier,$sigFor($deliveredCarrier));
$assert($db->query("SELECT carrier_tracking_status FROM commerce_shipments WHERE order_id='$orderId'")->fetchColumn()==='delivered',
    'verified carrier delivery status retained separately from staff status');
$late=$eventFor('evt_TESTCARRIER333333','failure',time()-3600);
az_carrier_webhook_receive($late,$sigFor($late));
$assert($db->query("SELECT carrier_tracking_status FROM commerce_shipments WHERE order_id='$orderId'")->fetchColumn()==='delivered',
    'out-of-order failure cannot replace later delivered scan');
$storedEvents=(int)$db->query('SELECT COUNT(*) FROM commerce_carrier_events')->fetchColumn();
$assert($storedEvents===3,'three uniquely signed event IDs recorded');
$assert(!str_contains(json_encode($db->query('SELECT * FROM commerce_carrier_events')->fetchAll()),
    'Private Recipient One'),'tracker event ledger does not store shipping contact');

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

/* Simulate a carrier purchase that was charged but whose HTTP response timed out.
 * The platform must never issue a second buy; GET reconciliation recovers it.
 */
$secondId=az_id();$secondRef='stripe:carrier-fixture-'.$secondId;
$now=az_now();
$db->prepare("INSERT INTO commerce_orders(
    id,external_reference,product_id,quantity,amount_cents,currency,commission_bps,commission_cents,
    state,recorded_by,provider,created_at,paid_at,updated_at
    ) VALUES(?,?,?,1,1599,'USD',0,0,'paid',3,'stripe',?,?,?)")
    ->execute([$secondId,$secondRef,$p,$now,$now,$now]);
$db->prepare("INSERT INTO commerce_shipments(
    order_id,contact_ciphertext,product_title,shipping_fee_cents,status,created_at,updated_at
    ) VALUES(?,?,?,375,'packed',?,?)")
    ->execute([$secondId,az_local_encrypt([
        'name'=>'Private Recipient Two','street'=>'789 Recovery Ave','unit'=>'',
        'city'=>'Phoenix','state'=>'AZ','zip'=>'85002','country'=>'US'
    ]),'Carrier recovery parcel',$now,$now]);
$secondProviderId='shp_RECOVERY123456789';
$secondRateId='rate_RECOVERY123456789';
$secondTrackerId='trk_RECOVERY123456789';
$uncertainCharges=0;$getAfterTimeout=0;
$GLOBALS['az_carrier_test_transport']=static function(string $method,string $path,?array $payload)
    use($secondProviderId,$secondRateId,$secondTrackerId,&$uncertainCharges,&$getAfterTimeout):array {
    $purchased=[
        'object'=>'Shipment','id'=>$secondProviderId,'mode'=>'test',
        'selected_rate'=>['id'=>$secondRateId,'carrier'=>'UPS','service'=>'Ground','rate'=>'7.00'],
        'tracking_code'=>'1Z999AA10123456785',
        'postage_label'=>['label_url'=>'https://easypost-files.s3.us-west-2.amazonaws.com/recoveredlabel.png'],
        'tracker'=>['id'=>$secondTrackerId]
    ];
    if($method==='POST'&&$path==='/shipments')
        return ['id'=>$secondProviderId,'object'=>'Shipment','mode'=>'test',
            'rates'=>[['id'=>$secondRateId,'carrier'=>'UPS','service'=>'Ground',
                'currency'=>'USD','rate'=>'7.00','delivery_days'=>4]]];
    if($method==='POST'&&$path==='/shipments/'.$secondProviderId.'/buy'){
        $uncertainCharges++;
        throw new AzHttpError(502,'Simulated timeout after EasyPost charged a label.','carrier_provider_unavailable');
    }
    if($method==='GET'&&$path==='/shipments/'.$secondProviderId){
        $getAfterTimeout++;return $purchased;
    }
    throw new RuntimeException('Unexpected carrier recovery test endpoint.');
};
$secondParcel=['id'=>$secondId,'confirmParcel'=>true,
    'weightGrams'=>400,'lengthMm'=>220,'widthMm'=>150,'heightMm'=>70];
$recoveryQuote=az_carrier_quote($admin,$secondParcel);
$reject(static fn()=>az_carrier_buy($admin,[
    'id'=>$secondId,'rateId'=>$secondRateId,'expectedCents'=>700,
    'revision'=>$recoveryQuote['revision'],'confirmPurchase'=>true
]),'label_outcome_unknown');
$unknownLabel=az_carrier_label_row($secondId);
$assert($unknownLabel['status']==='needs_review'&&$uncertainCharges===1,
    'ambiguous paid provider response becomes durable needs_review state');
$reject(static fn()=>az_carrier_buy($admin,[
    'id'=>$secondId,'rateId'=>$secondRateId,'expectedCents'=>700,
    'revision'=>$recoveryQuote['revision'],'confirmPurchase'=>true
]),'label_already_requested');
$assert($uncertainCharges===1,'uncertain label is NEVER purchased twice');
$recovered=az_carrier_reconcile($admin,['id'=>$secondId]);
$assert($recovered['status']==='purchased'&&$getAfterTimeout===1&&$uncertainCharges===1,
    'GET reconciliation recovers label without another charge');
$assert(az_carrier_label_row($secondId)['status']==='purchased',
    'recovered purchase persists for future staff and Agent review');

echo "Multi-carrier EasyPost rate, label and signed tracker integration: $checks passed\n";
