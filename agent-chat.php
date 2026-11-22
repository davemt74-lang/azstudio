<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/universal-header.php';
require_once __DIR__ . '/includes/member-layout.php';
$user=az_require_auth();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#fff"><title>Agent chat · AZHottie</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/agent-casting-chat.css?v=1.26.0"><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/member-layout.css"></head>
<body data-page="agent-chat">
<?php az_render_universal_header('agent-chat',$user); ?>
<?php az_render_member_layout_open('agent-chat',$user); ?>
<main id="azh-chat-canvas" class="azh-chat-canvas workspace" data-agent-canvas data-csrf="<?= az_h(az_csrf_token()) ?>">
  <header class="azh-chat-canvas-header"><div><p class="azh-chat-eyebrow">YOUR MEMBER ASSISTANT</p><h1>AZHottie Agent<span>.</span></h1><p class="azh-chat-subtitle">Talk through ideas, content, experiences, or your next creative project.</p></div><button type="button" id="azh-chat-new" class="azh-chat-new">+ New chat</button></header>
  <?php if(az_agent_workspace_available($user)): ?>
  <section class="azh-casting-chat-panel" id="azh-casting-chat-panel" data-role="<?=az_has_role($user,'admin')?'admin':'model'?>" data-csrf="<?=az_h(az_csrf_token())?>" aria-label="Verified casting and onboarding briefing">
    <div class="azh-casting-chat-head"><div><h2>Casting &amp; Onboarding Brain</h2><p>Verified workspace data, integrated into Agent Chat</p></div></div>
    <p data-casting-status role="status" aria-live="polite">Checking casting records…</p><div data-casting-body></div>
  </section>
  <?php endif; ?>
  <div class="azh-chat-history" aria-label="Your saved conversations"><strong>Recent chats</strong><div id="azh-chat-threads" class="azh-chat-threads"><span>Loading…</span></div></div>
  <div class="azh-chat-status" id="azh-chat-status" role="status" aria-live="polite">Loading your private conversation…</div>
  <section class="azh-chat-transcript" id="azh-chat-messages" aria-label="Chat messages" aria-live="polite"><div class="azh-chat-welcome"><span aria-hidden="true">✳</span><h2>What's on your mind?</h2><p>Ask about AZHottie, brainstorm a shoot, or discuss an idea. Chat does not create bookings or access private media.</p></div></section>
  <p class="azh-chat-privacy">Your chat is private to your account. If AI is enabled, your messages are sent to the administrator-selected AI provider to generate replies. No production actions are taken automatically.</p>
</main>
<?php az_render_member_layout_close(); ?>
<script type="module" src="assets/agent-casting-chat.js?v=1.26.0"></script>
<footer class="studio-footer"><span>AZHOTTIE · AGENT CHAT</span><span>Private member conversation</span></footer>
</body></html>
