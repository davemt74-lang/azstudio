<?php
declare(strict_types=1);

/* Persistent production records. All callers supply the authenticated owner. */
function az_owned_row(string $table, string $id, int $userId, string $label): array
{
    if (!in_array($table, ['model_profiles', 'questionnaires', 'finished_videos'], true) || !az_valid_id($id)) {
        throw new AzHttpError(404, $label . ' was not found.', 'not_found');
    }
    $statement = az_db()->prepare("SELECT * FROM $table WHERE id = ? AND owner_id = ?");
    $statement->execute([$id, $userId]);
    $row = $statement->fetch();
    if (!$row) {
        throw new AzHttpError(404, $label . ' was not found.', 'not_found');
    }
    return $row;
}

function az_profile_row(string $id, int $userId): array { return az_owned_row('model_profiles', $id, $userId, 'This model profile'); }
function az_questionnaire_row(string $id, int $userId): array { return az_owned_row('questionnaires', $id, $userId, 'This questionnaire'); }
function az_video_row(string $id, int $userId): array { return az_owned_row('finished_videos', $id, $userId, 'This finished video'); }

function az_revision_check(array $body, array $row, string $label): void
{
    if (isset($body['revision']) && az_integer($body['revision'], 1, PHP_INT_MAX, 'Revision') !== (int)$row['revision']) {
        throw new AzHttpError(409, $label . ' changed in another tab. Reload before saving more changes.', 'revision_conflict');
    }
}

function az_enum(mixed $value, array $allowed, string $label): string
{
    if (!is_string($value) || !in_array($value, $allowed, true)) {
        throw new AzHttpError(400, $label . ' is invalid.', 'invalid_field');
    }
    return $value;
}

function az_bool(mixed $value, string $label): bool
{
    $result = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($result === null || (!is_bool($value) && !is_int($value) && !is_string($value))) {
        throw new AzHttpError(400, $label . ' must be true or false.', 'invalid_field');
    }
    return $result;
}

function az_profile_data(array $row, bool $full = false): array
{
    $statement = az_db()->prepare('SELECT * FROM interviews WHERE model_id = ? AND owner_id = ? AND project_id IS NULL ORDER BY updated_at DESC, id DESC');
    $statement->execute([$row['id'], (int)$row['owner_id']]);
    $interviews = $statement->fetchAll();
    $data = [
        'id' => $row['id'], 'stageName' => $row['stage_name'], 'publicBio' => $row['public_bio'], 'bio' => $row['public_bio'],
        'socialHandles' => $row['social_handles'], 'interests' => $row['interests'], 'availability' => $row['availability'],
        'privateNotes' => $row['private_notes'], 'status' => $row['status'], 'revision' => (int)$row['revision'],
        'photoUrl' => empty($row['photo_storage_name']) ? null : az_url('media.php?type=profile&id=' . $row['id'] . '&v=' . $row['revision']),
        'createdAt' => $row['created_at'], 'updatedAt' => $row['updated_at'], 'interviewCount' => count($interviews),
    ];
    if ($full) {
        $data['interviews'] = array_map(static fn(array $item): array => az_interview_data($item), $interviews);
    }
    return $data;
}

function az_profiles(int $userId): array
{
    $statement = az_db()->prepare('SELECT * FROM model_profiles WHERE owner_id = ? ORDER BY updated_at DESC, id DESC');
    $statement->execute([$userId]);
    return array_map('az_profile_data', $statement->fetchAll());
}

function az_save_profile(array $body, int $userId): array
{
    $id = az_text($body['id'] ?? '', 32, 'Model profile ID');
    $old = $id !== '' ? az_profile_row($id, $userId) : null;
    if ($old) {
        az_revision_check($body, $old, 'This model profile');
    }
    $name = az_text($body['stageName'] ?? ($old['stage_name'] ?? ''), 200, 'Stage name');
    if ($name === '') {
        throw new AzHttpError(400, 'Enter a model or stage name.', 'invalid_field');
    }
    $values = [
        'stage_name' => $name,
        'public_bio' => az_text($body['publicBio'] ?? $body['bio'] ?? ($old['public_bio'] ?? ''), 16000, 'Public bio'),
        'social_handles' => az_text($body['socialHandles'] ?? ($old['social_handles'] ?? ''), 4000, 'Social handles'),
        'interests' => az_text($body['interests'] ?? ($old['interests'] ?? ''), 8000, 'Interests'),
        'availability' => az_text($body['availability'] ?? ($old['availability'] ?? ''), 8000, 'Availability'),
        'private_notes' => az_text($body['privateNotes'] ?? ($old['private_notes'] ?? ''), 64000, 'Private notes'),
        'status' => az_enum($body['status'] ?? ($old['status'] ?? 'active'), ['active', 'archived'], 'Profile status'),
        'updated_at' => az_now(),
    ];
    if ($old) {
        $assignments = array_map(static fn(string $field): string => "$field = ?", array_keys($values));
        $statement = az_db()->prepare('UPDATE model_profiles SET ' . implode(', ', $assignments) . ', revision = revision + 1 WHERE id = ? AND owner_id = ?');
        $statement->execute([...array_values($values), $id, $userId]);
    } else {
        $id = az_id();
        $values += ['id' => $id, 'owner_id' => $userId, 'created_at' => $values['updated_at']];
        az_insert_row('model_profiles', $values);
    }
    return az_profile_data(az_profile_row($id, $userId), true);
}

