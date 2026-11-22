<?php
declare(strict_types=1);

/** Work media always uses community.lock before the project owner's file lock. */
function az_work_media_owner_lock(int $ownerId)
{
    az_prepare_storage();
    $lock = fopen(AZ_STORAGE . '/uploads/admin-' . $ownerId . '.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new AzHttpError(503, 'Another recording is being saved. Try again shortly.', 'storage_busy');
    }
    return $lock;
}

function az_work_media_actor(?array $user): array
{
    $fresh = $user ? az_account_user((int)$user['id']) : null;
    if (!$fresh || $fresh['status'] !== 'active') {
        throw new AzHttpError(403, 'This account is no longer active.', 'account_inactive');
    }
    return $fresh;
}

function az_work_client_token(mixed $value): string
{
    $token = az_text($value, 160, 'Recording recovery token');
    if (preg_match('/\A[a-zA-Z0-9._:-]{16,160}\z/D', $token) !== 1) {
        throw new AzHttpError(400, 'A valid recording recovery token is required.', 'invalid_recovery_token');
    }
    return $token;
}

function az_work_upload_data(array $row): array
{
    // No administrator storage URL, owner, interview, or source-edit metadata.
    return ['uploadId' => $row['id'], 'assignmentId' => $row['assignment_id'],
        'chunkBytes' => (int)$row['chunk_bytes'], 'nextIndex' => (int)$row['next_index'],
        'receivedBytes' => (int)$row['received_bytes'], 'totalBytes' => (int)$row['total_bytes'],
        'status' => $row['status'], 'submissionId' => $row['submission_id'] ?: null];
}

function az_work_upload_raw(string $id): array
{
    if (!az_valid_id($id)) { throw new AzHttpError(404, 'This recording upload was not found.', 'upload_not_found'); }
    $q = az_db()->prepare('SELECT u.*, w.assignment_id, w.contributor_id, w.uploaded_by, w.assignment_revision, w.shot_revision, w.client_token, w.submission_id FROM uploads u JOIN work_uploads w ON w.upload_id = u.id WHERE u.id = ?');
    $q->execute([$id]);
    $row = $q->fetch();
    if (!$row) { throw new AzHttpError(404, 'This recording upload was not found.', 'upload_not_found'); }
    return $row;
}

function az_work_upload_access(string $id, array $user, ?string $assignmentId = null): array
{
    $row = az_work_upload_raw($id);
    if ((int)$row['uploaded_by'] !== (int)$user['id'] || ($assignmentId !== null && $assignmentId !== $row['assignment_id'])) {
        throw new AzHttpError(404, 'This recording upload was not found.', 'upload_not_found');
    }
    $assignment = az_work_assignment_access($row['assignment_id'], $user, $row['status'] !== 'completed');
    $contributor=az_account_user((int)$assignment['user_id']);
    $host=$contributor && az_has_role($contributor,'admin') && (int)$contributor['id']===(int)$assignment['owner_id'];
    if (!$contributor || $contributor['status']!=='active' || (!$host && !az_work_eligible($assignment,$contributor))) {
        throw new AzHttpError(409,'The assigned contributor is no longer eligible for this upload.','contributor_unavailable');
    }
    if (in_array($assignment['status'], ['cancelled','declined'], true) || $assignment['project_status'] === 'archived' || $assignment['shot_status'] === 'archived') {
        throw new AzHttpError(409, 'This assignment is no longer available for uploads.', 'work_closed');
    }
    if ((int)$assignment['user_id'] !== (int)$row['contributor_id'] || (int)$assignment['owner_id'] !== (int)$row['owner_id'] || $assignment['interview_id'] !== $row['interview_id'] || $assignment['shot_id'] !== $row['question_id']) {
        throw new AzHttpError(409, 'This upload no longer belongs to the same assignment.', 'assignment_changed');
    }
    if ($row['status'] !== 'completed' && ((int)$assignment['revision'] !== (int)$row['assignment_revision'] || (int)$assignment['shot_revision'] !== (int)$row['shot_revision'])) {
        throw new AzHttpError(409, 'The assignment or shot changed. Keep your local recording, discard this upload, and start it again after reviewing the new brief.', 'assignment_changed');
    }
    return [$row, $assignment];
}

