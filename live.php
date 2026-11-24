<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/universal-header.php';
require_once __DIR__.'/includes/member-layout.php';
$user=az_require_auth();header('Cache-Control: no-store');header('X-Robots-Tag: noindex,nofollow');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>AZHottie Live · Members</title><link rel="icon" href="assets/favicon.svg"><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/member-layout.css"><link rel="stylesheet" href="assets/live-studio.css?v=1.50.0"><link rel="stylesheet" href="assets/commerce.css?v=1.35.0"><link rel="stylesheet" href="assets/commerce-shipping-checkout.css?v=1.35.0"><link rel="stylesheet" href="assets/commerce-local.css?v=1.32.0"></head>
<body data-page="live" data-local-csrf="<?= az_h(az_csrf_token()) ?>"><?php az_render_universal_header('live',$user);az_render_member_layout_open('live',$user); ?>
<main class="azh-fan-live" id="azh-fan-live" data-csrf="<?= az_h(az_csrf_token()) ?>">
  <section class="azh-fan-player" aria-label="Live AZHottie broadcast"><div class="azh-fan-screen"><video id="fan-live-video" playsinline autoplay muted controls aria-label="AZHottie live presentation" hidden></video><div id="fan-live-offline" class="azh-fan-offline"><span aria-hidden="true">✳</span><strong>Nothing on air right now.</strong><p>Watch the latest live productions below, or join when the Director goes live.</p></div><span id="fan-live-pill" class="azh-fan-pill" hidden>● LIVE</span></div><div class="azh-fan-bar"><div><strong id="fan-live-title">AZHottie Live</strong><p id="fan-live-status" role="status" aria-live="polite">Checking live studio…</p></div><button type="button" class="azh-live-button azh-live-secondary" id="fan-live-sound" aria-pressed="false" hidden>Unmute sound</button><span id="fan-live-recovery" class="azh-fan-recovery" role="status" aria-live="polite" hidden></span><button type="button" class="azh-live-button" id="fan-live-join" hidden>Join live</button><button type="button" class="azh-live-button azh-live-secondary" id="fan-live-leave" hidden>Leave</button></div></section>
  <section id="azh-live-commerce" class="azh-live-commerce" aria-label="Featured live product" data-csrf="<?= az_h(az_csrf_token()) ?>" hidden><span class="azh-eyebrow">SHOP THIS LIVE</span><h2 data-commerce-title></h2><p data-commerce-description></p><p><strong data-commerce-price></strong></p><p data-commerce-shipping></p><a data-commerce-checkout rel="noopener noreferrer external nofollow" target="_blank">Visit seller checkout ↗</a>
<form hidden data-stripe-checkout data-csrf="<?= az_h(az_csrf_token()) ?>" data-channel="live">
<button class="azh-commerce-cta" type="submit">Secure Stripe Checkout</button><small role="status" aria-live="polite"></small>
</form>
<button type="button" class="azh-commerce-cta azh-local-checkout-button" data-local-order data-local-enabled="0"
 data-channel="live" data-csrf="<?= az_h(az_csrf_token()) ?>" hidden>Cash Pickup / Local Delivery</button>
</section>
  <details id="fan-live-engagement" class="azh-fan-engagement" hidden><summary>Reactions and live member chat</summary>
    <div class="azh-fan-reaction-row" aria-label="React to the live show">
      <button type="button" data-fan-reaction="heart">♥ <span data-reaction-count="heart">0</span></button>
      <button type="button" data-fan-reaction="applause">👏 <span data-reaction-count="applause">0</span></button>
      <button type="button" data-fan-reaction="fire">🔥 <span data-reaction-count="fire">0</span></button>
    </div>
    <div id="fan-chat-messages" class="azh-fan-chat-messages" aria-label="Member live chat messages"></div>
    <form id="fan-chat-form" class="azh-fan-chat-compose"><label for="fan-chat-text">Live chat</label><input id="fan-chat-text" maxlength="320" placeholder="Say something supportive…" autocomplete="off"><button type="submit" class="azh-live-button">Send</button></form>
    <p id="fan-chat-status" role="status" aria-live="polite">Chat is disabled until the Director enables it.</p>
  </details>
  <section class="azh-live-archive" aria-label="Recorded AZHottie live productions"><div class="azh-live-subhead"><h2>Previously recorded live shows</h2><a href="live-shows.php" class="azh-live-button azh-live-secondary">Public show gallery</a><button id="fan-archive-refresh" type="button" class="azh-live-button azh-live-secondary">Refresh</button></div><p id="fan-archive-status" class="azh-live-archive-status" role="status">Loading recorded broadcasts…</p><div id="fan-archive-grid" class="azh-live-archive-grid"></div><div id="fan-replay-panel" class="azh-fan-replay" hidden><div class="azh-live-subhead"><h3 id="fan-replay-title">Recorded show</h3><button type="button" class="azh-live-button azh-live-secondary" id="fan-replay-close">Close replay</button></div><video id="fan-replay-video" controls playsinline preload="metadata" aria-label="Previously recorded AZHottie broadcast"></video><p id="fan-replay-description"></p></div></section>
</main>
<?php az_render_member_layout_close(); ?>
<script type="module" src="assets/live-viewer.js?v=1.56.0"></script><script type="module" src="assets/commerce-live.js?v=1.41.0"></script><script type="module" src="assets/commerce-checkout.js?v=1.35.0"></script><script type="module" src="assets/commerce-shipping-checkout.js?v=1.35.0"></script><script type="module" src="assets/commerce-local.js?v=1.32.0"></script></body></html>
