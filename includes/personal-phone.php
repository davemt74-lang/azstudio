<?php
declare(strict_types=1);
/** Lucky-inspired owner-scoped camera pairing for the private personal media editor.
 * This capability is never an account login or publication permission.
 */
function az_upgrade_personal_phone_schema(PDO $db):void
{
    if (az_schema_version($db)>=20) return;
    az_prepare_storage();
    $lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Camera pairing schema lock unavailable.');
    try {
      if (az_schema_version($db)>=20) return;
      $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
      $uid=$mysql?'BIGINT UNSIGNED':'INTEGER';
      $long=$mysql?'LONGTEXT':'TEXT';
      $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
      $db->exec("CREATE TABLE IF NOT EXISTS personal_phone_invites (
        id CHAR(32) PRIMARY KEY, owner_id $uid NOT NULL, token_hash CHAR(64) NOT NULL,
        expires_epoch BIGINT NOT NULL, used_epoch BIGINT NOT NULL DEFAULT 0,
        created_epoch BIGINT NOT NULL, FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
      $db->exec("CREATE TABLE IF NOT EXISTS personal_phone_devices (
        id CHAR(32) PRIMARY KEY, invite_id CHAR(32) NOT NULL UNIQUE,
        owner_id $uid NOT NULL, display_name VARCHAR(48) NOT NULL,
        token_hash CHAR(64) NOT NULL, status VARCHAR(16) NOT NULL,
        expires_epoch BIGINT NOT NULL, last_seen_epoch BIGINT NOT NULL,
        created_epoch BIGINT NOT NULL,
        FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(invite_id) REFERENCES personal_phone_invites(id) ON DELETE CASCADE)$suffix");
      $db->exec("CREATE TABLE IF NOT EXISTS personal_phone_live (
        device_id CHAR(32) PRIMARY KEY, nonce CHAR(32) NOT NULL,
        offer_sdp $long NOT NULL, answer_sdp $long NULL,
        updated_epoch BIGINT NOT NULL,
        FOREIGN KEY(device_id) REFERENCES personal_phone_devices(id) ON DELETE CASCADE)$suffix");
      if ($mysql) {
        foreach ([['personal_phone_owner','personal_phone_devices','owner_id, status'],['personal_phone_invites_owner','personal_phone_invites','owner_id, expires_epoch']] as [$key,$table,$columns]) {
          $q=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
          $q->execute([$table,$key]);if (!(int)$q->fetchColumn())$db->exec("CREATE INDEX $key ON $table ($columns)");
        }
      } else {
        $db->exec('CREATE INDEX IF NOT EXISTS personal_phone_owner ON personal_phone_devices(owner_id, status)');
        $db->exec('CREATE INDEX IF NOT EXISTS personal_phone_invites_owner ON personal_phone_invites(owner_id, expires_epoch)');
      }
      $db->exec('UPDATE studio_schema SET version=20 WHERE id=1');
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function az_personal_phone_secure():void
{
    $host=strtolower((string)($_SERVER['HTTP_HOST']??''));
    $hostname=trim(explode(':',$host)[0],'[]');
    $https=(!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])!=='off') || (int)($_SERVER['SERVER_PORT']??0)===443;
    if (!$https && !in_array($hostname,['localhost','127.0.0.1','::1'],true))
        throw new AzHttpError(403,'Camera connections require HTTPS.','https_required');
}
function az_personal_phone_origin():void
{
    $origin=(string)($_SERVER['HTTP_ORIGIN']??'');
    $host=strtolower((string)($_SERVER['HTTP_HOST']??''));
    $https=(!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])!=='off') || (int)($_SERVER['SERVER_PORT']??0)===443;
    $expected=($https?'https':'http').'://'.$host;
    if ($origin==='' || !hash_equals($expected,strtolower(rtrim($origin,'/'))))
        throw new AzHttpError(403,'Camera request must come from this installation.','origin_invalid');
}
function az_personal_phone_digest(string $token):string
{
    if (!preg_match('/\A[a-f0-9]{64}\z/D',$token)) throw new AzHttpError(403,'Invalid camera permission.','camera_auth_invalid');
    return hash('sha256',hex2bin($token));
}
function az_personal_phone_invite(int $owner):array
{
    az_personal_phone_secure();$db=az_db();$now=time();
    $q=$db->prepare('SELECT COUNT(*) FROM personal_phone_invites WHERE owner_id=? AND used_epoch=0 AND expires_epoch>?');
    $q->execute([$owner,$now]);if ((int)$q->fetchColumn()>=4)throw new AzHttpError(429,'Four camera links are already active. Wait for one to expire.','invite_limit');
    $id=az_id();$secret=bin2hex(random_bytes(32));
    $q=$db->prepare('INSERT INTO personal_phone_invites(id,owner_id,token_hash,expires_epoch,used_epoch,created_epoch) VALUES(?,?,?,?,0,?)');
    $q->execute([$id,$owner,az_personal_phone_digest($secret),$now+300,$now]);
    return ['inviteId'=>$id,'code'=>$secret,'expiresEpoch'=>$now+300];
}
function az_personal_phone_claim(string $id,string $secret,string $name):array
{
    az_personal_phone_secure();az_personal_phone_origin();
    if (!az_valid_id($id)) throw new AzHttpError(403,'Camera invitation not found.','invite_invalid');
    $name=az_text($name,48,'Phone name');
    if ($name==='') throw new AzHttpError(400,'Enter a name for this phone.','name_required');
    $hash=az_personal_phone_digest($secret);$db=az_db();$now=time();
    $db->beginTransaction();
    try {
      $q=$db->prepare('SELECT owner_id,token_hash FROM personal_phone_invites WHERE id=? AND used_epoch=0 AND expires_epoch>?');
      $q->execute([$id,$now]);$invite=$q->fetch();
      if (!$invite||!hash_equals($invite['token_hash'],$hash))throw new AzHttpError(403,'Camera invitation expired or already used.','invite_invalid');
      $q=$db->prepare("SELECT COUNT(*) FROM personal_phone_devices WHERE owner_id=? AND status IN ('pending','approved') AND expires_epoch>?");
      $q->execute([(int)$invite['owner_id'],$now]);
      if ((int)$q->fetchColumn()>=8)throw new AzHttpError(409,'Disconnect an unused camera before adding another.','camera_limit');
      $q=$db->prepare('UPDATE personal_phone_invites SET used_epoch=? WHERE id=? AND used_epoch=0 AND expires_epoch>?');
      $q->execute([$now,$id,$now]);if ($q->rowCount()!==1)throw new AzHttpError(409,'This invitation was already used.','invite_used');
      $deviceId=az_id();$token=bin2hex(random_bytes(32));
      $q=$db->prepare("INSERT INTO personal_phone_devices(id,invite_id,owner_id,display_name,token_hash,status,expires_epoch,last_seen_epoch,created_epoch) VALUES(?,?,?,?,?,'pending',?,?,?)");
      $q->execute([$deviceId,$id,(int)$invite['owner_id'],$name,az_personal_phone_digest($token),$now+43200,$now,$now]);
      $db->commit();
      return ['id'=>$deviceId,'token'=>$token,'status'=>'pending','expiresEpoch'=>$now+43200];
    } catch(Throwable $e) {if ($db->inTransaction())$db->rollBack();throw $e;}
}
function az_personal_phone_device(string $id,string $token,bool $approved=false):array
{
    az_personal_phone_secure();
    if (!az_valid_id($id)) throw new AzHttpError(403,'Invalid camera identity.','camera_auth_invalid');
    $hash=az_personal_phone_digest($token);
    $db=az_db();$q=$db->prepare('SELECT * FROM personal_phone_devices WHERE id=?');$q->execute([$id]);$device=$q->fetch();
    if (!$device||!hash_equals((string)$device['token_hash'],$hash)||($device['status']??'')==='revoked'||(int)$device['expires_epoch']<=time())
        throw new AzHttpError(403,'Phone connection expired or disconnected. Scan a new QR code.','camera_disconnected');
    if ($approved && $device['status']!=='approved')throw new AzHttpError(403,'Waiting for the owner to approve this phone.','camera_pending');
    $db->prepare('UPDATE personal_phone_devices SET last_seen_epoch=? WHERE id=?')->execute([time(),$id]);
    return $device;
}
function az_personal_phone_list(int $owner):array
{
    // Connection approval alone is not evidence of video arriving. The phone
    // heartbeat/SDP state lets Director distinguish an inactive device, a
    // pending WebRTC handshake, and an answered session awaiting decoded frames.
    $now=time();
    $q=az_db()->prepare("SELECT d.id,d.display_name,d.status,d.expires_epoch,d.last_seen_epoch,
      CASE WHEN l.updated_epoch>? THEN 1 ELSE 0 END AS offering,
      CASE WHEN l.updated_epoch>? AND l.answer_sdp IS NOT NULL THEN 1 ELSE 0 END AS answered
      FROM personal_phone_devices d LEFT JOIN personal_phone_live l ON l.device_id=d.id
      WHERE d.owner_id=? AND d.expires_epoch>? AND d.status<>'revoked'
      ORDER BY d.created_epoch DESC LIMIT 12");
    $q->execute([$now-40,$now-40,$owner,$now]);
    return array_map(static fn(array $r):array=>['id'=>$r['id'],'name'=>$r['display_name'],'status'=>$r['status'],
        'expiresEpoch'=>(int)$r['expires_epoch'],'lastSeenEpoch'=>(int)$r['last_seen_epoch'],
        'sharing'=>(bool)$r['offering'],'answered'=>(bool)$r['answered']],$q->fetchAll());
}
function az_personal_phone_change(int $owner,string $deviceId,string $action):array
{
    if (!az_valid_id($deviceId))throw new AzHttpError(404,'Phone not found.','not_found');
    $now=time();$db=az_db();
    $q=$db->prepare('SELECT id,status,expires_epoch FROM personal_phone_devices WHERE id=? AND owner_id=?');
    $q->execute([$deviceId,$owner]);$row=$q->fetch();
    if (!$row||$row['status']==='revoked'||(int)$row['expires_epoch']<$now)throw new AzHttpError(404,'Phone not connected to your editor.','not_found');
    if ($action==='approve') {
      if ($row['status']!=='pending' && $row['status']!=='approved') throw new AzHttpError(409,'This phone cannot be approved.','invalid_status');
      $db->prepare("UPDATE personal_phone_devices SET status='approved' WHERE id=? AND owner_id=? AND status='pending'")->execute([$deviceId,$owner]);
      return ['id'=>$deviceId,'status'=>'approved'];
    }
    if ($action==='revoke') {
      $db->prepare("UPDATE personal_phone_devices SET status='revoked' WHERE id=? AND owner_id=?")->execute([$deviceId,$owner]);
      $db->prepare('DELETE FROM personal_phone_live WHERE device_id=?')->execute([$deviceId]);
      return ['id'=>$deviceId,'status'=>'revoked'];
    }
    throw new AzHttpError(400,'Unknown camera action.','unknown_action');
}
function az_personal_phone_upload_row(string $uploadId,array $device):array
{
    $row=az_personal_upload_row($uploadId,(int)$device['owner_id']);
    if (!str_starts_with((string)$row['client_token'],'phone:'.$device['id'].':'))
        throw new AzHttpError(403,'This upload belongs to another device.','camera_upload_denied');
    return $row;
}
function az_personal_phone_sdp(mixed $value,string $type):string
{
    if (!is_string($value)||strlen($value)<15||strlen($value)>120000||!preg_match('/\Av=0\r?\n/D',$value)||str_contains($value,"\0"))
      throw new AzHttpError(400,'Invalid camera stream description.','invalid_sdp');
    return $value;
}
function az_personal_phone_live_offer(array $device,string $nonce,string $sdp):array
{
    if (!az_valid_id($nonce))throw new AzHttpError(400,'Invalid stream identity.','invalid_nonce');
    $sdp=az_personal_phone_sdp($sdp,'offer');$now=time();$db=az_db();
    $q=$db->prepare('SELECT device_id FROM personal_phone_live WHERE device_id=?');$q->execute([$device['id']]);
    if ($q->fetchColumn()) {
      $q=$db->prepare('UPDATE personal_phone_live SET nonce=?,offer_sdp=?,answer_sdp=NULL,updated_epoch=? WHERE device_id=?');
      $q->execute([$nonce,$sdp,$now,$device['id']]);
    } else {
      $q=$db->prepare('INSERT INTO personal_phone_live(device_id,nonce,offer_sdp,answer_sdp,updated_epoch) VALUES(?,?,?,NULL,?)');
      $q->execute([$device['id'],$nonce,$sdp,$now]);
    }
    return ['sharing'=>true];
}
function az_personal_phone_live_offers(int $owner):array
{
    $q=az_db()->prepare("SELECT l.device_id,l.nonce,l.offer_sdp,d.display_name,d.last_seen_epoch FROM personal_phone_live l JOIN personal_phone_devices d ON d.id=l.device_id WHERE d.owner_id=? AND d.status='approved' AND d.expires_epoch>? AND l.updated_epoch>? ORDER BY l.updated_epoch DESC LIMIT 8");
    $q->execute([$owner,time(),time()-40]);
    return array_map(static fn(array $r):array=>['id'=>$r['device_id'],'nonce'=>$r['nonce'],'offer'=>$r['offer_sdp'],'name'=>$r['display_name']],$q->fetchAll());
}
function az_personal_phone_live_answer(int $owner,string $id,string $nonce,string $sdp):array
{
    if (!az_valid_id($id)||!az_valid_id($nonce))throw new AzHttpError(400,'Invalid camera stream.','invalid_stream');
    $sdp=az_personal_phone_sdp($sdp,'answer');$db=az_db();
    $q=$db->prepare("UPDATE personal_phone_live SET answer_sdp=?,updated_epoch=? WHERE device_id=? AND nonce=? AND answer_sdp IS NULL AND updated_epoch>? AND device_id IN (SELECT id FROM personal_phone_devices WHERE owner_id=? AND status='approved' AND expires_epoch>?)");
    $q->execute([$sdp,time(),$id,$nonce,time()-40,$owner,time()]);
    if ($q->rowCount()!==1) throw new AzHttpError(409,'Phone stream changed or disconnected.','stream_changed');
    return ['accepted'=>true];
}
function az_personal_phone_live_poll(array $device,string $nonce):array
{
    if (!az_valid_id($nonce))throw new AzHttpError(400,'Invalid stream.','invalid_stream');
    $db=az_db();$q=$db->prepare('SELECT answer_sdp FROM personal_phone_live WHERE device_id=? AND nonce=? AND updated_epoch>?');
    $q->execute([$device['id'],$nonce,time()-40]);$row=$q->fetch();
    if (!$row)throw new AzHttpError(409,'Camera stream expired. Reconnect it.','stream_expired');
    $db->prepare('UPDATE personal_phone_live SET updated_epoch=? WHERE device_id=? AND nonce=?')->execute([time(),$device['id'],$nonce]);
    return ['answer'=>$row['answer_sdp']?:null];
}
/** Studio-owner-only offer reset for a stalled approved phone feed.
 * Removing its *exact* stale nonce makes the phone's next authorized poll
 * return stream_expired, triggering the existing bounded phone-side reoffer.
 * No phone approval, recording or media file is modified.
 */
function az_personal_phone_live_reset(int $owner,string $id,string $nonce):array
{
    if(!az_valid_id($id)||!az_valid_id($nonce))
        throw new AzHttpError(400,'Invalid phone relay identity.','invalid_stream');
    $q=az_db()->prepare("DELETE FROM personal_phone_live WHERE device_id=? AND nonce=?
       AND EXISTS (SELECT 1 FROM personal_phone_devices WHERE id=? AND owner_id=?
                   AND status='approved' AND expires_epoch>?)");
    $q->execute([$id,$nonce,$id,$owner,time()]);
    // A phone that already changed nonce is left alone; never delete a new offer.
    return ['reset'=>$q->rowCount()===1,'approvalUnchanged'=>true];
}
function az_personal_phone_live_end(array $device,string $nonce):array
{
    if (!az_valid_id($nonce))throw new AzHttpError(400,'Invalid stream.','invalid_stream');
    az_db()->prepare('DELETE FROM personal_phone_live WHERE device_id=? AND nonce=?')->execute([$device['id'],$nonce]);
    return ['ended'=>true];
}
