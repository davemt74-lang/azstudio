<?php
declare(strict_types=1);
// CI PDO SQLite integration; no provider credentials or production data.
const AZ_STORAGE=__DIR__.'/.shop-homepage-test-tmp';
@mkdir(AZ_STORAGE,0700,true);
class AzHttpError extends RuntimeException {
    public function __construct(public readonly int $status, string $message, public readonly string $errorCode='request_failed'){parent::__construct($message);}
}
function az_is_installed():bool{return true;}
function az_prepare_storage():void{@mkdir(AZ_STORAGE,0700,true);}
function az_now():string{return gmdate('Y-m-d\TH:i:s\Z');}
function az_id():string{return bin2hex(random_bytes(16));}
function az_valid_id(string $id):bool{return (bool)preg_match('/\A[a-f0-9]{32}\z/D',$id);}
function az_has_role(array $user,string $role):bool{return in_array($role,$user['roles']??[],true);}
function az_shop_categories():array{return ['apparel'=>'Clothing','fitness'=>'Fitness','lifestyle'=>'Adult Lifestyle','digital'=>'Digital Entertainment',
    'candy'=>'Candy','photography'=>'Photography','spa'=>'Day Spa','accessories'=>'Accessories','bundles'=>'Bundles','gifts'=>'Gift Cards','other'=>'Other'];}
