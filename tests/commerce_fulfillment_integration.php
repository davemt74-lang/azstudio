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
az_upgrade_commerce_fulfillment_schema($db);
$assert(az_schema_version($db)===39,'replay migration safe');
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
echo "Inventory/shipping Stripe integration: $checks passed\n";