function az_insert_row(string $table, array $values): void
{
    if (!in_array($table, ['model_profiles', 'questionnaires', 'take_notes', 'finished_videos'], true)) {
        throw new RuntimeException('Invalid production table.');
    }
    $statement = az_db()->prepare('INSERT INTO ' . $table . ' (' . implode(', ', array_keys($values)) . ') VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')');
    $statement->execute(array_values($values));
}

function az_profile_photo(array $body, int $userId): array
{
    $id = az_text($body['id'] ?? '', 32, 'Model profile ID');
    $profile = az_profile_row($id, $userId);
    $file = az_uploaded_file('photo');
    $size = filesize($file['tmp_name']);
    if ($size === false || $size < 1 || $size > 5242880) {
        throw new AzHttpError(413, 'Choose a profile photo smaller than 5 MB.', 'photo_too_large');
    }
    $info = @getimagesize($file['tmp_name']);
    $mime = $info['mime'] ?? '';
    if (!$info || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $info[0] > 12000 || $info[1] > 12000) {
        throw new AzHttpError(415, 'Choose a JPG, PNG, or WebP photo up to 12,000 pixels wide or tall.', 'unsupported_photo');
    }
    $storageName = az_id() . '.bin';
    $path = az_media_path(['storage_name' => $storageName]);
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        throw new AzHttpError(507, 'The server could not save this profile photo.', 'storage_unavailable');
    }
    @chmod($path, 0640);
    try {
        $statement = az_db()->prepare('UPDATE model_profiles SET photo_storage_name = ?, photo_mime_type = ?, photo_file_name = ?, updated_at = ?, revision = revision + 1 WHERE id = ? AND owner_id = ?');
        $statement->execute([$storageName, $mime, 'profile-photo', az_now(), $id, $userId]);
    } catch (Throwable $exception) {
        @unlink($path);
        throw $exception;
    }
    if (!empty($profile['photo_storage_name'])) {
        @unlink(az_media_path(['storage_name' => $profile['photo_storage_name']]));
    }
    return az_profile_data(az_profile_row($id, $userId), true);
}

function az_delete_profile(string $id, int $userId): array
{
    $profile = az_profile_row($id, $userId);
    $media = empty($profile['photo_storage_name']) ? [] : [['storage_name' => $profile['photo_storage_name']]];
    az_remove_recordings($media, [], static function (PDO $db) use ($id, $userId): void {
        // Historical display names and exports remain intact after unlinking a profile.
        $statement = $db->prepare('UPDATE interviews SET model_id = NULL WHERE model_id = ? AND owner_id = ?');
        $statement->execute([$id, $userId]);
        $statement = $db->prepare('UPDATE finished_videos SET model_id = NULL WHERE model_id = ? AND owner_id = ?');
        $statement->execute([$id, $userId]);
        $statement = $db->prepare('DELETE FROM model_profiles WHERE id = ? AND owner_id = ?');
        $statement->execute([$id, $userId]);
    });
    return ['id' => $id, 'deleted' => true];
}