function az_work_begin_upload(array $body, array $user): array
{
    $assignmentId = az_text($body['assignmentId'] ?? '', 32, 'Assignment ID');
    $token = az_work_client_token($body['clientToken'] ?? null);
    $q = az_db()->prepare('SELECT upload_id, assignment_id FROM work_uploads WHERE uploaded_by = ? AND client_token = ?');
    $q->execute([(int)$user['id'], $token]);
    if ($previous = $q->fetch()) {
        [$row] = az_work_upload_access($previous['upload_id'], $user, $assignmentId);
        if ((int)$row['total_bytes'] !== (int)($body['totalBytes'] ?? 0)) {
            throw new AzHttpError(409, 'This recovery token belongs to a different recording.', 'upload_conflict');
        }
        return az_work_upload_data($row);
    }
    $assignment = az_work_assignment_access($assignmentId, $user, true);
    $safe = ['interviewId' => $assignment['interview_id'], 'purpose' => 'take',
        'questionId' => $assignment['shot_id'], 'questionText' => $assignment['shot_title'],
        'label' => az_text($body['label'] ?? '', 200, 'Take label') ?: $assignment['shot_title'],
        'fileName' => $body['fileName'] ?? 'production-take.webm', 'mimeType' => $body['mimeType'] ?? '',
        'totalBytes' => $body['totalBytes'] ?? 0, 'durationMs' => $body['durationMs'] ?? 0,
        'answerStartMs' => null, 'isPrivate' => false];
    $upload = az_begin_upload($safe, (int)$assignment['owner_id'], ['assignment' => $assignment, 'actor' => $user, 'clientToken' => $token]);
    return az_work_upload_data(az_work_upload_raw($upload['uploadId']));
}

function az_work_storage_token(array $body, ?array $context): ?string
{
    if (isset($context['clientToken'])) { return az_work_client_token($context['clientToken']); }
    $value=$body['clientToken']??$body['id']??null;
    return $value===null ? null : 'host:'.hash('sha256',az_work_client_token($value));
}

/** Recover an administrator recording if its begin receipt was lost. */
function az_work_retry_storage_upload(array $body, int $ownerId, array $interview, ?array $context): ?array
{
    if (empty($interview['project_id']) || ($body['purpose']??'take')!=='take') { return null; }
    $token=az_work_storage_token($body,$context);
    if($token===null)return null;
    $actor=az_work_media_actor($context['actor']??az_account_user($ownerId));
    $q=az_db()->prepare('SELECT upload_id FROM work_uploads WHERE uploaded_by=? AND client_token=?');$q->execute([(int)$actor['id'],$token]);
    $id=$q->fetchColumn();if(!$id)return null;
    [$row]=az_work_upload_access($id,$actor,$context['assignment']['id']??null);
    if($row['interview_id']!==$interview['id']||$row['question_id']!==($body['questionId']??'')||(int)$row['total_bytes']!==(int)($body['totalBytes']??$body['sizeBytes']??0)) {
        throw new AzHttpError(409,'This recording recovery token belongs to a different shot or file.','upload_conflict');
    }
    return az_upload_data($row);
}

/** Called only by storage code with a server-created context, never client data. */
function az_work_prepare_storage_upload(array &$body, int $ownerId, array $interview, ?array $context): ?array
{
    if (empty($interview['project_id'])) { return null; }
    $actor = az_work_media_actor($context['actor'] ?? az_account_user($ownerId));
    if (($body['purpose'] ?? 'take') === 'finished') {
        az_work_validate_export($interview, $body['sourceEdit'] ?? [], $actor);
        return null;
    }
    $assignment = isset($context['assignment'])
        ? az_work_assignment_access($context['assignment']['id'], $actor, true)
        : az_work_host_assignment($interview['id'], az_text($body['questionId'] ?? '', 32, 'Shot ID'), $actor);
    if ((int)$assignment['owner_id'] !== $ownerId || $assignment['interview_id'] !== $interview['id']) {
        throw new AzHttpError(403, 'This shot belongs to a different production.', 'assignment_scope');
    }
    $body['questionId'] = $assignment['shot_id'];
    $body['questionText'] = $assignment['shot_title'];
    $body['isPrivate'] = false;
    $token = az_work_storage_token($body,$context) ?? ('host:' . az_id());
    return ['assignment' => $assignment, 'actor' => $actor, 'clientToken' => $token];
}

