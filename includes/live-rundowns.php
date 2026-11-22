<?php
declare(strict_types=1);
/** v1.19 Director-owned production rundowns: file-backed, locked, idempotent.
 * No extra MySQL schema dependency; private source access is always rechecked.
 */
require_once __DIR__.'/live-director-settings.php';
function az_live_rundown_path(int $owner):string {return az_director_path($owner).'/rundowns.json';}
function az_live_rundown_read(int $owner):array {
    $path=az_live_rundown_path($owner);
    if(!is_file($path))return [];
    $decoded=json_decode((string)file_get_contents($path),true);
    return is_array($decoded)?array_values(array_filter($decoded, static fn($x)=>is_array($x)&&isset($x['id'],$x['name'],$x['segments'],$x['revision']))):[];
}
function az_live_rundown_write(int $owner,callable $change):array {
    $dir=az_director_path($owner);$lock=fopen($dir.'/rundowns.lock','c+b');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Rundown storage is busy.');
    try {
        $list=$change(az_live_rundown_read($owner));
        if(!is_array($list)||count($list)>12)throw new AzHttpError(422,'You can save up to 12 show rundowns.','rundown_limit');
        $json=json_encode(array_values($list),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $tmp=tempnam($dir,'run-');if($tmp===false)throw new RuntimeException('Rundown storage unavailable.');
        @chmod($tmp,0600);
        if(file_put_contents($tmp,$json,LOCK_EX)===false||!rename($tmp,az_live_rundown_path($owner))){@unlink($tmp);throw new RuntimeException('Rundown save failed.');}
        return array_values($list);
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function az_live_rundown_clean(int $owner,array $entry):array {
    $name=az_director_utf8_clip(trim((string)($entry['name']??'')),100);
    if($name==='')throw new AzHttpError(422,'Enter a show rundown name.','name_required');
    $segments=$entry['segments']??null;
    if(!is_array($segments)||count($segments)<1||count($segments)>30)throw new AzHttpError(422,'Include 1–30 segments.','invalid_segments');
    $out=[];$seen=[];
    foreach($segments as $index=>$segment){
        if(!is_array($segment))throw new AzHttpError(422,'Invalid rundown segment.','invalid_segment');
        $kind=az_enum($segment['kind']??'', ['camera','media','slate'],'Segment type');
        $id=(string)($segment['id']??'');if($id===''||!az_valid_id($id)||isset($seen[$id]))throw new AzHttpError(422,'Invalid or duplicate segment ID.','invalid_segment');
        $seen[$id]=true;
        $title=az_director_utf8_clip(trim((string)($segment['title']??'')),100);
        if($title==='')throw new AzHttpError(422,'Every segment needs a title.','segment_title');
        $layout=(string)($segment['layout']??'single');if(!in_array($layout,['single','split','pip','grid'],true))$layout='single';
        $slots=$segment['slots']??[];if(!is_array($slots))$slots=[];
        $count=['single'=>1,'split'=>2,'pip'=>2,'grid'=>4][$layout];
        $cleanSlots=[];$labels=[];
        for($i=0;$i<$count;$i++){
            $raw=(string)($slots[$i]??'');
            $cleanSlots[]=preg_match('/^phone:[a-f0-9]{32}$/D',$raw)?$raw:'';
            $labels[]=az_director_utf8_clip(trim((string)($segment['slotLabels'][$i]??'')),80);
        }
        $mediaId=(string)($segment['mediaId']??'');
        if($kind==='media'){
            if(!az_valid_id($mediaId))throw new AzHttpError(422,'Select media from your private gallery.','invalid_media');
            $media=az_personal_media_row($mediaId,$owner);
            if(!str_starts_with((string)$media['mime_type'],'video/'))throw new AzHttpError(422,'Only videos can go on Program.','invalid_media');
        }else{$mediaId='';}
        $settings=az_director_clean_scene(is_array($segment['settings']??null)?$segment['settings']:[]);
        if($settings['imageId']!==''&&!is_file(az_director_path($owner).'/overlay-'.$settings['imageId']))
            throw new AzHttpError(422,'A scene overlay is no longer available.','invalid_overlay');
        $transition=(string)($segment['transition']??'cut');
        $out[]=['id'=>$id,'title'=>$title,'kind'=>$kind,'layout'=>$layout,'slots'=>$cleanSlots,
            'slotLabels'=>$labels,'mediaId'=>$mediaId,'sceneId'=>(az_valid_id((string)($segment['sceneId']??''))?(string)$segment['sceneId']:''),
            'settings'=>$settings,'transition'=>in_array($transition,['cut','dissolve','fade'],true)?$transition:'cut',
            'overlayText'=>az_director_utf8_clip(trim((string)($segment['overlayText']??'')),90)];
    }
    return ['name'=>$name,'segments'=>$out];
}
function az_live_rundown_save(int $owner,array $body):array {
    $id=(string)($body['id']??'');if($id!==''&&!az_valid_id($id))throw new AzHttpError(422,'Invalid rundown ID.','invalid_rundown');
    $revision=(int)($body['revision']??0);
    $clean=az_live_rundown_clean($owner,$body);
    if($id===''){$id=az_id();$revision=0;}
    $list=az_live_rundown_write($owner,static function($list)use($id,$revision,$clean){
        foreach($list as $i=>$entry){if($entry['id']!==$id)continue;
            if((int)$entry['revision']!==$revision)throw new AzHttpError(409,'This rundown changed. Reload before saving.','revision_conflict');
            $list[$i]=['id'=>$id,'name'=>$clean['name'],'segments'=>$clean['segments'],'revision'=>$revision+1,'updatedAt'=>az_now()];return $list;
        }
        if($revision!==0)throw new AzHttpError(409,'The rundown was deleted or changed.','revision_conflict');
        $list[]=['id'=>$id,'name'=>$clean['name'],'segments'=>$clean['segments'],'revision'=>1,'updatedAt'=>az_now()];return $list;
    });
    return ['rundowns'=>$list,'savedId'=>$id];
}
function az_live_rundown_delete(int $owner,string $id,int $revision):array {
    if(!az_valid_id($id))throw new AzHttpError(422,'Invalid rundown ID.','invalid_rundown');
    return ['rundowns'=>az_live_rundown_write($owner,static function($list)use($id,$revision){
        foreach($list as $row)if($row['id']===$id && (int)$row['revision']!==$revision)
            throw new AzHttpError(409,'This rundown changed. Reload before deleting.','revision_conflict');
        return array_values(array_filter($list,static fn($row)=>$row['id']!==$id));
    })];
}