function az_questions(mixed $value): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > 200) {
        throw new AzHttpError(400, 'A questionnaire needs between 1 and 200 questions.', 'invalid_questions');
    }
    $questions = [];
    $ids = [];
    foreach ($value as $index => $item) {
        if ($item instanceof stdClass) {
            $item = get_object_vars($item);
        }
        if (!is_array($item)) {
            throw new AzHttpError(400, 'Each question must be an object.', 'invalid_questions');
        }
        $id = az_text($item['id'] ?? '', 160, 'Question ID');
        if ($id === '') {
            $id = 'custom-' . az_id();
        }
        if (isset($ids[$id])) {
            throw new AzHttpError(400, 'Each question needs a unique ID.', 'invalid_questions');
        }
        $ids[$id] = true;
        $text = az_text($item['text'] ?? '', 12000, 'Question');
        if ($text === '') {
            throw new AzHttpError(400, 'Enter text for every question.', 'invalid_questions');
        }
        $type = az_enum($item['type'] ?? 'open', ['choice', 'open'], 'Question type');
        $choices = $item['choices'] ?? [];
        if (!is_array($choices) || !array_is_list($choices) || count($choices) > 12) {
            throw new AzHttpError(400, 'Use no more than 12 choices per question.', 'invalid_questions');
        }
        $choices = array_map(static fn(mixed $choice): string => az_text($choice, 2000, 'Answer choice'), $choices);
        if ($type === 'choice' && (count($choices) < 2 || in_array('', $choices, true))) {
            throw new AzHttpError(400, 'Multiple-choice questions need at least two nonempty choices.', 'invalid_questions');
        }
        $questions[] = ['id' => $id, 'category' => az_text($item['category'] ?? 'Custom', 200, 'Question category'), 'text' => $text,
            'type' => $type, 'choices' => $type === 'choice' ? $choices : [], 'private' => az_bool($item['private'] ?? false, 'Private question')];
    }
    $json = json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (strlen($json) > az_limits()['maxStateBytes']) {
        throw new AzHttpError(413, 'This questionnaire contains too much text.', 'state_too_large');
    }
    return $questions;
}

function az_questionnaire_data(array $row): array
{
    $questions = json_decode($row['questions_json'], true, 64, JSON_THROW_ON_ERROR);
    return ['id' => $row['id'], 'name' => $row['name'], 'description' => $row['description'], 'status' => $row['status'],
        'revision' => (int)$row['revision'], 'questions' => $questions, 'questionCount' => count($questions),
        'createdAt' => $row['created_at'], 'updatedAt' => $row['updated_at']];
}

function az_questionnaires(int $userId): array
{
    require_once __DIR__ . '/questionnaire-presets.php';
    az_seed_editable_questionnaires($userId);
    $statement = az_db()->prepare('SELECT * FROM questionnaires WHERE owner_id = ? ORDER BY updated_at DESC, id DESC');
    $statement->execute([$userId]);
    return array_map('az_questionnaire_data', $statement->fetchAll());
}

function az_save_questionnaire(array $body, int $userId): array
{
    $id = az_text($body['id'] ?? '', 32, 'Questionnaire ID');
    $old = $id !== '' ? az_questionnaire_row($id, $userId) : null;
    if ($old) {
        az_revision_check($body, $old, 'This questionnaire');
    }
    $name = az_text($body['name'] ?? ($old['name'] ?? ''), 200, 'Questionnaire name');
    if ($name === '') {
        throw new AzHttpError(400, 'Enter a questionnaire name.', 'invalid_field');
    }
    $questions = az_questions($body['questions'] ?? ($old ? json_decode($old['questions_json'], true, 64, JSON_THROW_ON_ERROR) : []));
    $values = [
        'name' => $name, 'description' => az_text($body['description'] ?? ($old['description'] ?? ''), 16000, 'Description'),
        'questions_json' => json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'status' => az_enum($body['status'] ?? ($old['status'] ?? 'active'), ['active', 'archived'], 'Questionnaire status'), 'updated_at' => az_now(),
    ];
    if ($old) {
        $statement = az_db()->prepare('UPDATE questionnaires SET name = ?, description = ?, questions_json = ?, status = ?, updated_at = ?, revision = revision + 1 WHERE id = ? AND owner_id = ?');
        $statement->execute([...array_values($values), $id, $userId]);
    } else {
        $id = az_id();
        $values += ['id' => $id, 'owner_id' => $userId, 'created_at' => $values['updated_at']];
        az_insert_row('questionnaires', $values);
    }
    return az_questionnaire_data(az_questionnaire_row($id, $userId));
}

function az_delete_questionnaire(string $id, int $userId): array
{
    az_questionnaire_row($id, $userId);
    $db = az_db();
    $db->beginTransaction();
    try {
        // Preserve the immutable snapshot and its origin ID inside each existing interview.
        $statement = $db->prepare('UPDATE interviews SET questionnaire_id = NULL WHERE questionnaire_id = ? AND owner_id = ?');
        $statement->execute([$id, $userId]);
        $statement = $db->prepare('DELETE FROM questionnaires WHERE id = ? AND owner_id = ?');
        $statement->execute([$id, $userId]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) { $db->rollBack(); }
        throw $exception;
    }
    return ['id' => $id, 'deleted' => true];
}

