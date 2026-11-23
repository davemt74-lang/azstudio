<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
function phone_check(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);}
function phone_rejected(callable $fn,string $code):void{
    try{$fn();throw new RuntimeException('Expected '.$code);}catch(AzHttpError $e){phone_check($e->errorCode===$code,'Wrong status code: '.$e->errorCode);}
}
if(!in_array('sqlite',PDO::getAvailableDrivers(),true))throw new RuntimeException('pdo_sqlite required');
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE personal_phone_devices(id CHAR(32) PRIMARY KEY,owner_id INTEGER,status TEXT,expires_epoch INTEGER)');
$db->exec('CREATE TABLE personal_phone_live(device_id CHAR(32) PRIMARY KEY,nonce CHAR(32),offer_sdp TEXT,answer_sdp TEXT,updated_epoch INTEGER)');
$GLOBALS['az_database']=$db;
$id=str_repeat('a',32);$idOther=str_repeat('b',32);$idPending=str_repeat('c',32);
$nonce=str_repeat('d',32);$updated=time();
$device=$db->prepare('INSERT INTO personal_phone_devices VALUES(?,?,?,?)');
$device->execute([$id,10,'approved',$updated+120]);
$device->execute([$idOther,11,'approved',$updated+120]);
$device->execute([$idPending,10,'pending',$updated+120]);
$offer=$db->prepare('INSERT INTO personal_phone_live VALUES(?,?,?,?,?)');
$offer->execute([$id,$nonce,'v=0\r\nold-sdp','v=0\r\nanswer',$updated]);
$offer->execute([$idOther,$nonce,'v=0\r\nother-sdp',null,$updated]);
$offer->execute([$idPending,$nonce,'v=0\r\npending-sdp',null,$updated]);
phone_rejected(static fn()=>az_personal_phone_live_reset(10,'not-valid',$nonce),'invalid_stream');
phone_check(az_personal_phone_live_reset(11,$id,$nonce)['reset']===false,'Another owner was allowed to reset this phone');
phone_check(az_personal_phone_live_reset(10,$idPending,$nonce)['reset']===false,'Unapproved phone feed was reset');
phone_check(az_personal_phone_live_reset(10,$id,str_repeat('e',32))['reset']===false,'Wrong nonce must not affect stream');
$reset=az_personal_phone_live_reset(10,$id,$nonce);
phone_check($reset['reset']===true&&$reset['approvalUnchanged']===true,'Approved owner reset failed');
phone_check(az_personal_phone_live_reset(10,$id,$nonce)['reset']===false,'Repeat reset must be idempotent');
phone_check((int)$db->query("SELECT COUNT(*) FROM personal_phone_live WHERE device_id='$id'")->fetchColumn()===0,'Stale stream persisted');
phone_check((int)$db->query("SELECT COUNT(*) FROM personal_phone_live WHERE device_id='$idOther'")->fetchColumn()===1,'Other approved phone was interrupted');
phone_check((int)$db->query("SELECT COUNT(*) FROM personal_phone_live WHERE device_id='$idPending'")->fetchColumn()===1,'Pending phone relay was modified');
phone_check((int)$db->query("SELECT COUNT(*) FROM personal_phone_devices WHERE id='$id' AND status='approved'")->fetchColumn()===1,'Camera approval was modified');
// A newly offered camera must survive a late retry of its previous nonce.
$newNonce=str_repeat('f',32);
$db->prepare('INSERT INTO personal_phone_live VALUES(?,?,?,?,?)')->execute([$id,$newNonce,'v=0\r\nnew-sdp',null,$updated]);
phone_check(az_personal_phone_live_reset(10,$id,$nonce)['reset']===false,'Old nonce reset removed new camera offer');
phone_check((string)$db->query("SELECT nonce FROM personal_phone_live WHERE device_id='$id'")->fetchColumn()===$newNonce,'New camera offer was lost');
// Expiry prevents resets, even with a valid owner and nonce.
$db->prepare('UPDATE personal_phone_devices SET expires_epoch=? WHERE id=?')->execute([$updated-5,$id]);
phone_check(az_personal_phone_live_reset(10,$id,$newNonce)['reset']===false,'Expired device reset was permitted');
phone_check((string)$db->query("SELECT nonce FROM personal_phone_live WHERE device_id='$id'")->fetchColumn()===$newNonce,'Expired device offer unexpectedly removed');
$api=file_get_contents(__DIR__.'/../personal-phone-api.php');
phone_check(str_contains($api,"'resetFeed'"),'Missing authenticated host action');
phone_check(str_contains($api,'az_require_csrf()'),'Host repair action must retain CSRF verification');
$phoneJs=file_get_contents(__DIR__.'/../assets/personal-camera.js');
phone_check(str_contains($phoneJs,"signal('poll',null,{nonce:current})")&&str_contains($phoneJs,'}catch(e){retryLive(e);}')&&str_contains($phoneJs,'liveRetryTimer=setTimeout'),
    'Camera Studio must re-offer on stream_expired poll status');
print "Live phone relay reset: owner authorization, nonce isolation, approval preserved, idempotent reoffer PASS\n";
