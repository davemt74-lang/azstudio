<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
if (!in_array('sqlite',PDO::getAvailableDrivers(),true)) {fwrite(STDERR,"SQLite PDO unavailable\n");exit(2);}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('PRAGMA foreign_keys=ON');
$GLOBALS['az_database']=$db;
$db->exec("CREATE TABLE studio_schema(id INTEGER PRIMARY KEY,version INTEGER);
INSERT INTO studio_schema VALUES(1,35);
CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,email TEXT,status TEXT,revision INTEGER,created_at TEXT,last_login_at TEXT);
CREATE TABLE user_roles(user_id INTEGER,role TEXT,PRIMARY KEY(user_id,role));
CREATE TABLE user_role_requests(user_id INTEGER,role TEXT,status TEXT);
CREATE TABLE personal_media(id TEXT PRIMARY KEY,user_id INTEGER,duration_ms INTEGER,mime_type TEXT,size_bytes INTEGER,file_name TEXT,storage_name TEXT,cover_storage_name TEXT,cover_mime_type TEXT,revision INTEGER);
CREATE TABLE model_videos(id TEXT PRIMARY KEY,user_id INTEGER,media_id TEXT,title TEXT,description TEXT,status TEXT,review_note TEXT,rights_confirmed INTEGER,revision INTEGER,created_at TEXT,updated_at TEXT);
CREATE TABLE az_live_rooms(id TEXT PRIMARY KEY,owner_id INTEGER,status TEXT,heartbeat_epoch INTEGER,started_epoch INTEGER);
CREATE TABLE agent_workspace_events(id TEXT PRIMARY KEY,recipient_id INTEGER,actor_id INTEGER,kind TEXT,title TEXT,detail TEXT,href TEXT,created_at TEXT,read_at TEXT);
CREATE TABLE agent_chat_threads(id TEXT PRIMARY KEY,user_id INTEGER,title TEXT,created_at TEXT,updated_at TEXT);
CREATE TABLE agent_chat_messages(id TEXT PRIMARY KEY,thread_id TEXT,user_id INTEGER,position INTEGER,role TEXT,content TEXT,client_token TEXT,state TEXT,created_at TEXT,updated_at TEXT,UNIQUE(thread_id,position));
INSERT INTO users VALUES
(1,'Model One','one@example.invalid','active',1,'now',NULL),
(2,'Model Two','two@example.invalid','active',1,'now',NULL),
(3,'Administrator','admin@example.invalid','active',1,'now',NULL);
INSERT INTO user_roles VALUES(1,'model'),(2,'model'),(3,'admin');");
$admin=['id'=>3,'status'=>'active','roles'=>['admin']];
$model=['id'=>1,'status'=>'active','roles'=>['model']];
$other=['id'=>2,'status'=>'active','roles'=>['model']];
$pass=0;
$check=static function(bool $value,string $label)use(&$pass):void{if(!$value)throw new RuntimeException('Commerce integration FAIL: '.$label);$pass++;};
$expect=static function(callable $fn,string $code)use($check):void{
    try{$fn();$check(false,'expected error '.$code);}catch(AzHttpError $e){$check($e->errorCode===$code,'expected '.$code.' got '.$e->errorCode);}
};
az_upgrade_commerce_schema($db);
$check(az_schema_version($db)===36,'migration 35 to 36');
az_upgrade_commerce_schema($db);
$check(az_schema_version($db)===36,'migration replay safe');
az_upgrade_commerce_stripe_schema($db);$check(az_schema_version($db)===37,'checkout migration from 36');
az_upgrade_commerce_stripe_schema($db);$check(az_schema_version($db)===37,'checkout migration replay safe');
az_upgrade_commerce_local_schema($db);$check(az_schema_version($db)===38,'per-product cash schema migrated');
az_upgrade_commerce_local_schema($db);$check(az_schema_version($db)===38,'per-product cash schema replay safe');
az_upgrade_commerce_fulfillment_schema($db);$check(az_schema_version($db)===39,'inventory/shipping migration');
az_upgrade_commerce_carrier_schema($db);$check(az_schema_version($db)===40,'carrier migration v40');
az_upgrade_commerce_customer_shipping_schema($db);$check(az_schema_version($db)===41,'customer carrier quote schema v41');
az_upgrade_commerce_settlement_schema($db);$check(az_schema_version($db)===42,'return and settlement schema v42');
az_upgrade_commerce_fulfillment_schema($db);$check(az_schema_version($db)===42,'old migration replay preserves v42');
$expect(static fn()=>az_commerce_product_save($model,['title'=>'Unauthorized','priceCents'=>50]),'role_required');
$expect(static fn()=>az_commerce_url('http://example.com/checkout'),'invalid_checkout_url');
$created=az_commerce_product_save($admin,['title'=>'Featured sample','description'=>'A model-approved product',
  'priceCents'=>1250,'commissionBps'=>1000,'checkoutUrl'=>'https://seller.example/secure','state'=>'active']);
