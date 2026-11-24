<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';require_once __DIR__.'/includes/universal-header.php';
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
$id=is_string($_GET['id']??null)?$_GET['id']:'';$user=az_is_installed()?az_current_user():null;
$home=az_shop_homepage_public();
$products=$home['storeEnabled'] && az_is_installed()?array_values(array_filter(az_shop_public_products(),static fn($p)=>!empty($home['categories'][$p['category']]??false))):[];$p=null;
if(az_valid_id($id))foreach($products as $candidate){if($candidate['id']===$id){$p=$candidate;break;}}
if(!$p){http_response_code(404);}
$stripe=az_stripe_public_status();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#fffafc">
<title><?= $p?az_h($p['title']).' · ':'' ?>Shop · AZHottie</title><link rel="icon" href="assets/favicon.svg"><link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/commerce.css?v=1.36.0">
<link rel="stylesheet" href="assets/commerce-local.css?v=1.32.0"><link rel="stylesheet" href="assets/commerce-shipping-checkout.css?v=1.35.0"><link rel="stylesheet" href="assets/shop-v156.css?v=1.56.0"><link rel="stylesheet" href="assets/shop-v157.css?v=1.57.0"></head>
<body data-page="shop" data-local-csrf="<?= az_h(az_csrf_token()) ?>"><?php az_render_universal_header('shop',$user); ?>
<main class="azh-commerce azh-commerce-public azh-shop" id="azh-shop"><p class="azh-shop-crumb"><a href="shop.php">← All products</a></p>
<?php if(!$p): ?><section class="azh-shop-empty"><h1>Product unavailable</h1><p>This product may have been removed or is no longer available for purchase.</p><a href="shop.php">Return to shop</a></section>
<?php else: ?><article class="azh-shop-detail">
  <div class="azh-shop-gallery" data-shop-gallery>
    <div class="azh-shop-gallery-main">
    <?php if($p['images']): ?><img id="azh-shop-current-image" src="<?= az_h($p['images'][0]['src']) ?>" alt="<?= az_h($p['images'][0]['alt']) ?>">
    <?php else: ?><div class="azh-shop-card-placeholder" role="img" aria-label="No product photo yet"><span aria-hidden="true">✳</span><small>AZHOTTIE</small></div><?php endif; ?>
    </div>
    <?php if(count($p['images'])>1): ?><div class="azh-shop-thumbnails" aria-label="Product photos">
    <?php foreach($p['images'] as $i=>$image): ?><button type="button" data-gallery-src="<?= az_h($image['src']) ?>" data-gallery-alt="<?= az_h($image['alt']) ?>" aria-label="View product photo <?= $i+1 ?>" aria-pressed="<?= $i===0?'true':'false' ?>"><img loading="lazy" src="<?= az_h($image['src']) ?>" alt=""></button><?php endforeach; ?>
    </div><?php endif; ?>
  </div>
  <div class="azh-shop-detail-copy">
    <p class="azh-shop-card-kicker"><?= az_h($p['categoryLabel']) ?> / AZHOTTIE</p>
    <h1><?= az_h($p['title']) ?></h1>
    <?php if($p['tagline']): ?><p class="azh-shop-detail-tagline"><?= az_h($p['tagline']) ?></p><?php endif; ?>
    <p class="azh-shop-detail-description"><?= nl2br(az_h($p['description'])) ?></p>
    <?php if($p['recommendation']): ?><p class="azh-shop-creator">Featured by <?= az_h($p['recommendation']['credit']) ?> · <a href="<?= az_h($p['recommendation']['url']) ?>">Watch the creator video ↗</a></p><?php endif; ?>
    <?php if($p['bundleContents']): ?><section class="azh-shop-bundle"><h2>In this curated bundle</h2><ul><?php foreach($p['bundleContents'] as $part): ?><li><a href="<?= az_h($part['url']) ?>"><?= az_h($part['title']) ?></a></li><?php endforeach; ?></ul><small>This is one separately priced/stocked SKU. Component inventory is not reserved at checkout.</small></section><?php endif; ?>
    <div class="azh-commerce-card azh-shop-detail-checkout">
