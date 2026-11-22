<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/universal-header.php';
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex,nofollow');
$user=az_is_installed()?az_current_user():null;
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Return or Exchange Request · AZHottie</title>
<link rel="icon" href="assets/favicon.svg">
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/universal-header.css?v=1.18.1">
<link rel="stylesheet" href="assets/commerce.css?v=1.35.0">
<link rel="stylesheet" href="assets/commerce-returns.css?v=1.36.0"></head>
<body data-page="returns"><?php az_render_universal_header('shop',$user); ?>
<main class="azh-commerce azh-commerce-public azh-return-public" id="azh-return-public"
 data-csrf="<?= az_h(az_csrf_token()) ?>">
 <header class="azh-commerce-hero"><div>
  <p class="azh-eyebrow">AZHOTTIE · MERCHANDISE SUPPORT</p>
  <h1>Returns &amp; Exchanges<span>.</span></h1>
  <p>Submit a request for a merchandise order. Store staff will verify the purchase, eligibility, and next steps before approving any return, exchange, or refund.</p>
 </div><a class="azh-commerce-link" href="shop.php">Back to Shop</a></header>
 <div class="azh-commerce-editor">
  <form id="azh-return-public-form" class="azh-commerce-form">
    <label>Order reference from your receipt
      <input name="reference" maxlength="120" minlength="6" placeholder="Your original order number" required autocomplete="off"></label>
    <label>Contact email
      <input name="email" maxlength="254" type="email" autocomplete="email" placeholder="Email to coordinate the return" required></label>
    <div class="azh-commerce-row">
      <label>Request type<select name="kind" required>
        <option value="refund">Return for refund review</option>
        <option value="exchange">Exchange the same product</option>
      </select></label>
      <label>Quantity<input name="quantity" type="number" min="1" max="100" step="1" value="1" required></label>
    </div>
    <label>Reason<textarea name="reason" rows="4" minlength="8" maxlength="500"
      placeholder="Briefly describe the issue. Do not include card numbers, passwords or sensitive personal information." required></textarea></label>
    <button type="submit">Submit return or exchange request</button>
    <p id="azh-return-public-status" role="status" aria-live="polite"></p>
  </form>
 </div>
 <p class="azh-commerce-disclaimer">Submitting a request does not establish order ownership, authorize shipping goods back, initiate a refund, or start a replacement. Staff will review it under the merchant's return policy and applicable consumer protections. Never send payment details through this form.</p>
</main>
<script type="module" src="assets/commerce-return-public.js?v=1.36.0"></script></body></html>
