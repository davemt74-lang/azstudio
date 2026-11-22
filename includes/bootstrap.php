<?php
declare(strict_types=1);

const AZ_ROOT = __DIR__ . '/..';
const AZ_STORAGE = AZ_ROOT . '/storage';
const AZ_CONFIG_FILE = AZ_STORAGE . '/config.php';
const AZ_VERSION = '1.32.0';

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/production.php';
require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/casting.php';
require_once __DIR__ . '/agent-casting.php';
require_once __DIR__ . '/agent-scheduling.php';
require_once __DIR__ . '/personal-media.php';
require_once __DIR__ . '/model-videos.php';
require_once __DIR__ . '/personal-edits.php';
require_once __DIR__ . '/agent-video.php';
require_once __DIR__ . '/agent-publishing.php';
require_once __DIR__ . '/personal-phone.php';
require_once __DIR__ . '/social-profiles.php';
require_once __DIR__ . '/member-dashboard.php';
require_once __DIR__ . '/member-engagement.php';
require_once __DIR__ . '/agent-chat.php';
require_once __DIR__ . '/agent-workspace.php';
require_once __DIR__ . '/agent-followthrough.php';
require_once __DIR__ . '/live-agent.php';
require_once __DIR__ . '/live.php';
require_once __DIR__ . '/commerce.php';
require_once __DIR__ . '/commerce-stripe.php';
require_once __DIR__ . '/commerce-analytics.php';
require_once __DIR__ . '/commerce-local.php';
require_once __DIR__ . '/commerce-local-orders.php';
if (is_file(__DIR__ . '/scheduling.php')) { require_once __DIR__ . '/scheduling.php'; }
if (is_file(__DIR__ . '/publication.php')) { require_once __DIR__ . '/publication.php'; }
if (is_file(__DIR__ . '/work.php')) { require_once __DIR__ . '/work.php'; }
if (is_file(__DIR__ . '/work-media.php')) { require_once __DIR__ . '/work-media.php'; }
if (is_file(__DIR__ . '/work-planner.php')) { require_once __DIR__ . '/work-planner.php'; }
if (is_file(__DIR__ . '/director.php')) { require_once __DIR__ . '/director.php'; }
if (is_file(__DIR__ . '/edit-versions.php')) { require_once __DIR__ . '/edit-versions.php'; }
if (is_file(__DIR__ . '/edit-composer.php')) { require_once __DIR__ . '/edit-composer.php'; }
if (is_file(__DIR__ . '/ai.php')) { require_once __DIR__ . '/ai.php'; }
if (is_file(__DIR__ . '/work-roster.php')) { require_once __DIR__ . '/work-roster.php'; }
if (is_file(__DIR__ . '/production-plan.php')) { require_once __DIR__ . '/production-plan.php'; }
if (is_file(__DIR__ . '/work-threads.php')) { require_once __DIR__ . '/work-threads.php'; }

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('azhottie_studio');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

final class AzHttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly string $errorCode = 'request_failed')
    {
        parent::__construct($message);
    }
}

function az_is_installed(): bool
{
    if (!is_file(AZ_CONFIG_FILE)) {
        return false;
    }
    try {
        $config = az_config();
        return ($config['installed'] ?? false) === true && isset($config['database']['driver']);
    } catch (Throwable $exception) {
        error_log('AZHottie configuration error: ' . $exception->getMessage());
        return false;
    }
}

function az_config(): array
{
    if (isset($GLOBALS['az_config_cache'])) {
        return $GLOBALS['az_config_cache'];
    }
    if (!is_file(AZ_CONFIG_FILE)) {
        return [];
    }
    $config = require AZ_CONFIG_FILE;
    if (!is_array($config)) {
        throw new RuntimeException('The application configuration is invalid.');
    }
    return $GLOBALS['az_config_cache'] = $config;
}

function az_url(string $path = ''): string
{
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/.');
    return $base . '/' . ltrim($path, '/');
}

function az_redirect(string $path): never
{
    header('Location: ' . az_url($path), true, 303);
    exit;
}

