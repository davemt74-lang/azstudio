<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
ini_set('display_errors','0');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
try {
    $action=is_string($_GET['action']??null)?$_GET['action']:'';
    $method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
    if($method==='GET' && $action==='public'){
        az_json(['ok'=>true,'data'=>az_is_installed()?az_commerce_public_products():[]]);
    }
    if($method==='GET' && $action==='stripeStatus'){az_json(['ok'=>true,'data'=>az_stripe_public_status()]);}
    if($method==='GET' && $action==='liveFeature'){
        az_json(['ok'=>true,'data'=>az_is_installed()?az_commerce_live_feature():null]);
    }
    $user=az_require_auth(true);
    az_agent_workspace_assert($user);
    if($method==='GET' && $action==='state'){
        session_write_close();
        az_json(['ok'=>true,'data'=>az_commerce_state($user)]);
    }
    $actions=[
        'saveProduct'=>'az_commerce_product_save',
        'saveBrief'=>'az_commerce_brief_save',
        'interest'=>'az_commerce_interest_action',
        'reviewInterest'=>'az_commerce_interest_review',
        'requestPromotion'=>'az_commerce_promotion_request',
        'reviewPromotion'=>'az_commerce_promotion_review',
        'recordOrder'=>'az_commerce_order_record',
        'reviewOrder'=>'az_commerce_order_review',
        'featureLive'=>'az_commerce_live_select',
    ];
    if($method!=='POST'||!isset($actions[$action]))
        throw new AzHttpError(405,'Unsupported commerce action.','method_not_allowed');
    az_require_csrf();$body=az_json_body();session_write_close();
    $result=$actions[$action]($user,$body);
    az_json(['ok'=>true,'data'=>$result]);
} catch(AzHttpError $e){az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);}
catch(Throwable $e){az_log_exception($e);az_json(['ok'=>false,'error'=>['code'=>'server_error','message'=>'Commerce is temporarily unavailable.']],500);}
