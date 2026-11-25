<?php
declare(strict_types=1);
/** Account-owned Club reading and discovery. Notifications have a separate read state. */
function az_upgrade_creator_club_feed_schema(PDO $db):void
{
    if(az_schema_version($db)>=57||az_schema_version($db)<56)return;
    az_prepare_storage();$lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Creator Club feed schema lock unavailable.');
    $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    try{
        if(az_schema_version($db)>=57)return;
        if(!$mysql)$db->beginTransaction();
        $uid=$mysql?'BIGINT UNSIGNED':'INTEGER';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS creator_club_post_reads (
            user_id $uid NOT NULL,post_id CHAR(32) NOT NULL,read_revision INTEGER NOT NULL,
            read_at VARCHAR(32) NOT NULL,PRIMARY KEY(user_id,post_id),
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(post_id) REFERENCES creator_club_posts(id) ON DELETE CASCADE)$suffix");
        if($mysql){
            $q=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
            $q->execute(['creator_club_posts','club_post_publication']);
            if(!(int)$q->fetchColumn())$db->exec('CREATE INDEX club_post_publication ON creator_club_posts(state,published_at,id)');
        }else $db->exec('CREATE INDEX IF NOT EXISTS club_post_publication ON creator_club_posts(state,published_at,id)');
        $db->exec('UPDATE studio_schema SET version=57 WHERE id=1');
        if(!$mysql)$db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    finally{flock($lock,LOCK_UN);fclose($lock);}
}

function az_creator_club_feed_require(PDO $db,array $viewer):void
{
    az_creator_club_require_active($viewer,$db);
    if(az_schema_version($db)<57)throw new AzHttpError(503,'Club updates are being prepared. Please try again shortly.','club_feed_unavailable');
}

function az_creator_club_option(array $options,string $key,string $default=''):string
{
    $value=$options[$key]??$default;
    if(!is_string($value)&&!is_int($value))throw new AzHttpError(400,'Choose valid Club filters.','invalid_club_filter');
    return (string)$value;
}

function az_creator_club_page_limit(array $options):int
{
    return az_integer($options['limit']??12,1,40,'Page size');
}

/** Typed parameters also preserve integer affinity for SQLite computed expressions. */
function az_creator_club_query(PDO $db,string $sql,array $args):PDOStatement
{
    $q=$db->prepare($sql);
    foreach(array_values($args) as $i=>$value)$q->bindValue($i+1,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
    $q->execute();return $q;
}

function az_creator_club_cursor_scope(string $type,array $context):string
{
    return hash('sha256',$type.':'.json_encode($context,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

function az_creator_club_cursor_make(string $type,string $scope,string $sort,string $id):string
{
    return rtrim(strtr(base64_encode(json_encode(['v'=>1,'type'=>$type,'scope'=>$scope,'sort'=>$sort,'id'=>$id],JSON_UNESCAPED_SLASHES)),'+/','-_'),'=');
}

function az_creator_club_cursor_parse(string $cursor,string $type,string $scope):?array
{
    if($cursor==='')return null;
    $invalid=static fn()=>new AzHttpError(400,'This page link is no longer valid for these filters. Start from the first page.','invalid_club_cursor');
    if(strlen($cursor)>1024||!preg_match('/\A[A-Za-z0-9_-]+\z/D',$cursor))throw $invalid();
    $raw=base64_decode(strtr($cursor,'-_','+/'),true);
    if($raw===false)throw $invalid();
    try{$value=json_decode($raw,true,8,JSON_THROW_ON_ERROR);}catch(JsonException){throw $invalid();}
    if(!is_array($value)||($value['v']??null)!==1||($value['type']??null)!==$type||!is_string($value['scope']??null)||!hash_equals($scope,$value['scope'])||!is_string($value['sort']??null)||!is_string($value['id']??null))throw $invalid();
    if($type==='creators'){
        if(!preg_match('/\A[1-9][0-9]{0,17}\z/D',$value['id'])||$value['sort']!==$value['id'])throw $invalid();
    }elseif(!az_valid_id($value['id'])||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D',$value['sort']))throw $invalid();
    return $value;
}

/** Same public-name precedence as az_social_name; real names are not searched behind a stage name. */
function az_creator_club_name_sql():string
{
    return "COALESCE((SELECT NULLIF(TRIM(m.stage_name),'') FROM model_profiles m WHERE m.linked_user_id=u.id AND m.status='active' ORDER BY m.id LIMIT 1),u.name)";
}

/** Every member-facing page applies the full audience predicate before LIMIT. */
function az_creator_club_feed_predicate(array $viewer,string $now):array
{
    $fan=(int)$viewer['id'];
    $sql="u.status='active' AND EXISTS(SELECT 1 FROM user_roles ur WHERE ur.user_id=u.id AND ur.role='model')
        AND s.visibility IN ('public','members') AND p.state='approved' AND p.rights_confirmed=1
        AND p.published_at IS NOT NULL AND p.published_at<=?
        AND (p.media_id IS NULL OR EXISTS(SELECT 1 FROM personal_media pm WHERE pm.id=p.media_id AND pm.user_id=p.creator_id AND pm.revision=p.media_revision))
        AND ((p.audience='followers' AND EXISTS(SELECT 1 FROM creator_club_follows f WHERE f.creator_id=p.creator_id AND f.fan_id=?))";
    $args=[$now,$fan];
    if(az_config()['features']['creator_club_test_grants']??false){
        $sql.=" OR (p.audience='tier' AND EXISTS(SELECT 1 FROM creator_club_tiers t WHERE t.id=p.tier_id AND t.creator_id=p.creator_id AND t.state='approved')
            AND EXISTS(SELECT 1 FROM creator_club_entitlements e WHERE e.fan_id=? AND e.creator_id=p.creator_id AND e.tier_id=p.tier_id
                AND e.origin='admin_test' AND e.revoked_at IS NULL AND e.begins_at<=? AND e.ends_at>?))";
        array_push($args,$fan,$now,$now);
    }
    return [$sql.')',$args];
}

function az_creator_club_feed_from():string
{
    return ' FROM creator_club_posts p JOIN users u ON u.id=p.creator_id
        LEFT JOIN user_social_profiles s ON s.user_id=p.creator_id
        LEFT JOIN creator_club_post_reads r ON r.post_id=p.id AND r.user_id=?';
}

function az_creator_club_feed_select(string $sort):string
{
    return 'SELECT p.*,'.az_creator_club_name_sql().' AS creator_name,
        CASE WHEN s.avatar_storage_name IS NOT NULL AND s.avatar_storage_name<>\'\' THEN 1 ELSE 0 END AS creator_avatar,
        CASE WHEN r.read_revision=p.revision THEN 1 ELSE 0 END AS is_read,'.$sort.' AS page_sort';
}

function az_creator_club_present_feed_rows(array $rows,array $viewer,PDO $db):array
{
    $result=[];
    foreach($rows as $row){
        // Media delivery independently repeats this check for GET, HEAD and byte ranges.
        if(!az_creator_club_can_view($viewer,$row,$db))continue;
        $row['creator_id']=(int)$row['creator_id'];$row['revision']=(int)$row['revision'];
        $row['creator_avatar']=(bool)$row['creator_avatar'];$row['is_read']=(bool)$row['is_read'];
        $result[]=$row;
    }
    return $result;
}

function az_creator_club_feed_page(array $viewer,array $options=[],?PDO $db=null):array
{
    $db??=az_db();az_creator_club_feed_require($db,$viewer);
    $creator=az_integer($options['creator']??0,0,PHP_INT_MAX,'Creator');
    $view=az_creator_club_option($options,'view','all');
    if(!in_array($view,['all','unread'],true))throw new AzHttpError(400,'Choose All updates or Unread.','invalid_club_filter');
    $limit=az_creator_club_page_limit($options);$scope=az_creator_club_cursor_scope('feed',[(int)$viewer['id'],$creator,$view]);
    $cursor=az_creator_club_cursor_parse(az_creator_club_option($options,'cursor'),'feed',$scope);
    [$where,$args]=az_creator_club_feed_predicate($viewer,az_now());
    if($creator){$where.=' AND p.creator_id=?';$args[]=$creator;}
    $from=az_creator_club_feed_from();$baseArgs=array_merge([(int)$viewer['id']],$args);
    $unread='(r.read_revision IS NULL OR r.read_revision<>p.revision)';
    $unreadCount=(int)az_creator_club_query($db,'SELECT COUNT(*)'.$from.' WHERE '.$where.' AND '.$unread,$baseArgs)->fetchColumn();
    if($view==='unread')$where.=' AND '.$unread;
    if($cursor){$where.=' AND (p.published_at<? OR (p.published_at=? AND p.id<?))';array_push($baseArgs,$cursor['sort'],$cursor['sort'],$cursor['id']);}
    $q=az_creator_club_query($db,az_creator_club_feed_select('p.published_at').$from.' WHERE '.$where.' ORDER BY p.published_at DESC,p.id DESC LIMIT '.($limit+1),$baseArgs);
    $rows=az_creator_club_present_feed_rows($q->fetchAll(PDO::FETCH_ASSOC),$viewer,$db);
    $hasMore=count($rows)>$limit;if($hasMore)array_pop($rows);
    $last=$rows?end($rows):null;
    return ['posts'=>$rows,'nextCursor'=>$hasMore&&$last?az_creator_club_cursor_make('feed',$scope,$last['page_sort'],$last['id']):null,
        'unreadCount'=>$unreadCount,'creator'=>$creator,'view'=>$view,'limit'=>$limit];
}

/** Private management history is available only to the creator or an administrator. */
function az_creator_club_posts_page(int $creator,?array $viewer,array $options=[],?PDO $db=null):array
{
    if(!$viewer)return ['posts'=>[],'nextCursor'=>null];
    $db??=az_db();az_creator_club_feed_require($db,$viewer);
    if($creator<1)throw new AzHttpError(404,'Creator not found.','creator_missing');
    $manager=az_has_role($viewer,'admin')||((int)$viewer['id']===$creator&&az_has_role($viewer,'model'));
    $limit=az_creator_club_page_limit($options);$scope=az_creator_club_cursor_scope('posts',[(int)$viewer['id'],$creator,$manager]);
    $cursor=az_creator_club_cursor_parse(az_creator_club_option($options,'cursor'),'posts',$scope);
    if($manager){$where='p.creator_id=?';$args=[$creator];$sort='COALESCE(p.published_at,p.updated_at,p.created_at)';}
    else{[$where,$args]=az_creator_club_feed_predicate($viewer,az_now());$where.=' AND p.creator_id=?';$args[]=$creator;$sort='p.published_at';}
    $baseArgs=array_merge([(int)$viewer['id']],$args);
    if($cursor){$where.=' AND ('.$sort.'<? OR ('.$sort.'=? AND p.id<?))';array_push($baseArgs,$cursor['sort'],$cursor['sort'],$cursor['id']);}
    $q=az_creator_club_query($db,az_creator_club_feed_select($sort).az_creator_club_feed_from().' WHERE '.$where.' ORDER BY '.$sort.' DESC,p.id DESC LIMIT '.($limit+1),$baseArgs);
    $rows=az_creator_club_present_feed_rows($q->fetchAll(PDO::FETCH_ASSOC),$viewer,$db);
    $hasMore=count($rows)>$limit;if($hasMore)array_pop($rows);$last=$rows?end($rows):null;
    return ['posts'=>$rows,'nextCursor'=>$hasMore&&$last?az_creator_club_cursor_make('posts',$scope,$last['page_sort'],$last['id']):null,'limit'=>$limit];
}

function az_creator_club_mark_read(array $viewer,string $postId,bool $read,?PDO $db=null):array
{
    $db??=az_db();az_creator_club_feed_require($db,$viewer);
    if(!az_valid_id($postId))throw new AzHttpError(404,'Post not found.','not_found');
    return az_creator_club_write($db,function()use($db,$viewer,$postId,$read){
        $lookup=az_creator_club_query($db,'SELECT creator_id FROM creator_club_posts WHERE id=?',[$postId]);$creator=(int)($lookup->fetchColumn()?:0);
        if(!$creator)throw new AzHttpError(404,'Post not found.','not_found');
        $db->prepare('UPDATE users SET status=status WHERE id=?')->execute([$creator]);
        [$where,$args]=az_creator_club_feed_predicate($viewer,az_now());$args[]=$postId;
        $q=az_creator_club_query($db,'SELECT p.* FROM creator_club_posts p JOIN users u ON u.id=p.creator_id LEFT JOIN user_social_profiles s ON s.user_id=p.creator_id WHERE '.$where.' AND p.id=?',$args);
        $post=$q->fetch(PDO::FETCH_ASSOC);
        if(!$post||!az_creator_club_can_view($viewer,$post,$db))throw new AzHttpError(404,'Post not found.','not_found');
        $fan=(int)$viewer['id'];
        if(!$read)$db->prepare('DELETE FROM creator_club_post_reads WHERE user_id=? AND post_id=?')->execute([$fan,$postId]);
        else{
            $sql="INSERT INTO creator_club_post_reads(user_id,post_id,read_revision,read_at) VALUES(?,?,?,?)";
            $sql.=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
                ?' ON DUPLICATE KEY UPDATE read_revision=VALUES(read_revision),read_at=VALUES(read_at)'
                :' ON CONFLICT(user_id,post_id) DO UPDATE SET read_revision=excluded.read_revision,read_at=excluded.read_at';
            $db->prepare($sql)->execute([$fan,$postId,(int)$post['revision'],az_now()]);
        }
        return ['id'=>$postId,'is_read'=>$read];
    });
}

function az_creator_club_discovery_page(array $viewer,array $options=[],?PDO $db=null):array
{
    $db??=az_db();az_creator_club_feed_require($db,$viewer);
    $term=trim(az_creator_club_option($options,'q'));$filter=az_creator_club_option($options,'filter','all');
    if(strlen($term)>160)throw new AzHttpError(400,'Use a shorter creator search.','invalid_club_search');
    if(!in_array($filter,['all','following'],true))throw new AzHttpError(400,'Choose All creators or Following.','invalid_club_filter');
    $limit=az_creator_club_page_limit($options);$fan=(int)$viewer['id'];
    $scope=az_creator_club_cursor_scope('creators',[$fan,$term,$filter]);
    $cursor=az_creator_club_cursor_parse(az_creator_club_option($options,'cursor'),'creators',$scope);
    $name=az_creator_club_name_sql();
    $sql="SELECT u.id,$name AS name,s.tagline,s.avatar_storage_name,
        CASE WHEN f.fan_id IS NOT NULL THEN 1 ELSE 0 END AS following
        FROM users u JOIN user_social_profiles s ON s.user_id=u.id
        LEFT JOIN creator_club_follows f ON f.creator_id=u.id AND f.fan_id=?
        WHERE u.status='active' AND s.visibility IN ('public','members')
        AND EXISTS(SELECT 1 FROM user_roles ur WHERE ur.user_id=u.id AND ur.role='model')";
    $args=[$fan];
    if($filter==='following')$sql.=' AND f.fan_id IS NOT NULL';
    if($term!==''){
        $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$term).'%';
        $sql.=" AND (LOWER($name) LIKE LOWER(?) ESCAPE '!' OR LOWER(s.tagline) LIKE LOWER(?) ESCAPE '!')";
        array_push($args,$pattern,$pattern);
    }
    if($cursor){$sql.=' AND u.id<?';$args[]=(int)$cursor['id'];}
    $sql.=' ORDER BY u.id DESC LIMIT '.($limit+1);
    $rows=az_creator_club_query($db,$sql,$args)->fetchAll(PDO::FETCH_ASSOC);
    $hasMore=count($rows)>$limit;if($hasMore)array_pop($rows);
    $creators=[];
    foreach($rows as $row)$creators[]=['id'=>(int)$row['id'],'name'=>(string)$row['name'],'tagline'=>(string)$row['tagline'],
        'avatar'=>!empty($row['avatar_storage_name']),'following'=>(bool)$row['following']];
    $last=$creators?end($creators):null;
    return ['creators'=>$creators,'nextCursor'=>$hasMore&&$last?az_creator_club_cursor_make('creators',$scope,(string)$last['id'],(string)$last['id']):null,'q'=>$term,'filter'=>$filter,'limit'=>$limit];
}
