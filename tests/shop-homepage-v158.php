<?php
declare(strict_types=1);
/** JSON compatibility, responsive controls, curation and image-access regression. */
define('AZ_STORAGE',sys_get_temp_dir().'/azh-shop-v158-'.getmypid());
@mkdir(AZ_STORAGE,0700,true);
class AzHttpError extends RuntimeException {
    public function __construct(public readonly int $status,string $message,public readonly string $errorCode='request_failed'){parent::__construct($message);}
}
function az_is_installed():bool{return true;}
function az_prepare_storage():void{@mkdir(AZ_STORAGE,0700,true);}
function az_now():string{return gmdate('Y-m-d\TH:i:s\Z');}
function az_id():string{return bin2hex(random_bytes(16));}
function az_valid_id(string $id):bool{return preg_match('/\A[a-f0-9]{32}\z/D',$id)===1;}
function az_has_role(array $user,string $role):bool{return in_array($role,$user['roles']??[],true);}
function az_db():PDO{return $GLOBALS['v158_db'];}
function az_schema_version(PDO $db):int{return (int)$db->query('SELECT version FROM studio_schema WHERE id=1')->fetchColumn();}
require __DIR__.'/../includes/shop-catalog.php';
require __DIR__.'/../includes/shop-homepage.php';
function check158(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function body158(array $body):array{return get_object_vars(json_decode(json_encode($body,JSON_THROW_ON_ERROR),false,64,JSON_THROW_ON_ERROR));}
function reject158(callable $run,string $code,int $status=400):void{
    try{$run();throw new RuntimeException('Expected rejection: '.$code);}
    catch(AzHttpError $e){check158($e->status===$status&&$e->errorCode===$code,'Incorrect rejection: '.$e->errorCode);}
}

$db=new PDO('sqlite::memory:');$GLOBALS['v158_db']=$db;
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec("CREATE TABLE studio_schema(id INTEGER PRIMARY KEY,version INTEGER);INSERT INTO studio_schema VALUES(1,52);
CREATE TABLE commerce_products(id TEXT PRIMARY KEY,title TEXT,price_cents INTEGER,state TEXT);
CREATE TABLE commerce_product_catalog(product_id TEXT PRIMARY KEY,category TEXT,featured INTEGER);
CREATE TABLE commerce_product_images(id TEXT PRIMARY KEY,product_id TEXT,sort_order INTEGER)");
az_upgrade_shop_homepage_schema($db);
$admin=['id'=>1,'status'=>'active','roles'=>['admin']];$fan=['id'=>2,'status'=>'active','roles'=>['fan']];
$product=str_repeat('1',32);$privateProduct=str_repeat('2',32);
$db->prepare('INSERT INTO commerce_products VALUES(?,?,?,?)')->execute([$product,'First selected product',2900,'active']);
$db->prepare('INSERT INTO commerce_products VALUES(?,?,?,?)')->execute([$privateProduct,'Private archived product',3200,'archived']);
// The editor picker must not silently lose products older than the first page.
for($i=3;$i<63;$i++)$db->prepare('INSERT INTO commerce_products VALUES(?,?,?,?)')->execute([sprintf('%032x',$i),'Product '.$i,2000,'active']);
$db->prepare('INSERT INTO commerce_product_catalog VALUES(?,?,?)')->execute([$product,'fitness',1]);
$imageIds=[str_repeat('a',32),str_repeat('b',32),str_repeat('c',32)];
foreach($imageIds as $id)$db->prepare('INSERT INTO shop_homepage_images VALUES(?,?,?,?,?,?,?)')
    ->execute([$id,$id.'.webp','image/webp','Test uploaded image',1200,800,az_now()]);

// Loading the previous release adds controls but preserves deliberate choices.
$legacy=az_shop_homepage_default();
foreach(['heroFocalX','heroFocalY','heroTextPosition','mobileHeroImage','mobileHeroFocalX','mobileHeroFocalY',
    'mobileHeroHeight','mobileHeadingSize','mobileHeroAlignment','mobileHeroTextPosition','mobileOverlayStrength',
    'categoryCards','categoryOrder','showEmptyCategories','featuredMode','featuredProductIds','featuredCount'] as $key)unset($legacy[$key]);
$legacy['sections']['hero']=false;$legacy['categories']['candy']=false;$legacy['heroPosition']='right';
$db->prepare('UPDATE shop_homepage_settings SET draft_json=?,published_json=? WHERE id=1')
    ->execute([json_encode($legacy),json_encode($legacy)]);
$state=az_shop_homepage_admin_state($admin,$db);
check158(!$state['draft']['sections']['hero']&&!$state['draft']['categories']['candy'],'Upgrade re-enabled hidden content.');
check158($state['draft']['heroFocalX']===100&&$state['draft']['mobileHeroImage']==='inherit','Legacy image crop was not preserved.');
check158(count(array_filter($state['products'],static fn($p)=>!$p['demo']))===61,'Picker truncated active products.');
check158(!in_array($privateProduct,array_column($state['products'],'id'),true),'Picker leaked an inactive product.');
check158(count($state['categoryImages'])===count(az_shop_categories()),'Default collection thumbnails missing.');
check158(az_schema_version($db)===53,'The JSON extension must not require a database migration.');

$design=$state['draft'];$design['sections']['hero']=true;
$design['heroFocalX']=22;$design['heroFocalY']=60;$design['heroTextPosition']='bottom';$design['heroAlignment']='right';
$design['mobileHeroImage']=$imageIds[0];$design['mobileHeroFocalX']=72;$design['mobileHeroFocalY']=35;
$design['mobileHeroHeight']=620;$design['mobileHeadingSize']=38;$design['mobileHeroAlignment']='center';
$design['mobileHeroTextPosition']='top';$design['mobileOverlayStrength']=15;
$design['categoryCards']['fitness']=['title'=>'Move & Thrive','image'=>$imageIds[1]];
$design['categoryOrder']=array_reverse($design['categoryOrder']);$design['showEmptyCategories']=false;
$design['featuredMode']='manual';$design['featuredProductIds']=[$product,'demo-hoodie'];$design['featuredCount']=2;
$design['categories']=array_reverse($design['categories'],true); // JSON object member order is immaterial.
$body=body158(['revision'=>1,'action'=>'saveDraft','design'=>$design]);
$saved=az_shop_homepage_save($admin,$body,$db);
check158($saved['revision']===2,'Draft revision did not advance.');
$state=az_shop_homepage_admin_state($admin,$db);
foreach(['heroFocalX','heroFocalY','heroTextPosition','mobileHeroImage','mobileHeroFocalX','mobileHeroFocalY',
    'mobileHeroHeight','mobileHeadingSize','mobileHeroAlignment','mobileHeroTextPosition','mobileOverlayStrength',
    'categoryCards','categoryOrder','showEmptyCategories','featuredMode','featuredProductIds','featuredCount'] as $key)
    check158($state['draft'][$key]===$design[$key],'Draft lost '.$key);
check158($state['published']['featuredMode']==='automatic','Saving a draft changed public curation.');
foreach(array_slice($imageIds,0,2) as $id){
    reject158(static fn()=>az_shop_homepage_image_access($id,$fan,$db),'image_missing',404);
    reject158(static fn()=>az_shop_homepage_image_delete($admin,$id,$db),'image_in_use',409);
}
az_shop_homepage_save($admin,body158(['revision'=>2,'action'=>'publish','design'=>$design]),$db);
foreach(array_slice($imageIds,0,2) as $id)check158(az_shop_homepage_image_access($id,$fan,$db)['id']===$id,'Published mobile/category image not accessible.');
$live=az_shop_homepage_public($db);
check158($live['featuredProductIds']===[$product,'demo-hoodie']&&$live['categoryCards']['fitness']['title']==='Move & Thrive','Publish changed manual curation.');

$cases=[
    ['mobileHeroImage','https://example.invalid/photo.jpg','invalid_image'],
    ['heroFocalX',101,'invalid_layout'],['mobileHeroHeight',200,'invalid_layout'],
    ['mobileHeadingSize','38','invalid_layout'],['mobileHeroTextPosition','absolute','invalid_layout'],
    ['featuredCount',13,'invalid_layout'],['featuredMode','random-script','invalid_layout'],
    ['featuredProductIds',[$product,$product],'invalid_product'],
    ['featuredProductIds',[str_repeat('f',32)],'invalid_product'],
    ['featuredProductIds',['demo-missing'],'invalid_product'],
    ['featuredProductIds',[(object)['id'=>$product]],'invalid_product'],
    ['showEmptyCategories','false','invalid_visibility'],
    ['categoryOrder',array_fill(0,11,'apparel'),'invalid_order'],
    ['categoryOrder',[(object)['slug'=>'apparel']],'invalid_order'],
];
foreach($cases as [$key,$value,$code]){
    $bad=$design;$bad[$key]=$value;
    reject158(static fn()=>az_shop_homepage_save($admin,body158(['revision'=>3,'action'=>'saveDraft','design'=>$bad]),$db),$code);
}
foreach([
    ['title'=>'','image'=>'default'],['title'=>str_repeat('x',61),'image'=>'default'],
    ['title'=>'Fitness','image'=>'default','url'=>'https://evil.invalid'],
] as $card){
    $bad=$design;$bad['categoryCards']['fitness']=$card;
    reject158(static fn()=>az_shop_homepage_save($admin,body158(['revision'=>3,'action'=>'saveDraft','design'=>$bad]),$db),'invalid_category');
}
$bad=$design;$bad['categoryCards']['fitness']['image']='javascript:alert(1)';
reject158(static fn()=>az_shop_homepage_validate($bad,$db),'invalid_image');
foreach([[],new stdClass] as $invalidPosition){
    $bad=$design;unset($bad['heroFocalX']);$bad['heroPosition']=$invalidPosition;
    reject158(static fn()=>az_shop_homepage_validate($bad,$db),'invalid_layout');
}
reject158(static fn()=>az_shop_homepage_save($admin,body158(['revision'=>2,'action'=>'publish','design'=>$design]),$db),'revision_conflict',409);
check158(az_shop_homepage_state($db)['revision']===3,'Rejected edits mutated settings.');

// Removing saved references makes old uploads removable, including mobile/card refs.
$design['mobileHeroImage']='inherit';$design['categoryCards']['fitness']['image']='none';
az_shop_homepage_save($admin,body158(['revision'=>3,'action'=>'publish','design'=>$design]),$db);
foreach(array_slice($imageIds,0,2) as $id){
    reject158(static fn()=>az_shop_homepage_image_access($id,$fan,$db),'image_missing',404);
    check158(az_shop_homepage_image_delete($admin,$id,$db)['deleted'],'Unused mobile/category image could not be removed.');
}
foreach(glob(AZ_STORAGE.'/*')?:[] as $path)if(is_file($path))unlink($path);
@unlink(AZ_STORAGE.'/homepage-images/.htaccess');@rmdir(AZ_STORAGE.'/homepage-images');@rmdir(AZ_STORAGE);
echo "Shop 2.2 responsive settings, legacy upgrade, curation, JSON validation and image access: PASS\n";
