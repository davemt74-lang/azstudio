<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/universal-header.php';
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
$user=az_is_installed()?az_current_user():null;
$products=az_is_installed()?az_commerce_public_products():[];
$stripe=az_stripe_public_status();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Shop · AZHottie</title><link rel="icon" href="assets/favicon.svg"><link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/commerce.css?v=1.33.0"><link rel="stylesheet" href="assets/commerce-local.css?v=1.32.0"></head>
<body data-page="shop" data-local-csrf="<?= az_h(az_csrf_token()) ?>"><?php az_render_universal_header('shop',$user); ?>
<main class="azh-commerce azh-commerce-public"><header class="azh-commerce-hero"><div><p class="azh-eyebrow">THE AZHOTTIE SHOP</p><h1>Shop the stories<span>.</span></h1>
<p>Shop curated products featured by AZHottie creators. Choose secure checkout, an external seller, or cash pickup/delivery when offered on the individual product.</p></div></header>
<section class="azh-commerce-list azh-commerce-grid" aria-label="Available products">
<?php foreach($products as $p): ?><article class="azh-commerce-card"><span class="azh-commerce-eyebrow">AVAILABLE PRODUCT</span><h2><?= az_h($p['title']) ?></h2>
<p><?= nl2br(az_h($p['description'])) ?></p><div class="azh-commerce-card-footer">
<strong>$<?= az_h(number_format($p['priceCents']/100,2)) ?> USD</strong>
<div class="azh-shipping-product-summary">
<?php if(!$p['fulfillment']['inStock']): ?>
<p>Out of stock · Checkout paused</p>
<?php elseif($p['fulfillment']['stockMode']==='made_to_order'): ?>
<p>Made to order · <?= (int)$p['fulfillment']['handlingDays'] ?>-day handling</p>
<?php elseif($p['fulfillment']['lowStock']): ?>
<p>Only <?= (int)$p['fulfillment']['available'] ?> left</p>
<?php endif; ?>
<?php if($p['fulfillment']['shippingEnabled']): ?>
<p>US shipping · <?php if($p['fulfillment']['shippingRate']==='free'): ?>Free<?php else: ?>$<?= az_h(number_format($p['fulfillment']['shippingFeeCents']/100,2)) ?><?php endif; ?>
<?php if($p['fulfillment']['freeAboveCents']>0): ?> · Free over $<?= az_h(number_format($p['fulfillment']['freeAboveCents']/100,2)) ?><?php endif; ?>
 · <?= (int)$p['fulfillment']['handlingDays'] ?>-day handling</p>
<?php endif; ?>
</div>
<?php if($p['stripeReady'] && $p['fulfillment']['inStock']): ?>
<form data-stripe-checkout data-csrf="<?= az_h(az_csrf_token()) ?>" data-product-id="<?= az_h($p['id']) ?>" data-channel="shop">
<button class="azh-commerce-cta" type="submit"><?= $stripe['mode']==='test'?'Test Stripe Checkout':'Secure Stripe Checkout' ?></button>
<small role="status" aria-live="polite"></small></form>
<?php elseif($p['checkoutMode']==='external' && $p['checkoutUrl']!==''): ?>
<a href="<?= az_h($p['checkoutUrl']) ?>" rel="noopener noreferrer external nofollow" target="_blank" class="azh-commerce-cta">Visit seller checkout ↗</a><?php endif; ?>
<?php if($p['localEnabled'] && $p['fulfillment']['inStock']): ?><button type="button" class="azh-commerce-cta azh-local-checkout-button"
data-local-order data-local-enabled="1" data-product-id="<?= az_h($p['id']) ?>"
data-product-title="<?= az_h($p['title']) ?>" data-price-cents="<?= (int)$p['priceCents'] ?>"
data-channel="shop" data-csrf="<?= az_h(az_csrf_token()) ?>">Pay with cash · Pickup / Delivery</button><?php endif; ?>
</div></article><?php endforeach; ?>
<?php if(!$products): ?><div class="azh-commerce-card"><h2>Products coming soon</h2><p>Curated products will appear here once approved.</p></div><?php endif; ?>
</section><div class="azh-commerce-actions"><button type="button" class="azh-commerce-small" data-local-status>Check a cash order</button></div><p class="azh-commerce-disclaimer">Cash orders are unpaid requests subject to staff confirmation of the final total, any applicable taxes, and pickup/delivery availability. Stripe orders require signed payment-provider events. External seller purchases are handled by those sellers.</p>
</main><script type="module" src="assets/commerce-checkout.js?v=1.31.0"></script><script type="module" src="assets/commerce-local.js?v=1.32.0"></script></body></html>