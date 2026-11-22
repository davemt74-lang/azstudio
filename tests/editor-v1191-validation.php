<?php
declare(strict_types=1);
/** Run with: php tests/editor-v1191-validation.php */
class AzHttpError extends RuntimeException
{
    public function __construct(int $status,string $reason,string $slug='') {parent::__construct($reason,$status);}
}
function az_text(mixed $value,int $length,string $label):string
{
    if(!is_string($value)) throw new AzHttpError(400,"Invalid $label");
    if(strlen($value)>$length)throw new AzHttpError(400,"$label too long");
    return trim($value);
}
function az_integer(mixed $value,int $min,int $max,string $label):int
{
    if(!is_int($value)||$value<$min||$value>$max)throw new AzHttpError(400,"Invalid $label");
    return $value;
}
function az_valid_id(string $value):bool {return preg_match('/\A[a-f0-9]{32}\z/D',$value)===1;}
function az_personal_media_row(string $mediaId,int $owner):array
{
    if($owner!==7||$mediaId!==str_repeat('a',32))throw new AzHttpError(404,'Media not owned by current user');
    return ['duration_ms'=>8000];
}
require_once __DIR__.'/../includes/personal-edits.php';
function must(bool $condition,string $label):void {if(!$condition)throw new RuntimeException("FAILED: $label");}
function rejects(callable $operation,string $label):void
{
    try{$operation();}catch(AzHttpError){return;}
    throw new RuntimeException("FAILED: invalid $label was accepted");
}
$videoId=str_repeat('a',32);$clipId=str_repeat('b',32);$audioId=str_repeat('c',32);
$timeline=[
    'settings'=>['detachedAudio'=>[[
        'id'=>$audioId,'originClipId'=>$clipId,'mediaId'=>$videoId,'durationMs'=>8000,
        'startMs'=>4500,'trimStartMs'=>500,'trimEndMs'=>7600,'volume'=>0.4,
        'audioKeyframes'=>[['timeMs'=>0,'volume'=>0.25],['timeMs'=>4000,'volume'=>0.75]],
        'url'=>'https://untrusted.invalid/audio.mp3'
    ]]],
    'clips'=>[[
        'id'=>$clipId,'mediaId'=>$videoId,'durationMs'=>8000,
        'trimStartMs'=>1000,'trimEndMs'=>6000,
        'audioDetached'=>true,'volume'=>0.6,
        'audioKeyframes'=>[['timeMs'=>0,'volume'=>0.2],['timeMs'=>4000,'volume'=>1.0]],
        'src'=>'https://untrusted.invalid/picture.mp4'
    ]]
];
$clean=az_personal_edit_validate($timeline,7);
must($clean['clips'][0]['audioDetached']===true,'V1 audioDetached persisted');
must($clean['clips'][0]['audioKeyframes'][1]['volume']===1.0,'V1 envelope persisted');
must($clean['settings']['detachedAudio'][0]['startMs']===4500,'independent A2 timeline start persisted');
must(count($clean['settings']['detachedAudio'][0]['audioKeyframes'])===2,'A2 envelope persisted');
must(!isset($clean['clips'][0]['src'])&&!isset($clean['settings']['detachedAudio'][0]['url']),'arbitrary media URLs removed');
$invalid=$timeline;
$invalid['settings']['detachedAudio'][0]['audioKeyframes'][1]['timeMs']=0;
rejects(fn()=>az_personal_edit_validate($invalid,7),'unordered keyframes');
$invalid=$timeline;
$invalid['clips'][0]['audioKeyframes'][1]['volume']=1.2;
rejects(fn()=>az_personal_edit_validate($invalid,7),'volume beyond 100%');
$invalid=$timeline;
$invalid['settings']['detachedAudio'][0]['mediaId']=str_repeat('d',32);
rejects(fn()=>az_personal_edit_validate($invalid,7),'foreign media ID');
$invalid=$timeline;
$invalid['settings']['detachedAudio'][0]['audioKeyframes'][1]['timeMs']=9000;
rejects(fn()=>az_personal_edit_validate($invalid,7),'keyframe past audio trim');
$invalid=$timeline;
$invalid['settings']['detachedAudio'][0]['id']=$clipId;
rejects(fn()=>az_personal_edit_validate($invalid,7),'duplicate audio/video track ID');
echo "Editor v1.19.1: 10 PHP persistence & validation assertions passed\n";
