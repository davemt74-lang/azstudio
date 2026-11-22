<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
$checks=0;$ok=static function(bool $a,string $title)use(&$checks):void{$checks++;if(!$a)throw new RuntimeException('Publishing rules: '.$title);};
$now=strtotime('2026-11-22T12:00:00Z');
$at=az_publishing_schedule_time('2026-11-22T09:00',$now);
$ok($at==='2026-11-22T16:00:00Z','Phoenix time converted to UTC, no DST');
$ok(az_publishing_schedule_time('2026-11-23T09:00',$now)==='2026-11-23T16:00:00Z','future date converted');
foreach(['2026-02-30T09:00','2026-11-22 09:00','2026-11-22T25:00','2026-11-22T09:00Z','2026-11-22T05:05','2027-11-22T09:00','<script>'] as $input){$bad=false;try{az_publishing_schedule_time($input,$now);}catch(AzHttpError $e){$bad=$e->errorCode==='invalid_schedule';}$ok($bad,'invalid or out-of-window schedule blocked');}
$copy=az_publishing_metadata_copy('  An AZHottie Evening  ','  Meet the crew.  ');
$ok($copy['headline']==='An AZHottie Evening','metadata headline exact');
$ok($copy['copy']==='Meet the crew.','metadata copy exact');
$ok(az_publishing_metadata_copy('Welcome','')['copy']==='Watch Welcome on AZHottie.','no invented plot facts');
$ok(!str_contains(az_publishing_metadata_copy("A\nB",'')['headline'],"\n"),'control characters excluded');
$empty=az_publishing_timing_observation([['day'=>'2026-11-21','starts'=>5]]);
$ok($empty['quality']==='insufficient','no prediction when data sparse');
$strong=az_publishing_timing_observation([['day'=>'2026-11-19','starts'=>4],['day'=>'2026-11-20','starts'=>2],['day'=>'2026-11-26','starts'=>20]]);
$ok($strong['quality']==='observed' && $strong['day']==='Thursday','observed playback only, not predicted');
$ok(str_contains($strong['message'],'not a prediction'),'limitations explicit');
$ok(az_publishing_timing_observation([])['quality']==='insufficient','empty data handled');
echo "Publishing specialist rule checks: $checks passed\n";
