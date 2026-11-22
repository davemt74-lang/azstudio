<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/universal-header.php';
require_once __DIR__ . '/includes/member-layout.php';
$user = az_require_admin();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#fff7fa">
    <title>Admin · AI settings · AZHottie</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/ai-settings.css">
<link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/member-layout.css"></head>
<body class="ai-settings-body">
<?php az_render_universal_header('admin', $user); ?>
<?php az_render_member_layout_open('admin', $user); ?>
<main class="workspace ai-workspace" id="ai-settings-app" data-ai-csrf="<?= az_h(az_csrf_token()) ?>">
    <header class="page-heading"><div><p class="eyebrow">ADMIN / PRODUCTION TOOLS</p><h1>AI settings<span class="accent">.</span></h1><p class="page-intro">Connect the assistants that turn a production brief into a shot list and reviewed footage into an editable proposal.</p></div><a href="productions.php" class="button">Open productions</a></header>
    <nav class="ai-admin-links" aria-label="Admin sections"><a href="admin.php" aria-current="page">AI settings</a><a href="accounts.php">Accounts & roles</a><a href="profiles.php">Model profiles</a><a href="questionnaires.php">Questionnaires</a></nav>
    <div id="ai-settings-message" class="ai-notice" role="status" aria-live="polite" hidden></div>
    <p id="ai-settings-loading" class="ai-loading" role="status">Loading your AI settings…</p>
    <div id="ai-settings-recovery" class="ai-recovery" hidden><button type="button" class="button" data-ai-reload>Reload saved settings</button></div>
    <form id="ai-settings-form" autocomplete="off" hidden>
        <section class="ai-account-note" aria-label="Settings ownership"><span class="ai-status-pill" id="ai-enabled-status">AI is off</span><div><strong>Saved for your admin account</strong><p>Choose a provider for each assistant. You review proposed shots and edits before adding them to a production.</p></div></section>
        <div class="ai-provider-grid">
            <?php foreach (['openai' => ['OpenAI', 'ChatGPT API', 'OA'], 'anthropic' => ['Claude', 'Anthropic API', 'CL']] as $provider => $copy): ?>
            <section class="ai-provider panel" aria-labelledby="ai-<?= $provider ?>-heading" data-ai-provider="<?= $provider ?>">
                <div class="ai-provider-heading"><span class="ai-provider-mark" aria-hidden="true"><?= $copy[2] ?></span><div><p class="eyebrow"><?= $copy[1] ?></p><h2 id="ai-<?= $provider ?>-heading"><?= $copy[0] ?></h2></div><span class="ai-key-state" data-ai-key-state="<?= $provider ?>">No saved key</span></div>
                <label class="field" for="ai-<?= $provider ?>-key">API key<input type="password" id="ai-<?= $provider ?>-key" name="<?= $provider ?>Key" maxlength="500" autocomplete="new-password" spellcheck="false" autocapitalize="none" aria-describedby="ai-<?= $provider ?>-key-help" data-ai-key="<?= $provider ?>"></label>
                <p class="field-help" id="ai-<?= $provider ?>-key-help">Leave blank to keep a saved key. Save a new key before loading models.</p>
                <label class="ai-check ai-remove-key"><input type="checkbox" name="<?= $provider ?>RemoveKey" data-ai-remove="<?= $provider ?>"><span>Remove the saved <?= $copy[0] ?> key when I save</span></label>
                <?php if ($provider === 'anthropic'): ?><label class="field ai-workspace-field" for="ai-anthropic-workspace">Workspace ID <span class="optional">optional</span><input id="ai-anthropic-workspace" name="anthropicWorkspaceId" maxlength="120" autocomplete="off" spellcheck="false" autocapitalize="none" aria-describedby="ai-anthropic-workspace-help"></label><p class="field-help" id="ai-anthropic-workspace-help">Leave blank for a key scoped to one workspace.</p><?php endif; ?>
                <div class="ai-provider-model"><label class="field" for="ai-<?= $provider ?>-model">Model<select id="ai-<?= $provider ?>-model" name="<?= $provider ?>Model" data-ai-model="<?= $provider ?>"><option value="">Choose a model</option></select></label><button type="button" class="button" data-ai-load="<?= $provider ?>">Check key & load models</button></div>
                <p class="ai-model-help" data-ai-model-help="<?= $provider ?>">The list comes from your provider account. An access check does not generate content.</p>
                <p class="ai-connection-message" data-ai-connection="<?= $provider ?>" role="status" aria-live="polite">Save a key to check this connection.</p>
            </section>
            <?php endforeach; ?>
        </div>
        <section class="panel ai-routing" aria-labelledby="ai-routing-heading">
            <div class="ai-section-heading"><div><p class="eyebrow">YOUR PRODUCTION WORKFLOW</p><h2 id="ai-routing-heading">Choose your assistants</h2></div><label class="ai-check ai-enable"><input type="checkbox" name="enabled" id="ai-enable"><span>Enable AI assistants</span></label></div>
            <div class="ai-routing-grid"><label class="field" for="ai-shot-provider">Shot-list assistant<select name="shotProvider" id="ai-shot-provider"><option value="openai">OpenAI</option><option value="anthropic">Claude (Anthropic)</option></select><span class="field-help">Drafts shot instructions from your production brief.</span></label><label class="field" for="ai-edit-provider">Editing assistant<select name="editProvider" id="ai-edit-provider"><option value="openai">OpenAI</option><option value="anthropic">Claude (Anthropic)</option></select><span class="field-help">Proposes a sequence using eligible, reviewed footage.</span></label><label class="field" for="ai-daily-limit">Daily generation limit<input type="number" name="dailyLimit" id="ai-daily-limit" min="1" max="100" step="1" value="20"><span class="field-help">Shared across both assistants and providers for your account.</span></label></div>
            <p id="ai-usage" class="ai-usage">Loading request usage…</p>
        </section>
        <div class="ai-save-row"><div><p id="ai-save-state" role="status">All changes saved.</p><p class="field-help">Keys stay on the server. Provider API usage is billed through your provider account.</p></div><div class="ai-save-actions"><button type="button" class="button button--quiet" data-ai-reload>Reload saved settings</button><button type="submit" class="button button--primary" id="ai-save">Save AI settings</button></div></div>
        <aside class="ai-workflow-note"><h2>Where production assignments come from</h2><p>Create shots yourself or review an AI shot-list proposal. Once shots are saved, assign a model or open a shot for eligible contributors to claim. The Director can also create a shot for missing coverage.</p><p>AI helps prepare the brief and edit proposal. You choose who participates and which material is used.</p></aside>
    </form>
    <section class="panel azh-agent-admin" id="azh-agent-admin" data-agent-admin data-csrf="<?= az_h(az_csrf_token()) ?>" aria-labelledby="azh-agent-admin-title">
      <p class="eyebrow">MEMBER EXPERIENCE</p><h2 id="azh-agent-admin-title">Agent chat</h2>
      <p>Offer a text-only AZHottie assistant to signed-in members, using one of your saved OpenAI or Claude keys. Chat is off by default, and members cannot access admin tools or unpublished media through the agent.</p>
      <div id="azh-agent-admin-status" role="status" aria-live="polite">Loading member chat settings…</div>
      <form id="azh-agent-admin-form" hidden>
        <label class="ai-check"><input type="checkbox" id="azh-agent-enable"> Enable chat for logged-in members</label>
        <div class="azh-agent-admin-controls"><label>Provider<select id="azh-agent-provider"><option value="openai">OpenAI</option><option value="anthropic">Claude</option></select></label><label>Daily messages per member<input id="azh-agent-limit" type="number" min="1" max="40" value="12"></label></div>
        <button type="submit" class="button button--primary">Save chat settings</button> <a href="agent-chat.php" class="button button--quiet">Open agent canvas</a>
      </form>
    </section>
    <noscript><div class="ai-notice is-error">Enable JavaScript to configure AI providers. Your saved productions remain available.</div></noscript>
</main>
<?php az_render_member_layout_close(); ?>
<footer class="studio-footer"><span>AZHOTTIE · ADMIN</span><span>Plan. Create. Make the cut.</span></footer>
<script type="module" src="assets/ai-settings.js"></script>
<script type="module" src="assets/agent-admin.js?v=1.14.0"></script>
</body></html>
