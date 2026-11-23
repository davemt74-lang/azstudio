<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
function must(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
try {az_admin_command_assert(['id'=>3,'status'=>'active','roles'=>['editor']]);throw new RuntimeException('Editor accessed private admin dashboard');}
catch(AzHttpError $e){must($e->status===403,'Incorrect access rejection');}
az_admin_command_assert(['id'=>1,'status'=>'active','roles'=>['admin']]);
$cards=az_admin_command_scorecards([
    'verified'=>['netCents'=>13999],'cash'=>['netCents'=>4500],'manual'=>['netCents'=>2200]
],['new'=>2],['pending'=>3],['playStarts30d'=>19],['verifiedNetCents30d'=>7500]);
must(count($cards)===6,'Expected six real-source KPI cards');
must($cards[0]['valueCents']===13999&&$cards[1]['valueCents']===4500,'Cash/Stripe improperly combined');
must($cards[2]['valueCents']===7500&&$cards[3]['value']===2&&$cards[4]['value']===3,'Attribution / resume / submission metrics mismatched');
$today=new DateTimeImmutable('2026-11-23T06:00:00Z');
$trend=az_admin_command_trend([
 ['day'=>'2026-11-22','profileViews'=>12,'videoPlays'=>5,'liveProductImpressions'=>3],
 ['day'=>'2026-11-18','profileViews'=>-5,'videoPlays'=>10,'liveProductImpressions'=>1]
],7,$today);
must(count($trend)===7&&$trend[5]['videoPlays']===5,'Video trend not tied to saved view data');
must($trend[0]['profileViews']===0,'Missing days must be zero, never sample fixture data');
must($trend[1]['profileViews']===0,'Negative statistics should not appear');
if(!in_array('sqlite',PDO::getAvailableDrivers(),true))throw new RuntimeException('PHP pdo_sqlite required');
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE studio_schema (id INTEGER PRIMARY KEY,version INTEGER NOT NULL)');
$db->exec('INSERT INTO studio_schema VALUES(1,43)');
az_upgrade_commerce_live_analytics_schema($db);
must(az_schema_version($db)===44,'Live shopping migration failed');
az_upgrade_commerce_live_analytics_schema($db); // rerunnable
$feature=['roomId'=>str_repeat('a',32),'id'=>str_repeat('b',32),'promotionId'=>str_repeat('c',32)];
az_live_shop_increment($db,$feature,'2026-11-23','view');
az_live_shop_increment($db,$feature,'2026-11-23','click');
az_live_shop_increment($db,$feature,'2026-11-23','click');
$q=$db->query('SELECT impressions,clicks FROM commerce_live_engagement_daily');$record=$q->fetch(PDO::FETCH_ASSOC);
must((int)$record['impressions']===1&&(int)$record['clicks']===2,'Live product counters did not aggregate properly');
$db->exec('CREATE TABLE commerce_checkout_attempts (id TEXT,channel TEXT,source_ref TEXT,session_id TEXT,state TEXT)');
$db->exec('CREATE TABLE commerce_orders (external_reference TEXT,provider TEXT,state TEXT,amount_cents INTEGER,refunded_cents INTEGER,commission_cents INTEGER,id TEXT)');
$db->exec('CREATE TABLE commerce_local_orders (order_id TEXT,channel TEXT,source_ref TEXT)');
$db->exec('CREATE TABLE commerce_live_features (room_id TEXT,product_id TEXT,promotion_id TEXT)');
$db->exec('CREATE TABLE commerce_products (id TEXT,title TEXT)');
$db->exec('CREATE TABLE commerce_promotions (id TEXT,model_id INTEGER,state TEXT)');
$db->exec('CREATE TABLE users (id INTEGER,name TEXT)');
$db->prepare('INSERT INTO commerce_checkout_attempts VALUES(?,?,?,?,?)')->execute([str_repeat('d',32),'live',$feature['roomId'],'cs_test','paid']);
$db->prepare('INSERT INTO commerce_orders VALUES(?,?,?,?,?,?,?)')->execute(['stripe:'.str_repeat('d',32),'stripe','paid',2400,500,240,str_repeat('e',32)]);
$db->prepare('INSERT INTO commerce_orders VALUES(?,?,?,?,?,?,?)')->execute(['cash:one','cash','paid',1900,0,190,str_repeat('f',32)]);
$db->prepare('INSERT INTO commerce_local_orders VALUES(?,?,?)')->execute([str_repeat('f',32),'live',$feature['roomId']]);
$db->prepare('INSERT INTO commerce_live_features VALUES(?,?,?)')->execute([$feature['roomId'],$feature['id'],$feature['promotionId']]);
$db->prepare('INSERT INTO commerce_products VALUES(?,?)')->execute([$feature['id'],'Featured Test Item']);
$db->prepare('INSERT INTO commerce_promotions VALUES(?,?,?)')->execute([$feature['promotionId'],22,'approved']);
$db->exec("INSERT INTO users VALUES(22,'Verified Creator')");
$GLOBALS['az_database']=$db;
$report=az_live_shop_pulse(['id'=>1,'status'=>'active','roles'=>['admin']],['id'=>$feature['roomId'],'title'=>'Test Live']);
must($report['onAir']&&$report['impressions']===1&&$report['clicks']===2,'Active live counters incorrect');
must($report['checkoutStarts']===1&&$report['paidCheckoutSessions']===1,'Live Stripe checkout attribution lost');
must($report['verifiedNetCents']===1900,'Refund not subtracted from Stripe verified net');
must($report['cashRecordedNetCents']===1900,'Cash not kept in separate recorded column');
must($report['verifiedCommissionCents']===240,'Creator commission report missing');
must($report['featuredCreator']==='Verified Creator','Approved creator attribution missing');
try{az_live_shop_pulse(['id'=>4,'status'=>'active','roles'=>['model']],['id'=>$feature['roomId']]);throw new RuntimeException('Creator accessed admin revenue');}
catch(AzHttpError $e){must($e->status===403,'Creator restriction failed');}
$db->exec('CREATE TABLE model_videos (id TEXT,status TEXT,title TEXT,submitted_at TEXT,user_id INTEGER)');
$db->exec("INSERT INTO model_videos VALUES('a','pending_review','Submitted video','2026-11-23',22)");
$db->exec("INSERT INTO model_videos VALUES('b','published','Published video','2026-11-20',22)");
$submissions=az_model_video_dashboard_summary();
must($submissions['pending']===1&&$submissions['published']===1,'pending_review must count as pending admin submissions');
must(count($submissions['recent'])===1,'Recent submitted videos missing');
print "Admin dashboard permissions, real-source KPI/trend, pending video count, schema 44, live attribution: PASS\n";
