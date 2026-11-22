<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
ini_set('display_errors', '0');
header('Cache-Control: no-store');
try {
    $user = az_require_admin(true);
    $action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        az_csrf_token(); session_write_close(); $data = az_edit_get($action, $_GET, $user);
    } elseif ($method === 'POST') {
        az_require_csrf(); $body = az_json_body(); session_write_close(); $lock = az_community_lock();
        try {
            $user = az_require_admin(true); $db = az_db(); $db->beginTransaction();
            try { $data = az_edit_post($action, $body, $user); $db->commit(); }
            catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); } throw $e; }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    } else { header('Allow: GET, POST'); throw new AzHttpError(405, 'Use GET or POST for editable cuts.', 'method_not_allowed'); }
    az_json(['ok' => true, 'data' => $data]);
} catch (AzHttpError $e) { az_json(['ok' => false, 'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->status); }
catch (Throwable $e) { az_log_exception($e); az_json(['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'This editable cut could not be saved. Reload to check the saved version, or inspect the PHP error log.']], 500); }