function az_h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function az_now(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function az_id(): string
{
    return bin2hex(random_bytes(16));
}

function az_valid_id(string $id): bool
{
    return preg_match('/\A[a-f0-9]{32}\z/D', $id) === 1;
}

function az_current_user(): ?array
{
    if (!az_is_installed() || empty($_SESSION['user_id'])) {
        return null;
    }
    $statement = az_db()->prepare('SELECT id, status, password_version FROM users WHERE id = ?');
    $statement->execute([(int)$_SESSION['user_id']]);
    $user = $statement->fetch();
    if (!$user || $user['status'] !== 'active' || (int)($_SESSION['password_version'] ?? 1) !== (int)$user['password_version']) {
        unset($_SESSION['user_id']);
        return null;
    }
    return az_account_user((int)$user['id']);
}

function az_require_auth(bool $json = false): array
{
    if (!az_is_installed()) {
        if ($json) {
            throw new AzHttpError(503, 'Set up AZHottie Interview Studio before using this feature.', 'not_installed');
        }
        az_redirect('install.php');
    }
    $user = az_current_user();
    if (!$user) {
        if ($json) {
            throw new AzHttpError(401, 'Please sign in again. Your saved work is still available.', 'authentication_required');
        }
        az_redirect('login.php');
    }
    return $user;
}

function az_login_user(array $user): void
{
    session_regenerate_id(true);
    $row = az_account_row((int)$user['id']);
    $_SESSION = ['user_id' => (int)$user['id'], 'password_version' => (int)$row['password_version'], 'csrf' => bin2hex(random_bytes(32))];
    $statement = az_db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?');
    $statement->execute([az_now(), (int)$user['id']]);
}

function az_csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function az_require_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals(az_csrf_token(), $token)) {
        throw new AzHttpError(403, 'Your form session changed. Reload the page and try again.', 'csrf_invalid');
    }
}

