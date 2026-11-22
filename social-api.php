<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    $user=az_require_auth(true);$id=(int)$user['id'];$action=(string)($_GET['action']??'self');
    if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='self') {
        az_json(['ok'=>true,'data'=>['profile'=>az_social_editable(az_social_row($id)),'csrf'=>az_csrf_token(), 'user'=>['id'=>$id,'name'=>$user['name']]]]);
    }
    if ($_SERVER['REQUEST_METHOD']!=='POST') throw new AzHttpError(405,'POST required.','method_not_allowed');
    az_require_csrf();$lock=az_community_lock();
    try {
        if ($action==='save') $profile=az_social_save(az_json_body(),$id);
        elseif ($action==='uploadImage') $profile=az_social_upload_image($id,(string)($_POST['type']??''));
        elseif ($action==='removeImage') $profile=az_social_remove_image($id,(string)(az_json_body()['type']??''));
        else throw new AzHttpError(404,'Unknown social profile action.','unknown_action');
    } finally {flock($lock,LOCK_UN);fclose($lock);}
    az_json(['ok'=>true,'data'=>['profile'=>$profile]]);
} catch(AzHttpError $e){az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);}
catch(Throwable $e){az_log_exception($e);az_json(['ok'=>false,'error'=>['code'=>'server_error','message'=>'Could not save this profile.']],500);}
