<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
ini_set('display_errors','0');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex,nofollow');
try {
    $user=az_require_auth(true);az_commerce_admin($user);
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    $action=is_string($_GET['action']??null)?$_GET['action']:'';
    if($method==='GET'&&$action==='shipments'){
        session_write_close();az_json(['ok'=>true,'data'=>az_fulfillment_shipments($user)]);
    }
    if($method==='POST'&&in_array($action,['details','transition'],true)){
        az_require_csrf();$body=az_json_body();session_write_close();
        $result=$action==='details'?az_fulfillment_shipment_details(
            $user,az_text($body['id']??'',32,'Shipment')
        ):az_fulfillment_shipment_action($user,$body);
        az_json(['ok'=>true,'data'=>$result]);
    }
    throw new AzHttpError(405,'Unsupported shipping operation.','method_not_allowed');
}catch(AzHttpError $e){
    az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);
}catch(Throwable $e){
    az_log_exception($e);
    az_json(['ok'=>false,'error'=>['code'=>'server_error','message'=>'Shipping management is temporarily unavailable.']],500);
}
