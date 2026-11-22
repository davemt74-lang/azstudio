<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
ini_set('display_errors', '0');
header('Cache-Control: no-store');
try {
    $user = az_require_admin(true); $actorId = (int)$user['id'];
    $passwordVersion = (int)($_SESSION['password_version'] ?? 1);
    $action = az_text($_GET['action'] ?? '', 40, 'Action');
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'GET' && $method !== 'POST') { header('Allow: GET, POST'); throw new AzHttpError(405, 'Use GET or POST.', 'method_not_allowed'); }
    if ($method === 'POST') { az_require_csrf(); }
    $body = $method === 'POST' ? az_json_body() : [];
    session_write_close();
    $refresh = static function () use ($actorId, $passwordVersion): array {
        $fresh = az_account_user($actorId);
        if (!$fresh || $fresh['status'] !== 'active' || !az_has_role($fresh, 'admin') || (int)az_account_row($actorId)['password_version'] !== $passwordVersion) { throw new AzHttpError(403, 'Your administrator access changed. Sign in again.', 'role_required'); }
        return $fresh;
    };
    $lock = az_community_lock(); $user = $refresh();
    az_edit_composer_expire($actorId);
    if ($method === 'GET') {
        $data = match ($action) {
            'settings' => az_work_planner_settings($actorId, 'editComposer') + ['usage' => az_work_ai_usage($actorId)],
            'latest' => az_edit_composer_latest(az_text($_GET['projectId'] ?? '', 32, 'Project ID'), $user),
            'proposal' => az_edit_composer_data(az_edit_composer_row(az_text($_GET['id'] ?? '', 32, 'Proposal ID'), $user), $user),
            'request' => az_edit_composer_data(az_edit_composer_request_row(az_text($_GET['requestToken'] ?? '', 160, 'Request token'), az_text($_GET['projectId'] ?? '', 32, 'Project ID'), $user), $user),
            default => throw new AzHttpError(404, 'This edit assistant action was not found.', 'unknown_action'),
        };
        az_json(['ok' => true, 'data' => $data]);
    }
    if ($action !== 'generate') { throw new AzHttpError(404, 'This edit assistant action was not found.', 'unknown_action'); }
    $job = az_edit_composer_start($body, $user);
    if (isset($job['existing'])) { az_json(['ok' => true, 'data' => $job['existing']]); }
    flock($lock, LOCK_UN); fclose($lock); unset($lock);
    $failure = null; $proposal = null;
    try { $proposal = az_edit_composer_transport($job); }
    catch (AzHttpError $error) { $failure = $error; }
    catch (Throwable $error) { az_log_exception($error); $failure = new AzHttpError(502, 'The edit assistant could not finish. No edit was created.', 'ai_failed'); }
    unset($job['key']);
    $lock = az_community_lock(); $user = $refresh();
    az_json(['ok' => true, 'data' => az_edit_composer_finish($job['id'], $user, $proposal, $failure)]);
} catch (AzHttpError $error) {
    az_json(['ok' => false, 'error' => ['code' => $error->errorCode, 'message' => $error->getMessage()]], $error->status);
} catch (Throwable $error) {
    az_log_exception($error);
    az_json(['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'The edit assistant could not complete this request. Check the saved proposal before trying again.']], 500);
}
