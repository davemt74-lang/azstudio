<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){fwrite(STDERR,"SQLite PDO unavailable\n");exit(2);}
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
session_id('azh-local-test-fixture');session_start();
$GLOBALS['az_config_cache']=['app_secret'=>str_repeat('s',64)];
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('PRAGMA foreign_keys=ON');$GLOBALS['az_database']=$db;
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
INSERT INTO users VALUES (1,'Creator A','one@example.invalid','active',1,'now',NULL),
(2,'Creator B','two@example.invalid','active',1,'now',NULL),
(3,'Admin','admin@example.invalid','active',1,'now',NULL);
INSERT INTO user_roles VALUES(1,'model'),(2,'model'),(3,'admin');");
$admin=['id'=>3,'status'=>'active','roles'=>['admin']];
$model=['id'=>1,'status'=>'active','roles'=>['model']];
$other=['id'=>2,'status'=>'active','roles'=>['model']];
$checks=0;$ok=static function(bool $good,string $label)use(&$checks):void{
    if(!$good)throw new RuntimeException('Cash local Commerce FAIL: '.$label);
    $checks++;
};
$expect=static function(callable $fn,string $code)use($ok):void{
    try{$fn();$ok(false,'expected '.$code);}catch(AzHttpError $e){$ok($e->errorCode===$code,'expected '.$code.' saw '.$e->errorCode);}
};
az_upgrade_commerce_schema($db);$ok(az_schema_version($db)===36,'base Commerce v36');
az_upgrade_commerce_stripe_schema($db);$ok(az_schema_version($db)===37,'verified Commerce v37');
az_upgrade_commerce_local_schema($db);$ok(az_schema_version($db)===38,'local cash schema v38');
az_upgrade_commerce_local_schema($db);$ok(az_schema_version($db)===38,'local migration replay safe');
$ok(az_local_zips('85002,85001,85002')===['85001','85002'],'ZIPs normalized');
$expect(static fn()=>az_local_zips('8500x'),'invalid_zips');
$pickupSettings=[
    'revision'=>0,'enabled'=>true,'pickupEnabled'=>true,'deliveryEnabled'=>false,
    'pickupLocation'=>'Studio One','pickupAddress'=>'123 Market St, Phoenix, AZ',
    'pickupHours'=>'Mon-Fri 10-4','pickupInstructions'=>'Please wait for confirmation',
    'deliveryZips'=>'','deliveryState'=>'AZ','deliveryFeeCents'=>0,'deliveryMinCents'=>0,
    'deliveryInstructions'=>''
];
$deliverySettings=[
    'revision'=>0,'enabled'=>true,'pickupEnabled'=>false,'deliveryEnabled'=>true,
    'pickupLocation'=>'','pickupAddress'=>'','pickupHours'=>'','pickupInstructions'=>'',
    'deliveryZips'=>'85002, 85003','deliveryState'=>'AZ','deliveryFeeCents'=>400,
    'deliveryMinCents'=>2000,'deliveryInstructions'=>'Staff confirms delivery times'
];
$p1=az_commerce_product_save($admin,['title'=>'Pickup-only item','description'=>'Cash only',
    'priceCents'=>1000,'commissionBps'=>1250,'state'=>'active','checkoutMode'=>'external','checkoutUrl'=>'',
    'localSettings'=>$pickupSettings])['id'];
$p2=az_commerce_product_save($admin,['title'=>'Delivery-only item','description'=>'Cash delivery only',
    'priceCents'=>2000,'commissionBps'=>1000,'state'=>'active','checkoutMode'=>'external','checkoutUrl'=>'',
    'localSettings'=>$deliverySettings])['id'];
$ok(az_valid_id($p1)&&az_valid_id($p2),'two cash-only products created');
$first=az_local_options($p1);$second=az_local_options($p2);
$ok($first['pickupEnabled']&&!$first['deliveryEnabled']&&$first['pickupAddress']==='123 Market St, Phoenix, AZ','pickup only scoped to first product');
$ok(!$second['pickupEnabled']&&$second['deliveryEnabled']&&$second['deliveryFeeCents']===400,'delivery only scoped to second product');
$ok($second['deliveryZips']===['85002','85003']&&$first['deliveryZips']===[],'independent ZIP coverage');
$public=az_commerce_public_products();
$ok(count($public)===2&&$public[0]['localEnabled']&&$public[1]['localEnabled'],'cash-only products appear in public Shop without card checkout');
$ok(count(az_commerce_state($admin)['products'])===2,'admin sees both product settings');
az_commerce_brief_save($admin,['productId'=>$p1,'title'=>'Cash creator brief',
    'instructions'=>'Approved creator video for this product.','state'=>'active']);
