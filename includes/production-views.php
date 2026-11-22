<?php
declare(strict_types=1);

function render_production_view(string $page): void
{
    $copy = [
        'profiles' => ['THE PEOPLE BEHIND THE BRAND', 'Model profiles', 'Keep each model’s details, interview history, and production notes together.', 'Add model'],
        'questionnaires' => ['SET THE CONVERSATION', 'Questionnaires', 'Six editable AZHottie questionnaires are ready to use. You can change every question and answer; existing interviews keep their saved copy.', 'New questionnaire'],
        'videos' => ['THE FINISHED WORK', 'Finished videos', 'Your saved cuts, versions, and publishing details, connected to their original interviews.', ''],
    ][$page];
    ?>
    <div id="toast" class="toast" role="status" aria-live="polite"></div>
    <main class="workspace production-workspace" id="production-workspace">
        <section class="page-heading">
            <div><p class="eyebrow"><?= az_h($copy[0]) ?></p><h1><?= az_h($copy[1]) ?><span class="accent">.</span></h1><p class="page-intro"><?= az_h($copy[2]) ?></p></div>
            <?php if ($copy[3] !== ''): ?><button id="production-new" type="button" class="button button--primary"><?= azh_icon('plus') ?><?= az_h($copy[3]) ?></button><?php else: ?><a class="button" href="library.php"><?= azh_icon('camera') ?>Open interviews</a><?php endif; ?>
        </section>
        <div id="production-error" class="error-banner" role="alert" hidden><span id="production-error-text"></span><button type="button" class="button button--small" id="production-retry">Retry</button></div>
        <section id="production-list-view" aria-label="<?= az_h($copy[1]) ?>">
            <div class="production-toolbar">
                <label class="search-field"><?= azh_icon('search') ?><span class="sr-only">Search <?= az_h(strtolower($copy[1])) ?></span><input id="production-search" type="search" placeholder="<?= $page === 'profiles' ? 'Search models or interests…' : ($page === 'questionnaires' ? 'Search question sets…' : 'Search titles or models…') ?>" autocomplete="off"></label>
                <div class="production-filters">
                    <label class="field compact-field"><span class="sr-only">Archive filter</span><select id="production-archive"><option value="active">Active</option><option value="archived">Archived</option><option value="all">All</option></select></label>
                    <?php if ($page === 'videos'): ?><label class="field compact-field"><span class="sr-only">Video status</span><select id="production-status"><option value="all">Every status</option><option value="draft">Draft</option><option value="review">Awaiting review</option><option value="ready">Ready to post</option><option value="published">Published</option></select></label><button id="clear-interview-filter" type="button" class="button button--small" hidden>Show all interviews</button><?php endif; ?>
                </div>
            </div>
            <p id="production-count" class="production-count" role="status">Loading…</p>
            <div id="production-list" class="production-grid" aria-busy="true"></div>
            <div id="production-empty" class="empty-state" hidden><div class="empty-icon"><?= azh_icon($page === 'videos' ? 'film' : 'folder') ?></div><h2 id="production-empty-title"></h2><p id="production-empty-text"></p></div>
            <button id="production-more" type="button" class="button production-more" hidden>Show more</button>
        </section>

        <section id="production-detail" class="production-detail" hidden>
            <div class="production-detail-top"><button type="button" id="production-back" class="button button--quiet">Back to <?= az_h(strtolower($copy[1])) ?></button><span id="production-save-state" role="status" class="save-state"></span></div>
            <?php if ($page === 'profiles'): ?>
            <div class="production-detail-grid">
                <form id="profile-form" class="panel production-form">
                    <div class="production-section-heading"><div><p class="eyebrow">MODEL DETAILS</p><h2 id="profile-heading">New model</h2></div><span id="profile-state" class="badge" hidden></span></div>
                    <div class="profile-photo-editor"><div id="profile-photo-preview" class="profile-portrait profile-portrait--large" aria-label="Model profile photo"></div><div><label class="button file-button"><?= azh_icon('upload') ?>Choose profile photo<input type="file" id="profile-photo" accept="image/jpeg,image/png,image/webp"></label><p class="field-help">JPG, PNG, or WebP. Up to 5 MB.</p><p id="profile-photo-status" class="field-help" role="status"></p></div></div>
                    <label class="field">Stage name<input id="profile-stage-name" name="stageName" required maxlength="200" autocomplete="off" placeholder="Name used for interviews and credits"></label>
                    <label class="field">Public bio<textarea id="profile-public-bio" name="publicBio" rows="3" maxlength="10000" placeholder="A short bio for use in future public content"></textarea><span class="field-help">Saved here for your team. This page does not publish the bio.</span></label>
                    <div class="two-columns"><label class="field">Social handles<textarea id="profile-social" name="socialHandles" rows="3" maxlength="4000" placeholder="Platform and handle, one per line"></textarea></label><label class="field">Project interests<textarea id="profile-interests" name="interests" rows="3" maxlength="4000" placeholder="Clothing, fitness, comedy, photography…"></textarea></label></div>
                    <label class="field">Availability<textarea id="profile-availability" name="availability" rows="2" maxlength="4000" placeholder="Preferred days, times, or upcoming availability"></textarea></label>
                    <label class="field">Private production notes<textarea id="profile-private-notes" name="privateNotes" rows="4" maxlength="16000" placeholder="Casting notes, preparation, and follow-ups for your team"></textarea></label>
                    <div class="production-form-actions"><button type="submit" class="button button--primary"><?= azh_icon('save') ?>Save model</button><button type="button" id="profile-archive" class="button" hidden>Archive model</button><button type="button" id="profile-delete" class="text-button danger" hidden>Delete model</button></div>
                </form>
                <aside class="panel production-history"><div class="production-section-heading"><div><p class="eyebrow">PAST &amp; UPCOMING</p><h2>Interview history</h2></div></div><p id="profile-history-intro" class="muted">Save this model to link a new interview.</p><a id="profile-new-interview" class="button button--primary" href="library.php?new=1" hidden><?= azh_icon('plus') ?>New interview with this model</a><div id="profile-interviews" class="production-history-list"></div></aside>
            </div>
            <?php elseif ($page === 'questionnaires'): ?>
            <form id="questionnaire-form" class="questionnaire-form">
                <section class="panel production-form"><div class="production-section-heading"><div><p class="eyebrow">REUSABLE QUESTION SET</p><h2 id="questionnaire-heading">New questionnaire</h2></div><span id="questionnaire-revision" class="badge" hidden></span></div><label class="field">Questionnaire name<input id="questionnaire-name" name="name" required maxlength="200" placeholder="e.g. First impressions or Comedy audition"></label><label class="field">Description<textarea id="questionnaire-description" name="description" rows="2" maxlength="4000" placeholder="What this session is for, and how to use the questions"></textarea></label><div class="production-form-actions"><button type="submit" class="button button--primary"><?= azh_icon('save') ?>Save questionnaire</button><a id="questionnaire-start" class="button" href="library.php?new=1" hidden>Use for new interview</a><button id="questionnaire-duplicate" type="button" class="button" hidden>Duplicate set</button><button id="questionnaire-archive" type="button" class="button" hidden>Archive</button><button id="questionnaire-delete" type="button" class="text-button danger" hidden>Delete</button></div><p class="field-help">Changes apply when this set is chosen for a new interview. Questions already saved with an interview stay unchanged.</p></section>
                <section class="questionnaire-questions" aria-label="Questions in this set"><div class="production-section-heading"><div><h2>Questions <span id="questionnaire-question-count" class="muted">0</span></h2><p class="field-help">Arrange the conversation, write choices, and mark private casting questions.</p></div><button id="questionnaire-add" type="button" class="button"><?= azh_icon('plus') ?>Add question</button></div><div id="questionnaire-questions"></div><div id="questionnaire-no-questions" class="empty-state empty-state--compact"><h3>Build your first question</h3><p>Add your own question or select prompts from the AZHottie starter bank below.</p></div></section>
                <details class="panel question-bank-import"><summary>Add from the AZHottie starter bank</summary><div class="question-bank-import-body"><div class="production-toolbar"><label class="field compact-field">Category<select id="bank-category"><option value="all">All categories</option></select></label><button type="button" id="bank-add-all" class="button">Copy all 80 starter questions</button></div><div id="bank-list" class="bank-selection"></div><div class="production-form-actions"><button type="button" id="bank-add-selected" class="button button--primary">Add selected questions</button><button type="button" id="bank-more" class="button" hidden>Show more prompts</button><span id="bank-selected-count" class="field-help" role="status">0 selected</span></div></div></details>
                <div class="production-bottom-actions"><button type="button" id="questionnaire-add-bottom" class="button"><?= azh_icon('plus') ?>Add question</button><button type="submit" class="button button--primary"><?= azh_icon('save') ?>Save questionnaire</button></div>
            </form>
            <?php elseif ($page === 'videos'): ?>
            <div class="production-detail-grid production-detail-grid--video">
                <section class="panel finished-player-panel"><video id="finished-player" controls playsinline preload="metadata"></video><div class="production-video-caption"><h2 id="video-heading">Finished video</h2><p id="video-meta" class="muted"></p><div class="production-form-actions"><a id="video-download" class="button button--primary" download><?= azh_icon('download') ?>Download video</a><a id="video-source" class="button">Source interview</a><a id="video-editor" class="button">Open interview editor</a></div></div></section>
                <form id="video-form" class="panel production-form"><div class="production-section-heading"><div><p class="eyebrow">THIS FINISHED CUT</p><h2>Video details</h2></div><span id="video-version" class="badge"></span></div><label class="field">Video title<input id="video-title" name="title" required maxlength="200"></label><label class="field">Description<textarea id="video-description" name="description" rows="4" maxlength="16000" placeholder="What’s in this cut, or a draft post description"></textarea></label><label class="field">Public contributor credit<input id="video-credit" name="participantName" maxlength="200" placeholder="Stage names or a team credit"><span class="field-help">This credit is copied to the fan post when you publish or update it.</span></label><label class="field">Status<select id="video-status" name="status"><option value="draft">Draft</option><option value="review">Awaiting review</option><option value="ready">Ready to post</option><option value="published">Published</option></select></label><label class="field">Published URL <span class="optional">Optional</span><input id="video-published-url" name="publishedUrl" type="url" maxlength="2000" placeholder="https://"><span class="field-help">Track where you posted this version.</span></label><a id="video-published-link" class="text-button" target="_blank" rel="noopener noreferrer" hidden>Open published page</a><div class="production-form-actions"><button type="submit" class="button button--primary"><?= azh_icon('save') ?>Save details</button><button type="button" id="video-archive" class="button">Archive video</button><button type="button" id="video-delete" class="text-button danger">Delete video</button></div><p class="field-help">Each saved export has its own version. Updating these details does not alter the video itself.</p></form>
                <section class="panel production-form video-cover-panel">
                    <div><p class="eyebrow">THE FIRST IMPRESSION</p><h2>Video cover</h2></div>
                    <div class="video-cover-layout"><div id="video-cover-preview" class="video-cover-preview"><span>No cover chosen</span></div><div class="video-cover-controls">
                        <p id="video-cover-status" class="field-help" role="status">Upload an image or pause the video on a frame you like, then capture it.</p>
                        <label class="field">Upload cover image<input id="video-cover-upload" type="file" accept="image/jpeg,image/png,image/webp"><span class="field-help">JPG, PNG, or WebP · up to 5 MB. Your image keeps its proportions; cards use a 16:9 crop.</span></label>
                        <div class="production-form-actions"><button type="button" class="button button--primary" id="video-cover-capture" disabled>Capture current frame</button><button type="button" class="text-button" id="video-cover-remove" hidden>Remove cover</button></div>
                        <p class="field-help">Cover changes save immediately and also update an existing public post. The video itself stays unchanged.</p>
                    </div></div>
                </section>
                <section class="panel production-form fan-publication-panel"><p class="eyebrow">FOR THE FANS</p><h2>Publish this finished cut</h2><p class="field-help">Share the finished video, saved title, description, and model credit on the public Watch page. Review the video and save your details first.</p><p id="video-fan-status" role="status"></p><div class="production-form-actions"><button type="button" class="button button--primary" id="video-fan-publish">Publish to fan feed</button><button type="button" class="button" id="video-fan-unpublish" hidden>Unpublish</button><a class="button" id="video-fan-link" target="_blank" rel="noopener noreferrer" hidden>View public post</a></div><p class="field-help">Each publication keeps its own copy of the title and description. Use Update fan post to publish later metadata changes.</p></section>
            </div>
            <?php endif; ?>
        </section>
    </main>
    <?php
}