<div class="azh-commerce-card-footer azh-shop-card-footer">
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
<p>US shipping · <?php if($p['fulfillment']['shippingRate']==='carrier'): ?><?= $p['fulfillment']['carrierCheckoutAvailable']?'Live carrier rates at checkout':'Carrier rate checkout temporarily unavailable' ?><?php elseif($p['fulfillment']['shippingRate']==='free'): ?>Free<?php else: ?>$<?= az_h(number_format($p['fulfillment']['shippingFeeCents']/100,2)) ?><?php endif; ?>
<?php if($p['fulfillment']['shippingRate']==='flat' && $p['fulfillment']['freeAboveCents']>0): ?> · Free over $<?= az_h(number_format($p['fulfillment']['freeAboveCents']/100,2)) ?><?php endif; ?>
 · <?= (int)$p['fulfillment']['handlingDays'] ?>-day handling</p>
<?php endif; ?>
</div>
<?php if($p['stripeReady'] && $p['fulfillment']['inStock'] && ($p['fulfillment']['shippingRate']!=='carrier' || $p['fulfillment']['carrierCheckoutAvailable'])): ?>
<form data-stripe-checkout data-csrf="<?= az_h(az_csrf_token()) ?>" data-product-id="<?= az_h($p['id']) ?>"
 data-product-title="<?= az_h($p['title']) ?>" data-price-cents="<?= (int)$p['priceCents'] ?>"
 data-shipping-mode="<?= az_h($p['fulfillment']['shippingRate']) ?>" data-channel="shop">
<button class="azh-commerce-cta" type="submit"><?= $p['fulfillment']['shippingRate']==='carrier'?'Choose Shipping & Checkout':($stripe['mode']==='test'?'Test Stripe Checkout':'Secure Stripe Checkout') ?></button>
<small role="status" aria-live="polite"></small></form>
<?php elseif($p['checkoutMode']==='external' && $p['checkoutUrl']!==''): ?>
<a href="<?= az_h($p['checkoutUrl']) ?>" rel="noopener noreferrer external nofollow" target="_blank" class="azh-commerce-cta">Visit seller checkout ↗</a><?php endif; ?>
<?php if($p['localEnabled'] && $p['fulfillment']['inStock']): ?><button type="button" class="azh-commerce-cta azh-local-checkout-button"
data-local-order data-local-enabled="1" data-product-id="<?= az_h($p['id']) ?>"
data-product-title="<?= az_h($p['title']) ?>" data-price-cents="<?= (int)$p['priceCents'] ?>"
data-channel="shop" data-csrf="<?= az_h(az_csrf_token()) ?>">Pay with cash · Pickup / Delivery</button><?php endif; ?>
</div></div></div></article>
<?php $related=array_values(array_filter($products,static fn($candidate)=>$candidate['id']!==$p['id'] && $candidate['category']===$p['category'])); ?>
<?php if($related): ?><section class="azh-shop-related"><h2>More from <?= az_h($p['categoryLabel']) ?></h2><div class="azh-shop-related-grid">
<?php foreach(array_slice($related,0,4) as $item): ?><a href="shop-product.php?id=<?= az_h($item['id']) ?>">
<?php if($item['images']): ?><img src="<?= az_h($item['images'][0]['src']) ?>" alt="<?= az_h($item['images'][0]['alt']) ?>" loading="lazy"><?php else: ?><span class="azh-shop-card-placeholder" aria-hidden="true">✳</span><?php endif; ?>
<strong><?= az_h($item['title']) ?></strong><small>$<?= number_format($item['priceCents']/100,2) ?> USD</small></a><?php endforeach; ?></div></section><?php endif; ?>
<?php endif; ?>
</main><script type="module" src="assets/shop-v156.js?v=1.56.0"></script>
<script type="module" src="assets/commerce-checkout.js?v=1.35.0"></script><script type="module" src="assets/commerce-shipping-checkout.js?v=1.35.0"></script>
<script type="module" src="assets/commerce-local.js?v=1.32.0"></script></body></html>
