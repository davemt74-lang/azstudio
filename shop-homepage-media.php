<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex,nofollow');
header("Content-Security-Policy: default-src 'none'; sandbox");
try{
    $id=is_string($_GET['id']??null)?$_GET['id']:'';
    $actor=az_is_installed()?az_current_user():null;
    $row=az_shop_homepage_image_access($id,$actor);
    $file=az_shop_homepage_image_dir().'/'.$row['storage_name'];
    if(!is_file($file)||!is_readable($file))throw new AzHttpError(404,'Image unavailable.','image_missing');
    $bytes=filesize($file);if($bytes===false||$bytes<1||$bytes>5*1024*1024)throw new AzHttpError(404,'Image unavailable.','image_missing');
    if(!in_array($row['mime_type'],['image/jpeg','image/png','image/webp'],true))throw new AzHttpError(404,'Image unavailable.','image_missing');
    header('Content-Type: '.$row['mime_type']);header('Content-Length: '.$bytes);
    session_write_close();readfile($file);
}catch(Throwable $e){if($e instanceof AzHttpError)http_response_code($e->status);else{http_response_code(500);az_log_exception($e);}header('Content-Type: text/plain; charset=utf-8');echo 'Image unavailable.';}
