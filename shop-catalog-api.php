<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
ini_set('display_errors','0');
try {
    $user=az_require_admin(true);az_shop_admin($user);
    $action=(string)($_GET['action']??'');$method=$_SERVER['REQUEST_METHOD']??'GET';
    if($method==='GET' && $action==='state'){
        session_write_close();az_json(['ok'=>true,'data'=>az_shop_catalog_state($user)]);
    }
    if($method!=='POST')throw new AzHttpError(405,'Unsupported action.','method_not_allowed');
    az_require_csrf();
    if($action==='upload'){
        $result=az_shop_upload_image($user,(string)($_POST['productId']??''),
            (string)($_POST['alt']??''),$_FILES['photo']??[]);
    }else{
        $body=az_json_body();
        if($action==='save')$result=az_shop_catalog_save($user,$body);
        elseif($action==='image')$result=az_shop_image_action($user,$body);
        else throw new AzHttpError(405,'Unsupported action.','method_not_allowed');
    }
    az_json(['ok'=>true,'data'=>$result]);
}catch(AzHttpError $e){az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);}
catch(Throwable $e){az_log_exception($e);az_json(['ok'=>false,'error'=>['code'=>'server_error','message'=>'Storefront settings are temporarily unavailable.']],500);}
