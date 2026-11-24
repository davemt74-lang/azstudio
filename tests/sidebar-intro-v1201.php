<?php
declare(strict_types=1);
/* Self-contained rendering regression: no application DB or production secrets. */
function az_has_role(array $user, string $role): bool { return in_array($role, $user['roles'] ?? [], true); }
function az_h(string $text): string { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function az_csrf_token(): string { return 'fixture-token'; }
function az_is_installed(): bool { return false; }
function az_social_empty(int $userId): array { return []; }
function az_url(string $path): string { return $path; }
require_once __DIR__ . '/../includes/member-layout.php';
require_once __DIR__ . '/../includes/universal-header.php';
function render_nav(string $page, array $user): string {
    ob_start(); az_render_member_nav_links($page, $user); return (string) ob_get_clean();
}
function check(bool $condition, string $description): void {
    if (!$condition) throw new RuntimeException($description);
}
$member = ['id'=>1,'name'=>'Member Test','roles'=>['customer']];
$admin = ['id'=>2,'name'=>'Admin Test','roles'=>['admin']];
foreach ([['dashboard', $member, 'MEMBERSHIP'], ['admin-studio', $admin, 'ADMIN WORKSPACE'], ['watch', $member, 'MEMBERSHIP']] as [$page, $who, $section]) {
    $html = render_nav($page, $who);
    check(strpos($html,'azh-side-intro') === false, "$page has obsolete intro");
    foreach (['YOUR AZHOTTIE','My space','Private member area','Production administration'] as $text) {
        check(strpos($html,$text) === false, "$page contains '$text'");
    }
    $navLabel = $page === 'admin-studio' ? 'Admin sections' : 'Member sections';
    check(strpos($html,'<nav aria-label="' . $navLabel . '">')!==false, "$page nav missing");
    check(strpos($html,$section)!==false, "$page nav section missing");
    check(strpos($html,'Sign out')!==false, "$page sign out missing");
    check(strpos($html,'Public site')!==false, "$page public site link missing");
    check((bool) preg_match('/<div class="azh-side-navigation">\s*<nav /', $html), "$page nav not first sidebar block");
}
check(strpos(render_nav('dashboard', $member),'Admin Studio') === false, 'Member must not have admin links');
check(strpos(render_nav('admin-studio', $admin),'User Submissions') !== false, 'Admin submission nav missing');
ob_start(); az_render_universal_header('watch', $member); $header = (string) ob_get_clean();
check(strpos($header,'azh-floating-sidebar')!==false, 'Signed-in public page sidebar missing');
check(strpos($header,'YOUR AZHOTTIE')===false, 'Floating sidebar still has intro');
check(strpos($header,'MEMBERSHIP')!==false, 'Floating sidebar lacks member navigation');
check(strpos($header,'AZHOTTIE<span aria-hidden="true">.</span>')!==false, 'Global header brand changed');
ob_start(); az_render_universal_header('watch', null); $guestHeader = (string) ob_get_clean();
check(strpos($guestHeader,'azh-floating-sidebar')===false, 'Guest public page should have no member sidebar');
echo "Sidebar PHP rendering tests passed (member, admin, signed-in public, guest).\n";

