<?php
declare(strict_types=1);
/**
 * Live Director Specialist. Reports numeric, account-validated WebRTC health only.
 * Neither streams, SDP, camera identifiers, phone labels, chat nor media are stored.
 * This diagnostic service NEVER operates a camera, changes Program, or ends a show.
 */
function az_upgrade_live_agent_schema(PDO $db): void
{
    if (az_schema_version($db)>=31 || az_schema_version($db)<30) return;
    az_prepare_storage();
    $lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Cannot lock Live Agent schema upgrade.');
    try {
        if (az_schema_version($db)>=31) return;
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $uid=$mysql?'BIGINT UNSIGNED':'INTEGER';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS az_live_agent_samples (
          room_id CHAR(32) NOT NULL, source VARCHAR(12) NOT NULL, connection_id CHAR(32) NOT NULL,
          reporter_id $uid NOT NULL, updated_epoch BIGINT NOT NULL,
          camera_count INTEGER NOT NULL DEFAULT 0, camera_healthy INTEGER NOT NULL DEFAULT 0,
          camera_on_program INTEGER NOT NULL DEFAULT 0, camera_fault INTEGER NOT NULL DEFAULT 0, program_mode VARCHAR(16) NOT NULL DEFAULT 'slate',
          viewer_count INTEGER NOT NULL DEFAULT 0, sent_fps DECIMAL(8,2) NULL,
          stalled_outbound INTEGER NOT NULL DEFAULT 0, direct_relays INTEGER NOT NULL DEFAULT 0,
          decoded_fps DECIMAL(8,2) NULL, video_state VARCHAR(16) NOT NULL DEFAULT 'unknown',
          page_hidden INTEGER NOT NULL DEFAULT 0,
          PRIMARY KEY (room_id,source,connection_id),
          FOREIGN KEY (room_id) REFERENCES az_live_rooms(id) ON DELETE CASCADE,
          FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS az_live_agent_incidents (
          room_id CHAR(32) NOT NULL, code VARCHAR(40) NOT NULL, opened_epoch BIGINT NOT NULL,
          last_seen_epoch BIGINT NOT NULL, observations INTEGER NOT NULL DEFAULT 1,
          resolved_epoch BIGINT NULL, last_alert_epoch BIGINT NULL,
          PRIMARY KEY (room_id,code),
          FOREIGN KEY (room_id) REFERENCES az_live_rooms(id) ON DELETE CASCADE)$suffix");
        if ($mysql) {
            $db->exec('CREATE INDEX az_live_agent_sample_age ON az_live_agent_samples (updated_epoch)');
            $db->exec('CREATE INDEX az_live_agent_incident_age ON az_live_agent_incidents (last_seen_epoch)');
        } else {
            $db->exec('CREATE INDEX IF NOT EXISTS az_live_agent_sample_age ON az_live_agent_samples (updated_epoch)');
            $db->exec('CREATE INDEX IF NOT EXISTS az_live_agent_incident_age ON az_live_agent_incidents (last_seen_epoch)');
        }
        $db->exec('UPDATE studio_schema SET version=31 WHERE id=1');
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}

/** Constrain all telemetry to short enums and finite, bounded numeric measurements. */
function az_live_agent_int(mixed $value,int $max): int
{
    if (!is_int($value) && !is_float($value)) return 0;
    if (!is_finite((float)$value)) return 0;
    return max(0,min($max,(int)$value));
}
function az_live_agent_fps(mixed $value): ?float
{
    if ($value===null || (!is_int($value)&&!is_float($value)) || !is_finite((float)$value))return null;
    return round(max(0,min(120,(float)$value)),2);
}
/** Pure diagnostic rules, easy to test with numeric synthetic data. */
function az_live_agent_assess(?array $director,array $viewers): array
{
    $issues=[];$notes=[];
    if ($director) {
        if ((int)($director['camera_fault']??0)===1 || ((int)$director['camera_on_program']===1 && (int)$director['camera_healthy']===0))
            $issues['camera_missing']=['title'=>'On-air camera has no healthy frames','detail'=>'The selected phone is not providing playable video to Director.','advice'=>'Open Live Director, check Share live on the phone, then deliberately take a verified camera or the holding slate.','severity'=>'critical'];
        if ((int)$director['viewer_count']>0 && (int)$director['stalled_outbound']>0)
            $issues['outbound_stall']=['title'=>'Program delivery has stalled','detail'=>'Director reports that one or more viewer video senders stopped advancing frames.','advice'=>'Check Program sent FPS. For a single camera, use the operator-approved direct relay recovery. Verify the fan player afterwards.','severity'=>'critical'];
        if ((int)$director['page_hidden']===1 && in_array($director['program_mode'],['composition','media'],true))
            $notes[]='Director is backgrounded. Multicamera overlays and prerecorded video may be throttled; keep the Director visible.';
        if ((int)$director['direct_relays']>0)
            $notes[]='A direct camera relay is in use. Some viewers may not see composited overlays; verify before recording.';
    }
    $frozen=0;$blocked=0;
    foreach ($viewers as $viewer) {
        if ($viewer['video_state']==='stalled')$frozen++;
        if ($viewer['video_state']==='blocked')$blocked++;
    }
    if ($frozen) $issues['viewer_freeze']=['title'=>'Fan playback reports frozen frames',
      'detail'=>$frozen.' active viewer '.($frozen===1?'session reports':'sessions report').' no newly decoded video frames.',
      'advice'=>'Compare Director incoming camera FPS with Program sent FPS; ask the viewer to rejoin only after confirming the outbound stream.','severity'=>'critical'];
    if ($blocked) $issues['viewer_blocked']=['title'=>'Fan playback could not start',
      'detail'=>$blocked.' viewer '.($blocked===1?'session reports':'sessions report').' blocked video playback.',
      'advice'=>'Ask the fan to use the video Play control; the viewer starts muted to respect autoplay restrictions.','severity'=>'warning'];
    return ['issues'=>$issues,'notes'=>$notes];
}

function az_live_agent_fresh_rows(PDO $db,string $roomId,int $now): array
{
    // Ignore fan reports after the viewer leaves or its signaling lease expires.
    $q=$db->prepare("SELECT s.* FROM az_live_agent_samples s
      LEFT JOIN az_live_viewers v ON s.source='viewer' AND v.id=s.connection_id
        AND v.room_id=s.room_id AND v.status='connected' AND v.updated_epoch>?
      WHERE s.room_id=? AND s.updated_epoch>? AND (s.source='director' OR v.id IS NOT NULL)
      ORDER BY s.updated_epoch DESC");
    $q->execute([$now-AZ_LIVE_VIEWER_TTL,$roomId,$now-35]);
    $director=null;$viewers=[];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['source']==='director' && $director===null)$director=$row;
        elseif ($row['source']==='viewer')$viewers[]=$row;
    }
    return [$director,$viewers];
}
function az_live_agent_emit_incident(int $owner,string $code,array $details,bool $recovered=false): void
{
    $title=$recovered?'Live issue cleared: '.$details['title']:$details['title'];
    $detail=$recovered?'A later live health check no longer reports this issue. Recheck the fan feed before declaring the show healthy.':$details['detail'].' '.$details['advice'];
    foreach (az_agent_workspace_admin_ids() as $admin) {
        // Only secured admin notifications; never expose a fan's identity or raw video.
        az_agent_workspace_emit($owner,$admin,$recovered?'live_recovered':'live_'.$code,$title,$detail,'live-director.php',!$recovered);
    }
}
/** Stable two-observation alert gate, with a 15-minute recurrence cooldown. */
function az_live_agent_incident_step(?array $old,int $now): array
{
    if ($old===null)return ['opened'=>$now,'observations'=>1,'alertEpoch'=>null,'notify'=>false];
    $reopened=$old['resolved_epoch']!==null;
    $opened=$reopened?$now:(int)$old['opened_epoch'];
    $prior=$old['last_alert_epoch']===null?null:(int)$old['last_alert_epoch'];
    if ($reopened && $prior!==null && $now-$prior>=900)$prior=null;
    $observations=$reopened?1:min(100000,(int)$old['observations']+1);
    $notify=!$reopened && $observations>=2 && $prior===null;
    return ['opened'=>$opened,'observations'=>$observations,
      'alertEpoch'=>$notify?$now:$prior,'notify'=>$notify];
}
/** Serialized report writers ensure no double-alerts under concurrent viewers. */
function az_live_agent_reconcile(PDO $db,array $room,int $now): void
{
    [$director,$viewers]=az_live_agent_fresh_rows($db,$room['id'],$now);
    $finding=az_live_agent_assess($director,$viewers)['issues'];
    $q=$db->prepare('SELECT * FROM az_live_agent_incidents WHERE room_id=?');$q->execute([$room['id']]);
    $previous=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$previous[$row['code']]=$row;
    foreach($finding as $code=>$details){
        $old=$previous[$code]??null;
        if (!$old) {
            $db->prepare('INSERT INTO az_live_agent_incidents(room_id,code,opened_epoch,last_seen_epoch,observations,resolved_epoch,last_alert_epoch) VALUES(?,?,?,?,1,NULL,NULL)')
               ->execute([$room['id'],$code,$now,$now]);
            continue;
        }
        $step=az_live_agent_incident_step($old,$now);
        $db->prepare('UPDATE az_live_agent_incidents SET opened_epoch=?,last_seen_epoch=?,observations=?,resolved_epoch=NULL,last_alert_epoch=? WHERE room_id=? AND code=?')
           ->execute([$step['opened'],$now,$step['observations'],$step['alertEpoch'],$room['id'],$code]);
        if ($step['notify'])az_live_agent_emit_incident((int)$room['owner_id'],$code,$details);
    }
    foreach($previous as $code=>$old){
        if ($old['resolved_epoch']!==null || isset($finding[$code]))continue;
        // Don't treat missing telemetry as a recovery. A healthy, fresh report of
        // the relevant side is needed to clear an incident.
        $fanIssue=in_array($code,['viewer_freeze','viewer_blocked'],true);
        if ($fanIssue && !array_filter($viewers,static fn($v)=>$v['video_state']==='playing'))continue;
        if (!$fanIssue && !$director)continue;
        if ($code==='camera_missing' && $director && ((int)$director['camera_fault']===1 || (int)$director['camera_healthy']<1))continue;
        $db->prepare('UPDATE az_live_agent_incidents SET resolved_epoch=? WHERE room_id=? AND code=? AND resolved_epoch IS NULL')->execute([$now,$room['id'],$code]);
        if ($old['last_alert_epoch'] && (int)$old['last_alert_epoch']>=(int)$old['opened_epoch']) {
            $details=['title'=>match($code){'camera_missing'=>'On-air camera has no healthy frames','outbound_stall'=>'Program delivery has stalled','viewer_freeze'=>'Fan playback reports frozen frames','viewer_blocked'=>'Fan playback could not start',default=>'Live health issue'}];
            az_live_agent_emit_incident((int)$room['owner_id'],$code,$details,true);
        }
    }
}
function az_live_agent_report(array $actor,array $body): array
{
    $source=$body['source']??null;
    if (!in_array($source,['director','viewer'],true))throw new AzHttpError(400,'Invalid live health source.','health_source');
    $roomId=$body['roomId']??null;
    if (!is_string($roomId)||!az_valid_id($roomId))throw new AzHttpError(400,'Invalid live health room.','health_room');
    $active=az_live_active_room();
    if (!$active || $active['id']!==$roomId)throw new AzHttpError(409,'This broadcast is no longer live.','live_offline');
    $actorId=(int)$actor['id'];
    if ($source==='director') {
        az_live_require_editor(true);
        if ((int)$active['owner_id']!==$actorId)throw new AzHttpError(403,'Only the active Director may report program health.','health_forbidden');
        $connectionId=$roomId;
    } else {
        $connectionId=$body['viewerId']??null;
        if (!is_string($connectionId)||!az_valid_id($connectionId))throw new AzHttpError(400,'Missing viewer session.','health_viewer');
        $viewer=az_live_viewer_row($actorId,$connectionId);
        if ($viewer['room_id']!==$roomId || $viewer['status']!=='connected' || (int)$viewer['updated_epoch']<time()-AZ_LIVE_VIEWER_TTL)
            throw new AzHttpError(403,'Only an active connected viewer can report playback health.','health_forbidden');
    }
    $input=$body['metrics']??null;
    if (!is_array($input) || count($input)>16)throw new AzHttpError(400,'Invalid live health measurements.','health_metrics');
    $mode=$input['programMode']??'slate';
    if(!in_array($mode,['single','composition','media','slate'],true))$mode='slate';
    $state=$input['videoState']??'unknown';
    if(!in_array($state,['playing','stalled','blocked','connecting','unknown'],true))$state='unknown';
    $values=['camera_count'=>az_live_agent_int($input['cameraCount']??0,20),
      'camera_healthy'=>az_live_agent_int($input['healthyCameras']??0,20),
      'camera_on_program'=>($input['cameraOnProgram']??null)===true?1:0,
      'camera_fault'=>($input['cameraFault']??null)===true?1:0,
      'program_mode'=>$mode,'viewer_count'=>az_live_agent_int($input['viewerCount']??0,AZ_LIVE_MAX_VIEWERS),
      'sent_fps'=>az_live_agent_fps($input['sentFps']??null),
      'stalled_outbound'=>az_live_agent_int($input['stalledOutbound']??0,AZ_LIVE_MAX_VIEWERS),
      'direct_relays'=>az_live_agent_int($input['directRelays']??0,AZ_LIVE_MAX_VIEWERS),
      'decoded_fps'=>az_live_agent_fps($input['decodedFps']??null),
      'video_state'=>$state,'page_hidden'=>($input['pageHidden']??null)===true?1:0];
    // Viewers may only report their playback state. Never trust them for Director FPS,
    // camera counts, show layout, or sender/relay health.
    if ($source==='viewer') {
        foreach (['camera_count','camera_healthy','camera_on_program','camera_fault','viewer_count','stalled_outbound','direct_relays'] as $key)$values[$key]=0;
        $values['sent_fps']=null;$values['program_mode']='slate';
    } else {$values['decoded_fps']=null;$values['video_state']='unknown';}
    $db=az_db();$now=time();$db->beginTransaction();
    try {
        $q=$db->prepare('SELECT updated_epoch FROM az_live_agent_samples WHERE room_id=? AND source=? AND connection_id=?');
        $q->execute([$roomId,$source,$connectionId]);$last=$q->fetchColumn();
        if ($last!==false && $now-(int)$last<8){$db->commit();return ['received'=>true,'rateLimited'=>true];}
        $columns=array_keys($values);
        if ($last!==false){
            $update=$db->prepare('UPDATE az_live_agent_samples SET updated_epoch=?,'.implode(',',array_map(static fn($key)=>$key.'=?',$columns)).' WHERE room_id=? AND source=? AND connection_id=?');
            $update->execute(array_merge([$now],array_values($values),[$roomId,$source,$connectionId]));
        } else {
            $q=$db->prepare('INSERT INTO az_live_agent_samples(room_id,source,connection_id,reporter_id,updated_epoch,'.implode(',',$columns).') VALUES('.implode(',',array_fill(0,5+count($columns),'?')).')');
            $q->execute(array_merge([$roomId,$source,$connectionId,$actorId,$now],array_values($values)));
        }
        az_live_agent_reconcile($db,$active,$now);
        $db->commit();
    } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    return ['received'=>true,'rateLimited'=>false];
}
/** Admin-only read model. Unknown/stale telemetry is never presented as healthy. */
function az_live_agent_status(array $actor): array
{
    if (!az_has_role($actor,'admin'))throw new AzHttpError(403,'Admin access required for live diagnostics.','role_required');
    $room=az_live_active_room();
    if (!$room)return ['state'=>'off_air','room'=>null,'issues'=>[],'notes'=>[],'checkedAt'=>az_now()];
    [$director,$viewers]=az_live_agent_fresh_rows(az_db(),$room['id'],time());
    $report=az_live_agent_assess($director,$viewers);
    $q=az_db()->prepare('SELECT code,observations,opened_epoch,last_seen_epoch FROM az_live_agent_incidents WHERE room_id=? AND resolved_epoch IS NULL ORDER BY last_seen_epoch DESC LIMIT 10');
    $q->execute([$room['id']]);$evidence=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $incident){
        if (isset($report['issues'][$incident['code']]))$evidence[$incident['code']]=(int)$incident['observations'];
    }
    $issues=[];foreach($report['issues'] as $code=>$issue)$issues[]=['code'=>$code]+$issue+['observations'=>$evidence[$code]??1];
    $playing=0;$stalled=0;foreach($viewers as $v){if($v['video_state']==='playing')$playing++;if($v['video_state']==='stalled')$stalled++;}
    return [
      'state'=>$issues?'attention':(!$director?'unverified':'observed'),
      'room'=>['title'=>$room['title'],'startedEpoch'=>(int)$room['started_epoch'],
          'youAreDirectorOwner'=>(int)$room['owner_id']===(int)$actor['id']],
      'director'=>['ageSeconds'=>$director?max(0,time()-(int)$director['updated_epoch']):null,
        'cameraCount'=>$director?(int)$director['camera_count']:null,
        'healthyCameras'=>$director?(int)$director['camera_healthy']:null,
        'viewerCount'=>$director?(int)$director['viewer_count']:null,
        'sentFps'=>$director&&$director['sent_fps']!==null?(float)$director['sent_fps']:null,
        'stalledOutbound'=>$director?(int)$director['stalled_outbound']:null,
        'directRelays'=>$director?(int)$director['direct_relays']:null,
        'programMode'=>$director?$director['program_mode']:null,
        'backgrounded'=>$director?(bool)$director['page_hidden']:null],
      'viewers'=>['reporting'=>count($viewers),'playing'=>$playing,'stalled'=>$stalled],
      'issues'=>$issues,'notes'=>$report['notes'],'checkedAt'=>az_now(),
      'basis'=>'Browser-reported WebRTC and decoded-frame telemetry, refreshed while clients are active. Missing or stale measurements are unverified, not healthy.'];
}

/** Retain only 30 days of numeric diagnostics. Does not affect shows or recordings. */
function az_live_agent_prune(): array
{
    $db=az_db();$before=time()-30*86400;
    $q=$db->prepare('DELETE FROM az_live_agent_samples WHERE updated_epoch<?');$q->execute([$before]);$samples=$q->rowCount();
    $q=$db->prepare('DELETE FROM az_live_agent_incidents WHERE last_seen_epoch<?');$q->execute([$before]);
    return ['oldSamplesDeleted'=>$samples,'oldIncidentsDeleted'=>$q->rowCount()];
}