$modelProductView=az_commerce_state($model)['products'];
$ok(count($modelProductView)===1&&!array_key_exists('localSettings',$modelProductView[0]),'non-admin cannot fetch private product editing settings');
$local=az_local_settings_admin($admin,$p1);
$ok($local['pickupAddress']==='123 Market St, Phoenix, AZ','admin reads selected product settings');
$expect(static fn()=>az_local_settings_admin($model,$p1),'role_required');
$expect(static fn()=>az_commerce_product_save($admin,['id'=>$p1,'revision'=>1,'title'=>'Pickup-only item',
    'priceCents'=>1000,'commissionBps'=>1250,'checkoutMode'=>'external','checkoutUrl'=>'',
    'state'=>'active','localSettings'=>array_merge($pickupSettings,['revision'=>9])]),'revision_conflict');
// Creator link previously approved for first product, with current published and consented video.
$vid=str_repeat('a',32);$promo=str_repeat('b',32);
$db->prepare("INSERT INTO model_videos VALUES(?,1,'media','Creator video','published',2,1,?,?)")->execute([$vid,az_now(),az_now()]);
$db->prepare("INSERT INTO commerce_promotions(id,product_id,model_id,video_id,video_revision,state,approved_by,created_at,updated_at) VALUES(?,?,?,?,?,'approved',3,?,?)")
    ->execute([$promo,$p1,1,$vid,2,az_now(),az_now()]);
