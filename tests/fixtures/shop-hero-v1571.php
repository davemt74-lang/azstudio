<?php
declare(strict_types=1);
/** Seed only an isolated copy created by shop-hero-v1571-browser.py. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath($argv[1] ?? '');
$mode = $argv[2] ?? '';
if (!$root || !is_file($root . '/.shop-hero-test-copy')) {
    throw new RuntimeException('A marked temporary application copy is required.');
}
require $root . '/includes/bootstrap.php';
if ($mode === 'init') {
    az_prepare_storage();
    $config = ['installed' => true, 'database' => ['driver' => 'sqlite'],
        'app_secret' => str_repeat('fixture-only-', 6)];
    file_put_contents(AZ_CONFIG_FILE, '<?php return ' . var_export($config, true) . ';');
    $GLOBALS['az_config_cache'] = $config;
    $db = az_connect_database($config['database']);
    $GLOBALS['az_database'] = $db;
    az_create_schema($db);
    $db->prepare('INSERT INTO users(id,name,email,password_hash,created_at) VALUES(1,?,?,?,?)')
        ->execute(['Fixture Admin', 'fixture@example.invalid', password_hash('fixture-only-password', PASSWORD_DEFAULT), az_now()]);
    $db->exec("INSERT INTO user_roles(user_id,role) VALUES(1,'admin')");
    $source = $root . '/assets/shop-editorial-hero-v157.webp';
    $size = getimagesize($source);
    foreach (['a', 'b', 'c'] as $letter) {
        $id = str_repeat($letter, 32);
        copy($source, az_shop_homepage_image_dir() . '/' . $id . '.webp');
        $db->prepare('INSERT INTO shop_homepage_images(id,storage_name,mime_type,alt_text,width,height,created_at) VALUES(?,?,?,?,?,?,?)')
            ->execute([$id, $id . '.webp', 'image/webp', 'Fixture image ' . $letter, $size[0], $size[1], az_now()]);
    }
    // This endpoint exists only inside the marked temporary test copy. It is
    // never added to the application source or deployment archive.
    file_put_contents($root . '/_shop-fixture-login.php', <<<'PHP'
<?php
if (!is_file(__DIR__ . '/.shop-hero-test-copy')) { http_response_code(404); exit; }
require __DIR__ . '/includes/bootstrap.php';
az_login_user(az_account_user(1));
http_response_code(204);
PHP);
    echo "Initialized isolated shop renderer fixture\n";
    exit;
}
if (!in_array($mode, ['default', 'uploaded'], true)) {
    throw new RuntimeException('Unknown fixture layout.');
}
$db = az_db();
$live = az_shop_homepage_default();
$draft = $live;
$draft['heading'] = 'Private draft collection';
if ($mode === 'uploaded') {
    $live['heroImage'] = str_repeat('a', 32);
    $live['backgroundImage'] = str_repeat('b', 32);
    $draft['heroImage'] = str_repeat('c', 32);
    $draft['backgroundImage'] = str_repeat('b', 32);
}
$db->prepare('UPDATE shop_homepage_settings SET published_json=?,draft_json=? WHERE id=1')
    ->execute([json_encode($live, JSON_THROW_ON_ERROR), json_encode($draft, JSON_THROW_ON_ERROR)]);
echo "Selected $mode renderer fixture\n";
