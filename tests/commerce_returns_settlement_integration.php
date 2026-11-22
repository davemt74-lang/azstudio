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
az_upgrade_commerce_fulfillment_schema($db);$ok(az_schema_version($db)===39,'inventory/shipping schema v39');
az_upgrade_commerce_carrier_schema($db);$ok(az_schema_version($db)===40,'carrier schema v40');
az_upgrade_commerce_customer_shipping_schema($db);$ok(az_schema_version($db)===41,'customer carrier quote schema v41');
az_upgrade_commerce_settlement_schema($db);$ok(az_schema_version($db)===42,'returns and settlement schema v42');
az_upgrade_commerce_local_schema($db);$ok(az_schema_version($db)===42,'local migration replay does not downgrade v42');
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

/* v1.36 — tracked cash exchange/refund, verified provider holds, payout ledger. */
$lastReturn=$db->query('SELECT COUNT(*) FROM commerce_returns')->fetchColumn();
$ok((int)$lastReturn===0,'return table starts empty');
$holdDays=az_payout_hold_days();$ok($holdDays>=7,'settlement hold has nonzero safety default');
$p3settings=$pickupSettings;
$p3settings['revision']=0;
$tracked=[
    'sku'=>'RETURN-SKU-001','stockMode'=>'tracked','stockOnHand'=>5,'stockRevision'=>0,
    'stockAdjustmentReason'=>'','lowStockThreshold'=>1,'shippingEnabled'=>false,
    'shippingRate'=>'flat','shippingFeeCents'=>0,'freeAboveCents'=>0,
    'weightGrams'=>0,'lengthMm'=>0,'widthMm'=>0,'heightMm'=>0,'handlingDays'=>3,'shipCountry'=>'US'
];
$p3=az_commerce_product_save($admin,['title'=>'Return Test Shirt','description'=>'Return and exchange test product',
    'priceCents'=>1000,'commissionBps'=>1250,'state'=>'active','checkoutMode'=>'external',
    'checkoutUrl'=>'','localSettings'=>$p3settings,'fulfillmentSettings'=>$tracked])['id'];
