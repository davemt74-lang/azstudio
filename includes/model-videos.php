<?php
declare(strict_types=1);
/** Independent model-created videos. Never sourced from an assignment.
 *  Only an explicit model submission followed by admin approval is public.
 */

function az_upgrade_model_videos_schema(PDO $db): void
{
    if (az_schema_version($db) >= 26 || az_schema_version($db) < 25) return;
    az_prepare_storage();
    $lock = fopen(AZ_STORAGE . '/schema-migration.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Could not lock model video migration.');
    try {
        if (az_schema_version($db) >= 26) return;
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $owner = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $db->exec("CREATE TABLE IF NOT EXISTS model_videos (
            id CHAR(32) PRIMARY KEY, user_id $owner NOT NULL, media_id CHAR(32) NOT NULL,
            title VARCHAR(200) NOT NULL, description TEXT NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'draft', review_note TEXT NOT NULL,
            public_credit VARCHAR(200) NOT NULL DEFAULT '', rights_confirmed INTEGER NOT NULL DEFAULT 0,
            revision INTEGER NOT NULL DEFAULT 1, submitted_at VARCHAR(32) NULL,
            published_at VARCHAR(32) NULL, reviewed_at VARCHAR(32) NULL,
            reviewed_by $owner NULL, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL,
            UNIQUE (user_id, media_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (media_id) REFERENCES personal_media(id) ON DELETE CASCADE)$suffix");
        if ($mysql) {
            $q = $db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
            foreach (['model_video_owner' => 'user_id, updated_at', 'model_video_review' => 'status, submitted_at'] as $name => $columns) {
                $q->execute(['model_videos', $name]);
                if (!(int)$q->fetchColumn()) $db->exec('CREATE INDEX ' . $name . ' ON model_videos (' . $columns . ')');
            }
        } else {
            $db->exec('CREATE INDEX IF NOT EXISTS model_video_owner ON model_videos (user_id, updated_at)');
            $db->exec('CREATE INDEX IF NOT EXISTS model_video_review ON model_videos (status, submitted_at)');
        }
        $db->exec('UPDATE studio_schema SET version=26 WHERE id=1');
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function az_model_video_role(array $user): void
{
    if (!az_has_role($user, 'model') && !az_has_role($user, 'admin'))
        throw new AzHttpError(403, 'My Videos is for model accounts.', 'role_required');
}

function az_model_video_row(string $id, int $owner): array
{
    if (!az_valid_id($id)) throw new AzHttpError(404, 'Model video not found.', 'not_found');
    $q = az_db()->prepare('SELECT p.*, m.duration_ms, m.mime_type, m.size_bytes, m.file_name, m.storage_name, m.cover_storage_name, m.cover_mime_type, m.revision AS source_revision FROM model_videos p JOIN personal_media m ON m.id=p.media_id AND m.user_id=p.user_id WHERE p.id=? AND p.user_id=?');
    $q->execute([$id, $owner]);
    $row = $q->fetch();
    if (!$row) throw new AzHttpError(404, 'Model video not found.', 'not_found');
    return $row;
}

function az_model_video_any_row(string $id): array
{
    if (!az_valid_id($id)) throw new AzHttpError(404, 'Video not found.', 'not_found');
    $q = az_db()->prepare('SELECT p.*, m.duration_ms, m.mime_type, m.size_bytes, m.file_name, m.storage_name, m.cover_storage_name, m.cover_mime_type, m.revision AS source_revision, u.name AS owner_name, u.status AS owner_status FROM model_videos p JOIN personal_media m ON m.id=p.media_id AND m.user_id=p.user_id JOIN users u ON u.id=p.user_id WHERE p.id=?');
    $q->execute([$id]);
    $row = $q->fetch();
    if (!$row) throw new AzHttpError(404, 'Video not found.', 'not_found');
    return $row;
}

function az_model_video_data(array $row): array
{
    $id = $row['id'];
    return [
        'id' => $id, 'mediaId' => $row['media_id'],
        'title' => $row['title'], 'description' => $row['description'],
        'status' => $row['status'], 'reviewNote' => $row['review_note'],
        'revision' => (int)$row['revision'], 'durationMs' => (int)$row['duration_ms'],
        'sizeBytes' => (int)$row['size_bytes'], 'sourceRevision' => (int)$row['source_revision'],
        'submittedAt' => $row['submitted_at'], 'publishedAt' => $row['published_at'],
        'updatedAt' => $row['updated_at'], 'createdAt' => $row['created_at'],
        'scheduledAt' => $row['status']==='scheduled' ? (az_publishing_schedule_lookup($id)['publish_at']??null) : null,
        'publicCredit' => $row['public_credit'],
        'videoUrl' => az_url('model-video-file.php?id=' . $id),
        'coverUrl' => $row['cover_storage_name'] ? az_url('model-video-file.php?id=' . $id . '&type=cover&v=' . $row['source_revision']) : null,
        'watchUrl' => $row['status'] === 'published' ? az_url('watch.php?id=' . $id) : null,
    ];
}

function az_model_video_list(int $owner): array
{
    // One owner-scoped query; no N+1 lookups for galleries with many videos.
    $q = az_db()->prepare('SELECT p.*,m.duration_ms,m.mime_type,m.size_bytes,m.file_name,m.storage_name,m.cover_storage_name,m.cover_mime_type,m.revision AS source_revision
        FROM model_videos p JOIN personal_media m ON m.id=p.media_id AND m.user_id=p.user_id
        WHERE p.user_id=? ORDER BY p.updated_at DESC,p.id DESC LIMIT 200');
    $q->execute([$owner]);
    return array_map('az_model_video_data', $q->fetchAll(PDO::FETCH_ASSOC));
}

function az_model_video_create(array $body, int $owner): array
{
    $mediaId = az_text($body['mediaId'] ?? '', 32, 'Gallery video');
    $media = az_personal_media_row($mediaId, $owner); // Explicit owner boundary: never import assignment footage.
    if (!str_starts_with((string)$media['mime_type'], 'video/')) throw new AzHttpError(415, 'Select a video from your gallery.', 'video_required');
    $title = az_text($body['title'] ?? $media['title'], 200, 'Title');
    if ($title === '') throw new AzHttpError(400, 'Enter a video title.', 'title_required');
    $description = az_text($body['description'] ?? '', 2000, 'Description');
    $db = az_db();
    $q = $db->prepare('SELECT id FROM model_videos WHERE user_id=? AND media_id=?');
    $q->execute([$owner, $mediaId]);
    if ($q->fetchColumn()) throw new AzHttpError(409, 'This video is already in My Videos.', 'already_added');
    $q = $db->prepare('SELECT COUNT(*) FROM model_videos WHERE user_id=?');$q->execute([$owner]);
    if ((int)$q->fetchColumn() >= 200) throw new AzHttpError(413, 'My Videos has reached its 200-video limit.', 'library_full');
    $id=az_id();$now=az_now();
    try {
        $q=$db->prepare("INSERT INTO model_videos (id,user_id,media_id,title,description,status,review_note,created_at,updated_at) VALUES(?,?,?,?,?,'draft','',?,?)");
        $q->execute([$id,$owner,$mediaId,$title,$description,$now,$now]);
    } catch (PDOException $e) {
        $q=$db->prepare('SELECT id FROM model_videos WHERE user_id=? AND media_id=?');$q->execute([$owner,$mediaId]);
        if ($q->fetchColumn()) throw new AzHttpError(409, 'This video is already in My Videos.', 'already_added');
        throw $e;
    }
    az_agent_workspace_video_event($owner,$owner,'video_created','Video draft created',$title,$id,false);
    return az_model_video_data(az_model_video_row($id,$owner));
}

function az_model_video_update(array $body, int $owner): array
{
    $row=az_model_video_row(az_text($body['id']??'',32,'Video ID'),$owner);
    $revision=az_integer($body['revision']??0,1,PHP_INT_MAX,'Revision');
    if ($revision!==(int)$row['revision']) throw new AzHttpError(409,'This video changed in another tab. Reload it.','revision_conflict');
    $title=az_text($body['title']??'',200,'Title');
    if ($title==='') throw new AzHttpError(400,'Enter a video title.','title_required');
    $description=az_text($body['description']??'',2000,'Description');
    if ($title===$row['title'] && $description===$row['description']) return az_model_video_data($row);
    // Editing a queued or public post always removes it from circulation until reviewed again.
    $q=az_db()->prepare("UPDATE model_videos SET title=?,description=?,status='draft',review_note='',rights_confirmed=0,submitted_at=NULL,published_at=NULL,reviewed_at=NULL,reviewed_by=NULL,revision=revision+1,updated_at=? WHERE id=? AND user_id=? AND revision=?");
    $q->execute([$title,$description,az_now(),$row['id'],$owner,$revision]);
    if ($q->rowCount()!==1) throw new AzHttpError(409,'This video changed. Reload it.','revision_conflict');
    az_publishing_cancel_schedule($row['id']);
    az_agent_workspace_video_event($owner,$owner,'video_updated','Video details updated',$title,$row['id'],$row['status']==='pending_review'||$row['status']==='published'||$row['status']==='scheduled');
    return az_model_video_data(az_model_video_row($row['id'],$owner));
}

function az_model_video_credit(int $owner): string
{
    $q=az_db()->prepare("SELECT stage_name FROM model_profiles WHERE linked_user_id=? AND status='active' LIMIT 1");
    $q->execute([$owner]);
    $name=trim((string)($q->fetchColumn() ?: ''));
    // Do not expose the account holder's private full name by default.
    return $name !== '' ? mb_safe_trim($name,200) : 'AZHottie Model';
}

function mb_safe_trim(string $value, int $limit): string
{
    if (function_exists('mb_substr')) return mb_substr($value, 0, $limit, 'UTF-8');
    $chars=preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    return $chars===false ? '' : implode('', array_slice($chars, 0, $limit));
}

function az_model_video_submit(array $body,int $owner): array
{
    $row=az_model_video_row(az_text($body['id']??'',32,'Video ID'),$owner);
    $revision=az_integer($body['revision']??0,1,PHP_INT_MAX,'Revision');
    if ($revision!==(int)$row['revision']) throw new AzHttpError(409,'Video changed. Reload it.','revision_conflict');
    if (!in_array($row['status'],['draft','changes_requested'],true)) throw new AzHttpError(409,'Only draft or returned videos can be submitted.','invalid_status');
    if (($body['rightsConfirmed'] ?? null)!==true) throw new AzHttpError(400,'Confirm you have permission to share this video and everyone featured.','rights_required');
    $account=az_account_user($owner);
    if (!$account || $account['status']!=='active' || !az_has_role($account,'model')) throw new AzHttpError(403,'An active model account is required to submit.','role_required');
    if (!str_starts_with((string)$row['mime_type'],'video/') || !is_file(az_media_path($row))) throw new AzHttpError(409,'The gallery video is unavailable or invalid.','media_missing');
    $now=az_now();
    $q=az_db()->prepare("UPDATE model_videos SET status='pending_review',rights_confirmed=1,submitted_at=?,review_note='',revision=revision+1,updated_at=? WHERE id=? AND user_id=? AND revision=?");
    $q->execute([$now,$now,$row['id'],$owner,$revision]);
    if ($q->rowCount()!==1) throw new AzHttpError(409,'Video changed. Reload it.','revision_conflict');
    az_agent_workspace_video_event($owner,$owner,'video_submitted','Video sent for admin review',$row['title'],$row['id'],true);
    return az_model_video_data(az_model_video_row($row['id'],$owner));
}

function az_model_video_withdraw(array $body,int $owner): array
{
    $row=az_model_video_row(az_text($body['id']??'',32,'Video ID'),$owner);
    $revision=az_integer($body['revision']??0,1,PHP_INT_MAX,'Revision');
    if ($revision!==(int)$row['revision']) throw new AzHttpError(409,'Video changed. Reload it.','revision_conflict');
    if ($row['status']==='draft') return az_model_video_data($row);
    $q=az_db()->prepare("UPDATE model_videos SET status='draft',rights_confirmed=0,review_note='',submitted_at=NULL,published_at=NULL,reviewed_at=NULL,reviewed_by=NULL,revision=revision+1,updated_at=? WHERE id=? AND user_id=? AND revision=?");
    $q->execute([az_now(),$row['id'],$owner,$revision]);
    if ($q->rowCount()!==1) throw new AzHttpError(409,'Video changed. Reload it.','revision_conflict');
    az_publishing_cancel_schedule($row['id']);
    az_agent_workspace_video_event($owner,$owner,'video_withdrawn','Video withdrawn from review/publication',$row['title'],$row['id'],true);
    return az_model_video_data(az_model_video_row($row['id'],$owner));
}

function az_model_video_delete(array $body,int $owner): array
{
    $row=az_model_video_row(az_text($body['id']??'',32,'Video ID'),$owner);
    $revision=az_integer($body['revision']??0,1,PHP_INT_MAX,'Revision');
    $q=az_db()->prepare('DELETE FROM model_videos WHERE id=? AND user_id=? AND revision=?');
    $q->execute([$row['id'],$owner,$revision]);
    if ($q->rowCount()!==1) throw new AzHttpError(409,'Video changed. Reload it.','revision_conflict');
    // Deletion keeps private gallery media but removes the video's public entry.
    // Avoid a broken deep link in the log by sending users back to their library.
    az_agent_workspace_video_event($owner,$owner,'video_deleted','Video removed from My Videos',$row['title'],$row['id'],$row['status']==='published'||$row['status']==='pending_review');
    // The underlying original and edited copies in personal_media are preserved.
    return ['deleted'=>true,'id'=>$row['id']];
}

/** Changes to the gallery poster cannot silently alter an approved public publication. */
function az_model_video_source_cover_changed(string $mediaId,int $owner): void
{
    $q=az_db()->prepare("UPDATE model_videos SET status='draft',rights_confirmed=0,submitted_at=NULL,published_at=NULL,review_note='',reviewed_at=NULL,reviewed_by=NULL,revision=revision+1,updated_at=? WHERE media_id=? AND user_id=? AND status IN ('pending_review','published','scheduled')");
    $q->execute([az_now(),$mediaId,$owner]);
    $cancel=az_db()->prepare("UPDATE publishing_agent_schedules SET state='cancelled',updated_at=? WHERE user_id=? AND video_id IN (SELECT id FROM model_videos WHERE media_id=? AND user_id=?) AND state='scheduled'");
    $cancel->execute([az_now(),$owner,$mediaId,$owner]);
}

function az_model_video_review_list(): array
{
    // The admin queue can be busy; fetch all private review fields in one JOIN.
    $q=az_db()->query("SELECT p.*,m.duration_ms,m.mime_type,m.size_bytes,m.file_name,m.storage_name,m.cover_storage_name,m.cover_mime_type,
            m.revision AS source_revision,u.name AS owner_name,u.status AS owner_status
        FROM model_videos p JOIN personal_media m ON m.id=p.media_id AND m.user_id=p.user_id
            JOIN users u ON u.id=p.user_id
        WHERE p.status IN ('pending_review','published','scheduled','changes_requested','rejected')
        ORDER BY CASE p.status WHEN 'pending_review' THEN 0 WHEN 'changes_requested' THEN 1 WHEN 'rejected' THEN 2 ELSE 3 END,
            p.submitted_at DESC,p.id DESC LIMIT 200");
    $items=[];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $item=az_model_video_data($row);
        $item['ownerId']=(int)$row['user_id'];
        $item['ownerName']=$row['owner_name'];
        $items[]=$item;
    }
    return $items;
}

function az_model_video_moderate(array $body,int $admin): array
{
    $row=az_model_video_any_row(az_text($body['id']??'',32,'Video ID'));
    $revision=az_integer($body['revision']??0,1,PHP_INT_MAX,'Revision');
    if ($revision!==(int)$row['revision']) throw new AzHttpError(409,'This video changed. Reload the queue.','revision_conflict');
    $decision=az_text($body['decision']??'',24,'Review decision');
    if (!in_array($decision,['approve','schedule','request_changes','reject','unpublish'],true)) throw new AzHttpError(400,'Invalid review decision.','invalid_decision');
    if ($decision==='unpublish' && !in_array($row['status'],['published','scheduled'],true)) throw new AzHttpError(409,'Only a published or scheduled video can be unpublished.','invalid_status');
    if ($decision!=='unpublish' && $row['status']!=='pending_review') throw new AzHttpError(409,'Only submitted videos can be reviewed.','invalid_status');
    $note=az_text($body['note']??'',2000,'Review notes');
    if (in_array($decision,['request_changes','reject'],true) && $note==='') throw new AzHttpError(400,'Enter a review reason for the model.','note_required');
    if (in_array($decision,['approve','schedule'],true)) {
        $account=az_account_user((int)$row['user_id']);
        if (!$account || $account['status']!=='active' || !az_has_role($account,'model')) throw new AzHttpError(409,'The model account is not active.','model_unavailable');
        if (!(int)$row['rights_confirmed'] || !str_starts_with((string)$row['mime_type'],'video/') || !is_file(az_media_path($row)))
            throw new AzHttpError(409,'Video is missing, invalid, or not approved for sharing by the model.','video_unavailable');
    }
    if ($decision==='schedule') return az_publishing_schedule_video($row,$admin,$body['publishAt']??null);
    $now=az_now();
    $status=match($decision){'approve'=>'published','request_changes'=>'changes_requested','reject'=>'rejected','unpublish'=>'draft'};
    $credit=$decision==='approve'?az_model_video_credit((int)$row['user_id']):($decision==='unpublish'?'':$row['public_credit']);
    $q=az_db()->prepare('UPDATE model_videos SET status=?,review_note=?,public_credit=?,published_at=?,reviewed_at=?,reviewed_by=?,revision=revision+1,updated_at=? WHERE id=? AND revision=?');
    $q->execute([$status,$note,$credit,$decision==='approve'?$now:null,$now,$admin,$now,$row['id'],$revision]);
    if ($q->rowCount()!==1) throw new AzHttpError(409,'Video changed. Reload the queue.','revision_conflict');
    if ($decision==='unpublish')az_publishing_cancel_schedule($row['id']);
    az_agent_workspace_video_event($admin,(int)$row['user_id'],'video_'.$status, 'Admin review: '.str_replace('_',' ',$status), $row['title'].($note!==''?' · '.$note:''),$row['id'],true);
    $item=az_model_video_data(az_model_video_any_row($row['id']));
    return $item;
}

function az_model_video_public_row(string $id): array
{
    $row=az_model_video_any_row($id);
    $q=az_db()->prepare("SELECT COUNT(*) FROM user_roles WHERE user_id=? AND role='model'");$q->execute([(int)$row['user_id']]);
    if ($row['status']!=='published' || !$row['published_at'] || !$row['rights_confirmed'] || $row['owner_status']!=='active' || !(int)$q->fetchColumn())
        throw new AzHttpError(404,'This video is not available.','not_found');
    return $row;
}

function az_model_video_public_data(array $row): array
{
    // Public allowlist: no private owner, storage, original filename, review notes or assignment metadata.
    return ['id'=>$row['id'],'title'=>$row['title'],'description'=>$row['description'],
        'modelName'=>$row['public_credit']?:'AZHottie Model',
        'publishedAt'=>$row['published_at'],'updatedAt'=>$row['updated_at'],
        'durationMs'=>(int)$row['duration_ms'],'mimeType'=>$row['mime_type'],
        'watchUrl'=>az_url('watch.php?id='.$row['id']),
        'mediaUrl'=>az_url('model-video-file.php?id='.$row['id']),
        'coverUrl'=>$row['cover_storage_name']?az_url('model-video-file.php?type=cover&id='.$row['id'].'&v='.$row['source_revision']):null];
}

/** Produces one chronological public feed; does not change admin finished-video APIs. */
function az_public_watch_feed(array $query): array
{
    $search=az_text($query['search']??'',120,'Search');
    $page=isset($query['page'])?az_integer($query['page'],1,100000,'Page'):1;
    $productionWhere=az_public_video_conditions();
    $modelWhere="p.status='published' AND p.published_at IS NOT NULL AND p.rights_confirmed=1 AND u.status='active' AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id=p.user_id AND ur.role='model')";
    $params=[];
    if ($search!=='') {
        $productionWhere.=' AND (v.fan_title LIKE ? OR v.fan_description LIKE ? OR v.fan_credit LIKE ?)';
        $modelWhere.=' AND (p.title LIKE ? OR p.description LIKE ? OR p.public_credit LIKE ?)';
        $params=array_fill(0,6,'%'.$search.'%');
    }
    $parts=[
        "SELECT v.id,v.fan_title AS title,v.fan_description AS description,v.fan_credit AS credit,v.fan_published_at AS published_at,v.fan_updated_at AS updated_at,v.duration_ms,v.mime_type,v.cover_storage_name,v.revision AS source_revision,'production' AS origin FROM finished_videos v JOIN interviews i ON i.id=v.interview_id JOIN users u ON u.id=v.owner_id WHERE $productionWhere",
        "SELECT p.id,p.title,p.description,p.public_credit AS credit,p.published_at,p.updated_at,m.duration_ms,m.mime_type,m.cover_storage_name,m.revision AS source_revision,'creator' AS origin FROM model_videos p JOIN personal_media m ON m.id=p.media_id AND m.user_id=p.user_id JOIN users u ON u.id=p.user_id WHERE $modelWhere",
    ];
    $union=implode(' UNION ALL ',$parts);
    $count=az_db()->prepare('SELECT COUNT(*) FROM ('.$union.') AS posts');$count->execute($params);
    $total=(int)$count->fetchColumn();$offset=($page-1)*24;
    $querySql=az_db()->prepare('SELECT * FROM ('.$union.') AS posts ORDER BY published_at DESC,id DESC LIMIT 24 OFFSET '.$offset);
    $querySql->execute($params);
    $videos=[];
    foreach ($querySql->fetchAll() as $p) {
        $id=$p['id'];$creator=$p['origin']==='creator';
        $endpoint=$creator?'model-video-file.php':'public-media.php';
        $videos[]=['id'=>$id,'title'=>(string)$p['title'],'description'=>(string)$p['description'],
            'modelName'=>(string)($p['credit']??''),'publishedAt'=>$p['published_at'],'updatedAt'=>$p['updated_at'],
            'durationMs'=>(int)$p['duration_ms'],'mimeType'=>$p['mime_type'],
            'watchUrl'=>az_url('watch.php?id='.$id),'mediaUrl'=>az_url($endpoint.'?id='.$id),
            'coverUrl'=>$p['cover_storage_name']?az_url($endpoint.'?type=cover&id='.$id.'&v='.$p['source_revision']):null];
    }
    return ['videos'=>$videos,'page'=>$page,'total'=>$total,'hasMore'=>$offset+24<$total];
}

function az_public_watch_video(string $id): array
{
    try { $video=az_public_video_data(az_public_video_row($id)); $video['origin']='production'; return $video; }
    catch (AzHttpError $e) { if ($e->status!==404) throw $e; }
    $video=az_model_video_public_data(az_model_video_public_row($id)); $video['origin']='creator'; return $video;
}

/** Published independent clips for an owner's social profile. */
function az_model_video_public_profile(int $owner): array
{
    $q=az_db()->prepare("SELECT p.id,p.title,p.description,p.published_at,m.cover_storage_name,m.revision AS source_revision FROM model_videos p JOIN personal_media m ON m.id=p.media_id AND m.user_id=p.user_id JOIN users u ON u.id=p.user_id WHERE p.user_id=? AND p.status='published' AND p.published_at IS NOT NULL AND p.rights_confirmed=1 AND u.status='active' AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id=p.user_id AND ur.role='model') ORDER BY p.published_at DESC LIMIT 24");
    $q->execute([$owner]);return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** Dashboard teaser for administrators. Keep detailed private submissions inside the admin queue. */
function az_model_video_dashboard_summary(): array
{
    $summary=['pending'=>0,'published'=>0,'changes_requested'=>0,'rejected'=>0,'scheduled'=>0,'total'=>0,'recent'=>[]];
    $counts=az_db()->query('SELECT status,COUNT(*) AS total FROM model_videos GROUP BY status');
    foreach ($counts->fetchAll() as $row) {
        $status=(string)$row['status'];
        if ($status==='pending_review') $summary['pending']=(int)$row['total'];
        elseif (array_key_exists($status,$summary)) $summary[$status]=(int)$row['total'];
        $summary['total']+=(int)$row['total'];
    }
    $latest=az_db()->query("SELECT p.id,p.title,p.submitted_at,u.name AS model_name
        FROM model_videos p JOIN users u ON u.id=p.user_id
        WHERE p.status='pending_review' ORDER BY p.submitted_at DESC,p.id DESC LIMIT 3");
    $summary['recent']=$latest->fetchAll(PDO::FETCH_ASSOC);
    return $summary;
}
