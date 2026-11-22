<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
ini_set('display_errors','0');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex,nofollow');
try{
    $action=is_string($_GET['action']??null)?$_GET['action']:'';
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    if(!az_is_installed())throw new AzHttpError(503,'Local Commerce is not installed.','not_installed');
    if($method==='GET'&&$action==='options'){
        session_write_close();
        az_json(['ok'=>true,'data'=>az_local_options()]);
    }
    if($method==='POST'&&in_array($action,['create','status','cancel'],true)){
        if($action!=='status')az_require_csrf();
        $body=az_json_body();session_write_close();
        if($action==='create')$result=az_local_create_order($body);
        elseif($action==='status'){
            az_account_throttle('cash-receipt-check',50,900);
            $result=az_local_customer_status(az_text($body['receiptToken']??'',64,'Cash receipt token'));
        }else{
            $result=az_local_customer_cancel(az_text($body['receiptToken']??'',64,'Cash receipt token'));
        }
        az_json(['ok'=>true,'data'=>$result]);
    }
    $user=az_require_auth(true);az_commerce_admin($user);
    if($method==='GET'&&$action==='settings'){
        session_write_close();az_json(['ok'=>true,'data'=>az_local_settings_admin($user)]);
    }
    if($method==='GET'&&$action==='orders'){
        session_write_close();az_json(['ok'=>true,'data'=>az_local_admin_orders($user)]);
    }
    if($method==='POST'&&in_array($action,['saveSettings','details','transition'],true)){
        az_require_csrf();$body=az_json_body();session_write_close();
        if($action==='saveSettings')$result=az_local_settings_save($user,$body);
        elseif($action==='details')$result=az_local_admin_details($user,
            az_text($body['id']??'',32,'Cash order'));
        else $result=az_local_admin_action($user,$body);
        az_json(['ok'=>true,'data'=>$result]);
    }
    throw new AzHttpError(405,'Unsupported local Commerce operation.','method_not_allowed');
}catch(AzHttpError $e){
    az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);
}catch(Throwable $e){
    az_log_exception($e);
    az_json(['ok'=>false,'error'=>['code'=>'server_error','message'=>'Local Commerce is temporarily unavailable.']],500);
}
