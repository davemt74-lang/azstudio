<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/recordings.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
try {
    $user=az_require_auth(true);az_personal_media_role($user);
    $owner=(int)$user['id'];
    $action=az_text($_GET['action']??'',32,'Action');
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if ($method==='GET' && $action==='list') {
        az_json(['ok'=>true,'data'=>['items'=>az_personal_media_list($owner),'pendingUploads'=>az_personal_uploads_pending($owner),'quota'=>az_personal_media_quota($owner),'csrf'=>az_csrf_token(),'userId'=>$owner]]);
    }
    if ($method==='GET' && $action==='projects') {
        az_json(['ok'=>true,'data'=>['projects'=>az_personal_edit_project_list($owner)]]);
    }
    if ($method==='GET' && $action==='project') {
        az_json(['ok'=>true,'data'=>az_personal_edit_project_data(az_personal_edit_project_row(az_text($_GET['id']??'',32,'Project ID'),$owner))]);
    }
    if ($method==='GET' && $action==='status') {
        az_json(['ok'=>true,'data'=>az_personal_upload_data(az_personal_upload_row(az_text($_GET['uploadId']??'',32,'Upload ID'),$owner))]);
    }
    if ($method!=='POST') throw new AzHttpError(405,'This media action requires POST.','method_not_allowed');
    az_require_csrf();
    $body=str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'multipart/form-data')?$_POST:az_json_body();
    $id=az_text($body['id']??$body['uploadId']??'',32,'Media ID');
    $data=match($action) {
        'beginUpload'=>az_personal_upload_begin($body,$owner),
        'uploadChunk'=>az_personal_upload_chunk($id,az_integer($body['index']??-1,0,1000000,'Chunk index'),az_uploaded_file('chunk')['tmp_name'],$owner),
        'finishUpload'=>az_personal_upload_finish($id,$owner),
        'cancelUpload'=>az_personal_upload_cancel($id,$owner),
        'update'=>az_personal_media_update($body,$owner),
        'setDuration'=>az_personal_media_set_duration($id,$owner,az_integer($body['durationMs']??0,1,86400000,'Source duration')),
        'projectSave'=>az_personal_edit_project_save($body,$owner),
        'projectDelete'=>az_personal_edit_project_delete($id,$owner),
        'cover'=>az_personal_media_cover($id,$owner),
        'delete'=>az_personal_media_delete($id,$owner),
        default=>throw new AzHttpError(404,'Unknown personal-media action.','unknown_action'),
    };
    az_json(['ok'=>true,'data'=>$data],$action==='beginUpload'?201:200);
} catch(AzHttpError $e) {
    az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);
} catch(Throwable $e) {
    az_log_exception($e);
    az_json(['ok'=>false,'error'=>['code'=>'server_error','message'=>'The server could not complete your media request. Your original video is unchanged.']],500);
}
