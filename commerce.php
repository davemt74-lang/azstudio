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
<link rel="stylesheet" href="assets/commerce.css?v=1.36.0"><link rel="stylesheet" href="assets/commerce-local.css?v=1.32.0">
<link rel="stylesheet" href="assets/commerce-returns.css?v=1.36.0">
<link rel="stylesheet" href="assets/commerce-providers.css?v=1.38.0"></head>
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
    <?php if($admin): ?><button type="button" role="tab" data-tab="returns" aria-selected="false">Returns &amp; Exchanges</button><?php endif; ?>
    <button type="button" role="tab" data-tab="payouts" aria-selected="false">Creator Payouts</button>
    <?php if($admin): ?><button type="button" role="tab" data-tab="providers" aria-selected="false">Provider readiness</button><?php endif; ?>
    <button type="button" role="tab" data-tab="analytics" aria-selected="false">Sales analytics</button>
    <?php if($admin): ?><button type="button" role="tab" data-tab="shipping" aria-selected="false">Shipping</button><?php endif; ?>
    <?php if($admin): ?><button type="button" role="tab" data-tab="local" aria-selected="false">Local Orders</button><?php endif; ?>
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
        <label>Checkout provider<select name="checkoutMode"><option value="external">External seller · manual order reconciliation</option><option value="stripe">Stripe Checkout · verified webhooks</option></select></label>
        <label>External checkout URL (HTTPS)<input name="checkoutUrl" type="url" maxlength="500" placeholder="https://your-seller.example/checkout" autocomplete="off"></label>
        <p class="azh-commerce-hint">Stripe Checkout requires private server keys. If this product offers only cash pickup/delivery, its external checkout URL may be left blank.</p>
        <details id="azh-product-local-settings" class="azh-commerce-product-local">
          <summary><strong>Cash payment &amp; local fulfillment for this product</strong><span>Optional · per-product controls</span></summary>
          <div class="azh-commerce-local-fields">
            <input type="hidden" name="localRevision" value="0">
            <label class="azh-commerce-checkbox"><input name="localEnabled" type="checkbox"> Offer pay-with-cash on this product</label>
            <label class="azh-commerce-checkbox"><input name="pickupEnabled" type="checkbox"> Allow cash at local pickup</label>
            <label>Pickup location name<input name="pickupLocation" maxlength="160" placeholder="My shop / studio"></label>
            <label>Pickup address shown to buyers<input name="pickupAddress" maxlength="320" placeholder="Pickup address"></label>
            <label>Pickup hours / instructions<input name="pickupHours" maxlength="200" placeholder="By appointment · staff confirmation required"></label>
            <label>Extra pickup instructions<textarea name="pickupInstructions" maxlength="500" rows="2"></textarea></label>
            <label class="azh-commerce-checkbox"><input name="deliveryEnabled" type="checkbox"> Allow cash on local delivery</label>
            <label>Delivery ZIP codes for this product<input name="deliveryZips" maxlength="1500" placeholder="85001, 85003, 85004"></label>
            <div class="azh-commerce-row">
              <label>Delivery state<input name="deliveryState" maxlength="2" value="AZ" required></label>
              <label>Delivery fee for this product (USD)<input name="deliveryFeeDollars" type="number" step="0.01" min="0" max="10000" value="0.00" required></label>
            </div>
            <label>Minimum merchandise subtotal for delivery (USD)<input name="deliveryMinDollars" type="number" step="0.01" min="0" max="1000000" value="0.00" required></label>
            <label>Delivery instructions<textarea name="deliveryInstructions" maxlength="500" rows="2" placeholder="Local delivery details and limitations"></textarea></label>
            <p class="azh-commerce-hint">Each product has independent cash availability, pickup address, ZIP coverage, delivery fees and minimums. Buyers submit unpaid requests. Staff must verify any applicable tax and confirm the final amount before acceptance.</p>
          </div>
        </details>
