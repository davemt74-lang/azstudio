<?php
declare(strict_types=1);
/** Owner-scoped, non-destructive editable personal video timelines. No publication side effects. */
function az_upgrade_personal_edits_schema(PDO $db): void
{
    if (az_schema_version($db) >= 19) return;
    az_prepare_storage();
    $lock = fopen(AZ_STORAGE . '/schema-migration.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Personal editor migration lock unavailable.');
    try {
        if (az_schema_version($db) >= 19) return;
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $user = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $long = $mysql ? 'LONGTEXT' : 'TEXT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $db->exec("CREATE TABLE IF NOT EXISTS personal_edit_projects (
          id CHAR(32) PRIMARY KEY, user_id $user NOT NULL, title VARCHAR(160) NOT NULL,
          timeline_json $long NOT NULL, revision INTEGER NOT NULL DEFAULT 1,
          created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL,
          FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        if ($mysql) {
            $q=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
            $q->execute(['personal_edit_projects','personal_edits_owner']);
            if (!(int)$q->fetchColumn()) $db->exec('CREATE INDEX personal_edits_owner ON personal_edit_projects(user_id, updated_at)');
        } else {
            $db->exec('CREATE INDEX IF NOT EXISTS personal_edits_owner ON personal_edit_projects(user_id, updated_at)');
        }
        $db->exec('UPDATE studio_schema SET version=19 WHERE id=1');
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function az_personal_edit_project_row(string $id, int $owner): array
{
    if (!az_valid_id($id)) throw new AzHttpError(404,'Edit project not found.','not_found');
    $q=az_db()->prepare('SELECT * FROM personal_edit_projects WHERE id=? AND user_id=?');
    $q->execute([$id,$owner]);$row=$q->fetch();
    if (!$row) throw new AzHttpError(404,'Edit project not found.','not_found');
    return $row;
}

function az_personal_edit_project_data(array $row): array
{
    $timeline=json_decode((string)$row['timeline_json'],true);
    return [
        'id'=>$row['id'],'name'=>$row['title'],'revision'=>(int)$row['revision'],
        'timeline'=>is_array($timeline)?$timeline:['settings'=>[],'clips'=>[]],
        'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at'],
    ];
}
function az_personal_edit_project_list(int $owner): array
{
    $q=az_db()->prepare('SELECT * FROM personal_edit_projects WHERE user_id=? ORDER BY updated_at DESC, id DESC LIMIT 100');
    $q->execute([$owner]);
    return array_map(static function(array $row):array {
        $timeline=json_decode((string)$row['timeline_json'],true);
        return ['id'=>$row['id'],'name'=>$row['title'],'revision'=>(int)$row['revision'],
            'clipCount'=>count($timeline['clips']??[]),'updatedAt'=>$row['updated_at']];
    },$q->fetchAll());
}
function az_personal_edit_decimal(mixed $value, float $min, float $max, string $label): float
{
    if (!is_int($value) && !is_float($value)) throw new AzHttpError(400,$label.' is not a number.','invalid_field');
    $number=(float)$value;
    if (!is_finite($number) || $number<$min || $number>$max) throw new AzHttpError(400,$label.' is out of range.','invalid_field');
    return $number;
}

/** Validate audio envelopes without accepting arbitrary JavaScript values or URLs. */
function az_personal_edit_keyframes(mixed $input,int $duration):array
{
    if($input===null)return [];
    if(!is_array($input)||count($input)>32)throw new AzHttpError(400,'Up to 32 volume keyframes per clip.','invalid_field');
    $last=-1;$clean=[];
    foreach($input as $point){
        if(!is_array($point))throw new AzHttpError(400,'Invalid audio keyframe.','invalid_field');
        $time=az_integer($point['timeMs']??-1,0,$duration,'Keyframe time');
        if($time<=$last)throw new AzHttpError(400,'Volume keyframes must be strictly ordered.','invalid_field');
        $last=$time;
        $clean[]=['timeMs'=>$time,'volume'=>az_personal_edit_decimal($point['volume']??null,0,1,'Keyframe volume')];
    }
    return $clean;
}

/** Validate each media ID against authenticated owner; never accept client media URLs. */
function az_personal_edit_validate(array $timeline,int $owner): array
{
    $settings=$timeline['settings']??[];
    if (!is_array($settings)) throw new AzHttpError(400,'Invalid editor settings.','invalid_field');
    $aspect=$settings['aspect']??'16:9';$fit=$settings['fit']??'contain';
    if (!in_array($aspect,['16:9','9:16','1:1'],true)||!in_array($fit,['contain','cover'],true))
        throw new AzHttpError(400,'Invalid video shape or framing.','invalid_field');
    $resolution=$settings['resolution']??720;
    if (!in_array($resolution,[720,1080],true)) throw new AzHttpError(400,'Unsupported export resolution.','invalid_field');
    $settings=[
      'aspect'=>$aspect,'fit'=>$fit,'resolution'=>$resolution,
      'fadeDuration'=>az_personal_edit_decimal($settings['fadeDuration']??0.35,0,2,'Fade'),
      'cardDuration'=>az_personal_edit_decimal($settings['cardDuration']??2.5,0.5,10,'Title-card length'),
    ];
    // Lucky-style T1/B1 tracks remain owner-scoped and are saved with the edit.
    $titles=$timeline['settings']['timedTitles']??[];
    if(!is_array($titles)||count($titles)>32) throw new AzHttpError(400,'Up to 32 timed titles per project.','invalid_field');
    $settings['timedTitles']=[];$titleIds=[];
    foreach($titles as $t){
        if(!is_array($t)) throw new AzHttpError(400,'Invalid timed title.','invalid_field');
        $tid=az_text($t['id']??'',32,'Title ID');
        if(!az_valid_id($tid)||isset($titleIds[$tid])) throw new AzHttpError(400,'Invalid or repeated title ID.','invalid_field');
        $titleIds[$tid]=true;
        $value=az_text($t['text']??'',200,'Timed title');
        if($value==='') throw new AzHttpError(400,'Timed title text is required.','invalid_field');
        $position=$t['position']??'bottom';
        if(!in_array($position,['top','center','bottom'],true)) throw new AzHttpError(400,'Invalid title placement.','invalid_field');
        $settings['timedTitles'][]=['id'=>$tid,'text'=>$value,
            'startMs'=>az_integer($t['startMs']??0,0,86400000,'Title start'),
            'durationMs'=>az_integer($t['durationMs']??0,300,120000,'Title duration'),
            'position'=>$position];
    }
    $background=$timeline['settings']['background']??null;
    $settings['background']=null;
    if($background!==null){
        if(!is_array($background))throw new AzHttpError(400,'Invalid background audio.','invalid_field');
        $media=az_text($background['mediaId']??'',32,'Background source');
        if(!az_valid_id($media))throw new AzHttpError(400,'Invalid background source.','invalid_field');
        $ownerMedia=az_personal_media_row($media,$owner); // Never save a source from another account.
        $sourceStart=az_integer($background['trimStartMs']??0,0,86400000,'Background source start');
        if((int)$ownerMedia['duration_ms']>0 && $sourceStart >= (int)$ownerMedia['duration_ms'])
            throw new AzHttpError(400,'The background source start exceeds its duration.','invalid_field');
        $settings['background']=['mediaId'=>$media,
          'startMs'=>az_integer($background['startMs']??0,0,86400000,'Background start'),
          'trimStartMs'=>$sourceStart,
          'volume'=>az_personal_edit_decimal($background['volume']??0.18,0,1,'Background volume')];
    }
    $clips=$timeline['clips']??[];
    if (!is_array($clips)||count($clips)>48) throw new AzHttpError(400,'The editor supports up to 48 clips per timeline.','invalid_field');
    $ids=[];$checked=[];$clean=[];
    foreach ($clips as $i=>$clip) {
      if (!is_array($clip)) throw new AzHttpError(400,'Invalid timeline clip.','invalid_field');
      $media=az_text($clip['mediaId']??'',32,'Source video');
      if (!az_valid_id($media)) throw new AzHttpError(400,'Invalid source video.','invalid_field');
      $clipId=az_text($clip['id']??'',32,'Timeline clip ID');
      if (!az_valid_id($clipId)||isset($ids[$clipId])) throw new AzHttpError(400,'Each timeline clip needs a unique ID.','invalid_field');
      $ids[$clipId]=true;
      if (!isset($checked[$media])) $checked[$media]=az_personal_media_row($media,$owner);
      $storedDuration=(int)$checked[$media]['duration_ms'];
      $duration=az_integer($clip['durationMs']??0,1,86400000,'Source duration');
      if ($storedDuration>0 && abs($storedDuration-$duration)>2000) throw new AzHttpError(409,'The source duration changed. Reopen the clip.','source_changed');
      $start=az_integer($clip['trimStartMs']??0,0,86400000,'Clip start');
      $end=az_integer($clip['trimEndMs']??0,1,86400000,'Clip end');
      if ($end>$duration||$end-$start<100) throw new AzHttpError(400,'Trim a clip to at least 0.1 seconds within the source.','invalid_trim');
      $position=$clip['overlayPosition']??'bottom';
      if (!in_array($position,['top','center','bottom'],true)) throw new AzHttpError(400,'Unknown title placement.','invalid_field');
      $filter=$clip['filter']??'none';$transition=$clip['transition']??'fade';
      if(!in_array($filter,['none','vivid','warm','mono','cinematic','vintage'],true))
          throw new AzHttpError(400,'Unknown video filter.','invalid_field');
      if(!in_array($transition,['fade','cut'],true))
          throw new AzHttpError(400,'Unknown clip transition.','invalid_field');
      $clean[]=[
         'id'=>$clipId,'mediaId'=>$media,'durationMs'=>$duration,
         'trimStartMs'=>$start,'trimEndMs'=>$end,
         'overlayText'=>az_text($clip['overlayText']??'',200,'Text overlay'),
         'overlayPosition'=>$position,'titleCard'=>($clip['titleCard']??false)===true,
         'volume'=>az_personal_edit_decimal($clip['volume']??1,0,1,'Clip volume'),
         'audioDetached'=>($clip['audioDetached']??false)===true,
         'audioKeyframes'=>az_personal_edit_keyframes($clip['audioKeyframes']??[],$end-$start),
         'filter'=>$filter,'transition'=>$transition,
      ];
    }
    $audioClips=$timeline['settings']['detachedAudio']??[];
    if(!is_array($audioClips)||count($audioClips)>24)throw new AzHttpError(400,'Up to 24 detached audio clips per project.','invalid_field');
    $settings['detachedAudio']=[];$audioIds=[];
    foreach($audioClips as $audio){
        if(!is_array($audio))throw new AzHttpError(400,'Invalid detached audio clip.','invalid_field');
        $audioId=az_text($audio['id']??'',32,'Detached audio ID');
        if(!az_valid_id($audioId)||isset($audioIds[$audioId])||isset($ids[$audioId]))throw new AzHttpError(400,'Invalid or repeated detached audio ID.','invalid_field');
        $audioIds[$audioId]=true;
        $media=az_text($audio['mediaId']??'',32,'Detached audio source');
        if(!az_valid_id($media))throw new AzHttpError(400,'Invalid detached audio source.','invalid_field');
        if(!isset($checked[$media]))$checked[$media]=az_personal_media_row($media,$owner);
        $duration=az_integer($audio['durationMs']??0,1,86400000,'Audio source duration');
        $storedDuration=(int)$checked[$media]['duration_ms'];
        if($storedDuration>0&&abs($duration-$storedDuration)>2000)throw new AzHttpError(409,'The audio source duration changed.','source_changed');
        $start=az_integer($audio['trimStartMs']??0,0,86400000,'Audio trim start');
        $end=az_integer($audio['trimEndMs']??0,1,86400000,'Audio trim end');
        if($end>$duration||$end-$start<100)throw new AzHttpError(400,'Audio needs at least 0.1 seconds within its source.','invalid_trim');
        $origin=az_text($audio['originClipId']??'',32,'Original clip ID');
        if($origin!==''&&!az_valid_id($origin))throw new AzHttpError(400,'Invalid original clip ID.','invalid_field');
        $settings['detachedAudio'][]=[
            'id'=>$audioId,'originClipId'=>$origin,'mediaId'=>$media,'durationMs'=>$duration,
            'startMs'=>az_integer($audio['startMs']??0,0,86400000,'Audio position'),
            'trimStartMs'=>$start,'trimEndMs'=>$end,
            'volume'=>az_personal_edit_decimal($audio['volume']??1,0,1,'Detached audio volume'),
            'audioKeyframes'=>az_personal_edit_keyframes($audio['audioKeyframes']??[],$end-$start)
        ];
    }
    return ['settings'=>$settings,'clips'=>$clean];
}

function az_personal_edit_project_save(array $body,int $owner): array
{
    $id=az_text($body['id']??'',32,'Project ID');
    if (!az_valid_id($id)) throw new AzHttpError(400,'Invalid project ID.','invalid_field');
    $name=az_text($body['name']??'',160,'Project name');
    if ($name==='') throw new AzHttpError(400,'Name this edit project.','invalid_field');
    $revision=az_integer($body['revision']??0,0,10000000,'Project revision');
    $timeline=$body['timeline']??[];
    if ($timeline instanceof stdClass) $timeline=json_decode(json_encode($timeline,JSON_THROW_ON_ERROR),true,64,JSON_THROW_ON_ERROR);
    if (!is_array($timeline)) throw new AzHttpError(400,'Invalid timeline.','invalid_field');
    $validated=az_personal_edit_validate($timeline,$owner);
    $encoded=json_encode($validated,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    if (strlen($encoded)>131072) throw new AzHttpError(413,'The timeline is too large.','request_too_large');
    $db=az_db();$now=az_now();
    $q=$db->prepare('SELECT * FROM personal_edit_projects WHERE id=? AND user_id=?');$q->execute([$id,$owner]);$previous=$q->fetch();
    if ($previous) {
        if ($revision===0 && $previous['title']===$name && $previous['timeline_json']===$encoded)
            return az_personal_edit_project_data($previous); // lost first-save response
        if ($revision!==(int)$previous['revision']) throw new AzHttpError(409,'This project changed in another tab. Reload it before saving.','revision_conflict');
        $q=$db->prepare('UPDATE personal_edit_projects SET title=?,timeline_json=?,revision=revision+1,updated_at=? WHERE id=? AND user_id=? AND revision=?');
        $q->execute([$name,$encoded,$now,$id,$owner,$revision]);
        if ($q->rowCount()!==1) throw new AzHttpError(409,'This project changed. Reload it before saving.','revision_conflict');
    } else {
        if ($revision!==0) throw new AzHttpError(409,'This edit project no longer exists.','revision_conflict');
        $q=$db->prepare('SELECT COUNT(*) FROM personal_edit_projects WHERE user_id=?');$q->execute([$owner]);
        if ((int)$q->fetchColumn()>=100) throw new AzHttpError(413,'Your personal editor has reached 100 saved projects.','project_limit');
        try {
            $q=$db->prepare('INSERT INTO personal_edit_projects(id,user_id,title,timeline_json,revision,created_at,updated_at) VALUES(?,?,?,?,1,?,?)');
            $q->execute([$id,$owner,$name,$encoded,$now,$now]);
        } catch(PDOException $e) { throw new AzHttpError(409,'Project ID already exists. Reload your project list.','revision_conflict'); }
    }
    return az_personal_edit_project_data(az_personal_edit_project_row($id,$owner));
}
function az_personal_edit_project_delete(string $id,int $owner): array
{
    az_personal_edit_project_row($id,$owner);
    $q=az_db()->prepare('DELETE FROM personal_edit_projects WHERE id=? AND user_id=?');$q->execute([$id,$owner]);
    return ['deleted'=>true,'id'=>$id,'mediaPreserved'=>true];
}
function az_personal_media_set_duration(string $id,int $owner,int $duration): array
{
    $row=az_personal_media_row($id,$owner);
    if ((int)$row['duration_ms']===0 && $duration>0) {
       $q=az_db()->prepare('UPDATE personal_media SET duration_ms=? WHERE id=? AND user_id=? AND duration_ms=0');
       $q->execute([$duration,$id,$owner]);
    }
    return az_personal_media_data(az_personal_media_row($id,$owner));
}
