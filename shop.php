<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/universal-header.php';
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
$user=az_is_installed()?az_current_user():null;
$products=az_is_installed()?az_commerce_public_products():[];
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Shop · AZHottie</title><link rel="icon" href="assets/favicon.svg"><link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/commerce.css?v=1.30.0"></head>
<body data-page="shop"><?php az_render_universal_header('shop',$user); ?>
<main class="azh-commerce azh-commerce-public"><header class="azh-commerce-hero"><div><p class="azh-eyebrow">THE AZHOTTIE SHOP</p><h1>Shop the stories<span>.</span></h1>
<p>Shop curated products featured by AZHottie creators. Checkout opens on the external seller's secure website.</p></div></header>
<section class="azh-commerce-list azh-commerce-grid" aria-label="Available products">
<?php foreach($products as $p): ?><article class="azh-commerce-card"><span class="azh-commerce-eyebrow">AVAILABLE PRODUCT</span><h2><?= az_h($p['title']) ?></h2>
<p><?= nl2br(az_h($p['description'])) ?></p><div class="azh-commerce-card-footer">
<strong>$<?= az_h(number_format($p['priceCents']/100,2)) ?> USD</strong>
<a href="<?= az_h($p['checkoutUrl']) ?>" rel="noopener noreferrer external nofollow" target="_blank" class="azh-commerce-cta">Visit seller checkout ↗</a>
</div></article><?php endforeach; ?>
<?php if(!$products): ?><div class="azh-commerce-card"><h2>Products coming soon</h2><p>Curated products will appear here once approved.</p></div><?php endif; ?>
</section><p class="azh-commerce-disclaimer">Checkout, payment, fulfillment, returns and payment details are handled by the external seller. Product prices are shown as entered by AZHottie and may differ at checkout.</p>
</main></body></html>