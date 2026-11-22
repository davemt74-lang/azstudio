<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
ini_set('display_errors', '0');
try {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD'); throw new AzHttpError(405, 'Use GET to browse published content.', 'method_not_allowed');
    }
    if (!az_is_installed()) { az_json(['ok' => true, 'data' => ['videos' => [], 'page' => 1, 'total' => 0, 'hasMore' => false]]); }
    az_db(); session_write_close();
    $action = az_text($_GET['action'] ?? 'feed', 20, 'Action');
    $data = match ($action) {
        'feed' => az_public_feed($_GET),
        'video' => az_public_video_data(az_public_video_row(az_text($_GET['id'] ?? '', 32, 'Video ID'))),
        default => throw new AzHttpError(404, 'This action was not found.', 'not_found'),
    };
    az_json(['ok' => true, 'data' => $data]);
} catch (AzHttpError $error) {
    az_json(['ok' => false, 'error' => ['code' => $error->errorCode, 'message' => $error->getMessage()]], $error->status);
} catch (Throwable $error) {
    az_log_exception($error); az_json(['ok' => false, 'error' => ['code' => 'content_error', 'message' => 'The published videos could not be loaded.']], 500);
}
