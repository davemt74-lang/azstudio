<?php
declare(strict_types=1);
/** Durable history, atomic writes and restoration checks in an isolated SQLite database. */
define('AZ_STORAGE',sys_get_temp_dir().'/azh-shop-v159-'.getmypid());
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
function az_db():PDO{return $GLOBALS['v159_db'];}
function az_schema_version(PDO $db):int{return (int)$db->query('SELECT version FROM studio_schema WHERE id=1')->fetchColumn();}
require __DIR__.'/../includes/shop-catalog.php';
require __DIR__.'/../includes/shop-homepage.php';
function check159(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function reject159(callable $run,string $code,int $status=400):void{
    try{$run();throw new RuntimeException('Expected rejection: '.$code);}
    catch(AzHttpError $e){check159($e->status===$status&&$e->errorCode===$code,'Incorrect rejection: '.$e->errorCode);}
}
function version159(PDO $db,string $heading):array{
    foreach(az_shop_homepage_history_list($GLOBALS['v159_admin'],$db) as $row)if($row['heading']===$heading)return $row;
    throw new RuntimeException('Saved version missing: '.$heading);
}
$db=new PDO('sqlite::memory:');$GLOBALS['v159_db']=$db;
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec("CREATE TABLE studio_schema(id INTEGER PRIMARY KEY,version INTEGER);INSERT INTO studio_schema VALUES(1,52);
CREATE TABLE commerce_products(id TEXT PRIMARY KEY,title TEXT,price_cents INTEGER,state TEXT);
CREATE TABLE commerce_product_catalog(product_id TEXT PRIMARY KEY,category TEXT,featured INTEGER);
CREATE TABLE commerce_product_images(id TEXT PRIMARY KEY,product_id TEXT,sort_order INTEGER)");
$admin=['id'=>1,'name'=>'Design editor','status'=>'active','roles'=>['admin']];$GLOBALS['v159_admin']=$admin;
$fan=['id'=>2,'status'=>'active','roles'=>['fan']];
az_upgrade_shop_homepage_schema($db);
$original=az_shop_homepage_default();$original['heading']='Existing published shop';
$private=$original;$private['heading']='Existing private draft';$private['sections']['hero']=false;
$image=str_repeat('a',32);
$db->prepare('INSERT INTO shop_homepage_images VALUES(?,?,?,?,?,?,?)')->execute([$image,$image.'.webp','image/webp','Retained image',1200,800,az_now()]);
$private['heroImage']=$image;
$db->prepare('UPDATE shop_homepage_settings SET published_json=?,draft_json=? WHERE id=1')->execute([json_encode($original),json_encode($private)]);
az_upgrade_shop_homepage_history_schema($db);
check159(az_schema_version($db)===54,'Homepage history upgrade did not advance to 54.');
az_upgrade_shop_homepage_history_schema($db);
$history=az_shop_homepage_history_list($admin,$db);
check159(count($history)===2,'Upgrade did not preserve the distinct published and draft layouts exactly once.');
check159(count($db->query('SELECT * FROM shop_homepage_history WHERE actor_id IS NULL')->fetchAll())===2,'Upgrade snapshots falsely attribute an author.');
$initialDraft=version159($db,'Existing private draft');
$initialPublished=version159($db,'Existing published shop');
check159($initialDraft['scope']==='draft'&&$initialPublished['scope']==='published','Upgrade scopes changed.');
reject159(static fn()=>az_shop_homepage_history_list($fan,$db),'role_required',403);
reject159(static fn()=>az_shop_homepage_history_restore($fan,['id'=>$initialDraft['id'],'revision'=>1],$db),'role_required',403);
reject159(static fn()=>az_shop_homepage_history_delete($fan,['id'=>$initialDraft['id'],'revision'=>1],$db),'role_required',403);

$draft=$original;$draft['heading']='Next private design';
az_shop_homepage_save($admin,['revision'=>1,'action'=>'saveDraft','design'=>$draft],$db);
$saved=version159($db,'Next private design');
check159($saved['revision']===2&&$saved['actor']==='Design editor'&&$saved['action']==='saveDraft','Saved history metadata is wrong.');
check159(az_shop_homepage_public($db)===$original,'Saving a draft changed the public shop.');
// The old uploaded image is absent from both current designs but remains restorable.
reject159(static fn()=>az_shop_homepage_image_delete($admin,$image,$db),'image_in_history',409);
reject159(static fn()=>az_shop_homepage_image_access($image,$fan,$db),'image_missing',404);

// A failed history insert must roll back the matching draft write and revision.
$db->exec("CREATE TRIGGER fail_history BEFORE INSERT ON shop_homepage_history BEGIN SELECT RAISE(ABORT,'fixture history failure'); END");
$before=az_shop_homepage_state($db);$failed=$draft;$failed['heading']='Must roll back';
try{az_shop_homepage_save($admin,['revision'=>2,'action'=>'publish','design'=>$failed],$db);throw new RuntimeException('Expected history failure.');}
catch(PDOException $e){check159(str_contains($e->getMessage(),'fixture history failure'),'Unexpected persistence failure.');}
check159(az_shop_homepage_state($db)===$before&&!$db->inTransaction(),'History failure left a partial publication.');
$db->exec('DROP TRIGGER fail_history');

$restored=az_shop_homepage_history_restore($admin,['id'=>$initialDraft['id'],'revision'=>2],$db);
check159($restored['revision']===3&&!$restored['published'],'Restoration published or did not advance the draft revision.');
check159(az_shop_homepage_state($db)['draft_json']===$private,'Restoration changed disabled sections or uploaded image selection.');
check159(az_shop_homepage_public($db)===$original&&az_shop_homepage_state($db)['published_revision']===1,'Restore changed the published version.');
reject159(static fn()=>az_shop_homepage_history_restore($admin,['id'=>$saved['id'],'revision'=>2],$db),'revision_conflict',409);
reject159(static fn()=>az_shop_homepage_history_delete($admin,['id'=>$saved['id'],'revision'=>2],$db),'revision_conflict',409);
reject159(static fn()=>az_shop_homepage_history_restore($admin,['id'=>str_repeat('f',32),'revision'=>3],$db),'version_missing',404);
reject159(static fn()=>az_shop_homepage_history_restore($admin,['id'=>$saved['id'],'revision'=>'3'],$db),'invalid_version');
check159(az_shop_homepage_state($db)['revision']===3,'Rejected history action mutated settings.');

az_shop_homepage_save($admin,['revision'=>3,'action'=>'resetDraft'],$db);
check159(az_shop_homepage_state($db)['draft_json']===$original,'Reset changed the published design.');
check159(in_array('resetDraft',array_column(az_shop_homepage_history_list($admin,$db),'action'),true),'Reset was not recorded.');
$draft=$original;$draft['heading']='Different draft for toggle';
az_shop_homepage_save($admin,['revision'=>4,'action'=>'saveDraft','design'=>$draft],$db);
az_shop_homepage_toggle($admin,['revision'=>5,'key'=>'sampleEnabled','enabled'=>true],$db);
$toggle=array_values(array_filter(az_shop_homepage_history_list($admin,$db),static fn($item)=>$item['revision']===6));
check159(count($toggle)===2&&count(array_unique(array_column($toggle,'scope')))===2,'Toggle lost one distinct draft/live snapshot.');
check159(az_shop_homepage_state($db)['draft_json']['heading']===$draft['heading']&&az_shop_homepage_public($db)['heading']===$original['heading'],'Toggle mixed draft and public designs.');

// Removing history never deletes a current design. Only explicitly remove the old image references.
$before=az_shop_homepage_state($db);
foreach(az_shop_homepage_history_list($admin,$db) as $entry)if(in_array($image,$entry['imageIds'],true))
    az_shop_homepage_history_delete($admin,['id'=>$entry['id'],'revision'=>6],$db);
check159(az_shop_homepage_state($db)===$before,'Removing old versions mutated current designs.');
check159(az_shop_homepage_image_delete($admin,$image,$db)['deleted'],'Image remained locked after its last retained version was removed.');
reject159(static fn()=>az_shop_homepage_history_restore($admin,['id'=>$initialDraft['id'],'revision'=>6],$db),'version_missing',404);

// Unavailable picks must not block restoring the rest of an older layout.
$active=str_repeat('1',32);$archived=str_repeat('2',32);$deleted=str_repeat('3',32);
foreach([$active,$archived,$deleted] as $product)$db->prepare('INSERT INTO commerce_products VALUES(?,?,?,?)')->execute([$product,'Historical selection',1900,'active']);
$withProducts=az_shop_homepage_state($db)['draft_json'];$withProducts['heading']='Older curated design';
$withProducts['featuredMode']='manual';$withProducts['featuredProductIds']=[$archived,'demo-hoodie',$active,$deleted,'demo-tote'];
az_shop_homepage_save($admin,['revision'=>6,'action'=>'saveDraft','design'=>$withProducts],$db);
$curated=version159($db,'Older curated design');
$originalJson=$db->prepare('SELECT design_json FROM shop_homepage_history WHERE id=?');$originalJson->execute([$curated['id']]);$immutable=$originalJson->fetchColumn();
$db->prepare("UPDATE commerce_products SET state='archived' WHERE id=?")->execute([$archived]);
$db->prepare('DELETE FROM commerce_products WHERE id=?')->execute([$deleted]);
$publicBefore=az_shop_homepage_state($db);
$restored=az_shop_homepage_history_restore($admin,['id'=>$curated['id'],'revision'=>7],$db);
$expected=$withProducts;$expected['featuredProductIds']=['demo-hoodie',$active,'demo-tote'];
check159($restored['data']===$expected,'Restoring unavailable picks changed other fields or the remaining product order.');
check159($restored['removedFeaturedCount']===2&&count($restored['warnings'])===1,'Removed featured selections were not explicitly reported.');
$after=az_shop_homepage_state($db);
check159($after['published_json']===$publicBefore['published_json']&&$after['published_revision']===$publicBefore['published_revision'],'Curated restoration published automatically.');
$originalJson->execute([$curated['id']]);check159($originalJson->fetchColumn()===$immutable,'Restoration rewrote the original historical version.');
$newVersion=az_shop_homepage_history_list($admin,$db)[0];
check159($newVersion['action']==='restore'&&$newVersion['revision']===8,'Restored design did not receive a separate history entry.');
$newJson=$db->prepare('SELECT design_json FROM shop_homepage_history WHERE id=?');$newJson->execute([$newVersion['id']]);
check159(json_decode($newJson->fetchColumn(),true)===$expected,'New restored snapshot did not retain the adjusted selections.');

for($i=0;$i<35;$i++){
    $current=az_shop_homepage_state($db);$design=$current['draft_json'];$design['heading']='Retained version '.$i;
    az_shop_homepage_save($admin,['revision'=>$current['revision'],'action'=>'saveDraft','design'=>$design],$db);
}
$history=az_shop_homepage_history_list($admin,$db);
check159(count($history)===30&&$history[0]['heading']==='Retained version 34','History is not bounded to the latest 30 saved versions.');
$current=az_shop_homepage_state($db);
foreach($history as $entry)az_shop_homepage_history_delete($admin,['id'=>$entry['id'],'revision'=>$current['revision']],$db);
check159(az_shop_homepage_history_list($admin,$db)===[]&&az_shop_homepage_state($db)===$current,'Clearing every retained version damaged current designs.');
foreach(glob(AZ_STORAGE.'/*')?:[] as $path)if(is_file($path))unlink($path);
@unlink(AZ_STORAGE.'/homepage-images/.htaccess');@rmdir(AZ_STORAGE.'/homepage-images');@rmdir(AZ_STORAGE);
echo "Homepage history migration, atomicity, retention, restore, image protection and permissions: PASS\n";
