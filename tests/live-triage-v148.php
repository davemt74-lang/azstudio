<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
function triage_check(bool $actual,string $message):void{if(!$actual)throw new RuntimeException($message);}
function codes(array $a):array{return array_column($a['findings'],'code');}
function has_code(array $a,string $code):bool{return in_array($code,codes($a),true);}
$live=['status'=>'live','id'=>str_repeat('a',32),'title'=>'PRIVATE_BROADCAST_NAME'];
$ended=['status'=>'ended'];
$base=[
 'phoneApproved'=>1,'phoneOffering'=>1,'directorFresh'=>true,
 'directorAgeSeconds'=>2,'healthyCameras'=>1,'cameraCount'=>1,
 'programMode'=>'single','sentFps'=>24.5,'viewerCount'=>1,'outboundStalls'=>0,
 'fanReporting'=>1,'fanPlaying'=>1,'fanStalled'=>0,'fanBlocked'=>0,
 'fanDecodedFpsMax'=>23.5,'savedRecordings'=>1,'largestSavedBytes'=>2750000
];
$unknown=az_live_triage_analyze(null,null);
triage_check($unknown['state']==='no_session'&&has_code($unknown,'no_broadcast'),'No-session start instructions absent');
triage_check(!$unknown['independentlyVerified']&&!$unknown['automatedRecoveryTriggered'],'No session falsely certified');
$old=az_live_triage_analyze($ended,$base);
triage_check($old['state']==='ended'&&has_code($old,'broadcast_ended'),'Ended show not distinguished from current video');
triage_check(!has_code($old,'phone_no_offer')&&!has_code($old,'director_missing'),'Ended show must not present stale live transport failures');
$missing=az_live_triage_analyze($live,null);
triage_check(has_code($missing,'telemetry_unavailable')&&$missing['state']==='attention','Missing data must not be treated as healthy');
$none=az_live_triage_analyze($live,array_merge($base,['phoneApproved'=>0,'phoneOffering'=>0,'directorFresh'=>false,
    'cameraCount'=>null,'healthyCameras'=>null,'viewerCount'=>0,'fanReporting'=>0,'fanPlaying'=>0]));
triage_check(has_code($none,'phone_unapproved')&&has_code($none,'director_missing')&&has_code($none,'fan_test_needed'),'No camera / director / fan states omitted');
triage_check($none['criticalCount']===0,'No camera does not prove transport was broken');
$noOffer=az_live_triage_analyze($live,array_merge($base,['phoneOffering'=>0]));
triage_check(has_code($noOffer,'phone_no_offer')&&!has_code($noOffer,'phone_unapproved'),'Approved phone with no live offer not diagnosed');
$frozen=az_live_triage_analyze($live,array_merge($base,['healthyCameras'=>0,'cameraCount'=>1]));
triage_check($frozen['criticalCount']>=1&&has_code($frozen,'preview_frozen'),'Frozen preview must be a high-priority blocker');
$absent=az_live_triage_analyze($live,array_merge($base,['cameraCount'=>0,'healthyCameras'=>0]));
triage_check(has_code($absent,'preview_missing'),'Accepted phone offer without Director source missed');
$slate=az_live_triage_analyze($live,array_merge($base,['programMode'=>'slate']));
triage_check(has_code($slate,'holding_slate')&&$slate['criticalCount']===0,'Operator holding slate is not a failed network');
$send=az_live_triage_analyze($live,array_merge($base,['outboundStalls'=>2]));
triage_check(has_code($send,'sender_stalled')&&$send['criticalCount']===1,'Outbound Program stalls missing');
$noFps=az_live_triage_analyze($live,array_merge($base,['sentFps'=>0]));
triage_check(has_code($noFps,'sender_low_fps'),'Zero Program sender FPS with viewer not diagnosed');
$fan=az_live_triage_analyze($live,array_merge($base,['fanStalled'=>2,'fanBlocked'=>1,'fanPlaying'=>0]));
triage_check(has_code($fan,'fan_frozen')&&has_code($fan,'fan_autoplay'),'Fan freeze/autoplay variants not diagnosed separately');
$fanTelemetry=az_live_triage_analyze($live,array_merge($base,['viewerCount'=>2,'fanReporting'=>0,'fanPlaying'=>0]));
triage_check(has_code($fanTelemetry,'fan_telemetry_missing')&&!has_code($fanTelemetry,'fan_frozen'),
 'Missing fan report must not be fabricated into frozen video');
