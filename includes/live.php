<?php
declare(strict_types=1);
/** Private fan live program: host browser composites cameras; PHP only relays
 * short-lived WebRTC session descriptions. No unapproved camera is published.
 */
const AZ_LIVE_MAX_VIEWERS = 6; // Peer-to-peer fan-out, not an SFU / mass broadcast.
const AZ_LIVE_HOST_TTL = 25;
const AZ_LIVE_VIEWER_TTL = 45;

function az_upgrade_live_schema(PDO $db): void
{
    if (az_schema_version($db) >= 23 || az_schema_version($db) < 22) return;
    az_prepare_storage();
    $lock = fopen(AZ_STORAGE . '/schema-migration.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Live studio migration lock is unavailable.');
    try {
        if (az_schema_version($db) >= 23) return;
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $uid = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $long = $mysql ? 'LONGTEXT' : 'TEXT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $db->exec("CREATE TABLE IF NOT EXISTS az_live_rooms (
          id CHAR(32) PRIMARY KEY, owner_id $uid NOT NULL, title VARCHAR(180) NOT NULL,
          status VARCHAR(16) NOT NULL, created_at VARCHAR(32) NOT NULL,
          started_epoch BIGINT NOT NULL, heartbeat_epoch BIGINT NOT NULL, ended_epoch BIGINT NULL,
          FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS az_live_viewers (
          id CHAR(32) PRIMARY KEY, room_id CHAR(32) NOT NULL, user_id $uid NOT NULL,
          nonce CHAR(32) NOT NULL, offer_sdp $long NOT NULL,
          answer_sdp $long NULL, status VARCHAR(16) NOT NULL,
          updated_epoch BIGINT NOT NULL, created_epoch BIGINT NOT NULL,
          FOREIGN KEY (room_id) REFERENCES az_live_rooms(id) ON DELETE CASCADE,
          FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS az_live_recordings (
          id CHAR(32) PRIMARY KEY, room_id CHAR(32) NOT NULL, owner_id $uid NOT NULL,
          media_id CHAR(32) NOT NULL UNIQUE, recorded_at VARCHAR(32) NOT NULL,
          FOREIGN KEY (room_id) REFERENCES az_live_rooms(id) ON DELETE CASCADE,
          FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
          FOREIGN KEY (media_id) REFERENCES personal_media(id) ON DELETE CASCADE)$suffix");
        if ($mysql) {
            foreach ([['live_active','az_live_rooms','status, heartbeat_epoch'],['live_viewer_room','az_live_viewers','room_id, status, updated_epoch'],['live_recording_room','az_live_recordings','room_id, recorded_at']] as [$name,$table,$columns]) {
                $q=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
                $q->execute([$table,$name]);
                if (!(int)$q->fetchColumn()) $db->exec("CREATE INDEX $name ON $table ($columns)");
            }
        } else {
            $db->exec('CREATE INDEX IF NOT EXISTS live_active ON az_live_rooms(status, heartbeat_epoch)');
            $db->exec('CREATE INDEX IF NOT EXISTS live_viewer_room ON az_live_viewers(room_id, status, updated_epoch)');
            $db->exec('CREATE INDEX IF NOT EXISTS live_recording_room ON az_live_recordings(room_id, recorded_at)');
        }
        $db->exec('UPDATE studio_schema SET version=23 WHERE id=1');
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
function az_live_require_editor(bool $json = false): array
{
    return az_require_roles(['admin','editor'], $json);
}
function az_live_secure(): void
{
    if (function_exists('az_personal_phone_secure')) az_personal_phone_secure();
}
function az_live_ice_servers(): array
{
    $custom = az_config()['live']['iceServers'] ?? null;
    if (is_array($custom) && count($custom) <= 4 && count($custom) > 0) {
        $safe = [];
        foreach ($custom as $server) {
            if (!is_array($server) || !isset($server['urls'])) continue;
            $urls = is_string($server['urls']) ? [$server['urls']] : $server['urls'];
            if (!is_array($urls) || count($urls) > 4) continue;
            $urls = array_values(array_filter($urls, static fn($url) => is_string($url) && strlen($url) <= 240 && preg_match('~^(stun|stuns|turn|turns):~', $url)));
            if (!$urls) continue;
            $item = ['urls'=>$urls];
            foreach (['username','credential'] as $key) if (isset($server[$key]) && is_string($server[$key]) && strlen($server[$key]) <= 512) $item[$key]=$server[$key];
            $safe[]=$item;
        }
        if ($safe) return $safe;
    }
    return [['urls'=>['stun:stun.l.google.com:19302']]];
}
function az_live_room_public(array $room): array
{
    return ['id'=>$room['id'], 'title'=>$room['title'], 'startedEpoch'=>(int)$room['started_epoch']];
}
function az_live_active_room(): ?array
{
    $q = az_db()->prepare("SELECT * FROM az_live_rooms WHERE status='live' AND heartbeat_epoch>? ORDER BY started_epoch DESC LIMIT 1");
    $q->execute([time()-AZ_LIVE_HOST_TTL]);
    return $q->fetch() ?: null;
}
function az_live_host_room(int $owner,string $roomId,bool $allowEnded = false): array
{
    if (!az_valid_id($roomId)) throw new AzHttpError(404, 'Live session not found.', 'live_missing');
    $q=az_db()->prepare('SELECT * FROM az_live_rooms WHERE id=? AND owner_id=?');
    $q->execute([$roomId,$owner]); $room=$q->fetch();
    if (!$room || (!$allowEnded && ($room['status']!=='live' || (int)$room['heartbeat_epoch'] < time()-AZ_LIVE_HOST_TTL)))
        throw new AzHttpError(409,'This live session has ended or needs to be restarted.','live_ended');
    return $room;
}
function az_live_start(int $owner,string $title): array
{
    az_live_secure();$title=az_text($title,180,'Live session title');
    if ($title==='') throw new AzHttpError(400,'Give this live session a title.','title_required');
    $db=az_db();$lock=az_community_lock();
    try {
        $now=time();
        // One active program is permitted. Never interrupt someone else's live
        // broadcast merely because a second editor pressed Go live.
        $active=az_live_active_room();
        if ($active) throw new AzHttpError(409,'Another Director is already on air. Ask them to end their broadcast first.','live_busy');
        // Old/stale rows cannot regain viewer access; mark them ended.
        $db->prepare("UPDATE az_live_rooms SET status='ended',ended_epoch=? WHERE status='live' AND heartbeat_epoch<?")->execute([$now,$now-AZ_LIVE_HOST_TTL]);
        $id=az_id();$db->prepare("INSERT INTO az_live_rooms(id,owner_id,title,status,created_at,started_epoch,heartbeat_epoch,ended_epoch) VALUES(?,?,?,'live',?,?,?,NULL)")
          ->execute([$id,$owner,$title,az_now(),$now,$now]);
        return ['room'=>['id'=>$id,'title'=>$title,'startedEpoch'=>$now],'maxViewers'=>AZ_LIVE_MAX_VIEWERS,'iceServers'=>az_live_ice_servers()];
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function az_live_heartbeat(int $owner,string $roomId): array
{
    $room=az_live_host_room($owner,$roomId);
    az_db()->prepare("UPDATE az_live_rooms SET heartbeat_epoch=? WHERE id=? AND owner_id=? AND status='live'")->execute([time(),$roomId,$owner]);
    return ['room'=>az_live_room_public($room),'viewers'=>az_live_viewer_count($roomId),'maxViewers'=>AZ_LIVE_MAX_VIEWERS];
}
function az_live_viewer_count(string $roomId): int
{
    $q=az_db()->prepare("SELECT COUNT(*) FROM az_live_viewers WHERE room_id=? AND status IN ('waiting','connected') AND updated_epoch>?");
    $q->execute([$roomId,time()-AZ_LIVE_VIEWER_TTL]);return (int)$q->fetchColumn();
}
function az_live_stop(int $owner,string $roomId): array
{
    $room=az_live_host_room($owner,$roomId,true);
    az_db()->prepare("UPDATE az_live_rooms SET status='ended',ended_epoch=? WHERE id=? AND owner_id=? AND status='live'")->execute([time(),$roomId,$owner]);
    az_db()->prepare("UPDATE az_live_viewers SET status='left' WHERE room_id=?")->execute([$roomId]);
    if(function_exists('az_live_engagement_end'))az_live_engagement_end($roomId);
    return ['ended'=>true];
}
function az_live_signal_sdp(mixed $sdp): string
{
    if (!is_string($sdp) || strlen($sdp)<16 || strlen($sdp)>140000 || !preg_match('/\Av=0\r?\n/D',$sdp) || str_contains($sdp,"\0"))
        throw new AzHttpError(400,'Invalid live connection description.','invalid_sdp');
    return $sdp;
}
/** v1.49 per-viewer monotonic nonce stamp; legacy random hex nonces return null.
 * The stamp is a client-side ordering hint, not identity or authentication;
 * current member auth and a cryptographic random suffix remain mandatory.
 */
function az_live_join_nonce_stamp(string $nonce,int $now): ?int
{
    if(!az_valid_id($nonce))return null;
    $ms=hexdec(substr($nonce,0,12));
    if(!is_numeric($ms))return null;
    $stamp=(int)$ms;
    // Treat out-of-range prefixes as legacy random nonces, not errors.
    if($stamp<($now-86400)*1000||$stamp>($now+86400)*1000)return null;
    return $stamp;
}
function az_live_join(int $viewer,string $roomId,string $nonce,mixed $sdp): array
{
    az_live_secure();
    if (!az_valid_id($roomId)||!az_valid_id($nonce)) throw new AzHttpError(400,'Invalid live session request.','invalid_live_id');
    $sdp=az_live_signal_sdp($sdp);$active=az_live_active_room();
    if (!$active || $active['id']!==$roomId) throw new AzHttpError(409,'This live program is no longer on air.','live_offline');
    $db=az_db();$lock=az_community_lock();
    try {
        $q=$db->prepare('SELECT * FROM az_live_viewers WHERE room_id=? AND user_id=? AND nonce=?');$q->execute([$roomId,$viewer,$nonce]);
        if($prior=$q->fetch()){
            if($prior['status']==='left')throw new AzHttpError(409,'That viewer Join is no longer active.','viewer_stale_join');
            return ['viewerId'=>$prior['id'],'room'=>az_live_room_public($active),'iceServers'=>az_live_ice_servers()];
        }
        // A cancelled, delayed Join may arrive after a newer Join. Reject it
        // BEFORE altering the newer viewer session or consuming a slot.
        $newStamp=az_live_join_nonce_stamp($nonce,time());
        if($newStamp!==null){
            $q=$db->prepare("SELECT nonce FROM az_live_viewers WHERE room_id=? AND user_id=? AND status IN ('waiting','connected')");
            $q->execute([$roomId,$viewer]);
            foreach($q->fetchAll(PDO::FETCH_COLUMN) as $currentNonce){
                $activeStamp=az_live_join_nonce_stamp((string)$currentNonce,time());
                if($activeStamp!==null&&$activeStamp>$newStamp)
                    throw new AzHttpError(409,'A newer viewer connection already exists.','viewer_stale_join');
            }
        }
        $db->prepare("UPDATE az_live_viewers SET status='left' WHERE room_id=? AND user_id=? AND status IN ('waiting','connected')")->execute([$roomId,$viewer]);
        if (az_live_viewer_count($roomId)>=AZ_LIVE_MAX_VIEWERS) throw new AzHttpError(429,'This live room is at its current connection limit.','viewer_limit');
        $id=az_id();$db->prepare("INSERT INTO az_live_viewers(id,room_id,user_id,nonce,offer_sdp,answer_sdp,status,updated_epoch,created_epoch) VALUES(?,?,?,?,?,NULL,'waiting',?,?)")
          ->execute([$id,$roomId,$viewer,$nonce,$sdp,time(),time()]);
        return ['viewerId'=>$id,'room'=>az_live_room_public($active),'iceServers'=>az_live_ice_servers()];
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function az_live_viewer_row(int $viewer,string $viewerId): array
{
    if (!az_valid_id($viewerId)) throw new AzHttpError(404,'Viewer connection not found.','viewer_missing');
    $q=az_db()->prepare('SELECT * FROM az_live_viewers WHERE id=? AND user_id=?');$q->execute([$viewerId,$viewer]);
    $row=$q->fetch();if (!$row)throw new AzHttpError(404,'Viewer connection not found.','viewer_missing');return $row;
}
function az_live_poll_answer(int $viewer,string $viewerId): array
{
    $row=az_live_viewer_row($viewer,$viewerId);
    $room=az_live_active_room();
    if (!$room || $room['id']!==$row['room_id'] || $row['status']==='left')
        throw new AzHttpError(409,'Broadcast ended.','live_offline');
    az_db()->prepare("UPDATE az_live_viewers SET updated_epoch=? WHERE id=? AND user_id=? AND status IN ('waiting','connected')")
      ->execute([time(),$viewerId,$viewer]);
    return ['answer'=>$row['answer_sdp'] ?: null,'status'=>$row['status'],'room'=>az_live_room_public($room)];
}
function az_live_leave(int $viewer,string $viewerId): array
{
    $row=az_live_viewer_row($viewer,$viewerId);
    az_db()->prepare("UPDATE az_live_viewers SET status='left',updated_epoch=? WHERE id=? AND user_id=?")->execute([time(),$viewerId,$viewer]);
    return ['left'=>true];
}
function az_live_host_offers(int $owner,string $roomId): array
{
    az_live_host_room($owner,$roomId);
    $q=az_db()->prepare("SELECT id,nonce,offer_sdp,status FROM az_live_viewers WHERE room_id=? AND status IN ('waiting','connected') AND updated_epoch>? ORDER BY created_epoch ASC LIMIT 12");
    $q->execute([$roomId,time()-AZ_LIVE_VIEWER_TTL]);
    return ['offers'=>array_map(static fn(array $r):array=>['id'=>$r['id'],'nonce'=>$r['nonce'],'offer'=>$r['offer_sdp'],'status'=>$r['status']],$q->fetchAll()),'viewerCount'=>az_live_viewer_count($roomId)];
}
function az_live_host_answer(int $owner,string $roomId,string $viewerId,string $nonce,mixed $sdp): array
{
    az_live_host_room($owner,$roomId);if (!az_valid_id($viewerId)||!az_valid_id($nonce))throw new AzHttpError(400,'Invalid viewer connection.','invalid_live_id');
    $sdp=az_live_signal_sdp($sdp);
    $q=az_db()->prepare("UPDATE az_live_viewers SET answer_sdp=?,status='connected',updated_epoch=? WHERE id=? AND room_id=? AND nonce=? AND status='waiting' AND updated_epoch>?");
    $q->execute([$sdp,time(),$viewerId,$roomId,$nonce,time()-AZ_LIVE_VIEWER_TTL]);
    if ($q->rowCount()!==1)throw new AzHttpError(409,'Viewer disconnected or reconnected.','viewer_changed');
    return ['answered'=>true];
}
/** v1.16: public broadcast archive is a separately approved publication surface.
 * Finished program files remain private until an administrator publishes them. */
function az_upgrade_live_archive_schema(PDO $db): void
{
    if (az_schema_version($db) >= 24 || az_schema_version($db) < 23) return;
    az_prepare_storage();
    $lock=fopen(AZ_STORAGE . '/schema-migration.lock','c+b');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Live archive migration lock is unavailable.');
    try {
        if (az_schema_version($db) >= 24) return;
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        if ($mysql) {
            $q=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $q->execute(['az_live_recordings']);$columns=$q->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $columns=array_column($db->query('PRAGMA table_info(az_live_recordings)')->fetchAll(PDO::FETCH_ASSOC),'name');
        }
        foreach (['title'=>"VARCHAR(180) NOT NULL DEFAULT ''",'description'=>'TEXT NULL',
           'published_at'=>'VARCHAR(32) NULL','cover_storage_name'=>'VARCHAR(80) NULL',
           'cover_mime_type'=>'VARCHAR(80) NULL','updated_at'=>'VARCHAR(32) NULL',
           'revision'=>'INTEGER NOT NULL DEFAULT 1'] as $column=>$definition) {
            if (!in_array($column,$columns,true)) $db->exec("ALTER TABLE az_live_recordings ADD COLUMN $column $definition");
        }
        // An upgrade preserves existing recordings as unpublished drafts.
        $db->exec("UPDATE az_live_recordings SET title=(SELECT m.title FROM personal_media m WHERE m.id=az_live_recordings.media_id) WHERE title='' OR title IS NULL");
        $db->exec("UPDATE az_live_recordings SET updated_at=recorded_at WHERE updated_at IS NULL");
        if ($mysql) {
            $q=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
            $q->execute(['az_live_recordings','live_archive_publish']);
            if (!(int)$q->fetchColumn()) $db->exec('CREATE INDEX live_archive_publish ON az_live_recordings(published_at,recorded_at)');
        } else {
            $db->exec('CREATE INDEX IF NOT EXISTS live_archive_publish ON az_live_recordings(published_at,recorded_at)');
        }
        $db->exec('UPDATE studio_schema SET version=24 WHERE id=1');
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
/** Version 25: published shows are members-only unless explicitly released publicly. */
function az_upgrade_live_public_schema(PDO $db): void
{
    if (az_schema_version($db) >= 25 || az_schema_version($db) < 24) return;
    az_prepare_storage();
    $lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Live audience migration lock is unavailable.');
    try {
        if (az_schema_version($db)>=25) return;
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        if ($mysql) {
            $q=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $q->execute(['az_live_recordings']);$columns=$q->fetchAll(PDO::FETCH_COLUMN);
        } else {$columns=array_column($db->query('PRAGMA table_info(az_live_recordings)')->fetchAll(PDO::FETCH_ASSOC),'name');}
        if (!in_array('publication_audience',$columns,true))
            $db->exec("ALTER TABLE az_live_recordings ADD COLUMN publication_audience VARCHAR(16) NOT NULL DEFAULT 'members'");
        // Existing published recordings stay member-only, even after this upgrade.
        $db->exec("UPDATE az_live_recordings SET publication_audience='members' WHERE publication_audience IS NULL OR publication_audience NOT IN ('members','public')");
        $db->exec('UPDATE studio_schema SET version=25 WHERE id=1');
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function az_live_recording_payload(array $row,bool $private=false): array
{
    $published=!empty($row['published_at']);
    $data=['id'=>$row['id'],'title'=>$row['title'] ?: 'AZHottie Live',
        'description'=>$row['description'] ?? '', 'recordedAt'=>$row['recorded_at'],
        'coverUrl'=>empty($row['cover_storage_name'])?null:az_url('live-recording-media.php?id='.$row['id'].'&type=cover'),
        'videoUrl'=>az_url('live-recording-media.php?id='.$row['id']),
        'published'=>$published, 'publishedAt'=>$row['published_at'] ?: null, 'audience'=>$row['publication_audience']??'members'];
    if ($private) {
        $data['ownerId']=(int)$row['owner_id'];$data['sizeBytes']=(int)$row['size_bytes'];
        $data['revision']=(int)$row['revision'];$data['mediaId']=$row['media_id'];
    }
    return $data;
}
function az_live_recordings(int $owner,bool $admin=false): array
{
    $db=az_db();$sql='SELECT r.*,m.size_bytes FROM az_live_recordings r JOIN personal_media m ON m.id=r.media_id AND m.user_id=r.owner_id';
    $sql.=$admin?'':' WHERE r.owner_id=?';
    $q=$db->prepare($sql.' ORDER BY r.recorded_at DESC LIMIT 100');$q->execute($admin?[]:[$owner]);
    return array_map(static fn(array $row):array=>az_live_recording_payload($row,true),$q->fetchAll());
}
function az_live_public_recordings(): array
{
    $q=az_db()->prepare("SELECT r.*,m.size_bytes FROM az_live_recordings r JOIN personal_media m ON m.id=r.media_id AND m.user_id=r.owner_id JOIN users u ON u.id=r.owner_id AND u.status='active' WHERE r.published_at IS NOT NULL ORDER BY r.published_at DESC,r.recorded_at DESC LIMIT 60");
    $q->execute();return array_map(static fn(array $r):array=>az_live_recording_payload($r),$q->fetchAll());
}
/** Separate unauthenticated gallery: neither member-only nor draft media is indexed. */
function az_live_open_recordings(): array
{
    $q=az_db()->prepare("SELECT r.*,m.size_bytes FROM az_live_recordings r JOIN personal_media m ON m.id=r.media_id AND m.user_id=r.owner_id JOIN users u ON u.id=r.owner_id AND u.status='active' WHERE r.published_at IS NOT NULL AND r.publication_audience='public' ORDER BY r.published_at DESC,r.recorded_at DESC LIMIT 60");
    $q->execute();return array_map(static fn(array $r):array=>az_live_recording_payload($r),$q->fetchAll());
}
function az_live_public_media_row(string $id): ?array
{
    if (!az_valid_id($id)) return null;
    $q=az_db()->prepare("SELECT r.*,m.storage_name,m.file_name,m.mime_type,m.size_bytes,u.status AS owner_status FROM az_live_recordings r JOIN personal_media m ON m.id=r.media_id AND m.user_id=r.owner_id JOIN users u ON u.id=r.owner_id AND u.status='active' WHERE r.id=? AND r.published_at IS NOT NULL AND r.publication_audience='public'");
    $q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}
function az_live_recording_row(string $id,int $actorId,bool $admin=false): array
{
    if (!az_valid_id($id)) throw new AzHttpError(404,'Live recording not found.','not_found');
    $q=az_db()->prepare('SELECT r.*,m.storage_name,m.file_name,m.mime_type,m.size_bytes,u.status AS owner_status FROM az_live_recordings r JOIN personal_media m ON m.id=r.media_id AND m.user_id=r.owner_id JOIN users u ON u.id=r.owner_id WHERE r.id=?');
    $q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if (!$row||(!$admin && $actorId!==(int)$row['owner_id'] && (empty($row['published_at']) || $row['owner_status']!=='active')))
        throw new AzHttpError(404,'Live recording not found.','not_found');
    return $row;
}
function az_live_recording_edit_row(string $id,int $owner,bool $admin=false): array
{
    $row=az_live_recording_row($id,$owner,$admin);
    if (!$admin && (int)$row['owner_id']!==$owner)throw new AzHttpError(403,'You cannot change this recording.','access_denied');
    return $row;
}
function az_live_recording_check_revision(array $body,array $row): void
{
    $revision=az_integer($body['revision']??0,1,2147483647,'Revision');
    if ($revision!==(int)$row['revision'])throw new AzHttpError(409,'This live production changed. Refresh before editing.','revision_conflict');
}
function az_live_recording_update(array $body,int $actor,bool $admin): array
{
    $row=az_live_recording_edit_row(az_text($body['id']??'',32,'Recording'),$actor,$admin);
    az_live_recording_check_revision($body,$row);
    $title=az_text($body['title']??'',180,'Title');
    if ($title==='')throw new AzHttpError(400,'Give your saved production a title.','title_required');
    $description=az_text($body['description']??'',1000,'Description');
    $q=az_db()->prepare('UPDATE az_live_recordings SET title=?,description=?,updated_at=?,revision=revision+1 WHERE id=? AND revision=?');
    $q->execute([$title,$description,az_now(),$row['id'],(int)$row['revision']]);
    if ($q->rowCount()!==1) throw new AzHttpError(409,'This production changed. Refresh before editing.','revision_conflict');
    return az_live_recording_payload(az_live_recording_row($row['id'],$actor,$admin),true);
}
function az_live_recording_publish(array $body,int $actor,bool $publish): array
{
    $row=az_live_recording_edit_row(az_text($body['id']??'',32,'Recording'),$actor,true);
    az_live_recording_check_revision($body,$row);
    if ($publish && !is_file(az_media_path($row)))throw new AzHttpError(409,'The recorded video is missing from storage.','media_missing');
    $q=az_db()->prepare('UPDATE az_live_recordings SET published_at=?,updated_at=?,revision=revision+1 WHERE id=? AND revision=?');
    $q->execute([$publish?az_now():null,az_now(),$row['id'],(int)$row['revision']]);
    if ($q->rowCount()!==1)throw new AzHttpError(409,'This production changed. Refresh before editing.','revision_conflict');
    return az_live_recording_payload(az_live_recording_row($row['id'],$actor,true),true);
}
/** Explicit audience change, administrator-only; public never follows a normal member publish. */
function az_live_recording_set_audience(array $body,int $adminId): array
{
    $row=az_live_recording_edit_row(az_text($body['id']??'',32,'Recording'),$adminId,true);
    az_live_recording_check_revision($body,$row);
    if (empty($row['published_at']))throw new AzHttpError(409,'Publish to members before selecting a public audience.','not_published');
    $audience=az_enum($body['audience']??'',['members','public'],'Audience');
    if ($audience==='public' && !is_file(az_media_path($row))) throw new AzHttpError(409,'The recorded video is missing from storage.','media_missing');
    $q=az_db()->prepare('UPDATE az_live_recordings SET publication_audience=?,updated_at=?,revision=revision+1 WHERE id=? AND revision=?');
    $q->execute([$audience,az_now(),$row['id'],(int)$row['revision']]);
    if ($q->rowCount()!==1)throw new AzHttpError(409,'This production changed. Refresh before editing.','revision_conflict');
    return az_live_recording_payload(az_live_recording_row($row['id'],$adminId,true),true);
}
function az_live_recording_cover_upload(array $body,int $actor,bool $admin): array
{
    $row=az_live_recording_edit_row(az_text($body['id']??'',32,'Recording'),$actor,$admin);
    az_live_recording_check_revision($body,$row);
    $file=az_uploaded_file('cover');$size=@filesize($file['tmp_name']);
    if ($size===false||$size<1||$size>5242880)throw new AzHttpError(413,'Choose an image up to 5 MB.','cover_too_large');
    $info=@getimagesize($file['tmp_name']);$mime=$info['mime']??null;
    if (!$info||!in_array($mime,['image/png','image/jpeg','image/webp'],true)||$info[0]<1||$info[1]<1||$info[0]>12000||$info[1]>12000||$info[0]*$info[1]>40000000)
       throw new AzHttpError(415,'Use a valid PNG, JPG, or WebP image (maximum 40 megapixels).','unsupported_cover');
    $storage=az_id().'.bin';$path=az_media_path(['storage_name'=>$storage]);
    if (!move_uploaded_file($file['tmp_name'],$path))throw new AzHttpError(507,'Unable to save the cover image.','storage_unavailable');
    @chmod($path,0640);
    try {
        $q=az_db()->prepare('UPDATE az_live_recordings SET cover_storage_name=?,cover_mime_type=?,updated_at=?,revision=revision+1 WHERE id=? AND revision=?');
        $q->execute([$storage,$mime,az_now(),$row['id'],(int)$row['revision']]);
        if ($q->rowCount()!==1)throw new AzHttpError(409,'The production changed. Refresh before uploading a cover.','revision_conflict');
    } catch(Throwable $e) {@unlink($path);throw $e;}
    if (!empty($row['cover_storage_name'])) @unlink(az_media_path(['storage_name'=>$row['cover_storage_name']]));
    return az_live_recording_payload(az_live_recording_row($row['id'],$actor,$admin),true);
}
function az_live_recording_cover_remove(array $body,int $actor,bool $admin): array
{
    $row=az_live_recording_edit_row(az_text($body['id']??'',32,'Recording'),$actor,$admin);
    az_live_recording_check_revision($body,$row);
    $q=az_db()->prepare('UPDATE az_live_recordings SET cover_storage_name=NULL,cover_mime_type=NULL,updated_at=?,revision=revision+1 WHERE id=? AND revision=?');
    $q->execute([az_now(),$row['id'],(int)$row['revision']]);
    if ($q->rowCount()!==1)throw new AzHttpError(409,'The production changed. Refresh before removing a cover.','revision_conflict');
    if (!empty($row['cover_storage_name']))@unlink(az_media_path(['storage_name'=>$row['cover_storage_name']]));
    return az_live_recording_payload(az_live_recording_row($row['id'],$actor,$admin),true);
}

function az_live_attach_recording(int $owner,string $roomId,string $mediaId): array
{
    az_live_host_room($owner,$roomId,true);
    $media=az_personal_media_row($mediaId,$owner);
    if (!str_starts_with((string)$media['mime_type'],'video/'))throw new AzHttpError(400,'Recording is not a supported video.','invalid_media');
    $q=az_db()->prepare('SELECT id FROM az_live_recordings WHERE media_id=?');$q->execute([$mediaId]);
    if ($prior=$q->fetchColumn())return ['recordingId'=>$prior,'mediaId'=>$mediaId];
    $title=(string)$media['title'];
    while (strlen($title)>180) $title=preg_replace('/.$/us','',$title) ?? '';
    if (trim($title)==='') $title='AZHottie Live';
    $id=az_id();$now=az_now();az_db()->prepare('INSERT INTO az_live_recordings(id,room_id,owner_id,media_id,recorded_at,title,description,updated_at,revision) VALUES(?,?,?,?,?,?,?,?,1)')
      ->execute([$id,$roomId,$owner,$mediaId,$now,$title,'',$now]);
    return ['recordingId'=>$id,'mediaId'=>$mediaId];
}
