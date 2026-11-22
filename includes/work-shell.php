<?php
declare(strict_types=1);
require_once __DIR__ . '/universal-header.php';
require_once __DIR__ . '/member-layout.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/work-views.php';

function az_render_work_app(string $page): void
{
    $user = $page === 'productions' ? az_require_admin() : az_require_roles(['admin', 'model', 'professional']);
    $title = $page === 'productions' ? 'Productions' : 'My work';
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#101116"><title><?= az_h($title) ?> · AZHottie</title><link rel="icon" href="assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/work.css"><link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/member-layout.css"></head>
<body data-work-page="<?= az_h($page) ?>">
<?php az_render_universal_header($page, $user); ?>
<?php az_render_member_layout_open($page, $user); ?>
<?php az_render_work_view($page); ?>
<?php az_render_member_layout_close(); ?>
<footer class="studio-footer"><span>AZHOTTIE · PRODUCTION</span><span>Brief. Make. Review. Share.</span></footer><script type="module" src="assets/work.js"></script>
</body></html><?php
}
