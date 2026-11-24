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
        elseif($action==='preview'){
            $design=$body['design']??null;
            if($design instanceof stdClass)$design=get_object_vars($design);
            if(!is_array($design))throw new AzHttpError(400,'Invalid design payload.','invalid_design');
            $layout=az_shop_homepage_validate($design,az_db());
            session_write_close();
            require_once __DIR__.'/includes/shop-homepage-view.php';
            $result=['html'=>az_shop_homepage_render($layout,true,true)];
        }
        elseif($action==='restoreVersion')$result=az_shop_homepage_history_restore($user,$body);
        elseif($action==='deleteVersion')$result=az_shop_homepage_history_delete($user,$body);
        elseif($action==='toggle')$result=az_shop_homepage_toggle($user,$body);
        elseif($action==='deleteImage')$result=az_shop_homepage_image_delete($user,(string)($body['id']??''));
        else throw new AzHttpError(405,'Unsupported action.','method_not_allowed');
    }
    az_json(['ok'=>true,'data'=>$result]);
}catch(AzHttpError $e){az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);}
catch(Throwable $e){az_log_exception($e);az_json(['ok'=>false,'error'=>['code'=>'server_error','message'=>'Shop homepage settings are temporarily unavailable.']],500);}
