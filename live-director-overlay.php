<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/live-director-settings.php';
$user=az_live_require_editor();
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex,nofollow');
$id=(string)($_GET['id']??'');
if(!az_valid_id($id)){http_response_code(404);exit;}
$file=az_director_path((int)$user['id']).'/overlay-'.$id;
if(!is_file($file)){http_response_code(404);exit;}
$mime=(string)(@mime_content_type($file)?:'');
if(!in_array($mime,['image/png','image/jpeg','image/webp'],true)){http_response_code(404);exit;}
header('Content-Type: '.$mime);header('Content-Length: '.(string)filesize($file));
readfile($file);
