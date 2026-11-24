<?php
declare(strict_types=1);
/**
 * Shared signed-in AZHottie shell. Existing page content remains in the right
 * canvas; a private member chat composer opens the agent canvas when submitted.
 */
function az_is_admin_workspace_page(string $page, array $user): bool
{
    return in_array($page, ['admin-studio','live-director','live-productions','productions','director','library','interview','editor','profiles','casting','questionnaires','videos','user-submissions','publishing','commerce','shop-homepage-editor','accounts','admin','creator-club-admin'], true)
        || (az_has_role($user, 'admin') && in_array($page, ['creator-analytics','agent-chat','account','user','settings'], true));
}

function az_render_member_nav_links(string $page, array $user): void
{
    $admin = az_has_role($user, 'admin');
    $liveEditor = $admin || az_has_role($user, 'editor');
    $adminPage = az_is_admin_workspace_page($page, $user);
    $contributor = $admin || az_has_role($user, 'editor') || az_has_role($user, 'model') || az_has_role($user, 'professional');
    $links = [
        ['dashboard.php', 'Overview', 'dashboard', '⌂'],
        ['agent-chat.php', 'Agent chat', 'agent-chat', '✧'],
        ['user.php', 'My profile', 'user', '◌'],
        ['account-settings.php', 'Account settings', 'settings', '⚙'],
        ['calendar.php', 'Private calendar', 'calendar', '▦'],
        ['classes.php', 'Classes', 'classes', '◇'],
        ['appointments.php', 'Spa & appointments', 'appointments', '✧'],
        ['live.php', 'Live AZHottie', 'live', '◉'],
        ['live-shows.php', 'Published live shows', 'live-shows', '▶'],
        ['shop.php', 'Shop', 'shop', '◇'],
        ['creator-clubs.php', 'Explore Creator Clubs', 'creator-clubs', '♧'],
        ['creator-club-workspace.php', 'My Clubs', 'creator-club-workspace', '♡'],
    ];
    $teamLinks = [
        ['my-work.php', 'My assignments', 'my-work', '▤'],
        ['my-media.php', 'My media gallery', 'my-media', '▣'],
        ['my-videos.php', 'My Videos', 'my-videos', '▷'],
        ['creator-club.php', 'My Creator Club', 'creator-club', '♡'],
        ['commerce.php', 'Creator Commerce', 'commerce', '◈'],
        ['creator-analytics.php', 'Video performance', 'creator-analytics', '▥'],
        ['my-editor.php', 'Video editor', 'my-editor', '✂'],
        ['my-camera.php', 'Phone camera', 'my-camera', '◎'],
    ];
    $adminGroups = [
        'account' => ['Account', '◌', [
            ['user.php', 'My profile', 'user', '◌'],
            ['account-settings.php', 'Account settings', 'settings', '⚙'],
            ['accounts.php', 'Manage accounts', 'accounts', '◈'],
        ]],
        'content' => ['Content', '▣', [
            ['live-director.php', 'Live Director', 'live-director', '●'],
            ['live-productions.php', 'Saved live productions', 'live-productions', '▣'],
            ['productions.php', 'Productions', 'productions', '▥'],
            ['library.php', 'Interviews', 'library', '▣'],
            ['profiles.php', 'Models', 'profiles', '♧'],
            ['casting.php', 'Casting CRM', 'casting', '✧'],
            ['questionnaires.php', 'Questionnaires', 'questionnaires', '☷'],
            ['videos.php', 'Video library', 'videos', '▸'],
            ['user-submissions.php', 'User Submissions', 'user-submissions', '▤'],
            ['creator-analytics.php', 'Creator analytics', 'creator-analytics', '▥'],
            ['publishing.php', 'Publishing & Growth', 'publishing', '◈'],
        ]],
        'commerce' => ['Commerce', '◇', [
            ['commerce.php', 'Commerce & Affiliates', 'commerce', '◆'],
            ['shop-homepage-editor.php', 'Shop Homepage Editor', 'shop-homepage-editor', '✎'],
            ['creator-club-admin.php', 'Membership reviews', 'creator-club-admin', '♡'],
        ]],
        'agentic' => ['Agentic', '✧', [
            ['agent-chat.php', 'Agent chat', 'agent-chat', '✧'],
            ['commerce.php?tab=agent', 'Commerce Agent briefing', 'commerce-agent', '◆'],
        ]],
        'platform' => ['Platform Settings', '⚙', [
            ['admin-studio.php', 'Admin overview', 'admin-studio', '⌂'],
            ['admin.php', 'AI settings', 'admin', '✳'],
        ]],
    ];
    $label = [
        'dashboard'=>'Overview','live'=>'Live AZHottie','admin-studio'=>'Admin overview','live-director'=>'Live Director','live-productions'=>'Saved live productions','agent-chat'=>'Agent chat','account'=>'My profile','user'=>'My profile','settings'=>'Account settings','calendar'=>'Private calendar',
        'classes'=>'Classes','appointments'=>'Spa & appointments','my-work'=>'My assignments','my-videos'=>'My Videos',
        'my-camera'=>'Phone camera','productions'=>'Productions','director'=>'Production Director','library'=>'Interviews','my-media'=>'My media gallery','my-editor'=>'Video editor',
        'interview'=>'Recording room','editor'=>'Video editor','profiles'=>'Models',
        'questionnaires'=>'Questionnaires','casting'=>'Casting CRM','videos'=>'Finished videos','user-submissions'=>'User Submissions','publishing'=>'Publishing & Growth','commerce'=>'Commerce & Affiliates','shop-homepage-editor'=>'Shop Homepage Editor','accounts'=>'Manage accounts',
        'creator-analytics'=>'Video performance','creator-clubs'=>'Creator Clubs','creator-club'=>'Creator Club','creator-club-workspace'=>'My Clubs','creator-club-admin'=>'Membership reviews','admin'=>'AI settings',
    ][$page] ?? 'My workspace';
    // Details and nested routes retain the corresponding navigation highlight.
    $highlight = ['director'=>'productions','interview'=>'library','editor'=>'library','account'=>'user'][$page] ?? $page;
    if ($page === 'creator-club' && (!az_has_role($user, 'model') || (isset($_GET['creator']) && (int)$_GET['creator'] !== (int)$user['id']))) $highlight = 'creator-clubs';
    $teamLinks = array_values(array_filter($teamLinks, static fn(array $entry): bool => $entry[2] !== 'creator-club' || az_has_role($user, 'model')));
    if ($admin && $page === 'commerce' && ($_GET['tab'] ?? '') === 'agent') $highlight = 'commerce-agent';
    $render = static function(array $entries) use ($highlight): void {
        foreach ($entries as [$url, $text, $route, $glyph]) {
            $active = $route === $highlight;
            echo '<a class="azh-side-link' . ($active ? ' is-active' : '') . '" href="' . az_h($url) . '"';
            if ($active) echo ' aria-current="page"';
            echo '><span class="azh-side-glyph" aria-hidden="true">' . az_h($glyph) . '</span><span>' . az_h($text) . '</span></a>';
        }
    };
    // Native disclosure controls work with a keyboard and without JavaScript.
    // A distinct name per navigation prevents shared-header copies interfering.
    static $navigationInstance = 0;
    $accordionName = 'azh-admin-categories-' . ++$navigationInstance;
    ?>
    <div class="azh-side-navigation">
      <nav aria-label="<?= $adminPage ? 'Admin sections' : 'Member sections' ?>">
      <?php if ($adminPage): ?>
        <p class="azh-side-label">ADMIN WORKSPACE</p>
        <?php foreach ($adminGroups as $key => [$title, $glyph, $entries]):
            if (!$admin) $entries = array_values(array_filter($entries, static fn(array $entry): bool => in_array($entry[2], ['admin-studio','live-director','live-productions'], true)));
            if (!$entries) continue;
            $activeGroup = in_array($highlight, array_column($entries, 2), true);
        ?>
        <details class="azh-side-group<?= $activeGroup ? ' is-current-group' : '' ?>" name="<?= az_h($accordionName) ?>" data-side-group="<?= az_h($key) ?>"<?= $activeGroup ? ' open' : '' ?>>
          <summary><span class="azh-side-glyph" aria-hidden="true"><?= az_h($glyph) ?></span><span><?= az_h($title) ?></span><span class="azh-side-group-chevron" aria-hidden="true"></span></summary>
          <div class="azh-side-group-links"><?php $render($entries); ?></div>
        </details>
        <?php endforeach; ?>
        <p class="azh-side-label">RETURN</p>
        <?php $render([['dashboard.php','Member workspace','dashboard','←']]); ?>
      <?php else: ?>
        <p class="azh-side-label">MEMBERSHIP</p><?php $render($links); ?>
        <?php if ($contributor): ?><p class="azh-side-label">CREATOR WORK</p><?php $render($admin || az_has_role($user, 'model') ? $teamLinks : array_values(array_filter($teamLinks, static fn(array $entry): bool => !in_array($entry[2], ['my-videos','creator-analytics'], true)))); ?><?php endif; ?>
        <?php if ($liveEditor): ?><p class="azh-side-label">STUDIO ACCESS</p><?php $render([['admin-studio.php','Admin Studio','admin-studio','▥']]); ?><?php endif; ?>
      <?php endif; ?>
      <p class="azh-side-label">DISCOVER</p>
      <a class="azh-side-link" href="watch.php"><span class="azh-side-glyph" aria-hidden="true">▷</span><span>Watch AZHottie</span></a>
      <a class="azh-side-link" href="index.php"><span class="azh-side-glyph" aria-hidden="true">↗</span><span>Public site</span></a></nav>
      <p class="azh-side-foot">AZHOTTIE · ARIZONA<br>Membership &amp; studio</p>
      <form class="azh-side-signout" method="post" action="logout.php"><input type="hidden" name="csrf" value="<?= az_h(az_csrf_token()) ?>"><button type="submit" class="azh-side-link"><span class="azh-side-glyph" aria-hidden="true">↪</span><span>Sign out</span></button></form>
    </div>
<?php
}