$ok(count(az_commerce_video_products($vid))===1,'cash-only affiliate video remains shoppable');
$request=[
    'productId'=>$p1,'method'=>'pickup','quantity'=>1,
    'name'=>'Private Customer Alpha','phone'=>'6025550188','email'=>'test@example.invalid',
    'channel'=>'video','videoId'=>$vid,'promotionId'=>$promo,'clientToken'=>str_repeat('c',32)
];
$created=az_local_create_order($request);
$ok(!$created['duplicate']&&strlen($created['receiptToken'])===64,'first receipt is secret bearer token');
$repeat=az_local_create_order($request);
$ok($repeat['duplicate']&&$repeat['receiptToken']===$created['receiptToken'],'cash checkout request replay returns original receipt');
$expect(static fn()=>az_local_create_order(array_merge($request,['name'=>'Different Customer'])),'token_reused');
$receipt=az_local_customer_status($created['receiptToken']);
$ok($receipt['status']==='pending'&&$receipt['totalCashDueCents']===null,'customer initially sees only unpaid unaccepted request');
$ok($receipt['pickupDetails']===''&&!array_key_exists('contact',$receipt),'pending receipt reveals no personal data or pickup directions');
$expect(static fn()=>az_local_customer_status(str_repeat('0',64)),'receipt_not_found');
$orders=az_local_admin_orders($admin);$id=$orders[0]['id'];
$ok(count($orders)===1&&$orders[0]['paymentStatus']==='due','staff queue contains unpaid request');
$expect(static fn()=>az_local_admin_orders($model),'role_required');
$details=az_local_admin_details($admin,$id);
$ok($details['contact']['name']==='Private Customer Alpha'&&$details['contact']['phone']==='6025550188','admin alone decrypts customer details');
$expect(static fn()=>az_local_admin_details($other,$id),'role_required');
$log=json_encode(az_db()->query('SELECT detail FROM commerce_activity')->fetchAll(),JSON_THROW_ON_ERROR);
$ok(!str_contains($log,'Private Customer Alpha')&&!str_contains($log,'6025550188'),'agent activity does not contain contact details');
$before=$db->query('SELECT commission_cents FROM commerce_orders LIMIT 1')->fetchColumn();
$ok((int)$before===0,'no creator commission before cash collection');
$expect(static fn()=>az_local_admin_action($admin,['id'=>$id,'revision'=>1,'decision'=>'collect','confirmCash'=>true]),'invalid_transition');
$quote=az_local_admin_action($admin,['id'=>$id,'revision'=>1,'decision'=>'quote','taxCents'=>83]);
$ok($quote['revision']===2&&$quote['totalDueCents']===1083,'staff quotes cash due incl product-specific tax');
$expect(static fn()=>az_local_admin_action($admin,['id'=>$id,'revision'=>1,'decision'=>'accept']),'revision_conflict');
$accept=az_local_admin_action($admin,['id'=>$id,'revision'=>2,'decision'=>'accept']);
$ok($accept['status']==='accepted','staff approves after quoting');
$receipt=az_local_customer_status($created['receiptToken']);
$ok($receipt['pickupDetails']!==''&&$receipt['totalCashDueCents']===1083,'accepted receipt shows pickup location and final cash due');
$ready=az_local_admin_action($admin,['id'=>$id,'revision'=>3,'decision'=>'ready']);
$ok($ready['status']==='ready','pickup prepared');
$expect(static fn()=>az_local_admin_action($admin,['id'=>$id,'revision'=>4,'decision'=>'collect']),'confirmation_required');
$collected=az_local_admin_action($admin,['id'=>$id,'revision'=>4,'decision'=>'collect','confirmCash'=>true]);
$ok($collected['paymentStatus']==='collected','staff explicitly records cash collection');
$o=$db->query("SELECT commission_cents,state FROM commerce_orders WHERE id='$id'")->fetch();
$ok($o['state']==='paid'&&(int)$o['commission_cents']===125,'creator commission only product subtotal, not tax');
$complete=az_local_admin_action($admin,['id'=>$id,'revision'=>5,'decision'=>'complete']);
$ok($complete['status']==='fulfilled','pickup completed only after collection');
$refund=az_local_admin_action($admin,['id'=>$id,'revision'=>6,'decision'=>'refund','confirmCash'=>true,'reason'=>'Customer returned item']);
$ok($refund['paymentStatus']==='refunded','staff explicitly records cash refund');
$ok((int)$db->query("SELECT commission_cents FROM commerce_orders WHERE id='$id'")->fetchColumn()===0,'full cash refund reverses estimated commission');
$stats=az_commerce_analytics($model);
$ok($stats['cash']['orders']===1&&$stats['cash']['netCents']===0&&$stats['cash']['commissionCents']===0,'creator analytics separate cash from verified Stripe');
$ok($stats['verified']['orders']===0,'cash not mislabeled Stripe verified');
// Per-product delivery checks. Pickup-only product cannot be ordered for delivery.
$expect(static fn()=>az_local_create_order(array_merge($request,['method'=>'delivery','clientToken'=>str_repeat('d',32)])),'local_closed');
$deliveryRequest=[
    'productId'=>$p2,'method'=>'delivery','quantity'=>1,'name'=>'Private Customer Beta',
    'phone'=>'6025550156','channel'=>'shop','clientToken'=>str_repeat('e',32),
    'street'=>'456 Deliver Ave','city'=>'Phoenix','state'=>'AZ','zip'=>'85001'
];
$expect(static fn()=>az_local_create_order($deliveryRequest),'delivery_area_unavailable');
$deliveryRequest['zip']='85002';
$deliveryCreated=az_local_create_order($deliveryRequest);
$ok(!$deliveryCreated['duplicate']&&$deliveryCreated['order']['estimatedBeforeTaxCents']===2400,'product-specific delivery fee included');
$cancel=az_local_customer_cancel($deliveryCreated['receiptToken']);
$ok($cancel['cancelled'],'unaccepted cash request can be cancelled by its receipt holder');
$expect(static fn()=>az_local_customer_cancel($created['receiptToken']),'cancel_not_allowed');
$ok(az_local_customer_status($deliveryCreated['receiptToken'])['status']==='cancelled','cancelled cash receipt remains viewable');
// Contact integrity: authenticated encryption rejects even one-bit ciphertext edits.
$q=$db->prepare('SELECT contact_ciphertext FROM commerce_local_orders WHERE order_id=?');$q->execute([$id]);$cipher=$q->fetchColumn();
$original=base64_decode($cipher,true);$broken=$original;$broken[strlen($broken)-1]=chr(ord($broken[strlen($broken)-1])^1);
$failed=false;try{az_local_decrypt(base64_encode($broken));}catch(Throwable){$failed=true;}
$ok($failed,'tampering protected customer contact fails closed');
echo "Per-product cash pickup and delivery PHP/SQLite integration: $checks passed\n";
