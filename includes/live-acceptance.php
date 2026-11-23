<?php
declare(strict_types=1);
/**
 * v1.47 Live Broadcast Acceptance. Private operator attestations are stored
 * separately from read-only browser-reported transport telemetry. An attestation
 * is NOT independently verified physical-device or hosted-network acceptance.
 * No SDP, IP addresses, fan identities, images, video or sound are persisted.
 */
function az_upgrade_live_acceptance_schema(PDO $db):void
{
    if(az_schema_version($db)>=47||az_schema_version($db)<46)return;
    az_prepare_storage();
    $lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Live acceptance schema lock unavailable.');
    try{
        if(az_schema_version($db)>=47)return;
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $uid=$mysql?'BIGINT UNSIGNED':'INTEGER';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS az_live_acceptance_checks (
            room_id CHAR(32) NOT NULL,step VARCHAR(32) NOT NULL,result VARCHAR(12) NOT NULL,
            note VARCHAR(240) NOT NULL DEFAULT '',actor_id $uid NOT NULL,
            revision INTEGER NOT NULL DEFAULT 1,updated_at VARCHAR(32) NOT NULL,
            PRIMARY KEY(room_id,step),
            FOREIGN KEY(room_id) REFERENCES az_live_rooms(id) ON DELETE CASCADE,
            FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS az_live_acceptance_events (
            id CHAR(32) PRIMARY KEY,room_id CHAR(32) NOT NULL,step VARCHAR(32) NOT NULL,
            from_result VARCHAR(12) NOT NULL,to_result VARCHAR(12) NOT NULL,
            note VARCHAR(240) NOT NULL DEFAULT '',actor_id $uid NOT NULL,created_at VARCHAR(32) NOT NULL,
            FOREIGN KEY(room_id) REFERENCES az_live_rooms(id) ON DELETE CASCADE,
            FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        if($mysql){
            $db->exec('CREATE INDEX az_live_acceptance_audit ON az_live_acceptance_events(room_id,created_at)');
        }else{
            $db->exec('CREATE INDEX IF NOT EXISTS az_live_acceptance_audit ON az_live_acceptance_events(room_id,created_at)');
        }
        $db->exec('UPDATE studio_schema SET version=47 WHERE id=1');
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function az_live_acceptance_steps():array
{
    return [
      'phone_pairing'=>['title'=>'Approved phone connection','instruction'=>'Pair a real phone by QR and confirm it is approved and actively sharing'],
      'moving_preview'=>['title'=>'Moving phone preview','instruction'=>'Watch continuous moving camera frames in the Director preview, not a frozen first frame'],
      'program_switch'=>['title'=>'Program source switch','instruction'=>'Take the approved phone to Program and verify the live Program picture changes'],
      'remote_fan'=>['title'=>'Remote fan playback','instruction'=>'Watch Program from a second signed-in device on a different network or cellular connection'],
      'fan_audio'=>['title'=>'Fan audio playback','instruction'=>'Enable the intended microphone and confirm the remote fan can unmute and hear it'],
      'recording_playback'=>['title'=>'Saved recording playback','instruction'=>'Record Program, verify the saved private file, and play the resulting recording with audio'],
      'interruption_recovery'=>['title'=>'Interrupted-feed recovery','instruction'=>'Interrupt a test phone/video connection and verify controlled reconnection or an operator-safe holding slate']
    ];
}
function az_live_acceptance_assert(array $actor):void
{
    if((int)($actor['id']??0)<1||!az_has_role($actor,'admin')||($actor['status']??'active')!=='active')
        throw new AzHttpError(403,'Only an active studio administrator can manage broadcast acceptance.','role_required');
}
/** Restrict both read and write to a real broadcast owned by this administrator. */
function az_live_acceptance_room(PDO $db,array $actor,?string $id,int $now):?array
{
    az_live_acceptance_assert($actor);
    if($id!==null){
        if(!az_valid_id($id))throw new AzHttpError(400,'Invalid broadcast reference.','invalid_room');
        $q=$db->prepare('SELECT * FROM az_live_rooms WHERE id=? AND owner_id=?');
        $q->execute([$id,(int)$actor['id']]);
    }else{
        $q=$db->prepare('SELECT * FROM az_live_rooms WHERE owner_id=? ORDER BY started_epoch DESC,id DESC LIMIT 1');
        $q->execute([(int)$actor['id']]);
    }
    $room=$q->fetch(PDO::FETCH_ASSOC);
    if($room===false){
        if($id!==null)throw new AzHttpError(404,'Broadcast not found for this administrator.','room_not_found');
        return null;
    }
    return $room;
}
function az_live_acceptance_overview(?array $director,array $viewers,int $phoneCount,int $offeringCount,int $recordingCount,int $maxBytes,int $now):array
{
    $playing=0;$stalled=0;$blocked=0;$decoded=[];
    foreach($viewers as $row){
        if(($row['video_state']??'')==='playing')$playing++;
        if(($row['video_state']??'')==='stalled')$stalled++;
        if(($row['video_state']??'')==='blocked')$blocked++;
        if(($row['decoded_fps']??null)!==null&&is_numeric($row['decoded_fps']))
            $decoded[]=max(0,min(120,(float)$row['decoded_fps']));
    }
    $directorAge=$director?max(0,$now-(int)($director['updated_epoch']??0)):null;
    return ['phoneApproved'=>$phoneCount,'phoneOffering'=>$offeringCount,
      'directorFresh'=>$director!==null,
      'directorAgeSeconds'=>$directorAge,
      'healthyCameras'=>$director?(int)$director['camera_healthy']:null,
      'cameraCount'=>$director?(int)$director['camera_count']:null,
      'programMode'=>$director?$director['program_mode']:null,
      'sentFps'=>$director&&$director['sent_fps']!==null?(float)$director['sent_fps']:null,
      'viewerCount'=>$director?(int)$director['viewer_count']:null,
      'outboundStalls'=>$director?(int)$director['stalled_outbound']:null,
      'fanReporting'=>count($viewers),'fanPlaying'=>$playing,'fanStalled'=>$stalled,'fanBlocked'=>$blocked,
      'fanDecodedFpsMax'=>$decoded?round(max($decoded),1):null,
      'savedRecordings'=>$recordingCount,'largestSavedBytes'=>$maxBytes,
      'measurementBasis'=>'Recent numeric browser telemetry (at most 35 seconds old). Approved camera and live offer counts do not prove decoded frames. A saved media record does not prove the recording plays. Remote-network conditions, sound, and physical camera behavior require operator testing.'];
}
function az_live_acceptance_completion(array $checks):array
{
    $passed=0;$failed=0;$total=count(az_live_acceptance_steps());
    foreach(az_live_acceptance_steps() as $key=>$_){
        $result=(string)($checks[$key]['result']??'untested');
        if($result==='pass')$passed++;
        elseif($result==='fail')$failed++;
    }
    return ['total'=>$total,'operatorPassed'=>$passed,'operatorFailed'=>$failed,
      'unverifiedSteps'=>max(0,$total-$passed-$failed),
      'status'=>$passed===$total?'operator_attested':($passed||$failed?'incomplete':'not_started'),
      'hostedIndependentlyVerified'=>false,
      'basis'=>'A complete checklist means the operator attested each step. It does not establish independent hardware, cellular, TURN, audio, or hosted recording verification.'];
}
/** Sensitive status endpoint for Director owner only; no personal viewer metadata. */
function az_live_acceptance_status(array $actor,?string $roomId=null,?PDO $db=null,?int $now=null):array
{
    az_live_acceptance_assert($actor);
    $db??=az_db();$now??=time();
    $room=az_live_acceptance_room($db,$actor,$roomId,$now);
    $steps=az_live_acceptance_steps();
    if(!$room){
        return ['room'=>null,'steps'=>$steps,'checks'=>[],'completion'=>az_live_acceptance_completion([]),
          'diagnostics'=>null,'history'=>[],
          'triage'=>az_live_triage_analyze(null,null,[]),
          'supportSummary'=>az_live_triage_support_summary(null,null,az_live_triage_analyze(null,null,[]),az_live_acceptance_completion([])),
          'checkedAt'=>gmdate('Y-m-d\TH:i:s\Z',$now),
          'note'=>'Start a live broadcast to create an operator acceptance checklist. No camera or viewer measurements are available.'];
    }
    $roomId=(string)$room['id'];
    $live=$room['status']==='live'&&(int)$room['heartbeat_epoch']>$now-AZ_LIVE_HOST_TTL;
    $q=$db->prepare('SELECT step,result,note,revision,updated_at FROM az_live_acceptance_checks WHERE room_id=?');
    $q->execute([$roomId]);$checks=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        if(!isset($steps[$row['step']]))continue;
        $checks[$row['step']]=['result'=>$row['result'],'note'=>$row['note'],
          'revision'=>(int)$row['revision'],'updatedAt'=>$row['updated_at']];
    }
    $q=$db->prepare('SELECT step,from_result,to_result,created_at FROM az_live_acceptance_events WHERE room_id=? ORDER BY created_at DESC,id DESC LIMIT 20');
    $q->execute([$roomId]);$history=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $event)
        if(isset($steps[$event['step']]))$history[]=['step'=>$event['step'],'from'=>$event['from_result'],
          'to'=>$event['to_result'],'at'=>$event['created_at']];
    $director=null;$viewers=[];
    if($live){
        [$director,$viewers]=az_live_agent_fresh_rows($db,$roomId,$now);
    }
    $phones=$db->prepare("SELECT COUNT(DISTINCT d.id) AS approved,
      COUNT(DISTINCT CASE WHEN l.updated_epoch>? THEN d.id END) AS offering
      FROM personal_phone_devices d LEFT JOIN personal_phone_live l ON l.device_id=d.id
      WHERE d.owner_id=? AND d.status='approved' AND d.expires_epoch>?");
    $phones->execute([$now-40,(int)$room['owner_id'],$now]);
    $p=$phones->fetch(PDO::FETCH_ASSOC)?:[];
    $saved=$db->prepare('SELECT COUNT(*) AS n,COALESCE(MAX(m.size_bytes),0) AS bytes
      FROM az_live_recordings r JOIN personal_media m ON m.id=r.media_id AND m.user_id=r.owner_id WHERE r.room_id=?');
    $saved->execute([$roomId]);$recordings=$saved->fetch(PDO::FETCH_ASSOC)?:[];
    $diag=az_live_acceptance_overview($director,$viewers,
          $live?(int)($p['approved']??0):0,$live?(int)($p['offering']??0):0,
          (int)($recordings['n']??0),(int)($recordings['bytes']??0),$now);
    $roomStatus=['status'=>$live?'live':'ended'];
    $completion=az_live_acceptance_completion($checks);
    $triage=az_live_triage_analyze($roomStatus,$diag,$checks);
    return [
      'room'=>['id'=>$roomId,'title'=>$room['title'],'status'=>$live?'live':'ended',
         'startedAt'=>gmdate('Y-m-d\TH:i:s\Z',(int)$room['started_epoch'])],
      'steps'=>$steps,'checks'=>$checks,
      'completion'=>$completion,
      'diagnostics'=>$diag,
      'triage'=>$triage,'supportSummary'=>az_live_triage_support_summary($roomStatus,$diag,$triage,$completion),
      'history'=>$history,'checkedAt'=>gmdate('Y-m-d\TH:i:s\Z',$now),
      'note'=>'Read-only live measurements plus separate operator attestations. Neither alone establishes independently verified real-device acceptance.'];
}
/** Only explicit administrator clicks can mark a step. Revision avoids lost notes. */
function az_live_acceptance_mark(array $actor,array $body,?PDO $db=null,?int $now=null):array
{
    az_live_acceptance_assert($actor);$db??=az_db();$now??=time();
    $roomId=az_text($body['roomId']??null,32,'Broadcast reference');
    $step=az_text($body['step']??null,32,'Acceptance step');
    $result=az_text($body['result']??null,12,'Test result');
    $revision=az_integer($body['revision']??null,0,PHP_INT_MAX,'Revision');
    $note=az_text($body['note']??'',240,'Test observation');
    if(!isset(az_live_acceptance_steps()[$step]))throw new AzHttpError(400,'Unknown broadcast acceptance step.','invalid_step');
    if(!in_array($result,['pass','fail','untested'],true))
        throw new AzHttpError(400,'Choose pass, fail or untested.','invalid_result');
    if($result!=='untested'&&strlen($note)<12)
        throw new AzHttpError(400,'Describe the actual device or playback observation (at least 12 characters).','observation_required');
    if(preg_match('/[\x00-\x08\x0B-\x1F\x7F]/',$note))
        throw new AzHttpError(400,'Remove unsupported characters from the observation.','invalid_note');
    $room=az_live_acceptance_room($db,$actor,$roomId,$now);
    if(!$room)throw new AzHttpError(404,'Broadcast not found.','room_not_found');
    if((int)$room['started_epoch']<$now-7*86400)
        throw new AzHttpError(409,'Acceptance notes are editable only within seven days of the broadcast.','test_window_expired');
    $stamp=gmdate('Y-m-d\TH:i:s\Z',$now);
    $db->beginTransaction();
    try{
        $q=$db->prepare('SELECT result,revision FROM az_live_acceptance_checks WHERE room_id=? AND step=?');
        $q->execute([$roomId,$step]);$prior=$q->fetch(PDO::FETCH_ASSOC);
        $actual=$prior?(int)$prior['revision']:0;
        if($actual!==$revision)throw new AzHttpError(409,'Another operator updated this step. Refresh before saving.','revision_conflict');
        if($prior){
            $q=$db->prepare('UPDATE az_live_acceptance_checks SET result=?,note=?,actor_id=?,revision=revision+1,updated_at=? WHERE room_id=? AND step=? AND revision=?');
            $q->execute([$result,$note,(int)$actor['id'],$stamp,$roomId,$step,$revision]);
            if($q->rowCount()!==1)throw new AzHttpError(409,'Acceptance record changed. Refresh.','revision_conflict');
        }else{
            $q=$db->prepare('INSERT INTO az_live_acceptance_checks(room_id,step,result,note,actor_id,revision,updated_at) VALUES(?,?,?,?,?,1,?)');
            $q->execute([$roomId,$step,$result,$note,(int)$actor['id'],$stamp]);
        }
        $db->prepare('INSERT INTO az_live_acceptance_events(id,room_id,step,from_result,to_result,note,actor_id,created_at) VALUES(?,?,?,?,?,?,?,?)')
            ->execute([az_id(),$roomId,$step,$prior['result']??'untested',$result,$note,(int)$actor['id'],$stamp]);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    return ['step'=>$step,'result'=>$result,'revision'=>$revision+1,'updatedAt'=>$stamp,
      'operatorAttested'=>true,'independentlyVerified'=>false];
}
