<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
function acceptance_check(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);}
function acceptance_reject(callable $fn,string $code):void{
    try{$fn();throw new RuntimeException('Expected '.$code);}
    catch(AzHttpError $e){acceptance_check($e->errorCode===$code,'Wrong denial '.$e->errorCode.' expecting '.$code);}
}
if(!in_array('sqlite',PDO::getAvailableDrivers(),true))throw new RuntimeException('pdo_sqlite required');
$now=strtotime('2026-11-23T14:00:00Z');$stamp=static fn(int $offset=0):string=>gmdate('Y-m-d\TH:i:s\Z',$now+$offset);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,status TEXT)');
$db->exec("INSERT INTO users VALUES(1,'Director Admin','active'),(2,'Unrelated Admin','active'),(3,'Fan Model','active')");
$db->exec('CREATE TABLE studio_schema(id INTEGER PRIMARY KEY,version INTEGER NOT NULL)');
$db->exec('INSERT INTO studio_schema VALUES(1,46)');
$db->exec('CREATE TABLE az_live_rooms(id TEXT PRIMARY KEY,owner_id INTEGER,title TEXT,status TEXT,started_epoch INTEGER,heartbeat_epoch INTEGER)');
$db->exec('CREATE TABLE az_live_viewers(id TEXT PRIMARY KEY,room_id TEXT,status TEXT,updated_epoch INTEGER)');
$db->exec('CREATE TABLE az_live_agent_samples(room_id TEXT,source TEXT,connection_id TEXT,updated_epoch INTEGER,
  camera_count INTEGER,camera_healthy INTEGER,viewer_count INTEGER,sent_fps REAL,stalled_outbound INTEGER,
  program_mode TEXT,decoded_fps REAL,video_state TEXT)');
