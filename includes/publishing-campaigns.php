<?php
declare(strict_types=1);
/** v1.53: approved-video campaign planner; no external posting or private visitor tracking. */
function az_upgrade_publishing_campaign_schema(PDO $db): void
{
    if (az_schema_version($db)>=49 || az_schema_version($db)<48) return;
    az_prepare_storage();
    $lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Campaign migration lock unavailable.');
    try {
        if (az_schema_version($db)>=49) return;
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $uid=$mysql?'BIGINT UNSIGNED':'INTEGER';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS publishing_campaigns (
            id CHAR(32) PRIMARY KEY, promotion_id CHAR(32) NOT NULL, video_id CHAR(32) NOT NULL,
            created_by $uid NOT NULL, source_revision INTEGER NOT NULL, token CHAR(32) NOT NULL UNIQUE,
            platform VARCHAR(20) NOT NULL, state VARCHAR(20) NOT NULL, schedule_at VARCHAR(32) NULL,
            activated_at VARCHAR(32) NULL, retired_at VARCHAR(32) NULL, revision INTEGER NOT NULL DEFAULT 1,
            created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL,
            FOREIGN KEY(promotion_id) REFERENCES publishing_agent_promotions(id) ON DELETE CASCADE,
            FOREIGN KEY(video_id) REFERENCES model_videos(id) ON DELETE CASCADE,
            FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS publishing_campaign_daily (
            campaign_id CHAR(32) NOT NULL, view_day CHAR(10) NOT NULL, opens INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY(campaign_id,view_day), FOREIGN KEY(campaign_id) REFERENCES publishing_campaigns(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS publishing_campaign_audit (
            id CHAR(32) PRIMARY KEY, campaign_id CHAR(32) NOT NULL, actor_id $uid NOT NULL,
            event VARCHAR(20) NOT NULL, happened_at VARCHAR(32) NOT NULL,
            FOREIGN KEY(campaign_id) REFERENCES publishing_campaigns(id) ON DELETE CASCADE)$suffix");
        if ($mysql) {
            $q=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
            foreach ([['publishing_campaigns','campaign_state_schedule','(state,schedule_at)'],['publishing_campaign_audit','campaign_audit_idx','(campaign_id,happened_at)']] as [$table,$idx,$cols]) {
                $q->execute([$table,$idx]);if(!(int)$q->fetchColumn())$db->exec("CREATE INDEX $idx ON $table $cols");
            }
        } else {
            $db->exec('CREATE INDEX IF NOT EXISTS campaign_state_schedule ON publishing_campaigns(state,schedule_at)');
            $db->exec('CREATE INDEX IF NOT EXISTS campaign_audit_idx ON publishing_campaign_audit(campaign_id,happened_at)');
        }
        $db->exec('UPDATE studio_schema SET version=49 WHERE id=1');
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}

function az_campaign_platform(mixed $platform): string
{
    $value=az_text($platform??'',20,'Platform');
    if (!in_array($value,['instagram','tiktok','youtube','facebook','email','other'],true))
        throw new AzHttpError(400,'Choose a supported campaign channel.','invalid_channel');
    return $value;
}
function az_campaign_source(string $promotionId): array
{
    if (!az_valid_id($promotionId)) throw new AzHttpError(400,'Invalid approved promotion.','invalid_promotion');
    $q=az_db()->prepare('SELECT * FROM publishing_agent_promotions WHERE id=?');
    $q->execute([$promotionId]);$promo=$q->fetch();
    if (!$promo||$promo['channel']!=='social'||$promo['state']!=='approved')
        throw new AzHttpError(409,'An administrator-approved social promotion is required.','promotion_not_approved');
    $video=az_model_video_public_row($promo['video_id']);
    if ((int)$video['revision']!==(int)$promo['video_revision'])
        throw new AzHttpError(409,'The published video changed. Prepare and approve new copy.','source_revision_changed');
    return [$promo,$video];
}
function az_campaign_source_usable(array $row): bool
{
    try {
        [$promo,$video]=az_campaign_source($row['promotion_id']);
        return $promo['video_id']===$row['video_id'] && (int)$video['revision']===(int)$row['source_revision'];
    } catch (Throwable) {return false;}
}
function az_campaign_utc_schedule(mixed $value): ?string
{
    if ($value===null||$value==='')return null;
    return az_publishing_schedule_time($value);
}
function az_campaign_create(array $body,int $adminId): array
{
    $promotionId=az_text($body['promotionId']??'',32,'Promotion');
    [$promo,$video]=az_campaign_source($promotionId);
    $platform=az_campaign_platform($body['platform']??null);
    $time=az_campaign_utc_schedule($body['scheduleAt']??null);
    $id=az_id();$token=az_id();$now=az_now();$db=az_db();
    $db->beginTransaction();
    try {
        $q=$db->prepare("INSERT INTO publishing_campaigns(id,promotion_id,video_id,created_by,source_revision,token,platform,state,schedule_at,created_at,updated_at)
            VALUES(?,?,?,?,?,?,?,'draft',?,?,?)");
        $q->execute([$id,$promo['id'],$video['id'],$adminId,$video['revision'],$token,$platform,$time,$now,$now]);
        $q=$db->prepare('INSERT INTO publishing_campaign_audit(id,campaign_id,actor_id,event,happened_at) VALUES(?,?,?,?,?)');
        $q->execute([az_id(),$id,$adminId,'created',$now]);
        $db->commit();
    } catch(Throwable $e) {if($db->inTransaction())$db->rollBack();throw $e;}
    return ['id'=>$id,'state'=>'draft'];
}
/** State changes are explicit, guarded by revision to prevent stale admin tabs. */
function az_campaign_decide(array $body,int $adminId): array
{
    $id=az_text($body['id']??'',32,'Campaign');if(!az_valid_id($id))throw new AzHttpError(400,'Invalid campaign.','invalid_campaign');
    $revision=az_integer($body['revision']??null,1,PHP_INT_MAX,'Revision');
    $action=az_text($body['decision']??'',20,'Decision');
    if(!in_array($action,['schedule','activate','retire'],true))throw new AzHttpError(400,'Invalid campaign decision.','invalid_decision');
    $db=az_db();$q=$db->prepare('SELECT * FROM publishing_campaigns WHERE id=?');$q->execute([$id]);$row=$q->fetch();
    if(!$row)throw new AzHttpError(404,'Campaign not found.','not_found');
    if((int)$row['revision']!==$revision)throw new AzHttpError(409,'Campaign changed. Reload.','revision_conflict');
    if ($action==='schedule') {
        if($row['state']!=='draft'||!$row['schedule_at'])throw new AzHttpError(409,'Add a future handoff time to a draft.','invalid_state');
        if(strtotime($row['schedule_at'])<=time())throw new AzHttpError(409,'The handoff time has passed. Review and activate manually.','schedule_passed');
        $state='scheduled';
    } elseif($action==='activate') {
        if(!in_array($row['state'],['draft','scheduled'],true))throw new AzHttpError(409,'Only draft or scheduled campaigns can be activated.','invalid_state');
        if($row['state']==='scheduled' && strtotime((string)$row['schedule_at'])>time())throw new AzHttpError(409,'Scheduled handoff is not due yet.','not_due');
        if(!az_campaign_source_usable($row))throw new AzHttpError(409,'Source consent, publication, or approved promotion changed.','source_unavailable');
        $state='active';
    } else {
        if($row['state']==='retired')throw new AzHttpError(409,'Campaign is already retired.','invalid_state');
        $state='retired';
    }
    $now=az_now();$db->beginTransaction();
    try {
        $q=$db->prepare('UPDATE publishing_campaigns SET state=?, revision=revision+1,updated_at=?,activated_at=?,retired_at=? WHERE id=? AND revision=? AND state=?');
        $q->execute([$state,$now,$state==='active'?$now:$row['activated_at'],$state==='retired'?$now:$row['retired_at'],$id,$revision,$row['state']]);
        if($q->rowCount()!==1)throw new AzHttpError(409,'Campaign changed. Reload.','revision_conflict');
        $q=$db->prepare('INSERT INTO publishing_campaign_audit(id,campaign_id,actor_id,event,happened_at) VALUES(?,?,?,?,?)');
        $q->execute([az_id(),$id,$adminId,$action,$now]);
        $db->commit();
    } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    return ['id'=>$id,'state'=>$state,'revision'=>$revision+1];
}
function az_campaign_list(int $adminId): array
{
    $q=az_db()->prepare('SELECT c.*,p.headline,p.copy_text,v.title FROM publishing_campaigns c
        JOIN publishing_agent_promotions p ON p.id=c.promotion_id JOIN model_videos v ON v.id=c.video_id
        ORDER BY c.created_at DESC,c.id DESC LIMIT 120');$q->execute();
    $rows=$q->fetchAll();
    $ids=array_column($rows,'id');$clicks=[];$last7=[];$prior7=[];
    if($ids){
        $binds=implode(',',array_fill(0,count($ids),'?'));
        $query=az_db()->prepare('SELECT campaign_id,view_day,opens FROM publishing_campaign_daily WHERE campaign_id IN ('.$binds.')');
        $query->execute($ids);
        $seven=gmdate('Y-m-d',time()-6*86400);$fourteen=gmdate('Y-m-d',time()-13*86400);
        foreach($query->fetchAll() as $hit){
            $id=(string)$hit['campaign_id'];$count=(int)$hit['opens'];
            $clicks[$id]=($clicks[$id]??0)+$count;
            if($hit['view_day']>=$seven)$last7[$id]=($last7[$id]??0)+$count;
            elseif($hit['view_day']>=$fourteen)$prior7[$id]=($prior7[$id]??0)+$count;
        }
    }
    foreach($rows as &$r){
        $r['opens']=$clicks[$r['id']]??0;
        $r['opens7d']=$last7[$r['id']]??0;
        $r['opensPrevious7d']=$prior7[$r['id']]??0;
        $r['shareUrl']=az_url('campaign.php?t='.$r['token']);
        $r['videoUrl']=az_url('watch.php?id='.$r['video_id']);
        $r['coverUrl']=null;
        try { $video=az_model_video_public_data(az_model_video_public_row($r['video_id']));$r['coverUrl']=$video['coverUrl']; }
        catch(Throwable){}
        $r['ready']=az_campaign_source_usable($r);
        $r['handoffDue']=$r['state']==='scheduled' && $r['schedule_at']!==null && strtotime($r['schedule_at'])<=time();
        unset($r['token']); // Secret token still appears only inside the share link.
    }unset($r);
    return $rows;
}
/** Public redirect: no unapproved content is exposed; only aggregate session-limited opens counted. */
function az_campaign_resolve_link(string $token): string
{
    if(!az_valid_id($token))return 'watch.php';
    $q=az_db()->prepare('SELECT * FROM publishing_campaigns WHERE token=? AND state=?');
    $q->execute([$token,'active']);$row=$q->fetch();
    if(!$row||!az_campaign_source_usable($row))return 'watch.php';
    $now=time();$count=true;
    if(session_status()===PHP_SESSION_ACTIVE){
        $seen=$_SESSION['campaign_opens']??[];if(!is_array($seen))$seen=[];
        if(isset($seen[$token])&&is_int($seen[$token])&&($now-$seen[$token])<1800)$count=false;
        $seen[$token]=$now;
        // Bound per-session memory without storing viewer identities in database.
        $_SESSION['campaign_opens']=array_slice($seen,-80,null,true);
    }
    if($count){
        $db=az_db();$args=[$row['id'],gmdate('Y-m-d',$now)];
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
            $db->prepare('INSERT INTO publishing_campaign_daily(campaign_id,view_day,opens) VALUES(?,?,1) ON DUPLICATE KEY UPDATE opens=opens+1')->execute($args);
        }else{
            $db->prepare('INSERT INTO publishing_campaign_daily(campaign_id,view_day,opens) VALUES(?,?,1) ON CONFLICT(campaign_id,view_day) DO UPDATE SET opens=publishing_campaign_daily.opens+1')->execute($args);
        }
    }
    return 'watch.php?id='.$row['video_id'];
}
