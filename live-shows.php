<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/universal-header.php';
header('Cache-Control: no-store');
$user=az_current_user();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AZHottie · Published Live Shows</title><meta name="description" content="Watch published AZHottie live productions and their recorded highlights."><link rel="icon" href="assets/favicon.svg"><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/live-studio.css?v=1.18.0"></head>
<body class="azh-public-shows-body" data-page="live-shows"><?php az_render_universal_header('live-shows',$user); ?>
<main class="azh-public-shows" aria-labelledby="azh-public-shows-title">
 <div class="azh-live-subhead azh-public-shows-heading"><div><p class="azh-live-eyebrow">AZHOTTIE / RECORDED PRODUCTIONS</p><h1 id="azh-public-shows-title">Live Shows<span class="azh-accent">.</span></h1><p>Watch productions released by the AZHottie team.</p></div><a href="<?=az_h($user?'live.php':'login.php')?>" class="azh-live-button azh-live-secondary"><?= $user?'Member live player':'Sign in for live shows' ?></a></div>
 <section id="public-show-player" class="azh-fan-replay azh-public-replay" hidden aria-label="Published show player"><div class="azh-live-subhead"><h2 id="public-show-title">Saved production</h2><button id="public-show-close" type="button" class="azh-live-button azh-live-secondary">Close player</button></div><video id="public-show-video" playsinline controls preload="metadata"></video><p id="public-show-description"></p></section>
 <p id="public-shows-status" role="status" aria-live="polite">Loading published shows…</p><div id="public-shows-grid" class="azh-live-archive-grid" aria-label="Published live show recordings"></div>
</main>
<script type="module" src="assets/live-shows.js?v=1.17.0"></script></body></html>
