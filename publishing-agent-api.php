<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
try {
    $user=az_require_auth(true);
    az_agent_workspace_assert($user);
    $action=az_text($_GET['action']??'',30,'Action');
    $method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
    if($method==='GET' && $action==='state'){
        session_write_close();
        az_json(['ok'=>true,'data'=>[
            'brain'=>az_publishing_agent_briefing($user),
            'promotions'=>az_publishing_promo_list($user),
            'scheduled'=>az_publishing_scheduled_list($user),
            'publicVideos'=>az_has_role($user,'admin')?az_publishing_eligible_videos():[],
            'csrf'=>az_csrf_token()]]);
    }
    if($method!=='POST'||!in_array($action,['draftPromotion','decidePromotion','checkSchedules'],true))
        throw new AzHttpError(405,'Unsupported publishing action.','method_not_allowed');
    az_require_csrf();$body=az_json_body();session_write_close();
    if(!az_has_role($user,'admin')) throw new AzHttpError(403,'Admin access required.','role_required');
    if($action==='checkSchedules')az_json(['ok'=>true,'data'=>az_publishing_scan()]);
    $lock=az_community_lock();
    try{$result=$action==='draftPromotion'?az_publishing_promo_create($body,(int)$user['id']):az_publishing_promo_action($body,(int)$user['id']);}
    finally{flock($lock,LOCK_UN);fclose($lock);}
    az_json(['ok'=>true,'data'=>$result]);
} catch(AzHttpError $e){az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);}
catch(Throwable $e){az_log_exception($e);az_json(['ok'=>false,'error'=>['code'=>'server_error','message'=>'Publishing information is unavailable.']],500);}
