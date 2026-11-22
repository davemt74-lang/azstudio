<?php
declare(strict_types=1);

/** Named editable production cuts. Call mutations under community.lock + a transaction. */
function az_upgrade_edit_versions_schema(PDO $db): void
{
    if (az_schema_version($db) >= 11) { return; }
    az_prepare_storage();
    $lock = fopen(AZ_STORAGE . '/schema-migration.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('Could not lock the editable cuts upgrade.'); }
    try {
        if (az_schema_version($db) >= 11) { return; }
        if (az_schema_version($db) < 10) { throw new RuntimeException('The Director must be installed before editable cuts.'); }
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $uid = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $long = $mysql ? 'LONGTEXT' : 'TEXT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $db->exec("CREATE TABLE IF NOT EXISTS production_edits (id CHAR(32) PRIMARY KEY, project_id CHAR(32) NOT NULL, owner_id $uid NOT NULL, title VARCHAR(200) NOT NULL, target_duration_seconds INTEGER NOT NULL DEFAULT 0, status VARCHAR(16) NOT NULL DEFAULT 'active', revision INTEGER NOT NULL DEFAULT 1, edit_json $long NOT NULL, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, FOREIGN KEY (project_id) REFERENCES production_projects(id) ON DELETE CASCADE, FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS production_edit_history (timeline_id CHAR(32) NOT NULL, revision INTEGER NOT NULL, snapshot_json $long NOT NULL, reason VARCHAR(200) NOT NULL, created_at VARCHAR(32) NOT NULL, PRIMARY KEY (timeline_id,revision), FOREIGN KEY (timeline_id) REFERENCES production_edits(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS production_edit_requests (owner_id $uid NOT NULL, request_token VARCHAR(160) NOT NULL, action VARCHAR(32) NOT NULL, fingerprint CHAR(64) NOT NULL, result_json $long NOT NULL, created_at VARCHAR(32) NOT NULL, PRIMARY KEY (owner_id,request_token), FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        $db->exec('UPDATE studio_schema SET version=11 WHERE id=1');
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function az_edit_json(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function az_edit_array(mixed $value, string $label): array
{
    if ($value instanceof stdClass) { $value = (array)$value; }
    if (!is_array($value)) { throw new AzHttpError(400, $label . ' must be an object.', 'invalid_edit'); }
    return $value;
}

function az_edit_number(mixed $value, float $min, float $max, string $label): float
{
    if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < $min || $value > $max) {
        throw new AzHttpError(400, $label . ' is outside the supported range.', 'invalid_edit');
    }
    return round((float)$value, 3);
}

function az_edit_project(string $id, array $user, bool $writing = false): array
{
    if (!az_has_role($user, 'admin')) { throw new AzHttpError(403, 'Producer access is required for editable cuts.', 'role_required'); }
    $project = az_work_project_row($id, $user, true);
    if ($writing && $project['status'] === 'archived') { throw new AzHttpError(409, 'Restore this production before editing its cuts.', 'project_archived'); }
    return $project;
}

function az_edit_row(string $id, array $user, bool $writing = false): array
{
    $q = az_db()->prepare("SELECT e.*,p.interview_id FROM production_edits e JOIN production_projects p ON p.id=e.project_id WHERE e.id=? AND e.owner_id=? AND e.status<>'deleted'");
    $q->execute([$id, $user['id']]); $row = $q->fetch();
    if (!$row) { throw new AzHttpError(404, 'This editable cut was not found.', 'timeline_not_found'); }
    az_edit_project($row['project_id'], $user, $writing);
    return $row;
}

/** One ordered appearance per take, with independent per-timeline ranges/settings. */
function az_edit_normalize(mixed $input, array $project, bool $validateSources = true): array
{
    $edit = az_edit_array($input, 'The edit');
    $order = $edit['order'] ?? [];
    $rawSegments = az_edit_array($edit['segments'] ?? [], 'Clip selections');
    if (!is_array($order) || !array_is_list($order) || count($order) > 500 || array_filter($order, static fn($id) => !is_string($id) || !az_valid_id($id)) || count(array_unique($order)) !== count($order)) {
        throw new AzHttpError(400, 'Use at most 500 distinct source takes in the edit.', 'invalid_edit');
    }
    $sources = $validateSources ? array_column(az_work_eligible_take_rows($project), null, 'id') : [];
    $segments = [];
    foreach ($order as $id) {
        $s = az_edit_array($rawSegments[$id] ?? null, 'Each ordered clip');
        $included = az_bool($s['included'] ?? true, 'Clip included');
        if ($validateSources && !isset($sources[$id])) { throw new AzHttpError(409, 'A source take is no longer approved. Refresh the editor and remove unavailable clips before saving.', 'edit_source_changed'); }
        $max = $validateSources ? (int)$sources[$id]['duration_ms'] / 1000 : 86400;
        $start = az_edit_number($s['trimStart'] ?? 0, 0, $max, 'Clip start');
        $end = az_edit_number($s['trimEnd'] ?? $max, 0, $max, 'Clip end');
        if ($end - $start < 0.099) { throw new AzHttpError(400, 'Each clip needs at least 0.1 seconds within its source recording.', 'invalid_edit_range'); }
        $segments[$id] = ['included' => $included, 'title' => az_text($s['title'] ?? '', 1000, 'Clip title'), 'trimStart' => $start, 'trimEnd' => $end, 'showCard' => az_bool($s['showCard'] ?? false, 'Show title card')];
    }
    $raw = az_edit_array($edit['settings'] ?? [], 'Edit settings');
    $settings = [
        'cardMode' => az_enum($raw['cardMode'] ?? ($project['kind'] === 'production' ? 'none' : ($project['kind'] === 'mixed' ? 'perClip' : 'all')), ['all', 'none', 'perClip'], 'Title cards'),
        'cardDuration' => az_edit_number($raw['cardDuration'] ?? 3, .5, 15, 'Title card duration'),
        'fadeDuration' => az_edit_number($raw['fadeDuration'] ?? .45, 0, 3, 'Fade duration'),
        'aspect' => az_enum($raw['aspect'] ?? '16:9', ['16:9', '9:16', '1:1'], 'Frame'),
        'resolution' => az_integer($raw['resolution'] ?? 720, 720, 1080, 'Resolution'),
        'fit' => az_enum($raw['fit'] ?? 'contain', ['contain', 'cover'], 'Video fit'),
        'brand' => az_text($raw['brand'] ?? 'AZHOTTIE', 100, 'Brand label'),
        'modelName' => az_text($raw['modelName'] ?? '', 200, 'Participant label'),
    ];
    if (!in_array($settings['resolution'], [720, 1080], true)) { throw new AzHttpError(400, 'Choose 720p or 1080p.', 'invalid_edit'); }
    return ['order' => $order, 'segments' => $segments ?: new stdClass(), 'settings' => $settings];
}

function az_edit_selected(array $edit): array
{
    $segments = (array)$edit['segments'];
    return array_values(array_filter($edit['order'], static fn(string $id): bool => (bool)($segments[$id]['included'] ?? false)));
}

function az_edit_snapshot(array $row, ?int $revision = null): array
{
    $revision ??= (int)$row['revision'];
    $q = az_db()->prepare('SELECT snapshot_json,reason,created_at FROM production_edit_history WHERE timeline_id=? AND revision=?');
    $q->execute([$row['id'], $revision]); $history = $q->fetch();
    if (!$history) { throw new AzHttpError(404, 'This editable revision was not found.', 'timeline_revision_not_found'); }
    return json_decode($history['snapshot_json'], true, 64, JSON_THROW_ON_ERROR) + ['revision' => $revision, 'reason' => $history['reason'], 'savedAt' => $history['created_at']];
}

function az_edit_data(array $row, array $user, ?int $revision = null, bool $includeHistory = true): array
{
    $snapshot = az_edit_snapshot($row, $revision);
    $project = az_edit_project($row['project_id'], $user);
    $eligible = array_column(az_work_eligible_take_rows($project), 'id');
    $selected = az_edit_selected($snapshot['edit']);
    $unavailable = array_values(array_diff($selected, $eligible));
    $history = [];
    if ($includeHistory) {
        $q = az_db()->prepare('SELECT revision,snapshot_json,reason,created_at FROM production_edit_history WHERE timeline_id=? ORDER BY revision DESC LIMIT 100');
        $q->execute([$row['id']]);
        foreach ($q->fetchAll() as $h) {
            $s = json_decode($h['snapshot_json'], true, 64, JSON_THROW_ON_ERROR);
            $history[] = ['revision' => (int)$h['revision'], 'title' => $s['title'], 'reason' => $h['reason'], 'savedAt' => $h['created_at']];
        }
    }
    $q = az_db()->prepare('SELECT id,title,source_edit_json,created_at,archived_at FROM finished_videos WHERE interview_id=? AND owner_id=? ORDER BY created_at DESC,id DESC');
    $q->execute([$project['interview_id'], $user['id']]); $renders = [];
    foreach ($q->fetchAll() as $video) {
        $source = json_decode($video['source_edit_json'], true) ?: [];
        if (($source['timelineId'] ?? null) === $row['id']) { $renders[] = ['id' => $video['id'], 'title' => $video['title'], 'timelineRevision' => (int)($source['timelineRevision'] ?? 0), 'createdAt' => $video['created_at'], 'archived' => $video['archived_at'] !== null, 'url' => az_url('videos.php?id=' . $video['id'])]; }
    }
    return ['id' => $row['id'], 'projectId' => $row['project_id'], 'interviewId' => $project['interview_id'],
        'title' => $snapshot['title'], 'targetDurationSeconds' => $snapshot['targetDurationSeconds'], 'status' => $row['status'],
        'revision' => $snapshot['revision'], 'latestRevision' => (int)$row['revision'], 'historical' => $snapshot['revision'] !== (int)$row['revision'],
        'edit' => $snapshot['edit'], 'selectedTakeIds' => $selected, 'unavailableSelectedIds' => $unavailable,
        'history' => $history, 'renders' => $renders, 'lastRender' => $renders[0] ?? null,
        'createdAt' => $row['created_at'], 'updatedAt' => $row['updated_at'],
        'editorUrl' => az_url('editor.php?id=' . $project['interview_id'] . '&timelineId=' . $row['id'] . '&timelineRevision=' . $snapshot['revision'])];
}

function az_edit_request(array $body, array $user, string $action): array
{
    $token = az_text($body['requestToken'] ?? '', 160, 'Save recovery token');
    if (!preg_match('/\A[a-zA-Z0-9._:-]{16,160}\z/D', $token)) { throw new AzHttpError(400, 'A save recovery token is required.', 'invalid_request_token'); }
    $fingerprint = hash('sha256', az_edit_json($body));
    $q = az_db()->prepare('SELECT * FROM production_edit_requests WHERE owner_id=? AND request_token=?'); $q->execute([$user['id'], $token]);
    if ($row = $q->fetch()) {
        if ($row['action'] !== $action || !hash_equals($row['fingerprint'], $fingerprint)) { throw new AzHttpError(409, 'This recovery token belongs to a different edit. Reload to check what was saved.', 'request_conflict'); }
        return [$token, $fingerprint, json_decode($row['result_json'], true, 64, JSON_THROW_ON_ERROR)];
    }
    return [$token, $fingerprint, null];
}

function az_edit_receipt(array $request, array $user, string $action, array $result): array
{
    az_db()->prepare('INSERT INTO production_edit_requests (owner_id,request_token,action,fingerprint,result_json,created_at) VALUES (?,?,?,?,?,?)')->execute([$user['id'], $request[0], $action, $request[1], az_edit_json($result), az_now()]);
    return $result;
}

function az_edit_write_history(string $id, string $reason): void
{
    $q = az_db()->prepare('SELECT * FROM production_edits WHERE id=?'); $q->execute([$id]); $row = $q->fetch();
    $snapshot = ['title' => $row['title'], 'targetDurationSeconds' => (int)$row['target_duration_seconds'], 'status' => $row['status'], 'edit' => json_decode($row['edit_json'], true, 64, JSON_THROW_ON_ERROR)];
    az_db()->prepare('INSERT INTO production_edit_history (timeline_id,revision,snapshot_json,reason,created_at) VALUES (?,?,?,?,?)')->execute([$id, $row['revision'], az_edit_json($snapshot), $reason, az_now()]);
}

function az_edit_create(array $body, array $user): array
{
    $project = az_edit_project(az_text($body['projectId'] ?? '', 32, 'Project ID'), $user);
    $request = az_edit_request($body, $user, 'timeline.create');
    if ($request[2] !== null) { return $request[2]; }
    az_edit_project($project['id'], $user, true);
    $directorSnapshot = null;
    if (isset($body['directorFingerprint'])) {
        $fingerprint = az_text($body['directorFingerprint'], 64, 'Director fingerprint');
        $directorSnapshot = az_director_snapshot($project['id'], $user);
        if (!hash_equals($directorSnapshot['fingerprint'], $fingerprint)) { throw new AzHttpError(409, 'The production footage or requirements changed. Refresh the proposal before creating this cut.', 'director_changed'); }
    }
    $title = az_text($body['title'] ?? '', 200, 'Editable cut name');
    if ($title === '') { throw new AzHttpError(400, 'Give this editable cut a name.', 'invalid_title'); }
    $edit = az_edit_normalize($body['edit'] ?? [], $project);
    if ($directorSnapshot !== null) {
        foreach (az_edit_selected($edit) as $takeId) {
            $segment = ((array)$edit['segments'])[$takeId];
            $startMs = (int)round($segment['trimStart'] * 1000); $endMs = (int)round($segment['trimEnd'] * 1000);
            $allowed = false;
            foreach ($directorSnapshot['candidates'] as $candidate) {
                if ($candidate['takeId'] === $takeId && $startMs >= (int)$candidate['startMs'] && $endMs <= (int)$candidate['endMs']) { $allowed = true; break; }
            }
            if (!$allowed) { throw new AzHttpError(409, 'A proposed clip is outside its marked usable sections. Refresh the proposal and choose a section within those boundaries.', 'edit_range_changed'); }
        }
    }
    $target = az_integer($body['targetDurationSeconds'] ?? 0, 0, 3600, 'Target duration');
    $id = az_id(); $now = az_now();
    az_db()->prepare("INSERT INTO production_edits (id,project_id,owner_id,title,target_duration_seconds,status,revision,edit_json,created_at,updated_at) VALUES (?,?,?,?,?,'active',1,?,?,?)")->execute([$id, $project['id'], $user['id'], $title, $target, az_edit_json($edit), $now, $now]);
    az_edit_write_history($id, !empty($body['proposalId']) ? 'Created from assisted proposal' : 'Created editable cut');
    $data = az_edit_data(az_edit_row($id, $user), $user);
    if (!empty($body['proposalId'])) {
        if (!function_exists('az_edit_composer_attach')) { throw new AzHttpError(409, 'The editing assistant is unavailable.', 'proposal_unavailable'); }
        az_edit_composer_attach($body, $user, $data);
    }
    return az_edit_receipt($request, $user, 'timeline.create', $data);
}

function az_edit_save(array $body, array $user, string $action = 'timeline.save'): array
{
    $row = az_edit_row(az_text($body['id'] ?? '', 32, 'Editable cut ID'), $user);
    $request = az_edit_request($body, $user, $action);
    if ($request[2] !== null) { return $request[2]; }
    if (az_integer($body['revision'] ?? -1, 1, PHP_INT_MAX, 'Editable cut revision') !== (int)$row['revision']) { throw new AzHttpError(409, 'This editable cut changed in another tab. Reload it before saving.', 'revision_conflict'); }
    $project = az_edit_project($row['project_id'], $user, true);
    $reason = 'Saved changes';
    if ($action === 'timeline.restore') {
        $old = az_edit_snapshot($row, az_integer($body['restoreRevision'] ?? 0, 1, PHP_INT_MAX, 'Revision to restore'));
        $body['edit'] = $old['edit']; $body['title'] = $old['title']; $body['targetDurationSeconds'] = $old['targetDurationSeconds'];
        $body['status'] = 'active'; $reason = 'Restored revision ' . $old['revision'];
    }
    $title = az_text($body['title'] ?? $row['title'], 200, 'Editable cut name');
    if ($title === '') { throw new AzHttpError(400, 'Give this editable cut a name.', 'invalid_title'); }
    $target = az_integer($body['targetDurationSeconds'] ?? (int)$row['target_duration_seconds'], 0, 3600, 'Target duration');
    $status = az_enum($body['status'] ?? $row['status'], ['active', 'archived'], 'Editable cut status');
    if ($row['status'] === 'archived' && $status === 'archived' && (isset($body['edit']) || isset($body['title']))) { throw new AzHttpError(409, 'Restore this editable cut before changing it.', 'timeline_archived'); }
    // Status-only archival remains possible after a source is withdrawn.
    $edit = isset($body['edit']) ? az_edit_normalize($body['edit'], $project) : json_decode($row['edit_json'], true, 64, JSON_THROW_ON_ERROR);
    if ($status !== $row['status']) { $reason = $status === 'archived' ? 'Archived editable cut' : 'Restored editable cut'; }
    if ($action === 'timeline.restore' || $title !== $row['title'] || $target !== (int)$row['target_duration_seconds'] || $status !== $row['status'] || az_edit_json($edit) !== $row['edit_json']) {
        az_db()->prepare('UPDATE production_edits SET title=?,target_duration_seconds=?,status=?,edit_json=?,revision=revision+1,updated_at=? WHERE id=?')->execute([$title, $target, $status, az_edit_json($edit), az_now(), $row['id']]);
        az_edit_write_history($row['id'], $reason);
    }
    return az_edit_receipt($request, $user, $action, az_edit_data(az_edit_row($row['id'], $user), $user));
}

function az_edit_delete(array $body, array $user): array
{
    $request = az_edit_request($body, $user, 'timeline.delete');
    if ($request[2] !== null) { return $request[2]; }
    $row = az_edit_row(az_text($body['id'] ?? '', 32, 'Editable cut ID'), $user, true);
    if (az_integer($body['revision'] ?? -1, 1, PHP_INT_MAX, 'Editable cut revision') !== (int)$row['revision']) { throw new AzHttpError(409, 'This editable cut changed. Reload it before deleting.', 'revision_conflict'); }
    $data = az_edit_data($row, $user);
    if ($data['renders']) { throw new AzHttpError(409, 'This cut has saved finished videos. Archive it to keep their editable source history.', 'timeline_has_renders'); }
    az_db()->prepare("UPDATE production_edits SET status='deleted',revision=revision+1,updated_at=? WHERE id=?")->execute([az_now(), $row['id']]);
    az_edit_write_history($row['id'], 'Deleted draft');
    return az_edit_receipt($request, $user, 'timeline.delete', ['id' => $row['id'], 'deleted' => true]);
}

/** Called at finished upload start/chunks/finish and again at publication. */
function az_edit_validate_export(array $interview, mixed $sourceEdit, array $user): void
{
    $source = $sourceEdit instanceof stdClass ? (array)$sourceEdit : $sourceEdit;
    if (!is_array($source) || (!isset($source['timelineId']) && !isset($source['timelineRevision']))) { return; }
    $row = az_edit_row(az_text($source['timelineId'] ?? '', 32, 'Editable cut ID'), $user);
    if ($row['interview_id'] !== $interview['id'] || $row['project_id'] !== $interview['project_id']) { throw new AzHttpError(409, 'This editable cut belongs to another production.', 'timeline_scope'); }
    $snapshot = az_edit_snapshot($row, az_integer($source['timelineRevision'] ?? 0, 1, PHP_INT_MAX, 'Editable revision'));
    $project = az_edit_project($row['project_id'], $user, true);
    // Unselected bin footage can be withdrawn after a render without changing
    // that movie. The outer export check revalidates every selected source.
    $edit = az_edit_normalize($source['edit'] ?? [], $project, false);
    $savedEdit = az_edit_normalize($snapshot['edit'], $project, false);
    if (az_edit_json($edit) !== az_edit_json($savedEdit) || ($source['selectedTakeIds'] ?? null) !== az_edit_selected($savedEdit)) {
        throw new AzHttpError(409, 'The rendered footage does not match this saved editable revision. Save the cut and render it again.', 'timeline_export_mismatch');
    }
}

function az_edit_get(string $action, array $query, array $user): array
{
    if ($action === 'timeline') {
        $row = az_edit_row(az_text($query['id'] ?? '', 32, 'Editable cut ID'), $user);
        $revision = isset($query['revision']) ? az_integer($query['revision'], 1, PHP_INT_MAX, 'Editable revision') : null;
        return az_edit_data($row, $user, $revision);
    }
    if ($action === 'list') {
        $project = az_edit_project(az_text($query['projectId'] ?? '', 32, 'Project ID'), $user);
        $q = az_db()->prepare("SELECT e.*,p.interview_id FROM production_edits e JOIN production_projects p ON p.id=e.project_id WHERE e.project_id=? AND e.status<>'deleted' ORDER BY e.updated_at DESC,e.id DESC"); $q->execute([$project['id']]);
        return ['timelines' => array_map(static fn(array $r): array => az_edit_data($r, $user, null, false), $q->fetchAll())];
    }
    throw new AzHttpError(404, 'This editable cut action was not found.', 'unknown_action');
}

function az_edit_post(string $action, array $body, array $user): array
{
    return match ($action) {
        'timeline.create' => az_edit_create($body, $user),
        'timeline.save', 'timeline.restore' => az_edit_save($body, $user, $action),
        'timeline.delete' => az_edit_delete($body, $user),
        default => throw new AzHttpError(404, 'This editable cut action was not found.', 'unknown_action'),
    };
}
