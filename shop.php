<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/universal-header.php';
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
$user=az_is_installed()?az_current_user():null;
$isAdmin=is_array($user)&&($user['status']??'')==='active'&&az_has_role($user,'admin');
$preview=isset($_GET['preview'])&&$_GET['preview']==='1';
if($preview&&!$isAdmin){http_response_code(403);echo 'Admin preview only.';exit;}
$config=az_is_installed()?az_shop_homepage_state():null;
$layout=$config?($preview?$config['draft_json']:$config['published_json']):az_shop_homepage_default();
$all=az_is_installed()?az_shop_public_products():[];
$visible=array_values(array_filter($all,static fn($p)=>!empty($layout['categories'][$p['category']]??false)));
$demo=!empty($layout['sampleEnabled'])?array_values(array_filter(az_shop_homepage_demo_products(),static fn($p)=>!empty($layout['categories'][$p['category']]??false))):[];
$products=array_merge($visible,$demo);
$categories=az_shop_categories();$core=['apparel','fitness','lifestyle','digital','candy','photography','spa'];
$categoryImages=['apparel'=>'shop-category-apparel-v157.webp','fitness'=>'shop-category-fitness-v157.webp','lifestyle'=>'shop-category-lifestyle-v157.webp',
'digital'=>'shop-category-digital-v157.webp','candy'=>'shop-category-candy-v157.webp','photography'=>'shop-category-photography-v157.webp',
'spa'=>'shop-category-spa-v157.webp','accessories'=>'shop-demo-tote-v157.webp','bundles'=>'shop-demo-bundle-v157.webp','gifts'=>'shop-demo-gift-v157.webp','other'=>'shop-demo-candles-v157.webp'];
$stripe=az_stripe_public_status();$sections=az_shop_homepage_sections();$order=array_flip($layout['order']);
$hero=az_shop_homepage_image_src($layout['heroImage']);$background=az_shop_homepage_image_src($layout['backgroundImage']);
$height=(int)$layout['heroHeight'];$fontSize=(int)$layout['headingSize'];$overlay=(int)$layout['overlayStrength'];
$style='--az-accent:'.az_h($layout['accentColor']).';--az-button:'.az_h($layout['buttonColor']).';--az-hero-text:'.az_h($layout['textColor']).';--az-shop-bg:'.az_h($layout['backgroundColor']).';--az-hero-height:'.$height.'px;--az-hero-size:'.$fontSize.'px;--az-overlay:'.($overlay/100).';';
if($background!=='')$style.='--az-shop-backdrop:url(\''.az_h($background).'\');';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#fffafc"><meta name="description" content="AZHottie creator-curated lifestyle products and collections."><title>Shop · AZHottie</title>
<link rel="icon" href="assets/favicon.svg"><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/universal-header.css?v=1.18.1">
<link rel="stylesheet" href="assets/commerce.css?v=1.36.0"><link rel="stylesheet" href="assets/commerce-local.css?v=1.32.0"><link rel="stylesheet" href="assets/commerce-shipping-checkout.css?v=1.35.0">
<link rel="stylesheet" href="assets/shop-v156.css?v=1.56.0"><link rel="stylesheet" href="assets/shop-v157.css?v=1.57.0"></head>
<body data-page="shop" data-local-csrf="<?= az_h(az_csrf_token()) ?>" data-shop-preview="<?= $preview?'1':'0' ?>">
<?php az_render_universal_header('shop',$user); ?>
<main class="azh-commerce azh-commerce-public azh-shop azh-shop-v157" id="azh-shop" data-font="<?= az_h($layout['fontStyle']) ?>" data-hero-align="<?= az_h($layout['heroAlignment']) ?>" style="<?= $style ?>">
<?php if($isAdmin): ?><div class="azh-shop-admin-link"><a href="shop-homepage-editor.php">✎ Edit shop homepage</a><?php if($preview): ?><span>PRIVATE DRAFT PREVIEW — NOT PUBLISHED</span><?php endif; ?></div><?php endif; ?>
<div class="azh-shop-sections">
<?php if($layout['sections']['hero']): ?>
<section class="azh-shop-section azh-shop-hero-v157" data-section="hero" style="order:<?= (int)$order['hero'] ?>;--az-hero-image:<?= $hero!==''?'url(\''.az_h($hero).'\')':'none' ?>;--az-hero-position:<?= az_h($layout['heroPosition']) ?>" aria-labelledby="azh-hero-headline">
<div class="azh-hero-overlay"></div><div class="azh-shop-hero-copy">
<p class="azh-shop-eyebrow" id="azh-hero-eyebrow"><?= az_h($layout['eyebrow']) ?></p>
<h1 id="azh-hero-headline"><span id="azh-hero-heading"><?= az_h($layout['heading']) ?></span> <em id="azh-hero-accent"><?= az_h($layout['accent']) ?></em></h1>
<p id="azh-hero-description"><?= az_h($layout['description']) ?></p>
<?php if($layout['storeEnabled']): ?><a class="azh-shop-primary-cta" id="azh-hero-cta" href="<?= az_h($layout['ctaHref']) ?>"><span><?= az_h($layout['ctaText']) ?></span> <span aria-hidden="true">→</span></a><?php endif; ?>
</div></section><?php endif; ?>
<?php if(!$layout['storeEnabled']): ?>
<section class="azh-shop-closed" aria-label="Store temporarily unavailable"><h2>Our shop is taking a short break.</h2><p>Explore AZHottie while our curated collections are being prepared.</p><a href="watch.php">Explore creator videos</a></section>
<?php else: ?>
<?php if($layout['sections']['categories']): ?>
<section class="azh-shop-section azh-shop-category-section" data-section="categories" id="shop-categories" style="order:<?= (int)$order['categories'] ?>" aria-label="Shop by collection">
<div class="azh-shop-section-heading"><div><p class="azh-shop-kicker">EXPLORE AZHOTTIE</p><h2 id="azh-categories-title"><?= az_h($layout['categoriesTitle']) ?></h2></div><span class="azh-shop-section-caption">Find your mood</span></div>
<div class="azh-category-grid">
<?php foreach($categories as $slug=>$label): ?>
<?php if(empty($layout['categories'][$slug]))continue;
$count=count(array_filter($products,static fn($p)=>$p['category']===$slug));
if($count===0&&!in_array($slug,$core,true))continue; ?>
<a class="azh-category-tile" href="#shop-browse" data-shop-category-jump="<?= az_h($slug) ?>">
<img src="assets/<?= az_h($categoryImages[$slug]??'shop-category-apparel-v157.webp') ?>" alt="" loading="lazy" decoding="async"><span class="azh-category-title"><?= az_h($label) ?></span><span class="azh-category-arrow" aria-hidden="true">↗</span></a>
<?php endforeach; ?>
</div></section><?php endif; ?>
<?php if($layout['sections']['featured']): ?>
<section class="azh-shop-section azh-shop-featured" id="shop-products" data-section="featured" style="order:<?= (int)$order['featured'] ?>" aria-labelledby="azh-featured-title">
<div class="azh-shop-section-heading"><div><p class="azh-shop-kicker">FEATURED PRODUCTS</p><h2 id="azh-featured-title"><?= az_h($layout['featuredTitle']) ?></h2></div><a href="#shop-browse">View all <span aria-hidden="true">→</span></a></div>
<?php if($products): ?><div class="azh-shop-featured-grid">
<?php foreach(array_slice($products,0,8) as $p): ?><article class="azh-featured-card">
<div class="azh-featured-image">
<?php if($p['images']): ?><img src="<?= az_h($p['images'][0]['src']) ?>" alt="<?= az_h($p['images'][0]['alt']) ?>" loading="lazy" decoding="async"><?php else: ?><span class="azh-featured-image-empty">AZHOTTIE</span><?php endif; ?>
<span class="azh-featured-badge"><?= !empty($p['demo'])?'SAMPLE · NOT FOR SALE':(!empty($p['featured'])?'Studio pick':'Featured') ?></span></div>
<div class="azh-featured-meta"><strong><?= az_h($p['title']) ?></strong><small><?= az_h($p['categoryLabel']) ?></small><b>$<?= az_h(number_format($p['priceCents']/100,2)) ?></b>
<?php if(!empty($p['demo'])): ?><span class="azh-demo-button" aria-disabled="true">Preview only</span>
<?php else: ?><a href="shop-product.php?id=<?= az_h($p['id']) ?>">View product <span aria-hidden="true">→</span></a><?php endif; ?></div>
</article><?php endforeach; ?></div>
<?php else: ?><div class="azh-shop-empty"><strong>New favorites are on the way.</strong><p>Explore our categories and check back for new collections.</p></div><?php endif; ?>
</section><?php endif; ?>
<?php if($layout['sections']['catalog']): ?>
<section class="azh-shop-section azh-shop-catalog" data-section="catalog" id="shop-browse" style="order:<?= (int)$order['catalog'] ?>">
<div class="azh-shop-section-heading"><div><p class="azh-shop-kicker">THE COMPLETE EDIT</p><h2><?= az_h($layout['catalogTitle']) ?></h2></div><span id="azh-shop-count" role="status" aria-live="polite"><?= count($products) ?> products</span></div>
<div class="azh-shop-tool-row"><label class="azh-shop-search">Search products<input id="azh-shop-query" type="search" placeholder="Search names, categories, products…" autocomplete="off" maxlength="100"></label>
<label class="azh-shop-sort">Sort by<select id="azh-shop-sort"><option value="featured">Featured first</option><option value="name">Name A–Z</option><option value="price-low">Price: low to high</option><option value="price-high">Price: high to low</option></select></label></div>
<div class="azh-shop-filters" aria-label="Product collections"><button type="button" class="is-current" data-shop-filter="all" aria-pressed="true">All products</button>
<?php foreach($categories as $slug=>$label): ?><?php if(!empty($layout['categories'][$slug]) && count(array_filter($products,static fn($p)=>$p['category']===$slug))): ?>
<button type="button" data-shop-filter="<?= az_h($slug) ?>" aria-pressed="false"><?= az_h($label) ?></button>
<?php endif; ?><?php endforeach; ?></div>
<div class="azh-commerce-list azh-commerce-grid azh-shop-grid" id="azh-shop-products" aria-label="Available products">
<?php foreach($products as $p): ?>
<article class="azh-commerce-card azh-shop-card" data-shop-product data-category="<?= az_h($p['category']) ?>" data-price="<?= (int)$p['priceCents'] ?>" data-title="<?= az_h($p['title']) ?>" data-featured="<?= !empty($p['featured'])?'1':'0' ?>" data-search="<?= az_h($p['title'].' '.$p['description'].' '.$p['categoryLabel']) ?>">
<div class="azh-shop-card-media">
<?php if($p['images']): ?><?php if(!empty($p['demo'])): ?><img loading="lazy" src="<?= az_h($p['images'][0]['src']) ?>" alt="<?= az_h($p['images'][0]['alt']) ?>"><?php else: ?><a href="shop-product.php?id=<?= az_h($p['id']) ?>"><img loading="lazy" src="<?= az_h($p['images'][0]['src']) ?>" alt="<?= az_h($p['images'][0]['alt']) ?>"></a><?php endif; ?>
<?php else: ?><div class="azh-shop-card-placeholder" role="img" aria-label="No product photo yet"><span aria-hidden="true">✳</span><small>AZHOTTIE</small></div><?php endif; ?>
<span class="azh-shop-card-category"><?= az_h($p['categoryLabel']) ?></span><?php if(!empty($p['demo'])): ?><span class="azh-shop-card-featured">SAMPLE · NOT FOR SALE</span><?php elseif(!empty($p['featured'])): ?><span class="azh-shop-card-featured">Studio pick</span><?php endif; ?>
</div><div class="azh-shop-card-body"><span class="azh-shop-card-kicker"><?= !empty($p['demo'])?'PREVIEW CATALOG':(!empty($p['bundleContents'])?'CURATED SET':'CURATED PRODUCT') ?></span>
<h2><?= az_h($p['title']) ?></h2><p class="azh-shop-card-tagline"><?= az_h($p['tagline']??'') ?></p>
<p class="azh-shop-card-description"><?= az_h($p['description']) ?></p>
<?php if(!empty($p['recommendation'])): ?><p class="azh-shop-creator">Featured by <?= az_h($p['recommendation']['credit']) ?> · <a href="<?= az_h($p['recommendation']['url']) ?>">Watch video →</a></p><?php endif; ?>
<?php if(!empty($p['bundleContents'])): ?><div class="azh-shop-bundle"><strong>Includes</strong><?php foreach($p['bundleContents'] as $part): ?><a href="<?= az_h($part['url']) ?>"><?= az_h($part['title']) ?></a><?php endforeach; ?><small>Sold as one separately stocked SKU; component inventory is not reserved.</small></div><?php endif; ?>
<div class="azh-commerce-card-footer azh-shop-card-footer"><strong>$<?= az_h(number_format($p['priceCents']/100,2)) ?> USD</strong>
<?php if(!empty($p['demo'])): ?><button type="button" class="azh-demo-button" disabled>Demo only · Checkout unavailable</button><small>Example product. No order or payment can be placed.</small>
<?php else: ?><a class="azh-shop-details" href="shop-product.php?id=<?= az_h($p['id']) ?>">View photos &amp; details →</a>
<?php if(!$p['fulfillment']['inStock']): ?><p>Out of stock · Checkout paused</p><?php elseif($p['fulfillment']['stockMode']==='made_to_order'): ?><p>Made to order · <?= (int)$p['fulfillment']['handlingDays'] ?>-day handling</p><?php elseif($p['fulfillment']['lowStock']): ?><p>Only <?= (int)$p['fulfillment']['available'] ?> left</p><?php endif; ?>
<?php if($preview): ?><button type="button" class="azh-demo-button" disabled>Preview mode · Checkout disabled</button>
<?php elseif($p['stripeReady'] && $p['fulfillment']['inStock'] && ($p['fulfillment']['shippingRate']!=='carrier' || $p['fulfillment']['carrierCheckoutAvailable'])): ?>
<form data-stripe-checkout data-csrf="<?= az_h(az_csrf_token()) ?>" data-product-id="<?= az_h($p['id']) ?>" data-product-title="<?= az_h($p['title']) ?>" data-price-cents="<?= (int)$p['priceCents'] ?>" data-shipping-mode="<?= az_h($p['fulfillment']['shippingRate']) ?>" data-channel="shop"><button class="azh-commerce-cta" type="submit"><?= $p['fulfillment']['shippingRate']==='carrier'?'Choose Shipping & Checkout':($stripe['mode']==='test'?'Test Stripe Checkout':'Secure Stripe Checkout') ?></button><small role="status" aria-live="polite"></small></form>
<?php elseif($p['checkoutMode']==='external'&&$p['checkoutUrl']!==''): ?><a class="azh-commerce-cta" href="<?= az_h($p['checkoutUrl']) ?>" target="_blank" rel="noopener noreferrer external nofollow">Visit seller checkout →</a><?php endif; ?>
<?php if(!$preview && $p['localEnabled'] && $p['fulfillment']['inStock']): ?><button type="button" class="azh-commerce-cta azh-local-checkout-button" data-local-order data-local-enabled="1" data-product-id="<?= az_h($p['id']) ?>" data-product-title="<?= az_h($p['title']) ?>" data-price-cents="<?= (int)$p['priceCents'] ?>" data-channel="shop" data-csrf="<?= az_h(az_csrf_token()) ?>">Pay with cash · Pickup / Delivery</button><?php endif; ?>
<?php endif; ?></div></div></article><?php endforeach; ?>
<?php if(!$products): ?><div class="azh-shop-empty"><strong>New favorites are on the way.</strong><p>Curated products appear here once approved.</p></div><?php endif; ?>
</div><p id="azh-shop-no-matches" class="azh-shop-empty" hidden>No products match those filters. Try another collection or search.</p>
</section><?php endif; ?>
<?php if($layout['sections']['support']): ?><section class="azh-shop-section azh-shop-support" data-section="support" style="order:<?= (int)$order['support'] ?>"><div><strong>Curated by AZHottie.</strong><p>Explore products and creator collections with a personal touch.</p></div><div class="azh-commerce-actions"><button type="button" class="azh-commerce-small" data-local-status>Check a cash order</button><a href="returns.php">Returns &amp; exchanges →</a></div></section><?php endif; ?>
<?php endif; ?>
</div></main>
<script type="module" src="assets/shop-v156.js?v=1.56.0"></script><script src="assets/shop-v157.js?v=1.57.0" defer></script>
<?php if(!$preview): ?><script type="module" src="assets/commerce-checkout.js?v=1.35.0"></script><script type="module" src="assets/commerce-shipping-checkout.js?v=1.35.0"></script><script type="module" src="assets/commerce-local.js?v=1.32.0"></script><?php endif; ?>
</body></html>
