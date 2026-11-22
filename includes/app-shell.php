<?php
declare(strict_types=1);
require_once __DIR__ . '/universal-header.php';
require_once __DIR__ . '/member-layout.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/views.php';
require_once __DIR__ . '/production-views.php';

function az_render_app(string $page): void
{
    $user = az_require_admin();
    $production = in_array($page, ['profiles', 'questionnaires', 'videos'], true);
    $title = ['library' => 'Interview library', 'interview' => 'Recording room', 'editor' => 'Video editor', 'profiles' => 'Model profiles', 'questionnaires' => 'Questionnaires', 'videos' => 'Finished videos'][$page] ?? 'Studio';
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#101116"><meta name="robots" content="noindex,nofollow"><title><?= az_h($title) ?> · AZHottie</title><link rel="icon" href="assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/production.css"><link rel="stylesheet" href="assets/accounts.css"><?php if (!$production): ?><link rel="stylesheet" href="assets/review.css"><?php endif; ?><?php if ($page === 'interview'): ?><link rel="stylesheet" href="assets/phone.css"><?php endif; ?><?php if ($page === 'editor'): ?><link rel="stylesheet" href="assets/edit-versions.css"><?php endif; ?><link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/member-layout.css"></head>
<body data-page="<?= az_h($page) ?>">
<?php az_render_universal_header($page, $user); ?>
<?php az_render_member_layout_open($page, $user); ?>
<?php $production ? render_production_view($page) : render_studio_view($page); ?>
<?php az_render_member_layout_close(); ?>
<footer class="studio-footer"><span>AZHOTTIE · INTERVIEW STUDIO</span><span>Record. Save. Make the cut.</span></footer>
<script type="module" src="assets/<?= $production ? 'production' : 'app' ?>.js"></script>
<?php if ($page === 'interview'): ?><script src="assets/qr.js"></script><script type="module" src="assets/phone.js"></script><?php endif; ?>
</body></html>
<?php
}

