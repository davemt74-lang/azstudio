<?php
declare(strict_types=1);
// Standalone SQLite entitlement and product merchandising fixture.
const AZ_STORAGE=__DIR__.'/.shop-v156-test';
@mkdir(AZ_STORAGE,0700,true);
class AzHttpError extends RuntimeException {
    public function __construct(public readonly int $status,string $message,public readonly string $errorCode='request_failed') {parent::__construct($message);}
}
function az_db():PDO {return $GLOBALS['dbh'];}
function az_schema_version(PDO $db):int {return (int)$db->query('SELECT version FROM studio_schema WHERE id=1')->fetchColumn();}
function az_prepare_storage():void {@mkdir(AZ_STORAGE,0700,true);}
function az_now():string {return gmdate('Y-m-d\TH:i:s\Z');}
function az_valid_id(string $id):bool {return preg_match('/^[a-f0-9]{32}$/D',$id)===1;}
function az_id():string {return bin2hex(random_bytes(16));}
function az_has_role(array $user,string $role):bool {return in_array($role,$user['roles']??[],true);}
function az_commerce_active(array $product):bool {return $product['state']==='active';}
function az_commerce_row(string $id):array {
    $q=az_db()->prepare('SELECT * FROM commerce_products WHERE id=?');$q->execute([$id]);
    $row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new AzHttpError(404,'Missing product');return $row;
}
function az_commerce_public_products(?array $productIds=null):array {
    $q=az_db()->query("SELECT id,title,description,price_cents FROM commerce_products WHERE state='active' ORDER BY id");
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if($productIds!==null)$rows=array_values(array_filter($rows,static fn($row)=>in_array($row['id'],$productIds,true)));
    return array_map(static fn($row)=>['id'=>$row['id'],'title'=>$row['title'],
       'description'=>$row['description'],'priceCents'=>(int)$row['price_cents'],
       'fulfillment'=>['inStock'=>true],'stripeReady'=>false,'checkoutMode'=>'external',
       'checkoutUrl'=>'https://example.test/order','localEnabled'=>false],$rows);
}
require_once __DIR__.'/../includes/shop-catalog.php';
function check(bool $ok,string $name):void {if(!$ok)throw new RuntimeException('Shop fixture: '.$name);}
function error_code(callable $callback,int $status):void {
    try{$callback();throw new RuntimeException('Expected '.$status.' error');}
    catch(AzHttpError $e){check($e->status===$status,'correct rejection: '.$e->errorCode);}
}
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){fwrite(STDERR,"SQLite PDO required\n");exit(2);}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');$GLOBALS['dbh']=$db;
$db->exec("CREATE TABLE studio_schema(id INTEGER PRIMARY KEY,version INTEGER);INSERT INTO studio_schema VALUES(1,51);
CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT);INSERT INTO users VALUES(1,'active');
CREATE TABLE commerce_products(id CHAR(32) PRIMARY KEY,title TEXT,state TEXT,description TEXT,price_cents INTEGER,checkout_url TEXT,checkout_mode TEXT,currency TEXT,updated_at TEXT);
CREATE TABLE model_videos(id CHAR(32) PRIMARY KEY,user_id INTEGER,title TEXT,public_credit TEXT,status TEXT,revision INTEGER,rights_confirmed INTEGER,published_at TEXT);
CREATE TABLE commerce_promotions(id CHAR(32) PRIMARY KEY,product_id CHAR(32),model_id INTEGER,video_id CHAR(32),state TEXT,video_revision INTEGER,updated_at TEXT);");
$ids=[str_repeat('a',32),str_repeat('b',32),str_repeat('c',32),str_repeat('d',32)];
foreach($ids as $index=>$id){$state=$index===3?'archived':'active';$q=$db->prepare('INSERT INTO commerce_products VALUES(?,?,?,?,?,?,?,?,?)');$q->execute([$id,'Product '.$index,$state,'Visible description',1500+$index*100,'https://example.test/order','external','USD','2026-11-24T00:00:00Z']);}
$admin=['status'=>'active','roles'=>['admin'],'id'=>1];$fan=['status'=>'active','roles'=>['fan'],'id'=>2];
az_upgrade_shop_catalog_schema($db);check(az_schema_version($db)===52,'upgrade to schema 52');az_upgrade_shop_catalog_schema($db);check(az_schema_version($db)===52,'idempotent upgrade');
error_code(static fn()=>az_shop_catalog_state($fan),403);
error_code(static fn()=>az_shop_catalog_save($fan,['productId'=>$ids[0]]),403);
error_code(static fn()=>az_shop_catalog_save($admin,['productId'=>$ids[0],'category'=>'script','revision'=>0]),400);
error_code(static fn()=>az_shop_catalog_save($admin,['productId'=>$ids[0],'category'=>'apparel','components'=>[$ids[0]],'revision'=>0]),400);
error_code(static fn()=>az_shop_catalog_save($admin,['productId'=>$ids[0],'category'=>'apparel','components'=>[$ids[3]],'revision'=>0]),400);
error_code(static fn()=>az_shop_catalog_save($admin,['productId'=>$ids[0],'category'=>'apparel','components'=>[$ids[1],$ids[1]],'revision'=>0]),400);
error_code(static fn()=>az_shop_catalog_save($admin,['productId'=>$ids[0],'category'=>'apparel','components'=>[new stdClass],'revision'=>0]),400);
$save=az_shop_catalog_save($admin,['productId'=>$ids[0],'category'=>'apparel','tagline'=>'A studio-approved favorite',
    'featured'=>true,'components'=>[$ids[1],$ids[2]],'revision'=>0],$db);
