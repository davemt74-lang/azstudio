<?php
declare(strict_types=1);
// Standalone production-SQL integration: CI supplies pdo_sqlite, no private credentials.
const AZ_STORAGE = __DIR__ . '/.creator-analytics-tmp';
@mkdir(AZ_STORAGE, 0700, true);
final class AzHttpError extends RuntimeException {
    public function __construct(public readonly int $status, string $message, public readonly string $errorCode='request_failed') { parent::__construct($message); }
}
function az_has_role(array $user, string $role): bool { return in_array($role,$user['roles']??[],true); }
function az_valid_id(string $id): bool { return (bool)preg_match('/^[a-f0-9]{32}$/D',$id); }
function az_schema_version(PDO $db): int { return (int)$db->query('SELECT version FROM studio_schema WHERE id=1')->fetchColumn(); }
function az_prepare_storage(): void { @mkdir(AZ_STORAGE, 0700, true); }
function az_public_video_conditions(): string {return "v.fan_published_at IS NOT NULL AND v.archived_at IS NULL AND i.status='active' AND u.status='active'";}
function az_model_video_public_row(string $id): array {
    $q=az_db()->prepare("SELECT * FROM model_videos WHERE id=? AND status='published' AND rights_confirmed=1");$q->execute([$id]);
    $r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)throw new AzHttpError(404,'Not found');return $r;
}
function az_commerce_video_products(string $id): array {
    if($id!==str_repeat('a',32))return [];
    return [['id'=>str_repeat('d',32),'promotionId'=>str_repeat('e',32)]];
}
function az_db(): PDO {return $GLOBALS['db'];}
require_once __DIR__.'/../includes/creator-analytics.php';
function check(bool $ok,string $msg):void {if(!$ok)throw new RuntimeException('FAIL: '.$msg);}
function insert(PDO $db,string $table,array $row):void {
    $fields=array_keys($row);$sql='INSERT INTO '.$table.'('.implode(',',$fields).') VALUES('.implode(',',array_fill(0,count($fields),'?')).')';
    $db->prepare($sql)->execute(array_values($row));
}
$db=new PDO('sqlite::memory:');$GLOBALS['db']=$db;$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach([
 'CREATE TABLE studio_schema(id INTEGER PRIMARY KEY,version INTEGER)',
 'CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,status TEXT)',
 'CREATE TABLE user_roles(user_id INTEGER,role TEXT)',
 'CREATE TABLE model_videos(id TEXT PRIMARY KEY,user_id INTEGER,title TEXT,status TEXT,rights_confirmed INTEGER,published_at TEXT,submitted_at TEXT,reviewed_at TEXT)',
 'CREATE TABLE finished_videos(id TEXT PRIMARY KEY,interview_id TEXT,owner_id INTEGER,model_id TEXT,fan_title TEXT,fan_published_at TEXT,archived_at TEXT)',
 'CREATE TABLE interviews(id TEXT PRIMARY KEY,status TEXT)',
 'CREATE TABLE model_profiles(id TEXT PRIMARY KEY,status TEXT,linked_user_id INTEGER)',
 'CREATE TABLE published_video_engagement_daily(video_kind TEXT,video_id TEXT,view_day TEXT,play_starts INTEGER,player_completions INTEGER,watched_seconds INTEGER)',
 'CREATE TABLE commerce_checkout_attempts(id TEXT PRIMARY KEY,promotion_id TEXT,source_ref TEXT,channel TEXT,session_id TEXT,created_at TEXT)',
 'CREATE TABLE commerce_promotions(id TEXT PRIMARY KEY,video_id TEXT,model_id INTEGER)',
 'CREATE TABLE commerce_orders(id TEXT,provider TEXT,promotion_id TEXT,external_reference TEXT,paid_at TEXT,amount_cents INTEGER,refunded_cents INTEGER,commission_cents INTEGER,state TEXT)',
 'CREATE TABLE commerce_local_orders(order_id TEXT,channel TEXT,source_ref TEXT)'
] as $sql)$db->exec($sql);
insert($db,'studio_schema',['id'=>1,'version'=>47]);
az_upgrade_creator_analytics_schema($db);
check(az_schema_version($db)===48,'migration 47->48');
az_upgrade_creator_analytics_schema($db);
check(az_schema_version($db)===48,'migration idempotent');
$video1=str_repeat('a',32);$video2=str_repeat('b',32);$private=str_repeat('c',32);
$prod=str_repeat('f',32);$promo=str_repeat('e',32);$prodId=str_repeat('1',32);
foreach([[1,'Creator One','active'],[2,'Creator Two','active'],[3,'Studio Admin','active']] as [$id,$name,$status])insert($db,'users',compact('id','name','status'));
foreach([[1,'model'],[2,'model'],[3,'admin']] as [$user_id,$role])insert($db,'user_roles',compact('user_id','role'));
foreach([[$video1,1,'Creator One video','published',1,'2026-11-01T12:00:00Z','2026-11-20T12:00:00Z','2026-11-21T12:00:00Z'],[$video2,2,'Creator Two video','published',1,'2026-11-01T12:00:00Z',null,null],[$private,1,'PRIVATE TITLE','draft',0,null,null,null]] as [$id,$user_id,$title,$status,$rights_confirmed,$published_at,$submitted_at,$reviewed_at])insert($db,'model_videos',compact('id','user_id','title','status','rights_confirmed','published_at','submitted_at','reviewed_at'));
insert($db,'interviews',['id'=>$prodId,'status'=>'active']);
insert($db,'model_profiles',['id'=>$prodId,'status'=>'active','linked_user_id'=>1]);
insert($db,'finished_videos',['id'=>$prod,'interview_id'=>$prodId,'owner_id'=>3,'model_id'=>$prodId,'fan_title'=>'Public production','fan_published_at'=>'2026-11-01T00:00:00Z','archived_at'=>null]);
foreach([['creator',$video1,'2026-11-21',11,6,120],['creator',$video2,'2026-11-21',9,3,70],['production',$prod,'2026-11-21',6,3,45],['creator',$video1,'2026-11-14',5,2,60],['creator',$private,'2026-11-21',500,400,20000]] as [$video_kind,$video_id,$view_day,$play_starts,$player_completions,$watched_seconds])insert($db,'published_video_engagement_daily',compact('video_kind','video_id','view_day','play_starts','player_completions','watched_seconds'));
insert($db,'commerce_video_engagement_daily',['video_id'=>$video1,'product_id'=>str_repeat('d',32),'promotion_id'=>$promo,'view_day'=>'2026-11-21','clicks'=>4]);
insert($db,'commerce_promotions',['id'=>$promo,'video_id'=>$video1,'model_id'=>1]);
insert($db,'commerce_checkout_attempts',['id'=>str_repeat('2',32),'promotion_id'=>$promo,'source_ref'=>$video1,'channel'=>'video','session_id'=>'cs_test_1','created_at'=>'2026-11-21T13:00:00Z']);
insert($db,'commerce_orders',['id'=>str_repeat('3',32),'provider'=>'stripe','promotion_id'=>$promo,'external_reference'=>'stripe:'.str_repeat('2',32),'paid_at'=>'2026-11-22T13:00:00Z','amount_cents'=>5000,'refunded_cents'=>1000,'commission_cents'=>500,'state'=>'partially_refunded']);
insert($db,'commerce_orders',['id'=>str_repeat('4',32),'provider'=>'cash','promotion_id'=>$promo,'external_reference'=>'cash:test','paid_at'=>'2026-11-22T13:00:00Z','amount_cents'=>1800,'refunded_cents'=>0,'commission_cents'=>180,'state'=>'paid']);
insert($db,'commerce_local_orders',['order_id'=>str_repeat('4',32),'channel'=>'video','source_ref'=>$video1]);
$now=new DateTimeImmutable('2026-11-23T12:00:00Z');
$admin=['id'=>3,'status'=>'active','roles'=>['admin']];$model1=['id'=>1,'status'=>'active','roles'=>['model']];$model2=['id'=>2,'status'=>'active','roles'=>['model']];
$all=az_creator_analytics_report($admin,7,'all',null,$db,$now);
check(count($all['videos'])===3,'all public videos included');
check($all['current']['starts']===26,'public creator and production play starts');
check($all['previous']['starts']===5,'previous equal period');
check($all['current']['clicks']===4,'CTA taps');
check($all['current']['checkoutStarts']===1,'Stripe Checkout starts');
check($all['current']['verifiedNetCents']===4000,'verified Stripe net deducts refunds');
check($all['current']['cashNetCents']===1800,'cash separately recorded');
check($all['current']['commissionCents']===500,'estimated Stripe commissions');
check($all['reviews']['approved']===1 && $all['reviews']['reviewed']===1,'review snapshot');
check($all['reviews']['avgReviewHours']===24.0,'review turnaround');
$mine=az_creator_analytics_report($model1,7,'all',2,$db,$now);
check(count($mine['videos'])===2 && $mine['current']['starts']===17,'model role cannot change scope with model filter');
check($mine['current']['verifiedNetCents']===4000,'model sees only own attributed Stripe');
$theirs=az_creator_analytics_report($model2,7,'all',null,$db,$now);
check(count($theirs['videos'])===1 && $theirs['current']['verifiedNetCents']===0,'other model isolation');
$creatorOnly=az_creator_analytics_report($admin,7,'creator',1,$db,$now);
check($creatorOnly['current']['starts']===11 && count($creatorOnly['videos'])===1,'type and admin model filter');
try {az_creator_analytics_report(['id'=>4,'status'=>'active','roles'=>['fan']],7,'all',null,$db,$now);throw new RuntimeException('Fan unexpectedly authorized');}
catch (AzHttpError $e) {check($e->status===403,'fan blocked');}
try {az_creator_analytics_report($model1,31,'all',null,$db,$now);throw new RuntimeException('Invalid filter authorized');}
catch (AzHttpError $e) {check($e->status===400,'invalid period blocked');}
$all['videos'][0]['title']='=HYPERLINK("https://example.com","x")';
check(str_contains(az_creator_analytics_csv($all),"'=HYPERLINK"),'CSV spreadsheet formula neutralized');
$_SESSION=[];$_SERVER['HTTP_USER_AGENT']='Mozilla/5.0';
check(az_creator_video_click(null,$video1,str_repeat('d',32),$promo),'first valid CTA tap');
check(!az_creator_video_click(null,$video1,str_repeat('d',32),$promo),'session dedupe');
check((int)$db->query("SELECT SUM(clicks) FROM commerce_video_engagement_daily WHERE video_id='$video1'")->fetchColumn()===5,'click aggregate increments once');
try{az_creator_video_click(null,$video1,str_repeat('d',32),str_repeat('9',32));throw new RuntimeException('Forged promotion accepted');}
catch(AzHttpError $e){check($e->status===404,'forged promotion rejected');}
$_SESSION=[];check(!az_creator_video_click($model1,$video1,str_repeat('d',32),$promo),'self-click excluded');
$_SESSION=[];$_SERVER['HTTP_USER_AGENT']='SearchBot';check(!az_creator_video_click(null,$video1,str_repeat('d',32),$promo),'robot excluded');
@unlink(AZ_STORAGE.'/schema-migration.lock');@rmdir(AZ_STORAGE);
echo "creator analytics v1.50: PASS\n";
