<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new AzHttpError(405,'Use POST.','method_not_allowed');
    $user=az_require_auth(true);az_require_csrf();
    $action=is_string($_POST['action']??null)?$_POST['action']:'';
    $creator=filter_var($_POST['creator']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
    if($action==='follow'||$action==='unfollow'){
        $target=az_social_target(null,$creator);
        if(!$target || !az_social_visible(az_social_row($creator),$user,$target))
            throw new AzHttpError(404,'Creator profile unavailable.','not_found');
        az_creator_club_follow($user,$creator,$action==='follow');
    }elseif($action==='tier'){
        if($creator!==(int)$user['id'])throw new AzHttpError(403,'Creator only.','forbidden');
        $price=filter_var($_POST['price']??null,FILTER_VALIDATE_INT);
        if($price===false||$price===null)throw new AzHttpError(400,'Enter a whole-dollar proposed price.','invalid_price');
        az_creator_club_tier($user,(string)($_POST['title']??''),(string)($_POST['description']??''),$price*100);
    }elseif($action==='post'){
        if($creator!==(int)$user['id'])throw new AzHttpError(403,'Creator only.','forbidden');
        az_creator_club_post($user,(string)($_POST['tier_id']??''),($_POST['media_id']??'')?:null,(string)($_POST['title']??''),(string)($_POST['body']??''));
    }elseif($action==='withdraw'){
        if($creator!==(int)$user['id'])throw new AzHttpError(403,'Creator only.','forbidden');
        az_creator_club_withdraw($user,(string)($_POST['id']??''),(int)($_POST['revision']??0));
    }elseif($action==='review'){
        az_creator_club_review($user,(string)($_POST['type']??''),(string)($_POST['id']??''),(int)($_POST['revision']??0),(string)($_POST['decision']??''));
        az_redirect('creator-club-admin.php?done=1');
    }else throw new AzHttpError(400,'Unknown action.','invalid_action');
    az_redirect('creator-club.php?creator='.$creator.'&done=1');
}catch(AzHttpError $e){az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);}
catch(Throwable $e){az_log_exception($e);az_json(['ok'=>false,'error'=>['code'=>'club_error','message'=>'Could not save your change.']],500);}