function az_interview_links(array $body, int $userId): array
{
    $modelId = az_text($body['modelId'] ?? '', 32, 'Model profile ID');
    $model = $modelId !== '' ? az_profile_row($modelId, $userId) : null;
    if ($model && $model['status'] !== 'active') {
        throw new AzHttpError(400, 'Restore the archived model profile before starting an interview.', 'profile_archived');
    }
    $questionnaireId = az_text($body['questionnaireId'] ?? '', 32, 'Questionnaire ID');
    $snapshot = null;
    if ($questionnaireId !== '') {
        $questionnaire = az_questionnaire_row($questionnaireId, $userId);
        if ($questionnaire['status'] !== 'active') {
            throw new AzHttpError(400, 'Restore the archived questionnaire before using it for a new interview.', 'questionnaire_archived');
        }
        $snapshot = az_questionnaire_data($questionnaire);
    }
    return ['modelId' => $modelId ?: null, 'modelName' => $model['stage_name'] ?? '', 'questionnaireId' => $questionnaireId ?: null,
        'snapshot' => $snapshot === null ? null : json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
}

function az_take_notes(string $takeId, int $userId): array
{
    $statement = az_db()->prepare('SELECT id, at_ms, note_text, created_at FROM take_notes WHERE take_id = ? AND owner_id = ? ORDER BY at_ms ASC, created_at ASC, id ASC');
    $statement->execute([$takeId, $userId]);
    return array_map(static fn(array $row): array => ['id' => $row['id'], 'atMs' => (int)$row['at_ms'], 'text' => $row['note_text'], 'createdAt' => $row['created_at']], $statement->fetchAll());
}

function az_update_take_review(array $body, int $userId): array
{
    $id = az_text($body['takeId'] ?? '', 32, 'Recording ID');
    $take = az_take_row($id, $userId);
    $favorite = array_key_exists('isFavorite', $body) ? az_bool($body['isFavorite'], 'Favorite') : (bool)$take['is_favorite'];
    $status = az_enum($body['reviewStatus'] ?? $take['review_status'], ['unreviewed', 'shortlisted', 'retake', 'private'], 'Review status');
    $statement = az_db()->prepare('UPDATE takes SET is_favorite = ?, review_status = ? WHERE id = ? AND owner_id = ?');
    $statement->execute([$favorite ? 1 : 0, $status, $id, $userId]);
    if ($status !== 'shortlisted' && function_exists('az_work_withdraw_source')) { az_work_withdraw_source($id); }
    return az_take_data(az_take_row($id, $userId));
}

function az_add_take_note(array $body, int $userId): array
{
    $id = az_text($body['takeId'] ?? '', 32, 'Recording ID');
    $take = az_take_row($id, $userId);
    $at = az_integer($body['atMs'] ?? 0, 0, max(0, (int)$take['duration_ms']), 'Review timestamp');
    $text = az_text($body['text'] ?? '', 8000, 'Review note');
    if ($text === '') {
        throw new AzHttpError(400, 'Enter a review note.', 'invalid_field');
    }
    $statement = az_db()->prepare('SELECT COUNT(*) FROM take_notes WHERE take_id = ? AND owner_id = ?');
    $statement->execute([$id, $userId]);
    if ((int)$statement->fetchColumn() >= 500) {
        throw new AzHttpError(400, 'This take already has 500 review notes.', 'too_many_notes');
    }
    az_insert_row('take_notes', ['id' => az_id(), 'take_id' => $id, 'owner_id' => $userId, 'at_ms' => $at, 'note_text' => $text, 'created_at' => az_now()]);
    return az_take_data(az_take_row($id, $userId));
}

function az_delete_take_note(array $body, int $userId): array
{
    $id = az_text($body['takeId'] ?? '', 32, 'Recording ID');
    az_take_row($id, $userId);
    $noteId = az_text($body['noteId'] ?? '', 32, 'Review note ID');
    $statement = az_db()->prepare('DELETE FROM take_notes WHERE id = ? AND take_id = ? AND owner_id = ?');
    $statement->execute([$noteId, $id, $userId]);
    if ($statement->rowCount() === 0) {
        throw new AzHttpError(404, 'This review note was not found.', 'not_found');
    }
    return az_take_data(az_take_row($id, $userId));
}

function az_publication_url(mixed $value): string
{
    $url = az_text($value, 2000, 'Published URL');
    if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['https', 'http'], true))) {
        throw new AzHttpError(400, 'Enter a complete http:// or https:// published URL.', 'invalid_field');
    }
    return $url;
}

