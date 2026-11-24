<?php
declare(strict_types=1);

/** The published shop and unsaved admin preview share this exact markup. */
function az_shop_homepage_render(array $layout, bool $preview=false, bool $isAdmin=false): string
{
    $layout=az_shop_homepage_normalize($layout);
    $all=az_is_installed()?az_shop_public_products():[];
    if(az_is_installed()&&$layout['featuredMode']==='manual'){
        $selected=array_values(array_filter($layout['featuredProductIds'],static fn($id)=>az_valid_id($id)));
        if($selected){
            $byId=array_column($all,null,'id');
            foreach(az_shop_public_products($selected) as $product)if(!isset($byId[$product['id']]))$byId[$product['id']]=$product;
            $all=array_values($byId);
        }
    }
    $visible=array_values(array_filter($all,static fn($p)=>!empty($layout['categories'][$p['category']]??false)));
    $demo=!empty($layout['sampleEnabled'])?array_values(array_filter(az_shop_homepage_demo_products(),static fn($p)=>!empty($layout['categories'][$p['category']]??false))):[];
    $products=array_merge($visible,$demo);
    $counts=array_count_values(array_column($products,'category'));
    $categoryLabels=[];
    foreach($layout['categoryOrder'] as $slug){
        if(empty($layout['categories'][$slug]))continue;
        if(!$layout['showEmptyCategories']&&empty($counts[$slug]))continue;
        $categoryLabels[$slug]=$layout['categoryCards'][$slug]['title'];
    }
    foreach($products as &$product){
        $product['categoryLabel']=$layout['categoryCards'][$product['category']]['title']??$product['categoryLabel'];
        $product['bundleContents']=array_values(array_filter($product['bundleContents']??[],static fn($part)=>!empty($layout['categories'][$part['category']??'other'])));
    }
    unset($product);
    if($layout['featuredMode']==='manual'){
        $byId=array_column($products,null,'id');$featured=[];
        foreach($layout['featuredProductIds'] as $id)if(isset($byId[$id]))$featured[]=$byId[$id];
    }else{$featured=$products;}
    $featured=array_slice($featured,0,$layout['featuredCount']);
    $showCatalog=$layout['storeEnabled']&&$layout['sections']['catalog'];
    $targets=[];
    if($showCatalog)$targets[]='#shop-browse';
    if($layout['storeEnabled']&&$layout['sections']['featured'])$targets[]='#shop-products';
    if($layout['storeEnabled']&&$layout['sections']['categories'])$targets[]='#shop-categories';
    $ctaHref=$layout['ctaHref'];
    if(str_starts_with($ctaHref,'#')&&!in_array($ctaHref,$targets,true))$ctaHref=$targets[0]??'watch.php';
    $stripe=az_stripe_public_status();$order=array_flip($layout['order']);
    $hero=az_shop_homepage_image_src($layout['heroImage']);
    $mobileHero=$layout['mobileHeroImage']==='inherit'?$hero:az_shop_homepage_image_src($layout['mobileHeroImage']);
    $background=az_shop_homepage_image_src($layout['backgroundImage']);
    // External CSS resolves URL-valued variables from its own directory. All
    // image paths are anchored to the installation, including /studio installs.
    $cssImage=static function(string $src):string{
        return $src===''?'none':'url("'.str_replace(['\\','"'],['\\\\','\\"'],az_url($src)).'")';
    };
    $inline=['left'=>'flex-start','center'=>'center','right'=>'flex-end'];
    $block=['top'=>'flex-start','center'=>'center','bottom'=>'flex-end'];
    $vars=[
        '--az-accent'=>$layout['accentColor'],'--az-button'=>$layout['buttonColor'],
        '--az-hero-text'=>$layout['textColor'],'--az-shop-bg'=>$layout['backgroundColor'],
        '--az-hero-height'=>$layout['heroHeight'].'px','--az-hero-size'=>$layout['headingSize'].'px',
        '--az-overlay'=>$layout['overlayStrength']/100,
        '--az-mobile-hero-height'=>$layout['mobileHeroHeight'].'px',
        '--az-mobile-hero-size'=>$layout['mobileHeadingSize'].'px',
        '--az-mobile-overlay'=>$layout['mobileOverlayStrength']/100,
        '--az-hero-align'=>$layout['heroAlignment'],'--az-hero-inline'=>$inline[$layout['heroAlignment']],
        '--az-hero-block'=>$block[$layout['heroTextPosition']],
        '--az-mobile-hero-align'=>$layout['mobileHeroAlignment'],
        '--az-mobile-hero-inline'=>$inline[$layout['mobileHeroAlignment']],
        '--az-mobile-hero-block'=>$block[$layout['mobileHeroTextPosition']],
        '--az-hero-overlay-direction'=>$layout['heroAlignment']==='right'?'270deg':'90deg',
        '--az-mobile-overlay-direction'=>$layout['mobileHeroTextPosition']==='bottom'?'0deg':'180deg',
        '--az-shop-backdrop'=>$cssImage($background),
    ];
    $style='';foreach($vars as $name=>$value)$style.=$name.':'.$value.';';
    $heroStyle='--az-hero-image:'.$cssImage($hero).';--az-mobile-hero-image:'.$cssImage($mobileHero).';'
        .'--az-hero-position:'.$layout['heroFocalX'].'% '.$layout['heroFocalY'].'%;'
        .'--az-mobile-hero-position:'.$layout['mobileHeroFocalX'].'% '.$layout['mobileHeroFocalY'].'%;';
    ob_start();
    ?>
<main class="azh-commerce azh-commerce-public azh-shop azh-shop-v157" id="azh-shop" data-font="<?= az_h($layout['fontStyle']) ?>" data-hero-align="<?= az_h($layout['heroAlignment']) ?>" data-mobile-hero-align="<?= az_h($layout['mobileHeroAlignment']) ?>" style="<?= az_h($style) ?>">
<?php if($isAdmin): ?><div class="azh-shop-admin-link"><?php if($preview): ?><strong>Shop homepage preview</strong><span>PRIVATE DRAFT PREVIEW — NOT PUBLISHED</span><?php else: ?><a href="shop-homepage-editor.php">Edit shop homepage</a><?php endif; ?></div><?php endif; ?>
<div class="azh-shop-sections">
<?php if($layout['sections']['hero']): ?>
<section class="azh-shop-section azh-shop-hero-v157" data-section="hero" style="order:<?= (int)$order['hero'] ?>;<?= az_h($heroStyle) ?>" aria-labelledby="azh-hero-headline">
<div class="azh-hero-overlay"></div><div class="azh-shop-hero-copy">
<p class="azh-shop-eyebrow" id="azh-hero-eyebrow"><?= az_h($layout['eyebrow']) ?></p>
<h1 id="azh-hero-headline"><span id="azh-hero-heading"><?= az_h($layout['heading']) ?></span> <em id="azh-hero-accent"><?= az_h($layout['accent']) ?></em></h1>
<p id="azh-hero-description"><?= az_h($layout['description']) ?></p>
<?php if($layout['storeEnabled']): ?><a class="azh-shop-primary-cta" id="azh-hero-cta" href="<?= az_h($ctaHref) ?>"><span><?= az_h($layout['ctaText']) ?></span> <span aria-hidden="true">→</span></a><?php endif; ?>
</div></section><?php endif; ?>
<?php if(!$layout['storeEnabled']): ?>
<section class="azh-shop-closed" aria-label="Store temporarily unavailable"><h2>Our shop is taking a short break.</h2><p>Explore AZHottie while our curated collections are being prepared.</p><a href="watch.php">Explore creator videos</a></section>
<?php else: ?>
<?php if($layout['sections']['categories']): ?>
<section class="azh-shop-section azh-shop-category-section" data-section="categories" id="shop-categories" style="order:<?= (int)$order['categories'] ?>" aria-label="Shop by collection">
<div class="azh-shop-section-heading"><div><p class="azh-shop-kicker">EXPLORE AZHOTTIE</p><h2 id="azh-categories-title"><?= az_h($layout['categoriesTitle']) ?></h2></div><span class="azh-shop-section-caption">Find your mood</span></div>
<div class="azh-category-grid">
<?php foreach($categoryLabels as $slug=>$label):
$image=az_shop_homepage_category_image_src($slug,$layout['categoryCards'][$slug]['image']);
$tag=$showCatalog?'a':'div'; ?>
<<?= $tag ?> class="azh-category-tile" data-category-card="<?= az_h($slug) ?>"<?php if($showCatalog): ?> href="#shop-browse" data-shop-category-jump="<?= az_h($slug) ?>"<?php endif; ?>>
<?php if($image!==''): ?><img src="<?= az_h(az_url($image)) ?>" alt="" loading="lazy" decoding="async"><?php endif; ?><span class="azh-category-title"><?= az_h($label) ?></span></<?= $tag ?>>
<?php endforeach; ?>
<?php if(!$categoryLabels): ?><p class="azh-shop-empty">Collections will appear here when products are available.</p><?php endif; ?>
</div></section><?php endif; ?>
<?php if($layout['sections']['featured']): ?>
<section class="azh-shop-section azh-shop-featured" id="shop-products" data-section="featured" style="order:<?= (int)$order['featured'] ?>" aria-labelledby="azh-featured-title">
<div class="azh-shop-section-heading"><div><p class="azh-shop-kicker">FEATURED PRODUCTS</p><h2 id="azh-featured-title"><?= az_h($layout['featuredTitle']) ?></h2></div><?php if($showCatalog): ?><a href="#shop-browse">View all</a><?php endif; ?></div>
<?php if($featured): ?><div class="azh-shop-featured-grid">
<?php foreach($featured as $p): ?><article class="azh-featured-card" data-featured-product-id="<?= az_h($p['id']) ?>">
<div class="azh-featured-image">
<?php if($p['images']): ?><img src="<?= az_h($p['images'][0]['src']) ?>" alt="<?= az_h($p['images'][0]['alt']) ?>" loading="lazy" decoding="async"><?php else: ?><span class="azh-featured-image-empty">AZHOTTIE</span><?php endif; ?>
<span class="azh-featured-badge"><?= !empty($p['demo'])?'SAMPLE · NOT FOR SALE':(!empty($p['featured'])?'Studio pick':'Featured') ?></span></div>
<div class="azh-featured-meta"><strong><?= az_h($p['title']) ?></strong><small><?= az_h($p['categoryLabel']) ?></small><b>$<?= az_h(number_format($p['priceCents']/100,2)) ?></b>
<?php if(!empty($p['demo'])||$preview): ?><span class="azh-demo-button" aria-disabled="true"><?= !empty($p['demo'])?'Sample product':'Product preview' ?></span>
<?php else: ?><a href="shop-product.php?id=<?= az_h($p['id']) ?>">View product <span aria-hidden="true">→</span></a><?php endif; ?></div>
</article><?php endforeach; ?></div>
<?php else: ?><div class="azh-shop-empty"><strong>New favorites are on the way.</strong><p>Explore our categories and check back for new collections.</p></div><?php endif; ?>
</section><?php endif; ?>
<?php if($layout['sections']['catalog']): ?>
<section class="azh-shop-section azh-shop-catalog" data-section="catalog" id="shop-browse" style="order:<?= (int)$order['catalog'] ?>">
<div class="azh-shop-section-heading"><div><p class="azh-shop-kicker">THE COMPLETE EDIT</p><h2 id="azh-catalog-title"><?= az_h($layout['catalogTitle']) ?></h2></div><span id="azh-shop-count" role="status" aria-live="polite"><?= count($products) ?> products</span></div>
<div class="azh-shop-tool-row"><label class="azh-shop-search">Search products<input id="azh-shop-query" type="search" placeholder="Search names, categories, products…" autocomplete="off" maxlength="100"></label>
<label class="azh-shop-sort">Sort by<select id="azh-shop-sort"><option value="featured">Featured first</option><option value="name">Name A–Z</option><option value="price-low">Price: low to high</option><option value="price-high">Price: high to low</option></select></label></div>
<div class="azh-shop-filters" aria-label="Product collections"><button type="button" class="is-current" data-shop-filter="all" aria-pressed="true">All products</button>
<?php foreach($categoryLabels as $slug=>$label): ?>
<button type="button" data-shop-filter="<?= az_h($slug) ?>" aria-pressed="false"><?= az_h($label) ?></button>
<?php endforeach; ?></div>
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
<?php else: ?><?php if(!$preview): ?><a class="azh-shop-details" href="shop-product.php?id=<?= az_h($p['id']) ?>">View photos &amp; details</a><?php endif; ?>
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
<?php if($layout['sections']['support']): ?><section class="azh-shop-section azh-shop-support" data-section="support" style="order:<?= (int)$order['support'] ?>"><div><strong>Curated by AZHottie.</strong><p>Explore products and creator collections with a personal touch.</p></div><div class="azh-commerce-actions"><?php if(!$preview): ?><button type="button" class="azh-commerce-small" data-local-status>Check a cash order</button><?php endif; ?><a href="returns.php">Returns &amp; exchanges →</a></div></section><?php endif; ?>
<?php endif; ?>
</div></main>
<?php
    return (string)ob_get_clean();
}
