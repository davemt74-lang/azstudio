<?php

declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/universal-header.php';
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
$user=az_is_installed()?az_current_user():null;
$products=az_is_installed()?az_shop_public_products():[];
$stripe=az_stripe_public_status();$categories=az_shop_categories();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#fffafc"><title>Shop · AZHottie</title><meta name="description" content="Explore AZHottie creator-curated apparel, fitness and lifestyle products.">
<link rel="icon" href="assets/favicon.svg"><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/universal-header.css?v=1.18.1">
<link rel="stylesheet" href="assets/commerce.css?v=1.36.0"><link rel="stylesheet" href="assets/commerce-local.css?v=1.32.0"><link rel="stylesheet" href="assets/commerce-shipping-checkout.css?v=1.35.0"><link rel="stylesheet" href="assets/shop-v156.css?v=1.56.0"></head>
<body data-page="shop" data-local-csrf="<?= az_h(az_csrf_token()) ?>"><?php az_render_universal_header('shop',$user); ?>
<main class="azh-commerce azh-commerce-public azh-shop" id="azh-shop">
<header class="azh-shop-hero"><div class="azh-shop-eyebrow">CURATED FOR THE CURIOUS</div>
<h1>Discover your <em>next favorite.</em></h1>
<p>Discover products featured by AZHottie creators. Shop individual items and curated sets with the checkout and delivery options shown for each product.</p>
<a class="azh-shop-hero-link" href="watch.php">See what creators are sharing <span aria-hidden="true">↗</span></a></header>
<section class="azh-shop-tools" aria-label="Browse products">
  <div class="azh-shop-tools-title"><h2>Explore the collection</h2><span id="azh-shop-count" role="status" aria-live="polite"><?= count($products) ?> products</span></div>
  <div class="azh-shop-tool-row"><label class="azh-shop-search">Search products<input id="azh-shop-query" type="search" placeholder="Search names, creators, products…" autocomplete="off" maxlength="100"></label>
  <label class="azh-shop-sort">Sort by<select id="azh-shop-sort"><option value="featured">Featured first</option><option value="name">Name A–Z</option><option value="price-low">Price: low to high</option><option value="price-high">Price: high to low</option></select></label></div>
  <div class="azh-shop-filters" aria-label="Product collections"><button type="button" class="is-current" data-shop-filter="all" aria-pressed="true">All products</button>
  <?php foreach($categories as $slug=>$label): ?>
    <?php if(count(array_filter($products,static fn($p)=>$p['category']===$slug))): ?>
    <button type="button" data-shop-filter="<?= az_h($slug) ?>" aria-pressed="false"><?= az_h($label) ?></button>
    <?php endif; ?>
  <?php endforeach; ?></div>
</section>
<section class="azh-commerce-list azh-commerce-grid azh-shop-grid" id="azh-shop-products" aria-label="Available products">
<?php foreach($products as $p): ?>
<article class="azh-commerce-card azh-shop-card" data-shop-product data-category="<?= az_h($p['category']) ?>" data-price="<?= (int)$p['priceCents'] ?>"
 data-title="<?= az_h($p['title']) ?>" data-featured="<?= $p['featured']?'1':'0' ?>"
 data-search="<?= az_h($p['title'].' '.$p['description'].' '.$p['categoryLabel'].' '.($p['recommendation']['credit']??'')) ?>">
<div class="azh-shop-card-media">
 <?php if($p['images']): ?><a href="shop-product.php?id=<?= az_h($p['id']) ?>" aria-label="Photos and details for <?= az_h($p['title']) ?>">
  <img loading="lazy" decoding="async" src="<?= az_h($p['images'][0]['src']) ?>" alt="<?= az_h($p['images'][0]['alt']) ?>"></a>
 <?php else: ?><div class="azh-shop-card-placeholder" role="img" aria-label="No product photo yet"><span aria-hidden="true">✳</span><small>AZHOTTIE</small></div><?php endif; ?>
 <span class="azh-shop-card-category"><?= az_h($p['categoryLabel']) ?></span>
 <?php if($p['featured']): ?><span class="azh-shop-card-featured">Studio pick</span><?php endif; ?>
</div>
<div class="azh-shop-card-body"><span class="azh-shop-card-kicker"><?= $p['bundleContents']?'CURATED SET':'CURATED PRODUCT' ?></span>
<h2><?= az_h($p['title']) ?></h2>
<?php if($p['tagline']!==''): ?><p class="azh-shop-card-tagline"><?= az_h($p['tagline']) ?></p><?php endif; ?>
<p class="azh-shop-card-description"><?= az_h($p['description']) ?></p>
<?php if($p['recommendation']): ?><p class="azh-shop-creator">Featured by <?= az_h($p['recommendation']['credit']) ?> · <a href="<?= az_h($p['recommendation']['url']) ?>">Watch video ↗</a></p><?php endif; ?>
<?php if($p['bundleContents']): ?><div class="azh-shop-bundle"><strong>Includes</strong>
<?php foreach($p['bundleContents'] as $part): ?><a href="<?= az_h($part['url']) ?>"><?= az_h($part['title']) ?></a><?php endforeach; ?>
<small>Sold as one separately priced and stocked bundle SKU. Component stock is not reserved automatically.</small></div><?php endif; ?>
<a class="azh-shop-details" href="shop-product.php?id=<?= az_h($p['id']) ?>">View photos &amp; details <span aria-hidden="true">↗</span></a>
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
</div></div></article><?php endforeach; ?>
<?php if(!$products): ?><div class="azh-shop-empty"><strong>New favorites are on the way.</strong><p>Curated products appear here once they're approved and available.</p></div><?php endif; ?>
</section>
<p id="azh-shop-no-matches" class="azh-shop-empty" hidden>No products match those filters. Try a different collection or search.</p>
<div class="azh-commerce-actions azh-shop-bottom-links"><button type="button" class="azh-commerce-small" data-local-status>Check a cash order</button>
<a class="azh-commerce-link" href="returns.php">Request a return or exchange</a></div>
<p class="azh-commerce-disclaimer">Cash options are unpaid requests subject to staff confirmation. Secure checkout requires signed payment events. External seller purchases are completed with the seller. Product availability and delivery options may vary.</p>
</main><script type="module" src="assets/shop-v156.js?v=1.56.0"></script>
<script type="module" src="assets/commerce-checkout.js?v=1.35.0"></script>
<script type="module" src="assets/commerce-shipping-checkout.js?v=1.35.0"></script>
<script type="module" src="assets/commerce-local.js?v=1.32.0"></script></body></html>
