<?php
declare(strict_types=1);

function az_render_work_view(string $page): void
{
    $admin = $page === 'productions';
    ?>
<main class="workspace work-workspace">
    <div class="page-heading"><div><p class="eyebrow"><?= $admin ? 'THE WORK BEHIND THE BRAND' : 'YOUR NEXT MOMENT ON CAMERA' ?></p><h1><?= $admin ? 'Productions' : 'My work' ?><span class="accent">.</span></h1><p class="page-intro"><?= $admin ? 'Bring the brief, the team, and the footage into one project. Take each story from a first idea to a finished cut.' : 'See the productions you are included in, pick up an open shot, and follow your assignments through review.' ?></p></div><div class="work-heading-actions"><?php if ($admin): ?><button id="work-new-project" type="button" class="button button--primary">New production</button><?php endif; ?><button id="work-notification-toggle" type="button" class="button" aria-expanded="false" aria-controls="work-notifications">Updates <span id="work-unread-count" class="work-count-pill" hidden>0</span></button></div></div>
    <div id="work-error" class="error-banner" role="alert" hidden></div>
    <section id="work-notifications" class="panel work-panel work-notifications" aria-label="Production updates" hidden><div class="work-section-heading"><div><p class="eyebrow">KEEP THE WORK MOVING</p><h2>Updates</h2></div><button id="work-notification-read-all" type="button" class="text-button">Mark all read</button></div><div id="work-notification-list"></div></section>
    <section id="work-list-view" aria-label="<?= $admin ? 'Production projects' : 'Your production work' ?>">
        <?php if ($admin): ?>
        <div class="work-toolbar"><label class="field work-search">Search productions<input id="work-search" type="search" placeholder="A project, a brief, a product line…"></label><label class="field work-filter">Status<select id="work-project-status"><option value="active">In progress</option><option value="draft">Drafts</option><option value="completed">Completed</option><option value="archived">Archived</option><option value="all">Every status</option></select></label></div>
        <?php else: ?>
        <nav id="work-tabs" class="work-tabs" aria-label="My work sections"><button class="button is-active" type="button" data-work-tab="assigned" aria-pressed="true">Assigned to me</button><button class="button" type="button" data-work-tab="projects" aria-pressed="false">My productions</button><button class="button" type="button" data-work-tab="open" aria-pressed="false">Open shots</button><button class="button" type="button" data-work-tab="submissions" aria-pressed="false">My submissions</button><button class="button" type="button" data-work-tab="completed" aria-pressed="false">Completed work</button></nav>
        <div class="work-toolbar"><label class="field work-search">Search your work<input id="work-search" type="search" placeholder="Find a project or shot…"></label><button id="work-refresh" class="button" type="button">Refresh work</button></div>
        <?php endif; ?>
        <div id="work-metrics" class="work-metrics" aria-label="Work overview"></div>
        <p id="work-list-count" class="work-list-count" role="status">Loading your work…</p>
        <div id="work-list" class="work-grid" aria-live="polite"></div>
        <button id="work-more" class="button work-more" type="button" hidden>Show more</button>
    </section>
    <section id="work-detail" aria-label="Production details" hidden></section>
</main>
<div id="work-toast" class="toast" role="status"></div>
<?php
}
