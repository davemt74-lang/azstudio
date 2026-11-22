<?php
declare(strict_types=1);

/** Interview-scoped phone pairing and private WebRTC signaling, adapted from Lucky's approval/capability flow. */
function az_create_phone_schema(PDO $db): void
{
    if (az_schema_version($db) >= 3) { return; }
    az_prepare_storage();
    $lock = fopen(AZ_STORAGE . '/schema-migration.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('Could not lock the phone schema upgrade.'); }
    try {
    if (az_schema_version($db) >= 3) { return; }
    $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $owner = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
    $long = $mysql ? 'LONGTEXT' : 'TEXT';
    $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    foreach ([
        "CREATE TABLE IF NOT EXISTS phone_rooms (interview_id CHAR(32) PRIMARY KEY, owner_id $owner NOT NULL, enabled INTEGER NOT NULL DEFAULT 0, question_id VARCHAR(160) NOT NULL DEFAULT 'phone-footage', question_text TEXT NOT NULL, is_private INTEGER NOT NULL DEFAULT 0, host_seen_at BIGINT NOT NULL DEFAULT 0, FOREIGN KEY (interview_id) REFERENCES interviews(id) ON DELETE CASCADE, FOREIGN KEY (owner_id) REFERENCES users(id))$suffix",
        "CREATE TABLE IF NOT EXISTS phone_invites (id CHAR(32) PRIMARY KEY, interview_id CHAR(32) NOT NULL, owner_id $owner NOT NULL, secret_hash CHAR(64) NOT NULL, expires_at BIGINT NOT NULL, created_at VARCHAR(32) NOT NULL, FOREIGN KEY (interview_id) REFERENCES interviews(id) ON DELETE CASCADE, FOREIGN KEY (owner_id) REFERENCES users(id))$suffix",
        "CREATE TABLE IF NOT EXISTS phone_connections (id CHAR(32) PRIMARY KEY, interview_id CHAR(32) NOT NULL, owner_id $owner NOT NULL, token_hash CHAR(64) NOT NULL, label VARCHAR(100) NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'pending', expires_at BIGINT NOT NULL, last_seen_at BIGINT NOT NULL DEFAULT 0, created_at VARCHAR(32) NOT NULL, FOREIGN KEY (interview_id) REFERENCES interviews(id) ON DELETE CASCADE, FOREIGN KEY (owner_id) REFERENCES users(id))$suffix",
        "CREATE TABLE IF NOT EXISTS phone_upload_links (upload_id CHAR(32) PRIMARY KEY, connection_id CHAR(32) NOT NULL, FOREIGN KEY (upload_id) REFERENCES uploads(id) ON DELETE CASCADE, FOREIGN KEY (connection_id) REFERENCES phone_connections(id) ON DELETE CASCADE)$suffix",
        "CREATE TABLE IF NOT EXISTS phone_live (connection_id CHAR(32) PRIMARY KEY, nonce CHAR(32) NOT NULL, offer_sdp $long NOT NULL, answer_sdp $long NULL, updated_at BIGINT NOT NULL, FOREIGN KEY (connection_id) REFERENCES phone_connections(id) ON DELETE CASCADE)$suffix",
    ] as $sql) { $db->exec($sql); }
    $db->exec('UPDATE studio_schema SET version = 3 WHERE id = 1');
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
    }
}

function az_phone_id(mixed $value, string $label = 'Phone connection'): string
{
    if (!is_string($value) || !az_valid_id($value)) { throw new AzHttpError(400, $label . ' is invalid.', 'invalid_phone_id'); }
    return $value;
}

function az_phone_secret(mixed $value): string
{
    if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
        throw new AzHttpError(403, 'This phone invitation or connection is no longer valid. Scan a new QR code.', 'phone_unauthorized');
    }
    return hash('sha256', hex2bin($value));
}

