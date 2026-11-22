<?php
declare(strict_types=1);
/** Personal, owner-private media library for model/professional accounts. Not published. */

function az_upgrade_personal_media_schema(PDO $db): void
{
    if (az_schema_version($db) >= 18) return;
    az_prepare_storage();
    $lock = fopen(AZ_STORAGE . '/schema-migration.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Media migration lock unavailable.');
    try {
        if (az_schema_version($db) >= 18) return;
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $user = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $db->exec("CREATE TABLE IF NOT EXISTS personal_media (
            id CHAR(32) PRIMARY KEY, user_id $user NOT NULL,
            title VARCHAR(200) NOT NULL, description TEXT NOT NULL,
            mime_type VARCHAR(80) NOT NULL, file_name VARCHAR(250) NOT NULL,
            storage_name VARCHAR(80) NOT NULL UNIQUE, size_bytes BIGINT NOT NULL,
            duration_ms BIGINT NOT NULL DEFAULT 0, trim_start_ms BIGINT NOT NULL DEFAULT 0,
            trim_end_ms BIGINT NOT NULL DEFAULT 0, cover_storage_name VARCHAR(80) NULL,
            cover_mime_type VARCHAR(80) NULL, parent_media_id CHAR(32) NULL,
            revision INTEGER NOT NULL DEFAULT 1, created_at VARCHAR(32) NOT NULL,
            updated_at VARCHAR(32) NOT NULL, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS personal_media_uploads (
            id CHAR(32) PRIMARY KEY, user_id $user NOT NULL, client_token VARCHAR(120) NOT NULL,
            title VARCHAR(200) NOT NULL, file_name VARCHAR(250) NOT NULL,
            mime_type VARCHAR(80) NOT NULL, total_bytes BIGINT NOT NULL,
            received_bytes BIGINT NOT NULL DEFAULT 0, next_index INTEGER NOT NULL DEFAULT 0,
            chunk_bytes INTEGER NOT NULL, last_chunk_sha256 CHAR(64) NOT NULL DEFAULT '',
            last_chunk_bytes INTEGER NOT NULL DEFAULT 0, status VARCHAR(16) NOT NULL DEFAULT 'pending',
            media_id CHAR(32) NULL, parent_media_id CHAR(32) NULL, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL,
            UNIQUE(user_id, client_token), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        if ($mysql) {
            foreach (['personal_media_owner'=>['personal_media','user_id, created_at'], 'personal_media_upload_owner'=>['personal_media_uploads','user_id, status']] as $key=>$spec) {
                $q=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
                $q->execute([$spec[0],$key]);
                if (!(int)$q->fetchColumn()) $db->exec('CREATE INDEX '.$key.' ON '.$spec[0].'('.$spec[1].')');
            }
        } else {
            $db->exec('CREATE INDEX IF NOT EXISTS personal_media_owner ON personal_media(user_id, created_at)');
            $db->exec('CREATE INDEX IF NOT EXISTS personal_media_upload_owner ON personal_media_uploads(user_id, status)');
        }
        $db->exec('UPDATE studio_schema SET version = 18 WHERE id = 1');
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function az_personal_media_role(array $user): void
{
    if (!az_has_role($user, 'model') && !az_has_role($user, 'professional') && !az_has_role($user, 'editor') && !az_has_role($user, 'admin')) {
        throw new AzHttpError(403, 'A creator or live editor role is required for private media.', 'role_required');
    }
}

function az_personal_media_row(string $id, int $owner): array
{
    if (!az_valid_id($id)) throw new AzHttpError(404, 'This personal video was not found.', 'not_found');
    $q = az_db()->prepare('SELECT * FROM personal_media WHERE id=? AND user_id=?');
    $q->execute([$id, $owner]);
    $row = $q->fetch();
    if (!$row) throw new AzHttpError(404, 'This personal video was not found.', 'not_found');
    return $row;
}

function az_personal_media_data(array $row): array
{
    return [
        'id'=>$row['id'], 'title'=>$row['title'], 'description'=>$row['description'],
        'fileName'=>$row['file_name'], 'mimeType'=>$row['mime_type'],
        'sizeBytes'=>(int)$row['size_bytes'], 'durationMs'=>(int)$row['duration_ms'],
        'trimStartMs'=>(int)$row['trim_start_ms'], 'trimEndMs'=>(int)$row['trim_end_ms'],
        'revision'=>(int)$row['revision'], 'createdAt'=>$row['created_at'], 'updatedAt'=>$row['updated_at'],
        'parentMediaId'=>$row['parent_media_id'],
        'videoUrl'=>az_url('personal-media-file.php?id='.$row['id']),
        'downloadUrl'=>az_url('personal-media-file.php?id='.$row['id'].'&download=1'),
        'coverUrl'=>empty($row['cover_storage_name']) ? null : az_url('personal-media-file.php?id='.$row['id'].'&type=cover'),
    ];
}

function az_personal_media_list(int $owner): array
{
    $q=az_db()->prepare('SELECT * FROM personal_media WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 300');
    $q->execute([$owner]);
    return array_map('az_personal_media_data', $q->fetchAll());
}

function az_personal_media_quota(int $owner): array
{
    $q=az_db()->prepare('SELECT COALESCE(SUM(size_bytes),0) FROM personal_media WHERE user_id=?');
    $q->execute([$owner]); $used=(int)$q->fetchColumn();
    $q=az_db()->prepare("SELECT COALESCE(SUM(total_bytes),0) FROM personal_media_uploads WHERE user_id=? AND status='pending'");
    $q->execute([$owner]); $pending=(int)$q->fetchColumn();
    $limit=max(1, (int)(az_config()['limits']['max_personal_media_bytes'] ?? 5368709120));
    return ['usedBytes'=>$used,'pendingBytes'=>$pending,'limitBytes'=>$limit];
}

/** Pending receipts remain visible after refreshing the browser; the member can reselect the original file. */
function az_personal_uploads_pending(int $owner): array
{
    $q=az_db()->prepare("SELECT id,client_token,title,file_name,mime_type,total_bytes,received_bytes,updated_at FROM personal_media_uploads WHERE user_id=? AND status='pending' ORDER BY updated_at DESC LIMIT 8");
    $q->execute([$owner]);
    return array_map(static fn(array $r):array => [
        'uploadId'=>$r['id'],'clientToken'=>$r['client_token'],'title'=>$r['title'],
        'fileName'=>$r['file_name'],'mimeType'=>$r['mime_type'],
        'totalBytes'=>(int)$r['total_bytes'],'receivedBytes'=>(int)$r['received_bytes'],
        'updatedAt'=>$r['updated_at'],
    ],$q->fetchAll());
}

function az_personal_upload_row(string $id, int $owner): array
{
    if (!az_valid_id($id)) throw new AzHttpError(404, 'Upload not found.', 'not_found');
    $q=az_db()->prepare('SELECT * FROM personal_media_uploads WHERE id=? AND user_id=?');
    $q->execute([$id,$owner]); $row=$q->fetch();
    if (!$row) throw new AzHttpError(404, 'Upload not found.', 'not_found');
    return $row;
}

function az_personal_upload_data(array $row): array
{
    return ['uploadId'=>$row['id'],'totalBytes'=>(int)$row['total_bytes'],
        'receivedBytes'=>(int)$row['received_bytes'],'nextIndex'=>(int)$row['next_index'],
        'chunkBytes'=>(int)$row['chunk_bytes'],'status'=>$row['status'],
        'mediaId'=>$row['media_id']];
}

function az_personal_upload_begin(array $body, int $owner): array
{
    $token=az_text($body['clientToken']??'',120,'Upload recovery token');
    if (!preg_match('/\A[a-zA-Z0-9._:-]{16,120}\z/D',$token)) throw new AzHttpError(400,'Invalid upload recovery identity.','invalid_token');
    $total=az_integer($body['totalBytes']??0,1,min(az_limits()['maxUploadBytes'],1073741824),'Video size');
    $mime=strtolower(trim(explode(';',az_text($body['mimeType']??'',80,'Video type'))[0]));
    if (!in_array($mime,['video/mp4','video/quicktime','video/webm','video/ogg','application/octet-stream',''],true))
        throw new AzHttpError(415,'Select an MP4, MOV, WebM, or Ogg video.','unsupported_media');
    $title=az_text($body['title']??'Untitled video',200,'Video title')?:'Untitled video';
    $filename=az_text($body['fileName']??'video.webm',250,'File name');
    $parent=az_text($body['parentMediaId']??'',32,'Source video');
    if ($parent!=='') az_personal_media_row($parent,$owner);
    $db=az_db();
    $q=$db->prepare('SELECT * FROM personal_media_uploads WHERE user_id=? AND client_token=?');
    $q->execute([$owner,$token]);
    if ($old=$q->fetch()) {
        if ((int)$old['total_bytes']!==$total || $old['file_name']!==$filename) throw new AzHttpError(409,'This recovery token belongs to a different video.','upload_conflict');
        return az_personal_upload_data($old);
    }
    $q=$db->prepare("SELECT COUNT(*) FROM personal_media_uploads WHERE user_id=? AND status='pending'");
    $q->execute([$owner]);
    if ((int)$q->fetchColumn()>=8) throw new AzHttpError(409,'Too many unfinished personal uploads. Resume or discard one first.','pending_limit');
    $q=$db->prepare('SELECT COUNT(*) FROM personal_media WHERE user_id=?');$q->execute([$owner]);
    if ((int)$q->fetchColumn()>=300) throw new AzHttpError(413,'This gallery has reached its 300-video limit.','gallery_full');
    $quota=az_personal_media_quota($owner);
    if ($quota['usedBytes']+$quota['pendingBytes']+$total>$quota['limitBytes'])
        throw new AzHttpError(413,'Your personal gallery storage limit would be exceeded.','quota_exceeded');
    az_prepare_storage();
    $free=@disk_free_space(AZ_STORAGE);
    if ($free!==false && $free<$total+10485760) throw new AzHttpError(507,'The server does not have enough storage for this upload.','storage_full');
    // Mobile phones request smaller upload chunks to tolerate shared-host request limits.
    // The chosen size is stored with the upload, so retries retain the same boundary.
    $requestedChunk=$body['preferredChunkBytes']??az_limits()['chunkBytes'];
    $requestedChunk=az_integer($requestedChunk,65536,1048576,'Requested upload chunk size');
    $chunkBytes=min(az_limits()['chunkBytes'],$requestedChunk);
    $id=az_id(); $file=az_upload_path($id);
    $handle=@fopen($file,'x+b');
    if (!$handle) throw new AzHttpError(507,'The server cannot prepare the upload file.','storage_unavailable');
    fclose($handle); @chmod($file,0640);
    $now=az_now();
    try {
        $q=$db->prepare("INSERT INTO personal_media_uploads (id,user_id,client_token,title,file_name,mime_type,total_bytes,received_bytes,next_index,chunk_bytes,last_chunk_sha256,last_chunk_bytes,status,parent_media_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,0,0,?,?,0,'pending',?,?,?)");
        $q->execute([$id,$owner,$token,$title,$filename,$mime,$total,$chunkBytes,'',$parent?:null,$now,$now]);
    } catch(Throwable $e) {
        @unlink($file);
        // A concurrent retry of the same recovery token must get the same upload.
        $q=$db->prepare('SELECT * FROM personal_media_uploads WHERE user_id=? AND client_token=?');$q->execute([$owner,$token]);
        if ($existing=$q->fetch()) return az_personal_upload_data($existing);
        throw $e;
    }
    return az_personal_upload_data(az_personal_upload_row($id,$owner));
}

function az_personal_upload_chunk(string $id, int $index, string $chunk, int $owner): array
{
    $row=az_personal_upload_row($id,$owner);
    if ($row['status']!=='pending') return az_personal_upload_data($row);
    $file=@fopen(az_upload_path($id),'r+b');
    if (!$file || !flock($file,LOCK_EX)) {
        if (is_resource($file)) fclose($file);
        throw new AzHttpError(409,'Upload is busy. Retry from the confirmed checkpoint.','upload_busy');
    }
    $db=az_db();
    try {
        $row=az_personal_upload_row($id,$owner);
        if ($row['status']!=='pending') return az_personal_upload_data($row);
        $size=filesize($chunk);$expected=min((int)$row['chunk_bytes'],(int)$row['total_bytes']-(int)$row['received_bytes']);
        $hash=hash_file('sha256',$chunk);
        if ($index===(int)$row['next_index']-1) {
            if ((int)$row['last_chunk_bytes']===$size && hash_equals($row['last_chunk_sha256'],$hash)) return az_personal_upload_data($row);
            throw new AzHttpError(409,'This upload position already contains different data.','chunk_conflict');
        }
        if ($index!==(int)$row['next_index']) throw new AzHttpError(409,'Resume at the server-confirmed upload position.','chunk_order');
        if ($size===false || $size!==$expected || $size<1) throw new AzHttpError(400,'The uploaded chunk did not match its expected size.','chunk_size');
        $db->beginTransaction();
        $stat=fstat($file);
        if (!$stat || $stat['size']<(int)$row['received_bytes'] || !ftruncate($file,(int)$row['received_bytes']) || fseek($file,(int)$row['received_bytes'])!==0)
            throw new AzHttpError(409,'The incomplete file cannot be resumed.','upload_corrupted');
        $source=fopen($chunk,'rb');
        if (!$source) throw new AzHttpError(400,'The chunk could not be read.','chunk_invalid');
        try { $copied=stream_copy_to_stream($source,$file); } finally { fclose($source); }
        if ($copied!==$size || !fflush($file)) throw new AzHttpError(507,'The server ran out of space during the upload.','storage_full');
        $q=$db->prepare('UPDATE personal_media_uploads SET received_bytes=?,next_index=?,last_chunk_sha256=?,last_chunk_bytes=?,updated_at=? WHERE id=? AND user_id=?');
        $q->execute([(int)$row['received_bytes']+$size,$index+1,$hash,$size,az_now(),$id,$owner]);
        $db->commit();
        return az_personal_upload_data(az_personal_upload_row($id,$owner));
    } catch(Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    } finally { flock($file,LOCK_UN);fclose($file); }
}

function az_personal_upload_finish(string $id, int $owner): array
{
    $row=az_personal_upload_row($id,$owner);
    if ($row['status']==='completed' && $row['media_id']) return az_personal_media_data(az_personal_media_row($row['media_id'],$owner));
    if ((int)$row['received_bytes']!==(int)$row['total_bytes']) throw new AzHttpError(409,'The video has not finished uploading.','upload_incomplete');
    $path=az_upload_path($id);$handle=@fopen($path,'r+b');
    if (!$handle || !flock($handle,LOCK_EX)) {
        if (is_resource($handle)) fclose($handle);
        $row=az_personal_upload_row($id,$owner);
        if ($row['status']==='completed' && $row['media_id']) return az_personal_media_data(az_personal_media_row($row['media_id'],$owner));
        throw new AzHttpError(409,'Upload finalization is busy. Retry.','upload_busy');
    }
    $db=az_db();$destination=null;
    try {
        $row=az_personal_upload_row($id,$owner);
        if ($row['status']==='completed' && $row['media_id']) return az_personal_media_data(az_personal_media_row($row['media_id'],$owner));
        $stat=fstat($handle);
        if (!$stat || $stat['size']!==(int)$row['total_bytes']) throw new AzHttpError(409,'The uploaded file is incomplete.','upload_size_mismatch');
        $mime=az_detect_media_type($path,(string)$row['mime_type']);
        if (!in_array($mime,['video/mp4','video/quicktime','video/webm','video/ogg'],true)) throw new AzHttpError(415,'Only supported video footage can enter your gallery.','unsupported_media');
        $mediaId=az_id();$storage=$mediaId.'.bin';$destination=AZ_STORAGE.'/media/'.$storage;$now=az_now();
        $db->beginTransaction();
        $q=$db->prepare('INSERT INTO personal_media (id,user_id,title,description,mime_type,file_name,storage_name,size_bytes,parent_media_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$mediaId,$owner,$row['title'],'',$mime,$row['file_name'],$storage,(int)$row['total_bytes'],$row['parent_media_id'],$now,$now]);
        $q=$db->prepare("UPDATE personal_media_uploads SET media_id=?,status='completed',updated_at=? WHERE id=? AND user_id=? AND status='pending'");
        $q->execute([$mediaId,$now,$id,$owner]);
        if ($q->rowCount()!==1) throw new AzHttpError(409,'Another upload finalization completed.','upload_busy');
        if (!rename($path,$destination)) throw new AzHttpError(507,'The server could not store the completed video.','storage_unavailable');
        @chmod($destination,0640);
        $db->commit();$destination=null;
        return az_personal_media_data(az_personal_media_row($mediaId,$owner));
    } catch(Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($destination!==null && is_file($destination) && !is_file($path)) @rename($destination,$path);
        throw $e;
    } finally { flock($handle,LOCK_UN);fclose($handle); }
}

function az_personal_upload_cancel(string $id, int $owner): array
{
    $row=az_personal_upload_row($id,$owner);
    if ($row['status']==='completed') return ['cancelled'=>false,'completed'=>true,'mediaId'=>$row['media_id']];
    $handle=@fopen(az_upload_path($id),'r+b');
    if (!$handle || !flock($handle,LOCK_EX)) {
        if (is_resource($handle)) fclose($handle);
        throw new AzHttpError(409,'The upload is busy. Try again.','upload_busy');
    }
    try {
        $db=az_db();$db->prepare('DELETE FROM personal_media_uploads WHERE id=? AND user_id=? AND status=\'pending\'')->execute([$id,$owner]);
        @unlink(az_upload_path($id));
        return ['cancelled'=>true];
    } finally { flock($handle,LOCK_UN);fclose($handle); }
}

function az_personal_media_update(array $body, int $owner): array
{
    $row=az_personal_media_row(az_text($body['id']??'',32,'Media ID'),$owner);
    $rev=az_integer($body['revision']??0,1,PHP_INT_MAX,'Revision');
    if ($rev!==(int)$row['revision']) throw new AzHttpError(409,'This video was edited in another tab. Reload it first.','revision_conflict');
    $title=az_text($body['title']??'',200,'Video title');
    if ($title==='') throw new AzHttpError(400,'Enter a title for this video.','title_required');
    $note=az_text($body['description']??'',2000,'Video notes');
    $duration=az_integer($body['durationMs']??$row['duration_ms'],0,172800000,'Duration');
    $from=az_integer($body['trimStartMs']??$row['trim_start_ms'],0,172800000,'Trim start');
    $to=az_integer($body['trimEndMs']??$row['trim_end_ms'],0,172800000,'Trim end');
    if ($duration>0 && ($from >= $duration || $to > $duration || ($to!==0 && $from+100>$to)))
        throw new AzHttpError(400,'Keep a video section at least 0.1 seconds long, inside the video.','invalid_trim');
    if ($duration===0 && ($from!==0 || $to!==0)) throw new AzHttpError(400,'Load the video duration before setting trims.','invalid_trim');
    $q=az_db()->prepare('UPDATE personal_media SET title=?,description=?,duration_ms=?,trim_start_ms=?,trim_end_ms=?,revision=revision+1,updated_at=? WHERE id=? AND user_id=? AND revision=?');
    $q->execute([$title,$note,$duration,$from,$to,az_now(),$row['id'],$owner,$rev]);
    if ($q->rowCount()!==1) throw new AzHttpError(409,'The video was updated elsewhere. Reload before saving.','revision_conflict');
    return az_personal_media_data(az_personal_media_row($row['id'],$owner));
}

function az_personal_media_cover(string $id,int $owner): array
{
    $row=az_personal_media_row($id,$owner);
    $file=az_uploaded_file('cover');$size=filesize($file['tmp_name']);
    if ($size===false || $size<1 || $size>5242880) throw new AzHttpError(413,'Choose a cover image under 5 MB.','cover_too_large');
    $info=@getimagesize($file['tmp_name']);$mime=$info['mime']??'';
    if (!$info || !in_array($mime,['image/jpeg','image/png','image/webp'],true) || $info[0]<1 || $info[1]<1 || $info[0]>12000 || $info[1]>12000 || $info[0]*$info[1]>40000000)
        throw new AzHttpError(415,'Choose a supported JPG, PNG or WebP cover image.','unsupported_cover');
    $storage=az_id().'.bin';$path=az_media_path(['storage_name'=>$storage]);
    if (!move_uploaded_file($file['tmp_name'],$path)) throw new AzHttpError(507,'Could not save the cover.','storage_unavailable');
    @chmod($path,0640);
    try {
        $q=az_db()->prepare('UPDATE personal_media SET cover_storage_name=?,cover_mime_type=?,revision=revision+1,updated_at=? WHERE id=? AND user_id=?');
        $q->execute([$storage,$mime,az_now(),$id,$owner]);
    } catch(Throwable $e) {@unlink($path);throw $e;}
    if ($row['cover_storage_name']) @unlink(az_media_path(['storage_name'=>$row['cover_storage_name']]));
    if (function_exists('az_model_video_source_cover_changed')) az_model_video_source_cover_changed($id,$owner);
    return az_personal_media_data(az_personal_media_row($id,$owner));
}

function az_personal_media_delete(string $id,int $owner): array
{
    $row=az_personal_media_row($id,$owner);
    $q=az_db()->prepare('DELETE FROM personal_media WHERE id=? AND user_id=?');$q->execute([$id,$owner]);
    @unlink(az_media_path($row));
    if ($row['cover_storage_name']) @unlink(az_media_path(['storage_name'=>$row['cover_storage_name']]));
    return ['id'=>$id,'deleted'=>true];
}
