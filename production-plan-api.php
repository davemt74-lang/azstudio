<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
ini_set('display_errors','0');
header('Cache-Control: no-store');

try {
    $user = az_require_admin(true); $actorId = (int)$user['id']; $passwordVersion = (int)($_SESSION['password_version']??1);
    $action = az_text($_GET['action']??'',40,'Action'); $method = $_SERVER['REQUEST_METHOD']??'GET';
    $refresh = static function() use($actorId,$passwordVersion): array {
        $fresh = az_account_user($actorId);
        if (!$fresh || $fresh['status']!=='active' || !az_has_role($fresh,'admin') || (int)az_account_row($actorId)['password_version']!==$passwordVersion) { throw new AzHttpError(403,'Your administrator access changed. Sign in again.','role_required'); }
        return $fresh;
    };
    if ($method==='GET') {
        az_csrf_token();
        session_write_close(); $lock = az_community_lock(); $user = $refresh(); az_production_plan_ai_expire($actorId);
        $data = match($action) {
            'bootstrap' => az_production_plan_bootstrap(az_work_id($_GET['projectId']??null),$user),
            'plan' => az_production_plan_data(az_work_id($_GET['projectId']??null),$user),
            'latest' => az_production_plan_ai_latest(az_work_id($_GET['projectId']??null),$user),
            'proposal' => az_production_plan_ai_data(az_production_plan_ai_row(az_work_id($_GET['id']??null),$user)),
            default => throw new AzHttpError(404,'This production plan action was not found.','unknown_action'),
        };
        az_json(['ok'=>true,'data'=>$data]);
    }
    if ($method!=='POST') { header('Allow: GET, POST'); throw new AzHttpError(405,'Use a GET or POST request.','method_not_allowed'); }
    az_require_csrf(); $body = az_json_body(); session_write_close(); $lock = az_community_lock(); $user = $refresh();
    if (in_array($action,['save','apply','useProposal'],true)) { az_json(['ok'=>true,'data'=>az_production_plan_post($action,$body,$user)]); }
    if ($action!=='generate') { throw new AzHttpError(404,'This production plan action was not found.','unknown_action'); }
    $job = az_production_plan_ai_start($body,$user);
    if (isset($job['existing'])) { az_json(['ok'=>true,'data'=>$job['existing']]); }
    // External AI waits must not block uploads, claims, reviews, or scheduling.
    flock($lock,LOCK_UN); fclose($lock); unset($lock);
    $failure = null; $proposal = null;
    try { $proposal = az_production_plan_ai_transport($job); }
    catch (AzHttpError $error) { $failure = $error; }
    catch (Throwable $error) { az_log_exception($error); $failure = new AzHttpError(502,'The assistant could not complete a production plan. No work was created.','ai_failed'); }
    unset($job['key']); $lock = az_community_lock(); $user = $refresh();
    az_json(['ok'=>true,'data'=>az_production_plan_ai_finish($job['id'],$user,$proposal,$failure)]);
} catch (AzHttpError $error) {
    az_json(['ok'=>false,'error'=>['code'=>$error->errorCode,'message'=>$error->getMessage()]],$error->status);
} catch (Throwable $error) {
    az_log_exception($error);
    az_json(['ok'=>false,'error'=>['code'=>'server_error','message'=>'The production plan could not complete this request. Reload its saved version before retrying.']],500);
}
