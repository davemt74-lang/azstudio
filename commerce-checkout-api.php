<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
ini_set('display_errors','0');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
try {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')throw new AzHttpError(405,'Checkout requires POST.','method_not_allowed');
    if(!az_is_installed())throw new AzHttpError(503,'Shop is not installed.','not_installed');
    az_require_csrf();$body=az_json_body();session_write_close();
    az_json(['ok'=>true,'data'=>az_stripe_checkout_start($body)]);
}catch(AzHttpError $e){az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);}
catch(Throwable $e){az_log_exception($e);az_json(['ok'=>false,'error'=>['code'=>'checkout_unavailable','message'=>'Checkout could not be started.']],503);}