<details id="azh-product-shipping-settings" class="azh-commerce-product-local">
          <summary><strong>Inventory &amp; Carrier Shipping for this product</strong><span>Stock, SKU, shipping price &amp; parcel</span></summary>
          <div class="azh-commerce-local-fields">
            <input type="hidden" name="stockRevision" value="0">
            <label>Product SKU<input name="sku" maxlength="64" placeholder="MERCH-001"></label>
            <label>Inventory mode<select name="stockMode">
              <option value="unlimited">Unlimited · no stock limit</option>
              <option value="tracked">Track available units</option>
              <option value="made_to_order">Made to order</option>
            </select></label>
            <div class="azh-commerce-row">
              <label>On-hand quantity<input name="stockOnHand" type="number" min="0" max="100000000" step="1" value="0" required></label>
              <label>Low-stock warning at<input name="lowStockThreshold" type="number" min="0" max="100000" step="1" value="3" required></label>
            </div>
            <p class="azh-commerce-hint" id="azh-shipping-stock-snapshot">Reserved: 0 · Available: unlimited. Inventory edits to an existing product require a reason.</p>
            <label>Reason for stock adjustment (if on-hand quantity changed)<input name="stockAdjustmentReason" maxlength="180" placeholder="Received new stock / cycle count"></label>
            <label class="azh-commerce-checkbox"><input type="checkbox" name="shippingEnabled"> Offer prepaid US carrier shipping for this product</label>
            <label>Shipping price<select name="shippingRate">
              <option value="flat">Flat rate (with optional free-shipping threshold)</option>
              <option value="free">Always free shipping</option>
              <option value="carrier">Live USPS/UPS/FedEx rates chosen by customer at checkout</option>
            </select></label>
            <div class="azh-commerce-row">
              <label>Flat shipping fee (USD)<input name="shippingFeeDollars" type="number" min="0" max="10000" step="0.01" value="0.00" required></label>
              <label>Free shipping from merchandise subtotal (USD, 0 = no threshold)<input name="freeAboveDollars" type="number" min="0" max="1000000" step="0.01" value="0.00" required></label>
            </div>
            <div class="azh-commerce-row">
              <label>Weight (grams)<input name="weightGrams" type="number" min="0" max="100000" step="1" value="0" required></label>
              <label>Handling time (days)<input name="handlingDays" type="number" min="1" max="30" step="1" value="3" required></label>
            </div>
            <div class="azh-commerce-row">
              <label>Length (millimeters)<input name="lengthMm" type="number" min="0" max="3000" step="1" value="0" required></label>
              <label>Width (millimeters)<input name="widthMm" type="number" min="0" max="3000" step="1" value="0" required></label>
            </div>
            <label>Height (millimeters)<input name="heightMm" type="number" min="0" max="3000" step="1" value="0" required></label>
            <input type="hidden" name="shipCountry" value="US">
            <p class="azh-commerce-hint">Each product may charge flat/free shipping or let customers choose live USPS/UPS/FedEx rates before Stripe Checkout. Carrier rates require matching private Stripe and EasyPost test/live accounts, then staff separately approve and buy labels. Shipping charges do not contribute to creator commission.</p>
          </div>
        </details>
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

   <?php if($admin): ?>
   <section class="azh-commerce-panel" data-panel="returns" aria-label="Returns and exchanges" hidden>
     <div id="azh-returns-admin" data-csrf="<?= az_h(az_csrf_token()) ?>">
       <div class="azh-commerce-editor">
         <h2>Returns &amp; Exchanges</h2>
         <p>Verify customer ownership before authorizing a return. Inspected stock is restocked only after physical review. Refunding money is a separate Stripe, cash, or seller operation.</p>
         <div class="azh-commerce-actions">
           <button type="button" id="azh-returns-refresh" class="azh-commerce-small">Refresh returns</button>
           <a href="returns.php" class="azh-commerce-link">Customer request page</a>
         </div>
         <p id="azh-return-summary" class="azh-return-payout-banner"></p>
         <p id="azh-returns-status" role="status" aria-live="polite"></p>
       </div>
       <div class="azh-commerce-editor">
         <h3>Staff return intake</h3>
         <form class="azh-commerce-form" id="azh-return-intake">
           <label>Paid order<select name="orderId" required><option value="">Loading paid orders…</option></select></label>
           <div class="azh-commerce-row">
             <label>Requested resolution<select name="kind"><option value="refund">Refund review</option><option value="exchange">Same-product exchange</option></select></label>
             <label>Quantity<input type="number" name="quantity" min="1" max="100" step="1" value="1" required></label>
           </div>
           <label>Reason<textarea name="reason" rows="2" maxlength="500" minlength="8" required placeholder="Evidence and return reason. Do not enter payment card data."></textarea></label>
           <button type="submit">Record unapproved return request</button>
         </form>
       </div>
       <div class="azh-commerce-list" id="azh-returns-list"></div>
       <div id="azh-returns-private" class="azh-commerce-editor" hidden></div>
     </div>
   </section>
   <?php endif; ?>
   <section class="azh-commerce-panel" data-panel="payouts" aria-label="Creator commission settlements" hidden>
     <div id="azh-payout-workspace" data-admin="<?= $admin?'1':'0' ?>"
        data-csrf="<?= az_h(az_csrf_token()) ?>">
       <div class="azh-commerce-editor">
         <h2>Creator Commission Settlement</h2>
         <p>Commissions remain estimated until refundable sales, return holds, payment disputes, delivery and holding periods clear. Staff may approve and record external payouts, but AZHottie never sends funds automatically.</p>
         <div class="azh-commerce-actions">
           <button type="button" class="azh-commerce-small" id="azh-payout-refresh">Refresh settlement ledger</button>
         </div>
         <p id="azh-payout-status" role="status" aria-live="polite"></p>
         <div id="azh-payout-summary" class="azh-return-kpi-grid"></div>
       </div>
       <?php if($admin): ?>
       <div class="azh-commerce-editor">
         <h3>New payout draft</h3>
         <form id="azh-payout-draft" class="azh-commerce-form">
           <label>Creator<select name="modelId" required><option value="">Loading eligible creators…</option></select></label>
           <button type="submit">Draft eligible external commission payout</button>
         </form>
         <p class="azh-return-subtle">Drafts reserve the full eligible balance. Approval and verified external payment evidence are separate steps. Refund-related overpayments block new drafts.</p>
       </div>
       <div id="azh-payout-models" class="azh-commerce-list"></div>
       <?php else: ?>
       <div id="azh-payout-model-details" class="azh-commerce-list"></div>
       <?php endif; ?>
       <h3>Settlement history</h3>
       <div id="azh-payout-batches" class="azh-commerce-list"></div>
     </div>
   </section>
  <?php if($admin): ?>
  <section class="azh-commerce-panel" data-panel="providers" aria-label="Provider readiness" hidden>
    <div class="azh-commerce-editor" id="azh-provider-readiness">
      <h2>Provider Production Readiness</h2>
      <p>Staff-only, read-only configuration checks and reconciliation queue. No refunds, label purchases or creator transfers are initiated here.</p>
      <div class="azh-commerce-actions">
        <button type="button" class="azh-commerce-small" data-readiness-refresh>Refresh readiness</button>
      </div>
      <p data-readiness-status role="status" aria-live="polite">Loading provider readiness…</p>
      <div data-readiness-results></div>
    </div>
  </section>
  <?php endif; ?>
  <section class="azh-commerce-panel" data-panel="analytics" aria-label="Commerce performance" hidden>
    <div id="commerce-analytics" class="azh-commerce-analytics" aria-live="polite"></div>
  </section>
  <?php if($admin): ?>
  <section class="azh-commerce-panel" data-panel="shipping" aria-label="Carrier shipping orders" hidden>
    <section id="azh-shipping-admin" data-csrf="<?= az_h(az_csrf_token()) ?>">
      <div class="azh-commerce-editor"><h2>Carrier Shipping &amp; Fulfillment</h2>
        <p>Review paid orders, prepare parcels, enter a real carrier and tracking number, then record delivery. Address details are private to staff. This version does not purchase shipping labels or query carrier tracking scans.</p>
        <div class="azh-commerce-actions">
          <button type="button" id="azh-shipping-refresh" class="azh-commerce-small">Refresh shipments</button>
          <button type="button" id="azh-shipping-edit-products" class="azh-commerce-small">Edit shipping settings</button>
        </div>
        <p id="azh-shipping-counts"></p>
        <p id="azh-carrier-status" role="status" aria-live="polite">Checking carrier provider configuration…</p>
        <p id="azh-shipping-status" role="status" aria-live="polite"></p>
      </div>
      <div id="azh-shipping-orders" class="azh-commerce-list"></div>
      <section class="azh-commerce-editor" id="azh-shipping-private" aria-label="Private shipment destination" hidden></section>
    </section>
  </section>
  <?php endif; ?>
  <?php if($admin): ?>
  <section class="azh-commerce-panel" data-panel="local" aria-label="Local cash order management" hidden>
    <div id="azh-local-admin" data-csrf="<?= az_h(az_csrf_token()) ?>">
      <div class="azh-commerce-editor">
        <h2>Cash Pickup &amp; Delivery Orders</h2>
        <p>Configure payment and delivery independently inside each product's editor. Here, review requests, confirm taxes and totals, collect cash, and track fulfillment.</p>
        <div class="azh-commerce-actions">
          <button type="button" id="azh-local-refresh" class="azh-commerce-small">Refresh cash orders</button>
          <button type="button" id="azh-local-edit-products" class="azh-commerce-small">Manage product settings</button>
        </div>
        <p id="azh-local-queue-summary" role="status"></p>
        <p id="azh-local-admin-status" role="status" aria-live="polite"></p>
      </div>
      <div id="azh-local-orders-list" class="azh-commerce-list"></div>
      <div id="azh-local-private-details" class="azh-commerce-editor" hidden></div>
    </div>
  </section>
  <?php endif; ?>
  <?php if($admin): ?><section class="azh-commerce-panel" data-panel="live" aria-label="Live shopping" hidden>
    <div class="azh-commerce-editor"><h2>Feature a product during Live</h2>
      <p>Start a broadcast in Live Director, then choose an active, checkout-ready product. Viewers will see that product beside the Live player while the session is on air.</p>
      <form id="commerce-live-form" class="azh-commerce-form"><label>Featured product<select name="productId" data-active-products required></select></label>
      <label>Approved creator video for attribution (optional)<select name="promotionId" data-live-promotions><option value="">No creator attribution</option></select></label>
      <button type="submit">Feature product on Live</button></form>
      <p id="commerce-live-current"></p></div>
  </section><?php endif; ?>
  <p class="azh-commerce-disclaimer">External orders remain administrator-attested; Stripe orders require signed payment-provider events. Commissions remain estimated and no automatic payouts occur. The Agent cannot approve, sell, pay or refund without an authorized human action.</p>
</main>
<?php az_render_member_layout_close(); ?><script type="module" src="assets/commerce.js?v=1.36.0"></script><?php if($admin): ?><script type="module" src="assets/commerce-local-admin.js?v=1.32.0"></script><script type="module" src="assets/commerce-fulfillment-admin.js?v=1.33.0"></script>
<script type="module" src="assets/commerce-carrier-admin.js?v=1.34.0"></script><?php endif; ?>
<?php if($admin): ?><script type="module" src="assets/commerce-returns-admin.js?v=1.37.0"></script><?php endif; ?>
<script type="module" src="assets/commerce-settlements.js?v=1.37.0"></script>
<script type="module" src="assets/commerce-provider-actions.js?v=1.37.0"></script>
<?php if($admin): ?><script type="module" src="assets/commerce-provider-readiness.js?v=1.38.0"></script><?php endif; ?>
</body></html>
