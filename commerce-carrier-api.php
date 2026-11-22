<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
ini_set('display_errors','0');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex,nofollow');
try{
    $user=az_require_auth(true);az_commerce_admin($user);
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    $action=is_string($_GET['action']??null)?$_GET['action']:'';
    if($method==='GET'&&$action==='status'){
        session_write_close();
        az_json(['ok'=>true,'data'=>az_carrier_public_status()]);
    }
    if($method==='POST'&&in_array($action,['quote','buy','reconcile'],true)){
        az_require_csrf();$body=az_json_body();session_write_close();
        $outcome=match($action){
            'quote'=>az_carrier_quote($user,$body),
            'buy'=>az_carrier_buy($user,$body),
            'reconcile'=>az_carrier_reconcile($user,$body),
        };
        az_json(['ok'=>true,'data'=>$outcome]);
    }
    throw new AzHttpError(405,'Unsupported carrier operation.','method_not_allowed');
}catch(AzHttpError $e){
    az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);
}catch(Throwable $e){
    az_log_exception($e);
    az_json(['ok'=>false,'error'=>['code'=>'carrier_unavailable','message'=>'Carrier service temporarily unavailable.']],503);
}
