<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/universal-header.php';
require_once __DIR__.'/includes/member-layout.php';
$user=az_live_require_editor();header('Cache-Control: no-store');header('X-Robots-Tag: noindex,nofollow');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Saved Live Productions · AZHottie</title><link rel="icon" href="assets/favicon.svg"><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/member-layout.css"><link rel="stylesheet" href="assets/live-studio.css?v=1.18.0"></head>
<body data-page="live-productions"><?php az_render_universal_header('live-productions',$user);az_render_member_layout_open('live-productions',$user); ?>
<main class="azh-live-library" id="azh-live-library" data-csrf="<?= az_h(az_csrf_token()) ?>" data-can-publish="<?= az_has_role($user,'admin')?'1':'0' ?>">
  <div class="azh-live-subhead"><div><p class="azh-live-eyebrow">AZHOTTIE / ADMIN STUDIO</p><h1>Saved Live Productions<span class="azh-accent">.</span></h1><p>Review recorded broadcasts, add cover art, and choose which complete Program recordings members can replay.</p></div><a class="azh-live-button azh-live-secondary" href="live-director.php">Open Live Director</a></div>
  <p id="live-library-message" role="status" class="azh-live-message">Loading saved productions…</p>
  <div id="live-library-grid" class="azh-live-library-grid" aria-label="Saved live productions"></div>
  <p class="azh-live-note">Every new recording starts as a private draft. Admins may publish to signed-in members, then separately approve a Public release visible to all visitors. <a href="live-shows.php">Open public shows</a>. Original footage stays in its owner's media library.</p>
</main>
<?php az_render_member_layout_close(); ?>
<script type="module" src="assets/live-productions.js?v=1.17.0"></script></body></html>
