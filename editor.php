<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
$user=az_require_auth();
if (!az_has_role($user,'admin')) {
    az_require_roles(['model','professional']);
    az_redirect('my-media.php');
}
require_once __DIR__ . '/includes/app-shell.php';
az_render_app('editor');
