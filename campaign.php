<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
try {
    $token=is_string($_GET['t']??null)?$_GET['t']:'';
    $path=az_is_installed()?az_campaign_resolve_link($token):'watch.php';
} catch(Throwable $e){az_log_exception($e);$path='watch.php';}
header('Location: '.az_url($path),true,302);
exit;
