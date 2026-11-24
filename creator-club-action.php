<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
$wantsJson=str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT']??'')),'application/json');
$user=null;
$destination='creator-clubs.php';
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new AzHttpError(405,'Use POST.','method_not_allowed');
    $user=az_require_auth(true);az_require_csrf();
    $text=static function(string $key,string $default=''):string{
        $value=$_POST[$key]??$default;
        if(!is_string($value))throw new AzHttpError(400,'A form value is invalid. Reload and try again.','invalid_input');
        return $value;
    };
    $action=$text('action');
    $creator=filter_var($_POST['creator']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
    $return=$text('return','club');
    $destination=$return==='mine'?'creator-club-workspace.php':($creator>0?'creator-club.php?creator='.$creator:'creator-clubs.php');
    if($action==='follow'||$action==='unfollow'){
        $target=az_social_target(null,$creator);
        if($action==='follow'&&(!$target || !az_social_visible(az_social_row($creator),$user,$target)))
            throw new AzHttpError(404,'Creator profile unavailable.','not_found');
        az_creator_club_follow($user,$creator,$action==='follow');
    }elseif($action==='notifications'){
        az_creator_club_notification_preference($user,$creator,$text('enabled')==='1');
    }elseif($action==='tier'||$action==='tier_update'){
        if($creator!==(int)$user['id'])throw new AzHttpError(403,'Creator only.','forbidden');
        $price=$text('price');
        if(!preg_match('/\A([0-9]{1,3})(?:\.([0-9]{1,2}))?\z/D',$price,$amount))
            throw new AzHttpError(400,'Enter a proposed price between $1.00 and $500.00.','invalid_price');
        $cents=((int)$amount[1])*100+(int)str_pad($amount[2]??'',2,'0');
        if($action==='tier')az_creator_club_tier($user,$text('title'),$text('description'),$cents);
        else az_creator_club_tier_update($user,$text('id'),(int)($_POST['revision']??0),$text('title'),$text('description'),$cents);
    }elseif($action==='tier_archive'||$action==='tier_restore'){
        if($creator!==(int)$user['id'])throw new AzHttpError(403,'Creator only.','forbidden');
        az_creator_club_tier_archive($user,$text('id'),(int)($_POST['revision']??0),$action==='tier_archive');
    }elseif($action==='post'||$action==='post_update'){
        if($creator!==(int)$user['id'])throw new AzHttpError(403,'Creator only.','forbidden');
        $media=$text('media_id');$media=$media!==''?$media:null;
        if($action==='post')az_creator_club_post($user,$text('tier_id'),$media,$text('title'),$text('body'),az_db(),$text('rights_confirmed')==='1',$text('audience','tier'));
        else az_creator_club_post_update($user,$text('id'),(int)($_POST['revision']??0),$text('tier_id'),$media,$text('title'),$text('body'),$text('rights_confirmed')==='1',$text('audience','tier'));
    }elseif($action==='withdraw'){
        if($creator!==(int)$user['id'])throw new AzHttpError(403,'Creator only.','forbidden');
        az_creator_club_withdraw($user,$text('id'),(int)($_POST['revision']??0));
    }elseif($action==='review'){
        $destination='creator-club-admin.php';
        $note=trim($text('note'));
        if($text('decision')==='reject'&&$note==='')throw new AzHttpError(400,'Add a short note so the creator knows what to change.','review_note_required');
        az_creator_club_review($user,$text('type'),$text('id'),(int)($_POST['revision']??0),$text('decision'),az_db(),$note);
    }elseif($action==='revoke_access'){
        $destination='creator-club-admin.php';
        if($text('confirmed')!=='1')throw new AzHttpError(400,'Confirm the access record you want to revoke.','confirmation_required');
        az_creator_club_revoke_access($user,$text('id'));
    }else throw new AzHttpError(400,'Unknown action.','invalid_action');
    try{az_commerce_dispatch(45);}catch(Throwable $deliveryError){az_log_exception($deliveryError);}
    $destination.=(str_contains($destination,'?')?'&':'?').'done=1';
    if($wantsJson)az_json(['ok'=>true,'redirect'=>$destination]);
    az_redirect($destination);
}catch(AzHttpError $e){
    if($wantsJson||!$user)az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);
    http_response_code($e->status);
    require_once __DIR__.'/includes/universal-header.php';
    require_once __DIR__.'/includes/member-layout.php';
    require_once __DIR__.'/includes/creator-club-view.php';
    az_creator_club_page_start('Change not saved','creator-club-workspace',$user);
    ?><section class="club-section"><p class="club-eyebrow">CHANGE NOT SAVED</p><h1>Please review this change</h1><p role="alert"><?= az_h($e->getMessage()) ?></p><p>Use your browser’s Back button to return to your entries, or reload the club to see the latest version.</p><a class="club-primary" href="<?= az_h($destination) ?>">Return to clubs</a></section><?php
    az_creator_club_page_end();
}catch(Throwable $e){
    az_log_exception($e);
    if($wantsJson||!$user)az_json(['ok'=>false,'error'=>['code'=>'club_error','message'=>'Could not save your change. Your entries are still here; please try again.']],500);
    http_response_code(500);
    require_once __DIR__.'/includes/universal-header.php';
    require_once __DIR__.'/includes/member-layout.php';
    require_once __DIR__.'/includes/creator-club-view.php';
    az_creator_club_page_start('Change not saved','creator-club-workspace',$user);
    ?><section class="club-section"><h1>We could not save that change</h1><p role="alert">Please use your browser’s Back button to return to your entries and try again.</p><a class="club-primary" href="<?= az_h($destination) ?>">Return to clubs</a></section><?php
    az_creator_club_page_end();
}
