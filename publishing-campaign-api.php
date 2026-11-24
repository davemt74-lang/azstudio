<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
try {
    $user=az_require_admin(true);
    $action=az_text($_GET['action']??'',25,'Action');
    $method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
    if($method==='GET'&&$action==='state'){
        session_write_close();
        az_json(['ok'=>true,'data'=>['campaigns'=>az_campaign_list((int)$user['id']),'csrf'=>az_csrf_token()]]);
    }
    if($method!=='POST'||!in_array($action,['create','decide'],true))throw new AzHttpError(405,'Unsupported action.','method_not_allowed');
    az_require_csrf();$body=az_json_body();session_write_close();
    $lock=az_community_lock();
    try{$result=$action==='create'?az_campaign_create($body,(int)$user['id']):az_campaign_decide($body,(int)$user['id']);}
    finally{flock($lock,LOCK_UN);fclose($lock);}
    az_json(['ok'=>true,'data'=>$result]);
} catch(AzHttpError $e){az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);}
catch(Throwable $e){az_log_exception($e);az_json(['ok'=>false,'error'=>['code'=>'server_error','message'=>'Campaign operation unavailable.']],500);}
