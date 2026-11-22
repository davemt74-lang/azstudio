<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
ini_set('display_errors', '0');
header('Cache-Control: no-store');

try {
    $user = az_require_auth(true);
    $action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, ['GET', 'POST'], true)) { header('Allow: GET, POST'); throw new AzHttpError(405, 'Use a GET or POST request.', 'method_not_allowed'); }
    if ($method === 'POST') { az_require_csrf(); $body = az_json_body(); }
    else { az_csrf_token(); }
    session_write_close();
    $lock = az_community_lock();
    try {
        // Authentication is checked after waiting for any concurrent role/password change.
        $user = az_require_auth(true);
        if ($method === 'GET') {
            if ($action !== 'thread') { throw new AzHttpError(404, 'This conversation action was not found.', 'unknown_action'); }
            $before = isset($_GET['beforeSequence']) ? az_integer($_GET['beforeSequence'], 1, PHP_INT_MAX, 'Earlier message sequence') : null;
            $data = az_work_thread_data(az_work_id($_GET['assignmentId'] ?? null), $user, $before);
        } else {
            $db = az_db(); $db->beginTransaction();
            try {
                $data = match ($action) {
                    'send' => az_work_thread_send($body, $user),
                    'markRead' => az_work_thread_mark_read($body, $user),
                    default => throw new AzHttpError(404, 'This conversation action was not found.', 'unknown_action'),
                };
                $db->commit();
            } catch (Throwable $error) { if ($db->inTransaction()) { $db->rollBack(); } throw $error; }
        }
    } finally { flock($lock, LOCK_UN); fclose($lock); }
    az_json(['ok' => true, 'data' => $data]);
} catch (AzHttpError $error) {
    az_json(['ok' => false, 'error' => ['code' => $error->errorCode, 'message' => $error->getMessage()]], $error->status);
} catch (Throwable $error) {
    az_log_exception($error);
    az_json(['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'The conversation could not complete this request. Reload to check saved messages before retrying.']], 500);
}
