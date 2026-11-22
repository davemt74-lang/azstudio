<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
ini_set('display_errors','0');
try {
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') {
        header('Allow: POST'); throw new AzHttpError(405,'POST required.','method_not_allowed');
    }
    if (!az_is_installed()) throw new AzHttpError(404,'Video not found.','not_found');
    az_require_csrf();
    $body=az_json_body();
    $kind=az_enum($body['kind']??'', ['creator','production'],'Video kind');
    $id=az_text($body['id']??'',32,'Video');
    $event=az_enum($body['event']??'', ['start','progress','complete'],'Playback event');
    $seconds=az_integer($body['seconds']??0,0,30,'Watched seconds');
    $tracked=az_member_watch_event($kind,$id,$event,$seconds,az_current_user());
    az_json(['ok'=>true,'tracked'=>$tracked]);
} catch (AzHttpError $e) {
    az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);
} catch(Throwable $e) {
    az_log_exception($e);
    az_json(['ok'=>false,'error'=>['code'=>'engagement_unavailable','message'=>'Playback analytics unavailable.']],503);
}