$db->exec('CREATE TABLE personal_phone_devices(id TEXT PRIMARY KEY,owner_id INTEGER,status TEXT,expires_epoch INTEGER)');
$db->exec('CREATE TABLE personal_phone_live(device_id TEXT PRIMARY KEY,updated_epoch INTEGER)');
$db->exec('CREATE TABLE personal_media(id TEXT PRIMARY KEY,user_id INTEGER,size_bytes INTEGER)');
$db->exec('CREATE TABLE az_live_recordings(id TEXT PRIMARY KEY,room_id TEXT,owner_id INTEGER,media_id TEXT)');
$rid=str_repeat('a',32);$vid=str_repeat('b',32);$did=str_repeat('c',32);$media=str_repeat('d',32);
$db->prepare('INSERT INTO az_live_rooms VALUES(?,?,?,?,?,?)')->execute([$rid,1,'Real Camera Acceptance','live',$now-600,$now-2]);
$db->prepare('INSERT INTO az_live_viewers VALUES(?,?,?,?)')->execute([$vid,$rid,'connected',$now-4]);
$db->prepare('INSERT INTO az_live_agent_samples VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([$rid,'director',$rid,$now-3,1,1,1,24.5,0,'single',null,'unknown']);
$db->prepare('INSERT INTO az_live_agent_samples VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([$rid,'viewer',$vid,$now-4,0,0,0,null,0,'slate',23.5,'playing']);
$db->prepare('INSERT INTO personal_phone_devices VALUES(?,?,?,?)')->execute([$did,1,'approved',$now+3600]);
$db->prepare('INSERT INTO personal_phone_live VALUES(?,?)')->execute([$did,$now-3]);
$db->prepare('INSERT INTO personal_media VALUES(?,?,?)')->execute([$media,1,2500000]);
$db->prepare('INSERT INTO az_live_recordings VALUES(?,?,?,?)')->execute([str_repeat('e',32),$rid,1,$media]);
az_upgrade_live_acceptance_schema($db);
acceptance_check(az_schema_version($db)===47,'Schema 46→47 migration failed');
az_upgrade_live_acceptance_schema($db);
foreach(['az_live_acceptance_checks','az_live_acceptance_events'] as $table){
    acceptance_check((int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE name='$table' AND type='table'")->fetchColumn()===1,'Acceptance table missing');
}
$admin=['id'=>1,'status'=>'active','roles'=>['admin']];
$other=['id'=>2,'status'=>'active','roles'=>['admin']];
$member=['id'=>3,'status'=>'active','roles'=>['model']];
acceptance_reject(static fn()=>az_live_acceptance_status($member,$rid,$db,$now),'role_required');
acceptance_reject(static fn()=>az_live_acceptance_mark($member,['roomId'=>$rid],$db,$now),'role_required');
acceptance_reject(static fn()=>az_live_acceptance_status($other,$rid,$db,$now),'room_not_found');
acceptance_reject(static fn()=>az_live_acceptance_status($admin,'fake',$db,$now),'invalid_room');
$data=az_live_acceptance_status($admin,$rid,$db,$now);
acceptance_check($data['room']['status']==='live','Owned on-air room not found');
acceptance_check($data['diagnostics']['phoneApproved']===1&&$data['diagnostics']['phoneOffering']===1,'Approved phone or active offer count wrong');
acceptance_check($data['diagnostics']['directorFresh']===true&&$data['diagnostics']['healthyCameras']===1,'Director health observation absent');
acceptance_check($data['diagnostics']['programMode']==='single'&&$data['diagnostics']['sentFps']===24.5,'Program sender measurement incorrect');
acceptance_check($data['diagnostics']['fanReporting']===1&&$data['diagnostics']['fanPlaying']===1,'Playing viewer count incorrect');
acceptance_check($data['diagnostics']['fanDecodedFpsMax']===23.5,'Viewer decoded FPS incorrect');
acceptance_check($data['diagnostics']['savedRecordings']===1&&$data['diagnostics']['largestSavedBytes']===2500000,'Saved file evidence incorrect');
acceptance_check($data['completion']['status']==='not_started'&&$data['completion']['hostedIndependentlyVerified']===false,
    'Telemetry must not certify physical acceptance');
acceptance_reject(static fn()=>az_live_acceptance_mark($admin,[
    'roomId'=>$rid,'step'=>'remote_fan','result'=>'pass','note'=>'short','revision'=>0],$db,$now),'observation_required');
acceptance_reject(static fn()=>az_live_acceptance_mark($admin,[
    'roomId'=>$rid,'step'=>'UNKNOWN','result'=>'pass','note'=>'Remote cellular fan frames seen','revision'=>0],$db,$now),'invalid_step');
acceptance_reject(static fn()=>az_live_acceptance_mark($admin,[
    'roomId'=>$rid,'step'=>'remote_fan','result'=>'verified','note'=>'Remote cellular fan frames seen','revision'=>0],$db,$now),'invalid_result');
$first=az_live_acceptance_mark($admin,['roomId'=>$rid,'step'=>'remote_fan','result'=>'pass',
    'note'=>'Remote phone on cellular decoded moving Program frames','revision'=>0],$db,$now);
acceptance_check($first['revision']===1&&$first['independentlyVerified']===false,'Operator result or revision incorrect');
acceptance_reject(static fn()=>az_live_acceptance_mark($admin,['roomId'=>$rid,'step'=>'remote_fan',
    'result'=>'fail','note'=>'Video froze on the cellular viewer','revision'=>0],$db,$now),'revision_conflict');
$second=az_live_acceptance_mark($admin,['roomId'=>$rid,'step'=>'remote_fan','result'=>'fail',
    'note'=>'Video froze on second test after reconnect','revision'=>1],$db,$now);
acceptance_check($second['revision']===2,'Revision increment failed');
$third=az_live_acceptance_mark($admin,['roomId'=>$rid,'step'=>'remote_fan','result'=>'pass',
    'note'=>'Real cellular retest restored moving video frames','revision'=>2],$db,$now);
acceptance_check($third['revision']===3,'Follow-up retest not recorded');
acceptance_check((int)$db->query('SELECT COUNT(*) FROM az_live_acceptance_events')->fetchColumn()===3,'Audit history did not append');
foreach(array_keys(az_live_acceptance_steps()) as $step){
    if($step==='remote_fan')continue;
    az_live_acceptance_mark($admin,['roomId'=>$rid,'step'=>$step,'result'=>'pass',
        'note'=>'Physically tested on a separate real device during this show','revision'=>0],$db,$now);
}
$complete=az_live_acceptance_status($admin,$rid,$db,$now);
acceptance_check($complete['completion']['status']==='operator_attested'&&$complete['completion']['operatorPassed']===7,
    'Seven operator-confirmed checks should be fully attested');
acceptance_check($complete['completion']['hostedIndependentlyVerified']===false,
    'Manual completion falsely claimed hosted independent acceptance');
acceptance_check(count($complete['history'])===9,'Audit history was lost');
$json=json_encode($complete,JSON_THROW_ON_ERROR);
foreach(['offer_sdp','viewerId','phoneId','credential','SECRET_VIEWER','playerStream','SDP'] as $private){
    acceptance_check(!str_contains($json,$private),'Sensitive stream identity leaked: '.$private);
}
$stale=az_live_acceptance_status($admin,$rid,$db,$now+120);
acceptance_check($stale['room']['status']==='ended'&&$stale['diagnostics']['directorFresh']===false,
    'Expired broadcast must never display stale green health');
acceptance_check($stale['diagnostics']['fanReporting']===0,'Stale viewers should not be counted');
acceptance_reject(static fn()=>az_live_acceptance_mark($admin,['roomId'=>$rid,'step'=>'phone_pairing',
    'result'=>'fail','note'=>'A later test failed on the real phone','revision'=>1],$db,$now+8*86400),'test_window_expired');
$db->prepare('UPDATE az_live_rooms SET status=? WHERE id=?')->execute(['ended',$rid]);
$ended=az_live_acceptance_status($admin,null,$db,$now+300);
acceptance_check($ended['room']['status']==='ended'&&$ended['completion']['status']==='operator_attested',
    'Last owned ended broadcast must retain its explicit operator test history');
$api=file_get_contents(__DIR__.'/../live-api.php');
acceptance_check(str_contains($api,"action==='acceptanceMark'")&&str_contains($api,'az_require_csrf()'),
    'Mutation endpoint missing CSRF or routing');
print "Live acceptance: schema 47, owner privacy, fresh telemetry, manual attestations, audit/revisions, stale-room safety PASS\n";
