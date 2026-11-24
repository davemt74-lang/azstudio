<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/universal-header.php';
require_once __DIR__.'/includes/shop-homepage-view.php';
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
$user=az_is_installed()?az_current_user():null;
$isAdmin=is_array($user)&&($user['status']??'')==='active'&&az_has_role($user,'admin');
$preview=isset($_GET['preview'])&&$_GET['preview']==='1';
if($preview&&!$isAdmin){http_response_code(403);echo 'Admin preview only.';exit;}
$config=az_is_installed()?az_shop_homepage_state():null;
$layout=$config?($preview?$config['draft_json']:$config['published_json']):az_shop_homepage_default();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#fffafc"><meta name="description" content="AZHottie creator-curated lifestyle products and collections."><title>Shop · AZHottie</title>
<link rel="icon" href="assets/favicon.svg"><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/universal-header.css?v=1.18.1">
<link rel="stylesheet" href="assets/commerce.css?v=1.36.0"><link rel="stylesheet" href="assets/commerce-local.css?v=1.32.0"><link rel="stylesheet" href="assets/commerce-shipping-checkout.css?v=1.35.0">
<link rel="stylesheet" href="assets/shop-v156.css?v=1.56.0"><link rel="stylesheet" href="assets/shop-v157.css?v=1.58.0"></head>
<body data-page="shop" data-local-csrf="<?= az_h(az_csrf_token()) ?>" data-shop-preview="<?= $preview?'1':'0' ?>">
<?php az_render_universal_header('shop',$user); ?>
<?php echo az_shop_homepage_render($layout,$preview,$isAdmin); ?>
<script type="module" src="assets/shop-v156.js?v=1.58.0"></script><script src="assets/shop-v157.js?v=1.58.0" defer></script>
<?php if(!$preview): ?><script type="module" src="assets/commerce-checkout.js?v=1.35.0"></script><script type="module" src="assets/commerce-shipping-checkout.js?v=1.35.0"></script><script type="module" src="assets/commerce-local.js?v=1.32.0"></script><?php endif; ?>
</body></html>
