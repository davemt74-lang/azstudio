<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
ini_set('display_errors','0');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex,nofollow');
try{
    if(!az_is_installed())throw new AzHttpError(503,'Return service is unavailable.','not_installed');
    $action=is_string($_GET['action']??null)?$_GET['action']:'';
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    if($method==='POST'&&$action==='request'){
        az_require_csrf();$body=az_json_body();session_write_close();
        az_json(['ok'=>true,'data'=>az_return_public_request($body)]);
    }
    $user=az_require_auth(true);az_commerce_admin($user);
    if($method==='GET'&&$action==='cases'){
        session_write_close();az_json(['ok'=>true,'data'=>az_return_admin_list($user)]);
    }
    if($method==='POST'&&in_array($action,['intake','transition','contact'],true)){
        az_require_csrf();$body=az_json_body();session_write_close();
        $result=match($action){
            'intake'=>az_return_admin_create($user,$body),
            'transition'=>az_return_admin_transition($user,$body),
            'contact'=>az_return_admin_contact($user,az_text($body['id']??'',32,'Return case')),
        };
        az_json(['ok'=>true,'data'=>$result]);
    }
    throw new AzHttpError(405,'Unsupported return action.','method_not_allowed');
}catch(AzHttpError $e){
    az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);
}catch(Throwable $e){
    az_log_exception($e);
    az_json(['ok'=>false,'error'=>['code'=>'server_error','message'=>'Returns are temporarily unavailable.']],503);
}
