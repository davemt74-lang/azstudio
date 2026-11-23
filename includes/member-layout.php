<?php
declare(strict_types=1);
/**
 * Shared signed-in AZHottie shell. Existing page content remains in the right
 * canvas; a private member chat composer opens the agent canvas when submitted.
 */
function az_render_member_nav_links(string $page, array $user): void
{
    $admin = az_has_role($user, 'admin');
    $liveEditor = $admin || az_has_role($user, 'editor');
    $adminPage = in_array($page, ['admin-studio','live-director','live-productions','productions','director','library','interview','editor','profiles','casting','questionnaires','videos','user-submissions','publishing','commerce','accounts','admin'], true) || ($admin && $page==='creator-analytics');
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
    ];
    $teamLinks = [
        ['my-work.php', 'My assignments', 'my-work', '▤'],
        ['my-media.php', 'My media gallery', 'my-media', '▣'],
        ['my-videos.php', 'My Videos', 'my-videos', '▷'],
        ['commerce.php', 'Creator Commerce', 'commerce', '◈'],
        ['creator-analytics.php', 'Video performance', 'creator-analytics', '▥'],
        ['my-editor.php', 'Video editor', 'my-editor', '✂'],
        ['my-camera.php', 'Phone camera', 'my-camera', '◎'],
    ];
    $adminLinks = [
        ['admin-studio.php', 'Admin overview', 'admin-studio', '⌂'],
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
        ['commerce.php', 'Commerce & Affiliates', 'commerce', '◆'],
        ['accounts.php', 'Manage accounts', 'accounts', '◈'],
        ['admin.php', 'AI settings', 'admin', '✳'],
    ];
    $label = [
        'dashboard'=>'Overview','live'=>'Live AZHottie','admin-studio'=>'Admin overview','live-director'=>'Live Director','live-productions'=>'Saved live productions','agent-chat'=>'Agent chat','account'=>'My profile','user'=>'My profile','settings'=>'Account settings','calendar'=>'Private calendar',
        'classes'=>'Classes','appointments'=>'Spa & appointments','my-work'=>'My assignments','my-videos'=>'My Videos',
        'my-camera'=>'Phone camera','productions'=>'Productions','director'=>'Production Director','library'=>'Interviews','my-media'=>'My media gallery','my-editor'=>'Video editor',
        'interview'=>'Recording room','editor'=>'Video editor','profiles'=>'Models',
        'questionnaires'=>'Questionnaires','casting'=>'Casting CRM','videos'=>'Finished videos','user-submissions'=>'User Submissions','publishing'=>'Publishing & Growth','commerce'=>'Commerce & Affiliates','accounts'=>'Manage accounts',
        'creator-analytics'=>'Video performance','admin'=>'AI settings',
    ][$page] ?? 'My workspace';
    // Details and nested routes retain the corresponding navigation highlight.
    $highlight = ['director'=>'productions','interview'=>'library','editor'=>'library'][$page] ?? $page;
    $render = static function(array $entries) use ($highlight): void {
        foreach ($entries as [$url, $text, $route, $glyph]) {
            $active = $route === $highlight;
            echo '<a class="azh-side-link' . ($active ? ' is-active' : '') . '" href="' . az_h($url) . '"';
            if ($active) echo ' aria-current="page"';
            echo '><span class="azh-side-glyph" aria-hidden="true">' . az_h($glyph) . '</span><span>' . az_h($text) . '</span></a>';
        }
    };
    ?>
    <div class="azh-side-navigation">
      <nav aria-label="Member sections">
      <?php if ($adminPage): ?>
        <p class="azh-side-label">ADMIN WORKSPACE</p>
        <?php if ($admin): $render($adminLinks); else: $render([['admin-studio.php','Admin overview','admin-studio','⌂'],['live-director.php','Live Director','live-director','●'],['live-productions.php','Saved live productions','live-productions','▣']]); endif; ?>
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
<div class="azh-member-layout" id="azh-member-layout">
  <aside class="azh-member-sidebar" aria-label="Member workspace navigation">
    <div id="azh-side-navigation"><?php az_render_member_nav_links($page, $user); ?></div>
  </aside>
  <div class="azh-member-canvas" id="azh-page-canvas">
    <div class="azh-canvas-topline"><span>AZHOTTIE <span class="azh-canvas-slash">/</span> <?= az_h($page === 'my-media' ? 'My media gallery' : ('dashboard' === $page ? 'Overview' : ucwords(str_replace('-', ' ', $page)))) ?></span><span class="azh-canvas-member-pill"><span aria-hidden="true">✳</span> <?= in_array($page, ['admin-studio','live-director','live-productions','productions','director','library','interview','editor','profiles','casting','questionnaires','videos','user-submissions','publishing','commerce','accounts','admin'], true) ? 'ADMIN STUDIO' : 'MEMBER AREA' ?></span></div>
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
<script defer src="assets/member-layout.js"></script>
<?php if ($withAgent): ?>
<link rel="stylesheet" href="assets/agent-chat.css?v=1.29.2">
<script type="module" src="assets/agent-chat.js?v=1.29.2"></script>
<?php endif; ?>
<?php
}
