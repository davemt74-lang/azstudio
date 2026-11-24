<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
$user=az_live_require_editor();header('Cache-Control: no-store');header('X-Robots-Tag: noindex,nofollow');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Live Director · AZHottie</title><link rel="icon" href="assets/favicon.svg"><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/live-studio.css?v=1.54.0"><link rel="stylesheet" href="assets/commerce.css?v=1.31.0"></head>
<body class="azh-director-body" data-page="live-director">
<main class="azh-director azh-director-app" id="azh-director" data-csrf="<?= az_h(az_csrf_token()) ?>" aria-label="Full-screen live production control room">
  <header class="azh-director-toolbar">
    <a href="admin-studio.php" class="azh-live-button azh-live-secondary azh-director-back" aria-label="Back to Admin Studio" title="Back to Admin Studio">←</a>
    <label class="azh-live-inline azh-director-title-field"><input id="live-title" maxlength="180" value="AZHottie Live Studio" placeholder="Live show title" aria-label="Live show and saved recording title" title="Names this live show and its saved recording"></label>
    <span id="live-onair-pill" class="azh-live-pill">OFF AIR</span><span id="live-view-count" aria-live="polite">0 viewers</span>
    <button type="button" id="live-go" class="azh-live-button">Go live</button><button type="button" id="live-end" class="azh-live-button azh-live-danger" disabled>End</button>
  </header>
  <div class="azh-director-workspace">
    <aside class="azh-director-sources" aria-label="Connected phone cameras">
      <section class="azh-live-phones azh-phone-source-panel" aria-label="Connected phone camera feeds">
        <div class="azh-live-subhead">
          <div><h2>Connected phone cameras</h2><p class="azh-phone-panel-hint">Each approved phone has its own preview. Broadcast a camera when it is live.</p></div>
          <div class="azh-phone-list-actions"><span id="live-camera-count" class="azh-phone-count" aria-live="polite">0 live / 0 cameras</span><button type="button" id="live-invite" class="azh-live-button azh-live-secondary">+ Connect phone</button></div>
        </div>
        <div id="live-invite-box" hidden><div id="live-invite-qr" class="azh-live-qr"></div><input id="live-invite-url" readonly aria-label="Private camera invitation URL"><button type="button" id="live-copy-invite" class="azh-live-button azh-live-secondary">Copy link</button></div>
        <div id="live-sources" class="azh-phone-camera-grid" aria-label="Approved and pending phone cameras"><p class="azh-phone-empty">No phones connected. Use Connect phone to invite a camera.</p></div>
      </section>
    </aside>
    <section class="azh-director-program" aria-label="Program output and controls">
      <div class="azh-director-monitor"><div class="azh-monitor-label"><strong>PROGRAM</strong><small id="live-delivery-state">No connected fan viewers</small><span id="live-program-source">Holding slate</span></div><div class="azh-program-stage"><canvas id="live-program-canvas" width="1280" height="720" aria-label="Selected live program camera"></canvas></div></div>
      <section class="azh-live-agent-monitor" aria-label="Live Director Agent health and recovery">
        <div class="azh-live-agent-heading"><div><strong>✳ Live Director Agent</strong><p id="live-agent-health-summary" role="status" aria-live="polite">Off air · live health monitoring paused</p></div>
          <div class="azh-live-agent-actions"><button type="button" id="live-agent-recheck" class="azh-live-button azh-live-secondary">Recheck</button><button type="button" id="live-agent-direct" class="azh-live-button azh-live-secondary">Direct camera relay</button><button type="button" id="live-agent-restore" class="azh-live-button azh-live-secondary">Restore Program</button></div>
        </div>
        <p class="azh-live-agent-footnote">Measures video delivery without storing footage. Direct relay requires approval and omits Program overlays for fans; it does not change the recording.</p>
      </section>
      <section class="azh-director-tabs" aria-label="Live production controls">
        <div class="azh-director-tablist" role="tablist" aria-label="Live production tools">
          <button type="button" id="live-tab-switching" class="azh-director-tab" data-live-tab="switching" role="tab" aria-controls="live-panel-switching" aria-selected="true" tabindex="0">Switching</button>
          <button type="button" id="live-tab-compositions" class="azh-director-tab" data-live-tab="compositions" role="tab" aria-controls="live-panel-compositions" aria-selected="false" tabindex="-1">Compositions</button>
          <button type="button" id="live-tab-rundown" class="azh-director-tab" data-live-tab="rundown" role="tab" aria-controls="live-panel-rundown" aria-selected="false" tabindex="-1">Show rundown</button>
          <button type="button" id="live-tab-media" class="azh-director-tab" data-live-tab="media" role="tab" aria-controls="live-panel-media" aria-selected="false" tabindex="-1">Media playback</button>
          <button type="button" id="live-tab-audio" class="azh-director-tab" data-live-tab="audio" role="tab" aria-controls="live-panel-audio" aria-selected="false" tabindex="-1">Audio mixer</button>
          <button type="button" id="live-tab-overlays" class="azh-director-tab" data-live-tab="overlays" role="tab" aria-controls="live-panel-overlays" aria-selected="false" tabindex="-1">Overlays</button>
          <button type="button" id="live-tab-scenes" class="azh-director-tab" data-live-tab="scenes" role="tab" aria-controls="live-panel-scenes" aria-selected="false" tabindex="-1">Scenes</button>
          <button type="button" id="live-tab-audience" class="azh-director-tab" data-live-tab="audience" role="tab" aria-controls="live-panel-audience" aria-selected="false" tabindex="-1">Audience</button>
          <?php if(az_has_role($user,'admin')): ?><button type="button" id="live-tab-shopping" class="azh-director-tab" data-live-tab="shopping" role="tab" aria-controls="live-panel-shopping" aria-selected="false" tabindex="-1">Live shopping</button><?php endif; ?>
          <button type="button" id="live-tab-recording" class="azh-director-tab" data-live-tab="recording" role="tab" aria-controls="live-panel-recording" aria-selected="false" tabindex="-1">Recording</button><?php if(az_has_role($user,'admin')): ?><button type="button" id="live-tab-acceptance" class="azh-director-tab" data-live-tab="acceptance" role="tab" aria-controls="live-panel-acceptance" aria-selected="false" tabindex="-1">Broadcast checks</button><?php endif; ?>
        </div>
        <div class="azh-director-tabpanels">
          <div class="azh-director-tabpanel" id="live-panel-switching" role="tabpanel" aria-labelledby="live-tab-switching" >
      <div class="azh-live-controls"><button type="button" id="live-next" class="azh-live-button azh-live-secondary">Next camera → Program</button><label>Switching <select id="live-switch-mode"><option value="manual">Manual</option><option value="fixed">Every 30 seconds</option><option value="random">Random · 15–45 sec</option></select></label><label>Transition <select id="live-transition"><option value="cut">Cut · instant</option><option value="dissolve">Cross dissolve · 0.6 s</option><option value="fade">Fade through black · 0.6 s</option></select></label><label>Program microphone <select id="live-mic-list"><option value="">Off · video only</option></select></label><button type="button" id="live-apply-mic" class="azh-live-button azh-live-secondary">Set microphone</button></div>
          </div>
          <div class="azh-director-tabpanel" id="live-panel-compositions" role="tabpanel" aria-labelledby="live-tab-compositions" hidden>
            <section class="azh-live-composition-controls" aria-label="Build a private multiview preview">
              <div class="azh-live-subhead"><h2>Multicamera composition</h2><span>Preview privately; Take is required to broadcast</span></div>
              <div class="azh-show-form-row">
                <label>Layout <select id="live-layout-mode"><option value="single">Full-screen single</option><option value="split">Two-camera split</option><option value="pip">Picture-in-picture</option><option value="grid">Four-camera grid</option></select></label>
                <div id="live-layout-slots" class="azh-layout-slots" aria-label="Assign phones to layout positions"></div>
                <button type="button" id="live-layout-preview" class="azh-live-button azh-live-secondary">Preview composition</button>
                <button type="button" id="live-layout-take" class="azh-live-button">Take composition to Program</button>
              </div>
              <div class="azh-compose-preview-wrap"><canvas id="live-layout-canvas" width="640" height="360" aria-label="Private composition preview"></canvas><p id="live-layout-feedback" role="status" aria-live="polite">Choose connected live phones for each position.</p></div>
            </section>
          </div>
          <div class="azh-director-tabpanel" id="live-panel-rundown" role="tabpanel" aria-labelledby="live-tab-rundown" hidden>
            <section class="azh-live-rundown-controls" aria-label="Saved live show rundown">
              <div class="azh-live-subhead"><h2>Show rundown</h2><span>Prepare segments; taking one makes it live</span></div>
              <div class="azh-show-form-row">
                <label>Saved show <select id="live-rundown-select"><option value="">New rundown</option></select></label>
                <label>Show name <input id="live-rundown-name" maxlength="100" placeholder="AZHottie Friday Live"></label>
                <button type="button" id="live-rundown-new" class="azh-live-button azh-live-secondary">+ New</button>
                <button type="button" id="live-rundown-save" class="azh-live-button">Save rundown</button>
                <button type="button" id="live-rundown-delete" class="azh-live-button azh-live-secondary">Delete</button>
              </div>
              <div class="azh-show-form-row">
                <label>Segment name <input id="live-segment-name" maxlength="100" placeholder="Meet the models"></label>
                <label>Optional scene preset <select id="live-segment-scene"><option value="">Use current settings</option></select></label>
                <label>Source <select id="live-segment-kind"><option value="camera">Current camera composition</option><option value="media">Cued prerecorded video</option><option value="slate">Holding slate</option></select></label>
                <button type="button" id="live-segment-add" class="azh-live-button azh-live-secondary">+ Add segment</button>
              </div>
              <div class="azh-rundown-bottom"><ol id="live-rundown-list" class="azh-rundown-list" aria-label="Show segment order"></ol>
                <div class="azh-rundown-actions"><button type="button" id="live-rundown-prev" class="azh-live-button azh-live-secondary">← Previous</button><button type="button" id="live-rundown-preview" class="azh-live-button azh-live-secondary">Preview segment</button><button type="button" id="live-rundown-next" class="azh-live-button">Take next segment →</button></div>
              </div>
              <p id="live-rundown-feedback" class="azh-tool-feedback" role="status" aria-live="polite">Rundown edits are private until taken to Program.</p>
            </section>
          </div>
          <div class="azh-director-tabpanel" id="live-panel-media" role="tabpanel" aria-labelledby="live-tab-media" hidden>
            <section class="azh-live-media-controls" aria-label="Prerecorded program playback">
              <div class="azh-live-subhead"><h2>Prerecorded video playback</h2><span>Only videos in your private media gallery are available</span></div>
              <div class="azh-show-form-row">
                <label>Director media library <select id="live-media-select"><option value="">Choose a private video</option></select></label>
                <button type="button" id="live-media-refresh" class="azh-live-button azh-live-secondary">Refresh library</button>
                <button type="button" id="live-media-cue" class="azh-live-button azh-live-secondary">Cue video privately</button>
                <button type="button" id="live-media-take" class="azh-live-button">Take video to Program</button>
                <button type="button" id="live-media-stop" class="azh-live-button azh-live-secondary">Stop playback</button>
              </div>
              <div class="azh-media-preview-row"><video id="live-media-preview" controls muted playsinline preload="metadata" aria-label="Private prerecorded source preview"></video><div class="azh-media-preview-notes"><label>Program media volume <output id="live-media-volume-label">100%</output><input type="range" id="live-media-volume" min="0" max="150" value="100"></label><p id="live-media-status" role="status" aria-live="polite">Choose a video, then cue it. Taking it will interrupt the current Program until it ends or you return to a camera.</p></div></div>
            </section>
          </div>
          <div class="azh-director-tabpanel" id="live-panel-audio" role="tabpanel" aria-labelledby="live-tab-audio" hidden>
      <section class="azh-director-audio" aria-label="Program audio mixer"><div class="azh-live-subhead"><h2>Audio mixer</h2><span>Included in the live Program and recording</span></div>
        <div class="azh-audio-channel"><label for="live-mic-volume">Host microphone <output id="live-mic-volume-label">100%</output></label><input id="live-mic-volume" type="range" min="0" max="150" value="100"><label class="azh-audio-mute"><input id="live-mic-mute" type="checkbox"> Mute</label><meter id="live-mic-meter" value="0" min="0" max="1" aria-label="Host microphone level"></meter></div>
        <div class="azh-audio-channel"><label for="live-camera-volume">Program camera audio <output id="live-camera-volume-label">100%</output></label><input id="live-camera-volume" type="range" min="0" max="150" value="100"><label class="azh-audio-mute"><input id="live-camera-audio" type="checkbox"> Enable</label><meter id="live-camera-meter" value="0" min="0" max="1" aria-label="Program camera audio level"></meter></div>
        <p class="azh-audio-note">Phone microphone audio is off unless that phone explicitly opts in while sharing live. Camera audio is off in Program until enabled here.</p>
        <div class="azh-program-audio-status">
          <p id="live-audio-health" role="status" aria-live="polite" data-state="unknown">Program mixer not started. This does not verify that fans can hear the audio.</p>
          <button type="button" class="azh-live-button azh-live-secondary" id="live-audio-resume">Resume mixer</button>
        </div>
      </section>
          </div>
          <div class="azh-director-tabpanel" id="live-panel-overlays" role="tabpanel" aria-labelledby="live-tab-overlays" hidden>
      <section class="azh-director-overlays" aria-label="Live program overlays"><div class="azh-live-subhead"><h2>Program overlays</h2><span>Shown to viewers and included in recordings</span></div>
        <div class="azh-overlay-options"><label class="azh-overlay-check"><input id="live-brand-overlay" type="checkbox" checked> AZHottie logo</label><label class="azh-overlay-check"><input id="live-camera-overlay" type="checkbox" checked> Camera label</label><label class="azh-overlay-check"><input id="live-custom-overlay" type="checkbox"> Custom title</label><label class="azh-overlay-text">Text <input id="live-overlay-text" maxlength="90" placeholder="Interview with the AZHottie team"></label><label>Position <select id="live-overlay-position"><option value="lower">Lower third</option><option value="upper">Upper third</option></select></label></div>
        <div class="azh-image-overlay-controls"><label>Image overlay <select id="live-image-select"><option value="">No image</option></select></label><label class="azh-overlay-check"><input type="checkbox" id="live-image-enable"> Show image</label><label>Position <select id="live-image-position"><option value="top-right">Top right</option><option value="top-left">Top left</option><option value="bottom-right">Bottom right</option><option value="bottom-left">Bottom left</option></select></label><label>Width <input id="live-image-scale" type="range" min="10" max="50" value="24"></label><label class="azh-image-upload-label">Upload PNG / JPEG / WebP <input id="live-image-upload" type="file" accept="image/png,image/jpeg,image/webp"></label><button type="button" class="azh-live-button azh-live-secondary" id="live-image-remove">Remove image</button></div>
      </section>
          </div>
          <div class="azh-director-tabpanel" id="live-panel-scenes" role="tabpanel" aria-labelledby="live-tab-scenes" hidden>
      <section class="azh-director-scenes" aria-label="Saved Director scenes"><div class="azh-live-subhead"><h2>Scene presets</h2><span>Saved privately to your Director account</span></div>
        <div class="azh-scene-controls"><input id="live-scene-name" maxlength="80" aria-label="Scene name" placeholder="Scene name · Interview wide"><button type="button" id="live-save-scene" class="azh-live-button azh-live-secondary">Save scene</button><select id="live-scene-select" aria-label="Saved scenes"><option value="">No saved scenes</option></select><button type="button" id="live-recall-scene" class="azh-live-button azh-live-secondary">Preview scene</button><button type="button" id="live-delete-scene" class="azh-live-button azh-live-secondary">Delete</button></div>
      </section>
          </div>
          <div class="azh-director-tabpanel" id="live-panel-audience" role="tabpanel" aria-labelledby="live-tab-audience" hidden>
            <section class="azh-live-audience-controls" aria-label="Member interaction controls">
              <div class="azh-live-subhead"><h2>Fan engagement</h2><span>Members are identified by anonymous screen labels</span></div>
              <label class="azh-overlay-check"><input id="live-chat-enabled" type="checkbox"> Enable member live chat</label>
              <div id="live-audience-reactions" class="azh-audience-reactions" aria-live="polite">♥ 0 · 👏 0 · 🔥 0</div>
              <div id="live-audience-messages" class="azh-audience-messages" aria-label="Moderation queue"></div>
              <p id="live-audience-status" role="status" aria-live="polite">Go live to enable fan reactions and chat.</p>
            </section>
          </div>
          <?php if(az_has_role($user,'admin')): ?>
          <div class="azh-director-tabpanel" id="live-panel-shopping" role="tabpanel" aria-labelledby="live-tab-shopping" hidden>
            <section class="azh-live-commerce" aria-label="Live product spotlight" id="director-commerce" data-csrf="<?= az_h(az_csrf_token()) ?>">
              <div class="azh-live-subhead"><h2>Shopping spotlight</h2><span>Verified active product · external checkout · no automatic purchase</span></div>
              <form id="director-commerce-form" class="azh-show-form-row">
                <label>Product <select id="director-commerce-product" aria-label="Live product"><option value="">Loading active products…</option></select></label>
                <label>Creator attribution <select id="director-commerce-promotion" aria-label="Approved creator promotion"><option value="">No creator attribution</option></select></label>
                <button type="submit" class="azh-live-button">Feature product for viewers</button>
                <a class="azh-live-button azh-live-secondary" href="commerce.php">Manage products &amp; briefs ↗</a>
              </form>
              <p id="director-commerce-status" role="status" aria-live="polite">Go live to feature a product.</p>
              <div class="azh-live-pulse" id="director-commerce-pulse" aria-label="Live shopping performance" aria-live="polite">
                <h3>Live shopping performance</h3><p id="director-commerce-pulse-status" role="status">Loading the current broadcast metrics…</p>
                <div class="azh-live-pulse-metrics">
                  <div><strong id="director-commerce-impressions">—</strong><span>Product impressions</span></div>
                  <div><strong id="director-commerce-clicks">—</strong><span>Checkout CTA taps</span></div>
                  <div><strong id="director-commerce-checkouts">—</strong><span>Stripe checkouts started</span></div>
                  <div><strong id="director-commerce-paid">—</strong><span>Stripe-paid orders</span></div>
                  <div><strong id="director-commerce-net">—</strong><span>Stripe verified net</span></div>
                </div><p class="azh-live-pulse-notice">Session-bounded counts, not unique viewers. Cash sales and estimated commissions are shown separately in Commerce. No automatic payments.</p>
              </div>
              <div id="director-commerce-preview" class="azh-commerce-card" hidden>
                <p class="azh-commerce-eyebrow">VIEWER PRODUCT PREVIEW</p><h3 id="director-commerce-title"></h3>
                <p id="director-commerce-description"></p><strong id="director-commerce-price"></strong>
              </div>
            </section>
          </div>
          <?php endif; ?>
          <div class="azh-director-tabpanel" id="live-panel-recording" role="tabpanel" aria-labelledby="live-tab-recording" hidden>
      <div class="azh-record-controls"><button type="button" id="live-record" class="azh-live-button" disabled>● Record Program</button><button type="button" id="live-record-stop" class="azh-live-button azh-live-secondary" disabled>■ Stop recording</button><span id="live-record-clock">00:00</span><span id="live-record-message" role="status" aria-live="polite">Record and save the combined Program feed privately.</span><div id="live-record-backup"></div></div>
          </div>
          <?php if(az_has_role($user,'admin')): ?>
          <div class="azh-director-tabpanel" id="live-panel-acceptance" role="tabpanel" aria-labelledby="live-tab-acceptance" hidden>
            <section id="azh-live-acceptance" class="azh-live-acceptance" aria-label="Real-device broadcast acceptance">
              <header class="azh-acceptance-header">
                <div><h2>Broadcast acceptance & diagnostics</h2>
                  <p>Verify real phone → Director → Program → remote viewer → recording. Recorded telemetry is separate from the operator's test results.</p></div>
                <button type="button" id="live-acceptance-refresh" class="azh-live-button azh-live-secondary">Refresh observations</button>
              </header>
              <p id="live-acceptance-status" role="status" aria-live="polite">Open this tab to inspect current test readiness.</p>
              <div id="live-acceptance-summary" class="azh-acceptance-summary"></div>
              <section class="azh-live-triage" aria-labelledby="live-triage-title">
                <div class="azh-live-subhead"><h3 id="live-triage-title">Connection troubleshooting</h3>
                <span>Read-only observations · no automatic recovery triggered</span></div>
                <p id="live-triage-status" class="azh-live-triage-status" role="status" aria-live="polite">Checking the next stage to test…</p>
                <div id="live-triage-findings" class="azh-live-triage-findings"></div>
              </section>
              <h3>Recent, browser-reported measurements</h3>
              <div id="live-acceptance-diagnostics" class="azh-acceptance-diagnostics" aria-label="Current broadcast diagnostics"></div>
              <p class="azh-acceptance-basis">These counters are not a live video feed or independent proof of playback. Missing/stale measurements remain unverified. Neither the checklist nor this tab takes a camera to Program.</p>
              <div class="azh-acceptance-checkhead"><h3>Real-device test checklist</h3>
                <a class="azh-live-button azh-live-secondary" href="live.php" target="_blank" rel="noopener noreferrer">Open fan viewer ↗</a>
              </div>
              <div id="live-acceptance-checks" class="azh-acceptance-checklist"></div>
              <details class="azh-live-support" id="live-acceptance-support">
                <summary>Redacted diagnostic summary for technical support</summary>
                <p>This summary excludes names, room IDs, SDP, IPs, token values, video/audio and operator notes. It is not a certification.</p>
                <textarea id="live-support-summary" readonly spellcheck="false" rows="7" aria-label="Redacted broadcast troubleshooting summary"></textarea>
                <button type="button" class="azh-live-button azh-live-secondary" id="live-support-copy">Copy redacted summary</button>
              </details>
              <details class="azh-acceptance-history">
                <summary>View checklist change history</summary>
                <div id="live-acceptance-history"></div>
              </details>
              <p class="azh-acceptance-basis">Operator-attested results never enable payments, publish recordings, approve a phone, or certify that hosted networks and physical devices were independently validated. Test on a second real device and network before launch.</p>
            </section>
          </div>
          <?php endif; ?>
        </div>
      </section>
    </section>
  </div>
  <nav class="azh-mobile-director-bar" aria-label="Mobile Live Director shortcuts">
    <button type="button" id="live-mobile-cameras" class="azh-live-button azh-live-secondary">Cameras</button>
    <button type="button" id="live-mobile-next" class="azh-live-button azh-live-secondary" disabled>Take next</button>
    <button type="button" id="live-mobile-record" class="azh-live-button" disabled aria-label="Start Program recording">● Record</button>
    <button type="button" id="live-mobile-audio" class="azh-live-button azh-live-secondary">Audio</button>
  </nav>
  <p id="live-status" class="azh-live-message azh-director-toast" role="status" aria-live="polite" hidden></p>
</main>
<script src="assets/qr.js"></script><script type="module" src="assets/live-director.js?v=1.56.0"></script><?php if(az_has_role($user,'admin')): ?><script type="module" src="assets/live-acceptance.js?v=1.48.0"></script><?php endif; ?><?php if(az_has_role($user,'admin')): ?><script type="module" src="assets/commerce-director.js?v=1.41.0"></script><?php endif; ?>
</body></html>
