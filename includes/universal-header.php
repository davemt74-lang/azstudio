<?php
declare(strict_types=1);

/**
 * Shared, role-aware navigation for ALL AZHottie pages.
 * Include assets/universal-header.css in <head>; script is emitted here once.
 * No admin destinations are emitted for non-admin accounts.
 */
function az_render_universal_header(string $current, ?array $user = null, bool $installed = true): void
{
    $admin = $user !== null && az_has_role($user, 'admin');
    $liveEditor = $user !== null && az_has_role($user, 'editor');
    $team = $user !== null && ($admin || $liveEditor || az_has_role($user, 'model') || az_has_role($user, 'professional'));
    $current = in_array($current, ['index','watch','dashboard','agent-chat','calendar','classes','appointments','account','user','settings','accounts','my-work','my-media','my-videos','my-editor','my-camera','productions','director','library','interview','editor','profiles','questionnaires','videos','user-submissions','admin','login','signup','install','phone','apply','casting','admin-studio','live-director','live-productions','live','live-shows'], true) ? $current : 'index';
    $studioCurrent = in_array($current, ['productions','director','library','interview','editor','profiles','questionnaires','videos','user-submissions','accounts','admin','casting'], true);
    $item = static function (string $href, string $label, bool $active = false): string {
        return '<a class="azh-nav-link' . ($active ? ' is-active' : '') . '" href="' . az_h($href) . '"' . ($active ? ' aria-current="page"' : '') . '>' . az_h($label) . '</a>';
    };
    $signedIn = $user !== null;
    $hasWorkspace = in_array($current, [
        'dashboard','admin-studio','live-director','live-productions','live','live-shows','agent-chat','account','user','settings','accounts','calendar','classes','appointments','my-work','my-media','my-videos','my-editor','my-camera',
        'productions','director','library','interview','editor','profiles','questionnaires','videos','user-submissions','admin','casting',
    ], true);
    if ($signedIn && !function_exists('az_render_member_nav_links')) {
        require_once __DIR__ . '/member-layout.php';
    }
    ?>
<header id="azh-global-header" class="azh-global-header" data-authenticated="<?= $signedIn ? 'true' : 'false' ?>">
  <div class="azh-global-header__inner">
    <?php if ($signedIn): ?>
      <?php if ($current !== 'user' && $current !== 'live-director' && $current !== 'live-shows'): ?>
        <button id="azh-member-menu-button" class="azh-global-header__menu-toggle azh-global-header__menu-toggle--member" type="button" aria-label="Open sidebar navigation" aria-controls="azh-side-navigation" aria-expanded="<?= $hasWorkspace ? 'true' : 'false' ?>" aria-haspopup="false"><span aria-hidden="true">☰</span></button>
      <?php endif; ?>
    <?php else: ?>
      <button class="azh-global-header__menu-toggle" type="button" aria-label="Open navigation" aria-controls="azh-global-navigation" aria-expanded="false"><span aria-hidden="true">☰</span><span class="azh-menu-label">Menu</span></button>
    <?php endif; ?>
    <a class="azh-global-header__brand" href="<?= $signedIn ? 'dashboard.php' : 'index.php' ?>" aria-label="AZHottie <?= $signedIn ? 'workspace' : 'home' ?>">AZHOTTIE<span aria-hidden="true">.</span></a>
    <?php if ($signedIn): ?>
      <?php if ($admin || az_has_role($user,'model')): ?>
      <button type="button" id="azh-aw-bell" class="azh-aw-bell" aria-label="Open agent notifications" aria-controls="azh-aw-drawer" aria-expanded="false" title="Agent notifications">
        <svg viewBox="0 0 24 24" aria-hidden="true" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
        <span id="azh-aw-badge" class="azh-aw-badge" hidden></span>
      </button>
      <?php endif; ?>
      <?php $social=az_is_installed()?az_social_row((int)$user['id']):az_social_empty((int)$user['id']); $memberName=az_is_installed()?az_social_name($user):(string)$user['name']; ?>
      <details class="azh-account-menu" id="azh-account-menu">
        <summary aria-label="Open account menu"><span class="azh-account-avatar"><?php if(!empty($social['avatar_storage_name'])):?><img src="<?= az_h(az_url('user-media.php?u='.(int)$user['id'].'&type=avatar')) ?>" alt=""><?php else: ?><?= az_h(strtoupper(substr((string)$user['name'],0,1))) ?><?php endif;?></span><span class="azh-account-menu__name"><?= az_h($memberName) ?></span><span aria-hidden="true" class="azh-account-menu__chevron">⌄</span></summary>
        <div class="azh-account-menu__panel" role="group" aria-label="Account actions">
          <strong><?= az_h($memberName) ?></strong><small>AZHottie member</small>
          <a href="<?= az_h(az_url('user.php')) ?>">My profile</a>
          <a href="<?= az_h(az_url('account-settings.php')) ?>">Account settings</a>
          <?php if ($admin || $liveEditor): ?><a href="<?= az_h(az_url('admin-studio.php')) ?>">Admin Studio</a><?php endif; ?>
          <?php if ($team): ?><a href="<?= az_h(az_url('my-media.php')) ?>">My media gallery</a><?php endif; ?>
          <?php if ($admin || az_has_role($user, 'model')): ?><a href="<?= az_h(az_url('my-videos.php')) ?>">My Videos</a><?php endif; ?>
          <form method="post" action="<?= az_h(az_url('logout.php')) ?>"><input type="hidden" name="csrf" value="<?= az_h(az_csrf_token()) ?>"><button type="submit">Sign out</button></form>
        </div>
      </details>
    <?php else: ?>
      <nav id="azh-global-navigation" class="azh-global-header__nav" aria-label="AZHottie navigation">
      <?= $item('index.php', 'Home', $current === 'index') ?>
      <?php if ($installed): ?>
      <?= $item('watch.php', 'Watch', $current === 'watch') ?>
      <?= $item('live-shows.php', 'Live shows', $current === 'live-shows') ?>
      <?= $item('apply.php', 'Model casting', $current === 'apply') ?>
      <?= $item('signup.php', 'Events', $current === 'calendar') ?>
      <?= $item('signup.php', 'Classes', $current === 'classes') ?>
      <?= $item('signup.php', 'Appointments', $current === 'appointments') ?>
      <?= $item('login.php', 'Sign in', $current === 'login') ?>
      <?= $item('signup.php', 'Join AZHottie', $current === 'signup') ?>
      <?php else: ?>
      <?= $item('install.php', 'Set up studio', $current === 'install') ?>
      <?php endif; ?>
      </nav>
    <?php endif; ?>
  </div>
</header>
<?php if ($signedIn && ($admin || az_has_role($user,'model'))): ?>
<div id="azh-aw-shade" class="azh-aw-shade" hidden></div>
<aside id="azh-aw-drawer" class="azh-aw-drawer" role="dialog" aria-modal="true" aria-labelledby="azh-aw-title" aria-hidden="true" tabindex="-1" hidden data-csrf="<?= az_h(az_csrf_token()) ?>">
  <div class="azh-aw-header">
    <div class="azh-aw-heading"><span class="azh-aw-star" aria-hidden="true">✳</span><div><p>YOUR WORKSPACE AGENT</p><h2 id="azh-aw-title">Agent activity<span>.</span></h2></div></div>
    <button type="button" class="azh-aw-close" id="azh-aw-close" aria-label="Close agent sidebar">×</button>
  </div>
  <nav class="azh-aw-tabs" aria-label="Agent views" role="tablist">
    <button type="button" class="is-active" id="azh-aw-tab-notifications" role="tab" data-aw-tab="notifications" aria-controls="azh-aw-content" aria-selected="true">Notifications</button>
    <button type="button" id="azh-aw-tab-history" role="tab" data-aw-tab="history" aria-controls="azh-aw-content" aria-selected="false">History</button>
    <button type="button" id="azh-aw-tab-brain" role="tab" data-aw-tab="brain" aria-controls="azh-aw-content" aria-selected="false">Brain</button>
  </nav>
  <div class="azh-aw-actions"><span id="azh-aw-updated" role="status">Checking for updates…</span><div><button type="button" id="azh-aw-refresh">Refresh</button><button type="button" id="azh-aw-read-all" hidden>Mark all read</button></div></div>
  <div id="azh-aw-content" class="azh-aw-content" role="tabpanel" aria-labelledby="azh-aw-tab-notifications" tabindex="0"><p class="azh-aw-loading">Loading verified activity…</p></div>
  <div class="azh-aw-footer"><a href="agent-chat.php">Ask AZHottie Agent <span aria-hidden="true">↗</span></a><small>Assignments and approvals change only when you confirm an action.</small></div>
</aside>
<script type="module" src="assets/agent-workspace.js?v=1.26.0"></script>
<?php endif; ?>
<?php if ($signedIn && !$hasWorkspace): ?>
  <aside class="azh-floating-sidebar" id="azh-side-navigation" aria-label="Workspace sidebar" hidden><?php az_render_member_nav_links($current, $user); ?></aside>
<?php endif; ?>
<script defer src="assets/universal-header.js"></script>

<?php
}
