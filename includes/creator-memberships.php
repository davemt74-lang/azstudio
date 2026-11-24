<?php
declare(strict_types=1);
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
    $q=$db->prepare("SELECT u.id,u.name FROM users u JOIN user_roles r ON r.user_id=u.id WHERE u.id=? AND u.status='active' AND r.role='model' LIMIT 1");
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
    $sql='SELECT * FROM creator_club_tiers WHERE creator_id=?'.($includePending?'':" AND state='approved'").' ORDER BY monthly_cents,id LIMIT 30';
    $q=$db->prepare($sql);$q->execute([$creator]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function az_creator_club_tier(array $actor,string $title,string $description,int $cents,?PDO $db=null):array
{
    $creator=(int)$actor['id'];$db??=az_db();az_creator_club_require_creator($actor,$creator,$db);
    $title=trim(preg_replace('/[\x00-\x1F\x7F]/u',' ',$title)??'');
    $description=trim($description);
    if($title===''||strlen($title)>100||mb_strlen($description)>1200||$cents<100||$cents>50000)
        throw new AzHttpError(400,'Provide a title, details and a proposed price between $1 and $500.','invalid_tier');
    $q=$db->prepare('SELECT COUNT(*) FROM creator_club_tiers WHERE creator_id=? AND state IN (\'approved\',\'pending\')');
    $q->execute([$creator]);if((int)$q->fetchColumn()>=5)throw new AzHttpError(409,'You can have at most five pending or approved tiers.','tier_limit');
    $id=az_id();$now=az_now();
    $db->prepare("INSERT INTO creator_club_tiers(id,creator_id,title,description,monthly_cents,state,revision,created_at,updated_at) VALUES(?,?,?,?,?,'pending',1,?,?)")
       ->execute([$id,$creator,$title,$description,$cents,$now,$now]);
    return ['id'=>$id,'state'=>'pending'];
}
function az_creator_club_review(array $actor,string $kind,string $id,int $revision,string $decision,?PDO $db=null):array
{
    az_creator_club_require_admin($actor);$db??=az_db();
    if(!in_array($kind,['tier','post'],true)||!in_array($decision,['approve','reject'],true)||!az_valid_id($id)||$revision<1)
        throw new AzHttpError(400,'Invalid review request.','invalid_review');
    $table=$kind==='tier'?'creator_club_tiers':'creator_club_posts';
    $db->beginTransaction();
    try{
        $q=$db->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new AzHttpError(404,'Item not found.','not_found');
        if($row['state']!=='pending'||(int)$row['revision']!==$revision)
            throw new AzHttpError(409,'Item changed. Reload before reviewing.','stale_review');
        az_creator_club_creator((int)$row['creator_id'],$db);
        if($kind==='post') {
            $t=$db->prepare("SELECT 1 FROM creator_club_tiers WHERE id=? AND creator_id=? AND state='approved'");
            $t->execute([$row['tier_id'],$row['creator_id']]);
            if(!$t->fetchColumn())throw new AzHttpError(409,'The tier is not approved.','tier_not_approved');
            if($row['media_id']!==null)az_personal_media_row((string)$row['media_id'],(int)$row['creator_id']);
        }
        $next=$decision==='approve'?'approved':'rejected';$now=az_now();
        $q=$db->prepare('UPDATE '.$table.' SET state=?,revision=revision+1,reviewed_at=?,reviewed_by=?,updated_at=? WHERE id=? AND revision=? AND state=\'pending\'');
        $q->execute([$next,$now,(int)$actor['id'],$now,$id,$revision]);
        if($q->rowCount()!==1)throw new AzHttpError(409,'Review changed.','stale_review');
        $db->prepare('INSERT INTO creator_club_review_events(id,object_type,object_id,actor_id,event,created_at) VALUES(?,?,?,?,?,?)')
          ->execute([az_id(),$kind,$id,(int)$actor['id'],$next,$now]);
        $db->commit();return ['id'=>$id,'state'=>$next];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function az_creator_club_follow(array $actor,int $creator,bool $follow,?PDO $db=null):bool
{
    $db??=az_db();az_creator_club_creator($creator,$db);
    $fan=(int)$actor['id'];if($fan===$creator)throw new AzHttpError(400,'Cannot follow yourself.','self_follow');
    if($follow){
        $q=$db->prepare('SELECT 1 FROM creator_club_follows WHERE fan_id=? AND creator_id=?');$q->execute([$fan,$creator]);
        if(!$q->fetchColumn()){
            try{$db->prepare('INSERT INTO creator_club_follows(fan_id,creator_id,created_at) VALUES(?,?,?)')->execute([$fan,$creator,az_now()]);}
            catch(PDOException $e){$q->execute([$fan,$creator]);if(!$q->fetchColumn())throw $e;}
        }
    }else $db->prepare('DELETE FROM creator_club_follows WHERE fan_id=? AND creator_id=?')->execute([$fan,$creator]);
    return $follow;
}
function az_creator_club_is_following(?array $viewer,int $creator,?PDO $db=null):bool
{
    if(!$viewer)return false;$db??=az_db();
    $q=$db->prepare('SELECT 1 FROM creator_club_follows WHERE fan_id=? AND creator_id=?');
    $q->execute([(int)$viewer['id'],$creator]);return (bool)$q->fetchColumn();
}
function az_creator_club_post(array $actor,string $tierId,?string $mediaId,string $title,string $body,?PDO $db=null):array
{
    $db??=az_db();$creator=(int)$actor['id'];az_creator_club_require_creator($actor,$creator,$db);
    if(!az_valid_id($tierId))throw new AzHttpError(400,'Choose an approved tier.','invalid_tier');
    $q=$db->prepare("SELECT 1 FROM creator_club_tiers WHERE id=? AND creator_id=? AND state='approved'");
    $q->execute([$tierId,$creator]);if(!$q->fetchColumn())throw new AzHttpError(403,'Tier is not approved.','tier_forbidden');
    if($mediaId==='')$mediaId=null;
    if($mediaId!==null)az_personal_media_row($mediaId,$creator);
    $title=trim(preg_replace('/[\x00-\x1F\x7F]/u',' ',$title)??'');$body=trim($body);
    if($title===''||mb_strlen($title)>160||mb_strlen($body)>6000||($body===''&&$mediaId===null))
        throw new AzHttpError(400,'Provide a title and text or a private video.','invalid_post');
    $id=az_id();$now=az_now();
    $db->prepare("INSERT INTO creator_club_posts(id,creator_id,tier_id,media_id,title,body,state,revision,created_at,updated_at) VALUES(?,?,?,?,?,?,'pending',1,?,?)")
      ->execute([$id,$creator,$tierId,$mediaId,$title,$body,$now,$now]);
    return ['id'=>$id,'state'=>'pending'];
}
function az_creator_club_withdraw(array $actor,string $postId,int $revision,?PDO $db=null):void
{
    if(!az_valid_id($postId)||$revision<1)throw new AzHttpError(400,'Invalid post.','invalid_post');
    $db??=az_db();$q=$db->prepare('SELECT * FROM creator_club_posts WHERE id=?');$q->execute([$postId]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new AzHttpError(404,'Post not found.','not_found');
    az_creator_club_require_creator($actor,(int)$row['creator_id'],$db);
    $q=$db->prepare("UPDATE creator_club_posts SET state='withdrawn',revision=revision+1,updated_at=? WHERE id=? AND revision=? AND state IN ('pending','approved')");
    $q->execute([az_now(),$postId,$revision]);
    if($q->rowCount()!==1)throw new AzHttpError(409,'Post changed; reload.','stale_post');
}
/** Only a staff-issued temporary TEST grant can unlock content until approved payment processing exists. */
function az_creator_club_test_grant(array $actor,int $fan,int $creator,string $tierId,int $days,?PDO $db=null):array
{
    az_creator_club_require_admin($actor);$db??=az_db();
    if(!(az_config()['features']['creator_club_test_grants']??false))
        throw new AzHttpError(403,'Test grants are disabled.','test_disabled');
    if($fan<1||$fan===$creator||$days<1||$days>30||!az_valid_id($tierId))throw new AzHttpError(400,'Invalid test grant.','invalid_grant');
    az_creator_club_creator($creator,$db);
    $q=$db->prepare("SELECT 1 FROM users WHERE id=? AND status='active'");$q->execute([$fan]);
    if(!$q->fetchColumn())throw new AzHttpError(404,'Member not found.','not_found');
    $q=$db->prepare("SELECT 1 FROM creator_club_tiers WHERE id=? AND creator_id=? AND state='approved'");$q->execute([$tierId,$creator]);
    if(!$q->fetchColumn())throw new AzHttpError(409,'Tier must be approved.','tier_pending');
    $now=az_now();$ends=gmdate('Y-m-d\TH:i:s\Z',time()+$days*86400);$id=az_id();
    $db->prepare('INSERT INTO creator_club_entitlements(id,fan_id,creator_id,tier_id,origin,begins_at,ends_at,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?)')
      ->execute([$id,$fan,$creator,$tierId,'admin_test',$now,$ends,(int)$actor['id'],$now]);
    return ['id'=>$id,'endsAt'=>$ends,'origin'=>'admin_test'];
}
function az_creator_club_can_view(?array $viewer,array $post,?PDO $db=null,?string $now=null):bool
{
    if(!$viewer)return false;
    $db??=az_db();$creator=(int)$post['creator_id'];$viewerId=(int)$viewer['id'];
    if($viewerId===$creator&&az_has_role($viewer,'model'))return true;
    if(az_has_role($viewer,'admin'))return true;
    if($post['state']!=='approved')return false;
    // A post's media stays restricted when its tier is suspended or creator deactivated.
    try {az_creator_club_creator($creator,$db);}catch(AzHttpError){return false;}
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
    $db??=az_db();$isStaff=$viewer&&(az_has_role($viewer,'admin')||((int)$viewer['id']===$creator&&az_has_role($viewer,'model')));
    $sql='SELECT * FROM creator_club_posts WHERE creator_id=?'.($isStaff?'':" AND state='approved'").' ORDER BY created_at DESC,id DESC LIMIT 60';
    $q=$db->prepare($sql);$q->execute([$creator]);
    // Never expose private title/body/media ID unless authorization is satisfied.
    return array_values(array_filter($q->fetchAll(PDO::FETCH_ASSOC),fn($post)=>az_creator_club_can_view($viewer,$post,$db)));
}
function az_creator_club_review_queue(?PDO $db=null):array
{
    $db??=az_db();$result=[];
    foreach(['tier'=>'creator_club_tiers','post'=>'creator_club_posts'] as $type=>$table){
        $q=$db->query("SELECT * FROM $table WHERE state='pending' ORDER BY created_at ASC LIMIT 100");
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$result[]=['type'=>$type,'data'=>$row];
    }
    usort($result,static fn($a,$b)=>strcmp($a['data']['created_at'],$b['data']['created_at']));
    return $result;
}
