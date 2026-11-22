<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){fwrite(STDERR,"SQLite PDO unavailable\n");exit(2);}
session_id('commerce-stripe-fixture');session_start();
$GLOBALS['az_config_cache']=['app_secret'=>'fixture-secret-keep-private'];
putenv('AZH_STRIPE_SECRET_KEY=sk_test_fixture_very_long_secret_key');
putenv('AZH_STRIPE_WEBHOOK_SECRET=whsec_fixture_signing_secret');
putenv('AZH_PUBLIC_BASE_URL=https://studio.example');
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('PRAGMA foreign_keys=ON');$GLOBALS['az_database']=$db;
$db->exec("CREATE TABLE studio_schema(id INTEGER PRIMARY KEY,version INTEGER);
INSERT INTO studio_schema VALUES(1,35);
CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,email TEXT,status TEXT,revision INTEGER,created_at TEXT,last_login_at TEXT);
CREATE TABLE user_roles(user_id INTEGER,role TEXT,PRIMARY KEY(user_id,role));
CREATE TABLE user_role_requests(user_id INTEGER,role TEXT,status TEXT);
CREATE TABLE auth_attempts(attempt_key TEXT PRIMARY KEY,attempt_count INTEGER,window_started BIGINT);
CREATE TABLE model_videos(id TEXT PRIMARY KEY,user_id INTEGER,media_id TEXT,title TEXT,status TEXT,revision INTEGER,rights_confirmed INTEGER,published_at TEXT,updated_at TEXT);
CREATE TABLE az_live_rooms(id TEXT PRIMARY KEY,owner_id INTEGER,status TEXT,heartbeat_epoch INTEGER,started_epoch INTEGER);
CREATE TABLE agent_workspace_events(id TEXT PRIMARY KEY,recipient_id INTEGER,actor_id INTEGER,kind TEXT,title TEXT,detail TEXT,href TEXT,created_at TEXT,read_at TEXT);
CREATE TABLE agent_chat_threads(id TEXT PRIMARY KEY,user_id INTEGER,title TEXT,created_at TEXT,updated_at TEXT);
CREATE TABLE agent_chat_messages(id TEXT PRIMARY KEY,thread_id TEXT,user_id INTEGER,position INTEGER,role TEXT,content TEXT,client_token TEXT,state TEXT,created_at TEXT,updated_at TEXT,UNIQUE(thread_id,position));
INSERT INTO users VALUES (1,'Model A','a@test.invalid','active',1,'now',NULL),
(2,'Model B','b@test.invalid','active',1,'now',NULL),(3,'Admin','admin@test.invalid','active',1,'now',NULL);
INSERT INTO user_roles VALUES(1,'model'),(2,'model'),(3,'admin');");
$admin=['id'=>3,'roles'=>['admin'],'status'=>'active'];$model=['id'=>1,'roles'=>['model'],'status'=>'active'];
$other=['id'=>2,'roles'=>['model'],'status'=>'active'];$pass=0;
$ok=static function(bool $value,string $label)use(&$pass):void{
    if(!$value)throw new RuntimeException('Stripe integration failed: '.$label);
    $pass++;
};
$err=static function(callable $cb,string $code)use($ok):void{
    try{$cb();$ok(false,'expected '.$code);}catch(AzHttpError $e){$ok($e->errorCode===$code,'expected '.$code.' got '.$e->errorCode);}
};
az_upgrade_commerce_schema($db);$ok(az_schema_version($db)===36,'v1.30 migration');
az_upgrade_commerce_stripe_schema($db);$ok(az_schema_version($db)===37,'v1.31 migration');
az_upgrade_commerce_stripe_schema($db);$ok(az_schema_version($db)===37,'migration replay');
az_upgrade_commerce_local_schema($db);$ok(az_schema_version($db)===38,'per-product cash schema migrated');
az_upgrade_commerce_fulfillment_schema($db);$ok(az_schema_version($db)===39,'inventory/shipping schema migrated');
az_upgrade_commerce_carrier_schema($db);$ok(az_schema_version($db)===40,'carrier schema v40');
$ok(az_stripe_config()['ready']&&az_stripe_config()['mode']==='test','Stripe test configuration');
$bad='{"id":"evt_bad","object":"event"}';
$ok(!az_stripe_verify_signature($bad,'t=1700000000,v1='.str_repeat('0',64),'whsec_fixture_signing_secret'), 'bad signature rejected');
$order=az_commerce_product_save($admin,['title'=>'Verified sample','description'=>'Test product',
    'priceCents'=>1250,'commissionBps'=>1200,'checkoutMode'=>'stripe','state'=>'active']);
