<?php
declare(strict_types=1);

/*
 * Presentation only. app.js owns all interaction and record rendering.
 * Common controls: [data-action="new-interview"], [data-close-dialog="ID"].
 * Library filters: [data-library-status="active|archived"].
 * New interview form fields: title, participantName, notes.
 * Session forms: title, participantName, notes. Answer notes: answerNotes.
 * Dynamic cards should reuse .session-card, .question-item, .take-item,
 * .segment-item, .button/.button--small, .badge and .muted.
 * Header classes supported by style.css: .site-header, .header-inner,
 * .brand (.brand-name/.brand-caption), .main-nav, .header-user.
 */

function azh_icon(string $name, string $class = ''): string
{
    $paths = [
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'camera' => '<rect x="3" y="6" width="13" height="12" rx="3"/><path d="m16 10 5-3v10l-5-3z"/>',
        'film' => '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M7 3v18M17 3v18M3 8h4M3 16h4M17 8h4M17 16h4M7 12h10"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/>',
        'save' => '<path d="M5 3h12l4 4v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M7 3v6h10V3M7 21v-8h10v8"/>',
        'archive' => '<rect x="3" y="3" width="18" height="5" rx="1.5"/><path d="M5 8v12h14V8M10 12h4"/>',
        'play' => '<path d="m9 5 11 7-11 7z"/>',
        'stop' => '<rect x="6" y="6" width="12" height="12" rx="1.5"/>',
        'record' => '<circle cx="12" cy="12" r="6" fill="currentColor" stroke="none"/>',
        'upload' => '<path d="M12 16V3m-5 5 5-5 5 5M4 15v5h16v-5"/>',
        'download' => '<path d="M12 3v13m-5-5 5 5 5-5M4 16v5h16v-5"/>',
        'close' => '<path d="m6 6 12 12M6 18 18 6"/>',
        'flag' => '<path d="M5 21V4m0 0c5-4 9 4 14 0v10c-5 4-9-4-14 0"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'folder' => '<path d="M3 6a2 2 0 0 1 2-2h5l2 3h7a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/>',
        'mic' => '<rect x="9" y="3" width="6" height="12" rx="3"/><path d="M5 10v2a7 7 0 0 0 14 0v-2M12 19v3M8 22h8"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
    ];
    return '<svg class="icon ' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['film']) . '</svg>';
}

