<?php
declare(strict_types=1);
/** No database driver required: private storage / validation / auth invariants. */
const AZ_STORAGE = '/tmp/azhottie-v119-test-runtime';
class AzHttpError extends RuntimeException {
    public function __construct(public int $status,string $message,public string $errorCode='error'){parent::__construct($message);}
}
function az_prepare_storage():void { if(!is_dir(AZ_STORAGE))mkdir(AZ_STORAGE,0700,true); }
function az_valid_id(string $id):bool {return (bool)preg_match('/^[a-f0-9]{32}$/D',$id);}
function az_enum(mixed $value,array $options,string $label):string {if(!in_array($value,$options,true))throw new AzHttpError(422,'Invalid '.$label);return (string)$value;}
function az_id():string {return bin2hex(random_bytes(16));}
function az_now():string{return gmdate('c');}
function az_personal_media_row(string $id,int $owner):array {
    if($owner!==123||!az_valid_id($id)||$id===str_repeat('f',32))throw new AzHttpError(404,'Media not found.');
    return ['mime_type'=>$id===str_repeat('b',32)?'image/png':'video/mp4'];
}
$GLOBALS['fakeRoom']='';
function az_live_active_room():?array {return $GLOBALS['fakeRoom']?['id'=>$GLOBALS['fakeRoom']]:null;}
require_once __DIR__.'/../includes/live-rundowns.php';
require_once __DIR__.'/../includes/live-engagement.php';
function ok(bool $condition,string $message):void{if(!$condition)throw new RuntimeException('FAIL: '.$message);$GLOBALS['checks']=($GLOBALS['checks']??0)+1;}
function err(callable $call,int $code):void{try{$call();}catch(AzHttpError $e){ok($e->status===$code,'Expected HTTP '.$code.' got '.$e->status);return;}throw new RuntimeException('Expected HTTP '.$code);}
function cleanDir(string $dir):void{
    if(!is_dir($dir))return;foreach(scandir($dir)?:[] as $name){if($name==='.'||$name==='..')continue;$path=$dir.'/'.$name;
    if(is_dir($path))cleanDir($path);else unlink($path);}rmdir($dir);
}
cleanDir(AZ_STORAGE);az_prepare_storage();
try{
    $id=az_id();$media=str_repeat('a',32);$room=az_id();$GLOBALS['fakeRoom']=$room;
    $segment=['id'=>$id,'title'=>'Opening','kind'=>'camera','layout'=>'split','slots'=>['phone:'.str_repeat('a',32),'phone:'.str_repeat('c',32)],'slotLabels'=>['Model A','Model C'],
      'transition'=>'dissolve','overlayText'=>'Live from AZ','settings'=>['layoutType'=>'split','brand'=>true,'text'=>'AZHottie']];
    $saved=az_live_rundown_save(123,['name'=>'First Show','segments'=>[$segment]]);
    $first=$saved['rundowns'][0];ok($first['revision']===1,'initial revision');ok($first['segments'][0]['layout']==='split','camera layout');
    ok($first['segments'][0]['settings']['brand']===true,'scene snapshot');
    ok(az_live_rundown_read(123)[0]['id']===$saved['savedId'],'durable save');
    ok(!az_live_rundown_read(456),'owner isolation');
    $updated=az_live_rundown_save(123,['id'=>$saved['savedId'],'revision'=>1,'name'=>'Revised Show','segments'=>[$segment]]);
    ok(count($updated['rundowns'])===1,'update cannot duplicate');ok($updated['rundowns'][0]['revision']===2,'revision increments');
    err(fn()=>az_live_rundown_save(123,['id'=>$saved['savedId'],'revision'=>1,'name'=>'Stale','segments'=>[$segment]]),409);
    err(fn()=>az_live_rundown_save(123,['name'=>'Blank','segments'=>[]]),422);
    $bad=$segment;$bad['kind']='media';$bad['mediaId']=str_repeat('b',32);
    err(fn()=>az_live_rundown_save(123,['name'=>'Bad media','segments'=>[$bad]]),422);
    $bad['mediaId']=str_repeat('f',32);
    err(fn()=>az_live_rundown_save(123,['name'=>'Another owner media','segments'=>[$bad]]),404);
    $bad['mediaId']=$media;
    $withVideo=az_live_rundown_save(123,['name'=>'Video Show','segments'=>[$bad]]);
    ok($withVideo['rundowns'][1]['segments'][0]['mediaId']===$media,'owner media linked');
    $bad['id']=$id;$list=[$segment,$bad];err(fn()=>az_live_rundown_save(123,['name'=>'Duplicate segment','segments'=>$list]),422);
    $bad=$segment;$bad['settings']['imageId']=az_id();err(fn()=>az_live_rundown_save(123,['name'=>'Missing overlay','segments'=>[$bad]]),422);
    err(fn()=>az_live_rundown_delete(123,$saved['savedId'],1),409);
    $deleted=az_live_rundown_delete(123,$saved['savedId'],2);
    ok(count($deleted['rundowns'])===1,'only owned rundown deleted');
    $path=az_live_rundown_path(123);ok(is_file($path),'rundown stored outside public tree');
    ok((fileperms($path)&0777)===0600,'rundown private file permissions');
    $state=az_live_engagement_get($room);ok(!$state['chatEnabled'],'chat defaults closed');
    err(fn()=>az_live_engagement_post($room,5,'Hello'),403);
    $state=az_live_engagement_chat_toggle($room,true);ok($state['chatEnabled'],'editor explicitly enables chat');
    $state=az_live_engagement_post($room,5,'Hello fans!');ok(count($state['messages'])===1,'member message appears');
    ok(!str_contains(json_encode($state),'"userId"'),'no user ID exposed');
    ok(str_starts_with($state['messages'][0]['name'],'Member '),'pseudonym not real name');
    err(fn()=>az_live_engagement_post($room,5,'Spam immediately'),429);
    err(fn()=>az_live_engagement_post($room,6,''),422);
    $state=az_live_engagement_react($room,5,'heart');ok($state['reactions']['heart']===1,'reaction counts');
    err(fn()=>az_live_engagement_react($room,5,'fire'),429);
    err(fn()=>az_live_engagement_react($room,7,'other'),422);
    $state=az_live_engagement_remove($room,$state['messages'][0]['id']);ok(!count($state['messages']),'director moderation deletes');
    $other=az_id();err(fn()=>az_live_engagement_get($other),409);
    $engagementPath=az_live_engagement_path($room);ok((fileperms($engagementPath)&0777)===0600,'private chat file permissions');
    az_live_engagement_end($room);ok(!is_file($engagementPath),'chat removed after broadcast');
    echo 'v1.19 PHP storage / role / media / reactions: '.($GLOBALS['checks']??0).' checks passed'.PHP_EOL;
}finally{cleanDir(AZ_STORAGE);}