$pid=$order['id'];$ok(az_valid_id($pid),'Stripe product saved');
$public=az_commerce_public_products();$ok(count($public)===1&&$public[0]['checkoutMode']==='stripe'&&$public[0]['checkoutUrl']==='','secure Stripe product listed without merchant link');
$video=str_repeat('f',32);$promo=str_repeat('e',32);
$db->prepare("INSERT INTO model_videos VALUES(?,1,'asset','Video about product','published',4,1,?,?)")
    ->execute([$video,az_now(),az_now()]);
$db->prepare("INSERT INTO commerce_promotions VALUES(?,?,?,?,?,'approved',3,?,?)")
    ->execute([$promo,$pid,1,$video,4,az_now(),az_now()]);
$ok(count(az_commerce_video_products($video))===1,'approved creator video shoppable');
$attempts=0;
$GLOBALS['az_stripe_test_transport']=static function(array $payload,string $id)use(&$attempts):array{
    $attempts++;
    if($payload['line_items'][0]['price_data']['unit_amount']!==1250)throw new RuntimeException('Wrong provider price');
    if($payload['mode']!=='payment'||$payload['client_reference_id']!==$id)throw new RuntimeException('Missing provider identity');
    return ['id'=>'cs_test_'.str_repeat('s',20),'url'=>'https://checkout.stripe.com/c/pay/test-fixture'];
};
$token=str_repeat('1',32);
$checkout=az_stripe_checkout_start(['productId'=>$pid,'quantity'=>2,'clientToken'=>$token,
    'channel'=>'video','videoId'=>$video,'promotionId'=>$promo]);
$ok(str_starts_with($checkout['checkoutUrl'],'https://checkout.stripe.com/'),'Stripe hosted URL');
$retry=az_stripe_checkout_start(['productId'=>$pid,'quantity'=>2,'clientToken'=>$token,'channel'=>'video','videoId'=>$video,'promotionId'=>$promo]);
$ok($attempts===1&&$retry['checkoutUrl']===$checkout['checkoutUrl'],'client replay returns original Stripe session');
$err(static fn()=>az_stripe_checkout_start(['productId'=>$pid,'quantity'=>1,'clientToken'=>$token,'channel'=>'shop']),'token_reused');
$attempt=$db->query("SELECT * FROM commerce_checkout_attempts LIMIT 1")->fetch();
$ok($attempt['unit_cents']==1250&&$attempt['amount_cents']==2500&&$attempt['commission_bps']==1200,'server saved money and attribution snapshot');
$now=time();$signature=static function(string $raw)use($now):string{
    return 't='.$now.',v1='.hash_hmac('sha256',$now.'.'.$raw,'whsec_fixture_signing_secret');
};
$event=static function(string $id,string $kind,array $obj):string{
    return json_encode(['id'=>$id,'object'=>'event','livemode'=>false,'type'=>$kind,
        'data'=>['object'=>$obj]],JSON_UNESCAPED_SLASHES);
};
$session=['id'=>$attempt['session_id'],'object'=>'checkout.session','livemode'=>false,'mode'=>'payment',
    'client_reference_id'=>$attempt['id'],'metadata'=>['azh_attempt_id'=>$attempt['id']],
    'amount_subtotal'=>2500,'amount_total'=>2700,'total_details'=>['amount_tax'=>200,'amount_shipping'=>0,'amount_discount'=>0],'currency'=>'usd','payment_status'=>'unpaid','payment_intent'=>'pi_fixture123456'];