function az_shop_admin(array $user):void{if(($user['status']??'')!=='active'||!az_has_role($user,'admin'))throw new AzHttpError(403,'Admin only');}
function az_db():PDO{return $GLOBALS['shop_test_db'];}
function az_schema_version(PDO $db):int{return (int)$db->query('SELECT version FROM studio_schema WHERE id=1')->fetchColumn();}
require_once __DIR__.'/../includes/shop-homepage.php';
function ok(bool $pass,string $label):void{if(!$pass)throw new RuntimeException('Shop 2.1 test failed: '.$label);}
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){fwrite(STDERR,"PHP PDO SQLite required.\n");exit(2);}
$db=new PDO('sqlite::memory:');$GLOBALS['shop_test_db']=$db;$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE studio_schema (id INTEGER PRIMARY KEY,version INTEGER NOT NULL);INSERT INTO studio_schema VALUES(1,52)');
az_upgrade_shop_homepage_schema($db);ok(az_schema_version($db)===53,'schema 52→53');
az_upgrade_shop_homepage_schema($db);ok((int)$db->query('SELECT COUNT(*) FROM shop_homepage_settings')->fetchColumn()===1,'idempotent schema');
$admin=['id'=>1,'status'=>'active','roles'=>['admin']];$fan=['id'=>2,'status'=>'active','roles'=>['fan']];
$state=az_shop_homepage_admin_state($admin,$db);ok($state['revision']===1,'initial revision');
ok($state['published']['sampleEnabled']===false,'sample data off by default');
ok($state['published']['storeEnabled']===true,'shop on by default');
try{az_shop_homepage_admin_state($fan,$db);throw new RuntimeException('Unauthorized read succeeded');}catch(AzHttpError $e){ok($e->status===403,'role-scope read');}
try{az_shop_homepage_toggle($fan,['key'=>'sampleEnabled','enabled'=>true,'revision'=>1],$db);throw new RuntimeException('Unauthorized toggle succeeded');}catch(AzHttpError $e){ok($e->status===403,'role-scope write');}
$draft=$state['draft'];$draft['heading']='Alluring new collection';$draft['sections']['featured']=false;$draft['categories']['candy']=false;$draft['order']=array_reverse($draft['order']);
$saved=az_shop_homepage_save($admin,['revision'=>1,'action'=>'saveDraft','design'=>$draft],$db);
ok($saved['revision']===2,'save draft revision');$state=az_shop_homepage_admin_state($admin,$db);
ok($state['published']['heading']!==$draft['heading']&&$state['draft']['heading']===$draft['heading'],'draft not visible publicly');
ok($state['published']['sections']['featured']===true,'draft section hidden but public still shown');
try{az_shop_homepage_save($admin,['revision'=>1,'action'=>'publish','design'=>$draft],$db);throw new RuntimeException('Stale publish succeeded');}catch(AzHttpError $e){ok($e->status===409,'revision conflict');}
$invalid=$draft;$invalid['heroImage']='https://evil.invalid/evil.svg';
try{az_shop_homepage_save($admin,['revision'=>2,'action'=>'publish','design'=>$invalid],$db);throw new RuntimeException('External image accepted');}catch(AzHttpError $e){ok($e->status===400,'reject arbitrary image url');}
$invalid=$draft;$invalid['textColor']='red;background:url(http://evil.invalid)';
try{az_shop_homepage_save($admin,['revision'=>2,'action'=>'publish','design'=>$invalid],$db);throw new RuntimeException('CSS injection accepted');}catch(AzHttpError $e){ok($e->status===400,'color safety');}
$invalid=$draft;$invalid['ctaHref']='javascript:alert(1)';
try{az_shop_homepage_save($admin,['revision'=>2,'action'=>'publish','design'=>$invalid],$db);throw new RuntimeException('Script href accepted');}catch(AzHttpError $e){ok($e->status===400,'internal links only');}
$invalid=$draft;$invalid['sections']['hero']='off';
try{az_shop_homepage_save($admin,['revision'=>2,'action'=>'publish','design'=>$invalid],$db);throw new RuntimeException('String on/off accepted');}catch(AzHttpError $e){ok($e->status===400,'strict boolean categories');}
$invalid=$draft;$invalid['order']=['hero','hero'];
try{az_shop_homepage_save($admin,['revision'=>2,'action'=>'publish','design'=>$invalid],$db);throw new RuntimeException('Invalid reorder accepted');}catch(AzHttpError $e){ok($e->status===400,'reorder whitelist');}
$pub=az_shop_homepage_save($admin,['revision'=>2,'action'=>'publish','design'=>$draft],$db);ok($pub['published']&&$pub['revision']===3,'publish committed');
$live=az_shop_homepage_public($db);ok($live['heading']==='Alluring new collection'&&!$live['categories']['candy']&&!$live['sections']['featured'],'public reflects published section/category');
ok($live['order'][0]==='support','custom section order');
$demo=az_shop_homepage_demo_products();ok(count($demo)===8,'eight demo products');
foreach($demo as $item)ok(str_starts_with($item['id'],'demo-')&&isset($item['images'][0]['src'])&&isset($item['priceCents']),'demo not a saleable product ID');
$toggled=az_shop_homepage_toggle($admin,['key'=>'sampleEnabled','enabled'=>true,'revision'=>3],$db);
ok($toggled['revision']===4&&az_shop_homepage_public($db)['sampleEnabled'],'sample visible only after staff toggle');
$next=az_shop_homepage_toggle($admin,['key'=>'storeEnabled','enabled'=>false,'revision'=>4],$db);ok($next['revision']===5&&!az_shop_homepage_public($db)['storeEnabled'],'store off reversible');
$back=az_shop_homepage_toggle($admin,['key'=>'storeEnabled','enabled'=>true,'revision'=>5],$db);ok($back['revision']===6&&az_shop_homepage_public($db)['storeEnabled'],'store reenabled');
$id=str_repeat('a',32);$storage=$id.'.webp';
$db->prepare('INSERT INTO shop_homepage_images(id,storage_name,mime_type,alt_text,width,height,created_at) VALUES(?,?,?,?,?,?,?)')->execute([$id,$storage,'image/webp','Editorial photo',1200,480,az_now()]);
$state=az_shop_homepage_admin_state($admin,$db);$draft=$state['draft'];$draft['heroImage']=$id;
az_shop_homepage_save($admin,['revision'=>6,'action'=>'saveDraft','design'=>$draft],$db);
ok(az_shop_homepage_image_access($id,$admin,$db)['id']===$id,'admin sees draft image');
try{az_shop_homepage_image_access($id,$fan,$db);throw new RuntimeException('Draft image leaked');}catch(AzHttpError $e){ok($e->status===404,'unpublished image blocked');}
az_shop_homepage_save($admin,['revision'=>7,'action'=>'publish','design'=>$draft],$db);
ok(az_shop_homepage_image_access($id,$fan,$db)['id']===$id,'published image visible');
try{az_shop_homepage_image_delete($admin,$id,$db);throw new RuntimeException('Published image deleted');}catch(AzHttpError $e){ok($e->status===409,'published image deletion blocked');}
@unlink(AZ_STORAGE.'/schema-migration.lock');@rmdir(AZ_STORAGE);
echo "shop homepage v1.57 PDO SQLite: PASS\n";
