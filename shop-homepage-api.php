<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex,nofollow');
ini_set('display_errors','0');
try{
    $user=az_require_admin(true);az_shop_admin($user);
    $action=(string)($_GET['action']??'');$method=$_SERVER['REQUEST_METHOD']??'GET';
    if($method==='GET'&&$action==='state'){
        session_write_close();az_json(['ok'=>true,'data'=>az_shop_homepage_admin_state($user)]);
    }
    if($method!=='POST')throw new AzHttpError(405,'Unsupported action.','method_not_allowed');
    az_require_csrf();
    if($action==='upload'){
        $result=az_shop_homepage_image_upload($user,$_FILES['image']??[],(string)($_POST['alt']??''));
    }else{
        $body=az_json_body();
        if($action==='save')$result=az_shop_homepage_save($user,$body);
        elseif($action==='toggle')$result=az_shop_homepage_toggle($user,$body);
        elseif($action==='deleteImage')$result=az_shop_homepage_image_delete($user,(string)($body['id']??''));
        else throw new AzHttpError(405,'Unsupported action.','method_not_allowed');
    }
    az_json(['ok'=>true,'data'=>$result]);
}catch(AzHttpError $e){az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);}
catch(Throwable $e){az_log_exception($e);az_json(['ok'=>false,'error'=>['code'=>'server_error','message'=>'Shop homepage settings are temporarily unavailable.']],500);}
