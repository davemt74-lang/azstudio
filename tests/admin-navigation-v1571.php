<?php
declare(strict_types=1);
/** Real shared-shell rendering with inert identity/CSRF helpers; no live records. */
function az_has_role(array $user, string $role): bool { return in_array($role, $user['roles'], true); }
function az_h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function az_agent_activity_available(array $user): bool { return ($user['status'] ?? 'active') === 'active' && (az_has_role($user, 'admin') || az_has_role($user, 'model') || az_has_role($user, 'fan')); }
function az_csrf_token(): string { return 'navigation-fixture-csrf'; }
function az_is_installed(): bool { return false; }
function az_social_empty(int $id): array { return ['user_id' => $id, 'avatar_storage_name' => null]; }
function az_url(string $path = ''): string { return $path; }
require_once __DIR__ . '/../includes/member-layout.php';
require_once __DIR__ . '/../includes/universal-header.php';

function navigation_fixture(string $page, string $role = 'admin', string $tab = '', bool $full = false): string
{
    $user = ['id' => 1, 'name' => 'QA User', 'roles' => [$role]];
    $_GET = $tab === '' ? [] : ['tab' => $tab];
    ob_start();
    if ($full) {
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>';
        az_render_universal_header($page, $user);
        az_render_member_layout_open($page, $user);
        echo '<main class="workspace"><h1>Navigation acceptance</h1><p>Review the grouped administration menu.</p></main>';
        az_render_member_layout_close(false);
        echo '</body></html>';
    } else {
        az_render_member_nav_links($page, $user);
    }
    return (string) ob_get_clean();
}

if (($argv[1] ?? '') === '--render') {
    echo navigation_fixture($argv[2] ?? 'shop-homepage-editor', $argv[3] ?? 'admin', $argv[4] ?? '', true);
    exit;
}

function nav_check(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

function navigation_groups(string $html): array
{
    preg_match_all('/<details class="azh-side-group[^\"]*"[^>]*data-side-group="([^\"]+)"([^>]*)>(.*?)<\/details>/s', $html, $matches, PREG_SET_ORDER);
    $groups = [];
    foreach ($matches as $match) $groups[$match[1]] = ['open' => preg_match('/\bopen\b/', $match[2]) === 1, 'html' => $match[3]];
    return $groups;
}

$html = navigation_fixture('shop-homepage-editor');
$groups = navigation_groups($html);
nav_check(array_keys($groups) === ['account','content','commerce','agentic','platform'], 'Admin navigation must have the five requested categories in order.');
$titles = ['account'=>'Account','content'=>'Content','commerce'=>'Commerce','agentic'=>'Agentic','platform'=>'Platform Settings'];
foreach ($titles as $key => $title) nav_check(str_contains($groups[$key]['html'], '<span>' . $title . '</span>'), 'Missing exact category title: ' . $title);
nav_check(count(array_filter($groups, static fn(array $group): bool => $group['open'])) === 1 && $groups['commerce']['open'], 'Only the current Commerce category should initially be open.');
nav_check(str_contains($html, 'href="shop-homepage-editor.php" aria-current="page"'), 'Homepage editor link must be the active destination.');

// Preserve every pre-existing administrator destination while changing grouping.
$existing = ['admin-studio.php','live-director.php','live-productions.php','productions.php','library.php','profiles.php','casting.php','creator-club-admin.php','questionnaires.php','videos.php','user-submissions.php','creator-analytics.php','publishing.php','commerce.php','shop-homepage-editor.php','accounts.php','admin.php'];
foreach ($existing as $href) nav_check(substr_count($html, 'href="' . $href . '"') === 1, 'Existing admin destination missing or duplicated: ' . $href);
preg_match_all('/href="([^\"]+)"/', $html, $hrefs);
foreach ($hrefs[1] as $href) {
    $path = parse_url(html_entity_decode($href), PHP_URL_PATH);
    nav_check(is_string($path) && is_file(__DIR__ . '/../' . $path), 'Navigation target does not exist: ' . $href);
}

$pages = ['accounts'=>'account','settings'=>'account','director'=>'content','interview'=>'content','editor'=>'content','publishing'=>'content','creator-analytics'=>'content','commerce'=>'commerce','creator-club-admin'=>'commerce','agent-chat'=>'agentic','admin'=>'platform','admin-studio'=>'platform'];
foreach ($pages as $page => $group) {
    $pageHtml = navigation_fixture($page);
    $pageGroups = navigation_groups($pageHtml);
    nav_check($pageGroups[$group]['open'], 'Current page must open its category: ' . $page);
    nav_check(substr_count($pageHtml, 'aria-current="page"') === 1, 'Current page must have one active navigation link: ' . $page);
}
$agent = navigation_fixture('commerce', 'admin', 'agent');
nav_check(navigation_groups($agent)['agentic']['open'] && str_contains($agent, 'href="commerce.php?tab=agent" aria-current="page"'), 'Commerce Agent deep link must open Agentic.');

foreach (['shop-homepage-editor','creator-club-admin','creator-analytics','publishing'] as $page) {
    $full = navigation_fixture($page, 'admin', '', true);
    nav_check(substr_count($full, 'id="azh-side-navigation"') === 1, 'Shared header must not create a duplicate sidebar: ' . $page);
    nav_check(!str_contains($full, 'class="azh-floating-sidebar"'), 'Workspace pages must use the actual workspace sidebar: ' . $page);
    nav_check(str_contains($full, 'aria-controls="azh-side-navigation" aria-expanded="true"'), 'Workspace menu toggle must identify the shared sidebar: ' . $page);
}

$editor = navigation_fixture('live-director', 'editor');
nav_check(array_keys(navigation_groups($editor)) === ['content','platform'], 'Live editors only see their existing allowed navigation categories.');
foreach (['accounts.php','admin.php','shop-homepage-editor.php','creator-club-admin.php'] as $href) nav_check(!str_contains($editor, 'href="' . $href . '"'), 'Admin destination leaked to live editor: ' . $href);
foreach (['fan','model','professional'] as $role) {
    $member = navigation_fixture('dashboard', $role);
    nav_check(!str_contains($member, 'data-side-group='), 'Member workspace navigation must not change into admin navigation.');
    nav_check(str_contains($member, 'MEMBERSHIP') && str_contains($member, 'href="account-settings.php"'), 'Member links must be preserved.');
}
nav_check(!str_contains(navigation_fixture('dashboard'), 'data-side-group='), 'Admins returning to the member dashboard keep the member workspace navigation.');
echo "Admin categories, existing routes, current groups, header integration and role visibility: PASS\n";
