<?php
declare(strict_types=1);
/** Extend only a marked, isolated shop renderer fixture; never a real site. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath($argv[1] ?? '');
if (!$root || !is_file($root . '/.shop-hero-test-copy')) {
    throw new RuntimeException('A marked temporary application copy is required.');
}
require $root . '/includes/bootstrap.php';
$db = az_db();
$now = az_now();
// A fourth image is referenced only by a category tile, so its media access
// and deletion guards cannot accidentally pass through a hero reference.
$categoryImage = str_repeat('d', 32);
$source = $root . '/assets/shop-editorial-hero-v157.webp';
$size = getimagesize($source);
copy($source, az_shop_homepage_image_dir() . '/' . $categoryImage . '.webp');
$db->prepare('INSERT INTO shop_homepage_images(id,storage_name,mime_type,alt_text,width,height,created_at) VALUES(?,?,?,?,?,?,?)')
    ->execute([$categoryImage, $categoryImage . '.webp', 'image/webp', 'Fixture category image', $size[0], $size[1], $now]);
foreach ([
    ['1', 'Studio sweatshirt', 'apparel', 'active', 4900],
    ['2', 'Studio bottle', 'fitness', 'active', 2400],
    ['3', 'Unpublished product', 'lifestyle', 'draft', 9900],
] as [$letter, $title, $category, $status, $price]) {
    $id = str_repeat($letter, 32);
    $db->prepare('INSERT INTO commerce_products(id,owner_id,title,description,price_cents,checkout_url,state,created_at,updated_at) VALUES(?,1,?,?,?,?,?,?,?)')
        ->execute([$id, $title, 'Isolated acceptance product.', $price, 'https://example.invalid/fixture-checkout/' . $letter, $status, $now, '2000-01-01T00:00:00Z']);
    $db->prepare('INSERT INTO commerce_product_catalog(product_id,category,tagline,featured,updated_at) VALUES(?,?,?,1,?)')
        ->execute([$id, $category, 'Acceptance fixture', $now]);
}
// Both real picks above sit beyond the default public catalog page. An admin
// must still be able to find and feature an older eligible product.
for ($index = 1000; $index < 1050; $index++) {
    $id = str_pad(dechex($index), 32, '0', STR_PAD_LEFT);
    $db->prepare('INSERT INTO commerce_products(id,owner_id,title,description,price_cents,checkout_url,state,created_at,updated_at) VALUES(?,1,?,?,?,?,?,?,?)')
        ->execute([$id, 'Catalog filler ' . $index, 'Isolated pagination fixture.', 1200,
            'https://example.invalid/fixture-checkout/' . $index, 'active', $now, $now]);
    $db->prepare('INSERT INTO commerce_product_catalog(product_id,category,tagline,featured,updated_at) VALUES(?,?,?,0,?)')
        ->execute([$id, 'candy', 'Acceptance fixture', $now]);
}
foreach ([str_repeat('2', 32), str_pad(dechex(1000), 32, '0', STR_PAD_LEFT), str_repeat('3', 32)] as $position => $component) {
    $db->prepare('INSERT INTO commerce_bundle_components(bundle_id,component_id,position) VALUES(?,?,?)')
        ->execute([str_repeat('1', 32), $component, $position]);
}
$db->prepare('INSERT INTO users(id,name,email,password_hash,created_at) VALUES(2,?,?,?,?)')
    ->execute(['Fixture Member', 'member@example.invalid', password_hash('fixture-only-password', PASSWORD_DEFAULT), $now]);
$db->exec("INSERT INTO user_roles(user_id,role) VALUES(2,'fan')");
file_put_contents($root . '/_shop-fixture-member.php', <<<'PHP'
<?php
if (!is_file(__DIR__ . '/.shop-hero-test-copy')) { http_response_code(404); exit; }
require __DIR__ . '/includes/bootstrap.php';
az_login_user(az_account_user(2));
http_response_code(204);
PHP);
$live = az_shop_homepage_default();
$draft = $live;
$draft['heading'] = 'Saved private collection';
$draft['storeEnabled'] = false;
$draft['sections']['featured'] = false;
$draft['sections']['support'] = false;
$draft['categories']['fitness'] = false;
$db->prepare('UPDATE shop_homepage_settings SET published_json=?,draft_json=? WHERE id=1')
    ->execute([json_encode($live, JSON_THROW_ON_ERROR), json_encode($draft, JSON_THROW_ON_ERROR)]);
echo "Initialized isolated Shop 2.2 editor products and draft\n";