function az_phone_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $expected = ($secure ? 'https://' : 'http://') . $host;
    if (!is_string($origin) || $origin === '' || strcasecmp(rtrim($origin, '/'), rtrim($expected, '/')) !== 0) {
        throw new AzHttpError(403, 'Open the phone page on this studio’s own address.', 'phone_origin');
    }
    $hostname = strtolower((string)parse_url($expected, PHP_URL_HOST));
    if (!$secure && !in_array($hostname, ['localhost', '127.0.0.1', '[::1]'], true)) {
        throw new AzHttpError(403, 'Phone connections require the HTTPS address of this studio.', 'phone_https');
    }
}

function az_phone_room(string $interviewId, int $ownerId, bool $create = false): array
{
    $interview = az_interview_row($interviewId, $ownerId);
    $q = az_db()->prepare('SELECT * FROM phone_rooms WHERE interview_id = ? AND owner_id = ?');
    $q->execute([$interviewId, $ownerId]);
    $room = $q->fetch();
    if (!$room && $create) {
        az_db()->prepare('INSERT INTO phone_rooms (interview_id, owner_id, question_text) VALUES (?, ?, ?)')->execute([$interviewId, $ownerId, 'Behind the scenes']);
        $q->execute([$interviewId, $ownerId]); $room = $q->fetch();
    }
    if (!$room) { throw new AzHttpError(409, 'Phone access has not been opened for this interview.', 'phone_room_closed'); }
    $room['interview_title'] = $interview['title'];
    $room['interview_status'] = $interview['status'];
    $room['project_id'] = $interview['project_id'] ?? null;
    if ($room['project_id'] && function_exists('az_work_raw_project')) {
        $project = az_work_raw_project($room['project_id']);
        $room['project_status'] = $project['status'];
    }
    return $room;
}

function az_phone_require_open(array $room): void
{
    if (!(int)$room['enabled'] || $room['interview_status'] !== 'active') {
        throw new AzHttpError(403, 'The host closed phone access for this interview.', 'phone_room_closed');
    }
    $owner = az_account_user((int)$room['owner_id']);
    if (!$owner || $owner['status'] !== 'active' || !az_has_role($owner, 'admin') || (!empty($room['project_id']) && ($room['project_status'] ?? '') !== 'active')) {
        throw new AzHttpError(403, 'Phone access is no longer available for this production.', 'phone_room_closed');
    }
}

function az_phone_close_interview(PDO $db, string $interviewId): void
{
    $db->prepare('UPDATE phone_rooms SET enabled = 0, host_seen_at = 0 WHERE interview_id = ?')->execute([$interviewId]);
    $db->prepare("UPDATE phone_connections SET status = 'revoked' WHERE interview_id = ? AND status IN ('pending','approved')")->execute([$interviewId]);
    $db->prepare('DELETE FROM phone_invites WHERE interview_id = ?')->execute([$interviewId]);
    $db->prepare('DELETE FROM phone_live WHERE connection_id IN (SELECT id FROM phone_connections WHERE interview_id = ?)')->execute([$interviewId]);
}

function az_phone_row(string $id, ?int $ownerId = null): array
{
    az_phone_id($id);
    $q = az_db()->prepare('SELECT * FROM phone_connections WHERE id = ?' . ($ownerId === null ? '' : ' AND owner_id = ?'));
    $q->execute($ownerId === null ? [$id] : [$id, $ownerId]);
    $row = $q->fetch();
    if (!$row) { throw new AzHttpError(404, 'This phone connection was not found.', 'phone_not_found'); }
    return $row;
}

function az_phone_authenticate(string $id, bool $approved = true): array
{
    $hash = az_phone_secret($_SERVER['HTTP_X_PHONE_TOKEN'] ?? '');
    $row = az_phone_row($id);
    if (!hash_equals($row['token_hash'], $hash)) { throw new AzHttpError(403, 'This phone connection is not authorized.', 'phone_unauthorized'); }
    if ((int)$row['expires_at'] <= time() || in_array($row['status'], ['revoked', 'rejected'], true)) {
        throw new AzHttpError(403, 'This phone was disconnected or its connection expired. Scan a new QR code.', 'phone_disconnected');
    }
    if ($approved && $row['status'] !== 'approved') {
        throw new AzHttpError(403, 'Wait for the host to approve this phone.', 'phone_pending');
    }
    az_phone_require_open(az_phone_room($row['interview_id'], (int)$row['owner_id']));
    return $row;
}