check($save['revision']===1,'first save revision');
error_code(static fn()=>az_shop_catalog_save($admin,['productId'=>$ids[0],'category'=>'fitness','revision'=>0]),409);
$changed=az_shop_catalog_save($admin,['productId'=>$ids[0],'category'=>'apparel','featured'=>true,
    'components'=>[$ids[1]],'revision'=>1],$db);
check($changed['revision']===2,'revision increments');
// Approved creator attribution cannot include stale videos or private drafts.
$v=str_repeat('e',32);$pr=str_repeat('f',32);
$db->prepare('INSERT INTO model_videos VALUES(?,?,?,?,?,?,?,?)')->execute([$v,1,'Watch the approved video','Public model stage','published',2,1,'2026-11-24T00:00:00Z']);
$db->prepare('INSERT INTO commerce_promotions VALUES(?,?,?,?,?,?,?)')->execute([$pr,$ids[0],1,$v,'approved',2,'2026-11-24T00:00:00Z']);
$db->prepare('INSERT INTO commerce_product_images VALUES(?,?,?,?,?,?,?)')->execute([str_repeat('1',32),$ids[0],str_repeat('1',32).'.webp','image/webp','White studio product on table',0,az_now()]);
$db->prepare('INSERT INTO commerce_product_images VALUES(?,?,?,?,?,?,?)')->execute([str_repeat('2',32),$ids[3],str_repeat('2',32).'.png','image/png','Archived private photo',0,az_now()]);
$public=az_shop_public_products();
check(count($public)===3,'private inactive products omitted');
check($public[0]['id']===$ids[0]&&$public[0]['featured'],'featured placement');
check($public[0]['category']==='apparel'&&count($public[0]['images'])===1,'category and image');
check($public[0]['recommendation']['credit']==='Public model stage','approved public creator credit');
check(count($public[0]['bundleContents'])===1,'curated set with one active linked product');
$selectedBundle=az_shop_public_products([$ids[0]]);
check(count($selectedBundle)===1&&count($selectedBundle[0]['bundleContents'])===1,'Exact product lookup preserves eligible bundle contents.');
check($selectedBundle[0]['bundleContents'][0]['id']===$ids[1],'Bundle lookup resolves a component outside the selected ID list.');
error_code(static fn()=>az_shop_image_access(str_repeat('2',32),$fan),404);
check(az_shop_image_access(str_repeat('1',32),$fan)['product_id']===$ids[0],'public active media allowed');
check(az_shop_image_access(str_repeat('2',32),$admin)['product_id']===$ids[3],'staff draft image preview');
$db->prepare('UPDATE model_videos SET status=? WHERE id=?')->execute(['draft',$v]);
check(az_shop_public_products()[0]['recommendation']===null,'withdrawal removes creator recommendation');
$db->prepare('UPDATE commerce_products SET state=? WHERE id=?')->execute(['archived',$ids[0]]);
error_code(static fn()=>az_shop_image_access(str_repeat('1',32),$fan),404);
error_code(static fn()=>az_shop_image_input_check(['tmp_name'=>'/tmp/nonupload','size'=>300,'error'=>UPLOAD_ERR_OK]),400);
$state=az_shop_catalog_state($admin);check(count($state['products'])===4,'admin can manage all product states');
@unlink(AZ_STORAGE.'/schema-migration.lock');@rmdir(AZ_STORAGE);
echo "Shop v1.56 SQLite security, merchandising and migrations PASS\n";

