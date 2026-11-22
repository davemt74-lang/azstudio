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
    $body = $method === 'POST' ? az_json_body() : []; $csrf = az_csrf_token(); session_write_close();
    $refresh = static function () use ($actorId, $passwordVersion): array {
        $fresh = az_account_user($actorId);
        if (!$fresh || $fresh['status'] !== 'active' || !az_has_role($fresh, 'admin') || (int)az_account_row($actorId)['password_version'] !== $passwordVersion) { throw new AzHttpError(403, 'Your administrator access changed. Sign in again.', 'role_required'); }
        return $fresh;
    };
    $lock = az_community_lock(); $user = $refresh();
    if ($method === 'GET') {
        if ($action !== 'settings') { throw new AzHttpError(404, 'This AI settings action was not found.', 'unknown_action'); }
        az_json(['ok' => true, 'data' => az_ai_settings($actorId) + ['csrf' => $csrf]]);
    }
    if ($action === 'save' || $action === 'saveSettings') { az_json(['ok' => true, 'data' => az_ai_save_settings($body, $user) + ['csrf' => $csrf]]); }
    if (!in_array($action, ['models', 'test'], true)) { throw new AzHttpError(404, 'This AI settings action was not found.', 'unknown_action'); }
    $job = az_ai_check_job($body, $user);
    flock($lock, LOCK_UN); fclose($lock); unset($lock);
    $failure = null; $models = null;
    try { $models = az_ai_list_models($job['provider'], $job['key'], $job['workspaceId']); }
    catch (AzHttpError $error) { $failure = $error; }
    catch (Throwable $error) { $failure = new AzHttpError(502, 'The provider connection could not be checked. Your keys and production work are unchanged.', 'ai_failed'); }
    unset($job['key']);
    $lock = az_community_lock(); $user = $refresh();
    $result = az_ai_check_finish($job, $user, $models, $failure); $result['settings']['csrf'] = $csrf;
    az_json(['ok' => true, 'data' => $result]);
} catch (AzHttpError $error) {
    az_json(['ok' => false, 'error' => ['code' => $error->errorCode, 'message' => $error->getMessage()]], $error->status);
} catch (Throwable $error) {
    // Credential-bearing request bodies must never appear in diagnostic output.
    error_log('AZHottie: AI settings request failed.');
    az_json(['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'AI settings could not be updated. Reload to check the saved settings.']], 500);
}