function az_phone_connection_data(array $row): array
{
    return ['id' => $row['id'], 'interviewId' => $row['interview_id'], 'label' => $row['label'],
        'status' => (int)$row['expires_at'] <= time() ? 'expired' : $row['status'],
        'expiresAt' => (int)$row['expires_at'], 'lastSeenAt' => (int)$row['last_seen_at'],
        'online' => (int)$row['last_seen_at'] >= time() - 20, 'createdAt' => $row['created_at']];
}

function az_phone_ice_servers(): array
{
    $configured = az_config()['phone_ice_servers'] ?? [['urls' => 'stun:stun.l.google.com:19302']];
    $result = [];
    if (is_array($configured)) {
        foreach (array_slice($configured, 0, 8) as $server) {
            if (!is_array($server)) { continue; }
            $urls = $server['urls'] ?? [];
            $urls = is_string($urls) ? [$urls] : $urls;
            if (!is_array($urls) || !$urls || count($urls) > 8) { continue; }
            $valid = array_filter($urls, static fn($url) => is_string($url) && strlen($url) < 512 && preg_match('/\A(?:stun|stuns|turn|turns):[^\s]+\z/', $url));
            if (count($valid) !== count($urls)) { continue; }
            $entry = ['urls' => array_values($valid)];
            foreach (['username', 'credential'] as $key) { if (isset($server[$key]) && is_string($server[$key])) { $entry[$key] = $server[$key]; } }
            $result[] = $entry;
        }
    }
    return $result;
}

function az_phone_status(array $row): array
{
    $room = az_phone_room($row['interview_id'], (int)$row['owner_id']);
    return ['connection' => az_phone_connection_data($row),
        'interview' => ['id' => $row['interview_id'], 'title' => $room['interview_title']],
        'question' => $row['status'] === 'approved' ? ['id' => $room['question_id'], 'text' => $room['question_text'], 'private' => (bool)$room['is_private']] : null,
        'hostOnline' => (int)$room['host_seen_at'] >= time() - 25,
        'limits' => az_limits(), 'iceServers' => $row['status'] === 'approved' ? az_phone_ice_servers() : []];
}

function az_phone_sdp(mixed $value): string
{
    if (!is_string($value) || strlen($value) < 15 || strlen($value) > 140000 || !preg_match('/\Av=0\r?\n/', $value) || str_contains($value, "\0")) {
        throw new AzHttpError(400, 'The live camera connection description is invalid.', 'invalid_sdp');
    }
    return $value;
}

function az_phone_own_upload(string $uploadId, array $phone, bool $validateWork = true): array
{
    az_phone_id($uploadId, 'Upload');
    $q = az_db()->prepare('SELECT u.* FROM uploads u JOIN phone_upload_links p ON p.upload_id = u.id WHERE u.id = ? AND p.connection_id = ? AND u.interview_id = ? AND u.owner_id = ?');
    $q->execute([$uploadId, $phone['id'], $phone['interview_id'], (int)$phone['owner_id']]);
    $row = $q->fetch();
    if (!$row || ($row['purpose'] ?? 'take') !== 'take') { throw new AzHttpError(404, 'This upload does not belong to this phone.', 'phone_upload_not_found'); }
    if ($validateWork && function_exists('az_work_check_storage_upload')) { az_work_check_storage_upload($row, (int)$phone['owner_id']); }
    return $row;
}

function az_phone_offer_data(array $phone): ?array
{
    if ($phone['status'] !== 'approved' || (int)$phone['expires_at'] <= time()) { return null; }
    $q = az_db()->prepare('SELECT * FROM phone_live WHERE connection_id = ? AND updated_at >= ?');
    $q->execute([$phone['id'], time() - 30]); $live = $q->fetch();
    if (!$live) { return null; }
    return ['id' => $phone['id'], 'label' => $phone['label'], 'nonce' => $live['nonce'], 'offer' => $live['offer_sdp']];
}
