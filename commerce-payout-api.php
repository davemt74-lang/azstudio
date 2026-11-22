<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
ini_set('display_errors','0');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex,nofollow');
try{
    if(!az_is_installed())throw new AzHttpError(503,'Payout reporting is unavailable.','not_installed');
    $user=az_require_auth(true);
    az_agent_workspace_assert($user);
    $action=is_string($_GET['action']??null)?$_GET['action']:'';
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    if($method==='GET'&&$action==='state'){
        session_write_close();az_json(['ok'=>true,'data'=>az_payout_role_state($user)]);
    }
    if($method==='POST'&&in_array($action,['draft','change'],true)){
        az_commerce_admin($user);
        az_require_csrf();$body=az_json_body();session_write_close();
        $outcome=$action==='draft'?az_payout_draft($user,$body):az_payout_change($user,$body);
        az_json(['ok'=>true,'data'=>$outcome]);
    }
    throw new AzHttpError(405,'Unsupported payout action.','method_not_allowed');
}catch(AzHttpError $e){
    az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);
}catch(Throwable $e){
    az_log_exception($e);
    az_json(['ok'=>false,'error'=>['code'=>'server_error','message'=>'Settlement reporting is temporarily unavailable.']],503);
}
