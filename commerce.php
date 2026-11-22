<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/universal-header.php';
require_once __DIR__.'/includes/member-layout.php';
$user=az_require_auth();
az_agent_workspace_assert($user);
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex,nofollow');
$admin=az_has_role($user,'admin');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Commerce &amp; Creator Briefs · AZHottie</title><meta name="robots" content="noindex,nofollow">
<link rel="icon" href="assets/favicon.svg"><link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/member-layout.css">
<link rel="stylesheet" href="assets/commerce.css?v=1.30.0"></head>
<body data-page="commerce"><?php az_render_universal_header('commerce',$user);az_render_member_layout_open('commerce',$user); ?>
<main class="azh-commerce" id="azh-commerce" data-role="<?= $admin?'admin':'model' ?>" data-csrf="<?= az_h(az_csrf_token()) ?>">
  <header class="azh-commerce-hero"><div><p class="azh-eyebrow">AZHOTTIE · COMMERCE AGENT</p><h1>Commerce<span>.</span></h1>
  <p>Products, creator opportunities, approved video promotions, and attributed commissions in one workspace.</p></div>
  <a class="azh-commerce-link" href="shop.php">View storefront ↗</a></header>
  <p class="azh-commerce-status" id="commerce-status" role="status" aria-live="polite">Loading verified commerce records…</p>
  <nav class="azh-commerce-tabs" role="tablist" aria-label="Commerce sections">
    <button type="button" role="tab" data-tab="products" aria-selected="true">Products</button>
    <button type="button" role="tab" data-tab="briefs" aria-selected="false">Creator briefs</button>
    <button type="button" role="tab" data-tab="promotions" aria-selected="false">Video affiliates</button>
    <button type="button" role="tab" data-tab="orders" aria-selected="false">Orders &amp; earnings</button>
    <?php if($admin): ?><button type="button" role="tab" data-tab="live" aria-selected="false">Live shopping</button><?php endif; ?>
  </nav>
  <section class="azh-commerce-panel" data-panel="products" aria-label="Products">
    <?php if($admin): ?><div class="azh-commerce-editor"><h2>Create or edit product</h2>
      <form id="commerce-product-form" class="azh-commerce-form">
        <input type="hidden" name="id"><input type="hidden" name="revision">
        <label>Product name<input name="title" maxlength="160" required></label>
        <label>Description<textarea name="description" maxlength="4000" rows="3"></textarea></label>
        <div class="azh-commerce-row"><label>Price (USD)<input name="priceDollars" type="number" min="0.01" max="1000000" step="0.01" required></label>
          <label>Creator commission (%)<input name="commissionPercent" type="number" min="0" max="50" step="0.01" value="10" required></label></div>
        <label>Hosted checkout URL (HTTPS)<input name="checkoutUrl" type="url" maxlength="500" placeholder="https://your-checkout-provider.example/…" autocomplete="off"></label>
        <label>Visibility<select name="state"><option value="draft">Draft</option><option value="active">Active</option><option value="archived">Archived</option></select></label>
        <div class="azh-commerce-actions"><button type="submit">Save product</button><button type="reset" data-reset-product>New product</button></div>
      </form></div><?php endif; ?>
    <div class="azh-commerce-list" id="commerce-products-list"></div>
  </section>
  <section class="azh-commerce-panel" data-panel="briefs" aria-label="Creator briefs" hidden>
    <?php if($admin): ?><div class="azh-commerce-editor"><h2>Creator brief</h2>
      <form id="commerce-brief-form" class="azh-commerce-form">
        <label>Product<select name="productId" data-products required></select></label>
        <label>Brief title<input name="title" maxlength="160" required></label>
        <label>Creator instructions<textarea name="instructions" rows="5" maxlength="5000" required placeholder="Content deliverables, shot guidance, eligibility, messaging and expectations"></textarea></label>
        <label>Status<select name="state"><option value="closed">Closed / private</option><option value="active">Active · notify models</option></select></label>
        <div class="azh-commerce-actions"><button type="submit">Save creator brief</button></div>
      </form></div><?php endif; ?>
    <div id="commerce-brief-list" class="azh-commerce-list"></div>
    <?php if($admin): ?><div class="azh-commerce-editor"><h2>Creator interest approvals</h2><div id="commerce-interest-list" class="azh-commerce-list"></div></div><?php endif; ?>
  </section>
  <section class="azh-commerce-panel" data-panel="promotions" aria-label="Video product affiliations" hidden>
    <?php if(!$admin): ?><div class="azh-commerce-editor"><h2>Submit an approved video</h2>
      <p>Only your published, consented videos may be proposed for a product you have been approved to promote. An administrator must approve the video association.</p>
      <form id="commerce-promotion-form" class="azh-commerce-form"><label>Approved product<select name="productId" data-approved-products required></select></label>
      <label>Your published video<select name="videoId" data-videos required></select></label><button type="submit">Request video approval</button></form>
      </div><?php endif; ?>
    <div id="commerce-promotion-list" class="azh-commerce-list"></div>
  </section>
  <section class="azh-commerce-panel" data-panel="orders" aria-label="Order reconciliation and earnings" hidden>
    <?php if($admin): ?><div class="azh-commerce-editor"><h2>Record an external checkout order</h2>
      <p>Record orders using genuine provider references. Payment is not verified or captured by this site. Commission is assigned only to approved video attribution at recording time.</p>
      <form id="commerce-order-form" class="azh-commerce-form">
        <label>Product<select name="productId" data-products required></select></label>
        <label>External provider order reference<input name="externalReference" minlength="6" maxlength="120" required></label>
        <label>Approved video attribution (optional)<select name="promotionId" data-promotions></select></label>
        <label>Quantity<input name="quantity" type="number" min="1" max="100" value="1" required></label>
        <button type="submit">Record pending order</button>
      </form></div><?php endif; ?>
    <div id="commerce-orders-list" class="azh-commerce-list"></div>
  </section>
  <?php if($admin): ?><section class="azh-commerce-panel" data-panel="live" aria-label="Live shopping" hidden>
    <div class="azh-commerce-editor"><h2>Feature a product during Live</h2>
      <p>Start a broadcast in Live Director, then choose an active, checkout-ready product. Viewers will see that product beside the Live player while the session is on air.</p>
      <form id="commerce-live-form" class="azh-commerce-form"><label>Featured product<select name="productId" data-active-products required></select></label>
      <button type="submit">Feature product on Live</button></form>
      <p id="commerce-live-current"></p></div>
  </section><?php endif; ?>
  <p class="azh-commerce-disclaimer">Orders, payments and refunds shown here are administrator-entered records based on external provider evidence. Commissions are estimated, not paid out by AZHottie. The Agent cannot approve, sell, pay or refund without an authorized human action.</p>
</main>
<?php az_render_member_layout_close(); ?><script type="module" src="assets/commerce.js?v=1.30.0"></script>
</body></html>