$promo3=az_id();
$db->prepare("INSERT INTO commerce_promotions(id,product_id,model_id,video_id,video_revision,state,approved_by,created_at,updated_at)
 VALUES(?,?,?,?,?,'approved',3,?,?)")
 ->execute([$promo3,$p3,1,$vid,2,az_now(),az_now()]);
$ok((int)az_fulfillment_row($p3)['on_hand']===5,'original tracked inventory created');
$cashRequest=static function(string $t,int $qty)use($p3,$promo3,$vid):array{
    return az_local_create_order([
        'productId'=>$p3,'method'=>'pickup','quantity'=>$qty,
        'name'=>'Private Refund Buyer','phone'=>'6025550189',
        'channel'=>'video','videoId'=>$vid,'promotionId'=>$promo3,'clientToken'=>$t
    ]);
};
$fulfillCash=static function(string $token,int $qty)use($cashRequest,$admin,$db,$ok):array {
    $result=$cashRequest($token,$qty);
    $id=(string)$db->query("SELECT order_id FROM commerce_local_orders ORDER BY created_at DESC,order_id DESC LIMIT 1")->fetchColumn();
    // Resolve the new order from its private receipt hash, not unreliable sort order.
    $q=$db->prepare("SELECT order_id FROM commerce_local_orders WHERE receipt_hash=?");
    $q->execute([hash('sha256',$result['receiptToken'])]);$id=(string)$q->fetchColumn();
    az_local_admin_action($admin,['id'=>$id,'revision'=>1,'decision'=>'quote','taxCents'=>80]);
    az_local_admin_action($admin,['id'=>$id,'revision'=>2,'decision'=>'accept']);
    az_local_admin_action($admin,['id'=>$id,'revision'=>3,'decision'=>'ready']);
    az_local_admin_action($admin,['id'=>$id,'revision'=>4,'decision'=>'collect','confirmCash'=>true]);
    az_local_admin_action($admin,['id'=>$id,'revision'=>5,'decision'=>'complete']);
    $db->prepare('UPDATE commerce_orders SET paid_at=? WHERE id=?')
       ->execute([gmdate('Y-m-d\TH:i:s\Z',time()-(az_payout_hold_days()+2)*86400),$id]);
    return [$result,$id];
};
[$firstCash,$firstOrder]=$fulfillCash(str_repeat('1',32),2);
$ok((int)az_fulfillment_row($p3)['on_hand']===3,'cash sale consumes two tracked units');
$balance=az_payout_model_balance(1);
$ok($balance['eligibleCents']>=250,'fulfilled older attributed sale is eligible');
$generic=az_return_public_request([
    'reference'=>'cash:'.$firstOrder,'email'=>'PrivateBuyer@Test.invalid',
    'kind'=>'refund','quantity'=>1,'reason'=>'One shirt arrived torn'
]);
$ok($generic['received']===true&& !array_key_exists('orderId',$generic),'unverified return never reveals order ownership');
$phantom=az_return_public_request([
    'reference'=>'not-a-real-order-9999','email'=>'PrivateBuyer@Test.invalid',
    'kind'=>'refund','quantity'=>1,'reason'=>'Another request with private info'
]);
$ok($phantom===$generic,'unknown order returns identical generic acknowledgment');
$q=$db->prepare("SELECT r.* FROM commerce_returns r WHERE r.order_id=?");
$q->execute([$firstOrder]);$case=$q->fetch(PDO::FETCH_ASSOC);$caseId=$case['id'];
$ok($case['source']==='unverified'&&$case['status']==='requested','customer request awaits verification');
$contact=az_return_admin_contact($admin,$caseId);
$ok($contact['contact']['email']==='privatebuyer@test.invalid','admin alone decrypts unverified return contact');
$expect(static fn()=>az_return_admin_contact($other,$caseId),'role_required');
$activity=json_encode($db->query("SELECT title,detail FROM commerce_activity")->fetchAll(),JSON_THROW_ON_ERROR);
$ok(!str_contains($activity,'privatebuyer@test.invalid'),'return contact never enters shared Agent activity');
$beforeHold=az_payout_model_balance(1);
$ok($beforeHold['eligibleCents']>=250,'unverified customer request alone does not freeze creator commission');
$draft=az_payout_draft($admin,['modelId'=>1]);
$ok($draft['state']==='draft'&&$draft['amountCents']>=250,'eligible commission draft reserves available balance');
$approve=az_return_admin_transition($admin,['id'=>$caseId,'revision'=>1,'decision'=>'authorize',
    'confirm'=>true,'evidence'=>'verified-order-email-001']);
$ok($approve['status']==='authorized','staff identity verification authorizes return');
$held=az_payout_model_balance(1);
$ok($held['heldCents']>=250&&$held['eligibleCents']===0,'authorized case holds full order commission');
$expect(static fn()=>az_payout_change($admin,[
    'id'=>$draft['id'],'revision'=>1,'decision'=>'approve','confirmApproval'=>true
]),'commission_held');
$cancelDraft=az_payout_change($admin,['id'=>$draft['id'],'revision'=>1,'decision'=>'cancel']);
$ok($cancelDraft['state']==='cancelled','stale payout draft can be cancelled without money sent');
$expect(static fn()=>az_payout_draft($admin,['modelId'=>1]),'no_eligible_commissions');
$ok(az_return_admin_transition($admin,['id'=>$caseId,'revision'=>2,'decision'=>'receive'])['status']==='received',
    'authorized goods physically received');
$expect(static fn()=>az_return_admin_transition($admin,[
    'id'=>$caseId,'revision'=>3,'decision'=>'inspect','condition'=>'damaged','restockQuantity'=>1
]),'invalid_restock');
$beforeRestock=(int)az_fulfillment_row($p3)['on_hand'];
$inspect=az_return_admin_transition($admin,['id'=>$caseId,'revision'=>3,
    'decision'=>'inspect','condition'=>'resellable','restockQuantity'=>1]);
$ok($inspect['status']==='inspected'&&(int)az_fulfillment_row($p3)['on_hand']===$beforeRestock+1,
    'inspected one physically received unit restocked once');
$expect(static fn()=>az_return_admin_transition($admin,[
    'id'=>$caseId,'revision'=>3,'decision'=>'inspect','condition'=>'resellable','restockQuantity'=>1
]),'revision_conflict');
$refundApproved=az_return_admin_transition($admin,['id'=>$caseId,'revision'=>4,
    'decision'=>'approve_refund','confirm'=>true]);
$ok($refundApproved['status']==='refund_approved','staff authorized refund but no money moved');
$expect(static fn()=>az_return_admin_transition($admin,[
    'id'=>$caseId,'revision'=>5,'decision'=>'confirm_refund','confirm'=>true,
    'evidence'=>'cash-refund-receipt-1'
]),'provider_refund_unverified');
$refundedCash=az_local_admin_action($admin,['id'=>$firstOrder,'revision'=>6,
    'decision'=>'refund','confirmCash'=>true,'reason'=>'Item returned and full cash refunded']);
$ok($refundedCash['paymentStatus']==='refunded','cash refund recorded by existing staff controls');
$ok((int)$db->query("SELECT commission_cents FROM commerce_orders WHERE id='$firstOrder'")->fetchColumn()===0,
    'cash refund sets creator earned commission to zero');
$confirmed=az_return_admin_transition($admin,['id'=>$caseId,'revision'=>5,
    'decision'=>'confirm_refund','confirm'=>true,'evidence'=>'cash-refund-receipt-1']);
$ok($confirmed['status']==='refunded','separate actual cash refund evidence closes approved return');
$ok((int)az_fulfillment_row($p3)['on_hand']===$beforeRestock+1,
    'refund reconciliation does not restock goods again');
$expect(static fn()=>az_return_admin_transition($admin,['id'=>$caseId,'revision'=>6,
    'decision'=>'confirm_refund','confirm'=>true,'evidence'=>'cash-refund-receipt-1'
]),'invalid_transition');
[$exchangeCash,$exchangeOrder]=$fulfillCash(str_repeat('2',32),1);
$balance2=az_payout_model_balance(1);
$ok($balance2['eligibleCents']>=125,'new fulfilled cash sale becomes settlement eligible');
$case2=az_return_admin_create($admin,['orderId'=>$exchangeOrder,'kind'=>'exchange',
    'quantity'=>1,'reason'=>'Ordered the wrong size']);
$case2Id=$case2['id'];
$ok($case2['status']==='requested','staff can intake a like-for-like exchange');
$expect(static fn()=>az_return_admin_transition($other,['id'=>$case2Id,'revision'=>1,
    'decision'=>'authorize','confirm'=>true,'evidence'=>'verified-email-005']),'role_required');
az_return_admin_transition($admin,['id'=>$case2Id,'revision'=>1,
    'decision'=>'authorize','confirm'=>true,'evidence'=>'verified-email-005']);
az_return_admin_transition($admin,['id'=>$case2Id,'revision'=>2,'decision'=>'receive']);
$beforeExchange=(int)az_fulfillment_row($p3)['on_hand'];
az_return_admin_transition($admin,['id'=>$case2Id,'revision'=>3,
    'decision'=>'inspect','condition'=>'unopened','restockQuantity'=>1]);
$ok((int)az_fulfillment_row($p3)['on_hand']===$beforeExchange+1,'exchange return physically restocked');
$ex=az_return_admin_transition($admin,['id'=>$case2Id,'revision'=>4,
    'decision'=>'approve_exchange','confirm'=>true]);
$ok($ex['status']==='exchange_approved'&&(int)az_fulfillment_row($p3)['reserved']===1,
    'like-for-like exchange reserves replacement stock');
$heldExchange=az_payout_model_balance(1);
$ok($heldExchange['eligibleCents']===0&&$heldExchange['heldCents']>=125,
    'creator commission remains held during unresolved replacement');
$sent=az_return_admin_transition($admin,['id'=>$case2Id,'revision'=>5,
    'decision'=>'dispatch_exchange','confirm'=>true,'evidence'=>'replacement-track-005']);
$ok($sent['status']==='exchange_sent','staff confirmed actual replacement dispatch');
$ok((int)az_fulfillment_row($p3)['reserved']===0
    &&(int)az_fulfillment_row($p3)['on_hand']===$beforeExchange,
    'exchange consumes only the reserved unit after physical dispatch');
$balance3=az_payout_model_balance(1);
$ok($balance3['eligibleCents']>=125,'resolved exchange releases creator commission hold');
$payout=az_payout_draft($admin,['modelId'=>1]);
$ok($payout['amountCents']>=125,'eligible settled exchange order drafts commission');
$approvedBatch=az_payout_change($admin,['id'=>$payout['id'],'revision'=>1,
    'decision'=>'approve','confirmApproval'=>true]);
$ok($approvedBatch['state']==='approved','commission payout approval is separate from draft');
$expect(static fn()=>az_payout_change($admin,['id'=>$payout['id'],'revision'=>2,
    'decision'=>'paid','paymentChannel'=>'bank','evidenceReference'=>'bank-transfer-001'
]),'confirmation_required');
$paidBatch=az_payout_change($admin,['id'=>$payout['id'],'revision'=>2,
    'decision'=>'paid','paymentChannel'=>'bank','evidenceReference'=>'bank-transfer-001',
    'confirmPaid'=>true]);
$ok($paidBatch['state']==='paid','external payout entered only after transfer confirmation');
$ok(az_payout_model_balance(1)['recordedPaidCents']>0,
    'model sees actual staff-recorded external payout');
$expect(static fn()=>az_payout_change($admin,['id'=>$payout['id'],'revision'=>3,
    'decision'=>'cancel']),'invalid_transition');
$expect(static fn()=>az_payout_change($admin,['id'=>$payout['id'],'revision'=>3,
    'decision'=>'paid','paymentChannel'=>'bank','evidenceReference'=>'bank-transfer-001',
    'confirmPaid'=>true]),'invalid_transition');
$otherPayout=az_payout_role_state($model);
$ok($otherPayout['role']==='model'&&count($otherPayout['batches'])>=1,
    'creator can view own paid settlement ledger');
$ok(!array_key_exists('models',$otherPayout),
    'model payout view does not expose other creators');
$adminPayout=az_payout_role_state($admin);
$ok($adminPayout['role']==='admin'&&count($adminPayout['batches'])>=1,
    'administrator sees payout audit and eligible creator summaries');
// A later cash refund on the originally exchanged order reduces earned
// commission after the external payout. No duplicate return case is fabricated.
az_local_admin_action($admin,['id'=>$exchangeOrder,'revision'=>6,
    'decision'=>'refund','confirmCash'=>true,'reason'=>'Post-payout refund physically repaid']);
$debt=az_payout_model_balance(1);
$ok($debt['overpaidCents']>=125&&$debt['newPayoutBlocked'],
    'paid commission later reversed by refund becomes explicit overpayment liability');
$expect(static fn()=>az_payout_draft($admin,['modelId'=>1]),'commission_overpaid');
$ok((int)$db->query("SELECT COUNT(*) FROM commerce_return_events")->fetchColumn()>=10,
    'return approvals, inspection and provider reconciliation have durable audit entries');
$ok(count(az_returns_briefing($admin)['counts'])>0&&count(az_payout_briefing($admin)['counts'])>0,
    'Agent Brain receives role-scoped return and settlement counts');
echo "Returns, exchanges and external creator settlement integration: $checks passed\n";

