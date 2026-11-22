<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/universal-header.php';
require_once __DIR__ . '/includes/member-layout.php';
$user=az_require_roles(['model','professional','admin']);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#fff"><meta name="robots" content="noindex,nofollow"><title>My media &amp; editor · AZHottie</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/member-layout.css"><link rel="stylesheet" href="assets/personal-media.css"></head>
<body data-page="my-media">
<?php az_render_universal_header('my-media',$user); ?>
<?php az_render_member_layout_open('my-media',$user); ?>
<main class="workspace personal-media" id="main-content" data-user-id="<?= (int)$user['id'] ?>" data-csrf="<?= az_h(az_csrf_token()) ?>">
  <section class="pm-hero"><div><p class="pm-eyebrow">YOUR PRIVATE CREATIVE LIBRARY</p><h1>My media <em>&amp; editor.</em></h1><p>Upload your own footage, find your best moments, and make an edited copy. This private gallery is also where you prepare footage for independent My Videos submissions; nothing is published without your consent and admin approval.</p></div><div class="pm-hero-mark" aria-hidden="true">✳<span>AZ</span></div></section>
  <section class="pm-upload" aria-labelledby="pm-upload-title"><div><h2 id="pm-upload-title">Add footage to your gallery</h2><p>Video files: MP4, MOV, WebM or Ogg. Large files upload in resumable chunks. Your originals stay unchanged.</p></div><form id="pm-upload-form" class="pm-upload-form"><label class="pm-field">Video title<input name="title" id="pm-new-title" required maxlength="200" placeholder="New scene, behind the scenes, or product test"></label><label class="pm-file-btn"><span>Choose a video</span><input id="pm-file" type="file" accept="video/mp4,video/quicktime,video/webm,video/ogg,video/*" required></label><button class="pm-button pm-button-primary" id="pm-upload-btn" type="submit">Upload to my gallery</button></form><div class="pm-upload-status"><div class="pm-progress"><div id="pm-progress-fill"></div></div><p id="pm-upload-status" role="status" aria-live="polite">No upload in progress.</p><button id="pm-retry" type="button" class="pm-button pm-button-secondary" hidden>Resume upload</button></div><div class="pm-pending" id="pm-pending-wrap" hidden><h3>Unfinished uploads</h3><p>Select the same video file above to resume a previous upload, or discard its incomplete copy.</p><div id="pm-pending"></div></div></section>
  <section class="pm-library" aria-labelledby="pm-library-title"><div class="pm-section-head"><div><p class="pm-eyebrow">PRIVATE FOOTAGE</p><h2 id="pm-library-title">My gallery <span id="pm-count">0 videos</span></h2></div><span id="pm-storage" class="pm-storage"></span></div><div class="pm-grid" id="pm-grid" aria-live="polite"><p class="pm-empty">Loading your private media…</p></div></section>
  <section class="pm-editor" id="pm-editor" aria-labelledby="pm-editor-title" hidden><div class="pm-section-head"><div><p class="pm-eyebrow">PERSONAL EDITOR</p><h2 id="pm-editor-title">Review and edit</h2></div><button id="pm-close" class="pm-button pm-button-secondary" type="button">Close editor</button></div>
    <div class="pm-editor-grid"><div class="pm-stage"><video id="pm-video" controls playsinline preload="metadata"></video><canvas id="pm-render-canvas" width="1280" height="720" hidden aria-label="Edited video preview"></canvas><div class="pm-player-meta"><span id="pm-current-time">00:00</span><span id="pm-video-duration">--:--</span></div></div>
      <form id="pm-edit-form" class="pm-edit-form"><label class="pm-field">Video title<input id="pm-title" required maxlength="200"></label><label class="pm-field">Personal notes<textarea id="pm-description" rows="3" maxlength="2000" placeholder="Idea, scene details, or the moment to keep…"></textarea></label>
      <div class="pm-trim-grid"><label class="pm-field">Start (seconds)<input id="pm-start" type="number" min="0" step=".001" value="0"></label><label class="pm-field">End (seconds)<input id="pm-end" type="number" min="0" step=".001" value="0"></label></div>
      <div class="pm-trim-actions"><button type="button" class="pm-button pm-button-secondary" id="pm-mark-start">Start here</button><button type="button" class="pm-button pm-button-secondary" id="pm-mark-end">End here</button><button type="button" class="pm-button pm-button-secondary" id="pm-preview">Preview selection</button></div>
      <div class="pm-actions"><button type="submit" class="pm-button pm-button-primary" id="pm-save">Save edit points</button><button type="button" class="pm-button pm-button-primary" id="pm-export">Export &amp; save edited copy</button></div>
      <div class="pm-cover-tools"><strong>Video cover</strong><button type="button" id="pm-capture-cover" class="pm-button pm-button-secondary">Use current video frame</button><label class="pm-file-btn pm-file-btn-small"><span>Upload cover</span><input type="file" id="pm-cover-file" accept="image/jpeg,image/png,image/webp"></label></div>
      <div class="pm-actions pm-actions-secondary"><a id="pm-download" class="pm-button pm-button-secondary" href="#" download>Download original</a><button type="button" id="pm-delete" class="pm-button pm-button-danger">Delete from my gallery</button></div>
      <p id="pm-editor-status" role="status" aria-live="polite"></p></form></div>
  </section>
  <?php if (az_has_role($user, 'model')): ?><section class="pm-future"><div><p class="pm-eyebrow">INDEPENDENT CREATOR CONTENT</p><h2>Ready to share your own video?</h2><p>Use your original or edited copy to create an independent video in <a href="my-videos.php">My Videos</a>. Submit it for admin review, then approved videos appear on Watch. This is separate from producer assignments.</p></div><a class="pm-button pm-button-primary" href="my-videos.php">Open My Videos ↗</a></section><?php endif; ?>
  <section class="pm-future"><div><p class="pm-eyebrow">FROM YOUR LIBRARY TO AZHOTTIE</p><h2>Ready for a production?</h2><p>Your personal media stays private. To contribute footage to an AZHottie production, open its assigned shot under <a href="my-work.php">My assignments</a> and submit footage there for producer review.</p></div><a class="pm-button pm-button-secondary" href="my-work.php">View my assignments</a></section>
</main>
<?php az_render_member_layout_close(); ?>
<footer class="studio-footer"><span>AZHOTTIE · PRIVATE MEDIA</span><span>Originals stay yours · Publishing requires a separate approval</span></footer>
<script type="module" src="assets/personal-media.js"></script>
</body></html>
