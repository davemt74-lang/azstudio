<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/recordings.php';

ini_set('display_errors', '0');
header('Cache-Control: no-store');

try {
    $user = az_require_admin(true);
    $userId = $user['id'];
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
    if ($method === 'GET') {
        $csrf = az_csrf_token();
        session_write_close();
        if ($action === 'bootstrap') {
            $statement = az_db()->prepare('SELECT * FROM interviews WHERE owner_id = ? AND project_id IS NULL ORDER BY updated_at DESC, created_at DESC, id DESC');
            $statement->execute([$userId]);
            $interviews = array_map(static fn(array $row): array => az_interview_data($row), $statement->fetchAll());
            $statement = az_db()->prepare("SELECT * FROM uploads WHERE owner_id = ? AND status = 'pending' ORDER BY created_at DESC");
            $statement->execute([$userId]);
            az_json(['ok' => true, 'data' => ['user' => $user, 'csrf' => $csrf, 'version' => AZ_VERSION, 'limits' => az_limits(), 'interviews' => $interviews, 'schemaVersion' => az_schema_version(az_db()), 'profiles' => az_profiles($userId), 'questionnaires' => az_questionnaires($userId), 'pendingUploads' => array_map('az_upload_data', $statement->fetchAll())]]);
        }
        if ($action === 'interview') {
            $id = az_text($_GET['id'] ?? null, 32, 'Interview ID');
            $interview = az_interview_data(az_interview_row($id, $userId), true);
            if (function_exists('az_work_prepare_editor')) { $interview = az_work_prepare_editor($interview, $user); }
            az_json(['ok' => true, 'data' => $interview]);
        }
        if ($action === 'upload') {
            $id = az_text($_GET['uploadId'] ?? $_GET['id'] ?? null, 32, 'Upload ID');
            az_json(['ok' => true, 'data' => az_upload_data(az_upload_row($id, $userId))]);
        }
        $production = az_production_get($action, $_GET, $userId);
        if ($production !== null) { az_json(['ok' => true, 'data' => $production]); }
        throw new AzHttpError(404, 'This API action was not found.', 'unknown_action');
    }
    if ($method !== 'POST') {
        header('Allow: GET, POST');
        throw new AzHttpError(405, 'This action requires a GET or POST request.', 'method_not_allowed');
    }
    az_require_csrf();
    $passwordVersion = (int)($_SESSION['password_version'] ?? 1);
    session_write_close();
    az_prepare_storage();
    // Role changes and production reviews serialize before owner media mutations.
    $communityLock = az_community_lock();
    $fresh = az_account_user((int)$userId);
    if (!$fresh || $fresh['status'] !== 'active' || !az_has_role($fresh, 'admin') || (int)az_account_row((int)$userId)['password_version'] !== $passwordVersion) {
        throw new AzHttpError(403, 'Your studio access changed. Sign in again to continue.', 'role_required');
    }
    $user = $fresh;
    // Serialize this administrator's storage changes, including deletion versus a finishing upload.
    $mutationLock = fopen(AZ_STORAGE . '/uploads/admin-' . $userId . '.lock', 'c+b');
    if (!$mutationLock || !flock($mutationLock, LOCK_EX)) {
        throw new AzHttpError(503, 'The studio is busy saving another change. Please try again.', 'storage_busy');
    }
    $multipart = str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data');
    $body = $multipart ? $_POST : az_json_body();
    if ($action === '') {
        $action = az_text($body['action'] ?? '', 50, 'Action');
    }
    if ($action === 'createInterview') {
        $id = az_id();
        $links = az_interview_links($body, $userId);
        $title = az_text($body['title'] ?? 'Untitled interview', 200, 'Interview title');
        $participant = az_text($body['participantName'] ?? $links['modelName'], 200, 'Participant name');
        $notes = az_text($body['notes'] ?? '', 64000, 'Interview notes');
        $state = az_state_json($body['state'] ?? new stdClass());
        $now = az_now();
        $statement = az_db()->prepare("INSERT INTO interviews (id, owner_id, title, participant_name, notes, status, state_json, created_at, updated_at, model_id, questionnaire_id, questionnaire_snapshot_json) VALUES (?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?)");
        $statement->execute([$id, $userId, $title === '' ? 'Untitled interview' : $title, $participant, $notes, $state, $now, $now, $links['modelId'], $links['questionnaireId'], $links['snapshot']]);
        az_json(['ok' => true, 'data' => az_interview_data(az_interview_row($id, $userId), true)], 201);
    }
    if ($action === 'updateInterview' || $action === 'archiveInterview') {
        $id = az_text($body['id'] ?? $body['interviewId'] ?? '', 32, 'Interview ID');
        $row = az_interview_row($id, $userId);
        $relink = array_key_exists('modelId', $body) && (az_text($body['modelId'], 32, 'Model profile ID') ?: null) !== $row['model_id'];
        $statusChange = array_key_exists('status', $body) && $body['status'] !== $row['status'];
        if (!empty($row['project_id']) && ($action === 'archiveInterview' || $statusChange || $relink)) {
            throw new AzHttpError(409, 'Manage the project and its contributors from Productions.', 'project_managed');
        }
        if (isset($body['revision']) && az_integer($body['revision'], 1, PHP_INT_MAX, 'Revision') !== (int)$row['revision']) {
            throw new AzHttpError(409, 'This interview changed in another tab. Reload it before saving more changes.', 'revision_conflict');
        }
        $title = array_key_exists('title', $body) ? az_text($body['title'], 200, 'Interview title') : $row['title'];
        $participant = array_key_exists('participantName', $body) ? az_text($body['participantName'], 200, 'Participant name') : $row['participant_name'];
        $notes = array_key_exists('notes', $body) ? az_text($body['notes'], 64000, 'Interview notes') : $row['notes'];
        $state = array_key_exists('state', $body) ? az_state_json($body['state']) : $row['state_json'];
        $status = $body['status'] ?? $row['status'];
        if ($action === 'archiveInterview' && !isset($body['status'])) {
            $status = isset($body['archived']) && !filter_var($body['archived'], FILTER_VALIDATE_BOOLEAN) ? 'active' : 'archived';
        }
        if (!is_string($status) || !in_array($status, ['active', 'archived'], true)) {
            throw new AzHttpError(400, 'Interview status must be active or archived.', 'invalid_field');
        }
        $modelId = $row['model_id'];
        if (array_key_exists('modelId', $body)) {
            $modelId = az_text($body['modelId'], 32, 'Model profile ID');
            if ($modelId !== '') { az_profile_row($modelId, $userId); }
            $modelId = $modelId ?: null;
        }
        $now = az_now();
        $archivedAt = $status === 'archived' ? ($row['archived_at'] ?: $now) : null;
        $statement = az_db()->prepare('UPDATE interviews SET title = ?, participant_name = ?, notes = ?, state_json = ?, status = ?, archived_at = ?, updated_at = ?, model_id = ?, revision = revision + 1 WHERE id = ? AND owner_id = ?');
        $statement->execute([$title === '' ? 'Untitled interview' : $title, $participant, $notes, $state, $status, $archivedAt, $now, $modelId, $id, $userId]);
        if ($status === 'archived' && function_exists('az_phone_close_interview')) {
            az_phone_close_interview(az_db(), $id);
        }
        if ($status === 'archived' && function_exists('az_unpublish_interview')) {
            az_unpublish_interview($id);
        }
        if ($status === 'archived' && function_exists('az_work_withdraw_source')) {
            $sourceQuery = az_db()->prepare('SELECT id FROM takes WHERE interview_id=? AND owner_id=?');
            $sourceQuery->execute([$id, $userId]);
            foreach ($sourceQuery->fetchAll(PDO::FETCH_COLUMN) as $sourceId) { az_work_withdraw_source($sourceId); }
        }
        az_json(['ok' => true, 'data' => az_interview_data(az_interview_row($id, $userId), true)]);
    }
    if ($action === 'deleteInterview') {
        $id = az_text($body['id'] ?? $body['interviewId'] ?? '', 32, 'Interview ID');
        $row = az_interview_row($id, $userId);
        if (!empty($row['project_id'])) {
            throw new AzHttpError(409, 'Archive this project from Productions to preserve its assignment history.', 'project_managed');
        }
        $statement = az_db()->prepare('SELECT * FROM takes WHERE interview_id = ? AND owner_id = ?');
        $statement->execute([$id, $userId]);
        $takes = $statement->fetchAll();
        $sourceTakeIds = array_column($takes, 'id');
        $statement = az_db()->prepare('SELECT * FROM finished_videos WHERE interview_id = ? AND owner_id = ?');
        $statement->execute([$id, $userId]);
        foreach ($statement->fetchAll() as $video) { $takes = array_merge($takes, az_video_media_files($video)); }
        $statement = az_db()->prepare('SELECT * FROM uploads WHERE interview_id = ? AND owner_id = ?');
        $statement->execute([$id, $userId]);
        $uploads = $statement->fetchAll();
        az_remove_recordings($takes, $uploads, static function (PDO $database) use ($id, $userId, $sourceTakeIds): void {
            if (function_exists('az_work_withdraw_source')) {
                foreach ($sourceTakeIds as $sourceId) { az_work_withdraw_source($sourceId); }
            }
            $statement = $database->prepare('DELETE FROM interviews WHERE id = ? AND owner_id = ?');
            $statement->execute([$id, $userId]);
        });
        az_json(['ok' => true, 'data' => ['id' => $id, 'deleted' => true]]);
    }
    if ($action === 'beginUpload') {
        az_json(['ok' => true, 'data' => az_begin_upload($body, $userId)], 201);
    }
    if ($action === 'uploadChunk') {
        $uploadId = az_text($body['uploadId'] ?? '', 32, 'Upload ID');
        $index = az_integer($body['index'] ?? $body['chunkIndex'] ?? -1, 0, 1000000, 'Chunk index');
        $file = az_uploaded_file('chunk');
        az_json(['ok' => true, 'data' => az_store_chunk($uploadId, $index, $file['tmp_name'], $userId)]);
    }
    if ($action === 'finishUpload') {
        $uploadId = az_text($body['uploadId'] ?? '', 32, 'Upload ID');
        az_json(['ok' => true, 'data' => az_finish_upload($uploadId, $userId)]);
    }
    if ($action === 'abandonUpload') {
        $uploadId = az_text($body['uploadId'] ?? '', 32, 'Upload ID');
        az_json(['ok' => true, 'data' => az_abandon_upload($uploadId, $userId)]);
    }
    if ($action === 'saveTake') {
        $file = az_uploaded_file(isset($_FILES['file']) ? 'file' : 'recording');
        $body['totalBytes'] = (int)filesize($file['tmp_name']);
        $body['fileName'] = $body['fileName'] ?? basename($file['name']);
        $body['mimeType'] = $body['mimeType'] ?? $file['type'];
        foreach (['durationMs', 'answerStartMs'] as $field) {
            if (isset($body[$field]) && $body[$field] === '') {
                unset($body[$field]);
            }
        }
        $upload = az_begin_upload($body, $userId);
        $uploadId = $upload['uploadId'];
        $path = az_upload_path($uploadId);
        if (!move_uploaded_file($file['tmp_name'], $path)) {
            az_abandon_upload($uploadId, $userId);
            throw new AzHttpError(507, 'The server could not save this recording.', 'storage_unavailable');
        }
        @chmod($path, 0640);
        $statement = az_db()->prepare('UPDATE uploads SET received_bytes = total_bytes, updated_at = ? WHERE id = ? AND owner_id = ?');
        $statement->execute([az_now(), $uploadId, $userId]);
        az_json(['ok' => true, 'data' => az_finish_upload($uploadId, $userId)], 201);
    }
    if ($action === 'deleteTake') {
        $takeId = az_text($body['takeId'] ?? $body['id'] ?? '', 32, 'Recording ID');
        $take = az_take_row($takeId, $userId);
        $submissionCheck = az_db()->prepare('SELECT id FROM shot_submissions WHERE take_id=?');
        $submissionCheck->execute([$takeId]);
        if ($submissionCheck->fetchColumn()) {
            throw new AzHttpError(409, 'Production takes stay in their review history. Request a retake or cancel the assignment from Productions.', 'submission_history');
        }
        az_remove_recordings([$take], [], static function (PDO $database) use ($takeId, $userId, $take): void {
            if (function_exists('az_work_withdraw_source')) { az_work_withdraw_source($takeId); }
            $statement = $database->prepare('DELETE FROM uploads WHERE take_id = ? AND owner_id = ?');
            $statement->execute([$takeId, $userId]);
            $statement = $database->prepare('DELETE FROM takes WHERE id = ? AND owner_id = ?');
            $statement->execute([$takeId, $userId]);
            az_touch_interview($take['interview_id'], $userId);
        });
        az_json(['ok' => true, 'data' => ['id' => $takeId, 'takeId' => $takeId, 'interviewId' => $take['interview_id'], 'deleted' => true]]);
    }
    $production = az_production_post($action, $body, $userId);
    if ($production !== null) { az_json(['ok' => true, 'data' => $production]); }
    throw new AzHttpError(404, 'This API action was not found.', 'unknown_action');
} catch (AzHttpError $exception) {
    az_json(['ok' => false, 'error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], $exception->status);
} catch (Throwable $exception) {
    az_log_exception($exception);
    az_json(['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'The server could not finish this request. Reload to check your last saved change, or check the PHP error log.']], 500);
}
