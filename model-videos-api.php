<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
try {
    $action=az_text($_GET['action']??'',40,'Action');
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if ($method==='GET' && $action==='adminList') {
        az_require_admin(true);
        az_json(['ok'=>true,'data'=>['items'=>az_model_video_review_list(),'csrf'=>az_csrf_token()]]);
    }
    $user=az_require_roles(['model','admin'],true);
    az_model_video_role($user);
    $owner=(int)$user['id'];
    if ($method==='GET' && $action==='list') {
        az_json(['ok'=>true,'data'=>['items'=>az_model_video_list($owner),
            'gallery'=>az_personal_media_list($owner),'csrf'=>az_csrf_token()]]);
    }
    if ($method!=='POST') throw new AzHttpError(405,'Use POST to change a model video.','method_not_allowed');
    az_require_csrf();
    $body=az_json_body();
    $lock=az_community_lock();
    try {
        $data=match($action) {
            'create'=>az_model_video_create($body,$owner),
            'update'=>az_model_video_update($body,$owner),
            'submit'=>az_model_video_submit($body,$owner),
            'withdraw'=>az_model_video_withdraw($body,$owner),
            'delete'=>az_model_video_delete($body,$owner),
            default=>throw new AzHttpError(404,'Unknown model video action.','unknown_action'),
        };
    } finally {flock($lock,LOCK_UN);fclose($lock);}
    az_json(['ok'=>true,'data'=>$data],$action==='create'?201:200);
} catch(AzHttpError $e) {az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);}
catch(Throwable $e) {az_log_exception($e);az_json(['ok'=>false,'error'=>['code'=>'server_error','message'=>'Your video could not be updated. Please retry.']],500);}
