<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; sandbox");
try {
    $id=is_string($_GET['id']??null)?$_GET['id']:'';
    $user=az_is_installed()?az_current_user():null;
    $row=az_shop_image_access($id,$user);
    $path=az_shop_images_dir().'/'.$row['storage_name'];
    if(!is_file($path)||!is_readable($path))throw new AzHttpError(404,'Image unavailable.','image_missing');
    $bytes=filesize($path);if($bytes===false||$bytes<1||$bytes>4194304)throw new AzHttpError(404,'Image unavailable.','image_missing');
    if(!in_array($row['mime_type'],['image/jpeg','image/png','image/webp'],true))throw new AzHttpError(404,'Image unavailable.','image_missing');
    header('Content-Type: '.$row['mime_type']);header('Content-Length: '.$bytes);
    session_write_close();readfile($path);
}catch(Throwable $e){if($e instanceof AzHttpError){http_response_code($e->status);}else{http_response_code(500);az_log_exception($e);}header('Content-Type: text/plain; charset=utf-8');echo 'Image unavailable.';}
