<?php
declare(strict_types=1);
/** Member-facing social profiles. Private by default; only explicitly published
 * profile fields and already-published Watch videos can be seen by others.
 */
function az_upgrade_social_schema(PDO $db): void
{
    if (az_schema_version($db) >= 21 || az_schema_version($db) < 20) return;
    az_prepare_storage();
    $lock = fopen(AZ_STORAGE . '/schema-migration.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Could not acquire profile schema lock.');
    try {
        if (az_schema_version($db) >= 21) return;
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $uid = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $db->exec("CREATE TABLE IF NOT EXISTS user_social_profiles (
            user_id $uid PRIMARY KEY, handle VARCHAR(40) NULL UNIQUE,
            tagline VARCHAR(180) NOT NULL DEFAULT '', bio TEXT NOT NULL,
            specialties TEXT NOT NULL, visibility VARCHAR(12) NOT NULL DEFAULT 'private',
            avatar_storage_name VARCHAR(80) NULL, avatar_mime_type VARCHAR(80) NULL,
            cover_storage_name VARCHAR(80) NULL, cover_mime_type VARCHAR(80) NULL,
            revision INTEGER NOT NULL DEFAULT 1, created_at VARCHAR(32) NOT NULL,
            updated_at VARCHAR(32) NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        $db->exec('UPDATE studio_schema SET version=21 WHERE id=1');
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
function az_social_empty(int $userId): array
{
    return ['user_id'=>$userId, 'handle'=>null, 'tagline'=>'', 'bio'=>'', 'specialties'=>'',
        'visibility'=>'private', 'avatar_storage_name'=>null, 'avatar_mime_type'=>null,
        'cover_storage_name'=>null, 'cover_mime_type'=>null, 'revision'=>0];
}
function az_social_row(int $userId): array
{
    $q=az_db()->prepare('SELECT * FROM user_social_profiles WHERE user_id=?');$q->execute([$userId]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: az_social_empty($userId);
}
function az_social_editable(array $row): array
{
    return ['handle'=>$row['handle']??'', 'tagline'=>$row['tagline']??'',
        'bio'=>$row['bio']??'', 'specialties'=>$row['specialties']??'',
        'visibility'=>$row['visibility']??'private', 'revision'=>(int)($row['revision']??0),
        'hasAvatar'=>!empty($row['avatar_storage_name']), 'hasCover'=>!empty($row['cover_storage_name'])];
}
function az_social_name(array $user): string
{
    if (in_array('model',$user['roles']??[],true)) {
        $q=az_db()->prepare('SELECT stage_name FROM model_profiles WHERE linked_user_id=? AND status=\'active\' LIMIT 1');
        $q->execute([$user['id']]);$name=$q->fetchColumn();
        if (is_string($name)&&trim($name)!=='') return $name;
    }
    return (string)$user['name'];
}
function az_social_target(?string $handle, int $id): ?array
{
    if ($handle !== null && $handle !== '') {
        if (!preg_match('/\A[a-z0-9][a-z0-9_-]{2,39}\z/D', $handle)) return null;
        $q=az_db()->prepare('SELECT user_id FROM user_social_profiles WHERE handle=?');$q->execute([$handle]);
        $found=$q->fetchColumn();
        if ($found === false && preg_match('/\Amember-([0-9]+)\z/D',$handle,$m)) $found=(int)$m[1];
        if ($found === false) return null;
        $id=(int)$found;
    }
    if ($id<1) return null;
    $user=az_account_user($id);
    return $user && $user['status']==='active' ? $user : null;
}
function az_social_visible(array $row, ?array $viewer, array $target): bool
{
    if ($viewer && ((int)$viewer['id']===(int)$target['id'] || in_array('admin',$viewer['roles']??[],true))) return true;
    return ($row['visibility']??'private')==='public' || (($row['visibility']??'private')==='members' && $viewer!==null);
}
function az_social_save(array $body, int $userId): array
{
    $row=az_social_row($userId);
    $revision=az_integer($body['revision']??null,0,PHP_INT_MAX,'Profile revision');
    if ($revision!==(int)$row['revision']) throw new AzHttpError(409,'Profile changed. Reload before saving.','revision_conflict');
    $handle=strtolower(az_text($body['handle']??'',40,'Profile handle'));
    if ($handle!=='' && (!preg_match('/\A[a-z][a-z0-9_-]{2,29}\z/D',$handle) || preg_match('/\Amember-[0-9]+\z/D',$handle)
        || in_array($handle,['admin','login','user','users','settings','account','api','apply','watch','install','media','assets','private'],true)))
        throw new AzHttpError(400,'Use 3–30 letters, numbers, underscores or hyphens, beginning with a letter.','invalid_handle');
    $tagline=az_text($body['tagline']??'',180,'Profile headline');
    $bio=az_text($body['bio']??'',3000,'Profile bio');
    $specialties=az_text($body['specialties']??'',1000,'Specialties');
    $visibility=az_text($body['visibility']??'private',12,'Profile visibility');
    if (!in_array($visibility,['private','members','public'],true)) throw new AzHttpError(400,'Choose a valid visibility setting.','invalid_visibility');
    $now=az_now();$db=az_db();
    try {
        if ($revision===0) {
            $q=$db->prepare('INSERT INTO user_social_profiles(user_id,handle,tagline,bio,specialties,visibility,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
            $q->execute([$userId,$handle!==''?$handle:null,$tagline,$bio,$specialties,$visibility,$now,$now]);
        } else {
            $q=$db->prepare('UPDATE user_social_profiles SET handle=?,tagline=?,bio=?,specialties=?,visibility=?,revision=revision+1,updated_at=? WHERE user_id=? AND revision=?');
            $q->execute([$handle!==''?$handle:null,$tagline,$bio,$specialties,$visibility,$now,$userId,$revision]);
            if ($q->rowCount()!==1) throw new AzHttpError(409,'Profile changed. Reload before saving.','revision_conflict');
        }
    } catch(PDOException $e) {
        $q=$db->prepare('SELECT user_id FROM user_social_profiles WHERE handle=?');$q->execute([$handle]);
        if ($handle!=='' && $q->fetchColumn()!==false) throw new AzHttpError(409,'That profile handle is already taken.','handle_taken');
        throw $e;
    }
    return az_social_editable(az_social_row($userId));
}
function az_social_upload_image(int $userId, string $kind): array
{
    if (!in_array($kind,['avatar','cover'],true)) throw new AzHttpError(400,'Choose profile picture or cover.','invalid_image_type');
    $file=$_FILES['image']??null;
    if (!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name']??'')))
        throw new AzHttpError(400,'Select an image to upload.','upload_missing');
    $max=$kind==='avatar'?5*1048576:8*1048576;
    $size=@filesize($file['tmp_name']);
    if ($size===false || $size<1 || $size>$max) throw new AzHttpError(413,'Image is too large (5 MB avatar, 8 MB cover).','image_too_large');
    $image=@getimagesize($file['tmp_name']);$mime=$image['mime']??'';
    if (!$image || !in_array($mime,['image/jpeg','image/png','image/webp'],true) || $image[0]<100 || $image[1]<100 || $image[0]>10000 || $image[1]>10000 || $image[0]*$image[1]>35000000)
        throw new AzHttpError(415,'Use a JPG, PNG, or WebP picture with valid dimensions.','image_invalid');
    az_prepare_storage();$new=az_id().'.bin';$path=az_media_path(['storage_name'=>$new]);
    if (!move_uploaded_file($file['tmp_name'],$path)) throw new AzHttpError(507,'Could not save the picture.','storage_unavailable');
    @chmod($path,0640);$db=az_db();$row=az_social_row($userId);$old=$row[$kind.'_storage_name']??null;
    try {
        $db->beginTransaction();
        if ((int)$row['revision']===0) {
            $q=$db->prepare('INSERT INTO user_social_profiles(user_id,tagline,bio,specialties,visibility,'.$kind.'_storage_name,'.$kind.'_mime_type,created_at,updated_at) VALUES(?,\'\',\'\',\'\',\'private\',?,?,?,?)');
            $q->execute([$userId,$new,$mime,az_now(),az_now()]);
        } else {
            $q=$db->prepare('UPDATE user_social_profiles SET '.$kind.'_storage_name=?,'.$kind.'_mime_type=?,revision=revision+1,updated_at=? WHERE user_id=?');
            $q->execute([$new,$mime,az_now(),$userId]);
        }
        $db->commit();
    } catch(Throwable $e) { if ($db->inTransaction())$db->rollBack();@unlink($path);throw $e; }
    if ($old && preg_match('/\A[a-f0-9]{32}\.bin\z/D',$old)) @unlink(az_media_path(['storage_name'=>$old]));
    return az_social_editable(az_social_row($userId));
}
function az_social_remove_image(int $userId, string $kind): array
{
    if (!in_array($kind,['avatar','cover'],true)) throw new AzHttpError(400,'Choose profile picture or cover.','invalid_image_type');
    $row=az_social_row($userId);$old=$row[$kind.'_storage_name']??null;
    if (!$old) return az_social_editable($row);
    $q=az_db()->prepare('UPDATE user_social_profiles SET '.$kind.'_storage_name=NULL,'.$kind.'_mime_type=NULL,revision=revision+1,updated_at=? WHERE user_id=?');
    $q->execute([az_now(),$userId]);
    if (preg_match('/\A[a-f0-9]{32}\.bin\z/D',$old)) @unlink(az_media_path(['storage_name'=>$old]));
    return az_social_editable(az_social_row($userId));
}
function az_social_videos(int $userId): array
{
    $model=az_member_profile($userId);
    if (!$model || !function_exists('az_public_video_conditions')) return [];
    $q=az_db()->prepare('SELECT v.id,v.fan_title,v.fan_description,v.fan_published_at,v.cover_storage_name FROM finished_videos v JOIN interviews i ON i.id=v.interview_id JOIN users u ON u.id=i.owner_id WHERE v.model_id=? AND '.az_public_video_conditions().' ORDER BY v.fan_published_at DESC,v.id DESC LIMIT 24');
    $q->execute([$model['id']]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
