<?php
declare(strict_types=1);
// Private cPanel cron entry point. Directory access is denied by includes/.htaccess.
if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}
require_once __DIR__.'/bootstrap.php';
try {
    if (!az_is_installed()) throw new RuntimeException('AZHottie installation is not configured.');
    az_db();
    $result=az_agent_followthrough_run();
    // Shared private hourly cron; failure in one specialist is reported, not silently ignored.
    $result['casting']=az_agent_casting_scan();
    $result['scheduling']=az_agent_schedule_scan();
    $result['video']=az_video_agent_scan();
    $result['publishing']=az_publishing_scan();
    $result['commerce']=az_commerce_scan();
    $result['commerceAgent']=az_commerce_agent_scan();
    $result['management']=az_admin_intelligence_scan();
    $result['localCommerce']=az_local_scan();
    $result['shipping']=az_fulfillment_scan();
    $result['returns']=az_returns_scan();
    $result['settlements']=az_payout_scan();
    // Best-effort health-data retention; never roll back assignment reminders.
    try {$result['liveDiagnosticsPruned']=az_live_agent_prune();}
    catch (Throwable $healthError){error_log('Live Agent retention failed: '.$healthError->getMessage());}
    echo json_encode(['ok'=>true]+$result,JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($result['truncated']?2:0);
} catch (Throwable $e) {
    error_log('AZHottie follow-through check failed: '.$e->getMessage());
    fwrite(STDERR,"AZHottie assignment check failed; inspect your PHP error log.\n");
    exit(1);
}
