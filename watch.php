<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/watch-views.php';

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$state = ['feed' => ['videos' => [], 'page' => 1, 'total' => 0, 'hasMore' => false], 'video' => null, 'search' => '', 'user' => null, 'error' => ''];
try {
    $state['search'] = az_text($_GET['search'] ?? '', 120, 'Search');
    if (az_is_installed()) {
        $state['user'] = az_current_user();
        if (isset($_GET['id'])) {
            $id = az_text($_GET['id'], 32, 'Video');
            $state['video'] = az_public_watch_video($id);
        } else {
            $state['feed'] = az_public_watch_feed($_GET);
        }
    } elseif (isset($_GET['id'])) {
        throw new AzHttpError(404, 'This video is not available.', 'not_found');
    }
} catch (AzHttpError $exception) {
    http_response_code($exception->status);
    $state['error'] = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('AZHottie watch error: ' . $exception->getMessage());
    http_response_code(503);
    $state['error'] = 'The video library is temporarily unavailable. Please try again shortly.';
}
az_render_watch_page($state);
