<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');header('X-Robots-Tag: noindex');
try {
    if (!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)) throw new AzHttpError(405,'GET required.','method_not_allowed');
    $id=az_integer($_GET['u']??null,1,PHP_INT_MAX,'User');$kind=(string)($_GET['type']??'avatar');
    if (!in_array($kind,['avatar','cover'],true)) throw new AzHttpError(404,'Image not found.','not_found');
    $viewer=az_current_user();$target=az_social_target(null,$id);
    if (!$target) throw new AzHttpError(404,'Image not found.','not_found');
    $profile=az_social_row($id);
    if (!az_social_visible($profile,$viewer,$target)) throw new AzHttpError(404,'Image not found.','not_found');
    $storage=$profile[$kind.'_storage_name']??'';$mime=$profile[$kind.'_mime_type']??'';
    if (!preg_match('/\A[a-f0-9]{32}\.bin\z/D',$storage) || !in_array($mime,['image/jpeg','image/png','image/webp'],true)) throw new AzHttpError(404,'Image not found.','not_found');
    $path=az_media_path(['storage_name'=>$storage]);$handle=@fopen($path,'rb');
    if (!$handle) throw new AzHttpError(404,'Image not found.','not_found');
    $size=@filesize($path);if ($size===false || $size<=0){fclose($handle);throw new AzHttpError(404,'Image not found.','not_found');}
    session_write_close();header('Content-Type: '.$mime);header('Content-Length: '.$size);
    header('Content-Disposition: inline');header('Cross-Origin-Resource-Policy: same-origin');
    if (($_SERVER['REQUEST_METHOD']??'GET')==='GET') fpassthru($handle);
    fclose($handle);
} catch(AzHttpError $e){http_response_code($e->status);header('Content-Type: text/plain; charset=utf-8');echo 'Image unavailable.';}
catch(Throwable $e){az_log_exception($e);http_response_code(500);header('Content-Type: text/plain; charset=utf-8');echo 'Image unavailable.';}
