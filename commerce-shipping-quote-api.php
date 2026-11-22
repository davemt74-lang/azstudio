<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
ini_set('display_errors','0');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex,nofollow');
try {
    if(!az_is_installed())throw new AzHttpError(503,'Shipping rates are unavailable.','not_installed');
    $action=is_string($_GET['action']??null)?$_GET['action']:'';
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    if($method==='GET'&&$action==='status'){
        session_write_close();az_json(['ok'=>true,'data'=>az_customer_ship_public_status()]);
    }
    if($method==='POST'&&$action==='quote'){
        az_require_csrf();
        $body=az_json_body();session_write_close();
        az_json(['ok'=>true,'data'=>az_customer_ship_quote($body)]);
    }
    throw new AzHttpError(405,'Unsupported checkout shipping action.','method_not_allowed');
}catch(AzHttpError $e){
    az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);
}catch(Throwable $e){
    az_log_exception($e);
    az_json(['ok'=>false,'error'=>['code'=>'rates_unavailable','message'=>'Live carrier shipping rates are temporarily unavailable.']],503);
}
