<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
ini_set('display_errors','0');
try {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){
        header('Allow: POST');throw new AzHttpError(405,'POST required.','method_not_allowed');
    }
    if(!az_is_installed())throw new AzHttpError(404,'Product not available.','not_found');
    az_require_csrf();
    $body=az_json_body();
    if(($body['event']??'')!=='click')throw new AzHttpError(400,'Invalid engagement event.','invalid_event');
    $video=az_text($body['videoId']??'',32,'Video');
    $product=az_text($body['productId']??'',32,'Product');
    $promotion=az_text($body['promotionId']??'',32,'Promotion');
    $tracked=az_creator_video_click(az_current_user(),$video,$product,$promotion);
    az_json(['ok'=>true,'tracked'=>$tracked]);
} catch(AzHttpError $e){az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);}
catch(Throwable $e){az_log_exception($e);az_json(['ok'=>false,'error'=>['code'=>'analytics_unavailable','message'=>'Product engagement unavailable.']],503);}