function az_work_link_storage_upload(string $uploadId, array $context): void
{
    $a = $context['assignment'];
    az_db()->prepare('INSERT INTO work_uploads (upload_id,assignment_id,contributor_id,uploaded_by,assignment_revision,shot_revision,client_token,submission_id,created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$uploadId, $a['id'], (int)$a['user_id'], (int)$context['actor']['id'], (int)$a['revision'], (int)$a['shot_revision'], $context['clientToken'], null, az_now()]);
}

function az_work_check_storage_upload(array $upload, int $ownerId): void
{
    $interview = az_interview_row($upload['interview_id'], $ownerId);
    if (empty($interview['project_id'])) { return; }
    if (($upload['purpose'] ?? 'take') === 'finished') {
        $meta = json_decode($upload['meta_json'] ?: '{}', true, 64, JSON_THROW_ON_ERROR);
        az_work_validate_export($interview, $meta['sourceEdit'] ?? [], az_work_media_actor(az_account_user($ownerId)));
        return;
    }
    $row = az_work_upload_raw($upload['id']);
    $actor = az_work_media_actor(az_account_user((int)$row['uploaded_by']));
    az_work_upload_access($upload['id'], $actor, $row['assignment_id']);
}

function az_work_after_storage_take(array $upload, array $take, int $ownerId): void
{
    $interview = az_interview_row($upload['interview_id'], $ownerId);
    if (empty($interview['project_id']) || ($upload['purpose'] ?? 'take') !== 'take') { return; }
    $row = az_work_upload_raw($upload['id']);
    $actor = az_work_media_actor(az_account_user((int)$row['uploaded_by']));
    $assignment = az_work_assignment_access($row['assignment_id'], $actor, false);
    $submission = az_work_register_take($assignment, $take, $actor, $row['client_token']);
    az_db()->prepare('UPDATE work_uploads SET submission_id = ? WHERE upload_id = ?')->execute([$submission['id'], $upload['id']]);
}

function az_work_finish_upload(string $id, array $user, string $assignmentId): array
{
    [$row] = az_work_upload_access($id, $user, $assignmentId);
    $take = az_finish_upload($id, (int)$row['owner_id']);
    // Also runs for a lost finish receipt or a process interruption after moving
    // the take but before linking its submission. Both identities are unique.
    az_work_after_storage_take(az_upload_row($id, (int)$row['owner_id']), $take, (int)$row['owner_id']);
    $updated = az_work_upload_raw($id);
    $submission = az_work_submission_row($updated['submission_id'], $user);
    return ['saved' => true, 'uploadId' => $id, 'submissionId' => $submission['id'], 'takeId' => $submission['take_id'], 'assignmentId' => $row['assignment_id']];
}

function az_work_eligible_take_rows(array $project): array
{
    $q = az_db()->prepare("SELECT t.*, s.id AS work_submission_id, 'production' AS source_kind FROM takes t JOIN shot_submissions s ON s.take_id = t.id JOIN shot_assignments a ON a.id = s.assignment_id JOIN production_shots sh ON sh.id = a.shot_id WHERE sh.project_id = ? AND s.status = 'accepted' AND a.status NOT IN ('cancelled','declined') AND t.is_private = 0 AND t.review_status = 'shortlisted' ORDER BY sh.sort_order, t.created_at, t.id");
    $q->execute([$project['id']]);
    $rows = $q->fetchAll();
    if ($project['kind'] === 'mixed') {
        $q = az_db()->prepare("SELECT t.*, NULL AS work_submission_id, 'interview' AS source_kind FROM takes t JOIN work_source_takes x ON x.take_id = t.id JOIN takes source ON source.id = x.source_take_id JOIN interviews i ON i.id = source.interview_id WHERE x.project_id = ? AND source.is_private = 0 AND source.review_status = 'shortlisted' AND i.status = 'active' AND t.is_private = 0 AND t.review_status = 'shortlisted' ORDER BY x.created_at, t.id");
        $q->execute([$project['id']]);
        $rows = array_merge($rows, $q->fetchAll());
    }
    return $rows;
}

function az_work_prepare_editor(array $data, array $user): array
{
    $q = az_db()->prepare('SELECT * FROM production_projects WHERE interview_id = ? AND owner_id = ?');
    $q->execute([$data['id'], (int)$user['id']]);
    $project = $q->fetch();
    if (!$project) { return $data; }
    $data['state'] = json_decode(json_encode($data['state'] ?? new stdClass(), JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR) ?: [];
    $rows = az_work_eligible_take_rows($project);
    $eligible = array_column($rows, 'id');
    $edit = $data['state']['edit'] ?? [];
    $removed = [];
    foreach (($edit['order'] ?? []) as $id) {
        if (($edit['segments'][$id]['included'] ?? false) && !in_array($id, $eligible, true)) { $removed[] = $id; }
    }
    $data['takes'] = array_map(static function (array $row): array {
        return az_take_data($row) + ['sourceKind' => $row['source_kind'], 'submissionId' => $row['work_submission_id'], 'editorEligible' => true];
    }, $rows);
    $data['production'] = ['id' => $project['id'], 'title' => $project['title'], 'kind' => $project['kind'], 'status' => $project['status'], 'acceptedCount' => count($rows), 'unavailableSelectedIds' => $removed];
    $q = az_db()->prepare("SELECT id,title,brief FROM production_shots WHERE project_id = ? AND status = 'active' ORDER BY sort_order,created_at,id");
    $q->execute([$project['id']]);
    $questions=[];
    foreach ($q->fetchAll() as $index=>$shot) {
        $questions[]=['id'=>$shot['id'],'number'=>$index+1,'category'=>'Production shots','text'=>$shot['title'],'brief'=>$shot['brief'],'kind'=>'open','private'=>false,'options'=>[]];
    }
    $data['questionnaireSnapshot']=['id'=>'production-'.$project['id'],'title'=>'Production shots','questions'=>$questions];
    if (!isset($data['state']['edit']['settings']['cardMode'])) {
        $data['state']['edit']['settings']['cardMode'] = match ($project['kind']) { 'production' => 'none', 'mixed' => 'perClip', default => 'all' };
    }
    return $data;
}

function az_work_validate_export(array $interview, mixed $sourceEdit, array $user): void
{
    $project = az_work_project_row($interview['project_id'], $user, true);
    if ($project['status'] === 'archived') { throw new AzHttpError(409, 'Restore this production before saving another edit.', 'project_archived'); }
    if ($sourceEdit instanceof stdClass) { $sourceEdit = (array)$sourceEdit; }
    $ids = is_array($sourceEdit) ? ($sourceEdit['selectedTakeIds'] ?? null) : null;
    if (!is_array($ids) || !$ids || count($ids) > 1000 || array_filter($ids, static fn(mixed $id): bool => !is_string($id)) || count(array_unique($ids, SORT_STRING)) !== count($ids)) {
        throw new AzHttpError(400, 'This production export needs its selected take list.', 'export_sources_required');
    }
    $eligible = array_column(az_work_eligible_take_rows($project), 'id');
    foreach ($ids as $id) {
        if (!is_string($id) || !in_array($id, $eligible, true)) {
            throw new AzHttpError(409, 'One or more source takes are no longer approved. Review the production and render a new edit.', 'export_source_changed');
        }
    }
    if (function_exists('az_edit_validate_export')) { az_edit_validate_export($interview, $sourceEdit, $user); }
}

function az_work_upload_reference(array $body, array $user): array
{
    $project = az_work_project_row(az_text($body['projectId'] ?? '', 32, 'Project ID'), $user, true);
    $shotId = az_text($body['shotId'] ?? '', 32, 'Shot ID');
    if ($shotId !== '') {
        $shot = az_work_shot_row($shotId, $user, true);
        if ($shot['project_id'] !== $project['id']) { throw new AzHttpError(404, 'This shot was not found in the production.', 'not_found'); }
    }
    $file = az_uploaded_file('file');
    $size = (int)filesize($file['tmp_name']);
    if ($size < 1 || $size > 20 * 1024 * 1024) { throw new AzHttpError(413, 'Reference files must be 20 MB or smaller.', 'reference_size'); }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
        throw new AzHttpError(415, 'Choose a JPG, PNG, WebP, or PDF reference.', 'reference_type');
    }
    $id = az_id(); $storageName = $id . '.bin';
    $title = az_text($body['title'] ?? '', 200, 'Reference title') ?: az_text(pathinfo($file['name'], PATHINFO_FILENAME), 200, 'Reference title');
    if (!move_uploaded_file($file['tmp_name'], AZ_STORAGE . '/media/' . $storageName)) { throw new AzHttpError(507, 'The reference could not be saved.', 'storage_unavailable'); }
    @chmod(AZ_STORAGE . '/media/' . $storageName, 0640);
    try {
        az_db()->prepare('INSERT INTO work_references (id,project_id,shot_id,title,storage_name,mime_type,file_name,size_bytes,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$id,$project['id'],$shotId ?: null,$title,$storageName,$mime,az_text($file['name'],250,'File name'),$size,(int)$user['id'],az_now()]);
        az_work_event($project['id'], (int)$user['id'], 'reference_added', 'Added reference: ' . $title);
    } catch (Throwable $e) { @unlink(AZ_STORAGE . '/media/' . $storageName); throw $e; }
    return ['id'=>$id,'projectId'=>$project['id'],'shotId'=>$shotId ?: null,'title'=>$title,'mimeType'=>$mime,'sizeBytes'=>$size,'mediaUrl'=>az_url('work-media.php?type=reference&id='.$id)];
}

function az_work_source_interviews(string $projectId, array $user): array
{
    $project = az_work_project_row($projectId, $user, true);
    if ($project['kind'] !== 'mixed') { throw new AzHttpError(409, 'Interview imports belong in a mixed production.', 'project_kind'); }
    $q = az_db()->prepare("SELECT t.*, i.title AS interview_title FROM takes t JOIN interviews i ON i.id = t.interview_id WHERE i.owner_id = ? AND i.project_id IS NULL AND i.status = 'active' AND t.is_private = 0 AND t.review_status = 'shortlisted' ORDER BY i.updated_at DESC, t.created_at");
    $q->execute([(int)$user['id']]); $groups=[];
    foreach ($q->fetchAll() as $row) {
        $id=$row['interview_id'];
        $groups[$id] ??= ['id'=>$id,'title'=>$row['interview_title'],'takes'=>[]];
        $groups[$id]['takes'][]=['id'=>$row['id'],'label'=>$row['label'],'questionText'=>$row['question_text'],'durationMs'=>(int)$row['duration_ms']];
    }
    return array_values($groups);
}

function az_work_import_source(array $body, array $user): array
{
    $project=az_work_project_row(az_text($body['projectId'] ?? '',32,'Project ID'),$user,true);
    if ($project['kind']!=='mixed' || $project['status']==='archived') { throw new AzHttpError(409,'Choose an active mixed production for this source.','project_kind'); }
    $source=az_take_row(az_text($body['takeId'] ?? '',32,'Take ID'),(int)$user['id']);
    $interview=az_interview_row($source['interview_id'],(int)$user['id']);
    if (!empty($interview['project_id']) || $interview['status']!=='active' || (int)$source['is_private'] || $source['review_status']!=='shortlisted') {
        throw new AzHttpError(409,'Only shortlisted, nonprivate interview takes can be imported.','source_not_approved');
    }
    $q=az_db()->prepare('SELECT take_id FROM work_source_takes WHERE project_id = ? AND source_take_id = ?'); $q->execute([$project['id'],$source['id']]);
    if ($id=$q->fetchColumn()) { return ['takeId'=>$id,'imported'=>false]; }
    $id=az_id(); $destination=AZ_STORAGE.'/media/'.$id.'.bin';
    if (!copy(az_media_path($source),$destination)) { throw new AzHttpError(507,'This source could not be copied.','storage_unavailable'); }
    @chmod($destination,0640);
    $row=$source;
    foreach (['id','interview_id','storage_name','created_at'] as $field) { unset($row[$field]); }
    $row['id']=$id; $row['interview_id']=$project['interview_id']; $row['storage_name']=$id.'.bin'; $row['created_at']=az_now();
    $db=az_db();
    try {
        $db->beginTransaction();
        $columns=['id','interview_id','owner_id','question_id','question_text','label','mime_type','file_name','storage_name','size_bytes','duration_ms','answer_start_ms','is_private','created_at','is_favorite','review_status'];
        $db->prepare('INSERT INTO takes ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_map(static fn(string $column): mixed => $row[$column],$columns));
        $notes=$db->prepare('SELECT * FROM take_notes WHERE take_id=? AND owner_id=?');$notes->execute([$source['id'],(int)$user['id']]);
        foreach($notes->fetchAll() as $note){$note['id']=az_id();$note['take_id']=$id;az_insert_row('take_notes',$note);}
        $db->prepare('INSERT INTO work_source_takes (take_id,source_take_id,source_interview_id,project_id,created_by,created_at) VALUES (?,?,?,?,?,?)')->execute([$id,$source['id'],$source['interview_id'],$project['id'],(int)$user['id'],az_now()]);
        $sourceState=json_decode($interview['state_json']?:'{}',true,64,JSON_THROW_ON_ERROR);
        $previous=$sourceState['edit']['segments'][$source['id']]??[];
        $target=az_interview_row($project['interview_id'],(int)$user['id']);
        $targetState=json_decode($target['state_json']?:'{}',true,64,JSON_THROW_ON_ERROR);
        $targetState['edit']??=[];$targetState['edit']['order']??=[];$targetState['edit']['segments']??=[];$targetState['edit']['settings']??=[];
        $targetState['edit']['settings']['cardMode']??='perClip';
        $targetState['edit']['order'][]=$id;
        $duration=(int)$source['duration_ms']/1000;
        $start=max(0,min($duration,(float)($previous['trimStart']??((int)$source['answer_start_ms']/1000))));
        $end=max($start,min($duration,(float)($previous['trimEnd']??$duration)));
        if($end-$start<.05){$start=0;$end=$duration;}
        $targetState['edit']['segments'][$id]=['included'=>true,'title'=>(string)($previous['title']??($source['question_text']?:$source['label'])),'trimStart'=>$start,'trimEnd'=>$end,'showCard'=>(bool)($previous['showCard']??true)];
        $db->prepare('UPDATE interviews SET state_json=? WHERE id=? AND owner_id=?')->execute([json_encode($targetState,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$project['interview_id'],(int)$user['id']]);
        az_touch_interview($project['interview_id'],(int)$user['id']);
        az_work_event($project['id'],(int)$user['id'],'source_imported','Imported a shortlisted interview take.');
        $db->commit();
    } catch (Throwable $e) { if($db->inTransaction())$db->rollBack(); @unlink($destination); throw $e; }
    return ['takeId'=>$id,'imported'=>true];
}

/** Called under community/owner locks before deleting or withdrawing a source. */
function az_work_withdraw_source(string $takeId): void
{
    if (!function_exists('az_unpublish_interview')) { return; }
    $q=az_db()->prepare('SELECT DISTINCT p.interview_id FROM production_projects p JOIN work_source_takes x ON x.project_id=p.id WHERE x.source_take_id=? OR x.take_id=?');
    $q->execute([$takeId,$takeId]); $interviews=$q->fetchAll(PDO::FETCH_COLUMN);
    $q=az_db()->prepare('SELECT DISTINCT p.interview_id FROM production_projects p JOIN production_shots sh ON sh.project_id=p.id JOIN shot_assignments a ON a.shot_id=sh.id JOIN shot_submissions s ON s.assignment_id=a.id WHERE s.take_id=?');
    $q->execute([$takeId]);
    foreach(array_unique(array_merge($interviews,$q->fetchAll(PDO::FETCH_COLUMN))) as $id) { az_unpublish_interview($id); }
}
