<?php
declare(strict_types=1);
/** Creator Club management and read models. Billing remains disabled. */
function az_creator_club_workspace_enabled(PDO $db): bool
{
    return az_schema_version($db)>=55;
}
function az_creator_club_has_column(PDO $db,string $table,string $column):bool
{
    if(!in_array($table,['creator_club_tiers','creator_club_follows','creator_club_posts','creator_club_review_events'],true))throw new InvalidArgumentException('Invalid club schema table.');
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        $q=$db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$q->execute([$table,$column]);return (int)$q->fetchColumn()>0;
    }
    return in_array($column,array_column($db->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name'),true);
}
function az_upgrade_creator_club_workspace_schema(PDO $db):void
{
    if(az_schema_version($db)>=55||az_schema_version($db)<54)return;
    az_prepare_storage();$lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Creator Club workspace schema lock unavailable.');
    $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    try{
        if(az_schema_version($db)>=55)return;
        if(!$mysql)$db->beginTransaction();
        foreach([
            ['creator_club_tiers','archived','INTEGER NOT NULL DEFAULT 0'],
            ['creator_club_tiers','review_note',"VARCHAR(1000) NOT NULL DEFAULT ''"],
            ['creator_club_follows','notifications_enabled','INTEGER NOT NULL DEFAULT 1'],
            ['creator_club_follows','follow_token','CHAR(32) NULL'],
            ['creator_club_review_events','note',"VARCHAR(1000) NOT NULL DEFAULT ''"]
        ] as [$table,$column,$definition]){
            if(!az_creator_club_has_column($db,$table,$column))$db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
        }
        if($mysql){
            // MySQL DDL commits independently; every alteration is replay-safe.
            $db->exec('ALTER TABLE creator_club_posts MODIFY tier_id CHAR(32) NULL');
            if(!az_creator_club_has_column($db,'creator_club_posts','audience'))$db->exec("ALTER TABLE creator_club_posts ADD COLUMN audience VARCHAR(12) NOT NULL DEFAULT 'tier'");
            if(!az_creator_club_has_column($db,'creator_club_posts','review_note'))$db->exec("ALTER TABLE creator_club_posts ADD COLUMN review_note VARCHAR(1000) NOT NULL DEFAULT ''");
        }elseif(!az_creator_club_has_column($db,'creator_club_posts','audience')){
            // SQLite requires a transactional table rebuild to make tier_id optional.
            $db->exec("CREATE TABLE creator_club_posts_v159 (
                id CHAR(32) PRIMARY KEY,creator_id INTEGER NOT NULL,tier_id CHAR(32) NULL,
                media_id CHAR(32) NULL,title VARCHAR(160) NOT NULL,body TEXT NOT NULL,
                rights_confirmed INTEGER NOT NULL DEFAULT 0,state VARCHAR(20) NOT NULL DEFAULT 'pending',
                revision INTEGER NOT NULL DEFAULT 1,created_at VARCHAR(32) NOT NULL,updated_at VARCHAR(32) NOT NULL,
                reviewed_by INTEGER NULL,reviewed_at VARCHAR(32) NULL,
                audience VARCHAR(12) NOT NULL DEFAULT 'tier',review_note VARCHAR(1000) NOT NULL DEFAULT '',
                FOREIGN KEY(creator_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY(tier_id) REFERENCES creator_club_tiers(id))");
            $db->exec('INSERT INTO creator_club_posts_v159(id,creator_id,tier_id,media_id,title,body,rights_confirmed,state,revision,created_at,updated_at,reviewed_by,reviewed_at) SELECT id,creator_id,tier_id,media_id,title,body,rights_confirmed,state,revision,created_at,updated_at,reviewed_by,reviewed_at FROM creator_club_posts');
            $db->exec('DROP TABLE creator_club_posts');$db->exec('ALTER TABLE creator_club_posts_v159 RENAME TO creator_club_posts');
            $db->exec('CREATE INDEX club_posts_creator ON creator_club_posts(creator_id,state,created_at)');
        }
        $q=$db->query('SELECT fan_id,creator_id FROM creator_club_follows WHERE follow_token IS NULL');
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$db->prepare('UPDATE creator_club_follows SET follow_token=? WHERE fan_id=? AND creator_id=? AND follow_token IS NULL')->execute([az_id(),$row['fan_id'],$row['creator_id']]);
        $db->exec('UPDATE studio_schema SET version=55 WHERE id=1');
        if(!$mysql)$db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    finally{flock($lock,LOCK_UN);fclose($lock);}
}
/** Mutations and their outbox records commit together. The HTTP handler delivers afterwards. */
function az_creator_club_write(PDO $db,callable $fn):mixed
{
    $owns=!$db->inTransaction();if($owns)$db->beginTransaction();
    try{$result=$fn();if($owns)$db->commit();return $result;}
    catch(Throwable $e){if($owns&&$db->inTransaction())$db->rollBack();throw $e;}
}
function az_creator_club_require_active(array $actor,PDO $db):void
{
    $q=$db->prepare("SELECT 1 FROM users WHERE id=? AND status='active'");$q->execute([(int)($actor['id']??0)]);
    if(!$q->fetchColumn())throw new AzHttpError(403,'Your account is not active.','account_inactive');
}
function az_creator_club_queue(PDO $db,string $key,int $actor,int $recipient,string $kind,string $title,string $detail,string $href,bool $announce=true):void
{
    if(!az_creator_club_workspace_enabled($db))return;
    az_commerce_queue('creator-club:'.$key,$actor,$recipient,'club_'.$kind,$title,$detail,$href,$announce,$db);
}
function az_creator_club_audit(PDO $db,string $kind,string $id,int $actor,string $event,string $note=''):void
{
    if(!az_creator_club_workspace_enabled($db))return;
    $db->prepare('INSERT INTO creator_club_review_events(id,object_type,object_id,actor_id,event,note,created_at) VALUES(?,?,?,?,?,?,?)')->execute([az_id(),$kind,$id,$actor,$event,$note,az_now()]);
}
function az_creator_club_notify_review(PDO $db,string $kind,string $id,int $creator,int $revision):void
{
    if(!az_creator_club_workspace_enabled($db))return;
    foreach(az_agent_workspace_admin_ids($db) as $admin)az_creator_club_queue($db,'review:'.$kind.':'.$id.':'.$revision,$creator,$admin,'review_needed','Creator Club review waiting','A creator submitted a '.$kind.' for review. Open the review queue to check the current version.','creator-club-admin.php');
}
/** Titles, bodies and media IDs never enter persistent member alerts. Recheck on open. */
function az_creator_club_notify_post(PDO $db,array $post,int $reviewer):void
{
    $creator=(int)$post['creator_id'];
    $q=$db->prepare("SELECT f.fan_id FROM creator_club_follows f JOIN users u ON u.id=f.fan_id AND u.status='active' WHERE f.creator_id=? AND f.notifications_enabled=1 AND f.fan_id<>?");$q->execute([$creator,$creator]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $fan){
        $fan=(int)$fan;if(!az_creator_club_can_view(['id'=>$fan,'roles'=>['fan']],$post,$db))continue;
        az_creator_club_queue($db,'post:'.$post['id'].':'.$post['revision'],$creator,$fan,'post_published','New Creator Club update','A Creator Club posted an update. Open the club to view posts available with your current access.','creator-club.php?creator='.$creator);
    }
}
function az_creator_club_validate_tier(string $title,string $description,int $cents):array
{
    $title=trim(preg_replace('/[\x00-\x1F\x7F]/u',' ',$title)??'');$description=trim($description);
    if($title===''||strlen($title)>100||strlen($description)>1200||$cents<100||$cents>50000)throw new AzHttpError(400,'Provide a title, details and a proposed price between $1 and $500.','invalid_tier');
    return [$title,$description];
}
function az_creator_club_validate_post(PDO $db,array $actor,string $tierId,?string $mediaId,string $title,string $body,bool $rights,string $audience):array
{
    $creator=(int)$actor['id'];az_creator_club_require_creator($actor,$creator,$db);
    if(!in_array($audience,['followers','tier'],true)||($audience==='followers'&&!az_creator_club_workspace_enabled($db)))throw new AzHttpError(400,'Choose a valid post audience.','invalid_audience');
    if($audience==='tier'){
        if(!az_valid_id($tierId))throw new AzHttpError(400,'Choose an approved tier.','invalid_tier');
        $current=$db->inTransaction()&&$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $q=$db->prepare("SELECT 1 FROM creator_club_tiers WHERE id=? AND creator_id=? AND state='approved'".(az_creator_club_workspace_enabled($db)?' AND archived=0':'').$current);
        $q->execute([$tierId,$creator]);if(!$q->fetchColumn())throw new AzHttpError(403,'Choose an active approved tier.','tier_forbidden');
    }else $tierId='';
    if($mediaId==='')$mediaId=null;if($mediaId!==null)az_personal_media_row($mediaId,$creator);
    $title=trim(preg_replace('/[\x00-\x1F\x7F]/u',' ',$title)??'');$body=trim($body);
    if(!$rights)throw new AzHttpError(400,'Confirm rights and participant consent before submitting.','rights_required');
    if($title===''||strlen($title)>160||strlen($body)>6000||($body===''&&$mediaId===null))throw new AzHttpError(400,'Provide a title and text or a private video.','invalid_post');
    return [$tierId===''?null:$tierId,$mediaId,$title,$body];
}
function az_creator_club_tier_update(array $actor,string $id,int $revision,string $title,string $description,int $cents,?PDO $db=null):array
{
    $db??=az_db();$creator=(int)$actor['id'];az_creator_club_require_creator($actor,$creator,$db);
    if(!az_valid_id($id)||$revision<1)throw new AzHttpError(400,'Invalid tier.','invalid_tier');
    [$title,$description]=az_creator_club_validate_tier($title,$description,$cents);
    return az_creator_club_write($db,function()use($db,$creator,$id,$revision,$title,$description,$cents){
        $db->prepare('UPDATE users SET status=status WHERE id=?')->execute([$creator]);
        $q=$db->prepare('SELECT * FROM creator_club_tiers WHERE id=? AND creator_id=?');$q->execute([$id,$creator]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new AzHttpError(404,'Tier not found.','not_found');
        if((int)$row['revision']!==$revision||!in_array($row['state'],['pending','rejected'],true)||(int)$row['archived']===1)throw new AzHttpError(409,'Only a current, unarchived pending or rejected tier can be edited.','stale_tier');
        $q=$db->prepare("SELECT COUNT(*) FROM creator_club_tiers WHERE creator_id=? AND archived=0 AND state IN ('pending','approved') AND id<>?");$q->execute([$creator,$id]);
        if((int)$q->fetchColumn()>=5)throw new AzHttpError(409,'You can have at most five pending or approved tiers.','tier_limit');
        $q=$db->prepare("UPDATE creator_club_tiers SET title=?,description=?,monthly_cents=?,state='pending',review_note='',reviewed_at=NULL,reviewed_by=NULL,revision=revision+1,updated_at=? WHERE id=? AND creator_id=? AND revision=? AND state IN ('pending','rejected') AND archived=0");$q->execute([$title,$description,$cents,az_now(),$id,$creator,$revision]);
        if($q->rowCount()!==1)throw new AzHttpError(409,'Tier changed. Reload before editing.','stale_tier');
        az_creator_club_audit($db,'tier',$id,$creator,'resubmitted');az_creator_club_notify_review($db,'tier',$id,$creator,$revision+1);
        return ['id'=>$id,'state'=>'pending','revision'=>$revision+1];
    });
}
/** Archival stops new selection; approved content and existing access remain unchanged. */
function az_creator_club_tier_archive(array $actor,string $id,int $revision,bool $archive,?PDO $db=null):array
{
    $db??=az_db();$creator=(int)$actor['id'];az_creator_club_require_creator($actor,$creator,$db);
    if(!az_valid_id($id)||$revision<1)throw new AzHttpError(400,'Invalid tier.','invalid_tier');
    return az_creator_club_write($db,function()use($db,$creator,$id,$revision,$archive){
        $db->prepare('UPDATE users SET status=status WHERE id=?')->execute([$creator]);
        $q=$db->prepare('SELECT * FROM creator_club_tiers WHERE id=? AND creator_id=?');$q->execute([$id,$creator]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new AzHttpError(404,'Tier not found.','not_found');
        if((int)$row['revision']!==$revision||(bool)$row['archived']===$archive)throw new AzHttpError(409,'Tier changed. Reload before changing visibility.','stale_tier');
        if(!$archive&&in_array($row['state'],['approved','pending'],true)){
            $q=$db->prepare("SELECT COUNT(*) FROM creator_club_tiers WHERE creator_id=? AND archived=0 AND state IN ('pending','approved')");$q->execute([$creator]);
            if((int)$q->fetchColumn()>=5)throw new AzHttpError(409,'Archive another tier before restoring this one.','tier_limit');
        }
        $q=$db->prepare('UPDATE creator_club_tiers SET archived=?,revision=revision+1,updated_at=? WHERE id=? AND creator_id=? AND revision=?');$q->execute([$archive?1:0,az_now(),$id,$creator,$revision]);
        if($q->rowCount()!==1)throw new AzHttpError(409,'Tier changed. Reload.','stale_tier');
        az_creator_club_audit($db,'tier',$id,$creator,$archive?'archived':'restored');
        if(!$archive&&$row['state']==='pending')az_creator_club_notify_review($db,'tier',$id,$creator,$revision+1);
        return ['id'=>$id,'archived'=>$archive,'revision'=>$revision+1];
    });
}
function az_creator_club_post_update(array $actor,string $id,int $revision,string $tierId,?string $mediaId,string $title,string $body,bool $rightsConfirmed,string $audience='tier',?PDO $db=null):array
{
    $db??=az_db();$creator=(int)$actor['id'];
    if(!az_valid_id($id)||$revision<1)throw new AzHttpError(400,'Invalid post.','invalid_post');
    return az_creator_club_write($db,function()use($db,$actor,$creator,$id,$revision,$tierId,$mediaId,$title,$body,$rightsConfirmed,$audience){
        $db->prepare('UPDATE users SET status=status WHERE id=?')->execute([$creator]);
        [$tierId,$mediaId,$title,$body]=az_creator_club_validate_post($db,$actor,$tierId,$mediaId,$title,$body,$rightsConfirmed,$audience);
        $q=$db->prepare('SELECT state,revision FROM creator_club_posts WHERE id=? AND creator_id=?');$q->execute([$id,$creator]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new AzHttpError(404,'Post not found.','not_found');
        if((int)$row['revision']!==$revision||!in_array($row['state'],['pending','rejected','withdrawn'],true))throw new AzHttpError(409,'Withdraw a published post before editing it. Reload if this draft changed.','stale_post');
        $q=$db->prepare("UPDATE creator_club_posts SET tier_id=?,media_id=?,title=?,body=?,audience=?,rights_confirmed=1,state='pending',review_note='',reviewed_by=NULL,reviewed_at=NULL,revision=revision+1,updated_at=? WHERE id=? AND creator_id=? AND revision=? AND state IN ('pending','rejected','withdrawn')");$q->execute([$tierId,$mediaId,$title,$body,$audience,az_now(),$id,$creator,$revision]);
        if($q->rowCount()!==1)throw new AzHttpError(409,'Post changed. Reload.','stale_post');
        az_creator_club_audit($db,'post',$id,$creator,'resubmitted');az_creator_club_notify_review($db,'post',$id,$creator,$revision+1);
        return ['id'=>$id,'state'=>'pending','revision'=>$revision+1];
    });
}
function az_creator_club_notification_preference(array $actor,int $creator,bool $enabled,?PDO $db=null):void
{
    $db??=az_db();az_creator_club_require_active($actor,$db);
    $q=$db->prepare('SELECT 1 FROM creator_club_follows WHERE fan_id=? AND creator_id=?');$q->execute([(int)$actor['id'],$creator]);
    if(!$q->fetchColumn())throw new AzHttpError(409,'Follow this creator before changing post alerts.','not_following');
    $db->prepare('UPDATE creator_club_follows SET notifications_enabled=? WHERE fan_id=? AND creator_id=?')->execute([$enabled?1:0,(int)$actor['id'],$creator]);
}
function az_creator_club_access_status(array $row,?string $now=null):string
{
    $now??=az_now();
    if(!empty($row['revoked_at']))return 'revoked';
    if(($row['creator_status']??'active')!=='active'||($row['tier_state']??'approved')!=='approved')return 'unavailable';
    if($row['ends_at']<=$now)return 'expired';
    if($row['begins_at']>$now)return 'scheduled';
    if($row['origin']!=='admin_test')return 'unsupported';
    if(!(az_config()['features']['creator_club_test_grants']??false))return 'test_disabled';
    return 'active_test';
}
function az_creator_club_my_memberships(array $viewer,?PDO $db=null):array
{
    $db??=az_db();az_creator_club_require_active($viewer,$db);$fan=(int)$viewer['id'];
    $q=$db->prepare("SELECT u.id AS creator_id,u.name,u.status AS creator_status,s.visibility,EXISTS(SELECT 1 FROM user_roles r WHERE r.user_id=u.id AND r.role='model') AS is_creator,f.created_at AS followed_at,f.notifications_enabled FROM users u LEFT JOIN user_social_profiles s ON s.user_id=u.id LEFT JOIN creator_club_follows f ON f.creator_id=u.id AND f.fan_id=? WHERE f.fan_id IS NOT NULL OR EXISTS(SELECT 1 FROM creator_club_entitlements e WHERE e.creator_id=u.id AND e.fan_id=?) ORDER BY u.name,u.id LIMIT 200");$q->execute([$fan,$fan]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $access=$db->prepare('SELECT e.*,t.title AS tier_title,t.state AS tier_state,t.archived AS tier_archived FROM creator_club_entitlements e JOIN creator_club_tiers t ON t.id=e.tier_id AND t.creator_id=e.creator_id WHERE e.fan_id=? AND e.creator_id=? ORDER BY e.created_at DESC,e.id DESC LIMIT 100');
    foreach($rows as &$row){
        $row['creator_id']=(int)$row['creator_id'];$row['following']=$row['followed_at']!==null;$row['notifications_enabled']=$row['following']&&(int)$row['notifications_enabled']===1;
        $row['available']=$row['creator_status']==='active'&&(int)$row['is_creator']===1&&(in_array($row['visibility'],['public','members'],true)||az_has_role($viewer,'admin')||$fan===$row['creator_id']);
        if(!$row['available'])$row['name']='Unavailable creator';
        $access->execute([$fan,$row['creator_id']]);$row['access']=$access->fetchAll(PDO::FETCH_ASSOC);
        foreach($row['access'] as &$grant){$grant['creator_status']=$row['available']?'active':'unavailable';$grant['status']=az_creator_club_access_status($grant);if(!$row['available'])$grant['tier_title']='Unavailable tier';unset($grant['created_by']);}unset($grant);
        $row['available_posts']=$row['available']?count(az_creator_club_posts($row['creator_id'],$viewer,$db)):0;
    }unset($row);return $rows;
}
function az_creator_club_creator_stats(array $actor,?PDO $db=null):array
{
    $db??=az_db();$creator=(int)$actor['id'];az_creator_club_require_creator($actor,$creator,$db);
    $q=$db->prepare('SELECT COUNT(*) FROM creator_club_follows WHERE creator_id=?');$q->execute([$creator]);$stats=['followers'=>(int)$q->fetchColumn(),'tiers'=>0,'pending'=>0,'published'=>0,'rejected'=>0];
    $q=$db->prepare('SELECT COUNT(*) FROM creator_club_tiers WHERE creator_id=? AND archived=0');$q->execute([$creator]);$stats['tiers']=(int)$q->fetchColumn();
    $q=$db->prepare('SELECT state,COUNT(*) AS total FROM creator_club_posts WHERE creator_id=? GROUP BY state');$q->execute([$creator]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){if($row['state']==='approved')$stats['published']=(int)$row['total'];elseif(in_array($row['state'],['pending','rejected'],true))$stats[$row['state']]=(int)$row['total'];}
    return $stats;
}
function az_creator_club_access_history(array $actor,?int $creator=null,?PDO $db=null):array
{
    $db??=az_db();az_creator_club_require_active($actor,$db);
    if(!az_has_role($actor,'admin')){$creator??=(int)$actor['id'];az_creator_club_require_creator($actor,$creator,$db);}
    $q=$db->prepare('SELECT e.*,u.name AS fan_name,c.name AS creator_name,c.status AS creator_status,t.title AS tier_title,t.state AS tier_state,t.archived AS tier_archived FROM creator_club_entitlements e JOIN users u ON u.id=e.fan_id JOIN users c ON c.id=e.creator_id JOIN creator_club_tiers t ON t.id=e.tier_id AND t.creator_id=e.creator_id'.($creator!==null?' WHERE e.creator_id=?':'').' ORDER BY e.created_at DESC,e.id DESC LIMIT 200');$q->execute($creator!==null?[$creator]:[]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as &$row)$row['status']=az_creator_club_access_status($row);unset($row);return $rows;
}
function az_creator_club_revoke_access(array $actor,string $id,?PDO $db=null):void
{
    az_creator_club_require_admin($actor);$db??=az_db();
    if(!az_valid_id($id))throw new AzHttpError(400,'Invalid access record.','invalid_grant');
    az_creator_club_write($db,function()use($db,$actor,$id){
        $q=$db->prepare('SELECT * FROM creator_club_entitlements WHERE id=?');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new AzHttpError(404,'Access record not found.','not_found');
        if($row['revoked_at']!==null)return;
        $q=$db->prepare('UPDATE creator_club_entitlements SET revoked_at=? WHERE id=? AND revoked_at IS NULL');$q->execute([az_now(),$id]);if($q->rowCount()!==1)return;
        az_creator_club_audit($db,'access',$id,(int)$actor['id'],'revoked');
        az_creator_club_queue($db,'access-revoked:'.$id,(int)$actor['id'],(int)$row['fan_id'],'access_revoked','Creator Club access updated','An administrator ended a temporary access grant. Your free follows are unchanged.','creator-club-workspace.php');
    });
}
function az_creator_club_history(array $actor,?int $creator=null,?PDO $db=null):array
{
    $db??=az_db();az_creator_club_require_active($actor,$db);
    if(!az_has_role($actor,'admin')){$creator??=(int)$actor['id'];az_creator_club_require_creator($actor,$creator,$db);}
    $q=$db->prepare("SELECT e.*,u.name AS actor_name,COALESCE(t.creator_id,p.creator_id,a.creator_id) AS creator_id,COALESCE(t.title,p.title,'Temporary access') AS object_title FROM creator_club_review_events e JOIN users u ON u.id=e.actor_id LEFT JOIN creator_club_tiers t ON e.object_type='tier' AND t.id=e.object_id LEFT JOIN creator_club_posts p ON e.object_type='post' AND p.id=e.object_id LEFT JOIN creator_club_entitlements a ON e.object_type='access' AND a.id=e.object_id".($creator!==null?' WHERE COALESCE(t.creator_id,p.creator_id,a.creator_id)=?':'').' ORDER BY e.created_at DESC,e.id DESC LIMIT 100');
    // COALESCE has no SQLite column affinity: bind an integer explicitly so the
    // creator scope matches integer IDs instead of comparing them to SQL text.
    if($creator!==null)$q->bindValue(1,$creator,PDO::PARAM_INT);
    $q->execute();return $q->fetchAll(PDO::FETCH_ASSOC);
}
/** Current facts only; fans receive no staff or production work data. */
function az_creator_club_briefing(array $user,?PDO $db=null):array
{
    $db??=az_db();$cards=[];$counts=[];
    if(!az_creator_club_workspace_enabled($db))return ['cards'=>[],'counts'=>[]];
    $id=(int)$user['id'];
    if(az_has_role($user,'admin')){
        $pending=count(az_creator_club_review_queue($db));$counts['clubReviews']=$pending;
        if($pending)$cards[]=az_agent_workspace_card('club_review','Review '.$pending.' Creator Club '.($pending===1?'submission':'submissions'),'Creator tiers and posts are waiting for a recorded staff decision.','creator-club-admin.php',1);
    }
    if(az_has_role($user,'model')){
        $stats=az_creator_club_creator_stats($user,$db);$counts['clubFollowers']=$stats['followers'];$counts['clubPostsPublished']=$stats['published'];
        $q=$db->prepare("SELECT COUNT(*) FROM creator_club_tiers WHERE creator_id=? AND state='rejected' AND archived=0");$q->execute([$id]);$rejected=$stats['rejected']+(int)$q->fetchColumn();
        if($rejected)$cards[]=az_agent_workspace_card('club_feedback','Review Creator Club feedback','You have '.$rejected.' rejected '.($rejected===1?'submission':'submissions').'. Open your club to read feedback, edit and resubmit.','creator-club.php?creator='.$id,2);
        if($stats['pending'])$cards[]=az_agent_workspace_card('club_pending','Creator Club posts under review',$stats['pending'].' '.($stats['pending']===1?'post is':'posts are').' awaiting staff review.','creator-club.php?creator='.$id,4);
    }
    $q=$db->prepare('SELECT COUNT(*) FROM creator_club_follows WHERE fan_id=?');$q->execute([$id]);$count=(int)$q->fetchColumn();$counts['clubsFollowing']=$count;
    if($count)$cards[]=az_agent_workspace_card('club_following','Your Creator Clubs','You follow '.$count.' '.($count===1?'creator':'creators').'. Open My Clubs for available posts and alert preferences.','creator-club-workspace.php',4);
    elseif(!az_has_role($user,'admin')&&!az_has_role($user,'model'))$cards[]=az_agent_workspace_card('club_discover','Find your first Creator Club','Explore creators and follow for free. Paid subscriptions are not enabled.','creator-clubs.php',4);
    return ['cards'=>$cards,'counts'=>$counts];
}
