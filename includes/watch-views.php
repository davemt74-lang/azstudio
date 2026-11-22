<?php
declare(strict_types=1);
require_once __DIR__ . '/universal-header.php';

/** The caller supplies only public video fields; this view never reads studio data. */
function az_watch_duration(int $milliseconds): string
{
    $seconds = max(0, (int)round($milliseconds / 1000));
    return $seconds >= 3600 ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60) : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
}

function az_watch_date(?string $date): string
{
    $timestamp = $date ? strtotime($date) : false;
    return $timestamp === false ? '' : (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('America/Phoenix'))->format('M j, Y');
}

function az_render_watch_page(array $state): void
{
    $feed = $state['feed'] ?? ['videos' => [], 'page' => 1, 'total' => 0, 'hasMore' => false];
    $video = $state['video'] ?? null;
    $search = $state['search'] ?? '';
    $user = $state['user'] ?? null;
    $error = $state['error'] ?? '';
    $sample = !empty($state['sample']);
    $routes = array_merge(['home' => 'index.php', 'watch' => 'watch.php', 'calendar' => 'calendar.php', 'login' => 'login.php', 'account' => 'user.php', 'signup' => 'signup.php', 'assets' => 'assets/'], $state['routes'] ?? []);
    $title = $video ? $video['title'] . ' · AZHottie' : 'Watch AZHottie · The stories behind the smile';
    $description = $video ? $video['description'] : 'Meet the people and personalities behind AZHottie. Watch interviews, creative stories, and a little Arizona attitude.';
    $pageUrl = static fn(int $page): string => $routes['watch'] . '?' . http_build_query(['search' => $search, 'page' => $page]);
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#faf7f3"><meta name="description" content="<?= az_h($description) ?>"><?php if ($sample || $error !== ''): ?><meta name="robots" content="noindex,nofollow"><?php endif; ?><title><?= az_h($title) ?></title><link rel="icon" href="<?= az_h($routes['assets']) ?>favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="<?= az_h($routes['assets']) ?>watch.css"><link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/commerce.css?v=1.33.0"><link rel="stylesheet" href="assets/commerce-local.css?v=1.32.0"></head>
<body class="watch-page" data-local-csrf="<?= az_h(!$sample?az_csrf_token():'') ?>">
<?php if ($sample): ?><div class="watch-sample"><strong>SAMPLE DATA PREVIEW</strong><span>Fictional titles and synthetic footage · No production account or database access</span><a href="v12-account.php">Preview accounts</a></div><?php endif; ?>
<?php az_render_universal_header('watch', $user); ?>
<main class="watch-main" id="main">
<?php if ($error !== ''): ?>
<section class="watch-empty watch-error"><span class="watch-star" aria-hidden="true">✳</span><p class="watch-eyebrow">A MOMENT OFF CAMERA</p><h1>This one isn’t available.</h1><p><?= az_h($error) ?></p><a class="watch-button" href="<?= az_h($routes['watch']) ?>">Browse the videos </a></section>
<?php elseif ($video): ?>
<article class="watch-detail"><a class="watch-back" href="<?= az_h($routes['watch']) ?>">All videos</a><div class="watch-player"><video controls playsinline preload="metadata" data-engagement-kind="<?= az_h($video['origin']??'') ?>" data-engagement-id="<?= az_h($video['id']) ?>" data-engagement-csrf="<?= az_h(!$sample ? az_csrf_token() : '') ?>" src="<?= az_h($video['mediaUrl']) ?>"<?php if (!empty($video['coverUrl'])): ?> poster="<?= az_h($video['coverUrl']) ?>"<?php endif; ?> aria-label="<?= az_h($video['title']) ?>">Your browser does not support this video. <a href="<?= az_h($video['mediaUrl']) ?>">Open the video</a>.</video></div><div class="watch-detail-body"><div><p class="watch-eyebrow">THE AZHOTTIE CUT</p><h1><?= az_h($video['title']) ?></h1><p class="watch-meta"><?php if ($video['modelName'] !== ''): ?><span><?= az_h($video['modelName']) ?></span><?php endif; ?><?php if ($video['publishedAt']): ?><time datetime="<?= az_h($video['publishedAt']) ?>"><?= az_h(az_watch_date($video['publishedAt'])) ?></time><?php endif; ?><span><?= az_h(az_watch_duration((int)$video['durationMs'])) ?></span></p><?php if ($video['description'] !== ''): ?><div class="watch-description"><?= nl2br(az_h($video['description'])) ?></div><?php endif; ?></div><aside class="watch-invite"><span class="watch-star" aria-hidden="true">✳</span><h2>Good company.<br>Interesting possibilities.</h2><p>Follow the people behind the brand. Make room for your next AZHottie moment.</p><a class="watch-button" href="<?= az_h($user ? $routes['calendar'] : $routes['signup']) ?>"><?= $user ? 'Explore the calendar' : 'Join the community' ?></a></aside></div></article>
<?php if(!empty($state['commerceProducts'])): ?>
<section class="azh-commerce azh-commerce-watch" aria-label="Products featured in this video">
 <div class="azh-commerce-eyebrow">SHOP THE VIDEO</div><h2>Featured products<span>.</span></h2>
 <div class="azh-commerce-list azh-commerce-grid">
 <?php foreach($state['commerceProducts'] as $p): ?>
 <article class="azh-commerce-card"><p class="azh-commerce-eyebrow">CREATOR-APPROVED PRODUCT</p>
  <h3><?= az_h($p['title']) ?></h3><p><?= az_h($p['description']) ?></p>
  <strong>$<?= az_h(number_format($p['priceCents']/100,2)) ?> USD</strong>
  <?php if(!$p['fulfillment']['inStock']): ?><p>Out of stock</p><?php endif; ?>
  <?php if($p['fulfillment']['shippingEnabled']): ?><p>US shipping available · <?= $p['fulfillment']['shippingRate']==='free'?'Free':('
  <?php if($p['stripeReady'] && $p['fulfillment']['inStock']): ?>
   <form data-stripe-checkout data-csrf="<?= az_h(!$sample?az_csrf_token():'') ?>"
     data-product-id="<?= az_h($p['id']) ?>" data-promotion-id="<?= az_h($p['promotionId']) ?>"
     data-video-id="<?= az_h($p['videoId']) ?>" data-channel="video">
     <button class="azh-commerce-cta" type="submit">Secure Stripe Checkout</button>
     <small role="status" aria-live="polite"></small>
   </form>
  <?php elseif($p['checkoutMode']==='external' && $p['checkoutUrl']!==''): ?>
   <a class="azh-commerce-cta" href="<?= az_h($p['checkoutUrl']) ?>" target="_blank" rel="noopener noreferrer external nofollow">Visit external seller</a>
  <?php endif; ?>
  <?php if(!empty($p['localEnabled']) && $p['fulfillment']['inStock'] && !$sample): ?>
   <button type="button" class="azh-commerce-cta azh-local-checkout-button" data-local-order data-local-enabled="1"
     data-product-id="<?= az_h($p['id']) ?>" data-product-title="<?= az_h($p['title']) ?>"
     data-price-cents="<?= (int)$p['priceCents'] ?>" data-channel="video"
     data-video-id="<?= az_h($p['videoId']) ?>" data-promotion-id="<?= az_h($p['promotionId']) ?>"
     data-csrf="<?= az_h(az_csrf_token()) ?>">Cash pickup / Local delivery</button>
  <?php endif; ?>
 </article>
 <?php endforeach; ?>
 </div>
 <p class="azh-commerce-disclaimer">Product commissions require approved attribution. External seller purchases cannot be verified here.</p>
</section>
<?php endif; ?>
<?php else: ?>
<section class="watch-hero"><div><p class="watch-eyebrow"><span aria-hidden="true">✳</span> ARIZONA ATTITUDE. PRESS PLAY.</p><h1>The stories<br>behind the <em>smile.</em></h1><p>Local faces. Candid conversations. A little mischief.<br>Meet the people making AZHottie their own.</p></div><div class="watch-hero-note" aria-hidden="true"><span>AZHOTTIE</span><b>ON<br>AIR<span>●</span></b><small>PERSONALITY INCLUDED.</small></div></section>
<?php if (!empty($state['featured'])): $feature=$state['featured']; ?><section class="watch-featured" aria-label="Featured video"><p class="watch-eyebrow">EDITOR'S FEATURED PICK</p><h2><?= az_h($feature['headline']) ?></h2><p><?= az_h($feature['copy']) ?></p><a class="watch-button" href="<?= az_h($feature['video']['watchUrl']) ?>">Watch <?= az_h($feature['video']['title']) ?> →</a></section><?php endif; ?>
<section class="watch-library" aria-labelledby="watch-library-heading"><div class="watch-library-heading"><div><p class="watch-eyebrow">TAKE A CLOSER LOOK</p><h2 id="watch-library-heading"><?= $search !== '' ? 'Search results' : 'Fresh from the studio' ?><span>.</span></h2><p class="watch-count"><?= (int)$feed['total'] ?> <?= (int)$feed['total'] === 1 ? 'video' : 'videos' ?><?= $search !== '' ? ' for “' . az_h($search) . '”' : ' to get to know us' ?></p></div><form class="watch-search" method="get" action="<?= az_h($routes['watch']) ?>" role="search"><label for="watch-search">Find a video</label><div><input type="search" id="watch-search" name="search" value="<?= az_h($search) ?>" maxlength="120" placeholder="A name, a story, a little curiosity…"><button type="submit" aria-label="Search videos">Search</button></div></form></div>
<?php if (!$feed['videos']): ?><div class="watch-empty"><span class="watch-star" aria-hidden="true">✳</span><h3><?= $search !== '' ? 'No matches just yet.' : ((int)$feed['total'] > 0 ? 'You’ve reached the end.' : 'The next story is on its way.') ?></h3><p><?= $search !== '' ? 'Try another name or a different keyword.' : ((int)$feed['total'] > 0 ? 'Head back to the beginning to find your next watch.' : 'New videos will appear here when they are ready to share. Check back for a little more Arizona attitude.') ?></p><?php if ($search !== '' || (int)$feed['total'] > 0): ?><a class="watch-button" href="<?= az_h($routes['watch']) ?>">Browse all videos</a><?php endif; ?></div>
<?php else: ?><div class="watch-grid"><?php foreach ($feed['videos'] as $item): ?><article class="watch-card"><a class="watch-card-cover" href="<?= az_h($item['watchUrl']) ?>" aria-label="Watch <?= az_h($item['title']) ?>"><?php if (!empty($item['coverUrl'])): ?><img src="<?= az_h($item['coverUrl']) ?>" alt="" loading="lazy" width="960" height="540"><?php else: ?><div class="watch-cover-placeholder" aria-hidden="true"><span>THE AZHOTTIE CUT</span><b>ALL<br>PERSONALITY<span>✳</span></b><small>AZ / ON CAMERA</small></div><?php endif; ?><span class="watch-play" aria-hidden="true">▶</span><span class="watch-duration"><?= az_h(az_watch_duration((int)$item['durationMs'])) ?></span></a><div class="watch-card-copy"><p class="watch-card-credit"><?= az_h($item['modelName'] !== '' ? $item['modelName'] : 'AZHottie') ?></p><h3><a href="<?= az_h($item['watchUrl']) ?>"><?= az_h($item['title']) ?></a></h3><?php if ($item['description'] !== ''): ?><p class="watch-card-description"><?= az_h($item['description']) ?></p><?php endif; ?><time datetime="<?= az_h($item['publishedAt']) ?>"><?= az_h(az_watch_date($item['publishedAt'])) ?></time></div></article><?php endforeach; ?></div><?php endif; ?>
<?php if ((int)$feed['page'] > 1 || $feed['hasMore']): ?><nav class="watch-pagination" aria-label="Video pages"><?php if ((int)$feed['page'] > 1): ?><a class="watch-button watch-button-light" href="<?= az_h($pageUrl((int)$feed['page'] - 1)) ?>">Previous</a><?php endif; ?><span>Page <?= (int)$feed['page'] ?></span><?php if ($feed['hasMore']): ?><a class="watch-button watch-button-light" href="<?= az_h($pageUrl((int)$feed['page'] + 1)) ?>">Next</a><?php endif; ?></nav><?php endif; ?>
</section>
<section class="watch-community"><div><p class="watch-eyebrow">MORE THAN A SCREEN</p><h2>There’s a place<br>for you <em>here.</em></h2><p>Events, classes, and time with your favorite professionals.<br>One account. Your kind of AZHottie.</p></div><a class="watch-button" href="<?= az_h($user ? $routes['calendar'] : $routes['signup']) ?>"><?= $user ? 'Open my calendar' : 'Join the community' ?></a></section>
<?php endif; ?>
</main><footer class="watch-footer"><a class="watch-wordmark" href="<?= az_h($routes['home']) ?>">AZHOTTIE<span>.</span></a><p>ARIZONA · LIFESTYLE · ENTERTAINMENT</p><span>© <?= date('Y') ?> AZHottie</span></footer>
<?php if ($video && !$sample && $error===''): ?><script type="module" src="assets/watch-engagement.js?v=1.22.0"></script><?php endif; ?><?php if(!empty($state['commerceProducts']) && !$sample): ?><script type="module" src="assets/commerce-checkout.js?v=1.31.0"></script><script type="module" src="assets/commerce-local.js?v=1.32.0"></script><?php endif; ?></body></html><?php
}
.az_h(number_format($p['fulfillment']['shippingFeeCents']/100,2))) ?> · <?= (int)$p['fulfillment']['handlingDays'] ?>-day handling</p><?php endif; ?>
  <?php if($p['stripeReady']): ?>
   <form data-stripe-checkout data-csrf="<?= az_h(!$sample?az_csrf_token():'') ?>"
     data-product-id="<?= az_h($p['id']) ?>" data-promotion-id="<?= az_h($p['promotionId']) ?>"
     data-video-id="<?= az_h($p['videoId']) ?>" data-channel="video">
     <button class="azh-commerce-cta" type="submit">Secure Stripe Checkout</button>
     <small role="status" aria-live="polite"></small>
   </form>
  <?php elseif($p['checkoutMode']==='external' && $p['checkoutUrl']!==''): ?>
   <a class="azh-commerce-cta" href="<?= az_h($p['checkoutUrl']) ?>" target="_blank" rel="noopener noreferrer external nofollow">Visit external seller</a>
  <?php endif; ?>
  <?php if(!empty($p['localEnabled']) && !$sample): ?>
   <button type="button" class="azh-commerce-cta azh-local-checkout-button" data-local-order data-local-enabled="1"
     data-product-id="<?= az_h($p['id']) ?>" data-product-title="<?= az_h($p['title']) ?>"
     data-price-cents="<?= (int)$p['priceCents'] ?>" data-channel="video"
     data-video-id="<?= az_h($p['videoId']) ?>" data-promotion-id="<?= az_h($p['promotionId']) ?>"
     data-csrf="<?= az_h(az_csrf_token()) ?>">Cash pickup / Local delivery</button>
  <?php endif; ?>
 </article>
 <?php endforeach; ?>
 </div>
 <p class="azh-commerce-disclaimer">Product commissions require approved attribution. External seller purchases cannot be verified here.</p>