function render_studio_view(string $page): void
{
    ?>
    <div id="toast" class="toast" role="status" aria-live="polite"></div>
    <?php if ($page === 'library'): ?>
    <main id="library-view" class="workspace">
        <section class="page-heading">
            <div><p class="eyebrow">YOUR PRODUCTION DESK</p><h1>Interview library<span class="accent">.</span></h1><p class="page-intro">Every conversation, every take, ready to pick up where you left off.</p></div>
            <button type="button" class="button button--primary" data-action="new-interview"><?= azh_icon('plus') ?>New interview</button>
        </section>

        <section class="library-overview" aria-label="Interview totals">
            <div class="metric"><span class="metric-icon"><?= azh_icon('folder') ?></span><div><span class="metric-label">Interviews</span><strong id="stats-total">0</strong></div></div>
            <div class="metric"><span class="metric-icon"><?= azh_icon('camera') ?></span><div><span class="metric-label">Recorded takes</span><strong id="stats-recordings">0</strong></div></div>
            <div class="metric"><span class="metric-icon"><?= azh_icon('clock') ?></span><div><span class="metric-label">Recorded time</span><strong id="stats-duration">0:00</strong></div></div>
            <div class="overview-note"><span class="eyebrow">FROM QUESTION TO FINAL CUT</span><p>Record each answer. Build the edit as you go.</p></div>
        </section>

        <section class="library-body" aria-label="Saved interviews">
            <div class="library-toolbar">
                <div class="tab-bar" role="tablist" aria-label="Interview status"><button id="filter-active" class="tab active" data-library-status="active" type="button" role="tab" aria-selected="true">Active</button><button id="filter-archived" class="tab" data-library-status="archived" type="button" role="tab" aria-selected="false">Archived</button></div>
                <label class="search-field"><?= azh_icon('search') ?><span class="sr-only">Search interviews</span><input id="library-search" type="search" placeholder="Search model or interview…" autocomplete="off"></label>
            </div>
            <div id="interview-list" class="session-grid"></div>
            <div id="library-empty" class="empty-state">
                <div class="empty-icon"><?= azh_icon('camera') ?></div>
                <p class="eyebrow">READY WHEN YOU ARE</p><h2>Start your first interview</h2><p>Create a session, choose a question, and record an answer. Your clips and edit settings stay together.</p><button type="button" class="button button--primary" data-action="new-interview"><?= azh_icon('plus') ?>New interview</button>
            </div>
        </section>
    </main>
    <dialog id="new-interview-dialog" class="dialog">
        <form id="new-interview-form">
            <div class="dialog-heading"><div><p class="eyebrow">NEW SESSION</p><h2>Add an interview</h2></div><button type="button" class="icon-button" data-close-dialog="new-interview-dialog" aria-label="Close new interview form"><?= azh_icon('close') ?></button></div>
            <label class="field">Interview title<input id="new-title" name="title" required maxlength="180" placeholder="e.g. After hours with Alex" autocomplete="off"></label>
            <label class="field">Saved model profile <span class="optional">Optional</span><select id="new-profile" name="modelId"><option value="">Enter a name for this session</option></select></label>
            <label class="field">Model or stage name<input id="new-model" name="participantName" required maxlength="100" placeholder="Name for this session" autocomplete="off"></label>
            <label class="field">Questionnaire<select id="new-questionnaire" name="questionnaireId"><option value="">AZHottie starter question bank</option></select><span id="new-questionnaire-help" class="field-help">The questions are preserved with this interview.</span></label>
            <label class="field">Session notes <span class="optional">Optional</span><textarea id="new-notes" name="notes" rows="3" placeholder="Shoot concept, questions to focus on, or production notes…"></textarea></label>
            <div class="dialog-actions"><button type="button" class="button button--quiet" data-close-dialog="new-interview-dialog">Cancel</button><button type="submit" class="button button--primary"><?= azh_icon('plus') ?>Create interview</button></div>
        </form>
    </dialog>
    <?php elseif ($page === 'interview'): ?>
    <main id="interview-view" class="workspace workspace--wide">
        <nav class="breadcrumb" aria-label="Breadcrumb"><a href="library.php">Interview library</a><span>/</span><span>Recording room</span></nav>
        <section class="page-heading page-heading--compact">
            <div><p class="eyebrow">RECORDING ROOM</p><h1 id="interview-heading">Interview session</h1><p id="interview-meta" class="page-intro">Loading your interview…</p></div>
            <div class="action-group"><span id="save-state" class="save-state" role="status"></span><button id="save-interview" class="button" type="button"><?= azh_icon('save') ?>Save interview</button><a id="open-editor" class="button button--primary" href="editor.php"><?= azh_icon('film') ?>Open editor</a></div>
        </section>
        <div id="archived-banner" class="notice" hidden><span>This interview is archived.</span><button id="restore-interview" type="button" class="button button--small">Restore interview</button></div>
        <details class="session-details"><summary>Session details</summary><div class="details-grid"><label class="field">Interview title<input id="interview-title" name="title" maxlength="180"></label><label class="field">Model or stage name<input id="interview-model" name="participantName" maxlength="100"></label><label class="field">Linked model profile<select id="interview-profile"><option value="">No linked profile</option></select></label><div class="field"><span>Questionnaire</span><strong id="interview-questionnaire">AZHottie starter question bank</strong><span class="field-help">The question wording stays with this session.</span></div><label class="field field--full">Session notes<textarea id="interview-notes" name="notes" rows="2" placeholder="Notes for this interview"></textarea></label></div></details>

        <section id="phone-connections" class="panel phone-connections"><div class="panel-heading"><div><p class="eyebrow">FOOTAGE FROM YOUR TEAM</p><h2>Connect a phone</h2><p class="field-help">Scan the interview QR code, approve the phone, and receive its footage here.</p></div><button id="open-phone-pairing" type="button" class="button">Interview QR code</button></div><div id="phone-connection-content"></div></section>
        <dialog id="phone-pairing-dialog" class="dialog"><div class="dialog-heading"><h2>Connect to this interview</h2><button type="button" class="icon-button" data-close-dialog="phone-pairing-dialog" aria-label="Close phone connection"><?= azh_icon('close') ?></button></div><div id="phone-pairing-content"></div></dialog>
        <div class="interview-layout">
            <aside class="panel question-bank">
                <div class="panel-heading"><div><p class="eyebrow">THE CONVERSATION</p><h2>Question bank</h2></div><span id="question-total" class="badge">0</span></div>
                <label class="search-field search-field--panel"><?= azh_icon('search') ?><span class="sr-only">Search questions</span><input id="question-search" type="search" placeholder="Find a question…"></label>
                <label class="field compact-field"><span class="sr-only">Question category</span><select id="question-category"><option value="all">All questions</option></select></label>
                <div id="question-list" class="question-list"></div>
                <button id="questions-more" class="button button--quiet button--full" type="button" hidden>Show more questions</button>
            </aside>

            <div class="record-workspace">
                <section class="panel active-question" aria-label="Selected question">
                    <div class="question-topline"><span id="current-question-number" class="badge badge--accent">QUESTION</span><span id="current-question-category" class="muted"></span></div>
                    <h2 id="current-question-text">Choose a question to begin.</h2>
                    <div id="current-question-choices" class="question-choices"></div>
                </section>
                <section class="panel camera-panel" aria-label="Camera and recording controls">
                    <div class="camera-stage">
                        <video id="camera-preview" autoplay muted playsinline></video>
                        <div id="camera-placeholder" class="camera-placeholder"><div class="stage-icon"><?= azh_icon('camera') ?></div><h3>Your recording space</h3><p>Connect your camera and microphone when you’re ready.</p><button id="enable-camera" class="button" type="button"><?= azh_icon('camera') ?>Enable camera &amp; mic</button></div>
                        <div class="stage-label">AZHOTTIE <span>LIVE VIEW</span></div>
                        <div id="recording-indicator" class="recording-indicator" hidden><span class="record-light"></span><span>REC</span><span id="recording-timer">00:00</span></div>
                        <div id="recording-countdown" class="recording-countdown" hidden></div>
                    </div>
                    <div class="record-control-bar"><div class="action-group"><button id="record-answer" type="button" class="button button--record"><?= azh_icon('record') ?>Record video answer</button><button id="stop-recording" type="button" class="button" disabled><?= azh_icon('stop') ?>Stop &amp; save</button></div><button id="mark-answer-start" type="button" class="button button--quiet" disabled><?= azh_icon('flag') ?>Mark answer start</button></div>
                    <p id="recording-status" class="record-status" role="status">Record the question and answer together. Mark the answer’s start to trim the spoken question from your edit.</p>
                    <details id="device-settings" class="device-settings"><summary>Camera &amp; audio settings</summary><div class="device-grid"><label class="field">Camera<select id="camera-select"><option value="">Default camera</option></select></label><label class="field">Microphone<select id="microphone-select"><option value="">Default microphone</option></select></label><label class="field">Countdown<select id="countdown-select"><option value="0">No countdown</option><option value="3" selected>3 seconds</option><option value="5">5 seconds</option></select></label><label class="checkbox-field"><input id="mirror-preview" type="checkbox" checked><span>Mirror camera preview</span></label><label class="field">Camera switching<select id="camera-switch-mode"><option value="off">Manual switching</option><option value="interval">Switch on a fixed interval</option><option value="random">Switch at random intervals</option></select></label><label class="field">Switch interval <span class="field-unit">seconds</span><input id="camera-switch-interval" type="number" min="10" max="120" value="30" disabled><span class="field-help">Random timing varies around this interval.</span></label><div class="field camera-rotation-field"><span>Cameras in the rotation</span><div id="camera-rotation-list" class="camera-rotation-list"><p class="field-help">Enable your camera to see connected cameras.</p></div></div><div class="camera-switch-actions"><button id="switch-camera" class="button button--small" type="button" disabled>Switch camera now</button><p id="camera-switch-status" class="field-help" role="status">Your microphone stays the same when the camera switches.</p></div></div></details>
                </section>
                <section class="panel answer-panel"><div class="panel-heading"><div><p class="eyebrow">THIS ANSWER</p><h2>Notes &amp; takes</h2></div><span id="take-count" class="badge">0 takes</span></div><label class="field">Answer notes<textarea id="answer-notes" name="answerNotes" rows="3" placeholder="Key moments, written answer, or a note for the edit…"></textarea></label><div class="answer-toolbar"><button id="save-answer" type="button" class="button button--small"><?= azh_icon('save') ?>Save notes</button><label class="button button--small button--quiet file-button"><?= azh_icon('upload') ?>Import a video<input id="import-clip" type="file" accept="video/*"></label></div><div id="take-list" class="take-list"></div><video id="review-video" class="review-video" controls playsinline hidden></video><div id="take-review-panel" class="take-review-panel" hidden><div class="review-panel-heading"><h3 id="take-review-title">Review this take</h3><span id="take-review-time" class="timecode">00:00</span></div><div id="take-review-controls" class="review-controls"></div><form id="take-note-form" class="review-note-form"><label class="field">Note at the playhead<textarea id="take-note-text" rows="2" maxlength="4000" required placeholder="A strong line, a trim request, or something to reshoot…"></textarea></label><button type="submit" class="button button--small">Add timestamped note</button></form><div id="take-note-list" class="review-note-list"></div></div></section>
            </div>
        </div>
    </main>
    <?php elseif ($page === 'editor'): ?>
    <main id="editor-view" class="workspace workspace--wide">
        <nav class="breadcrumb" aria-label="Breadcrumb"><a href="library.php">Interview library</a><span>/</span><a id="back-to-interview" data-interview-link href="interview.php">Recording room</a><span>/</span><span>Editor</span></nav>
        <section class="page-heading page-heading--compact"><div><p class="eyebrow">THE EDITING ROOM</p><h1 id="interview-heading">Build the interview<span class="accent">.</span></h1><p id="interview-meta" class="page-intro">Your recorded answers, assembled with question cards and fades.</p></div><div class="action-group"><span id="save-state" class="save-state" role="status"></span><button id="save-interview" type="button" class="button"><?= azh_icon('save') ?>Save edit</button><button id="export-video" type="button" class="button button--primary"><?= azh_icon('download') ?>Export video</button></div></section>

        <div class="editor-layout">
            <section class="panel preview-panel"><div class="panel-heading"><h2>Final cut preview</h2><div class="inline-meta"><span id="edit-count">0 answers</span><span class="meta-divider"></span><span id="edit-duration">0:00</span></div></div><div class="editor-stage"><canvas id="edit-canvas" width="1280" height="720" aria-label="Preview of the edited interview"></canvas></div><div class="preview-controls"><div class="action-group"><button id="preview-edit" type="button" class="button"><?= azh_icon('play') ?>Preview edit</button><button id="stop-preview" type="button" class="button button--quiet" disabled><?= azh_icon('stop') ?>Stop</button></div><span id="preview-time" class="timecode">00:00 / 00:00</span></div><p id="preview-status" class="record-status" role="status">A black question card introduces each answer. The video and audio fade in and out.</p><div id="export-panel" class="export-panel" hidden><div class="export-topline"><strong id="export-status">Preparing your video…</strong><button id="cancel-export" class="button button--small button--quiet" type="button">Cancel export</button></div><progress id="export-progress" value="0" max="100">0%</progress><p class="muted">Keep this page open while the edit renders.</p></div><a id="export-download" class="button button--primary export-download" hidden download><?= azh_icon('download') ?>Download finished video</a><form id="finished-save-form" class="finished-save-form" hidden><div><p class="eyebrow">KEEP THIS FINISHED CUT</p><h3>Save to finished videos</h3><p class="field-help">Save this rendered movie to the studio, linked to this interview. Each save creates a new version.</p></div><label class="field">Video title<input id="finished-title" maxlength="200" required></label><label class="field">Description<textarea id="finished-description" rows="2" maxlength="16000"></textarea></label><label class="field">Production status<select id="finished-status"><option value="draft">Draft</option><option value="review">Awaiting review</option><option value="ready">Ready to post</option><option value="published">Published</option></select></label><div class="action-group"><button id="save-finished-video" class="button button--primary" type="submit">Save finished video</button><span id="finished-save-status" class="field-help" role="status"></span></div></form></section>

            <aside class="panel edit-settings"><div class="panel-heading"><div><p class="eyebrow">FINISHING TOUCHES</p><h2>Edit settings</h2></div></div><div class="settings-section"><h3>Title cards & fades</h3><p class="field-help">White text on a clean black screen.</p><label class="field">Title cards<select id="card-mode" data-edit-setting><option value="all">Before every clip</option><option value="perClip">Choose for each clip</option><option value="none">No title cards</option></select></label><label class="field">Card duration <span class="field-unit">seconds</span><input id="card-duration" type="number" min="0.5" max="15" step="0.5" value="3"></label><label class="field">Fade duration <span class="field-unit">seconds</span><input id="fade-duration" type="number" min="0" max="3" step="0.1" value="0.5"></label></div><div class="settings-section"><h3>Video format</h3><label class="field">Frame<select id="aspect-ratio"><option value="16:9">Landscape · 16:9</option><option value="9:16">Portrait · 9:16</option><option value="1:1">Square · 1:1</option></select></label><label class="field">Resolution<select id="export-resolution"><option value="720">720p</option><option value="1080">1080p</option></select></label><label class="field">Fit video<select id="video-fit"><option value="contain">Keep the entire frame</option><option value="cover">Fill frame and crop edges</option></select></label></div><p id="export-format-note" class="field-help">The export format is selected for your browser.</p></aside>
        </div>

        <section class="panel finished-versions-panel"><div class="panel-heading"><div><p class="eyebrow">SAVED FINISHED CUTS</p><h2>Videos from this interview</h2></div><a id="interview-videos-link" class="button button--small" href="videos.php">Finished video library</a></div><div id="finished-version-list" class="finished-version-list"><p class="empty-inline">Loading finished videos…</p></div></section>
        <section class="panel sequence-panel"><div class="panel-heading"><div><p class="eyebrow">YOUR SEQUENCE</p><h2>Answers in the edit</h2></div><span class="muted">Question card + recorded answer</span></div><p class="sequence-intro">Choose the takes to include, reorder answers, and adjust where each clip begins and ends.</p><div id="segment-list" class="segment-list"></div><div id="editor-empty" class="empty-state empty-state--compact"><div class="empty-icon"><?= azh_icon('film') ?></div><h3>No recorded answers yet</h3><p>Record your first answer in the recording room, or import a video below.</p></div><section id="pending-takes" class="pending-takes"></section><div class="editor-import"><div class="import-copy"><h3>Import an existing video</h3><p class="field-help">Enter the question, then choose the clip to import.</p><label class="field">Question on the title card<input id="import-question-title" type="text" maxlength="1000" placeholder="Question for this video" autocomplete="off" required></label></div><label class="button file-button"><?= azh_icon('upload') ?>Import video<input id="import-editor-clip" type="file" accept="video/*"></label></div></section>

        <section id="trim-panel" class="panel trim-panel" hidden><div class="panel-heading"><div><p class="eyebrow">CLIP DETAILS</p><h2 id="trim-heading">Adjust this answer</h2><p id="trim-take-label" class="field-help"></p></div><button id="close-trim" type="button" class="icon-button" aria-label="Close clip details"><?= azh_icon('close') ?></button></div><div class="trim-layout"><video id="trim-video" controls playsinline></video><div class="trim-fields"><label class="field">Question shown on the black card<textarea id="trim-question" rows="3"></textarea></label><div class="two-columns"><label class="field">Answer starts at <span class="field-unit">seconds</span><input id="trim-start" type="number" min="0" step="0.1" value="0"></label><label class="field">Answer ends at <span class="field-unit">seconds</span><input id="trim-end" type="number" min="0.1" step="0.1"></label></div><div class="action-group"><button id="use-current-start" type="button" class="button button--small">Use playhead as start</button><button id="use-current-end" type="button" class="button button--small">Use playhead as end</button></div><button id="trim-save" type="button" class="button button--primary"><?= azh_icon('check') ?>Apply clip changes</button><p id="trim-status" class="field-help" role="status"></p><div class="clip-review-block"><h3>Take review</h3><div id="trim-review-controls" class="review-controls"></div><form id="trim-note-form" class="review-note-form"><label class="field">Note at the playhead<textarea id="trim-note-text" rows="2" maxlength="4000" required placeholder="Write a note for this exact moment…"></textarea></label><div class="action-group"><button class="button button--small" type="submit">Add timestamped note</button><span id="trim-review-time" class="timecode">00:00</span></div></form><div id="trim-note-list" class="review-note-list"></div></div></div></div></section>
    </main>
    <?php endif;
}
