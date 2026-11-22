<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
ini_set('display_errors','0');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
try {
    $user=az_require_auth(true);az_personal_media_role($user);$owner=(int)$user['id'];
    $action=az_text($_GET['action']??'',40,'Action');
    $method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
    if($method==='GET'){
        if($action==='state'){
            $project=az_text($_GET['projectId']??'',32,'Edit project');
            $data=['moments'=>az_video_agent_moments($owner), 'plans'=>$project!==''?az_video_agent_plans($owner,$project):[]];
        }elseif($action==='plan'){
            $data=az_video_agent_plan_data(az_video_agent_plan_row(az_text($_GET['id']??'',32,'Plan ID'),$owner));
        }elseif($action==='briefing'){
            $data=az_video_agent_briefing($user);
        }else throw new AzHttpError(404,'Unknown video specialist action.','not_found');
        az_json(['ok'=>true,'data'=>$data]);
    }
    if($method!=='POST') {header('Allow: GET, POST');throw new AzHttpError(405,'Use GET or POST.','method_not_allowed');}
    az_require_csrf();$body=az_json_body();session_write_close();
    $lock=az_community_lock();
    try{
        $db=az_db();$db->beginTransaction();
        try{
            $data=match($action){
                'addMoment'=>az_video_agent_add_moment($body,$owner),
                'removeMoment'=>az_video_agent_remove_moment(az_text($body['id']??'',32,'Moment ID'),$owner),
                'generate'=>az_video_agent_generate($body,$owner),
                'apply'=>az_video_agent_apply($body,$owner),
                'discard'=>az_video_agent_discard($body,$owner),
                'revert'=>az_video_agent_revert($body,$owner),
                default=>throw new AzHttpError(404,'Unknown video specialist action.','not_found')
            };
            $db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }finally{flock($lock,LOCK_UN);fclose($lock);}
    az_json(['ok'=>true,'data'=>$data]);
}catch(AzHttpError $e){az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);}
catch(Throwable $e){az_log_exception($e);az_json(['ok'=>false,'error'=>['code'=>'server_error','message'=>'Video specialist could not finish. Original footage is unchanged.']],500);}
