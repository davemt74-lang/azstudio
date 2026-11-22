<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
function check(bool $pass,string $message):void {if(!$pass)throw new RuntimeException($message);}
check(version_compare(AZ_VERSION,'1.18.0','>='),'Live functionality requires release 1.18 or later');
check(AZ_LIVE_MAX_VIEWERS===6,'Pilot viewer cap changed');
check(az_live_ice_servers()[0]['urls'][0]==='stun:stun.l.google.com:19302','Default STUN is missing');
$room=az_live_room_public(['id'=>str_repeat('a',32),'title'=>'Welcome','started_epoch'=>123,'owner_id'=>999,'heartbeat_epoch'=>456]);
check(!isset($room['owner_id'])&&!isset($room['heartbeat_epoch']),'Private room data leaked');
check($room['title']==='Welcome'&&$room['startedEpoch']===123,'Room presentation corrupted');
foreach (['bad','v=0'."\n".str_repeat('a',140001), 'v=0'."\nabc\0def"] as $bad) {
    try {az_live_signal_sdp($bad);throw new RuntimeException('Malformed SDP accepted');}
    catch(AzHttpError $e){check($e->status===400,'Incorrect SDP error status');}
}
check(az_live_signal_sdp("v=0\r\no=- 1 1 IN IP4 0.0.0.0\r\n")!=='','Valid SDP rejected');
check(is_callable('az_upgrade_live_schema'),'Missing schema migration');
check(is_callable('az_upgrade_live_archive_schema'),'Missing archive migration');
check(is_callable('az_upgrade_live_public_schema'),'Missing public audience migration');
$payload=az_live_recording_payload(['id'=>str_repeat('a',32),'title'=>'Recorded','description'=>'A show','recorded_at'=>'2026-11-20T12:00:00Z','cover_storage_name'=>null,'published_at'=>null],false);
check(!isset($payload['ownerId'])&&!isset($payload['mediaId'])&&$payload['published']===false,'Private recording metadata leaked');
check($payload['audience']==='members','Old recordings must remain members-only');
echo "11 live PHP contract checks passed (no PDO driver required).\n";
