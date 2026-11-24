<?php
declare(strict_types=1);
/**
 * Publishing & Audience Growth Specialist. Verified Watch analytics; no private
 * viewing history, no external marketing sends, and no AI analysis of footage.
 * Scheduled publication is possible only after a specific admin approval of a
 * consented submission. Every due job rechecks account/media/revision/status.
 */
function az_upgrade_agent_publishing_schema(PDO $db): void
{
    if (az_schema_version($db)>=35 || az_schema_version($db)<34) return;
    az_prepare_storage();
    $lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Publishing migration lock unavailable.');
    try {
        if (az_schema_version($db)>=35) return;
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $uid=$mysql?'BIGINT UNSIGNED':'INTEGER';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS publishing_agent_schedules (
            video_id CHAR(32) PRIMARY KEY, user_id $uid NOT NULL, approved_by $uid NOT NULL,
            source_revision INTEGER NOT NULL, publish_at VARCHAR(32) NOT NULL,
            state VARCHAR(24) NOT NULL, created_at VARCHAR(32) NOT NULL,
            updated_at VARCHAR(32) NOT NULL, executed_at VARCHAR(32) NULL,
            FOREIGN KEY(video_id) REFERENCES model_videos(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(approved_by) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS publishing_agent_promotions (
            id CHAR(32) PRIMARY KEY, video_id CHAR(32) NOT NULL, user_id $uid NOT NULL,
            created_by $uid NOT NULL, video_revision INTEGER NOT NULL,
            channel VARCHAR(16) NOT NULL, state VARCHAR(20) NOT NULL, headline VARCHAR(160) NOT NULL,
            copy_text VARCHAR(900) NOT NULL, featured_at VARCHAR(32) NULL,
            created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL,
            FOREIGN KEY(video_id) REFERENCES model_videos(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        if($mysql) {
            $q=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
            foreach([['publishing_agent_schedules','publishing_due','(state,publish_at)'],['publishing_agent_promotions','publishing_features','(state,featured_at)']] as [$table,$index,$columns]){
                $q->execute([$table,$index]);if(!(int)$q->fetchColumn())$db->exec("CREATE INDEX $index ON $table $columns");
            }
        } else {
            $db->exec('CREATE INDEX IF NOT EXISTS publishing_due ON publishing_agent_schedules(state,publish_at)');
            $db->exec('CREATE INDEX IF NOT EXISTS publishing_features ON publishing_agent_promotions(state,featured_at)');
        }
        $db->exec('UPDATE studio_schema SET version=35 WHERE id=1');
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}

/** Strict model/admin displayed Arizona local time; database always stores UTC. */
function az_publishing_schedule_time(mixed $input,?int $now=null): string
{
    if (!is_string($input)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d$/D',$input))
        throw new AzHttpError(400,'Enter a valid Arizona publication date and time.','invalid_schedule');
    $zone=new DateTimeZone('America/Phoenix');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$input,$zone);
    if(!$date||$date->format('Y-m-d\TH:i')!==$input)throw new AzHttpError(400,'Publication date is invalid.','invalid_schedule');
    $ts=$date->getTimestamp();$now=$now??time();
    if($ts<$now+900||$ts>$now+180*86400)
        throw new AzHttpError(400,'Schedule between 15 minutes and 180 days from now.','invalid_schedule');
    return gmdate('Y-m-d\TH:i:s\Z',$ts);
}
function az_publishing_video_eligible(array $row): bool
{
    $owner=az_account_user((int)$row['user_id']);
    return $owner!==null&&($owner['status']??'')==='active'&&az_has_role($owner,'model')
        &&(int)$row['rights_confirmed']===1&&str_starts_with((string)$row['mime_type'],'video/')
        &&is_file(az_media_path($row));
}
/** Called only inside the authenticated, CSRF-checked canonical admin moderation API. */
function az_publishing_schedule_video(array $row,int $admin,mixed $time): array
{
    if($row['status']!=='pending_review')throw new AzHttpError(409,'Only submitted videos can be scheduled.','invalid_status');
    if(!az_publishing_video_eligible($row))throw new AzHttpError(409,'Model, sharing consent, or source video is unavailable.','video_unavailable');
    $publishAt=az_publishing_schedule_time($time);$now=az_now();$db=az_db();
    $db->beginTransaction();
    try {
        $rev=(int)$row['revision'];
        $q=$db->prepare("UPDATE model_videos SET status='scheduled',published_at=NULL,reviewed_at=?,reviewed_by=?,public_credit=?,review_note='',revision=revision+1,updated_at=? WHERE id=? AND status='pending_review' AND revision=? AND rights_confirmed=1");
        $q->execute([$now,$admin,az_model_video_credit((int)$row['user_id']),$now,$row['id'],$rev]);
        if($q->rowCount()!==1)throw new AzHttpError(409,'Submission changed before scheduling.','revision_conflict');
        $exists=$db->prepare('SELECT video_id FROM publishing_agent_schedules WHERE video_id=?');$exists->execute([$row['id']]);
        if($exists->fetchColumn())$db->prepare("UPDATE publishing_agent_schedules SET user_id=?,approved_by=?,source_revision=?,publish_at=?,state='scheduled',updated_at=?,executed_at=NULL WHERE video_id=?")
            ->execute([$row['user_id'],$admin,$rev+1,$publishAt,$now,$row['id']]);
        else $db->prepare("INSERT INTO publishing_agent_schedules(video_id,user_id,approved_by,source_revision,publish_at,state,created_at,updated_at) VALUES(?,?,?,?,?,'scheduled',?,?)")
            ->execute([$row['id'],$row['user_id'],$admin,$rev+1,$publishAt,$now,$now]);
        $db->commit();
    } catch(Throwable $e) {if($db->inTransaction())$db->rollBack();throw $e;}
    az_agent_workspace_video_event($admin,(int)$row['user_id'],'video_scheduled','Video approved for scheduled publication',
        'The administrator approved publication for '.(new DateTimeImmutable($publishAt))->setTimezone(new DateTimeZone('America/Phoenix'))->format('M j, g:i A').' Arizona time.',$row['id'],false);
    return az_model_video_data(az_model_video_any_row($row['id']));
}
/** Invalidate outstanding approval as soon as consent, status, or source changes. */
function az_publishing_cancel_schedule(string $id): void
{
    az_db()->prepare("UPDATE publishing_agent_schedules SET state='cancelled',updated_at=? WHERE video_id=? AND state='scheduled'")
        ->execute([az_now(),$id]);
}
function az_publishing_schedule_lookup(string $id): ?array
{
    $q=az_db()->prepare('SELECT publish_at,state,source_revision,approved_by,updated_at FROM publishing_agent_schedules WHERE video_id=?');
    $q->execute([$id]);$row=$q->fetch();return $row?:null;
}
/** Idempotent cron. No new approval is inferred from content or a mere date. */
function az_publishing_scan(): array
{
    $db=az_db();$lock=az_community_lock();$checked=0;$published=0;$blocked=0;$now=az_now();
    try {
        $q=$db->prepare("SELECT s.video_id,s.user_id,s.source_revision,s.publish_at,s.approved_by FROM publishing_agent_schedules s WHERE s.state='scheduled' AND s.publish_at<=? ORDER BY s.publish_at,s.video_id LIMIT 100");
        $q->execute([$now]);$rows=$q->fetchAll();
        foreach($rows as $plan){$checked++;
            try{$row=az_model_video_any_row($plan['video_id']);}catch(AzHttpError){continue;}
            $valid=$row['status']==='scheduled'&&(int)$row['revision']===(int)$plan['source_revision']&&az_publishing_video_eligible($row);
            $db->beginTransaction();
            try {
                if($valid){
                    $change=$db->prepare("UPDATE model_videos SET status='published',published_at=?,revision=revision+1,updated_at=? WHERE id=? AND status='scheduled' AND revision=? AND rights_confirmed=1");
                    $change->execute([$now,$now,$plan['video_id'],$plan['source_revision']]);
                    $valid=$change->rowCount()===1;
                }
                if(!$valid){
                    // Fail closed: do not leave a scheduled badge indefinitely on an
                    // ineligible clip. A newer owner revision is never overwritten.
                    $db->prepare("UPDATE model_videos SET status='changes_requested',review_note='Scheduled publication could not verify the source or model eligibility.',revision=revision+1,updated_at=? WHERE id=? AND status='scheduled' AND revision=?")
                       ->execute([$now,$plan['video_id'],$plan['source_revision']]);
                    $db->prepare("UPDATE publishing_agent_schedules SET state='blocked',updated_at=?,executed_at=? WHERE video_id=? AND state='scheduled'")
                       ->execute([$now,$now,$plan['video_id']]);$blocked++;
                } else {
                    $db->prepare("UPDATE publishing_agent_schedules SET state='published',updated_at=?,executed_at=? WHERE video_id=? AND state='scheduled'")
                       ->execute([$now,$now,$plan['video_id']]);$published++;
                }
                $db->commit();
            }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
            if($valid)az_agent_workspace_video_event((int)$plan['approved_by'],(int)$plan['user_id'],'video_published','Scheduled video published',
                'The scheduled, administrator-approved video is now public on Watch.',$plan['video_id'],true);
            else az_agent_workspace_video_event((int)$plan['approved_by'],(int)$plan['user_id'],
                'publication_blocked','Scheduled publication needs review',
                'The approved release was blocked because source, consent, account or revision checks failed.',
                $plan['video_id'],true);
        }
    }finally{flock($lock,LOCK_UN);fclose($lock);}
    return ['checked'=>$checked,'published'=>$published,'blocked'=>$blocked,'truncated'=>$checked>=100];
}

/** Count only aggregated public playback; this system never stores viewer identities. */
function az_publishing_analytics(array $user): array
{
    $admin=az_has_role($user,'admin');$owner=(int)$user['id'];$since=gmdate('Y-m-d',time()-59*86400);
    $q=az_db()->prepare("SELECT d.view_day,d.play_starts,d.player_completions,d.watched_seconds,p.id AS video_id,p.title
        FROM published_video_engagement_daily d
        JOIN model_videos p ON p.id=d.video_id AND d.video_kind='creator'
        JOIN users u ON u.id=p.user_id AND u.status='active'
        WHERE d.view_day>=? AND p.status='published' AND p.published_at IS NOT NULL AND p.rights_confirmed=1
          AND EXISTS(SELECT 1 FROM user_roles ur WHERE ur.user_id=p.user_id AND ur.role='model')".($admin?'':' AND p.user_id=?')." ORDER BY d.view_day DESC");
    $args=[$since];if(!$admin)$args[]=$owner;$q->execute($args);
    $daily=[];$byVideo=[];
    $rows=$q->fetchAll();
    // Published interview playback is credited to the linked active model only;
    // admins can see the aggregate studio performance. No viewer identities.
    $interviews=az_db()->prepare("SELECT d.view_day,d.play_starts,d.player_completions,d.watched_seconds,v.id AS video_id,v.fan_title AS title
        FROM published_video_engagement_daily d
        JOIN finished_videos v ON v.id=d.video_id AND d.video_kind='production'
        JOIN interviews i ON i.id=v.interview_id
        JOIN users u ON u.id=v.owner_id
        LEFT JOIN model_profiles mp ON mp.id=v.model_id
        WHERE d.view_day>=? AND ".az_public_video_conditions().($admin?'':' AND mp.linked_user_id=? AND mp.status=\'active\'')." ORDER BY d.view_day DESC");
    $interviewArgs=[$since];if(!$admin)$interviewArgs[]=$owner;$interviews->execute($interviewArgs);
    $rows=array_merge($rows,$interviews->fetchAll());
    foreach($rows as $r){$day=$r['view_day'];$id=$r['video_id'];
        foreach(['play_starts'=>'starts','player_completions'=>'completions','watched_seconds'=>'seconds'] as $col=>$key){
            $daily[$day][$key]=($daily[$day][$key]??0)+(int)$r[$col];
            $byVideo[$id][$key]=($byVideo[$id][$key]??0)+(int)$r[$col];
        }
        $byVideo[$id]['id']=$id;$byVideo[$id]['title']=$r['title'];
    }
    $trend=[];$current=['starts'=>0,'completions'=>0,'seconds'=>0];$previous=$current;
    for($offset=59;$offset>=0;$offset--){$day=gmdate('Y-m-d',time()-$offset*86400);$point=['day'=>$day,'starts'=>(int)($daily[$day]['starts']??0),'completions'=>(int)($daily[$day]['completions']??0),'seconds'=>(int)($daily[$day]['seconds']??0)];
        foreach($current as $key=>$unused)${$offset<30?'current':'previous'}[$key]+=$point[$key];
        if($offset<14)$trend[]=$point;
    }
    foreach($byVideo as &$v){$v['completionRate']=$v['starts']?round(100*$v['completions']/$v['starts'],1):0;$v['watchedMinutes']=(int)floor($v['seconds']/60);unset($v['seconds']);}unset($v);
    usort($byVideo,static fn($a,$b)=>$b['starts']<=>$a['starts']);
    return ['current'=>$current,'previous'=>$previous,'trend'=>$trend,'topVideos'=>array_slice($byVideo,0,8),
        'completionRate'=>$current['starts']?round(100*$current['completions']/$current['starts'],1):0,
        'watchMinutes'=>(int)floor($current['seconds']/60),
        'timingObservation'=>az_publishing_timing_observation($trend),
        'basis'=>'Aggregate public creator and interview playback, session deduplicated. 30-day periods; no attribution to external campaigns.'];
}
/** Only report measured patterns, never claim predicted engagement. */
function az_publishing_timing_observation(array $trend): array
{
    $totals=[];$total=0;
    foreach($trend as $point){
        $day=DateTimeImmutable::createFromFormat('!Y-m-d',$point['day'],new DateTimeZone('UTC'));
        if(!$day)continue;$name=$day->format('l');$starts=max(0,(int)$point['starts']);
        $totals[$name]=($totals[$name]??0)+$starts;$total+=$starts;
    }
    if($total<20)return ['quality'=>'insufficient','message'=>'There are not yet 20 measured starts across the last 14 days; choose a publication time manually and check your existing event calendar.'];
    arsort($totals);$best=array_key_first($totals);
    return ['quality'=>'observed','day'=>$best,'message'=>"$best recorded the most play starts in the last 14 days. This is a small observational sample, not a prediction of future audience activity."];
}
/** Metadata-based drafting aid: no semantic model, transcript or footage analysis. */
function az_publishing_metadata_copy(string $title,string $description): array
{
    $title=mb_safe_trim(trim(preg_replace('/[\x00-\x1F\x7F]/u',' ',$title)??''),160);
    $description=mb_safe_trim(trim(preg_replace('/[\x00-\x1F\x7F]/u',' ',$description)??''),700);
    return ['headline'=>$title,'copy'=>$description!==''?$description:('Watch '.$title.' on AZHottie.'),
       'basis'=>'Copied from approved, creator-provided metadata. Review before publishing.'];
}
function az_publishing_promo_list(array $user): array
{
    $admin=az_has_role($user,'admin');$q=az_db()->prepare("SELECT p.id,p.video_id,p.channel,p.state,p.headline,p.copy_text,p.created_at,p.updated_at,p.featured_at,v.title
       FROM publishing_agent_promotions p JOIN model_videos v ON v.id=p.video_id
       WHERE ".($admin?'1=1':'p.user_id=?')." ORDER BY p.updated_at DESC LIMIT 60");
    $q->execute($admin?[]:[(int)$user['id']]);return $q->fetchAll();
}
function az_publishing_promo_create(array $body,int $admin): array
{
    $row=az_model_video_any_row(az_text($body['videoId']??'',32,'Video ID'));
    if($row['status']!=='published'||!az_publishing_video_eligible($row))throw new AzHttpError(409,'Only a public, approved video can be promoted.','not_public');
    $channel=az_text($body['channel']??'',16,'Channel');
    if(!in_array($channel,['onsite','social'],true))throw new AzHttpError(400,'Choose on-site or social draft.','invalid_channel');
    $headline=az_text($body['headline']??'',160,'Headline');$copy=az_text($body['copy']??'',900,'Promotion copy');
    if($headline===''||$copy==='')throw new AzHttpError(400,'Headline and promotion copy are required.','copy_required');
    $now=az_now();$id=az_id();
    az_db()->prepare("INSERT INTO publishing_agent_promotions(id,video_id,user_id,created_by,video_revision,channel,state,headline,copy_text,created_at,updated_at) VALUES(?,?,?,?,?,?,'draft',?,?,?,?)")
      ->execute([$id,$row['id'],$row['user_id'],$admin,(int)$row['revision'],$channel,$headline,$copy,$now,$now]);
    az_agent_workspace_emit($admin,$admin,'promotion_draft','Promotion copy drafted','Draft is private until explicit approval.','publishing.php');
    return ['id'=>$id,'state'=>'draft'];
}
function az_publishing_promo_action(array $body,int $admin): array
{
    $id=az_text($body['id']??'',32,'Promotion ID');if(!az_valid_id($id))throw new AzHttpError(400,'Invalid promotion.','invalid_id');
    $q=az_db()->prepare('SELECT p.*,v.status,v.rights_confirmed FROM publishing_agent_promotions p JOIN model_videos v ON v.id=p.video_id WHERE p.id=?');$q->execute([$id]);
    $plan=$q->fetch();if(!$plan)throw new AzHttpError(404,'Promotion not found.','not_found');
    $action=az_text($body['decision']??'',16,'Decision');
    if(!in_array($action,['approve','retire'],true))throw new AzHttpError(400,'Invalid promotion decision.','invalid_decision');
    if($action==='approve'){
        if($plan['state']!=='draft')throw new AzHttpError(409,'Only drafts can be approved.','invalid_status');
        $row=az_model_video_any_row($plan['video_id']);
        if($row['status']!=='published'||!az_publishing_video_eligible($row)||(int)$row['revision']!==(int)$plan['video_revision'])throw new AzHttpError(409,'Video changed since this draft. Create a new promotion.','revision_conflict');
        $state=$plan['channel']==='onsite'?'featured':'approved';
    }else{
        if(!in_array($plan['state'],['featured','approved','draft'],true))throw new AzHttpError(409,'Promotion already inactive.','invalid_status');
        $state='retired';
    }
    $now=az_now();$q=az_db()->prepare('UPDATE publishing_agent_promotions SET state=?,featured_at=?,updated_at=? WHERE id=? AND state=?');
    $q->execute([$state,$state==='featured'?$now:null,$now,$id,$plan['state']]);
    if($q->rowCount()!==1)throw new AzHttpError(409,'Promotion changed. Reload.','revision_conflict');
    az_agent_workspace_emit($admin,$admin,'promotion_'.$state,'Promotion decision recorded',
        $state==='featured'?'An approved video is featured on Watch.':($state==='approved'?'Copy approved for manual sharing; no external service was contacted.':'Promotion retired.'),'publishing.php');
    return ['id'=>$id,'state'=>$state];
}
/** No private review notes, storage paths or owner IDs leak to public Watch. */
function az_publishing_public_feature(): ?array
{
    $q=az_db()->query("SELECT p.video_id,p.headline,p.copy_text FROM publishing_agent_promotions p
       JOIN model_videos v ON v.id=p.video_id
       JOIN users u ON u.id=v.user_id AND u.status='active'
       WHERE p.state='featured' AND p.channel='onsite' AND v.status='published' AND v.published_at IS NOT NULL
         AND v.rights_confirmed=1 AND p.video_revision=v.revision AND EXISTS(SELECT 1 FROM user_roles r WHERE r.user_id=v.user_id AND r.role='model')
       ORDER BY p.featured_at DESC LIMIT 1");
    $r=$q->fetch();if(!$r)return null;
    try{$item=az_model_video_public_data(az_model_video_public_row($r['video_id']));}
    catch(AzHttpError){return null;}
    return ['headline'=>$r['headline'],'copy'=>$r['copy_text'],'video'=>$item];
}
function az_publishing_scheduled_list(array $user): array
{
    $admin=az_has_role($user,'admin');$q=az_db()->prepare("SELECT s.video_id,s.publish_at,s.state,v.status,v.title,v.revision FROM publishing_agent_schedules s
        JOIN model_videos v ON v.id=s.video_id WHERE ".($admin?'1=1':'s.user_id=?')." ORDER BY s.publish_at DESC LIMIT 80");
    $q->execute($admin?[]:[(int)$user['id']]);return $q->fetchAll();
}
function az_publishing_eligible_videos(): array
{
    $q=az_db()->query("SELECT v.id,v.title,v.description,v.published_at FROM model_videos v JOIN users u ON u.id=v.user_id AND u.status='active'
        WHERE v.status='published' AND v.published_at IS NOT NULL AND v.rights_confirmed=1
        AND EXISTS(SELECT 1 FROM user_roles r WHERE r.user_id=v.user_id AND r.role='model')
        ORDER BY v.published_at DESC LIMIT 100");
    return $q->fetchAll();
}
function az_publishing_agent_briefing(array $user): array
{
    $admin=az_has_role($user,'admin');$id=(int)$user['id'];$cards=[];
    $q=az_db()->prepare("SELECT status,COUNT(*) n FROM model_videos WHERE ".($admin?'1=1':'user_id=?')." GROUP BY status");$q->execute($admin?[]:[$id]);
    $statuses=[];foreach($q->fetchAll() as $r)$statuses[$r['status']]=(int)$r['n'];
    $q=az_db()->prepare("SELECT COUNT(*) FROM publishing_agent_promotions WHERE ".($admin?"state='draft'":"user_id=? AND state='draft'"));$q->execute($admin?[]:[$id]);$draftPromos=(int)$q->fetchColumn();
    $counts=['publishingPending'=>(int)($statuses['pending_review']??0),'publishingScheduled'=>(int)($statuses['scheduled']??0),'publishingPublished'=>(int)($statuses['published']??0),'publishingDraftPromos'=>$draftPromos];
    if($admin&&function_exists('az_campaign_list')&&az_schema_version(az_db())>=49){
        $due=0;foreach(az_campaign_list($id) as $campaign){if($campaign['handoffDue'])$due++;}
        $counts['campaignHandoffsDue']=$due;
        if($due)$cards[]=az_agent_workspace_card('growth','Campaign handoffs due',
            $due.' planned campaign(s) are ready for a human publishing decision. No external platform was contacted.','publishing.php#pg-campaign-workspace',1);
    }
    $stats=az_publishing_analytics($user);
    if($admin){
        if($counts['publishingPending'])$cards[]=az_agent_workspace_card('publishing','Publishing approvals needed',
            $counts['publishingPending'].' submitted video(s) need a review before immediate or scheduled publication.','user-submissions.php',1);
        if($counts['publishingScheduled'])$cards[]=az_agent_workspace_card('publishing','Scheduled releases',
            $counts['publishingScheduled'].' admin-approved video(s) await their release time.','publishing.php',2);
        if($counts['publishingDraftPromos'])$cards[]=az_agent_workspace_card('growth','Promotion drafts need a decision',
            $counts['publishingDraftPromos'].' draft(s) can be approved to feature on Watch or copied for manual sharing.','publishing.php',3);
        if($stats['current']['starts']>$stats['previous']['starts'] && $stats['previous']['starts']>0)
            $cards[]=az_agent_workspace_card('growth','Creator-video engagement rising',
                '30-day video starts increased over the previous period. Review measured Watch activity before choosing a new feature.','publishing.php',4);
    }else{
        if($counts['publishingScheduled'])$cards[]=az_agent_workspace_card('publishing','Your scheduled videos',
            'An administrator approved publication for '.$counts['publishingScheduled'].' video(s). You can still withdraw your content.','my-videos.php',3);
    }
    return ['counts'=>$counts,'cards'=>$cards,'analytics'=>$stats,'basis'=>'Verified consented publications, moderator decisions and aggregate Watch counters. Promotions are approval-controlled.'];
}
function az_publishing_agent_chat_snapshot(array $user): array
{
    $b=az_publishing_agent_briefing($user);$a=$b['analytics'];
    return ['counts'=>$b['counts'],'plays30Days'=>$a['current']['starts'],'playsPrevious30Days'=>$a['previous']['starts'],
        'completions30Days'=>$a['current']['completions'],'watchMinutes30Days'=>$a['watchMinutes'],
        'basis'=>'Anonymized Watch counters and publication workflow only; no external campaign attribution or automatic promotion.',
        'nextStep'=>az_has_role($user,'admin')?'Review the submission queue and Publishing workspace. Approve or schedule public releases explicitly.':'Submit finished edits through My Videos. Only an administrator may schedule or publish.'];
}