$clean=az_live_triage_analyze($live,$base);
triage_check($clean['state']==='not_confirmed'&&!$clean['findings'],'Healthy measurements must remain not independently confirmed');
triage_check(str_contains($clean['nextStep'],'real devices'),'No live telemetry can self-certify acceptance');
$failed=az_live_triage_analyze($live,$base,['fan_audio'=>['result'=>'fail','note'=>'PRIVATE_NOTE_FROM_TEST']]);
triage_check(has_code($failed,'operator_failed_fan_audio')&&$failed['state']==='attention','Explicit failed audio acceptance should appear');
triage_check(!str_contains(json_encode($failed),'PRIVATE_NOTE_FROM_TEST'),'Operator private note leaked into troubleshooting');
$packet=az_live_triage_support_summary($live,array_merge($base,['ip'=>'192.168.1.9','sdp'=>'v=0','viewerId'=>str_repeat('c',32),
    'note'=>'PRIVATE_NOTE','access_token'=>'SECRET_CAMERA_TOKEN','roomId'=>str_repeat('a',32)]),$failed,
  ['operatorPassed'=>2,'operatorFailed'=>1,'unverifiedSteps'=>4]);
$serial=json_encode($packet,JSON_THROW_ON_ERROR);
foreach(['192.168.1.9','SECRET_CAMERA_TOKEN','PRIVATE_NOTE','PRIVATE_BROADCAST_NAME',
         str_repeat('a',32),str_repeat('c',32),'v=0','viewerId','roomId','sdp','access_token'] as $private){
    triage_check(!str_contains($serial,$private),'Support summary included private field '.$private);
}
triage_check($packet['roomState']==='live'&&$packet['hostedIndependentlyVerified']===false,
    'Support summary falsely claims independent acceptance');
triage_check($packet['measurements']['largestSavedBytes']===2750000.0,'Large recording metadata incorrectly truncated');
triage_check($packet['operatorPassed']===2&&$packet['operatorFailed']===1,'Operator counts changed in redacted summary');
triage_check(in_array('operator_failed_fan_audio',$packet['issueCodes'],true),'Issue code omitted from support');
$endedPacket=az_live_triage_support_summary($ended,$base,$old,['operatorPassed'=>0,'operatorFailed'=>0,'unverifiedSteps'=>7]);
triage_check($endedPacket['measurements']['phoneApproved']===null&&$endedPacket['measurements']['fanPlaying']===null,
    'Ended broadcast reused live phone / fan counts');
triage_check($endedPacket['measurements']['savedRecordings']===1.0,'Past recording evidence should remain available');
$noPacket=az_live_triage_support_summary(null,null,$unknown,[]);
triage_check($noPacket['roomState']==='not_started'&&$noPacket['measurements']['fanPlaying']===null,
    'No-session packet needs explicit unknowns');
$source=file_get_contents(__DIR__.'/../includes/live-acceptance.php');
triage_check(str_contains($source,'az_live_triage_analyze($roomStatus,$diag,$checks)')&&
    str_contains($source,'az_live_triage_support_summary($roomStatus,$diag,$triage,$completion)'),
    'The live acceptance status endpoint must return corresponding triage and redacted support summary');
print "Live v1.48: stage isolation, no false green, operator failure, stale-room safety and redacted support summary PASS\n";
