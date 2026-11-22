<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
header('Cache-Control: no-store');header('X-Robots-Tag: noindex,nofollow');
try {
 if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') throw new AzHttpError(405,'GET required.','method_not_allowed');
 if (!az_is_installed()) throw new AzHttpError(503,'Studio not installed.','not_installed');
 az_json(['ok'=>true,'data'=>['recordings'=>az_live_open_recordings()]]);
} catch(AzHttpError $e){az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);} catch(Throwable $e){az_log_exception($e);az_json(['ok'=>false,'error'=>['code'=>'server_error','message'=>'Unable to load public shows.']],500);}