function az_video_metadata(array $body, array $interview): array
{
    $title = az_text($body['title'] ?? $interview['title'], 200, 'Finished video title');
    return [
        'title' => $title ?: $interview['title'], 'description' => az_text($body['description'] ?? '', 16000, 'Video description'),
        'status' => az_enum($body['status'] ?? 'draft', ['draft', 'review', 'ready', 'published'], 'Video status'),
        'publishedUrl' => az_publication_url($body['publishedUrl'] ?? ''),
        'sourceEdit' => json_decode(az_state_json($body['sourceEdit'] ?? new stdClass()), true, 64, JSON_THROW_ON_ERROR),
    ];
}

function az_video_data(array $row): array
{
    $projectQuery = az_db()->prepare('SELECT p.id,p.title FROM production_projects p WHERE p.interview_id=? AND p.owner_id=?');
    $projectQuery->execute([$row['interview_id'], $row['owner_id']]);
    $project = $projectQuery->fetch();
    $sourceEdit = json_decode($row['source_edit_json'], true, 64, JSON_THROW_ON_ERROR);
    $editorUrl = az_url('editor.php?id=' . $row['interview_id']);
    if ($project && !empty($sourceEdit['timelineId']) && az_valid_id((string)$sourceEdit['timelineId']) && (int)($sourceEdit['timelineRevision'] ?? 0) > 0) {
        $editorUrl .= '&timelineId=' . $sourceEdit['timelineId'] . '&timelineRevision=' . (int)$sourceEdit['timelineRevision'];
    }
    return [
        'id' => $row['id'], 'interviewId' => $row['interview_id'], 'modelId' => $row['model_id'], 'participantName' => $row['participant_name'],
        'title' => $row['title'], 'description' => $row['description'], 'status' => $row['status'], 'publishedUrl' => $row['published_url'],
        'version' => (int)$row['version'], 'revision' => (int)$row['revision'], 'sourceEdit' => $sourceEdit,
        'fileName' => $row['file_name'], 'mimeType' => $row['mime_type'], 'sizeBytes' => (int)$row['size_bytes'], 'durationMs' => (int)$row['duration_ms'],
        'archived' => $row['archived_at'] !== null, 'archivedAt' => $row['archived_at'], 'createdAt' => $row['created_at'], 'updatedAt' => $row['updated_at'],
        'mediaUrl' => az_url('media.php?type=finished&id=' . $row['id']), 'downloadUrl' => az_url('media.php?type=finished&id=' . $row['id'] . '&download=1'),
        'coverUrl' => empty($row['cover_storage_name']) ? null : az_url('media.php?type=cover&id=' . $row['id'] . '&v=' . $row['revision']),
        'projectId' => $project['id'] ?? null, 'projectTitle' => $project['title'] ?? null,
        'projectUrl' => $project ? az_url('productions.php?projectId=' . $project['id']) : null,
        'editorUrl' => $editorUrl,
    ] + az_publication_fields($row);
}

function az_videos(int $userId, array $filters = []): array
{
    $clauses = ['owner_id = ?']; $params = [$userId];
    if (isset($filters['interviewId']) && $filters['interviewId'] !== '') {
        $id = az_text($filters['interviewId'], 32, 'Interview ID');
        az_interview_row($id, $userId);
        $clauses[] = 'interview_id = ?'; $params[] = $id;
    }
    if (isset($filters['status']) && !in_array($filters['status'], ['', 'all'], true)) {
        if ($filters['status'] === 'archived') {
            $clauses[] = 'archived_at IS NOT NULL';
        } elseif ($filters['status'] === 'active') {
            $clauses[] = 'archived_at IS NULL';
        } else {
            $clauses[] = 'status = ?'; $params[] = az_enum($filters['status'], ['draft', 'review', 'ready', 'published'], 'Video status');
            $clauses[] = 'archived_at IS NULL';
        }
    }
    $statement = az_db()->prepare('SELECT * FROM finished_videos WHERE ' . implode(' AND ', $clauses) . ' ORDER BY created_at DESC, version DESC, id DESC');
    $statement->execute($params);
    return array_map('az_video_data', $statement->fetchAll());
}

