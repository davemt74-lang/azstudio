<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
ini_set('display_errors', '0');

try {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        throw new AzHttpError(405, 'Use GET or HEAD to retrieve a video.', 'method_not_allowed');
    }
    if (!az_is_installed()) { throw new AzHttpError(404, 'This video is not available.', 'not_found'); }
    $id = az_text($_GET['id'] ?? '', 32, 'Video ID');
    $type = az_enum($_GET['type'] ?? 'finished', ['finished', 'cover'], 'Media type');
    $take = az_public_video_row($id);
    $publicTitle = $take['fan_title'] ?: 'azhottie-video';
    if ($type === 'cover') { $take = az_video_cover_media($take); }
    session_write_close();
    $path = az_media_path($take);
    $handle = @fopen($path, 'rb');
    if (!$handle) {
        throw new AzHttpError(404, 'This recording file is unavailable on the server.', 'media_missing');
    }
    $stat = fstat($handle);
    $size = (int)($stat['size'] ?? 0);
    if ($size <= 0) {
        fclose($handle);
        throw new AzHttpError(404, 'This recording file is empty or unavailable.', 'media_missing');
    }
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $take['mime_type']);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Cross-Origin-Resource-Policy: same-origin');
    $extension = match ($take['mime_type']) {
        'video/mp4', 'audio/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/ogg', 'audio/ogg' => 'ogg',
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'audio/mpeg' => 'mp3', 'audio/wav' => 'wav', 'audio/aac' => 'aac', 'audio/flac' => 'flac', default => 'webm',
    };
    $name = trim(preg_replace('/[^a-zA-Z0-9._ -]/', '', $publicTitle . ($type === 'cover' ? '-cover' : '')) ?? '', '. ');
    $name = ($name !== '' ? substr($name, 0, 160) : 'azhottie-video') . '.' . $extension;
    header('Content-Disposition: ' . (($_GET['download'] ?? '') === '1' ? 'attachment' : 'inline') . '; filename="' . $name . '"');
    $start = 0;
    $end = $size - 1;
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    if ($range !== '') {
        if (preg_match('/\Abytes=([0-9]*)-([0-9]*)\z/D', trim($range), $matches) !== 1 || ($matches[1] === '' && $matches[2] === '')) {
            fclose($handle);
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            header('Content-Length: 0');
            exit;
        }
        if ($matches[1] === '') {
            $suffixLength = min($size, (int)$matches[2]);
            $start = $size - $suffixLength;
        } else {
            $start = (int)$matches[1];
            if ($matches[2] !== '') {
                $end = min($end, (int)$matches[2]);
            }
        }
        if ($start >= $size || $start < 0 || $end < $start) {
            fclose($handle);
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            header('Content-Length: 0');
            exit;
        }
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    $length = $end - $start + 1;
    header('Content-Length: ' . $length);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        fclose($handle);
        exit;
    }
    @set_time_limit(0);
    fseek($handle, $start);
    while ($length > 0 && !feof($handle) && !connection_aborted()) {
        $chunk = fread($handle, min(262144, $length));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $length -= strlen($chunk);
        flush();
    }
    fclose($handle);
} catch (AzHttpError $exception) {
    az_json(['ok' => false, 'error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], $exception->status);
} catch (Throwable $exception) {
    az_log_exception($exception);
    az_json(['ok' => false, 'error' => ['code' => 'media_error', 'message' => 'This published video could not be opened.']], 500);
}