</section>
<?php endif; ?>
<?php else: ?>
<section class="watch-hero"><div><p class="watch-eyebrow"><span aria-hidden="true">✳</span> ARIZONA ATTITUDE. PRESS PLAY.</p><h1>The stories<br>behind the <em>smile.</em></h1><p>Local faces. Candid conversations. A little mischief.<br>Meet the people making AZHottie their own.</p></div><div class="watch-hero-note" aria-hidden="true"><span>AZHOTTIE</span><b>ON<br>AIR<span>●</span></b><small>PERSONALITY INCLUDED.</small></div></section>
<?php if (!empty($state['featured'])): $feature=$state['featured']; ?><section class="watch-featured" aria-label="Featured video"><p class="watch-eyebrow">EDITOR'S FEATURED PICK</p><h2><?= az_h($feature['headline']) ?></h2><p><?= az_h($feature['copy']) ?></p><a class="watch-button" href="<?= az_h($feature['video']['watchUrl']) ?>">Watch <?= az_h($feature['video']['title']) ?> →</a></section><?php endif; ?>
<section class="watch-library" aria-labelledby="watch-library-heading"><div class="watch-library-heading"><div><p class="watch-eyebrow">TAKE A CLOSER LOOK</p><h2 id="watch-library-heading"><?= $search !== '' ? 'Search results' : 'Fresh from the studio' ?><span>.</span></h2><p class="watch-count"><?= (int)$feed['total'] ?> <?= (int)$feed['total'] === 1 ? 'video' : 'videos' ?><?= $search !== '' ? ' for “' . az_h($search) . '”' : ' to get to know us' ?></p></div><form class="watch-search" method="get" action="<?= az_h($routes['watch']) ?>" role="search"><label for="watch-search">Find a video</label><div><input type="search" id="watch-search" name="search" value="<?= az_h($search) ?>" maxlength="120" placeholder="A name, a story, a little curiosity…"><button type="submit" aria-label="Search videos">Search</button></div></form></div>
<?php if (!$feed['videos']): ?><div class="watch-empty"><span class="watch-star" aria-hidden="true">✳</span><h3><?= $search !== '' ? 'No matches just yet.' : ((int)$feed['total'] > 0 ? 'You’ve reached the end.' : 'The next story is on its way.') ?></h3><p><?= $search !== '' ? 'Try another name or a different keyword.' : ((int)$feed['total'] > 0 ? 'Head back to the beginning to find your next watch.' : 'New videos will appear here when they are ready to share. Check back for a little more Arizona attitude.') ?></p><?php if ($search !== '' || (int)$feed['total'] > 0): ?><a class="watch-button" href="<?= az_h($routes['watch']) ?>">Browse all videos</a><?php endif; ?></div>
<?php else: ?><div class="watch-grid"><?php foreach ($feed['videos'] as $item): ?><article class="watch-card"><a class="watch-card-cover" href="<?= az_h($item['watchUrl']) ?>" aria-label="Watch <?= az_h($item['title']) ?>"><?php if (!empty($item['coverUrl'])): ?><img src="<?= az_h($item['coverUrl']) ?>" alt="" loading="lazy" width="960" height="540"><?php else: ?><div class="watch-cover-placeholder" aria-hidden="true"><span>THE AZHOTTIE CUT</span><b>ALL<br>PERSONALITY<span>✳</span></b><small>AZ / ON CAMERA</small></div><?php endif; ?><span class="watch-play" aria-hidden="true">▶</span><span class="watch-duration"><?= az_h(az_watch_duration((int)$item['durationMs'])) ?></span></a><div class="watch-card-copy"><p class="watch-card-credit"><?= az_h($item['modelName'] !== '' ? $item['modelName'] : 'AZHottie') ?></p><h3><a href="<?= az_h($item['watchUrl']) ?>"><?= az_h($item['title']) ?></a></h3><?php if ($item['description'] !== ''): ?><p class="watch-card-description"><?= az_h($item['description']) ?></p><?php endif; ?><time datetime="<?= az_h($item['publishedAt']) ?>"><?= az_h(az_watch_date($item['publishedAt'])) ?></time></div></article><?php endforeach; ?></div><?php endif; ?>
<?php if ((int)$feed['page'] > 1 || $feed['hasMore']): ?><nav class="watch-pagination" aria-label="Video pages"><?php if ((int)$feed['page'] > 1): ?><a class="watch-button watch-button-light" href="<?= az_h($pageUrl((int)$feed['page'] - 1)) ?>">Previous</a><?php endif; ?><span>Page <?= (int)$feed['page'] ?></span><?php if ($feed['hasMore']): ?><a class="watch-button watch-button-light" href="<?= az_h($pageUrl((int)$feed['page'] + 1)) ?>">Next</a><?php endif; ?></nav><?php endif; ?>
</section>
<section class="watch-community"><div><p class="watch-eyebrow">MORE THAN A SCREEN</p><h2>There’s a place<br>for you <em>here.</em></h2><p>Events, classes, and time with your favorite professionals.<br>One account. Your kind of AZHottie.</p></div><a class="watch-button" href="<?= az_h($user ? $routes['calendar'] : $routes['signup']) ?>"><?= $user ? 'Open my calendar' : 'Join the community' ?></a></section>
<?php endif; ?>
</main><footer class="watch-footer"><a class="watch-wordmark" href="<?= az_h($routes['home']) ?>">AZHOTTIE<span>.</span></a><p>ARIZONA · LIFESTYLE · ENTERTAINMENT</p><span>© <?= date('Y') ?> AZHottie</span></footer>
<?php if ($video && !$sample && $error===''): ?><script type="module" src="assets/watch-engagement.js?v=1.22.0"></script><?php endif; ?><?php if(!empty($state['commerceProducts']) && !$sample): ?><script type="module" src="assets/commerce-checkout.js?v=1.31.0"></script><script type="module" src="assets/commerce-local.js?v=1.32.0"></script><?php endif; ?></body></html><?php
}