function az_update_video(array $body, int $userId): array
{
    $id = az_text($body['id'] ?? '', 32, 'Finished video ID');
    $row = az_video_row($id, $userId);
    az_revision_check($body, $row, 'This finished video');
    $title = az_text($body['title'] ?? $row['title'], 200, 'Finished video title');
    if ($title === '') {
        throw new AzHttpError(400, 'Enter a finished video title.', 'invalid_field');
    }
    $statement = az_db()->prepare('UPDATE finished_videos SET title = ?, description = ?, status = ?, published_url = ?, participant_name = ?, updated_at = ?, revision = revision + 1 WHERE id = ? AND owner_id = ?');
    $statement->execute([$title, az_text($body['description'] ?? $row['description'], 16000, 'Video description'),
        az_enum($body['status'] ?? $row['status'], ['draft', 'review', 'ready', 'published'], 'Video status'),
        az_publication_url($body['publishedUrl'] ?? $row['published_url']), az_text($body['participantName'] ?? $row['participant_name'], 200, 'Public contributor credit'), az_now(), $id, $userId]);
    return az_video_data(az_video_row($id, $userId));
}

function az_archive_video(array $body, int $userId): array
{
    $id = az_text($body['id'] ?? '', 32, 'Finished video ID');
    $row = az_video_row($id, $userId);
    $archived = az_bool($body['archived'] ?? true, 'Archived');
    $statement = az_db()->prepare('UPDATE finished_videos SET archived_at = ?, updated_at = ?, revision = revision + 1 WHERE id = ? AND owner_id = ?');
    $statement->execute([$archived ? ($row['archived_at'] ?: az_now()) : null, az_now(), $id, $userId]);
    if ($archived) {
        $statement = az_db()->prepare('UPDATE finished_videos SET fan_published_at = NULL, fan_updated_at = NULL, fan_title = NULL, fan_description = NULL, fan_credit = NULL WHERE id = ? AND owner_id = ?');
        $statement->execute([$id, $userId]);
    }
    return az_video_data(az_video_row($id, $userId));
}

function az_delete_video(string $id, int $userId): array
{
    $row = az_video_row($id, $userId);
    az_remove_recordings(az_video_media_files($row), [], static function (PDO $db) use ($id, $userId): void {
        $statement = $db->prepare('DELETE FROM uploads WHERE video_id = ? AND owner_id = ?');
        $statement->execute([$id, $userId]);
        $statement = $db->prepare('DELETE FROM finished_videos WHERE id = ? AND owner_id = ?');
        $statement->execute([$id, $userId]);
    });
    return ['id' => $id, 'deleted' => true];
}

function az_production_get(string $action, array $query, int $userId): mixed
{
    return match ($action) {
        'profiles' => az_profiles($userId),
        'profile' => az_profile_data(az_profile_row(az_text($query['id'] ?? '', 32, 'Model profile ID'), $userId), true),
        'questionnaires' => az_questionnaires($userId),
        'questionnaire' => az_questionnaire_data(az_questionnaire_row(az_text($query['id'] ?? '', 32, 'Questionnaire ID'), $userId)),
        'videos' => az_videos($userId, $query),
        'video' => az_video_data(az_video_row(az_text($query['id'] ?? '', 32, 'Finished video ID'), $userId)),
        default => null,
    };
}

function az_production_post(string $action, array $body, int $userId): mixed
{
    return match ($action) {
        'saveProfile', 'archiveProfile' => az_save_profile($body, $userId),
        'deleteProfile' => az_delete_profile(az_text($body['id'] ?? '', 32, 'Model profile ID'), $userId),
        'uploadProfilePhoto' => az_profile_photo($body, $userId),
        'saveQuestionnaire', 'archiveQuestionnaire' => az_save_questionnaire($body, $userId),
        'deleteQuestionnaire' => az_delete_questionnaire(az_text($body['id'] ?? '', 32, 'Questionnaire ID'), $userId),
        'updateTakeReview' => az_update_take_review($body, $userId),
        'addTakeNote' => az_add_take_note($body, $userId),
        'deleteTakeNote' => az_delete_take_note($body, $userId),
        'updateVideo' => az_update_video($body, $userId),
        'publishVideo' => az_set_video_publication($body, $userId),
        'uploadVideoCover' => az_upload_video_cover($body, $userId),
        'removeVideoCover' => az_remove_video_cover($body, $userId),
        'archiveVideo' => az_archive_video($body, $userId),
        'deleteVideo' => az_delete_video(az_text($body['id'] ?? '', 32, 'Finished video ID'), $userId),
        default => null,
    };
}
