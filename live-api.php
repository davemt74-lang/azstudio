<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/recordings.php';
require_once __DIR__.'/includes/live-director-settings.php'; // validated image uploads for saved live covers
require_once __DIR__.'/includes/live-rundowns.php';
require_once __DIR__.'/includes/live-engagement.php';
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex,nofollow');
try {
    $actor=az_require_auth(true);
    $method=(string)($_SERVER['REQUEST_METHOD']??'GET');
    $action=az_text($_GET['action']??'',32,'Action');
    if (!in_array($method,['GET','POST'],true))throw new AzHttpError(405,'Unsupported method.','method_not_allowed');
    $body=$method==='POST'?(str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'multipart/form-data')?$_POST:az_json_body()):$_GET;
    if ($method==='POST')az_require_csrf();
    $owner=(int)$actor['id'];
    if ($action==='healthReport') {
        if ($method!=='POST') throw new AzHttpError(405,'POST required.','method_not_allowed');
        az_json(['ok'=>true,'data'=>az_live_agent_report($actor,$body)]);
    }
    if ($action==='acceptanceStatus') {
        if ($method!=='GET')throw new AzHttpError(405,'GET required.','method_not_allowed');
        $roomReference=isset($_GET['roomId'])?az_text($_GET['roomId'],32,'Broadcast reference'):null;
        az_json(['ok'=>true,'data'=>az_live_acceptance_status($actor,$roomReference)]);
    }
    if ($action==='acceptanceMark') {
        if ($method!=='POST')throw new AzHttpError(405,'POST required.','method_not_allowed');
        az_json(['ok'=>true,'data'=>az_live_acceptance_mark($actor,$body)]);
    }
    if ($action==='healthStatus') {
        if ($method!=='GET') throw new AzHttpError(405,'GET required.','method_not_allowed');
        az_json(['ok'=>true,'data'=>az_live_agent_status($actor)]);
    }
    if (in_array($action,['start','stop','heartbeat','offers','answer','recordings','attach','updateRecording','uploadCover','removeCover','publishRecording','unpublishRecording','setAudience','scenes','saveScene','deleteScene','overlays','uploadOverlay','deleteOverlay','rundowns','saveRundown','deleteRundown','directorEngagement','enableChat','removeChat'],true)) {
        az_live_require_editor(true);
        $roomId=az_text($body['roomId']??'',32,'Room');
        $data=match($action) {
            'directorEngagement'=>$method==='GET'?(az_live_host_room($owner,$roomId) ? az_live_engagement_get($roomId):[]):throw new AzHttpError(405,'GET required.','method_not_allowed'),
            'enableChat'=>$method==='POST'?(az_live_host_room($owner,$roomId) ? az_live_engagement_chat_toggle($roomId,($body['enabled']??null)===true):[]):throw new AzHttpError(405,'POST required.','method_not_allowed'),
            'removeChat'=>$method==='POST'?(az_live_host_room($owner,$roomId) ? az_live_engagement_remove($roomId,(string)($body['id']??'')):[]):throw new AzHttpError(405,'POST required.','method_not_allowed'),
            'rundowns'=>$method==='GET'?['rundowns'=>az_live_rundown_read($owner)]:throw new AzHttpError(405,'GET required.','method_not_allowed'),
            'saveRundown'=>$method==='POST'?az_live_rundown_save($owner,$body):throw new AzHttpError(405,'POST required.','method_not_allowed'),
            'deleteRundown'=>$method==='POST'?az_live_rundown_delete($owner,(string)($body['id']??''),(int)($body['revision']??0)):throw new AzHttpError(405,'POST required.','method_not_allowed'),
            'scenes'=>$method==='GET'?['scenes'=>az_director_read_scenes($owner)]:throw new AzHttpError(405,'GET required.','method_not_allowed'),
            'saveScene'=>$method==='POST'?az_director_scene_save($owner,$body):throw new AzHttpError(405,'POST required.','method_not_allowed'),
            'deleteScene'=>$method==='POST'?az_director_scene_delete($owner,(string)($body['id']??'')):throw new AzHttpError(405,'POST required.','method_not_allowed'),
            'overlays'=>$method==='GET'?['overlays'=>az_director_overlays($owner)]:throw new AzHttpError(405,'GET required.','method_not_allowed'),
            'uploadOverlay'=>$method==='POST'?az_director_overlay_upload($owner,$_FILES['file']??null):throw new AzHttpError(405,'POST required.','method_not_allowed'),
            'deleteOverlay'=>$method==='POST'?az_director_overlay_delete($owner,(string)($body['id']??'')):throw new AzHttpError(405,'POST required.','method_not_allowed'),
            'start'=>$method==='POST'?az_live_start($owner,$body['title']??''):throw new AzHttpError(405,'POST required.','method_not_allowed'),
            'stop'=>$method==='POST'?az_live_stop($owner,$roomId):throw new AzHttpError(405,'POST required.','method_not_allowed'),
            'heartbeat'=>$method==='POST'?az_live_heartbeat($owner,$roomId):throw new AzHttpError(405,'POST required.','method_not_allowed'),
            'offers'=>$method==='GET'?az_live_host_offers($owner,$roomId):throw new AzHttpError(405,'GET required.','method_not_allowed'),
            'answer'=>$method==='POST'?az_live_host_answer($owner,$roomId,az_text($body['viewerId']??'',32,'Viewer'),az_text($body['nonce']??'',32,'Nonce'),$body['sdp']??''):throw new AzHttpError(405,'POST required.','method_not_allowed'),
            'recordings'=>$method==='GET'?['recordings'=>az_live_recordings($owner,az_has_role($actor,'admin'))]:throw new AzHttpError(405,'GET required.','method_not_allowed'),
            'updateRecording'=>$method==='POST'?az_live_recording_update($body,$owner,az_has_role($actor,'admin')):throw new AzHttpError(405,'POST required.','method_not_allowed'),
            'uploadCover'=>$method==='POST'?az_live_recording_cover_upload($body,$owner,az_has_role($actor,'admin')):throw new AzHttpError(405,'POST required.','method_not_allowed'),
            'removeCover'=>$method==='POST'?az_live_recording_cover_remove($body,$owner,az_has_role($actor,'admin')):throw new AzHttpError(405,'POST required.','method_not_allowed'),
            'publishRecording'=>$method==='POST'&&az_has_role($actor,'admin')?az_live_recording_publish($body,$owner,true):throw new AzHttpError(403,'Only an administrator can publish live recordings.','access_denied'),
            'setAudience'=>$method==='POST'&&az_has_role($actor,'admin')?az_live_recording_set_audience($body,$owner):throw new AzHttpError(403,'Only an administrator can change the public audience.','access_denied'),
            'unpublishRecording'=>$method==='POST'&&az_has_role($actor,'admin')?az_live_recording_publish($body,$owner,false):throw new AzHttpError(403,'Only an administrator can unpublish live recordings.','access_denied'),
            'attach'=>$method==='POST'?az_live_attach_recording($owner,$roomId,az_text($body['mediaId']??'',32,'Media')):throw new AzHttpError(405,'POST required.','method_not_allowed'),
        };
    } else {
        $roomId=az_text($body['roomId']??'',32,'Room');
        $viewerId=az_text($body['viewerId']??'',32,'Viewer');
        $data=match($action) {
            'engagement'=>$method==='GET'?az_live_engagement_get($roomId):throw new AzHttpError(405,'GET required.','method_not_allowed'),
            'sendChat'=>$method==='POST'?az_live_engagement_post($roomId,$owner,(string)($body['text']??'')):throw new AzHttpError(405,'POST required.','method_not_allowed'),
            'react'=>$method==='POST'?az_live_engagement_react($roomId,$owner,(string)($body['kind']??'')):throw new AzHttpError(405,'POST required.','method_not_allowed'),
            'archive'=>$method==='GET'?['recordings'=>az_live_public_recordings()]:throw new AzHttpError(405,'GET required.','method_not_allowed'),
            'status'=>$method==='GET'?['room'=>($r=az_live_active_room())?az_live_room_public($r):null,'maxViewers'=>AZ_LIVE_MAX_VIEWERS,'iceServers'=>az_live_ice_servers()]:throw new AzHttpError(405,'GET required.','method_not_allowed'),
            'join'=>$method==='POST'?az_live_join($owner,$roomId,az_text($body['nonce']??'',32,'Nonce'),$body['sdp']??''):throw new AzHttpError(405,'POST required.','method_not_allowed'),
            'poll'=>$method==='GET'?az_live_poll_answer($owner,$viewerId):throw new AzHttpError(405,'GET required.','method_not_allowed'),
            'leave'=>$method==='POST'?az_live_leave($owner,$viewerId):throw new AzHttpError(405,'POST required.','method_not_allowed'),
            default=>throw new AzHttpError(404,'Unknown live operation.','unknown_action'),
        };
    }
    az_json(['ok'=>true,'data'=>$data]);
} catch (AzHttpError $e) {
    az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);
} catch(Throwable $e) {
    az_log_exception($e);
    az_json(['ok'=>false,'error'=>['code'=>'server_error','message'=>'Live studio could not complete this request.']],500);
}
