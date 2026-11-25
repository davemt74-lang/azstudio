<?php
declare(strict_types=1);
require_once __DIR__.'/creator-club-workspace.php';
/**
 * Creator clubs: free follows and admin-reviewed tier / exclusive-post drafts.
 * Production billing is deliberately DISABLED. Staff test grants are available
 * only behind an explicit private config flag. This module does not charge cards.
 */
function az_upgrade_creator_memberships_schema(PDO $db):void
{
    if (az_schema_version($db)>=51 || az_schema_version($db)<50) return;
    az_prepare_storage();
    $lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if (!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Creator club schema lock unavailable.');
    try {
        if (az_schema_version($db)>=51) return;
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $userId=$mysql?'BIGINT UNSIGNED':'INTEGER';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS creator_club_tiers (
            id CHAR(32) PRIMARY KEY,creator_id $userId NOT NULL,title VARCHAR(100) NOT NULL,
            description TEXT NOT NULL,monthly_cents INTEGER NOT NULL,
            state VARCHAR(20) NOT NULL DEFAULT 'pending',revision INTEGER NOT NULL DEFAULT 1,
            created_at VARCHAR(32) NOT NULL,updated_at VARCHAR(32) NOT NULL,
            reviewed_by $userId NULL,reviewed_at VARCHAR(32) NULL,
            FOREIGN KEY(creator_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS creator_club_follows (
            fan_id $userId NOT NULL,creator_id $userId NOT NULL,created_at VARCHAR(32) NOT NULL,
            PRIMARY KEY(fan_id,creator_id),FOREIGN KEY(fan_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(creator_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS creator_club_posts (
            id CHAR(32) PRIMARY KEY,creator_id $userId NOT NULL,tier_id CHAR(32) NOT NULL,
            media_id CHAR(32) NULL,title VARCHAR(160) NOT NULL,body TEXT NOT NULL,
            rights_confirmed INTEGER NOT NULL DEFAULT 0,
            state VARCHAR(20) NOT NULL DEFAULT 'pending',revision INTEGER NOT NULL DEFAULT 1,
            created_at VARCHAR(32) NOT NULL,updated_at VARCHAR(32) NOT NULL,
            reviewed_by $userId NULL,reviewed_at VARCHAR(32) NULL,
            FOREIGN KEY(creator_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(tier_id) REFERENCES creator_club_tiers(id))$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS creator_club_entitlements (
            id CHAR(32) PRIMARY KEY,fan_id $userId NOT NULL,creator_id $userId NOT NULL,
            tier_id CHAR(32) NOT NULL,origin VARCHAR(32) NOT NULL,
            begins_at VARCHAR(32) NOT NULL,ends_at VARCHAR(32) NOT NULL,
            created_by $userId NOT NULL,created_at VARCHAR(32) NOT NULL,
            revoked_at VARCHAR(32) NULL,
            FOREIGN KEY(fan_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(creator_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(tier_id) REFERENCES creator_club_tiers(id))$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS creator_club_review_events (
            id CHAR(32) PRIMARY KEY,object_type VARCHAR(12) NOT NULL,object_id CHAR(32) NOT NULL,
            actor_id $userId NOT NULL,event VARCHAR(30) NOT NULL,created_at VARCHAR(32) NOT NULL)$suffix");
        if ($mysql) {
            foreach ([
              'club_tier_creator'=>['creator_club_tiers','creator_id,state'],
              'club_posts_creator'=>['creator_club_posts','creator_id,state,created_at'],
              'club_grant_fan'=>['creator_club_entitlements','fan_id,creator_id,ends_at'],
              'club_review_object'=>['creator_club_review_events','object_type,object_id']
            ] as $index=>$spec) {
                $q=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
                $q->execute([$spec[0],$index]);
                if (!(int)$q->fetchColumn())$db->exec('CREATE INDEX '.$index.' ON '.$spec[0].'('.$spec[1].')');
            }
        } else {
            $db->exec('CREATE INDEX IF NOT EXISTS club_tier_creator ON creator_club_tiers(creator_id,state)');
            $db->exec('CREATE INDEX IF NOT EXISTS club_posts_creator ON creator_club_posts(creator_id,state,created_at)');
            $db->exec('CREATE INDEX IF NOT EXISTS club_grant_fan ON creator_club_entitlements(fan_id,creator_id,ends_at)');
            $db->exec('CREATE INDEX IF NOT EXISTS club_review_object ON creator_club_review_events(object_type,object_id)');
        }
        $db->exec('UPDATE studio_schema SET version=51 WHERE id=1');
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}

function az_creator_club_creator(int $id, ?PDO $db=null):array
{
    if($id<1)throw new AzHttpError(404,'Creator not found.','creator_missing');
    $db??=az_db();
    $current=$db->inTransaction()&&$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    $q=$db->prepare("SELECT u.id,u.name FROM users u JOIN user_roles r ON r.user_id=u.id WHERE u.id=? AND u.status='active' AND r.role='model' LIMIT 1".$current);
    $q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new AzHttpError(404,'Creator not found.','creator_missing');
    return $row;
}
function az_creator_club_require_creator(array $actor,int $creator,?PDO $db=null):void
{
    if((int)$actor['id']!==$creator||!az_has_role($actor,'model'))
        throw new AzHttpError(403,'Only this creator can manage these drafts.','creator_forbidden');
    az_creator_club_creator($creator,$db);
}
function az_creator_club_require_admin(array $actor):void
{
    if(!az_has_role($actor,'admin'))throw new AzHttpError(403,'Admin role required.','admin_required');
}
function az_creator_club_tiers(int $creator,bool $includePending=false,?PDO $db=null):array
{
    $db??=az_db();
    $modern=az_creator_club_workspace_enabled($db);
    $order=$includePending&&$modern?"CASE WHEN archived=0 AND state IN ('pending','approved') THEN 0 WHEN archived=0 THEN 1 ELSE 2 END,monthly_cents,id":'monthly_cents,id';
    $sql='SELECT * FROM creator_club_tiers WHERE creator_id=?'.($includePending?'':" AND state='approved'".($modern?' AND archived=0':'')).' ORDER BY '.$order.' LIMIT 30';
    $q=$db->prepare($sql);$q->execute([$creator]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function az_creator_club_tier(array $actor,string $title,string $description,int $cents,?PDO $db=null):array
{
    $creator=(int)$actor['id'];$db??=az_db();az_creator_club_require_creator($actor,$creator,$db);
    [$title,$description]=az_creator_club_validate_tier($title,$description,$cents);
    return az_creator_club_write($db,function()use($db,$creator,$title,$description,$cents){
        $db->prepare('UPDATE users SET status=status WHERE id=?')->execute([$creator]);
        $q=$db->prepare("SELECT COUNT(*) FROM creator_club_tiers WHERE creator_id=? AND state IN ('approved','pending')".(az_creator_club_workspace_enabled($db)?' AND archived=0':''));
        $q->execute([$creator]);if((int)$q->fetchColumn()>=5)throw new AzHttpError(409,'You can have at most five pending or approved tiers.','tier_limit');
        $id=az_id();$now=az_now();
        $db->prepare("INSERT INTO creator_club_tiers(id,creator_id,title,description,monthly_cents,state,revision,created_at,updated_at) VALUES(?,?,?,?,?,'pending',1,?,?)")->execute([$id,$creator,$title,$description,$cents,$now,$now]);
        az_creator_club_audit($db,'tier',$id,$creator,'submitted');az_creator_club_notify_review($db,'tier',$id,$creator,1);
        return ['id'=>$id,'state'=>'pending','revision'=>1];
    });
}
function az_creator_club_review(array $actor,string $kind,string $id,int $revision,string $decision,?PDO $db=null,string $note=''):array
{
    az_creator_club_require_admin($actor);$db??=az_db();$note=trim($note);
    if(!in_array($kind,['tier','post'],true)||!in_array($decision,['approve','reject'],true)||!az_valid_id($id)||$revision<1)
        throw new AzHttpError(400,'Invalid review request.','invalid_review');
    $modern=az_creator_club_workspace_enabled($db);
    if(strlen($note)>1000||($modern&&$decision==='reject'&&$note===''))throw new AzHttpError(400,'Explain the changes needed in up to 1,000 characters.','review_note_required');
    $table=$kind==='tier'?'creator_club_tiers':'creator_club_posts';$publishing=$kind==='post'&&function_exists('az_creator_club_publishing_enabled')&&az_creator_club_publishing_enabled($db);
    $lookup=$db->prepare('SELECT creator_id FROM '.$table.' WHERE id=?');$lookup->execute([$id]);$creator=(int)($lookup->fetchColumn()?:0);
    if(!$creator)throw new AzHttpError(404,'Item not found.','not_found');
    return az_creator_club_write($db,function()use($db,$actor,$kind,$id,$revision,$decision,$note,$modern,$table,$creator,$publishing){
        // Take the creator lock before any transactional snapshot read. Tier archival
        // and creator edits use the same order, including on MySQL REPEATABLE READ.
        $db->prepare('UPDATE users SET status=status WHERE id=?')->execute([$creator]);
        $current=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $q=$db->prepare('SELECT * FROM '.$table.' WHERE id=?'.$current);$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new AzHttpError(404,'Item not found.','not_found');
        if($row['state']!=='pending'||(int)$row['revision']!==$revision||($kind==='tier'&&(int)($row['archived']??0)===1))throw new AzHttpError(409,'Item changed. Reload before reviewing.','stale_review');
        // Deactivation prevents publication, but staff can still clear a pending
        // item with feedback instead of leaving an unresolvable queue entry.
        if($decision==='approve')az_creator_club_creator($creator,$db);
        if($kind==='post'&&$decision==='approve'&&$publishing)az_creator_club_validate_stored_post($db,$row);
        elseif($kind==='post'&&$decision==='approve'){
            if(($row['audience']??'tier')==='tier'){
                $t=$db->prepare("SELECT 1 FROM creator_club_tiers WHERE id=? AND creator_id=? AND state='approved'".($modern?' AND archived=0':'').$current);$t->execute([$row['tier_id'],$creator]);
                if(!$t->fetchColumn())throw new AzHttpError(409,'The tier is not active and approved.','tier_not_approved');
            }elseif(($row['audience']??'')!=='followers')throw new AzHttpError(409,'The post audience is invalid.','invalid_audience');
            if((int)($row['rights_confirmed']??0)!==1)throw new AzHttpError(409,'Creator rights confirmation is missing.','rights_not_confirmed');
            if($row['media_id']!==null)az_creator_club_validate_media($db,(string)$row['media_id'],$creator);
        }
        $next=$decision==='approve'?'approved':'rejected';$now=az_now();
        if($publishing&&$decision==='approve'&&$row['publish_at']!==null&&$row['publish_at']>$now)$next='scheduled';
        if($publishing&&$next==='approved')az_creator_club_validate_publish_visibility($db,$creator);
        $publishedAt=$next==='approved'?$now:null;
        $sql='UPDATE '.$table.' SET state=?,revision=revision+1,reviewed_at=?,reviewed_by=?,updated_at=?'.($modern?',review_note=?':'').($publishing?",published_at=?,publish_error_code='',publish_error='',publish_checked_at=?":'')." WHERE id=? AND revision=? AND state='pending'";
        $args=[$next,$now,(int)$actor['id'],$now];if($modern)$args[]=$note;if($publishing){$args[]=$publishedAt;$args[]=$now;}$args[]=$id;$args[]=$revision;
        $q=$db->prepare($sql);$q->execute($args);if($q->rowCount()!==1)throw new AzHttpError(409,'Review changed.','stale_review');
        if($modern){
            az_creator_club_audit($db,$kind,$id,(int)$actor['id'],$next,$note);
            az_creator_club_queue($db,'decision:'.$kind.':'.$id.':'.($revision+1),(int)$actor['id'],$creator,'review_decision','Creator Club review completed','A '.$kind.' was '.($next==='scheduled'?'approved for scheduled publication':$next).'. Open your club to read the decision and any feedback.','creator-club.php?creator='.$creator);
            if($kind==='post'&&$next==='approved'){$row['state']='approved';$row['revision']=$revision+1;if($publishing)$row['published_at']=$publishedAt;az_creator_club_notify_post($db,$row,(int)$actor['id']);}
        }else $db->prepare('INSERT INTO creator_club_review_events(id,object_type,object_id,actor_id,event,created_at) VALUES(?,?,?,?,?,?)')->execute([az_id(),$kind,$id,(int)$actor['id'],$next,$now]);
        return ['id'=>$id,'state'=>$next,'revision'=>$revision+1];
    });
}
function az_creator_club_follow(array $actor,int $creator,bool $follow,?PDO $db=null):bool
{
    $db??=az_db();if($follow)az_creator_club_creator($creator,$db);az_creator_club_require_active($actor,$db);
    $fan=(int)$actor['id'];if($fan===$creator)throw new AzHttpError(400,'Cannot follow yourself.','self_follow');
    if($follow&&az_creator_club_workspace_enabled($db)&&!az_has_role($actor,'admin')){
        $q=$db->prepare("SELECT 1 FROM user_social_profiles WHERE user_id=? AND visibility IN ('members','public')");$q->execute([$creator]);
        if(!$q->fetchColumn())throw new AzHttpError(404,'Creator profile unavailable.','not_found');
    }
    return az_creator_club_write($db,function()use($db,$fan,$creator,$follow){
        $db->prepare('UPDATE users SET status=status WHERE id=?')->execute([$fan]);
        $q=$db->prepare('SELECT 1 FROM creator_club_follows WHERE fan_id=? AND creator_id=?');$q->execute([$fan,$creator]);$exists=(bool)$q->fetchColumn();
        if($follow&&!$exists){
            if(az_creator_club_workspace_enabled($db)){
                $token=az_id();$db->prepare('INSERT INTO creator_club_follows(fan_id,creator_id,created_at,follow_token,notifications_enabled) VALUES(?,?,?,?,1)')->execute([$fan,$creator,az_now(),$token]);
                az_creator_club_queue($db,'follow:'.$token,$fan,$creator,'followed','New Creator Club follower','A member followed your Creator Club for free. No subscription or payment was created.','creator-club.php?creator='.$creator);
                az_creator_club_queue($db,'follow:'.$token,$fan,$fan,'follow_saved','Creator Club followed','Your free follow is saved. Manage post alerts from My Clubs.','creator-club-workspace.php',false);
            }else $db->prepare('INSERT INTO creator_club_follows(fan_id,creator_id,created_at) VALUES(?,?,?)')->execute([$fan,$creator,az_now()]);
        }elseif(!$follow&&$exists)$db->prepare('DELETE FROM creator_club_follows WHERE fan_id=? AND creator_id=?')->execute([$fan,$creator]);
        return $follow;
    });
}
function az_creator_club_is_following(?array $viewer,int $creator,?PDO $db=null):bool
{
    if(!$viewer)return false;$db??=az_db();
    $q=$db->prepare('SELECT 1 FROM creator_club_follows WHERE fan_id=? AND creator_id=?');
    $q->execute([(int)$viewer['id'],$creator]);return (bool)$q->fetchColumn();
}
function az_creator_club_post(array $actor,string $tierId,?string $mediaId,string $title,string $body,?PDO $db=null,bool $rightsConfirmed=false,string $audience='tier'):array
{
    $db??=az_db();$creator=(int)$actor['id'];
    if(function_exists('az_creator_club_publishing_enabled')&&az_creator_club_publishing_enabled($db))return az_creator_club_save_post($actor,['tierId'=>$tierId,'mediaId'=>$mediaId,'title'=>$title,'body'=>$body,'rightsConfirmed'=>$rightsConfirmed,'audience'=>$audience],true,$db);
    return az_creator_club_write($db,function()use($db,$actor,$creator,$tierId,$mediaId,$title,$body,$rightsConfirmed,$audience){
        $db->prepare('UPDATE users SET status=status WHERE id=?')->execute([$creator]);
        [$tierId,$mediaId,$title,$body]=az_creator_club_validate_post($db,$actor,$tierId,$mediaId,$title,$body,$rightsConfirmed,$audience);
        $id=az_id();$now=az_now();$modern=az_creator_club_workspace_enabled($db);
        $sql="INSERT INTO creator_club_posts(id,creator_id,tier_id,media_id,title,body,rights_confirmed,state,revision,created_at,updated_at".($modern?',audience':'').") VALUES(?,?,?,?,?,?,1,'pending',1,?,?".($modern?',?':'').")";
        $args=[$id,$creator,$tierId,$mediaId,$title,$body,$now,$now];if($modern)$args[]=$audience;$db->prepare($sql)->execute($args);
        az_creator_club_audit($db,'post',$id,$creator,'submitted');az_creator_club_notify_review($db,'post',$id,$creator,1);
        return ['id'=>$id,'state'=>'pending','revision'=>1];
    });
}
function az_creator_club_withdraw(array $actor,string $postId,int $revision,?PDO $db=null):void
{
    if(!az_valid_id($postId)||$revision<1)throw new AzHttpError(400,'Invalid post.','invalid_post');$db??=az_db();
    az_creator_club_write($db,function()use($db,$actor,$postId,$revision){
        $creator=(int)$actor['id'];$db->prepare('UPDATE users SET status=status WHERE id=?')->execute([$creator]);az_creator_club_require_creator($actor,$creator,$db);
        $current=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $q=$db->prepare('SELECT * FROM creator_club_posts WHERE id=? AND creator_id=?'.$current);$q->execute([$postId,$creator]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new AzHttpError(404,'Post not found.','not_found');
        $publishing=function_exists('az_creator_club_publishing_enabled')&&az_creator_club_publishing_enabled($db);
        $q=$db->prepare("UPDATE creator_club_posts SET state='withdrawn',revision=revision+1,updated_at=?".($publishing?",publish_at=NULL,reviewed_by=NULL,reviewed_at=NULL,publish_error_code='',publish_error='',publish_checked_at=NULL":'')." WHERE id=? AND creator_id=? AND revision=? AND state IN (".($publishing?"'draft','pending','approved','scheduled','rejected'":"'pending','approved'").')');$q->execute([az_now(),$postId,$creator,$revision]);
        if($q->rowCount()!==1)throw new AzHttpError(409,'Post changed; reload.','stale_post');az_creator_club_audit($db,'post',$postId,(int)$actor['id'],'withdrawn');
    });
}
/** Only a staff-issued temporary TEST grant can unlock content until approved payment processing exists. */
function az_creator_club_test_grant(array $actor,int $fan,int $creator,string $tierId,int $days,?PDO $db=null):array
{
    az_creator_club_require_admin($actor);$db??=az_db();
    if(!(az_config()['features']['creator_club_test_grants']??false))throw new AzHttpError(403,'Test grants are disabled.','test_disabled');
    if($fan<1||$fan===$creator||$days<1||$days>30||!az_valid_id($tierId))throw new AzHttpError(400,'Invalid test grant.','invalid_grant');
    return az_creator_club_write($db,function()use($db,$actor,$fan,$creator,$tierId,$days){
        $db->prepare('UPDATE users SET status=status WHERE id=?')->execute([$creator]);az_creator_club_creator($creator,$db);
        $q=$db->prepare("SELECT 1 FROM users WHERE id=? AND status='active'");$q->execute([$fan]);if(!$q->fetchColumn())throw new AzHttpError(404,'Member not found.','not_found');
        $q=$db->prepare("SELECT 1 FROM creator_club_tiers WHERE id=? AND creator_id=? AND state='approved'".(az_creator_club_workspace_enabled($db)?' AND archived=0':''));$q->execute([$tierId,$creator]);
        if(!$q->fetchColumn())throw new AzHttpError(409,'Tier must be approved.','tier_pending');
        $now=az_now();$ends=gmdate('Y-m-d\TH:i:s\Z',time()+$days*86400);$id=az_id();
        $db->prepare('INSERT INTO creator_club_entitlements(id,fan_id,creator_id,tier_id,origin,begins_at,ends_at,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$id,$fan,$creator,$tierId,'admin_test',$now,$ends,(int)$actor['id'],$now]);
        az_creator_club_audit($db,'access',$id,(int)$actor['id'],'test_granted');
        az_creator_club_queue($db,'test-grant:'.$id,(int)$actor['id'],$fan,'test_access','Temporary Creator Club test access','An administrator added temporary test access. No payment or subscription was created. View the expiration date in My Clubs.','creator-club-workspace.php');
        return ['id'=>$id,'endsAt'=>$ends,'origin'=>'admin_test'];
    });
}
function az_creator_club_can_view(?array $viewer,array $post,?PDO $db=null,?string $now=null):bool
{
    if(!$viewer)return false;
    $db??=az_db();$creator=(int)$post['creator_id'];$viewerId=(int)$viewer['id'];
    if($viewerId===$creator&&az_has_role($viewer,'model'))return true;
    if(az_has_role($viewer,'admin'))return true;
    if($post['state']!=='approved'||(int)($post['rights_confirmed']??0)!==1)return false;
    $now??=az_now();
    if(function_exists('az_creator_club_publishing_enabled')&&az_creator_club_publishing_enabled($db)){
        if(empty($post['published_at'])||$post['published_at']>$now)return false;
        try{az_creator_club_validate_media_revision($db,$post);}catch(AzHttpError){return false;}
    }
    // A post's media stays restricted when its tier is suspended or creator deactivated.
    try {az_creator_club_creator($creator,$db);}catch(AzHttpError){return false;}
    if(az_creator_club_workspace_enabled($db)){
        $active=$db->prepare("SELECT 1 FROM users WHERE id=? AND status='active'");$active->execute([$viewerId]);if(!$active->fetchColumn())return false;
        $visible=$db->prepare("SELECT 1 FROM user_social_profiles WHERE user_id=? AND visibility IN ('public','members')");$visible->execute([$creator]);if(!$visible->fetchColumn())return false;
    }
    if(($post['audience']??'tier')==='followers')return az_creator_club_is_following($viewer,$creator,$db);
    if(($post['audience']??'tier')!=='tier')return false;
    $q=$db->prepare("SELECT 1 FROM creator_club_tiers WHERE id=? AND creator_id=? AND state='approved'");
    $q->execute([$post['tier_id'],$creator]);if(!$q->fetchColumn())return false;
    if(!(az_config()['features']['creator_club_test_grants']??false))return false;
    $now??=az_now();
    $q=$db->prepare("SELECT 1 FROM creator_club_entitlements WHERE fan_id=? AND creator_id=? AND tier_id=? AND origin='admin_test' AND revoked_at IS NULL AND begins_at<=? AND ends_at>? LIMIT 1");
    $q->execute([$viewerId,$creator,$post['tier_id'],$now,$now]);
    return (bool)$q->fetchColumn();
}
function az_creator_club_post_row(string $id,?array $viewer,?PDO $db=null):array
{
    if(!az_valid_id($id))throw new AzHttpError(404,'Post not found.','not_found');$db??=az_db();
    $q=$db->prepare('SELECT p.* FROM creator_club_posts p WHERE p.id=?');$q->execute([$id]);
    $post=$q->fetch(PDO::FETCH_ASSOC);
    if(!$post||!az_creator_club_can_view($viewer,$post,$db))throw new AzHttpError(404,'Post not found.','not_found');
    return $post;
}
function az_creator_club_posts(int $creator,?array $viewer,?PDO $db=null):array
{
    if(!$viewer)return [];$db??=az_db();$isStaff=az_has_role($viewer,'admin')||((int)$viewer['id']===$creator&&az_has_role($viewer,'model'));
    $publishing=function_exists('az_creator_club_publishing_enabled')&&az_creator_club_publishing_enabled($db);
    $sql='SELECT * FROM creator_club_posts p WHERE creator_id=?'.($isStaff?'':" AND state='approved'");$args=[$creator];
    if(!$isStaff&&$publishing){
        $sql.=' AND published_at IS NOT NULL AND published_at<=? AND (p.media_id IS NULL OR EXISTS(SELECT 1 FROM personal_media m WHERE m.id=p.media_id AND m.user_id=p.creator_id AND m.revision=p.media_revision))';$args[]=az_now();
    }
    if(!$isStaff&&az_creator_club_workspace_enabled($db)){
        // Apply audiences before pagination so locked tier posts cannot bury older
        // posts that are actually available to a free follower.
        $sql.=" AND rights_confirmed=1 AND ((audience='followers' AND EXISTS(SELECT 1 FROM creator_club_follows f WHERE f.creator_id=p.creator_id AND f.fan_id=?))";$args[]=(int)$viewer['id'];
        if(az_config()['features']['creator_club_test_grants']??false){
            $sql.=" OR (audience='tier' AND EXISTS(SELECT 1 FROM creator_club_tiers t WHERE t.id=p.tier_id AND t.creator_id=p.creator_id AND t.state='approved') AND EXISTS(SELECT 1 FROM creator_club_entitlements e WHERE e.fan_id=? AND e.creator_id=p.creator_id AND e.tier_id=p.tier_id AND e.origin='admin_test' AND e.revoked_at IS NULL AND e.begins_at<=? AND e.ends_at>?))";
            $args[]=(int)$viewer['id'];$args[]=az_now();$args[]=az_now();
        }
        $sql.=')';
    }
    $sql.=' ORDER BY '.($publishing?($isStaff?'updated_at':'published_at'):'created_at').' DESC,id DESC LIMIT 60';$q=$db->prepare($sql);$q->execute($args);
    // Recheck current authorization before exposing private title/body/media ID.
    return array_values(array_filter($q->fetchAll(PDO::FETCH_ASSOC),fn($post)=>az_creator_club_can_view($viewer,$post,$db)));
}
function az_creator_club_review_queue(?PDO $db=null):array
{
    $db??=az_db();$result=[];
    foreach(['tier'=>'creator_club_tiers','post'=>'creator_club_posts'] as $type=>$table){
        $q=$db->query("SELECT * FROM $table WHERE state='pending'".($type==='tier'&&az_creator_club_workspace_enabled($db)?' AND archived=0':'')." ORDER BY created_at ASC LIMIT 100");
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$result[]=['type'=>$type,'data'=>$row];
    }
    usort($result,static fn($a,$b)=>strcmp($a['data']['created_at'],$b['data']['created_at']));
    return $result;
}