$pid=$created['id'];
$check(az_valid_id($pid),'product saved');
$check(count(az_commerce_public_products())===1,'active checkout product publicly listed');
$brief=az_commerce_brief_save($admin,['productId'=>$pid,'title'=>'Creator spotlight','instructions'=>'Describe the item in your own style.','state'=>'active']);
$check($brief['state']==='active','brief created');
$check((int)$db->query("SELECT COUNT(*) FROM agent_workspace_events WHERE recipient_id=1 AND kind='commerce_brief_open'")->fetchColumn()===1,'model notified in existing History');
$check((int)$db->query("SELECT COUNT(*) FROM agent_chat_messages WHERE user_id=1 AND role='assistant'")->fetchColumn()>=1,'model proactive Agent Chat thread');
$check(count(az_commerce_state($model)['products'])===1,'model can see active brief');
$interest=az_commerce_interest_action($model,['productId'=>$pid,'decision'=>'interested']);
$check($interest['state']==='interested','model expresses interest');
$check(count(az_commerce_state($other)['orders'])===0,'other model cannot see orders');
$approved=az_commerce_interest_review($admin,['productId'=>$pid,'modelId'=>1,'decision'=>'approved']);
$check($approved['state']==='approved','admin explicitly approves creator');
$media=str_repeat('c',32);$vid=str_repeat('a',32);
$db->prepare('INSERT INTO personal_media VALUES(?,?,?,?,?,?,?,?,?,?)')
    ->execute([$media,1,60000,'video/mp4',50,'clip.mp4','asset.bin',null,null,1]);
$db->prepare("INSERT INTO model_videos VALUES(?,?,?,?,?,'published','',1,1,?,?)")
    ->execute([$vid,1,$media,'My published video','A creator video',az_now(),az_now()]);
$promo=az_commerce_promotion_request($model,['productId'=>$pid,'videoId'=>$vid]);
$check($promo['state']==='pending','creator video awaits admin review');
$promoApproved=az_commerce_promotion_review($admin,['id'=>$promo['id'],'decision'=>'approved']);
$check($promoApproved['state']==='approved','admin approves verified video');
$order=az_commerce_order_record($admin,['productId'=>$pid,'externalReference'=>'provider-order-001','promotionId'=>$promo['id'],'quantity'=>2]);
$check($order['state']==='recorded','external order recorded but unpaid');
$expect(static fn()=>az_commerce_order_record($admin,['productId'=>$pid,'externalReference'=>'provider-order-001','promotionId'=>$promo['id'],'quantity'=>2]),'duplicate_order');
$paid=az_commerce_order_review($admin,['id'=>$order['id'],'decision'=>'paid','evidenceReference'=>'processor-paid-001']);
$check($paid['commissionCents']===250,'commission snapshot = 10 percent of 2500 cents');
$me=az_commerce_state($model);
$check(count($me['orders'])===1 && $me['orders'][0]['state']==='paid','own attributed paid order visible');
$check(!array_key_exists('external_reference',$me['orders'][0]),'provider order reference hidden from model');
$check(count(az_commerce_state($other)['orders'])===0,'other model still cannot see attribution');
$refund=az_commerce_order_review($admin,['id'=>$order['id'],'decision'=>'refunded','evidenceReference'=>'processor-refund-001']);
$check($refund['state']==='refunded','recorded refund reverses commission availability');
$expect(static fn()=>az_commerce_order_review($admin,['id'=>$order['id'],'decision'=>'paid','evidenceReference'=>'processor-paid-001']),'revision_conflict');
$before=(int)$db->query('SELECT COUNT(*) FROM agent_chat_messages')->fetchColumn();
az_commerce_dispatch();az_commerce_dispatch();
$check((int)$db->query('SELECT COUNT(*) FROM agent_chat_messages')->fetchColumn()===$before,'no duplicate proactive chat after dispatch replay');
$room=str_repeat('b',32);
$db->prepare("INSERT INTO az_live_rooms VALUES(?,3,'live',?,?)")->execute([$room,time(),time()]);
$selected=az_commerce_live_select($admin,['productId'=>$pid]);
$check($selected['roomId']===$room,'admin selects product in real active live session');
$public=az_commerce_live_feature();
$check($public!==null&&$public['id']===$pid,'live public selection is linked to current room');
$db->prepare("UPDATE az_live_rooms SET status='ended' WHERE id=?")->execute([$room]);
$check(az_commerce_live_feature()===null,'ended live session reveals no featured product');
$db->prepare("UPDATE model_videos SET status='draft' WHERE id=?")->execute([$vid]);
$expect(static fn()=>az_commerce_promotion_request($model,['productId'=>$pid,'videoId'=>$vid]),'promotion_ineligible');
echo "Commerce & Creator Agent PDO/SQLite integration: $pass passed\n";
