<?php
declare(strict_types=1);

function az_upload_path(string $id): string
{
    if (!az_valid_id($id)) {
        throw new AzHttpError(404, 'This upload was not found.', 'not_found');
    }
    return AZ_STORAGE . '/uploads/' . $id . '.part';
}

function az_upload_row(string $id, int $userId): array
{
    if (!az_valid_id($id)) {
        throw new AzHttpError(404, 'This upload was not found.', 'not_found');
    }
    $statement = az_db()->prepare('SELECT * FROM uploads WHERE id = ? AND owner_id = ?');
    $statement->execute([$id, $userId]);
    $row = $statement->fetch();
    if (!$row) {
        throw new AzHttpError(404, 'This upload was not found. Start the upload again from the saved recording.', 'upload_not_found');
    }
    return $row;
}

function az_upload_data(array $row): array
{
    return [
        'uploadId' => $row['id'], 'interviewId' => $row['interview_id'], 'chunkBytes' => (int)$row['chunk_bytes'],
        'nextIndex' => (int)$row['next_index'], 'receivedBytes' => (int)$row['received_bytes'],
        'totalBytes' => (int)$row['total_bytes'], 'status' => $row['status'], 'takeId' => $row['take_id'],
        'purpose' => $row['purpose'] ?? 'take', 'videoId' => $row['video_id'] ?? null,
        'meta' => empty($row['meta_json']) ? null : json_decode($row['meta_json'], true, 64, JSON_THROW_ON_ERROR),
    ];
}

