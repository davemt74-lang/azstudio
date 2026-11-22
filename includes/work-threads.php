<?php
declare(strict_types=1);

/** Private, append-only questions between a production owner and one assignee. */
function az_upgrade_work_threads_schema(PDO $db): void
{
    if (az_schema_version($db) >= 16) { return; }
    az_prepare_storage();
    $lock = fopen(AZ_STORAGE . '/schema-migration.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('Could not lock the assignment questions upgrade.'); }
    try {
        if (az_schema_version($db) >= 16) { return; }
        if (az_schema_version($db) < 15) { throw new RuntimeException('Production plans must be upgraded before assignment questions.'); }
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $uid = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $db->exec("CREATE TABLE IF NOT EXISTS work_assignment_messages (id CHAR(32) PRIMARY KEY, assignment_id CHAR(32) NOT NULL, sequence_no BIGINT NOT NULL, author_id $uid NOT NULL, body TEXT NOT NULL, client_token VARCHAR(160) NOT NULL, request_hash CHAR(64) NOT NULL, created_at VARCHAR(32) NOT NULL, UNIQUE (assignment_id,sequence_no), UNIQUE (author_id,client_token), FOREIGN KEY (assignment_id) REFERENCES shot_assignments(id) ON DELETE CASCADE, FOREIGN KEY (author_id) REFERENCES users(id))$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS work_assignment_reads (assignment_id CHAR(32) NOT NULL, reader_id $uid NOT NULL, through_sequence BIGINT NOT NULL DEFAULT 0, updated_at VARCHAR(32) NOT NULL, PRIMARY KEY (assignment_id,reader_id), FOREIGN KEY (assignment_id) REFERENCES shot_assignments(id) ON DELETE CASCADE, FOREIGN KEY (reader_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        $db->exec('UPDATE studio_schema SET version=16 WHERE id=1');
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function az_work_thread_latest_sequence(string $assignmentId): int
{
    return (int)az_work_query('SELECT COALESCE(MAX(sequence_no),0) FROM work_assignment_messages WHERE assignment_id=?', [$assignmentId])->fetchColumn();
}

function az_work_thread_read_sequence(string $assignmentId, int $userId): int
{
    return (int)az_work_query('SELECT through_sequence FROM work_assignment_reads WHERE assignment_id=? AND reader_id=?', [$assignmentId, $userId])->fetchColumn();
}

/** Authorization is deliberately repeated: counts must not become an ID oracle. */
function az_work_thread_unread(string $assignmentId, array $user): int
{
    $user = az_work_live_user($user);
    az_work_assignment_access($assignmentId, $user);
    return az_work_thread_unread_authorized($assignmentId, (int)$user['id']);
}

function az_work_thread_unread_authorized(string $assignmentId, int $userId): int
{
    $through = az_work_thread_read_sequence($assignmentId, $userId);
    return (int)az_work_query('SELECT COUNT(*) FROM work_assignment_messages WHERE assignment_id=? AND author_id<>? AND sequence_no>?', [$assignmentId, $userId, $through])->fetchColumn();
}

function az_work_thread_read_only_reason(array $assignment): string
{
    if ((int)$assignment['owner_id'] === (int)$assignment['user_id']) { return 'Studio contributions do not have a separate model conversation.'; }
    if (!in_array($assignment['project_status'], ['draft', 'active'], true)) { return 'Questions are read-only while this production is completed or archived.'; }
    if (in_array($assignment['status'], ['declined', 'cancelled'], true)) { return 'This assignment no longer accepts new questions or replies.'; }
    $member = az_account_user((int)$assignment['user_id']);
    if (!$member || $member['status'] !== 'active' || !az_work_eligible($assignment, $member)) { return 'The assigned contributor is currently unavailable for this conversation.'; }
    return '';
}

function az_work_thread_message_data(array $row, int $userId): array
{
    return ['id' => $row['id'], 'sequence' => (int)$row['sequence_no'], 'authorId' => (int)$row['author_id'], 'authorName' => $row['author_name'], 'text' => $row['body'], 'createdAt' => $row['created_at'], 'isOwn' => (int)$row['author_id'] === $userId];
}

function az_work_thread_data(string $assignmentId, array $user, ?int $beforeSequence = null): array
{
    $user = az_work_live_user($user);
    $assignment = az_work_assignment_access($assignmentId, $user);
    $latest = az_work_thread_latest_sequence($assignmentId);
    $sql = 'SELECT m.*,u.name AS author_name FROM work_assignment_messages m JOIN users u ON u.id=m.author_id WHERE m.assignment_id=?';
    $args = [$assignmentId];
    if ($beforeSequence !== null) { $sql .= ' AND m.sequence_no<?'; $args[] = $beforeSequence; }
    $rows = az_work_query($sql . ' ORDER BY m.sequence_no DESC LIMIT 31', $args)->fetchAll();
    $hasMore = count($rows) > 30;
    if ($hasMore) { array_pop($rows); }
    $rows = array_reverse($rows);
    $reason = az_work_thread_read_only_reason($assignment);
    return ['assignmentId' => $assignmentId, 'messages' => array_map(static fn(array $row): array => az_work_thread_message_data($row, (int)$user['id']), $rows), 'hasMore' => $hasMore, 'oldestSequence' => $rows ? (int)$rows[0]['sequence_no'] : 0, 'latestSequence' => $latest, 'unreadCount' => az_work_thread_unread_authorized($assignmentId, (int)$user['id']), 'canPost' => $reason === '', 'readOnlyReason' => $reason, 'csrf' => az_csrf_token()];
}

function az_work_thread_text(mixed $value): string
{
    if (!is_string($value)) { throw new AzHttpError(400, 'Enter a question or reply.', 'invalid_message'); }
    $text = trim(str_replace("\0", '', $value));
    if ($text === '' || strlen($text) > 16000 || preg_match('//u', $text) !== 1 || preg_match_all('/./us', $text) > 4000) { throw new AzHttpError(400, 'Use between 1 and 4,000 characters for a question or reply.', 'invalid_message'); }
    return $text;
}

/** Called only under community.lock and the endpoint's transaction. */
function az_work_thread_send(array $body, array $user): array
{
    $user = az_work_live_user($user);
    $assignmentId = az_work_id($body['assignmentId'] ?? null);
    $assignment = az_work_assignment_access($assignmentId, $user);
    $text = az_work_thread_text($body['text'] ?? null);
    $token = az_text($body['requestId'] ?? '', 160, 'Request ID');
    if (!preg_match('/\A[a-zA-Z0-9_.:-]{8,160}\z/D', $token)) { throw new AzHttpError(400, 'Use a stable request ID of 8 to 160 letters, digits or separators.', 'invalid_request_id'); }
    $hash = hash('sha256', json_encode(['assignmentId' => $assignmentId, 'text' => $text], JSON_THROW_ON_ERROR));
    $old = az_work_query('SELECT id,assignment_id,request_hash FROM work_assignment_messages WHERE author_id=? AND client_token=?', [$user['id'], $token])->fetch();
    if ($old) {
        if ($old['assignment_id'] !== $assignmentId || !hash_equals($old['request_hash'], $hash)) { throw new AzHttpError(409, 'This request ID was already used for a different question or reply.', 'request_conflict'); }
        // Receipt recovery is safe after closure, but current read access is still required.
        return az_work_thread_data($assignmentId, $user) + ['sentMessageId' => $old['id']];
    }
    $reason = az_work_thread_read_only_reason($assignment);
    if ($reason !== '') { throw new AzHttpError(409, $reason, 'thread_read_only'); }
    $sequence = az_work_thread_latest_sequence($assignmentId) + 1;
    $id = az_id();
    az_work_query('INSERT INTO work_assignment_messages(id,assignment_id,sequence_no,author_id,body,client_token,request_hash,created_at) VALUES(?,?,?,?,?,?,?,?)', [$id, $assignmentId, $sequence, $user['id'], $text, $token, $hash, az_now()]);
    $recipient = (int)$assignment['owner_id'] === (int)$user['id'] ? (int)$assignment['user_id'] : (int)$assignment['owner_id'];
    az_work_notify($recipient, 'assignment_message', 'New assignment question or reply', 'A new message is waiting on ' . $assignment['shot_title'] . '.', $assignment['project_id'], $assignmentId);
    return az_work_thread_data($assignmentId, $user) + ['sentMessageId' => $id];
}

/** Acknowledging a rendered page never advances past the server's current end. */
function az_work_thread_mark_read(array $body, array $user): array
{
    $user = az_work_live_user($user);
    $assignmentId = az_work_id($body['assignmentId'] ?? null);
    az_work_assignment_access($assignmentId, $user);
    $through = az_integer($body['throughSequence'] ?? null, 0, PHP_INT_MAX, 'Read message sequence');
    if ($through > az_work_thread_latest_sequence($assignmentId)) { throw new AzHttpError(409, 'Reload the conversation before marking newer messages read.', 'read_sequence_ahead'); }
    $old = az_work_query('SELECT through_sequence FROM work_assignment_reads WHERE assignment_id=? AND reader_id=?', [$assignmentId, $user['id']])->fetch();
    if (!$old) {
        az_work_query('INSERT INTO work_assignment_reads(assignment_id,reader_id,through_sequence,updated_at) VALUES(?,?,?,?)', [$assignmentId, $user['id'], $through, az_now()]);
    } elseif ($through > (int)$old['through_sequence']) {
        az_work_query('UPDATE work_assignment_reads SET through_sequence=?,updated_at=? WHERE assignment_id=? AND reader_id=?', [$through, az_now(), $assignmentId, $user['id']]);
    } else { $through = (int)$old['through_sequence']; }
    return ['assignmentId' => $assignmentId, 'throughSequence' => $through, 'unreadCount' => az_work_thread_unread_authorized($assignmentId, (int)$user['id']), 'csrf' => az_csrf_token()];
}
