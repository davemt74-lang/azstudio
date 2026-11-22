<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
ini_set('display_errors','0');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
try {
    $user=az_require_auth(true);$id=(int)$user['id'];
    $action=az_text($_GET['action']??'state',40,'Action');
    $method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
    if ($method==='GET') {
        if ($action==='state') {
            $thread=az_text($_GET['threadId']??'',32,'Chat ID');
            az_json(['ok'=>true,'data'=>az_agent_chat_state($id,$thread?:null)]);
        }
        if ($action==='settings') {
            if (!az_has_role($user,'admin')) throw new AzHttpError(403,'Administrator access required.','role_required');
            az_json(['ok'=>true,'data'=>az_agent_public_settings()]);
        }
        throw new AzHttpError(404,'Unknown agent chat action.','not_found');
    }
    if ($method!=='POST') {header('Allow: GET, POST');throw new AzHttpError(405,'Use GET or POST.','method_not_allowed');}
    az_require_csrf();
    $body=az_json_body();
    if ($action==='saveSettings') {
        if (!az_has_role($user,'admin'))throw new AzHttpError(403,'Administrator access required.','role_required');
        $lock=az_community_lock();
        try {$saved=az_agent_settings_save($body,$user);}finally{flock($lock,LOCK_UN);fclose($lock);}
        az_json(['ok'=>true,'data'=>$saved]);
    }
    if ($action!=='send') throw new AzHttpError(404,'Unknown agent chat action.','not_found');
    $job=az_agent_begin($id,$body);
    if ($job['recovered']) az_json(['ok'=>true,'data'=>az_agent_chat_state($id,$job['threadId'])]);
    session_write_close();
    $failure=null;$reply=null;
    try {$reply=az_agent_provider_reply($job,$id);}
    catch(AzHttpError $error) {$failure=$error;}
    catch(Throwable $error) {$failure=new AzHttpError(502,'The agent could not finish its reply. Your message is saved; retry it from the chat canvas.','ai_failed');}
    // Credentials are retained only in request-local memory and never serialized.
    unset($job['key']);
    az_agent_finish($id,$job,$reply,$failure?->getMessage()??'');
    if ($failure) throw $failure;
    az_json(['ok'=>true,'data'=>az_agent_chat_state($id,$job['threadId'])]);
} catch(AzHttpError $error) {
    az_json(['ok'=>false,'error'=>['code'=>$error->errorCode,'message'=>$error->getMessage()]],$error->status);
} catch(Throwable $error) {
    error_log('AZHottie agent chat request failed: '.get_class($error));
    az_json(['ok'=>false,'error'=>['code'=>'server_error','message'=>'The agent chat could not complete this request. Reload the chat to check your saved work.']],500);
}
