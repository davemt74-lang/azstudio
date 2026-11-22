<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/recordings.php';
require_once __DIR__ . '/includes/work-media.php';
ini_set('display_errors', '0');
header('Cache-Control: no-store');

try {
    $user = az_require_auth(true);
    $passwordVersion = (int)($_SESSION['password_version'] ?? 1);
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, ['GET','POST'], true)) { header('Allow: GET, POST'); throw new AzHttpError(405, 'Use GET or POST for this action.', 'method_not_allowed'); }
    if ($method === 'POST') { az_require_csrf(); }
    $action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
    $body = $method === 'GET' ? $_GET : (str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') ? $_POST : az_json_body());
    session_write_close();
    $communityLock = az_community_lock();
    $user = az_work_media_actor($user);
    if ((int)az_account_row((int)$user['id'])['password_version'] !== $passwordVersion) { throw new AzHttpError(401, 'Sign in again before continuing this upload.', 'session_changed'); }
    if ($method === 'GET' && $action === 'sourceInterviews') {
        az_json(['ok'=>true,'data'=>az_work_source_interviews(az_text($body['projectId'] ?? '',32,'Project ID'),$user)]);
    }
    if ($method === 'GET' && $action === 'upload') {
        [$row] = az_work_upload_access(az_text($body['uploadId'] ?? '',32,'Upload ID'),$user,az_text($body['assignmentId'] ?? '',32,'Assignment ID'));
        az_json(['ok'=>true,'data'=>az_work_upload_data($row)]);
    }
    if ($method !== 'POST') { throw new AzHttpError(404,'This upload action was not found.','unknown_action'); }
    if (in_array($action,['prepareEditor','uploadReference','importSource'],true)) {
        $project = az_work_project_row(az_text($body['projectId'] ?? '',32,'Project ID'),$user,true);
        $ownerLock = az_work_media_owner_lock((int)$project['owner_id']);
        if ($action === 'uploadReference') { az_json(['ok'=>true,'data'=>az_work_upload_reference($body,$user)],201); }
        if ($action === 'importSource') { az_json(['ok'=>true,'data'=>az_work_import_source($body,$user)],201); }
        $data=az_work_prepare_editor(az_interview_data(az_interview_row($project['interview_id'],(int)$user['id']),true),$user);
        az_json(['ok'=>true,'data'=>['interviewId'=>$project['interview_id'],'editorUrl'=>az_url('editor.php?id='.$project['interview_id']),'acceptedCount'=>count($data['takes']),'unavailableSelectedIds'=>$data['production']['unavailableSelectedIds']]]);
    }
    if ($action === 'beginUpload') {
        $assignment = az_work_assignment_access(az_text($body['assignmentId'] ?? '',32,'Assignment ID'),$user,false);
        $ownerLock = az_work_media_owner_lock((int)$assignment['owner_id']);
        az_json(['ok'=>true,'data'=>az_work_begin_upload($body,$user)],201);
    }
    if (in_array($action,['uploadChunk','finishUpload','abandonUpload'],true)) {
        $id=az_text($body['uploadId'] ?? '',32,'Upload ID');
        $assignmentId=az_text($body['assignmentId'] ?? '',32,'Assignment ID');
        // Even abort is scoped to the current contributor; no raw owner IDs are
        // accepted from an upload client.
        if ($action === 'abandonUpload') {
            $row=az_work_upload_raw($id);
            if ((int)$row['uploaded_by'] !== (int)$user['id'] || $row['assignment_id']!==$assignmentId) { throw new AzHttpError(404,'This upload was not found.','upload_not_found'); }
            az_work_assignment_access($assignmentId,$user,false);
        } else { [$row]=az_work_upload_access($id,$user,$assignmentId); }
        $ownerLock=az_work_media_owner_lock((int)$row['owner_id']);
        if ($action==='abandonUpload') {
            $result=az_abandon_upload($id,(int)$row['owner_id']);
            az_json(['ok'=>true,'data'=>['abandoned'=>(bool)($result['abandoned']??false),'completed'=>(bool)($result['completed']??false)]]);
        }
        if ($action==='uploadChunk') {
            $file=az_uploaded_file('chunk');
            az_store_chunk($id,az_integer($body['index']??-1,0,1000000,'Chunk index'),$file['tmp_name'],(int)$row['owner_id']);
            az_json(['ok'=>true,'data'=>az_work_upload_data(az_work_upload_raw($id))]);
        }
        az_json(['ok'=>true,'data'=>az_work_finish_upload($id,$user,$assignmentId)]);
    }
    throw new AzHttpError(404,'This upload action was not found.','unknown_action');
} catch (AzHttpError $e) {
    az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);
} catch (Throwable $e) {
    az_log_exception($e);
    az_json(['ok'=>false,'error'=>['code'=>'work_upload_error','message'=>'The recording could not be saved. Your local recording can be retried.']],500);
}
