<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=realpath($argv[1]??'');
if(!$root||!is_file($root.'/.shop-hero-test-copy'))throw new RuntimeException('A marked temporary application copy is required.');
require $root.'/includes/bootstrap.php';
$db=az_db();$state=az_shop_homepage_state($db);
// v158 fixture intentionally writes its initial layouts directly. Snapshot those
// fixture layouts as an upgrade would, without touching any real installation.
$db->exec('DELETE FROM shop_homepage_history');
az_shop_homepage_history_record($db,$state['published_json'],$state['revision'],'published','upgrade',null);
az_shop_homepage_history_record($db,$state['draft_json'],$state['revision'],'draft','upgrade',null);
// An older curated layout precedes one archived and one deleted catalog item.
$removedProduct=str_repeat('5',32);$inactiveProduct=str_repeat('3',32);
$db->prepare('INSERT INTO commerce_products(id,owner_id,title,description,price_cents,checkout_url,state,created_at,updated_at) VALUES(?,1,?,?,?,?,?,?,?)')
    ->execute([$removedProduct,'Previously available fixture product','Historical restoration fixture',1700,'https://example.invalid/fixture-checkout/history','active',az_now(),az_now()]);
$db->prepare("UPDATE commerce_products SET state='active' WHERE id=?")->execute([$inactiveProduct]);
$older=$state['draft_json'];$older['heading']='Older curated design';$older['featuredMode']='manual';
$older['featuredProductIds']=[$inactiveProduct,str_repeat('1',32),$removedProduct,'demo-hoodie'];
az_shop_homepage_history_record($db,$older,$state['revision'],'draft','saveDraft',null);
$db->prepare("UPDATE commerce_products SET state='draft' WHERE id=?")->execute([$inactiveProduct]);
$db->prepare('DELETE FROM commerce_products WHERE id=?')->execute([$removedProduct]);
$db->prepare('INSERT INTO users(id,name,email,password_hash,created_at) VALUES(3,?,?,?,?)')
    ->execute(['Second fixture admin','second-admin@example.invalid',password_hash('fixture-only-password',PASSWORD_DEFAULT),az_now()]);
$db->exec("INSERT INTO user_roles(user_id,role) VALUES(3,'admin')");
file_put_contents($root.'/_shop-fixture-admin-two.php',<<<'PHP'
<?php
if(!is_file(__DIR__.'/.shop-hero-test-copy')){http_response_code(404);exit;}
require __DIR__.'/includes/bootstrap.php';
az_login_user(az_account_user(3));
http_response_code(204);
PHP);
echo "Initialized isolated homepage history and second administrator\n";
