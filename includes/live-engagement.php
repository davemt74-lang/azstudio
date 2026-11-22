<?php
declare(strict_types=1);
/** v1.19: opt-in member chat and aggregate reactions for one ACTIVE room.
 * File-backed under denied storage, not exposed by any static web route.
 * No personal names, account numbers or media references leave the API.
 */
function az_live_engagement_path(string $roomId):string {
    if(!az_valid_id($roomId))throw new AzHttpError(404,'Live room unavailable.','live_missing');
    az_prepare_storage();$dir=AZ_STORAGE.'/live-engagement';
    if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Live engagement storage unavailable.');
    @chmod($dir,0700);return $dir.'/'.$roomId.'.json';
}
function az_live_engagement_default():array{return ['chatEnabled'=>false,'messages'=>[],'reactions'=>['heart'=>0,'applause'=>0,'fire'=>0],'lastMessages'=>[],'lastReactions'=>[]];}
function az_live_engagement_mutate(string $roomId,callable $apply):array {
    $path=az_live_engagement_path($roomId);$handle=fopen($path,'c+b');
    if(!$handle||!flock($handle,LOCK_EX))throw new RuntimeException('Live interaction is busy.');
    try {
        rewind($handle);$raw=stream_get_contents($handle);$body=$raw?json_decode($raw,true):null;
        $state=is_array($body)?array_replace(az_live_engagement_default(),$body):az_live_engagement_default();
        $new=$apply($state);if(!is_array($new))throw new RuntimeException('Invalid live engagement state.');
        $json=json_encode($new,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if(strlen($json)>100000)throw new AzHttpError(413,'Chat is full.','engagement_limit');
        rewind($handle);ftruncate($handle,0);
        if(fwrite($handle,$json)===false)throw new RuntimeException('Live chat could not be saved.');
        fflush($handle);@chmod($path,0600);return $new;
    }finally{flock($handle,LOCK_UN);fclose($handle);}
}
function az_live_engagement_room(string $roomId):void {
    $room=az_live_active_room();
    if(!$room||$room['id']!==$roomId)throw new AzHttpError(409,'This broadcast is no longer active.','live_offline');
}
function az_live_engagement_public(array $state):array {
    return ['chatEnabled'=>(bool)($state['chatEnabled']??false),
        'reactions'=>array_intersect_key((array)($state['reactions']??[]),['heart'=>1,'applause'=>1,'fire'=>1]),
        'messages'=>array_values(array_slice(array_map(static fn($m)=>['id'=>$m['id'],'name'=>$m['name'],'text'=>$m['text'],'at'=>$m['at']],
            array_filter((array)($state['messages']??[]),static fn($m)=>is_array($m)&&isset($m['id'],$m['name'],$m['text'],$m['at']))),-60))];
}
function az_live_engagement_get(string $roomId):array {
    az_live_engagement_room($roomId);
    return az_live_engagement_public(az_live_engagement_mutate($roomId,static fn($s)=>$s));
}
function az_live_engagement_chat_toggle(string $roomId,bool $enabled):array {
    az_live_engagement_room($roomId);
    return az_live_engagement_public(az_live_engagement_mutate($roomId,static function($s)use($enabled){$s['chatEnabled']=$enabled;return $s;}));
}
function az_live_engagement_post(string $roomId,int $userId,string $text):array {
    az_live_engagement_room($roomId);
    $text=trim((string)preg_replace('/[\x00-\x1f\x7f]+/u',' ',$text));
    $length=count(preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[]);
    if($length<1||$length>320)throw new AzHttpError(422,'Chat messages must be 1–320 characters.','invalid_message');
    $state=az_live_engagement_mutate($roomId,static function($s)use($roomId,$userId,$text){
        if(empty($s['chatEnabled']))throw new AzHttpError(403,'Live chat is disabled by the Director.','chat_closed');
        $now=time();$last=(int)($s['lastMessages'][(string)$userId]??0);
        if($last>$now-4)throw new AzHttpError(429,'Send at most one message every four seconds.','chat_rate');
        $s['lastMessages'][(string)$userId]=$now;
        $s['lastMessages']=array_filter($s['lastMessages'],static fn($x)=>(int)$x >=$now-600);
        $s['messages'][]=['id'=>bin2hex(random_bytes(16)),'name'=>'Member '.strtoupper(substr(hash('sha256',$roomId.':'.$userId),0,4)),
             'text'=>$text,'at'=>gmdate('c')];
        $s['messages']=array_slice($s['messages'],-80);return $s;
    });
    return az_live_engagement_public($state);
}
function az_live_engagement_react(string $roomId,int $userId,string $kind):array {
    az_live_engagement_room($roomId);
    if(!in_array($kind,['heart','applause','fire'],true))throw new AzHttpError(422,'Invalid reaction.','invalid_reaction');
    $state=az_live_engagement_mutate($roomId,static function($s)use($kind,$userId){
        $now=time();$last=(int)($s['lastReactions'][(string)$userId]??0);
        if($last>$now-2)throw new AzHttpError(429,'Reactions are limited to one every two seconds.','reaction_rate');
        $s['lastReactions'][(string)$userId]=$now;
        $s['lastReactions']=array_filter($s['lastReactions'],static fn($x)=>(int)$x >=$now-600);
        $s['reactions'][$kind]=min(999999,(int)($s['reactions'][$kind]??0)+1);return $s;
    });
    return az_live_engagement_public($state);
}
function az_live_engagement_remove(string $roomId,string $messageId):array {
    az_live_engagement_room($roomId);
    if(!az_valid_id($messageId))throw new AzHttpError(422,'Invalid message.','invalid_message');
    $state=az_live_engagement_mutate($roomId,static function($s)use($messageId){
        $s['messages']=array_values(array_filter($s['messages'],static fn($m)=>($m['id']??'')!==$messageId));return $s;
    });return az_live_engagement_public($state);
}
function az_live_engagement_end(string $roomId):void {
    if(az_valid_id($roomId))@unlink(az_live_engagement_path($roomId));
}