$unpaid=$event('evt_pending12345','checkout.session.completed',$session);
az_stripe_webhook_receive($unpaid,$signature($unpaid));
$ok((int)$db->query('SELECT COUNT(*) FROM commerce_orders')->fetchColumn()===0,'no order from unpaid redirect or pending payment');
$invalid=$session;$invalid['amount_total']=2400;$invalid['payment_status']='paid';
$bad=$event('evt_mismatch12345','checkout.session.async_payment_succeeded',$invalid);
$err(static fn()=>az_stripe_webhook_receive($bad,$signature($bad)),'checkout_mismatch');
$paid=$session;$paid['payment_status']='paid';
$paidEvent=$event('evt_paid123456','checkout.session.async_payment_succeeded',$paid);
az_stripe_webhook_receive($paidEvent,$signature($paidEvent));
$ok((int)$db->query("SELECT COUNT(*) FROM commerce_orders WHERE provider='stripe' AND state='paid'")->fetchColumn()===1,'signed paid webhook creates order');
az_stripe_webhook_receive($paidEvent,$signature($paidEvent));
$ok((int)$db->query('SELECT COUNT(*) FROM commerce_orders')->fetchColumn()===1,'webhook duplicate idempotent');
$stored=$db->query("SELECT * FROM commerce_orders WHERE provider='stripe'")->fetch();
$ok((int)$stored['commission_cents']===300,'12 percent commission excludes 200 cents provider sales tax');
$ok((int)$stored['provider_total_cents']===2700&&(int)$stored['provider_tax_cents']===200,'signed provider tax and total reconciliation saved');
$ok(count(az_commerce_state($model)['orders'])===1&&count(az_commerce_state($other)['orders'])===0,'model account isolation');
$err(static fn()=>az_commerce_order_review($admin,['id'=>$stored['id'],'decision'=>'refunded','evidenceReference'=>'bogus-ref123']),'provider_controlled');
$dispute=['object'=>'dispute','payment_intent'=>'pi_fixture123456','status'=>'needs_response'];
$opened=$event('evt_dispute12345','charge.dispute.created',$dispute);
az_stripe_webhook_receive($opened,$signature($opened));
$ok($db->query('SELECT state FROM commerce_orders')->fetchColumn()==='disputed','signed dispute holds commission');
$won=$event('evt_disputewon12345','charge.dispute.closed',array_merge($dispute,['status'=>'won']));
az_stripe_webhook_receive($won,$signature($won));
$ok((int)$db->query('SELECT commission_cents FROM commerce_orders')->fetchColumn()===300,'provider-confirmed dispute win restores commission');
$refund=['object'=>'charge','payment_intent'=>'pi_fixture123456','amount'=>2500,'amount_refunded'=>500];
$partial=$event('evt_partial12345','charge.refunded',$refund);
az_stripe_webhook_receive($partial,$signature($partial));
$cur=$db->query('SELECT * FROM commerce_orders')->fetch();
$ok($cur['state']==='partially_refunded'&&(int)$cur['commission_cents']===240&&(int)$cur['refunded_cents']===500,'partial refund reverses commission proportionally');
$refund['amount_refunded']=2500;$full=$event('evt_fullrefund12345','charge.refunded',$refund);
az_stripe_webhook_receive($full,$signature($full));
$cur=$db->query('SELECT * FROM commerce_orders')->fetch();
$ok($cur['state']==='refunded'&&(int)$cur['commission_cents']===0,'full refund removes commission');
$rollback=$event('evt_latepart12345','charge.refunded',array_merge($refund,['amount_refunded'=>500]));
az_stripe_webhook_receive($rollback,$signature($rollback));
$ok((int)$db->query('SELECT refunded_cents FROM commerce_orders')->fetchColumn()===2500,'out-of-order refund cannot restore credit');
$report=az_commerce_analytics($model);
$ok($report['verified']['grossCents']===2500 && $report['verified']['netCents']===0 && $report['verified']['commissionCents']===0,'analytics respects verified full refund');
$ok($report['checkout']['started']===1 && $report['checkout']['paid']===1,'checkout conversion counts payment sessions');
$ok((int)$db->query("SELECT COUNT(*) FROM agent_workspace_events WHERE recipient_id=3 AND kind='commerce_stripe_paid' AND read_at IS NULL")->fetchColumn()===1,'verified payment unread in shared notifications');
$ok((int)$db->query("SELECT COUNT(*) FROM agent_chat_messages WHERE user_id=1 AND role='assistant'")->fetchColumn()>0,'creator notified in canonical Agent Chat');
putenv('AZH_STRIPE_SECRET_KEY');putenv('AZH_STRIPE_WEBHOOK_SECRET');
$ok(!az_stripe_config()['ready'],'missing secrets fail closed');
$ok(count(az_commerce_public_products())===0,'unconfigured Stripe listing hidden');
echo "Stripe checkout signed webhook + model commission integration: $pass passed\n";
