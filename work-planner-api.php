<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
ini_set('display_errors', '0');
header('Cache-Control: no-store');

try {
    $user = az_require_admin(true); $actorId = (int)$user['id'];
    $action = az_text($_GET['action'] ?? '', 40, 'Action'); $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        session_write_close();
        $lock = az_community_lock();
        $user = az_require_admin(true);
        az_work_planner_expire($actorId);
        $data = match ($action) {
            'settings' => az_work_planner_settings($actorId),
            'latest' => az_work_planner_latest(az_text($_GET['projectId'] ?? '', 32, 'Project ID'), $user),
            'proposal' => az_work_planner_proposal_data(az_work_planner_proposal_row(az_text($_GET['id'] ?? '', 32, 'Proposal ID'), $user)),
            default => throw new AzHttpError(404, 'This assistant action was not found.', 'unknown_action'),
        };
        az_json(['ok' => true, 'data' => $data]);
    }
    if ($method !== 'POST') { header('Allow: GET, POST'); throw new AzHttpError(405, 'Use a GET or POST request.', 'method_not_allowed'); }
    az_require_csrf(); $passwordVersion = (int)($_SESSION['password_version'] ?? 1); session_write_close();
    $body = az_json_body(); $lock = az_community_lock();
    $refresh = static function () use ($actorId, $passwordVersion): array {
        $fresh = az_account_user($actorId);
        if (!$fresh || $fresh['status'] !== 'active' || !az_has_role($fresh, 'admin') || (int)az_account_row($actorId)['password_version'] !== $passwordVersion) { throw new AzHttpError(403, 'Your administrator access changed. Sign in again.', 'role_required'); }
        return $fresh;
    };
    $user = $refresh();
    if ($action === 'saveSettings') { az_json(['ok' => true, 'data' => az_work_planner_save_settings($body, $user)]); }
    if ($action === 'apply') { az_json(['ok' => true, 'data' => az_work_planner_apply($body, $user)]); }
    if ($action !== 'generate') { throw new AzHttpError(404, 'This assistant action was not found.', 'unknown_action'); }
    $job = az_work_planner_start($body, $user);
    if (isset($job['existing'])) { az_json(['ok' => true, 'data' => $job['existing']]); }
    // Network latency must not block member uploads, scheduling or review changes.
    flock($lock, LOCK_UN); fclose($lock); unset($lock);
    $failure = null; $proposal = null;
    try { $proposal = az_work_planner_transport($job); }
    catch (AzHttpError $e) { $failure = $e; }
    catch (Throwable $e) { az_log_exception($e); $failure = new AzHttpError(502, 'The assistant could not complete a proposal. No shots were created.', 'ai_failed'); }
    unset($job['key']);
    $lock = az_community_lock(); $user = $refresh();
    $result = az_work_planner_finish($job['id'], $user, $proposal, $failure);
    az_json(['ok' => true, 'data' => $result]);
} catch (AzHttpError $e) {
    az_json(['ok' => false, 'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->status);
} catch (Throwable $e) {
    az_log_exception($e);
    az_json(['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'The shot assistant could not complete this request. Reload to check the saved proposal.']], 500);
}