function az_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function az_json_body(): array
{
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $max = (int)(az_limits()['maxStateBytes'] + 131072);
    if ($length > $max) {
        throw new AzHttpError(413, 'This interview update is too large.', 'request_too_large');
    }
    $raw = file_get_contents('php://input', false, null, 0, $max + 1);
    if ($raw === false || strlen($raw) > $max) {
        throw new AzHttpError(413, 'This interview update is too large.', 'request_too_large');
    }
    try {
        $body = json_decode($raw === '' ? '{}' : $raw, false, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new AzHttpError(400, 'The request did not contain valid JSON.', 'invalid_json');
    }
    if (!$body instanceof stdClass) {
        throw new AzHttpError(400, 'The request must contain a JSON object.', 'invalid_json');
    }
    return get_object_vars($body);
}

function az_text(mixed $value, int $maxLength, string $field, string $default = ''): string
{
    if ($value === null) {
        return $default;
    }
    if (!is_string($value)) {
        throw new AzHttpError(400, $field . ' must be text.', 'invalid_field');
    }
    $value = trim(str_replace("\0", '', $value));
    if (strlen($value) > $maxLength) {
        throw new AzHttpError(400, $field . ' is too long.', 'invalid_field');
    }
    return $value;
}

function az_integer(mixed $value, int $min, int $max, string $field): int
{
    if ((!is_int($value) && !(is_string($value) && preg_match('/\A[0-9]+\z/D', $value))) || (float)$value < $min || (float)$value > $max) {
        throw new AzHttpError(400, $field . ' is outside the supported range.', 'invalid_field');
    }
    return (int)$value;
}

function az_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1' || $value === '0') {
        return PHP_INT_MAX;
    }
    $suffix = strtolower(substr($value, -1));
    $number = (float)$value;
    return (int)min(PHP_INT_MAX, $number * match ($suffix) {'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1});
}

function az_limits(): array
{
    $configuration = az_config()['limits'] ?? [];
    $uploadLimit = az_bytes((string)ini_get('upload_max_filesize'));
    $postLimit = az_bytes((string)ini_get('post_max_size'));
    $chunkBytes = max(65536, min(1048576, $uploadLimit, max(65536, $postLimit - 65536)));
    return [
        'chunkBytes' => $chunkBytes,
        'maxUploadBytes' => (int)($configuration['max_upload_bytes'] ?? 2147483648),
        'maxStateBytes' => (int)($configuration['max_state_bytes'] ?? 2097152),
        'maxDirectUploadBytes' => min($uploadLimit, max(0, $postLimit - 65536)),
        'acceptedMimeTypes' => ['video/webm', 'video/mp4', 'video/quicktime', 'video/ogg', 'audio/webm', 'audio/mp4', 'audio/ogg', 'audio/mpeg', 'audio/wav', 'audio/aac', 'audio/flac'],
    ];
}

function az_interview_row(string $id, int $userId): array
{
    if (!az_valid_id($id)) {
        throw new AzHttpError(404, 'This interview was not found.', 'not_found');
    }
    $statement = az_db()->prepare('SELECT * FROM interviews WHERE id = ? AND owner_id = ?');
    $statement->execute([$id, $userId]);
    $row = $statement->fetch();
    if (!$row) {
        throw new AzHttpError(404, 'This interview was not found.', 'not_found');
    }
    return $row;
}

function az_take_row(string $id, int $userId): array
{
    if (!az_valid_id($id)) {
        throw new AzHttpError(404, 'This recording was not found.', 'not_found');
    }
    $statement = az_db()->prepare('SELECT * FROM takes WHERE id = ? AND owner_id = ?');
    $statement->execute([$id, $userId]);
    $row = $statement->fetch();
    if (!$row) {
        throw new AzHttpError(404, 'This recording was not found.', 'not_found');
    }
    return $row;
}

function az_take_data(array $row): array
{
    return [
        'id' => $row['id'], 'interviewId' => $row['interview_id'], 'questionId' => $row['question_id'],
        'questionText' => $row['question_text'], 'label' => $row['label'], 'fileName' => $row['file_name'],
        'mimeType' => $row['mime_type'], 'sizeBytes' => (int)$row['size_bytes'], 'durationMs' => (int)$row['duration_ms'],
        'answerStartMs' => $row['answer_start_ms'] === null ? null : (int)$row['answer_start_ms'],
        'isPrivate' => (bool)$row['is_private'], 'createdAt' => $row['created_at'],
        'isFavorite' => (bool)($row['is_favorite'] ?? false), 'reviewStatus' => $row['review_status'] ?? 'unreviewed',
        'reviewNotes' => az_take_notes($row['id'], (int)$row['owner_id']),
        'mediaUrl' => az_url('media.php?id=' . $row['id']),
        'downloadUrl' => az_url('media.php?id=' . $row['id'] . '&download=1'),
    ];
}

function az_interview_data(array $row, bool $full = false): array
{
    $counts = az_db()->prepare('SELECT COUNT(*) AS take_count, COALESCE(SUM(duration_ms), 0) AS total_duration_ms FROM takes WHERE interview_id = ? AND owner_id = ?');
    $counts->execute([$row['id'], (int)$row['owner_id']]);
    $count = $counts->fetch();
    $data = [
        'id' => $row['id'], 'title' => $row['title'], 'participantName' => $row['participant_name'],
        'notes' => $row['notes'], 'status' => $row['status'], 'createdAt' => $row['created_at'],
        'modelId' => $row['model_id'] ?? null, 'questionnaireId' => $row['questionnaire_id'] ?? null,
        'questionnaireSnapshot' => empty($row['questionnaire_snapshot_json']) ? null : json_decode($row['questionnaire_snapshot_json'], true, 64, JSON_THROW_ON_ERROR),
        'updatedAt' => $row['updated_at'], 'archivedAt' => $row['archived_at'], 'revision' => (int)$row['revision'],
        'takeCount' => (int)$count['take_count'], 'totalDurationMs' => (int)$count['total_duration_ms'],
    ];
    if ($full) {
        $data['state'] = json_decode($row['state_json'], false, 64, JSON_THROW_ON_ERROR);
        $statement = az_db()->prepare('SELECT * FROM takes WHERE interview_id = ? AND owner_id = ? ORDER BY created_at ASC, id ASC');
        $statement->execute([$row['id'], (int)$row['owner_id']]);
        $data['takes'] = array_map('az_take_data', $statement->fetchAll());
    }
    return $data;
}

function az_state_json(mixed $state): string
{
    if (!is_array($state) && !is_object($state)) {
        throw new AzHttpError(400, 'Interview state must be a JSON object.', 'invalid_state');
    }
    if (is_array($state) && $state !== [] && array_is_list($state)) {
        throw new AzHttpError(400, 'Interview state must be a JSON object.', 'invalid_state');
    }
    try {
        $json = json_encode($state === [] ? new stdClass() : $state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR, 64);
    } catch (JsonException) {
        throw new AzHttpError(400, 'Interview state could not be saved as JSON.', 'invalid_state');
    }
    if (strlen($json) > az_limits()['maxStateBytes']) {
        throw new AzHttpError(413, 'This interview contains too much saved text or editor data.', 'state_too_large');
    }
    return $json;
}

function az_touch_interview(string $id, int $ownerId): void
{
    $statement = az_db()->prepare('UPDATE interviews SET updated_at = ? WHERE id = ? AND owner_id = ?');
    $statement->execute([az_now(), $id, $ownerId]);
}

function az_prepare_storage(): void
{
    foreach ([AZ_STORAGE, AZ_STORAGE . '/media', AZ_STORAGE . '/uploads'] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('The server could not create the private storage folders.');
        }
        if (!is_writable($directory)) {
            throw new RuntimeException('The storage folder is not writable by PHP.');
        }
    }
}

function az_media_path(array $take): string
{
    $name = $take['storage_name'];
    if (preg_match('/\A[a-f0-9]{32}\.bin\z/D', $name) !== 1) {
        throw new RuntimeException('Invalid media storage name.');
    }
    return AZ_STORAGE . '/media/' . $name;
}

function az_log_exception(Throwable $exception): void
{
    error_log('AZHottie: ' . get_class($exception) . ': ' . $exception->getMessage());
}


if (is_file(__DIR__ . '/phone.php')) { require_once __DIR__ . '/phone.php'; }