function az_render_member_layout_open(string $page, array $user): void
{
    ?>
<link rel="stylesheet" href="assets/member-layout.css?v=1.57.1">
<div class="azh-member-layout" id="azh-member-layout">
  <aside class="azh-member-sidebar" aria-label="Member workspace navigation">
    <div id="azh-side-navigation"><?php az_render_member_nav_links($page, $user); ?></div>
  </aside>
  <div class="azh-member-canvas" id="azh-page-canvas">
    <div class="azh-canvas-topline"><span>AZHOTTIE <span class="azh-canvas-slash">/</span> <?= az_h($page === 'my-media' ? 'My media gallery' : ('dashboard' === $page ? 'Overview' : ucwords(str_replace('-', ' ', $page)))) ?></span><span class="azh-canvas-member-pill"><span aria-hidden="true">✳</span> <?= az_is_admin_workspace_page($page, $user) ? 'ADMIN STUDIO' : 'MEMBER AREA' ?></span></div>
<?php
}

function az_render_member_layout_close(bool $withAgent = true): void
{
    ?>
    <?php if ($withAgent): ?>
    <div class="azh-agent-dock" id="azh-agent-dock" data-agent-dock data-csrf="<?= az_h(az_csrf_token()) ?>">
      <form id="azh-agent-compose" class="azh-agent-compose" aria-label="AZHottie message composer">
        <div class="azh-agent-compose-row">
          <textarea id="azh-agent-input" rows="1" maxlength="2200" placeholder="Message AZHottie Agent…" aria-label="Message AZHottie Agent"></textarea>
          <button type="submit" id="azh-agent-send" aria-label="Send message" title="Send message"><span aria-hidden="true">↑</span></button>
        </div>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>
<script defer src="assets/member-layout.js?v=1.57.1"></script>
<?php if ($withAgent): ?>
<link rel="stylesheet" href="assets/agent-chat.css?v=1.29.2">
<script type="module" src="assets/agent-chat.js?v=1.29.2"></script>
<?php endif; ?>
<?php
}
