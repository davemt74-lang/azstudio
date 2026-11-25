<?php
declare(strict_types=1);
/** Private drafts and reviewed, retryable Creator Club publication. No billing. */
function az_creator_club_publishing_enabled(PDO $db):bool
{
    return az_schema_version($db)>=56;
}
function az_upgrade_creator_club_publishing_schema(PDO $db):void
{
    if(az_schema_version($db)>=56||az_schema_version($db)<55)return;
    az_prepare_storage();$lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Creator Club publishing schema lock unavailable.');
    $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    try{
        if(az_schema_version($db)>=56)return;
        if(!$mysql)$db->beginTransaction();
        // MySQL DDL commits independently, so every change can safely be replayed.
        foreach([
            ['publish_at','VARCHAR(32) NULL'],
            ['published_at','VARCHAR(32) NULL'],
            ['publish_timezone',"VARCHAR(64) NOT NULL DEFAULT 'America/Phoenix'"],
            ['media_revision','INTEGER NULL'],
            ['publish_error_code',"VARCHAR(64) NOT NULL DEFAULT ''"],
            ['publish_error',"VARCHAR(400) NOT NULL DEFAULT ''"],
            ['publish_checked_at','VARCHAR(32) NULL']
        ] as [$column,$definition]){
            if(!az_creator_club_has_column($db,'creator_club_posts',$column))$db->exec('ALTER TABLE creator_club_posts ADD COLUMN '.$column.' '.$definition);
        }
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS creator_club_publish_runs (
            scope VARCHAR(12) PRIMARY KEY,run_token CHAR(32) NULL,running INTEGER NOT NULL DEFAULT 0,
            started_at VARCHAR(32) NULL,completed_at VARCHAR(32) NULL,error_at VARCHAR(32) NULL,
            checked_count INTEGER NOT NULL DEFAULT 0,published_count INTEGER NOT NULL DEFAULT 0,
            blocked_count INTEGER NOT NULL DEFAULT 0,skipped_count INTEGER NOT NULL DEFAULT 0,
            error_count INTEGER NOT NULL DEFAULT 0,truncated INTEGER NOT NULL DEFAULT 0)$suffix");
        foreach(['cron','manual'] as $scope){
            $q=$db->prepare('SELECT scope FROM creator_club_publish_runs WHERE scope=?');$q->execute([$scope]);
            if($q->fetchColumn()===false)$db->prepare('INSERT INTO creator_club_publish_runs(scope) VALUES(?)')->execute([$scope]);
        }
        if($mysql){
            $q=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');$q->execute(['creator_club_posts','club_posts_due']);
            if(!(int)$q->fetchColumn())$db->exec('CREATE INDEX club_posts_due ON creator_club_posts(state,publish_at)');
        }else $db->exec('CREATE INDEX IF NOT EXISTS club_posts_due ON creator_club_posts(state,publish_at)');
        // Existing publication dates are historical facts, not migration time.
        $db->exec("UPDATE creator_club_posts SET published_at=COALESCE(NULLIF(reviewed_at,''),created_at) WHERE state='approved' AND published_at IS NULL");
        $mediaRevision=az_creator_club_has_column($db,'personal_media','revision')?'m.revision':'1';
        $db->exec('UPDATE creator_club_posts SET media_revision=(SELECT '.$mediaRevision.' FROM personal_media m WHERE m.id=creator_club_posts.media_id AND m.user_id=creator_club_posts.creator_id) WHERE media_id IS NOT NULL AND media_revision IS NULL');
        $db->exec('UPDATE studio_schema SET version=56 WHERE id=1');
        if(!$mysql)$db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    finally{flock($lock,LOCK_UN);fclose($lock);}
}
/** A local wall time must identify exactly one UTC instant, including around DST. */
function az_creator_club_parse_publish_time(string $local,string $timezone='America/Phoenix'):array
{
    $local=trim($local);$timezone=trim($timezone);if($timezone==='')$timezone='America/Phoenix';
    if(!in_array($timezone,DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true))throw new AzHttpError(400,'Choose a valid IANA time zone, such as America/Phoenix.','invalid_publish_timezone');
    if($local==='')return [null,$timezone];
    if(!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}(?::[0-9]{2})?\z/',$local))throw new AzHttpError(400,'Choose a valid local date and time for publication.','invalid_publish_time');
    $canonical=strlen($local)===16?$local.':00':$local;
    $wall=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s',$canonical,new DateTimeZone('UTC'));
    $errors=DateTimeImmutable::getLastErrors();
    if(!$wall||($errors!==false&&($errors['warning_count']||$errors['error_count']))||$wall->format('Y-m-d\TH:i:s')!==$canonical)throw new AzHttpError(400,'Choose a valid calendar date and time for publication.','invalid_publish_time');
    $zone=new DateTimeZone($timezone);$epoch=$wall->getTimestamp();$matches=[];
    // Try the actual offsets near this wall time. This catches gaps and repeated
    // hours without relying on PHP's silent normalization or preferred offset.
    foreach($zone->getTransitions($epoch-172800,$epoch+172800)?:[] as $transition){
        $candidate=$epoch-(int)$transition['offset'];
        if((new DateTimeImmutable('@'.$candidate))->setTimezone($zone)->format('Y-m-d\TH:i:s')===$canonical)$matches[$candidate]=true;
    }
    if(!$matches)throw new AzHttpError(400,'This local time does not exist in that time zone. Choose another time.','invalid_publish_time');
    if(count($matches)!==1)throw new AzHttpError(400,'This local time occurs twice because clocks change. Choose an unambiguous time or use UTC.','ambiguous_publish_time');
    return [gmdate('Y-m-d\TH:i:s\Z',(int)array_key_first($matches)),$timezone];
}
function az_creator_club_publish_time_valid(?string $value):bool
{
    if($value===null)return true;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$value,new DateTimeZone('UTC'));
    $errors=DateTimeImmutable::getLastErrors();
    return $date!==false&&($errors===false||(!$errors['warning_count']&&!$errors['error_count']))&&$date->format('Y-m-d\TH:i:s\Z')===$value;
}
function az_creator_club_post_input(array $body,string $key,string $fallback='',string $default=''):string
{
    $value=$body[$key]??($fallback!==''?($body[$fallback]??$default):$default);
    if(!is_string($value)&&!is_int($value)&&!is_bool($value)&&$value!==null)throw new AzHttpError(400,'Provide valid post fields.','invalid_post');
    return (string)$value;
}
/** Save and submit share one transaction; validation failures never partly save. */
function az_creator_club_save_post(array $actor,array $body,bool $submit=false,?PDO $db=null):array
{
    $db??=az_db();
    if(!az_creator_club_publishing_enabled($db))throw new AzHttpError(409,'Creator Club publishing is awaiting a database upgrade.','publishing_unavailable');
    $creator=(int)($actor['id']??0);$id=trim(az_creator_club_post_input($body,'id'));
    $revisionValue=az_creator_club_post_input($body,'revision','','0');$revision=(int)$revisionValue;
    if($id!==''&&(!az_valid_id($id)||!ctype_digit($revisionValue)||$revision<1))throw new AzHttpError(400,'Reload this post before saving it.','invalid_post');
    $title=trim(preg_replace('/[\x00-\x1F\x7F]/u',' ',az_creator_club_post_input($body,'title'))??'');$text=trim(az_creator_club_post_input($body,'body'));
    $audience=az_creator_club_post_input($body,'audience','','followers');$tierId=trim(az_creator_club_post_input($body,'tierId','tier_id'));$mediaId=trim(az_creator_club_post_input($body,'mediaId','media_id'));
    $rights=in_array(strtolower(az_creator_club_post_input($body,'rightsConfirmed','rights_confirmed')),['1','true','on'],true);
    [$publishAt,$timezone]=az_creator_club_parse_publish_time(az_creator_club_post_input($body,'publishLocal','publish_local'),az_creator_club_post_input($body,'publishTimezone','publish_timezone','America/Phoenix'));
    if(strlen($title)>160||strlen($text)>6000)throw new AzHttpError(400,'Use up to 160 characters for the title and 6,000 for the post.','invalid_post');
    if(!in_array($audience,['followers','tier'],true))throw new AzHttpError(400,'Choose a valid post audience.','invalid_audience');
    return az_creator_club_write($db,function()use($db,$actor,$creator,$id,$revision,$title,$text,$audience,$tierId,$mediaId,$rights,$publishAt,$timezone,$submit){
        // This creator lock is shared by reviews, withdrawals, archival and cron.
        $db->prepare('UPDATE users SET status=status WHERE id=?')->execute([$creator]);
        az_creator_club_require_creator($actor,$creator,$db);$current=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$row=null;
        if($id!==''){
            $q=$db->prepare('SELECT * FROM creator_club_posts WHERE id=? AND creator_id=?'.$current);$q->execute([$id,$creator]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row)throw new AzHttpError(404,'Post not found.','not_found');
            if((int)$row['revision']!==$revision||!in_array($row['state'],['draft','pending','rejected','withdrawn'],true))throw new AzHttpError(409,'This post changed. Withdraw a published or scheduled post before editing, then reload its current version.','stale_post');
        }
        $mediaId=$mediaId===''?null:$mediaId;
        if($submit){
            [$tierId,$mediaId,$title,$text]=az_creator_club_validate_post($db,$actor,$tierId,$mediaId,$title,$text,$rights,$audience);
        }else{
            // An incomplete draft may omit a tier, body, title or rights checkbox.
            // Any references it does contain must still belong to this creator.
            if($audience==='tier'&&$tierId!==''){
                if(!az_valid_id($tierId))throw new AzHttpError(400,'Choose one of your Creator Club tiers.','invalid_tier');
                $q=$db->prepare('SELECT 1 FROM creator_club_tiers WHERE id=? AND creator_id=?'.$current);$q->execute([$tierId,$creator]);
                if(!$q->fetchColumn())throw new AzHttpError(403,'Choose one of your Creator Club tiers.','tier_forbidden');
            }
            $tierId=$audience==='tier'&&$tierId!==''?$tierId:null;
            if($mediaId!==null)az_creator_club_validate_media($db,$mediaId,$creator);
        }
        $mediaRevision=$mediaId!==null?(int)(az_creator_club_validate_media($db,$mediaId,$creator)['revision']??1):null;
        $state=$submit?'pending':'draft';$now=az_now();$newId=$id!==''?$id:az_id();$nextRevision=$row?$revision+1:1;
        $note=$submit?'':(string)($row['review_note']??'');
        if($row){
            $q=$db->prepare("UPDATE creator_club_posts SET tier_id=?,media_id=?,media_revision=?,title=?,body=?,audience=?,rights_confirmed=?,state=?,review_note=?,reviewed_by=NULL,reviewed_at=NULL,publish_at=?,published_at=NULL,publish_timezone=?,publish_error_code='',publish_error='',publish_checked_at=NULL,revision=revision+1,updated_at=? WHERE id=? AND creator_id=? AND revision=? AND state IN ('draft','pending','rejected','withdrawn')");
            $q->execute([$tierId,$mediaId,$mediaRevision,$title,$text,$audience,$rights?1:0,$state,$note,$publishAt,$timezone,$now,$newId,$creator,$revision]);
            if($q->rowCount()!==1)throw new AzHttpError(409,'Post changed. Reload before saving.','stale_post');
        }else{
            $db->prepare('INSERT INTO creator_club_posts(id,creator_id,tier_id,media_id,media_revision,title,body,audience,rights_confirmed,state,revision,created_at,updated_at,publish_at,publish_timezone) VALUES(?,?,?,?,?,?,?,?,?,?,1,?,?,?,?)')->execute([$newId,$creator,$tierId,$mediaId,$mediaRevision,$title,$text,$audience,$rights?1:0,$state,$now,$now,$publishAt,$timezone]);
        }
        az_creator_club_audit($db,'post',$newId,$creator,$submit?($row?'resubmitted':'submitted'):'draft_saved');
        if($submit)az_creator_club_notify_review($db,'post',$newId,$creator,$nextRevision);
        return ['id'=>$newId,'state'=>$state,'revision'=>$nextRevision,'publish_at'=>$publishAt,'published_at'=>null,'publish_timezone'=>$timezone];
    });
}
/** Recheck the exact persisted content at approval and again at release. */
function az_creator_club_validate_stored_post(PDO $db,array $post,bool $release=false):void
{
    az_creator_club_validate_post($db,['id'=>(int)$post['creator_id'],'roles'=>['model']],(string)($post['tier_id']??''),$post['media_id']===null?null:(string)$post['media_id'],(string)$post['title'],(string)$post['body'],(int)$post['rights_confirmed']===1,(string)($post['audience']??'tier'));
    az_creator_club_validate_media_revision($db,$post);
    if(!az_creator_club_publish_time_valid($post['publish_at']??null))throw new AzHttpError(409,'The saved publication time is invalid. Withdraw this schedule and choose the time again.','invalid_publish_time');
    if(!in_array((string)($post['publish_timezone']??''),DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true))throw new AzHttpError(409,'The saved publication time zone is invalid. Withdraw this schedule and choose it again.','invalid_publish_timezone');
    if($release)az_creator_club_validate_publish_visibility($db,(int)$post['creator_id']);
}
function az_creator_club_validate_media_revision(PDO $db,array $post):void
{
    if($post['media_id']===null)return;
    $media=az_creator_club_validate_media($db,(string)$post['media_id'],(int)$post['creator_id']);
    if((int)($post['media_revision']??0)<1||(int)$post['media_revision']!==(int)($media['revision']??1))throw new AzHttpError(409,'The selected video changed after this post was saved. Withdraw it if scheduled or published, then reselect the video and submit the post for review again.','media_changed');
}
function az_creator_club_validate_publish_visibility(PDO $db,int $creator):void
{
    $current=$db->inTransaction()&&$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    $q=$db->prepare('SELECT visibility FROM user_social_profiles WHERE user_id=?'.$current);$q->execute([$creator]);
    if(!in_array($q->fetchColumn(),['members','public'],true))throw new AzHttpError(409,'Make this creator profile visible to members or the public before publishing.','creator_visibility');
}
/** Return safe, actionable feedback, never private text or database error detail. */
function az_creator_club_publish_blocker(AzHttpError $error):array
{
    return match($error->errorCode){
        'creator_missing','creator_forbidden','account_inactive'=>['creator_unavailable','The creator account is unavailable. This post stays private until the creator account is active again.'],
        'creator_visibility'=>['creator_visibility','The creator profile is private or unavailable. Make it visible to members or the public before this scheduled post can publish.'],
        'invalid_tier','tier_forbidden','tier_not_approved'=>['tier_unavailable','The selected tier is no longer active and approved. Restore the tier, or withdraw this schedule and choose another tier.'],
        'not_found'=>['media_unavailable','The selected private media is unavailable. Withdraw this schedule, choose available media and submit it for review again.'],
        'media_changed'=>['media_changed','The selected video changed after submission. Withdraw this schedule, reselect the video and submit the post for review again.'],
        'rights_required','rights_not_confirmed'=>['rights_required','Rights and participant consent must be confirmed. Withdraw this schedule and submit it for review again.'],
        'invalid_audience'=>['invalid_audience','The selected audience is unavailable. Withdraw this schedule, choose an audience and submit it for review again.'],
        'invalid_publish_time','invalid_publish_timezone'=>[$error->errorCode,'The saved publication time or time zone is invalid. Withdraw this schedule and choose it again.'],
        'review_required'=>['review_required','This version has no recorded approval. Withdraw this schedule and submit it for review again.'],
        default=>['invalid_post','This post needs changes before publication. Withdraw this schedule, check its title, content and media, then submit it for review again.']
    };
}
/** Process bounded batches; blocked rows rotate behind unchecked rows on retries. */
function az_creator_club_publish_due(?PDO $db=null,int $limit=50,string $scope='cron'):array
{
    $db??=az_db();
    if(!az_creator_club_publishing_enabled($db))throw new RuntimeException('Creator Club publishing schema is unavailable.');
    if(!in_array($scope,['cron','manual'],true))throw new InvalidArgumentException('Invalid Creator Club publication scope.');
    if($db->inTransaction())throw new LogicException('Run scheduled publication outside an existing transaction.');
    $limit=max(1,min($limit,250));$token=az_id();$started=az_now();
    $result=['checked'=>0,'published'=>0,'blocked'=>0,'skipped'=>0,'errors'=>0,'truncated'=>false,'completedAt'=>null,'scope'=>$scope];
    $db->prepare('UPDATE creator_club_publish_runs SET run_token=?,running=1,started_at=?,error_at=NULL WHERE scope=?')->execute([$token,$started,$scope]);
    try{
        $q=$db->prepare("SELECT id,creator_id,revision FROM creator_club_posts WHERE state='scheduled' AND publish_at IS NOT NULL AND publish_at<=? ORDER BY CASE WHEN publish_checked_at IS NULL THEN 0 ELSE 1 END,publish_checked_at,publish_at,id LIMIT ?");
        $q->bindValue(1,$started,PDO::PARAM_STR);$q->bindValue(2,$limit+1,PDO::PARAM_INT);$q->execute();$candidates=$q->fetchAll(PDO::FETCH_ASSOC);
        $result['truncated']=count($candidates)>$limit;
        foreach(array_slice($candidates,0,$limit) as $candidate){
            $result['checked']++;
            try{
                $outcome=az_creator_club_write($db,function()use($db,$candidate){
                    $creator=(int)$candidate['creator_id'];$db->prepare('UPDATE users SET status=status WHERE id=?')->execute([$creator]);
                    $current=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
                    $q=$db->prepare('SELECT * FROM creator_club_posts WHERE id=? AND creator_id=?'.$current);$q->execute([$candidate['id'],$creator]);$post=$q->fetch(PDO::FETCH_ASSOC);$now=az_now();
                    if(!$post||$post['state']!=='scheduled'||(int)$post['revision']!==(int)$candidate['revision']||$post['publish_at']===null||$post['publish_at']>$now)return 'skipped';
                    try{
                        if(empty($post['reviewed_by'])||empty($post['reviewed_at'])||$post['published_at']!==null)throw new AzHttpError(409,'Recorded approval is required.','review_required');
                        az_creator_club_validate_stored_post($db,$post,true);
                    }catch(AzHttpError $error){
                        [$code,$message]=az_creator_club_publish_blocker($error);
                        $db->prepare("UPDATE creator_club_posts SET publish_error_code=?,publish_error=?,publish_checked_at=? WHERE id=? AND creator_id=? AND revision=? AND state='scheduled'")->execute([$code,$message,$now,$post['id'],$creator,$post['revision']]);
                        if($post['publish_error_code']!==$code||$post['publish_error']!==$message){
                            $actor=(int)($post['reviewed_by']?:$creator);az_creator_club_audit($db,'post',$post['id'],$actor,'schedule_blocked',$message);
                            az_creator_club_queue($db,'schedule-blocked:'.$post['id'].':'.$post['revision'].':'.$code,$actor,$creator,'schedule_blocked','Scheduled Creator Club post needs attention','A scheduled post remains private because a publication check failed. Open your club to review the current issue.','creator-club.php?creator='.$creator);
                        }
                        return 'blocked';
                    }
                    // The state, revision and outbox commit together. Another worker
                    // can only see the released version or retry the entire release.
                    $released=az_now();$q=$db->prepare("UPDATE creator_club_posts SET state='approved',published_at=?,revision=revision+1,updated_at=?,publish_checked_at=?,publish_error_code='',publish_error='' WHERE id=? AND creator_id=? AND revision=? AND state='scheduled' AND published_at IS NULL AND publish_at<=?");
                    $q->execute([$released,$released,$released,$post['id'],$creator,$post['revision'],$released]);if($q->rowCount()!==1)return 'skipped';
                    $post['state']='approved';$post['published_at']=$released;$post['revision']=(int)$post['revision']+1;
                    $reviewer=(int)$post['reviewed_by'];az_creator_club_audit($db,'post',$post['id'],$reviewer,'published');
                    az_creator_club_notify_post($db,$post,$reviewer);
                    az_creator_club_queue($db,'release:'.$post['id'].':'.$post['revision'],$reviewer,$creator,'schedule_published','Scheduled Creator Club post published','A reviewed scheduled post has been published. Open your club to view its recorded publication time.','creator-club.php?creator='.$creator);
                    return 'published';
                });
                $result[$outcome]++;
            }catch(Throwable $error){
                $result['errors']++;
                error_log('Creator Club scheduled publication failed: '.$error->getMessage());
            }
        }
        $result['completedAt']=az_now();
        $db->prepare('UPDATE creator_club_publish_runs SET running=0,completed_at=?,error_at=?,checked_count=?,published_count=?,blocked_count=?,skipped_count=?,error_count=?,truncated=? WHERE scope=? AND run_token=?')->execute([$result['completedAt'],$result['errors']?$result['completedAt']:null,$result['checked'],$result['published'],$result['blocked'],$result['skipped'],$result['errors'],$result['truncated']?1:0,$scope,$token]);
        return $result;
    }catch(Throwable $error){
        $db->prepare('UPDATE creator_club_publish_runs SET running=0,error_at=?,error_count=? WHERE scope=? AND run_token=?')->execute([az_now(),max(1,$result['errors']),$scope,$token]);
        throw $error;
    }
}
/** Reads never run the publisher; a manual check never supplies a cron heartbeat. */
function az_creator_club_publish_status(?PDO $db=null):array
{
    $db??=az_db();$empty=['status'=>'not_run','startedAt'=>null,'completedAt'=>null,'errorAt'=>null,'checked'=>0,'published'=>0,'blocked'=>0,'skipped'=>0,'errors'=>0,'truncated'=>false];
    if(!az_creator_club_publishing_enabled($db))return $empty+['manual'=>$empty];
    $rows=$db->query('SELECT * FROM creator_club_publish_runs')->fetchAll(PDO::FETCH_ASSOC);$statuses=[];
    foreach($rows as $row){
        $status=$empty;$status['startedAt']=$row['started_at'];$status['completedAt']=$row['completed_at'];$status['errorAt']=$row['error_at'];
        foreach(['checked','published','blocked','skipped','errors'] as $key)$status[$key]=(int)$row[$key==='errors'?'error_count':$key.'_count'];
        $status['truncated']=(bool)$row['truncated'];$now=time();
        if((int)$row['running']===1)$status['status']=$row['started_at']!==null&&strtotime($row['started_at'])<$now-600?'stale':'running';
        elseif($row['error_at']!==null)$status['status']='attention';
        elseif($row['completed_at']!==null){
            $status['status']=strtotime($row['completed_at'])<$now-600?'stale':(($status['blocked']||$status['errors']||$status['truncated'])?'attention':'ok');
        }
        $statuses[$row['scope']]=$status;
    }
    return ($statuses['cron']??$empty)+['manual'=>$statuses['manual']??$empty];
}
