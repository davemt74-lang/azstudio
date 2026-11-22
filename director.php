<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/universal-header.php';
require_once __DIR__ . '/includes/member-layout.php';
$user = az_require_admin();
$projectId = is_string($_GET['projectId'] ?? null) ? $_GET['projectId'] : '';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#fff7fa">
    <title>Production Director · AZHottie</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/work.css">
    <link rel="stylesheet" href="assets/director.css">
<link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/member-layout.css"></head>
<body class="director-body" data-director-project="<?= az_h($projectId) ?>" data-director-csrf="<?= az_h(az_csrf_token()) ?>" data-director-user="<?= (int)$user['id'] ?>">
<?php az_render_universal_header('director', $user); ?>
<?php az_render_member_layout_open('director', $user); ?>
<main class="workspace director-workspace" id="director-app">
    <div class="director-breadcrumb"><a id="director-production-link" href="productions.php">Back to production</a><button id="director-refresh" type="button" class="text-button" data-director-lock>Refresh</button></div>
    <header class="director-heading"><div><p class="eyebrow">PRODUCTION DIRECTOR</p><h1 id="director-title">A clear path to your first cut<span class="accent">.</span></h1><p id="director-brief" class="page-intro">Check your coverage, mark the moments to use, and shape a first edit.</p></div><a id="director-editor-link" class="button" href="productions.php" hidden>Open editor</a></header>
    <div id="director-error" class="error-banner" role="alert" hidden></div>
    <p id="director-loading" class="director-notice" role="status">Loading this production…</p>
    <div id="director-closed" class="director-notice" hidden></div>
    <div id="director-metrics" class="director-metrics" aria-label="Production coverage"></div>
    <nav id="director-tabs" class="director-tabs" aria-label="Director sections" hidden>
        <button type="button" data-director-tab="coverage" aria-pressed="true">Coverage</button>
        <button type="button" data-director-tab="footage" aria-pressed="false">Usable footage</button>
        <button type="button" data-director-tab="cut" aria-pressed="false">First cut & versions</button>
    </nav>
    <section id="director-coverage" data-director-panel="coverage" aria-labelledby="director-coverage-title" hidden>
        <div class="director-section-heading"><div><p class="eyebrow">WHAT THE STORY NEEDS</p><h2 id="director-coverage-title">Coverage requirements</h2><p>Define the usable material this production needs. Linked accepted footage and marked sections count toward coverage.</p></div><button type="button" class="button button--primary" data-director-action="new-requirement" data-director-lock>Add requirement</button></div>
        <div id="director-requirement-form"></div>
        <div id="director-requirements" class="director-requirements"></div>
    </section>
    <section id="director-footage" data-director-panel="footage" aria-labelledby="director-footage-title" hidden>
        <div class="director-section-heading"><div><p class="eyebrow">KEEP THE MOMENTS THAT WORK</p><h2 id="director-footage-title">Usable footage</h2><p>Watch an accepted take, mark its start and end, and choose the requirement it covers.</p></div></div>
        <div class="director-filter-row"><label class="field">Find footage<input id="director-footage-search" type="search" placeholder="Take label, question, or shot…"></label><label class="field">Show<select id="director-footage-filter"><option value="all">All eligible takes</option><option value="favorite">Favorite takes</option><option value="unselected">Without a usable selection</option></select></label></div>
        <p id="director-footage-count" class="field-help" role="status"></p><div id="director-takes" class="director-takes"></div>
    </section>
    <section id="director-cut" data-director-panel="cut" aria-labelledby="director-cut-title" hidden>
        <div class="director-section-heading"><div><p class="eyebrow">GIVE THE STORY ITS SHAPE</p><h2 id="director-cut-title">Create an editable first cut</h2><p>Start with your preferred selections, adjust the proposal, then save it as a named version.</p></div></div>
        <form id="director-cut-form" class="director-panel director-form" data-director-form="cut" data-form-key="cut">
            <div class="director-form-grid"><label class="field">Target length<select name="preset" id="director-target-preset" data-director-lock><option value="30">30-second teaser</option><option value="60" selected>60-second feature</option><option value="0">Full selection</option><option value="custom">Custom duration</option></select></label><label class="field" id="director-custom-target" hidden>Target seconds<input type="number" name="customTarget" min="1" max="3600" step="1" value="90" data-director-lock></label><label class="field">Question cards<select name="cardMode" data-director-lock><option value="none">No cards</option><option value="all">A card before every clip</option><option value="perClip">Choose for each clip</option></select></label></div>
            <p class="field-help">The target guides clip selection. You can refine timing in the editor before rendering.</p>
            <div class="director-actions"><button type="submit" class="button button--primary" data-director-lock>Build first-cut proposal</button></div><p class="director-form-status" data-form-status role="status"></p>
        </form>
        <div id="director-ai-mount"></div>
        <div id="director-proposal" aria-live="polite"></div>
        <section class="director-panel" aria-labelledby="director-versions-title"><div class="director-section-heading"><div><h3 id="director-versions-title">Saved editable versions</h3><p>Return to a draft, make a different cut, or restore an earlier revision in the editor.</p></div><button type="button" class="button" data-director-action="refresh-versions" data-director-lock>Refresh versions</button></div><div id="director-versions"><p class="field-help">Loading saved versions…</p></div></section>
    </section>
    <noscript><div class="error-banner">Enable JavaScript to use coverage requirements and edit proposals. Your saved productions are still available.</div></noscript>
</main>
<?php az_render_member_layout_close(); ?>
<footer class="studio-footer"><span>AZHOTTIE · PRODUCTION</span><span>Brief. Make. Review. Share.</span></footer>
<div id="director-toast" class="toast" role="status"></div>
<script type="module" src="assets/director.js"></script>
</body>
</html>
