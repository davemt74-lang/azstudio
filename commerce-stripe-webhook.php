<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
ini_set('display_errors','0');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
try {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')throw new AzHttpError(405,'POST required.','method_not_allowed');
    if((int)($_SERVER['CONTENT_LENGTH']??0)>262144)throw new AzHttpError(413,'Webhook too large.','webhook_large');
    $raw=file_get_contents('php://input',false,null,0,262145);
    if($raw===false||strlen($raw)>262144)throw new AzHttpError(413,'Webhook too large.','webhook_large');
    $outcome=az_stripe_webhook_receive($raw,(string)($_SERVER['HTTP_STRIPE_SIGNATURE']??''));
    az_json(['ok'=>true,'data'=>$outcome]);
}catch(AzHttpError $e){az_json(['ok'=>false,'error'=>['code'=>$e->errorCode]],$e->status);}
catch(Throwable $e){az_log_exception($e);az_json(['ok'=>false,'error'=>['code'=>'retry_webhook']],503);}
