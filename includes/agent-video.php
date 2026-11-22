<?php
declare(strict_types=1);
/**
 * Video Production Specialist: source-bound, reversible editing plans and a verified
 * publishing follow-through snapshot. This code NEVER claims to have heard or
 * viewed media and NEVER submits, publishes, or modifies original footage.
 */
function az_upgrade_agent_video_schema(PDO $db): void
{
    if (az_schema_version($db) >= 34 || az_schema_version($db) < 33) return;
    az_prepare_storage();
    $lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Video specialist migration lock unavailable.');
    try {
        if (az_schema_version($db) >= 34) return;
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $uid=$mysql?'BIGINT UNSIGNED':'INTEGER';
        $long=$mysql?'LONGTEXT':'TEXT';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS video_agent_moments (
            id CHAR(32) PRIMARY KEY, user_id $uid NOT NULL, media_id CHAR(32) NOT NULL,
            start_ms INTEGER NOT NULL, end_ms INTEGER NOT NULL,
            kind VARCHAR(20) NOT NULL, note VARCHAR(500) NOT NULL,
            created_at VARCHAR(32) NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(media_id) REFERENCES personal_media(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS video_agent_plans (
            id CHAR(32) PRIMARY KEY, user_id $uid NOT NULL, edit_project_id CHAR(32) NOT NULL,
            source_revision INTEGER NOT NULL, source_hash CHAR(64) NOT NULL,
            source_timeline_json $long NOT NULL, proposal_json $long NOT NULL,
            goal VARCHAR(20) NOT NULL, target_seconds INTEGER NOT NULL,
            status VARCHAR(20) NOT NULL, applied_revision INTEGER NULL,
            created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(edit_project_id) REFERENCES personal_edit_projects(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS video_agent_deliveries (
            id CHAR(32) PRIMARY KEY, user_id $uid NOT NULL, plan_id CHAR(32) NOT NULL,
            rule VARCHAR(40) NOT NULL, fingerprint CHAR(64) NOT NULL, created_at VARCHAR(32) NOT NULL,
            UNIQUE(user_id,rule,fingerprint),
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(plan_id) REFERENCES video_agent_plans(id) ON DELETE CASCADE)$suffix");
        if ($mysql) {
            $q=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
            foreach ([['video_agent_moments','video_agent_moments_owner','(user_id,media_id)'],['video_agent_plans','video_agent_plans_owner','(user_id,updated_at)']] as [$table,$index,$cols]) {
                $q->execute([$table,$index]); if (!(int)$q->fetchColumn()) $db->exec("CREATE INDEX $index ON $table $cols");
            }
        } else {
            $db->exec('CREATE INDEX IF NOT EXISTS video_agent_moments_owner ON video_agent_moments(user_id,media_id)');
            $db->exec('CREATE INDEX IF NOT EXISTS video_agent_plans_owner ON video_agent_plans(user_id,updated_at)');
        }
        $db->exec('UPDATE studio_schema SET version=34 WHERE id=1');
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}

/** Pure boundary checks for creator-authored timing notes, not auto-transcripts. */
function az_video_agent_moment_input(array $body,int $mediaDuration): array
{
    $kind=$body['kind']??null;
    if (!in_array($kind,['highlight','caption','issue'],true))
        throw new AzHttpError(400,'Choose highlight, caption, or issue.','invalid_moment');
    $start=az_integer($body['startMs']??-1,0,86400000,'Moment start');
    $end=az_integer($body['endMs']??-1,1,86400000,'Moment end');
    if ($end-$start<100 || ($mediaDuration>0 && $end>$mediaDuration))
        throw new AzHttpError(400,'Moment must be at least 0.1 seconds and inside the source.','invalid_moment');
    $note=az_text($body['note']??'',500,'Creator note');
    if ($note==='' || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$note))
        throw new AzHttpError(400,'Enter a short plain-text note.','invalid_moment');
    return ['kind'=>$kind,'startMs'=>$start,'endMs'=>$end,'note'=>$note];
}
function az_video_agent_moments(int $owner,string $mediaId=''): array
{
    if ($mediaId!=='' && !az_valid_id($mediaId)) throw new AzHttpError(400,'Invalid media ID.','invalid_id');
    $sql='SELECT id,media_id,kind,start_ms,end_ms,note,created_at FROM video_agent_moments WHERE user_id=?';
    $args=[$owner];if ($mediaId!==''){$sql.=' AND media_id=?';$args[]=$mediaId;}
    $sql.=' ORDER BY media_id,start_ms,id LIMIT 200';
    $q=az_db()->prepare($sql);$q->execute($args);
    return array_map(static fn($r):array=>['id'=>$r['id'],'mediaId'=>$r['media_id'],'kind'=>$r['kind'],
        'startMs'=>(int)$r['start_ms'],'endMs'=>(int)$r['end_ms'],'note'=>$r['note'],'createdAt'=>$r['created_at']],$q->fetchAll());
}
function az_video_agent_add_moment(array $body,int $owner): array
{
    $mediaId=az_text($body['mediaId']??'',32,'Media ID');
    if (!az_valid_id($mediaId)) throw new AzHttpError(400,'Choose a saved source.','invalid_id');
    $media=az_personal_media_row($mediaId,$owner);
    $note=az_video_agent_moment_input($body,(int)$media['duration_ms']);
    $q=az_db()->prepare('SELECT COUNT(*) FROM video_agent_moments WHERE user_id=?');$q->execute([$owner]);
    if ((int)$q->fetchColumn()>=200)throw new AzHttpError(413,'Limit 200 source notes per account.','moment_limit');
    $id=az_id();
    az_db()->prepare('INSERT INTO video_agent_moments(id,user_id,media_id,start_ms,end_ms,kind,note,created_at) VALUES(?,?,?,?,?,?,?,?)')
      ->execute([$id,$owner,$mediaId,$note['startMs'],$note['endMs'],$note['kind'],$note['note'],az_now()]);
    az_agent_workspace_emit($owner,$owner,'video_moment_added','Video source note saved',
        'A time-bound private editing note was added.','my-editor.php');
    return ['id'=>$id,'mediaId'=>$mediaId]+$note;
}
function az_video_agent_remove_moment(string $id,int $owner): array
{
    if (!az_valid_id($id))throw new AzHttpError(400,'Invalid note ID.','invalid_id');
    $q=az_db()->prepare('DELETE FROM video_agent_moments WHERE id=? AND user_id=?');$q->execute([$id,$owner]);
    if ($q->rowCount()!==1)throw new AzHttpError(404,'Note not found.','not_found');
    return ['removed'=>true,'id'=>$id];
}

/** Match the editor's interpolated volume envelope when a cut changes in/out. */
function az_video_agent_audio_at(array $points,int $position,float $baseline): float
{
    if(!$points)return $baseline;
    if($position <= (int)$points[0]['timeMs'])return (float)$points[0]['volume'];
    $last=$points[count($points)-1];
    if($position >= (int)$last['timeMs'])return (float)$last['volume'];
    for($i=1;$i<count($points);$i++){
        $a=$points[$i-1];$b=$points[$i];
        if($position <= (int)$b['timeMs']){
            $span=max(1,(int)$b['timeMs']-(int)$a['timeMs']);
            return (float)$a['volume']+((float)$b['volume']-(float)$a['volume'])*($position-(int)$a['timeMs'])/$span;
        }
    }
    return $baseline;
}
function az_video_agent_rebase_audio(array $points,int $oldStart,int $newStart,int $newEnd,float $volume): array
{
    if(!$points)return [];
    $delta=$newStart-$oldStart;$duration=$newEnd-$newStart;
    $next=[['timeMs'=>0,'volume'=>az_video_agent_audio_at($points,$delta,$volume)]];
    foreach($points as $point){$time=(int)$point['timeMs']-$delta;
        if($time>0 && $time<$duration && count($next)<31)$next[]=['timeMs'=>$time,'volume'=>(float)$point['volume']];
    }
    $next[]=['timeMs'=>$duration,'volume'=>az_video_agent_audio_at($points,$delta+$duration,$volume)];
    return $next;
}
/**
 * Source-range selection, not optical or speech inference. One chosen segment per
 * source clip; original clip settings and titles remain unless owner opts into a
 * caption from their own note. The algorithm is pure and unit testable.
 */
function az_video_agent_suggest(array $timeline,array $moments,int $targetSeconds,string $goal,bool $useCaptions=false): array
{
    if (!in_array($goal,['teaser','highlight','recap'],true) || $targetSeconds<5 || $targetSeconds>300)
        throw new AzHttpError(400,'Choose a supported goal and duration.','invalid_plan');
    $clips=$timeline['clips']??[];
    if (!is_array($clips) || !$clips)throw new AzHttpError(409,'Save a project with at least one video clip.','empty_timeline');
    $usable=[];
    foreach($clips as $clip){
        if (!is_array($clip))continue;
        $start=(int)($clip['trimStartMs']??0);$end=(int)($clip['trimEndMs']??0);
        if ($end-$start<100)continue;
        $best=null;
        foreach($moments as $m){
            if (($m['mediaId']??'')!==($clip['mediaId']??'') || !in_array($m['kind']??'',['highlight','caption'],true))continue;
            $from=max($start,(int)$m['startMs']);$to=min($end,(int)$m['endMs']);
            if ($to-$from<100)continue;
            $score=($m['kind']==='highlight'?4:2)*100000000+($to-$from);
            if ($best===null||$score>$best['score'])$best=['start'=>$from,'end'=>$to,'kind'=>$m['kind'],'note'=>$m['note'],'score'=>$score];
        }
        $usable[]=['clip'=>$clip,'start'=>$best['start']??$start,'end'=>$best['end']??$end,'note'=>$best,'sourceStart'=>$start,'sourceEnd'=>$end];
    }
    if (!$usable)throw new AzHttpError(409,'No usable footage ranges remain.','empty_timeline');
    // Teasers prefer notes first; recap retains source order. Never invent visual significance.
    if($goal!=='recap')usort($usable,static fn($a,$b)=>($b['note']!==null)<=>($a['note']!==null));
    $budget=$targetSeconds*1000;$selected=[];$used=0;
    $recapQuota=$goal==='recap'?(int)floor($budget/count($usable)):$budget;
    foreach($usable as $part){
        if ($budget-$used<100)break;
        $available=$part['end']-$part['start'];$remaining=$budget-$used;
        // Title cards consume runtime too; a recap spreads duration across sources.
        $cardMs=($part['clip']['titleCard']??false)?(int)round(((float)($timeline['settings']['cardDuration']??2.5))*1000):0;
        $length=min($available,$remaining-$cardMs,$goal==='recap'?max(100,$recapQuota-$cardMs):$budget);
        if ($length<100)continue;
        $start=$part['start'];$end=$start+$length;
        // For unannotated sources, use a midpoint rather than asserting a 'best moment'.
        if ($part['note']===null && $available>$length) {
            $start+=(int)floor(($available-$length)/2);$end=$start+$length;
        }
        $clip=$part['clip'];$clip['trimStartMs']=$start;$clip['trimEndMs']=$end;
        if($useCaptions && ($part['note']['kind']??'')==='caption')$clip['overlayText']=mb_safe_trim($part['note']['note'],200);
        $clip['audioKeyframes']=az_video_agent_rebase_audio($clip['audioKeyframes']??[],(int)$part['sourceStart'],$start,$end,(float)($clip['volume']??1));
        $selected[]=$clip;$used+=$length+$cardMs;
    }
    if (!$selected)throw new AzHttpError(409,'No valid proposed cut is available.','empty_timeline');
    // A2 audio tracks are dropped only from the proposed alternative; the source
    // timeline remains intact for revert. Never misalign detached sound with new cuts.
    $settings=$timeline['settings']??[];$settings['detachedAudio']=[];
    $warnings=['This proposal uses source duration and your own timed notes; no visual or speech analysis was performed.'];
    foreach($selected as $clip)foreach($moments as $moment){
        if(($moment['kind']??'')!=='issue'||($moment['mediaId']??'')!==$clip['mediaId'])continue;
        if((int)$moment['startMs']<(int)$clip['trimEndMs']&&(int)$moment['endMs']>(int)$clip['trimStartMs']){
            $warnings[]='A creator-flagged issue overlaps a suggested clip. Review the source at '.round((int)$moment['startMs']/1000,1).'s before export.';
        }
    }

    if(!empty($timeline['settings']['detachedAudio']))$warnings[]='Detached A2 audio was omitted from the proposed cut. Review sound before export.';
    if(!empty($timeline['settings']['background']))$warnings[]='Check the B1 background-audio timing in the new cut before export.';
    if(!empty($timeline['settings']['timedTitles']))$warnings[]='Check original T1 timed-title positions: cuts may change when they appear.';
    if($used<$budget)$warnings[]='The selected footage and title cards provide only '.round($used/1000,1).' seconds of the requested cut.';
    return ['timeline'=>['settings'=>$settings,'clips'=>$selected], 'durationMs'=>$used,
        'sourceCount'=>count($clips),'selectedCount'=>count($selected),'warnings'=>$warnings,
        'basis'=>'source timing and creator-authored notes, not audiovisual analysis'];
}
function az_video_agent_plan_row(string $id,int $owner): array
{
    if(!az_valid_id($id))throw new AzHttpError(404,'Editing plan not found.','not_found');
    $q=az_db()->prepare('SELECT * FROM video_agent_plans WHERE id=? AND user_id=?');$q->execute([$id,$owner]);
    $r=$q->fetch();if(!$r)throw new AzHttpError(404,'Editing plan not found.','not_found');
    return $r;
}
function az_video_agent_plan_data(array $row,bool $includeTimeline=true): array
{
    $plan=json_decode((string)$row['proposal_json'],true)?:[];
    if(!$includeTimeline)unset($plan['timeline']);
    return ['id'=>$row['id'],'projectId'=>$row['edit_project_id'],'projectRevision'=>(int)$row['source_revision'],
        'status'=>$row['status'],'targetSeconds'=>(int)$row['target_seconds'],'goal'=>$row['goal'],
        'appliedRevision'=>$row['applied_revision']!==null?(int)$row['applied_revision']:null,
        'proposal'=>$plan,'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at']];
}
function az_video_agent_plans(int $owner,string $projectId): array
{
    if(!az_valid_id($projectId))throw new AzHttpError(400,'Choose a saved edit.','invalid_id');
    az_personal_edit_project_row($projectId,$owner);
    $q=az_db()->prepare('SELECT * FROM video_agent_plans WHERE user_id=? AND edit_project_id=? ORDER BY created_at DESC,id DESC LIMIT 10');
    $q->execute([$owner,$projectId]);
    return array_map(static fn($r)=>az_video_agent_plan_data($r,false),$q->fetchAll());
}
function az_video_agent_generate(array $body,int $owner): array
{
    $project=az_text($body['projectId']??'',32,'Project ID');
    $row=az_personal_edit_project_row($project,$owner);
    $revision=$body['revision']??null;
    if(!is_int($revision)||$revision!==(int)$row['revision'])
        throw new AzHttpError(409,'Project changed; save and reload before requesting a plan.','revision_conflict');
    $target=az_integer($body['targetSeconds']??0,5,300,'Target duration');
    $goal=az_text($body['goal']??'',20,'Edit goal');
    $clips=json_decode($row['timeline_json'],true);
    if(!is_array($clips))throw new AzHttpError(409,'Saved edit is unreadable.','invalid_timeline');
    $notes=[];$mediaIds=[];
    foreach(($clips['clips']??[]) as $clip)if(is_array($clip)&&is_string($clip['mediaId']??null))$mediaIds[$clip['mediaId']]=true;
    foreach(az_video_agent_moments($owner) as $note)if(isset($mediaIds[$note['mediaId']]))$notes[$note['id']]=$note;
    $proposal=az_video_agent_suggest($clips,array_values($notes),$target,$goal,($body['useCaptions']??false)===true);
    // Validate every media source even for data derived from previously saved edits.
    $proposal['timeline']=az_personal_edit_validate($proposal['timeline'],$owner);
    $q=az_db()->prepare("SELECT COUNT(*) FROM video_agent_plans WHERE user_id=? AND status='proposed'");$q->execute([$owner]);
    if((int)$q->fetchColumn()>=25)throw new AzHttpError(413,'Review or discard some older plans first.','plan_limit');
    $id=az_id();$now=az_now();
    az_db()->prepare('INSERT INTO video_agent_plans(id,user_id,edit_project_id,source_revision,source_hash,source_timeline_json,proposal_json,goal,target_seconds,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$id,$owner,$project,$revision,hash('sha256',$row['timeline_json']),$row['timeline_json'],
          json_encode($proposal,JSON_THROW_ON_ERROR),$goal,$target,'proposed',$now,$now]);
    az_agent_workspace_emit($owner,$owner,'video_plan_created','Editing plan ready for review',
        'Preview an optional nondestructive cut before approving any changes.','my-editor.php?projectId='.$project);
    return az_video_agent_plan_data(az_video_agent_plan_row($id,$owner));
}
/** Explicit owner approval, optimistic revision plus entire-source fingerprint. */
function az_video_agent_apply(array $body,int $owner): array
{
    $plan=az_video_agent_plan_row(az_text($body['planId']??'',32,'Plan ID'),$owner);
    if($plan['status']!=='proposed')throw new AzHttpError(409,'This editing proposal is no longer pending.','invalid_status');
    $row=az_personal_edit_project_row($plan['edit_project_id'],$owner);
    if((int)$row['revision']!==(int)$plan['source_revision'] || !hash_equals($plan['source_hash'],hash('sha256',$row['timeline_json'])))
        throw new AzHttpError(409,'The edit changed since this plan was created. Generate a fresh plan.','plan_stale');
    if(($body['approve']??false)!==true)throw new AzHttpError(400,'Explicit approval is required to apply a plan.','approval_required');
    $proposal=json_decode($plan['proposal_json'],true);
    if(!is_array($proposal)||!is_array($proposal['timeline']??null))throw new AzHttpError(409,'The plan could not be read.','invalid_plan');
    // The existing owner-only timeline writer handles duration, source ownership,
    // media change detection, and edit revision checking.
    $saved=az_personal_edit_project_save(['id'=>$row['id'],'revision'=>(int)$row['revision'],'name'=>$row['title'], 'timeline'=>$proposal['timeline']],$owner);
    $q=az_db()->prepare("UPDATE video_agent_plans SET status='applied',applied_revision=?,updated_at=? WHERE id=? AND user_id=? AND status='proposed'");
    $q->execute([$saved['revision'],az_now(),$plan['id'],$owner]);
    if($q->rowCount()!==1)throw new AzHttpError(409,'Plan status changed.','plan_stale');
    az_video_agent_resolve_alert($plan);
    az_agent_workspace_emit($owner,$owner,'video_plan_applied','Editing plan approved',
        'A new private timeline revision was saved; original footage was preserved.','my-editor.php?projectId='.$row['id']);
    return ['project'=>$saved,'plan'=>az_video_agent_plan_data(az_video_agent_plan_row($plan['id'],$owner))];
}
function az_video_agent_resolve_alert(array $plan): void
{
    $href='my-editor.php?projectId='.$plan['edit_project_id'].'&planId='.$plan['id'];
    az_db()->prepare("UPDATE agent_workspace_events SET read_at=? WHERE recipient_id=? AND kind='video_review_due' AND href=? AND read_at IS NULL")
        ->execute([az_now(),(int)$plan['user_id'],$href]);
}
function az_video_agent_discard(array $body,int $owner): array
{
    $p=az_video_agent_plan_row(az_text($body['planId']??'',32,'Plan ID'),$owner);
    if($p['status']!=='proposed')throw new AzHttpError(409,'Only pending plans can be discarded.','invalid_status');
    $q=az_db()->prepare("UPDATE video_agent_plans SET status='discarded',updated_at=? WHERE id=? AND user_id=? AND status='proposed'");
    $q->execute([az_now(),$p['id'],$owner]);
    if($q->rowCount()!==1)throw new AzHttpError(409,'The editing plan changed.','plan_stale');
    az_video_agent_resolve_alert($p);
    return az_video_agent_plan_data(az_video_agent_plan_row($p['id'],$owner),false);
}
function az_video_agent_revert(array $body,int $owner): array
{
    $p=az_video_agent_plan_row(az_text($body['planId']??'',32,'Plan ID'),$owner);
    if($p['status']!=='applied')throw new AzHttpError(409,'Only an applied editing plan can be reverted.','invalid_status');
    $row=az_personal_edit_project_row($p['edit_project_id'],$owner);
    if((int)$row['revision']!==(int)$p['applied_revision'])
        throw new AzHttpError(409,'This timeline has newer edits. Manual undo is required to avoid losing them.','revision_conflict');
    $source=json_decode($p['source_timeline_json'],true);
    if(!is_array($source))throw new AzHttpError(409,'Original cut unavailable.','invalid_timeline');
    $saved=az_personal_edit_project_save(['id'=>$row['id'],'revision'=>(int)$row['revision'],'name'=>$row['title'],'timeline'=>$source],$owner);
    $q=az_db()->prepare("UPDATE video_agent_plans SET status='reverted',updated_at=? WHERE id=? AND user_id=? AND status='applied'");
    $q->execute([az_now(),$p['id'],$owner]);
    if($q->rowCount()!==1)throw new AzHttpError(409,'Plan changed while reverting.','plan_stale');
    az_agent_workspace_emit($owner,$owner,'video_plan_reverted','Previous editing timeline restored',
        'The timeline was restored to its prior revision; original footage was never changed.','my-editor.php?projectId='.$row['id']);
    return ['project'=>$saved,'plan'=>az_video_agent_plan_data(az_video_agent_plan_row($p['id'],$owner),false)];
}

/** Bounded, role-specific read-only pipeline summaries for Brain and Chat. */
function az_video_agent_briefing(array $user): array
{
    $owner=(int)$user['id'];$admin=az_has_role($user,'admin');$cards=[];$counts=[];
    if($admin){
        $q=az_db()->query("SELECT COUNT(*) FROM model_videos WHERE status='pending_review'");$waiting=(int)$q->fetchColumn();
        $q=az_db()->query("SELECT COUNT(*) FROM model_videos WHERE status='changes_requested'");$revisions=(int)$q->fetchColumn();
        $q=az_db()->prepare("SELECT COUNT(*) FROM production_edits e JOIN production_projects p ON p.id=e.project_id WHERE p.owner_id=? AND e.status<>'deleted'");
        $q->execute([$owner]);$producerEdits=(int)$q->fetchColumn();
        $counts=['videoReviewQueue'=>$waiting,'videoRevisions'=>$revisions,'producerEdits'=>$producerEdits];
        if($waiting)$cards[]=az_agent_workspace_card('video','Video publishing decisions needed',
          "$waiting model video(s) await review. Approve or request changes from the existing secure queue.",'user-submissions.php',1);
        if($revisions)$cards[]=az_agent_workspace_card('video','Videos awaiting revisions',
          "$revisions model video(s) need changes after a review. Check their status and follow through.",'user-submissions.php',3);
        if($producerEdits)$cards[]=az_agent_workspace_card('video','Review production edits',
          "$producerEdits versioned production timeline(s) are available in the editor.",'productions.php',4);
    } elseif(az_has_role($user,'model')){
        $q=az_db()->prepare("SELECT COUNT(*) FROM personal_edit_projects WHERE user_id=?");$q->execute([$owner]);$edits=(int)$q->fetchColumn();
        $q=az_db()->prepare("SELECT COUNT(*) FROM video_agent_plans WHERE user_id=? AND status='proposed'");$q->execute([$owner]);$proposals=(int)$q->fetchColumn();
        $q=az_db()->prepare("SELECT COUNT(*) FROM model_videos WHERE user_id=? AND status='changes_requested'");$q->execute([$owner]);$changes=(int)$q->fetchColumn();
        $q=az_db()->prepare("SELECT COUNT(*) FROM model_videos WHERE user_id=? AND status='draft'");$q->execute([$owner]);$drafts=(int)$q->fetchColumn();
        $counts=['savedVideoEdits'=>$edits,'videoPlansToReview'=>$proposals,'videoDrafts'=>$drafts,'videoChangesRequested'=>$changes];
        if($proposals)$cards[]=az_agent_workspace_card('video','Editing proposals awaiting your decision',
            "$proposals saved cut(s) can be previewed, applied, or discarded in Video Studio.",'my-editor.php',2);
        if($changes)$cards[]=az_agent_workspace_card('video','Video revisions requested',
            "$changes video(s) need updates before resubmission.",'my-videos.php',1);
        if($drafts)$cards[]=az_agent_workspace_card('video','Finish and submit independent videos',
            "$drafts video draft(s) can be reviewed and submitted with explicit sharing consent.",'my-videos.php',3);
    }
    return ['cards'=>$cards,'counts'=>$counts,'basis'=>'Database status, editor revisions, and creator-authored notes only'];
}
function az_video_agent_chat_snapshot(array $user): array
{
    $brief=az_video_agent_briefing($user);
    // No source IDs, names, footage, transcripts, notes, or media URLs are transmitted.
    return ['counts'=>$brief['counts'],'basis'=>$brief['basis'], 'nextStep'=>az_has_role($user,'admin')?
      'Review the authenticated submission queue and versioned producer edits before approving publication.':
      'Save a private timeline, add timestamped source notes, preview a proposed cut, then explicitly approve or discard it. Rendered uploads still require normal admin review.'];
}
/** Reminder rule for stalled unapproved plans, only to their authenticated creator. */
function az_video_agent_rules(array $row,int $now): array
{
    $age=$now-(strtotime((string)($row['updated_at']??''))?:$now);
    return ($row['status']??'')==='proposed' && $age>=2*86400 ? ['review_48h'] : [];
}
function az_video_agent_scan(): array
{
    $db=az_db();$lock=az_community_lock();$checked=0;$delivered=0;
    try{
        $q=$db->query("SELECT p.id,p.user_id,p.edit_project_id,p.status,p.updated_at FROM video_agent_plans p
          JOIN users u ON u.id=p.user_id AND u.status='active' WHERE p.status='proposed' ORDER BY p.updated_at LIMIT 300");
        foreach($q->fetchAll() as $row){
            $checked++;
            foreach(az_video_agent_rules($row,time()) as $rule){
                $fingerprint=hash('sha256',$row['id'].'|'.$rule.'|'.$row['status']);
                $db->beginTransaction();
                try{
                    $existing=$db->prepare('SELECT id FROM video_agent_deliveries WHERE user_id=? AND rule=? AND fingerprint=?');
                    $existing->execute([$row['user_id'],$rule,$fingerprint]);
                    if($existing->fetchColumn()){$db->commit();continue;}
                    $db->prepare('INSERT INTO video_agent_deliveries(id,user_id,plan_id,rule,fingerprint,created_at) VALUES(?,?,?,?,?,?)')
                        ->execute([az_id(),$row['user_id'],$row['id'],$rule,$fingerprint,az_now()]);
                    az_agent_workspace_emit((int)$row['user_id'],(int)$row['user_id'],'video_review_due',
                        'Your editing plan is awaiting review','Review your proposed cut before accepting or discarding it.',
                        'my-editor.php?projectId='.$row['edit_project_id'].'&planId='.$row['id'],true);
                    $db->commit();$delivered++;
                }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
            }
        }
    }finally{flock($lock,LOCK_UN);fclose($lock);}
    return ['checked'=>$checked,'delivered'=>$delivered,'truncated'=>$checked>=300];
}
