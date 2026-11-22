<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
$done=0;
$ok=static function(bool $value,string $reason)use(&$done):void{++$done;if(!$value)throw new RuntimeException('FAIL: '.$reason);};
$week=['0'=>[], '1'=>[['start'=>'09:00','end'=>'12:00'],['start'=>'13:00','end'=>'17:00']]];
$valid=az_agent_schedule_validate_weekly($week);
$ok(count($valid)===7 && count($valid['1'])===2,'weekly normalization');
$ok(az_agent_schedule_window_coverage($valid,'2026-11-23T17:00:00Z','2026-11-23T18:00:00Z'),'Monday 10-11 local is available');
$ok(!az_agent_schedule_window_coverage($valid,'2026-11-23T19:30:00Z','2026-11-23T20:30:00Z'),'lunch gap is not covered');
$ok(!az_agent_schedule_window_coverage($valid,'2026-11-23T22:00:00Z','2026-11-24T01:00:00Z'),'slot outside available window');
$ok(!az_agent_schedule_window_coverage($valid,'2026-11-23T23:30:00Z','2026-11-24T00:30:00Z'),'overnight is not implicitly available');
$ok(az_agent_schedule_interval_overlap('a','c','b','d'),'cross-overlap');
$ok(!az_agent_schedule_interval_overlap('a','b','b','c'),'touching is not overlap');
$ok(!az_agent_schedule_interval_overlap('b','c','a','b'),'non-overlap');
$items=[
 ['id'=>'a','startAt'=>'2026-11-23T17:00:00Z','endAt'=>'2026-11-23T18:00:00Z','busy'=>true],
 ['id'=>'b','startAt'=>'2026-11-23T17:30:00Z','endAt'=>'2026-11-23T19:00:00Z','busy'=>true],
 ['id'=>'c','startAt'=>'2026-11-23T17:40:00Z','endAt'=>'2026-11-23T19:00:00Z','busy'=>false],
 ['id'=>'d','startAt'=>'2026-11-23T19:00:00Z','endAt'=>'2026-11-23T20:00:00Z','busy'=>true]
];
$conflicts=az_agent_schedule_collisions($items);
$ok(count($conflicts)===1 && $conflicts[0]['first']==='a' && $conflicts[0]['second']==='b','only true busy conflicts');
$ok(az_agent_schedule_reminder_fingerprint('appointment:one','2026-11-23T17:00:00Z','appointment_24h')===az_agent_schedule_reminder_fingerprint('appointment:one','2026-11-23T17:00:00Z','appointment_24h'),'replay stable');
$ok(az_agent_schedule_reminder_fingerprint('appointment:one','2026-11-23T17:00:00Z','appointment_24h')!==az_agent_schedule_reminder_fingerprint('appointment:one','2026-11-24T17:00:00Z','appointment_24h'),'reschedule resets notification cycle');
$ok(az_agent_schedule_reminder_fingerprint('appointment:one','2026-11-23T17:00:00Z','appointment_24h')!==az_agent_schedule_reminder_fingerprint('appointment:two','2026-11-23T17:00:00Z','appointment_24h'),'independent bookings');
foreach([
 ['1'=>[['start'=>'09:10','end'=>'10:00']]],
 ['1'=>[['start'=>'11:00','end'=>'09:00']]],
 ['1'=>[['start'=>'09:00','end'=>'12:00'],['start'=>'11:45','end'=>'13:00']]],
 ['10'=>[['start'=>'09:00','end'=>'13:00']]],
 ['1'=>[['start'=>'09:00','end'=>'10:00'],['start'=>'10:00','end'=>'11:00'],['start'=>'11:00','end'=>'12:00'],['start'=>'12:00','end'=>'13:00']]]
] as $invalid){$threw=false;try{az_agent_schedule_validate_weekly($invalid);}catch(AzHttpError $e){$threw=$e->status===400;}$ok($threw,'reject invalid time windows');}
$ok(!az_agent_schedule_window_coverage($valid,'2026-11-22T17:00:00Z','2026-11-22T18:00:00Z'),'unset Sunday not assumed available');
echo "Scheduling specialist rule tests: $done passed\n";
