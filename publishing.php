<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/universal-header.php';
require_once __DIR__.'/includes/member-layout.php';
$user=az_require_admin();
header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Publishing &amp; Growth · AZHottie</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/universal-header.css"><link rel="stylesheet" href="assets/member-layout.css"><link rel="stylesheet" href="assets/publishing-agent.css?v=1.53.0"></head>
<body data-page="publishing"><?php az_render_universal_header('publishing',$user);az_render_member_layout_open('publishing',$user); ?>
<main class="workspace pg-workspace" id="main-content" data-csrf="<?= az_h(az_csrf_token()) ?>">
<header class="pg-header"><div><p class="pg-eyebrow">ADMIN / CONTENT OPERATIONS</p><h1>Publishing &amp; Growth<span>.</span></h1><p>Review real Watch engagement, schedule approved model videos, and prepare or feature promotions. No automatic social or email posting.</p></div><a href="user-submissions.php" class="pg-button">Open User Submissions ↗</a></header>
<p id="pg-message" class="pg-message" role="status" aria-live="polite" hidden></p>
<section aria-labelledby="pg-metrics-title"><div class="pg-heading"><h2 id="pg-metrics-title">Audience snapshot</h2><p>Verified public-video events · most recent 30 days versus the previous 30</p></div><div class="pg-metrics" id="pg-metrics"><p class="pg-empty">Loading actual playback data…</p></div><div class="pg-chart" role="img" aria-label="Last 14 days of play starts" id="pg-chart"></div><div class="pg-heading"><h3>Top published creator videos</h3><p id="pg-analytics-basis"></p></div><div id="pg-top-videos" class="pg-top"></div></section>
<section class="pg-grid" aria-label="Publishing operations"><div class="pg-panel"><div class="pg-heading"><h2>Publishing calendar</h2><p>Admin-approved release dates use America/Phoenix. Scheduled clips remain private until released by the server cron.</p></div><div id="pg-scheduled" class="pg-lines"></div><a href="user-submissions.php" class="pg-link">Review and schedule submitted videos ↗</a><button type="button" class="pg-button pg-button-outline" id="pg-check">Check due releases now</button></div>
<div class="pg-panel"><div class="pg-heading"><h2>Promotion studio</h2><p>Create metadata-based drafts, then approve an on-site feature or copy text for manual social sharing. No external posts are sent.</p></div><form id="pg-draft" class="pg-form"><label>Published video<select id="pg-video" required></select></label><label>Placement<select id="pg-channel"><option value="onsite">Featured on Watch</option><option value="social">Social copy for manual sharing</option></select></label><button type="button" id="pg-suggest" class="pg-button pg-button-outline">Use verified video metadata</button><label>Headline<input id="pg-headline" maxlength="160" required placeholder="Introduce the story…"></label><label>Promotion copy<textarea id="pg-copy" maxlength="900" required rows="3" placeholder="Write a factual description using known metadata…"></textarea></label><button type="submit" class="pg-button">Save private promotion draft</button></form><div id="pg-promotions" class="pg-lines"></div></div></section>
<section class="pg-campaign-board" id="pg-campaign-workspace" data-csrf="<?= az_h(az_csrf_token()) ?>" aria-labelledby="pg-campaign-title">
  <header class="pg-heading"><div><h2 id="pg-campaign-title">Campaign Studio · social-ready handoffs</h2>
    <p>Prepare approved video campaigns, generate tracking links, export 9:16 cover artwork, and review link opens. Posts to external services are manual.</p></div>
    <button type="button" class="pg-button pg-button-outline" id="pg-campaign-refresh">Refresh campaigns</button></header>
  <p id="pg-campaign-summary" class="pg-note" aria-live="polite">Loading campaign summary…</p>
  <p id="pg-campaign-message" class="pg-message" role="status" aria-live="polite" hidden></p>
  <form id="pg-campaign-form" class="pg-form">
    <div class="pg-campaign-controls">
      <label>Approved social promotion<select id="pg-campaign-source" required></select><small id="pg-campaign-source-note">Select a previously approved promotion.</small></label>
      <label>Campaign channel<select id="pg-campaign-platform"><option value="instagram">Instagram</option><option value="tiktok">TikTok</option><option value="youtube">YouTube Shorts</option><option value="facebook">Facebook</option><option value="email">Email newsletter</option><option value="other">Other / manual</option></select></label>
      <label>Optional handoff · Arizona time<input id="pg-campaign-time" type="datetime-local" aria-describedby="pg-campaign-handoff-hint"></label>
    </div><p id="pg-campaign-handoff-hint" class="pg-note">Scheduling creates a reminder/handoff only. It does not post to social media automatically. Activate the tracking link explicitly.</p>
    <button id="pg-campaign-create" class="pg-button" type="submit">Create campaign draft</button>
  </form>
  <div class="pg-lines" id="pg-campaigns" aria-live="polite"><p class="pg-empty">Loading campaigns…</p></div>
</section>
<section class="pg-panel" aria-labelledby="pg-brain"><div class="pg-heading"><h2 id="pg-brain">Agent Brain · Publishing priorities</h2><p>Verified recommendations, not simulated AI video analysis</p></div><div id="pg-suggestions" class="pg-lines"></div><a href="agent-chat.php?topic=publishing" class="pg-link">Discuss findings in Agent Chat ↗</a></section>
</main><?php az_render_member_layout_close(); ?><script type="module" src="assets/publishing-agent.js?v=1.53.0"></script><script type="module" src="assets/publishing-campaigns.js?v=1.53.0"></script></body></html>
