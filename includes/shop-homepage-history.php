<?php
declare(strict_types=1);
/** Immutable, bounded snapshots. Restoring a version always changes the draft only. */
function az_upgrade_shop_homepage_history_schema(PDO $db): void
{
    if(az_schema_version($db)>=54 || az_schema_version($db)<53)return;
    az_prepare_storage();$lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Homepage history migration lock unavailable.');
    try{
        if(az_schema_version($db)>=54)return;
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $text=$mysql?'LONGTEXT':'TEXT';$userId=$mysql?'BIGINT UNSIGNED':'INTEGER';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS shop_homepage_history (
            id CHAR(32) PRIMARY KEY, revision INTEGER NOT NULL, scope VARCHAR(12) NOT NULL,
            action VARCHAR(30) NOT NULL, design_json $text NOT NULL, actor_id $userId NULL,
            actor_name VARCHAR(180) NOT NULL, created_at VARCHAR(32) NOT NULL)$suffix");
        // Keep DDL outside the transaction for MySQL; snapshots and schema marker commit together.
        $db->beginTransaction();
        try{
            if(!(int)$db->query('SELECT COUNT(*) FROM shop_homepage_history')->fetchColumn()){
                $state=az_shop_homepage_state($db);
                az_shop_homepage_history_record($db,$state['published_json'],$state['revision'],'published','upgrade',null);
                if($state['draft_json']!==$state['published_json'])az_shop_homepage_history_record($db,$state['draft_json'],$state['revision'],'draft','upgrade',null);
            }
            $db->exec('UPDATE studio_schema SET version=54 WHERE id=1');
            $db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function az_shop_homepage_history_enabled(PDO $db):bool{return az_schema_version($db)>=54;}
/** Called in the same transaction as the revision update. Names are historical labels, never access grants. */
function az_shop_homepage_history_record(PDO $db,array $design,int $revision,string $scope,string $action,?array $actor):void
{
    $q=$db->prepare('INSERT INTO shop_homepage_history(id,revision,scope,action,design_json,actor_id,actor_name,created_at) VALUES(?,?,?,?,?,?,?,?)');
    $q->execute([az_id(),$revision,$scope,$action,json_encode($design,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
        $actor?(int)$actor['id']:null,$actor?(preg_replace('/^(.{0,180}).*$/us','$1',(string)($actor['name']??'Administrator'))??'Administrator'):'Existing design',az_now()]);
    // Two scopes can share a revision. Keep the newest 30 complete snapshots.
    $old=$db->query('SELECT id FROM shop_homepage_history ORDER BY revision DESC,created_at DESC,id DESC LIMIT 100000 OFFSET 30')->fetchAll(PDO::FETCH_COLUMN);
    $delete=$db->prepare('DELETE FROM shop_homepage_history WHERE id=?');
    foreach($old as $id)$delete->execute([$id]);
}
function az_shop_homepage_history_list(array $user,?PDO $db=null):array
{
    az_shop_admin($user);$db??=az_db();if(!az_shop_homepage_history_enabled($db))return [];
    $rows=$db->query('SELECT * FROM shop_homepage_history ORDER BY revision DESC,created_at DESC,id DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static function(array $row):array{
        $design=json_decode($row['design_json'],true,64,JSON_THROW_ON_ERROR);
        return ['id'=>$row['id'],'revision'=>(int)$row['revision'],'scope'=>$row['scope'],'action'=>$row['action'],
            'actor'=>$row['actor_name'],'createdAt'=>$row['created_at'],'heading'=>(string)($design['heading']??''),
            'enabledSections'=>count(array_filter($design['sections']??[])),
            'imageIds'=>az_shop_homepage_image_ids($design)];
    },$rows);
}
/** Lock the one settings row so saves, restores and image deletion agree on references. */
function az_shop_homepage_write_lock(PDO $db):void
{
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$db->query('SELECT revision FROM shop_homepage_settings WHERE id=1 FOR UPDATE')->fetchColumn();
    else $db->exec('UPDATE shop_homepage_settings SET revision=revision WHERE id=1');
}
function az_shop_homepage_history_restore(array $user,array $body,?PDO $db=null):array
{
    az_shop_admin($user);$db??=az_db();
    if(!az_shop_homepage_history_enabled($db))throw new AzHttpError(409,'Homepage history is not available yet.','history_unavailable');
    $id=$body['id']??null;$revision=$body['revision']??null;
    if(!is_string($id)||!az_valid_id($id)||!is_int($revision)||$revision<1)throw new AzHttpError(400,'Choose a saved version and current revision.','invalid_version');
    $db->beginTransaction();
    try{
        az_shop_homepage_write_lock($db);$state=az_shop_homepage_state($db);
        if($state['revision']!==$revision)throw new AzHttpError(409,'Homepage changed in another tab. Reload before restoring.','revision_conflict');
        $q=$db->prepare('SELECT design_json FROM shop_homepage_history WHERE id=?');$q->execute([$id]);$json=$q->fetchColumn();
        if($json===false)throw new AzHttpError(404,'This saved version is no longer retained.','version_missing');
        $design=az_shop_homepage_normalize(json_decode((string)$json,true,64,JSON_THROW_ON_ERROR));
        // A catalog item may have been removed or archived since this snapshot.
        // Match the admin picker's eligibility while preserving the saved order.
        // The original version stays immutable; only the new restored draft is adjusted.
        $available=array_fill_keys(array_column(az_shop_homepage_product_choices($db),'id'),true);
        $selected=$design['featuredProductIds'];$removed=0;
        if(is_array($selected)&&array_is_list($selected)){
            $design['featuredProductIds']=array_values(array_filter($selected,static fn($id):bool=>is_string($id)&&isset($available[$id])));
            $removed=count($selected)-count($design['featuredProductIds']);
        }
        $design=az_shop_homepage_validate($design,$db);
        $q=$db->prepare('UPDATE shop_homepage_settings SET draft_json=?,revision=revision+1,updated_at=? WHERE id=1 AND revision=?');
        $q->execute([json_encode($design,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),az_now(),$revision]);
        if($q->rowCount()!==1)throw new AzHttpError(409,'Homepage changed; reload before restoring.','revision_conflict');
        az_shop_homepage_history_record($db,$design,$revision+1,'draft','restore',$user);
        $db->commit();return ['saved'=>true,'published'=>false,'revision'=>$revision+1,'data'=>$design,
            'removedFeaturedCount'=>$removed,'warnings'=>$removed?[$removed.' unavailable featured '.($removed===1?'product was':'products were').' removed. Review before publishing.']:[]];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function az_shop_homepage_history_delete(array $user,array $body,?PDO $db=null):array
{
    az_shop_admin($user);$db??=az_db();$id=$body['id']??null;$revision=$body['revision']??null;
    if(!is_string($id)||!az_valid_id($id)||!is_int($revision)||$revision<1)throw new AzHttpError(400,'Choose a saved version and current revision.','invalid_version');
    $db->beginTransaction();
    try{
        az_shop_homepage_write_lock($db);
        if(az_shop_homepage_state($db)['revision']!==$revision)throw new AzHttpError(409,'Homepage changed; reload before removing a saved version.','revision_conflict');
        $q=$db->prepare('DELETE FROM shop_homepage_history WHERE id=?');$q->execute([$id]);
        if(!$q->rowCount())throw new AzHttpError(404,'This saved version is no longer retained.','version_missing');
        $db->commit();return ['deleted'=>true,'id'=>$id];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function az_shop_homepage_history_uses_image(PDO $db,string $id):bool
{
    if(!az_shop_homepage_history_enabled($db))return false;
    foreach($db->query('SELECT design_json FROM shop_homepage_history')->fetchAll(PDO::FETCH_COLUMN) as $json)
        if(in_array($id,az_shop_homepage_image_ids(json_decode($json,true,64,JSON_THROW_ON_ERROR)),true))return true;
    return false;
}
