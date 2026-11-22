<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
// A QR-invited phone may contribute to this model's media library without
// receiving the model's account cookie or any editor/publication authority.
$guest=isset($_GET['invite']) && isset($_GET['code']);
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
$user=az_current_user();
if ($user && !$guest)az_personal_media_role($user);
// A model using ordinary camera uploads is already authenticated, but a
// Live Director QR still needs a distinct approved phone feed/offer. Do not
// redirect away the live invitation when an owner scans it while signed in.
if ($guest && $user && (string)($_GET['studio']??'')!=='live' && az_valid_id((string)$_GET['invite']) && preg_match('/\A[a-f0-9]{64}\z/D',(string)$_GET['code'])) {
  $q=az_db()->prepare('SELECT owner_id,token_hash FROM personal_phone_invites WHERE id=? AND used_epoch=0 AND expires_epoch>?');
  $q->execute([(string)$_GET['invite'],time()]);$invite=$q->fetch();
  if ($invite && (int)$invite['owner_id']===(int)$user['id'] && hash_equals((string)$invite['token_hash'],az_personal_phone_digest((string)$_GET['code'])))
    az_redirect('my-camera.php');
}
if ($guest) $user=null; // Guests always need the model's explicit device approval.
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#0d111b">
<meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>Camera Studio · AZHottie</title>
<link rel="icon" href="assets/favicon.svg"><link rel="manifest" href="camera.webmanifest">
<link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/personal-media.css"><link rel="stylesheet" href="assets/personal-phone.css?v=1.18.1">
</head><body class="azh-phone-body">
<main class="azh-phone-capture" id="phone-camera-root" data-mode="<?= $user?'signed-in':'paired' ?>" data-csrf="<?= $user?az_h(az_csrf_token()):'' ?>">
  <header class="azh-phone-heading" aria-label="Camera controls">
    <div class="azh-phone-title"><a class="azh-phone-brand" href="<?= $user ? 'dashboard.php' : 'index.php' ?>" title="Back to AZHottie">AZHOTTIE<span>✳</span></a><span class="azh-phone-private">CAMERA STUDIO</span></div>
    <div class="azh-phone-header-actions"><label id="phone-record-share-choice" class="azh-phone-record-share-choice" hidden><input id="phone-share-on-record" type="checkbox" checked> Live preview on Record</label><span id="phone-connection-pill" class="azh-phone-connection-pill">Checking connection</span><button type="button" id="phone-fullscreen" class="azh-phone-icon-button" title="Expand camera" aria-label="Expand camera to full screen">⛶</button></div>
  </header>
  <section class="azh-phone-stage" id="phone-stage" aria-label="Live camera monitor">
    <video id="phone-preview" autoplay playsinline muted aria-label="Live phone camera preview"></video>
    <div class="azh-phone-empty-stage" id="phone-stage-placeholder" aria-hidden="true"><span class="azh-phone-lens">◎</span><strong>Ready when you are</strong><small>Start the camera to frame your shot</small></div>
    <div class="azh-phone-stage-badges"><span id="phone-recording-badge" class="azh-phone-record-badge" hidden>● REC <span id="phone-recording-clock">00:00</span></span><span id="phone-live-badge" class="azh-phone-live-badge" hidden>● LIVE</span></div>
    <button type="button" id="phone-start-camera" class="pm-button azh-phone-start-button">◉ Start camera</button>
    <p id="phone-status" class="azh-phone-status" role="status" aria-live="polite">Checking camera access…</p>
    <section id="phone-claim" class="azh-phone-claim" hidden aria-label="Connect this phone">
      <h1>Connect your camera</h1><p>This phone needs approval from the model running the editor. No login is required.</p>
      <label for="phone-display-name">Phone name</label><input id="phone-display-name" maxlength="48" placeholder="Second camera or iPhone">
      <button type="button" id="phone-claim-button" class="pm-button pm-button-primary">Request connection</button>
    </section>
  </section>
  <?php if ($guest && (string)($_GET['studio']??'')==='live'): ?><p class="azh-phone-audience-disclosure" role="note">Live Director invitation: if Live preview on Record is checked, recording also shares this camera privately with the Director. You may turn it off before recording or stop sharing at any time. Only the Director can put your camera on Program for members to watch.</p><?php endif; ?>
  <section class="azh-phone-toolbar" id="phone-record-controls" hidden aria-label="Recording controls">
    <div class="azh-phone-action-row">
      <button type="button" id="phone-switch" class="azh-phone-action" aria-label="Switch front or rear camera"><span aria-hidden="true">⟳</span><small>Flip</small></button>
      <button type="button" id="phone-record" class="azh-phone-record-action" aria-label="Start recording"><span class="azh-phone-record-dot"></span></button>
      <button type="button" id="phone-stop" class="azh-phone-action" disabled aria-label="Stop recording"><span aria-hidden="true">■</span><small>Stop</small></button>
      <button type="button" id="phone-open-library" class="azh-phone-action" aria-controls="phone-upload-drawer" aria-expanded="false"><span aria-hidden="true">▣</span><small>Footage</small></button>
      <button type="button" id="phone-live" class="azh-phone-action azh-phone-live-action" hidden aria-pressed="false" aria-label="Share live camera"><span aria-hidden="true" class="azh-phone-live-icon">◉</span><small class="azh-phone-live-text">Share live</small></button>
    </div>
  </section>
  <section id="phone-upload-drawer" class="azh-phone-upload-drawer" hidden aria-label="Footage upload panel">
    <div class="azh-phone-drawer-header"><div><strong>Footage &amp; upload</strong><small>Saved only to the model's private media gallery</small></div><button type="button" id="phone-close-library" class="azh-phone-icon-button" aria-label="Close footage panel">✕</button></div>
    <div id="phone-recorded" class="azh-phone-recorded" hidden><video id="phone-playback" controls playsinline preload="metadata" aria-label="Recorded clip preview"></video><div class="azh-phone-clip-actions"><button type="button" class="pm-button pm-button-secondary" id="phone-edit" aria-controls="phone-trim-editor" aria-expanded="false">✂ Edit / trim</button><a id="phone-download" class="pm-button pm-button-secondary" download="azhottie-phone-recording.webm">↓ Download backup</a></div></div>
    <section class="azh-phone-trim-editor" id="phone-trim-editor" hidden aria-label="Trim video before upload"><h2>Trim video</h2><p>Choose the start and end of the clip. The original recording stays untouched.</p><div class="azh-phone-trim-grid"><label>Start (seconds)<input id="phone-trim-in" type="number" min="0" step="0.1" value="0"></label><label>End (seconds)<input id="phone-trim-out" type="number" min="0" step="0.1" value="0"></label></div><div class="azh-phone-clip-actions"><button type="button" class="pm-button pm-button-secondary" id="phone-trim-mark-in">Start here</button><button type="button" class="pm-button pm-button-secondary" id="phone-trim-mark-out">End here</button><button type="button" class="pm-button pm-button-secondary" id="phone-trim-preview">Preview</button></div><div class="azh-phone-clip-actions"><button type="button" class="pm-button pm-button-primary" id="phone-trim-save">Save trimmed clip</button><button type="button" class="pm-button pm-button-secondary" id="phone-trim-cancel">Cancel</button></div><p id="phone-trim-status" role="status" aria-live="polite"></p><canvas id="phone-trim-canvas" width="1280" height="720" hidden></canvas></section>
    <label class="azh-phone-live-mic-choice"><input type="checkbox" id="phone-share-audio"> Also share this phone’s microphone while Live is on (off by default)</label>
    <label class="azh-phone-field" for="phone-title">Clip title</label><input id="phone-title" maxlength="200" placeholder="Behind the scenes, take 2">
    <label class="azh-phone-field" for="phone-file">Or choose a video from this phone</label><input id="phone-file" type="file" accept="video/mp4,video/quicktime,video/webm,video/ogg,video/*">
    <button type="button" id="phone-upload" class="pm-button pm-button-primary">↑ Upload to private editor</button>
    <progress id="phone-upload-progress" max="100" value="0" hidden></progress>
    <p class="azh-phone-upload-hint" id="phone-upload-hint">If the connection drops, you can retry this upload. Keep a backup of recordings you need.</p>
  </section>
</main><script type="module" src="assets/personal-camera.js?v=1.19.1"></script></body></html>