function az_begin_upload(array $body, int $userId, ?array $workContext = null): array
{
    $interviewId = az_text($body['interviewId'] ?? null, 32, 'Interview ID');
    $interview = az_interview_row($interviewId, $userId);
    if (function_exists('az_work_retry_storage_upload') && ($retry=az_work_retry_storage_upload($body,$userId,$interview,$workContext))!==null) { return $retry; }
    $workContext = function_exists('az_work_prepare_storage_upload') ? az_work_prepare_storage_upload($body, $userId, $interview, $workContext) : null;
    $purpose = az_enum($body['purpose'] ?? 'take', ['take', 'finished'], 'Upload purpose');
    $metadata = $purpose === 'finished' ? az_video_metadata($body, $interview) : null;
    az_prepare_storage();
    $totalBytes = az_integer($body['totalBytes'] ?? $body['sizeBytes'] ?? 0, 1, az_limits()['maxUploadBytes'], 'Recording size');
    $durationMs = az_integer($body['durationMs'] ?? 0, 0, 172800000, 'Recording duration');
    $answerStartMs = isset($body['answerStartMs']) ? az_integer($body['answerStartMs'], 0, 172800000, 'Answer start') : null;
    if ($answerStartMs !== null && $durationMs > 0 && $answerStartMs > $durationMs) {
        throw new AzHttpError(400, 'The answer cannot start after the recording ends.', 'invalid_field');
    }
    $freeBytes = @disk_free_space(AZ_STORAGE);
    if ($freeBytes !== false && $freeBytes < $totalBytes + 10485760) {
        throw new AzHttpError(507, 'The server does not have enough free space for this recording.', 'storage_full');
    }
    $statement = az_db()->prepare("SELECT COUNT(*) FROM uploads WHERE owner_id = ? AND status = 'pending'");
    $statement->execute([$userId]);
    if ((int)$statement->fetchColumn() >= 30) {
        throw new AzHttpError(409, 'There are too many unfinished uploads. Resume or discard an unfinished upload first.', 'too_many_uploads');
    }
    $mimeType = strtolower(trim(explode(';', az_text($body['mimeType'] ?? '', 160, 'Media type'))[0]));
    if ($mimeType !== '' && $mimeType !== 'application/octet-stream' && !in_array($mimeType, az_limits()['acceptedMimeTypes'], true)) {
        throw new AzHttpError(415, 'Choose a supported video or audio recording.', 'unsupported_media');
    }
    $id = az_id();
    $now = az_now();
    $row = [
        'id' => $id, 'interview_id' => $interviewId, 'owner_id' => $userId,
        'question_id' => az_text($body['questionId'] ?? '', 160, 'Question ID'),
        'question_text' => az_text($body['questionText'] ?? '', 12000, 'Question text'),
        'label' => az_text($body['label'] ?? '', 200, 'Recording label'),
        'mime_type' => $mimeType,
        'file_name' => az_text($body['fileName'] ?? 'recording.webm', 250, 'File name'),
        'total_bytes' => $totalBytes, 'received_bytes' => 0, 'next_index' => 0,
        'chunk_bytes' => az_limits()['chunkBytes'], 'duration_ms' => $durationMs, 'answer_start_ms' => $answerStartMs,
        'is_private' => filter_var($body['isPrivate'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        'status' => 'pending', 'take_id' => null, 'created_at' => $now, 'updated_at' => $now,
        'purpose' => $purpose, 'video_id' => null,
        'meta_json' => $metadata === null ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ];
    $file = @fopen(az_upload_path($id), 'x+b');
    if (!$file) {
        throw new AzHttpError(507, 'The server could not create the recording file. Check the storage folder permissions.', 'storage_unavailable');
    }
    fclose($file);
    @chmod(az_upload_path($id), 0640);
    try {
        $columns = array_keys($row);
        $statement = az_db()->prepare('INSERT INTO uploads (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')');
        $statement->execute(array_values($row));
        if ($workContext !== null) { az_work_link_storage_upload($id, $workContext); }
    } catch (Throwable $exception) {
        az_db()->prepare('DELETE FROM uploads WHERE id = ? AND owner_id = ?')->execute([$id, $userId]);
        @unlink(az_upload_path($id));
        throw $exception;
    }
    return az_upload_data($row);
}

function az_uploaded_file(string $field): array
{
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || !isset($file['tmp_name'], $file['error']) || is_array($file['tmp_name'])) {
        $postLimit = az_bytes((string)ini_get('post_max_size'));
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $postLimit) {
            throw new AzHttpError(413, 'This upload exceeds the server request limit. Use smaller upload chunks.', 'upload_too_large');
        }
        throw new AzHttpError(400, 'The recording data was missing from this upload.', 'upload_missing');
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        $message = match ((int)$file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'This upload exceeds the server request limit. Use smaller upload chunks.',
            UPLOAD_ERR_PARTIAL => 'The connection ended before this upload finished. Please retry.',
            UPLOAD_ERR_NO_FILE => 'No recording data was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded recording. Check temporary storage permissions.',
            default => 'The server could not accept this recording upload.',
        };
        throw new AzHttpError(400, $message, 'upload_failed');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new AzHttpError(400, 'The recording upload is invalid.', 'upload_invalid');
    }
    return $file;
}

