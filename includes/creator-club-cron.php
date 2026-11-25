<?php
declare(strict_types=1);
// Private CLI entry point: use a 15-minute host task by default. A faster task
// requires provider confirmation and matching creator_club.publish_interval_minutes.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/bootstrap.php';
try{
    if(!az_is_installed())throw new RuntimeException('AZHottie installation is not configured.');
    $result=az_creator_club_publish_due(az_db(),50,'cron');
    // Publication and its outbox event were committed together; delivery can be retried.
    try{$result['notifications']=az_commerce_dispatch(100);}
    catch(Throwable $e){az_log_exception($e);$result['notificationsDeferred']=true;}
    echo json_encode(['ok'=>$result['errors']===0]+$result,JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($result['errors']>0?1:(!empty($result['truncated'])?2:0));
}catch(Throwable $e){
    az_log_exception($e);fwrite(STDERR,"Creator Club publishing check failed; inspect your PHP error log.\n");exit(1);
}
