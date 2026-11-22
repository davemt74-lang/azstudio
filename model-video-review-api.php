<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
try {
    $user=az_require_admin(true);
    $action=az_text($_GET['action']??'',32,'Action');
    if (($_SERVER['REQUEST_METHOD']??'GET')==='GET' && $action==='list')
        az_json(['ok'=>true,'data'=>['items'=>az_model_video_review_list(),'summary'=>az_model_video_dashboard_summary(),'csrf'=>az_csrf_token()]]);
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST' || $action!=='moderate')
        throw new AzHttpError(405,'Review changes require POST.','method_not_allowed');
    az_require_csrf();
    $lock=az_community_lock();
    try {$data=az_model_video_moderate(az_json_body(),(int)$user['id']);}
    finally {flock($lock,LOCK_UN);fclose($lock);}
    az_json(['ok'=>true,'data'=>$data]);
} catch(AzHttpError $e){az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);}
catch(Throwable $e){az_log_exception($e);az_json(['ok'=>false,'error'=>['code'=>'server_error','message'=>'The review could not be saved.']],500);}
