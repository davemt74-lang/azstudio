<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
ini_set('display_errors','0');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
try {
    $user=az_require_auth(true);
    az_agent_workspace_assert($user);
    $action=is_string($_GET['action']??null)?$_GET['action']:'';
    $method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
    if($method==='GET'&&$action==='badge') {
        session_write_close();
        az_json(['ok'=>true,'data'=>['unreadCount'=>az_agent_workspace_unread_count($user)]]);
    }
    if($method==='GET'&&$action==='castingBriefing') {
        session_write_close();
        az_json(['ok'=>true,'data'=>az_agent_casting_briefing($user,az_has_role($user,'admin')?az_agent_workspace_assign_options($user):[])]);
    }
    if($method==='GET'&&$action==='actionCenter') {
        if(!az_has_role($user,'admin'))throw new AzHttpError(403,'Admin access required.','role_required');
        session_write_close();
        az_json(['ok'=>true,'data'=>az_admin_action_snapshot($user)]);
    }
    if($method==='GET'&&$action==='state') {
        session_write_close();
        az_json(['ok'=>true,'data'=>az_agent_workspace_state($user)]);
    }
    if($method!=='POST'||!in_array($action,['markRead','checkFollowthrough','remindAssignment','checkCasting','castingAdvance','castingCheckpoint','checkSchedule','checkCommerce','checkManagement','actionTrack','actionChange','actionSync'],true))
        throw new AzHttpError(405,'Unsupported agent workspace action.','method_not_allowed');
    az_require_csrf();$body=az_json_body();session_write_close();
    if($action==='markRead'){
        az_agent_workspace_mark_read((int)$user['id'],$body);
        az_json(['ok'=>true,'data'=>az_agent_workspace_state($user)]);
    }
    if(!az_has_role($user,'admin'))throw new AzHttpError(403,'Admin access required.','role_required');
    if($action==='actionTrack'){
        az_json(['ok'=>true,'data'=>az_admin_action_track($user,$body)]);
    }
    if($action==='actionChange'){
        az_json(['ok'=>true,'data'=>az_admin_action_change($user,$body)]);
    }
    if($action==='actionSync'){
        az_json(['ok'=>true,'data'=>az_admin_action_scan((int)$user['id'])]);
    }
    if($action==='checkManagement'){
        az_json(['ok'=>true,'data'=>az_admin_intelligence_scan((int)$user['id'])]);
    }
    if($action==='checkCommerce'){
        az_json(['ok'=>true,'data'=>az_commerce_agent_scan()]);
    }
    if($action==='checkSchedule'){
        az_json(['ok'=>true,'data'=>az_agent_schedule_scan((int)$user['id'])]);
    }
    if($action==='checkCasting'){
        az_json(['ok'=>true,'data'=>az_agent_casting_scan((int)$user['id'])]);
    }
    if($action==='castingAdvance'){
        az_json(['ok'=>true,'data'=>az_agent_casting_advance($user,$body)]);
    }
    if($action==='castingCheckpoint'){
        az_json(['ok'=>true,'data'=>az_agent_casting_checkpoint($user,$body)]);
    }
    if($action==='checkFollowthrough'){
        $result=az_agent_followthrough_run((int)$user['id']);
        az_json(['ok'=>true,'data'=>$result]);
    }
    $result=az_agent_followthrough_remind($user,$body);
    az_json(['ok'=>true,'data'=>$result]);
} catch(AzHttpError $e) {az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);}
catch(Throwable $e) {az_log_exception($e);az_json(['ok'=>false,'error'=>['code'=>'server_error','message'=>'Agent activity is temporarily unavailable.']],500);}
