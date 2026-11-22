<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
try {
    $action=az_text($_GET['action']??'',32,'Camera action');
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    $multipart=$method==='POST'&&str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'multipart/form-data');
    $body=$method==='POST'?($multipart?$_POST:az_json_body()):$_GET;
    $ownerActions=['invite','devices','approve','revoke','offers','answer'];
    if (in_array($action,$ownerActions,true)) {
        $user=az_require_auth(true);az_personal_media_role($user);$owner=(int)$user['id'];
        if ($method==='GET' && $action==='devices')$data=['devices'=>az_personal_phone_list($owner),'iceServers'=>az_live_ice_servers()];
        elseif ($method==='GET' && $action==='offers')$data=['offers'=>az_personal_phone_live_offers($owner)];
        elseif ($method==='POST') {
            az_require_csrf();
            $data=match($action) {
              'invite'=>az_personal_phone_invite($owner),
              'approve','revoke'=>az_personal_phone_change($owner,az_text($body['id']??'',32,'Phone ID'),$action),
              'answer'=>az_personal_phone_live_answer($owner,az_text($body['id']??'',32,'Phone ID'),az_text($body['nonce']??'',32,'Stream ID'),$body['sdp']??''),
              default=>throw new AzHttpError(405,'Unsupported request method.','method_not_allowed')
            };
        } else throw new AzHttpError(405,'Unsupported request method.','method_not_allowed');
        az_json(['ok'=>true,'data'=>$data]);
    }
    az_personal_phone_secure();
    if ($action==='claim' && $method==='POST') {
        $result=az_personal_phone_claim(az_text($body['inviteId']??'',32,'Invite'),az_text($body['code']??'',64,'Invite code'),az_text($body['displayName']??'',48,'Phone name'));
        az_json(['ok'=>true,'data'=>$result],201);
    }
    // The phone's bearer capability is only a temporary media-contributor token.
    // It cannot call authenticated account APIs or publish footage.
    $id=az_text($body['id']??'',32,'Phone ID');
    $token=(string)($_SERVER['HTTP_X_AZH_PHONE_TOKEN']??'');
    $device=az_personal_phone_device($id,$token,$action!=='status');
    if ($method==='POST')az_personal_phone_origin();
    $owner=(int)$device['owner_id'];
    if ($method==='GET') {
        $data=match($action) {
            'status'=>['status'=>$device['status'],'id'=>$device['id'],'expiresEpoch'=>(int)$device['expires_epoch'],'iceServers'=>$device['status']==='approved'?az_live_ice_servers():[]],
            'uploadStatus'=>az_personal_upload_data(az_personal_phone_upload_row(az_text($body['uploadId']??'',32,'Upload ID'),$device)),
            'poll'=>az_personal_phone_live_poll($device,az_text($body['nonce']??'',32,'Stream ID')),
            default=>throw new AzHttpError(405,'Unsupported request method.','method_not_allowed'),
        };
    } elseif($method==='POST') {
        $uploadId=az_text($body['uploadId']??'',32,'Upload ID');
        $data=match($action) {
            'beginUpload'=>az_personal_upload_begin([
               'clientToken'=>'phone:'.$device['id'].':'.az_text($body['clientToken']??'',40,'Recovery token'),
               'title'=>$body['title']??'', 'fileName'=>$body['fileName']??'',
               'totalBytes'=>$body['totalBytes']??0, 'mimeType'=>$body['mimeType']??'',
               'parentMediaId'=>'',
            ],$owner),
            'uploadChunk'=>az_personal_upload_chunk(
                az_personal_phone_upload_row($uploadId,$device)['id'],
                az_integer($body['index']??-1,0,1000000,'Chunk index'),
                az_uploaded_file('chunk')['tmp_name'],$owner),
            'finishUpload'=>az_personal_upload_finish(az_personal_phone_upload_row($uploadId,$device)['id'],$owner),
            'cancelUpload'=>az_personal_upload_cancel(az_personal_phone_upload_row($uploadId,$device)['id'],$owner),
            'offer'=>az_personal_phone_live_offer($device,az_text($body['nonce']??'',32,'Stream ID'),$body['sdp']??''),
            'end'=>az_personal_phone_live_end($device,az_text($body['nonce']??'',32,'Stream ID')),
            default=>throw new AzHttpError(404,'Unknown camera operation.','unknown_action'),
        };
    } else throw new AzHttpError(405,'Unsupported request method.','method_not_allowed');
    az_json(['ok'=>true,'data'=>$data],$action==='beginUpload'?201:200);
} catch (AzHttpError $e) {
    az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);
} catch(Throwable $e) {
    az_log_exception($e);
    az_json(['ok'=>false,'error'=>['code'=>'server_error','message'=>'The camera request could not complete. Your original recording was not changed.']],500);
}