function az_store_chunk(string $uploadId, int $index, string $chunkPath, int $userId): array
{
    $upload = az_upload_row($uploadId, $userId);
    if (function_exists('az_work_check_storage_upload')) { az_work_check_storage_upload($upload, $userId); }
    if ($upload['status'] !== 'pending') {
        return az_upload_data($upload);
    }
    $path = az_upload_path($uploadId);
    $handle = @fopen($path, 'r+b');
    if (!$handle || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        throw new AzHttpError(409, 'This upload is busy or no longer available. Please retry.', 'upload_busy');
    }
    $database = az_db();
    try {
        $upload = az_upload_row($uploadId, $userId);
        $size = filesize($chunkPath);
        if ($size === false || $size < 1 || $size > (int)$upload['chunk_bytes']) {
            throw new AzHttpError(400, 'The recording chunk has an invalid size.', 'chunk_invalid');
        }
        $hash = hash_file('sha256', $chunkPath);
        if ($index < (int)$upload['next_index']) {
            $statement = $database->prepare('SELECT size_bytes, sha256 FROM upload_chunks WHERE upload_id = ? AND chunk_index = ?');
            $statement->execute([$uploadId, $index]);
            $existing = $statement->fetch();
            if ($existing && (int)$existing['size_bytes'] === $size && hash_equals($existing['sha256'], $hash)) {
                return az_upload_data($upload);
            }
            throw new AzHttpError(409, 'A different recording chunk already exists at this position.', 'chunk_conflict');
        }
        if ($index !== (int)$upload['next_index']) {
            throw new AzHttpError(409, 'The recording chunks arrived out of order. Resume from the last confirmed chunk.', 'chunk_out_of_order');
        }
        $expectedSize = min((int)$upload['chunk_bytes'], (int)$upload['total_bytes'] - (int)$upload['received_bytes']);
        if ($size !== $expectedSize) {
            throw new AzHttpError(400, 'The recording chunk is incomplete or exceeds the announced recording size.', 'chunk_size_mismatch');
        }
        $stat = fstat($handle);
        if ($stat === false || $stat['size'] < (int)$upload['received_bytes']) {
            throw new AzHttpError(409, 'The incomplete server upload cannot be resumed. Start this upload again from the saved recording.', 'upload_corrupted');
        }
        $database->beginTransaction();
        // A prior interrupted transaction may have appended bytes without committing its metadata.
        if (!ftruncate($handle, (int)$upload['received_bytes']) || fseek($handle, (int)$upload['received_bytes']) !== 0) {
            throw new RuntimeException('Could not position the recording upload file.');
        }
        $source = fopen($chunkPath, 'rb');
        if (!$source) {
            throw new RuntimeException('Could not read the recording chunk.');
        }
        try {
            $copied = stream_copy_to_stream($source, $handle);
        } finally {
            fclose($source);
        }
        if ($copied !== $size || !fflush($handle)) {
            throw new AzHttpError(507, 'The server ran out of space while saving the recording.', 'storage_full');
        }
        $statement = $database->prepare('INSERT INTO upload_chunks (upload_id, chunk_index, size_bytes, sha256) VALUES (?, ?, ?, ?)');
        $statement->execute([$uploadId, $index, $size, $hash]);
        $statement = $database->prepare('UPDATE uploads SET received_bytes = ?, next_index = ?, updated_at = ? WHERE id = ? AND owner_id = ?');
        $statement->execute([(int)$upload['received_bytes'] + $size, $index + 1, az_now(), $uploadId, $userId]);
        $database->commit();
        return az_upload_data(az_upload_row($uploadId, $userId));
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function az_detect_media_type(string $path, string $claimed): string
{
    $header = file_get_contents($path, false, null, 0, 64);
    if ($header === false || strlen($header) < 4) {
        throw new AzHttpError(415, 'The recording does not contain a supported media file.', 'unsupported_media');
    }
    if (str_starts_with($header, "\x1a\x45\xdf\xa3")) {
        return $claimed === 'audio/webm' ? 'audio/webm' : 'video/webm';
    }
    if (strlen($header) >= 12 && in_array(substr($header, 4, 4), ['ftyp', 'moov', 'mdat', 'wide'], true)) {
        if ($claimed === 'audio/mp4') {
            return 'audio/mp4';
        }
        return $claimed === 'video/quicktime' ? 'video/quicktime' : 'video/mp4';
    }
    if (str_starts_with($header, 'OggS')) {
        return $claimed === 'video/ogg' ? 'video/ogg' : 'audio/ogg';
    }
    if (str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WAVE') {
        return 'audio/wav';
    }
    if (str_starts_with($header, 'fLaC')) {
        return 'audio/flac';
    }
    if (str_starts_with($header, 'ID3') || (ord($header[0]) === 0xff && (ord($header[1]) & 0xe0) === 0xe0)) {
        return $claimed === 'audio/aac' ? 'audio/aac' : 'audio/mpeg';
    }
    throw new AzHttpError(415, 'This file is not a supported WebM, MP4, MOV, Ogg, or audio recording.', 'unsupported_media');
}

function az_completed_upload_result(array $upload, int $userId): ?array
{
    if ($upload['status'] !== 'completed') { return null; }
    if (($upload['purpose'] ?? 'take') === 'finished' && !empty($upload['video_id'])) {
        return ['video' => az_video_data(az_video_row($upload['video_id'], $userId))];
    }
    $take = !empty($upload['take_id']) ? az_take_data(az_take_row($upload['take_id'], $userId)) : null;
    if ($take !== null && function_exists('az_work_after_storage_take')) { az_work_after_storage_take($upload, $take, $userId); }
    return $take;
}

function az_finish_upload(string $uploadId, int $userId): array
{
    $upload = az_upload_row($uploadId, $userId);
    if (function_exists('az_work_check_storage_upload')) { az_work_check_storage_upload($upload, $userId); }
    if (($completed = az_completed_upload_result($upload, $userId)) !== null) {
        return $completed;
    }
    if ((int)$upload['received_bytes'] !== (int)$upload['total_bytes']) {
        throw new AzHttpError(409, 'The recording has not finished uploading yet.', 'upload_incomplete');
    }
    $path = az_upload_path($uploadId);
    $handle = @fopen($path, 'r+b');
    if (!$handle || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        // Another finish request may have completed immediately before this one.
        $upload = az_upload_row($uploadId, $userId);
        if (($completed = az_completed_upload_result($upload, $userId)) !== null) {
            return $completed;
        }
        throw new AzHttpError(409, 'The uploaded recording is not available. Please retry.', 'upload_busy');
    }
    $database = az_db();
    $destination = null;
    try {
        $upload = az_upload_row($uploadId, $userId);
        if (($completed = az_completed_upload_result($upload, $userId)) !== null) {
            return $completed;
        }
        $interview = az_interview_row($upload['interview_id'], $userId);
        $stat = fstat($handle);
        if ($stat === false || $stat['size'] !== (int)$upload['total_bytes']) {
            throw new AzHttpError(409, 'The uploaded file size did not match the recording. Retry the upload.', 'upload_size_mismatch');
        }
        $mimeType = az_detect_media_type($path, $upload['mime_type']);
        $finished = ($upload['purpose'] ?? 'take') === 'finished';
        if ($finished && !str_starts_with($mimeType, 'video/')) {
            throw new AzHttpError(415, 'A finished export must be a video file.', 'unsupported_media');
        }
        $takeId = az_id();
        $storageName = $takeId . '.bin';
        $destination = AZ_STORAGE . '/media/' . $storageName;
        $row = [
            'id' => $takeId, 'interview_id' => $upload['interview_id'], 'owner_id' => $userId,
            'question_id' => $upload['question_id'], 'question_text' => $upload['question_text'],
            'label' => $upload['label'], 'mime_type' => $mimeType, 'file_name' => $upload['file_name'],
            'storage_name' => $storageName, 'size_bytes' => (int)$upload['total_bytes'],
            'duration_ms' => (int)$upload['duration_ms'], 'answer_start_ms' => $upload['answer_start_ms'],
            'is_private' => (int)$upload['is_private'], 'created_at' => az_now(),
        ];
        $database->beginTransaction();
        if ($finished) {
            $meta = json_decode($upload['meta_json'], true, 64, JSON_THROW_ON_ERROR);
            $statement = $database->prepare('UPDATE interviews SET export_sequence = export_sequence + 1 WHERE id = ? AND owner_id = ?');
            $statement->execute([$upload['interview_id'], $userId]);
            $statement = $database->prepare('SELECT export_sequence FROM interviews WHERE id = ? AND owner_id = ?');
            $statement->execute([$upload['interview_id'], $userId]);
            $row = [
                'id' => $takeId, 'interview_id' => $upload['interview_id'], 'owner_id' => $userId,
                'model_id' => $interview['model_id'], 'participant_name' => $interview['participant_name'],
                'title' => $meta['title'], 'description' => $meta['description'], 'status' => $meta['status'],
                'published_url' => $meta['publishedUrl'], 'version' => (int)$statement->fetchColumn(),
                'source_edit_json' => json_encode($meta['sourceEdit'] ?: new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'mime_type' => $mimeType, 'file_name' => $upload['file_name'], 'storage_name' => $storageName,
                'size_bytes' => (int)$upload['total_bytes'], 'duration_ms' => (int)$upload['duration_ms'],
                'revision' => 1, 'created_at' => az_now(), 'updated_at' => az_now(), 'archived_at' => null,
            ];
            az_insert_row('finished_videos', $row);
            $statement = $database->prepare("UPDATE uploads SET status = 'completed', video_id = ?, updated_at = ? WHERE id = ? AND owner_id = ?");
        } else {
            $columns = array_keys($row);
            $statement = $database->prepare('INSERT INTO takes (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')');
            $statement->execute(array_values($row));
            $statement = $database->prepare("UPDATE uploads SET status = 'completed', take_id = ?, updated_at = ? WHERE id = ? AND owner_id = ?");
        }
        $statement->execute([$takeId, az_now(), $uploadId, $userId]);
        az_touch_interview($upload['interview_id'], $userId);
        if (!rename($path, $destination)) {
            throw new AzHttpError(507, 'The server could not move the completed recording into storage.', 'storage_unavailable');
        }
        $database->commit();
        $destination = null;
        $statement = $database->prepare('DELETE FROM upload_chunks WHERE upload_id = ?');
        $statement->execute([$uploadId]);
        if (!$finished && function_exists('az_work_after_storage_take')) { az_work_after_storage_take(az_upload_row($uploadId, $userId), $row, $userId); }
        return $finished ? ['video' => az_video_data($row)] : az_take_data(az_take_row($row['id'], $userId));
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        if ($destination !== null && is_file($destination) && !is_file($path)) {
            @rename($destination, $path);
        }
        throw $exception;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function az_abandon_upload(string $uploadId, int $userId): array
{
    $upload = az_upload_row($uploadId, $userId);
    if ($upload['status'] === 'completed') {
        return ['abandoned' => false, 'completed' => true, 'takeId' => $upload['take_id'], 'videoId' => $upload['video_id'] ?? null, 'purpose' => $upload['purpose'] ?? 'take'];
    }
    $path = az_upload_path($uploadId);
    $handle = is_file($path) ? @fopen($path, 'r+b') : false;
    if ($handle && !flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new AzHttpError(409, 'This recording is still being saved. Try again shortly.', 'upload_busy');
    }
    try {
        $upload = az_upload_row($uploadId, $userId);
        if ($upload['status'] === 'completed') {
            return ['abandoned' => false, 'completed' => true, 'takeId' => $upload['take_id'], 'videoId' => $upload['video_id'] ?? null, 'purpose' => $upload['purpose'] ?? 'take'];
        }
        if (is_file($path) && !unlink($path)) {
            throw new AzHttpError(500, 'The incomplete recording could not be removed from storage.', 'storage_unavailable');
        }
        $statement = az_db()->prepare('DELETE FROM uploads WHERE id = ? AND owner_id = ?');
        $statement->execute([$uploadId, $userId]);
        return ['abandoned' => true];
    } finally {
        if ($handle) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

function az_remove_recordings(array $takes, array $uploads, callable $databaseDelete): void
{
    // Quarantine first so a failed database transaction can put every original file back.
    $moved = [];
    $handles = [];
    $database = az_db();
    try {
        foreach ($uploads as $upload) {
            $path = az_upload_path($upload['id']);
            if (!is_file($path)) {
                continue;
            }
            $handle = @fopen($path, 'r+b');
            if (!$handle || !flock($handle, LOCK_EX)) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
                throw new AzHttpError(409, 'An upload is still being saved. Try deleting again shortly.', 'upload_busy');
            }
            $handles[] = $handle;
        }
        $database->beginTransaction();
        $paths = array_map('az_media_path', $takes);
        foreach ($uploads as $upload) {
            $paths[] = az_upload_path($upload['id']);
        }
        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $temporary = $path . '.delete-' . az_id();
            if (!rename($path, $temporary)) {
                throw new AzHttpError(500, 'The server could not remove this recording from storage.', 'storage_unavailable');
            }
            $moved[$path] = $temporary;
        }
        $databaseDelete($database);
        $database->commit();
        foreach ($moved as $temporary) {
            if (!@unlink($temporary)) {
                error_log('AZHottie cleanup pending: ' . basename($temporary));
            }
        }
        $moved = [];
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        foreach ($moved as $original => $temporary) {
            if (is_file($temporary)) {
                @rename($temporary, $original);
            }
        }
        throw $exception;
    } finally {
        foreach ($handles as $handle) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
