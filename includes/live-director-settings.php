<?php
declare(strict_types=1);
/** Private scene and image overlay configuration for one live Director account.
 * Stored outside the public web root, with no video or phone credentials.
 */
function az_director_path(int $owner): string {
    if ($owner < 1) throw new AzHttpError(403,'Invalid Director account.','access_denied');
    az_prepare_storage();
    $base=AZ_STORAGE.'/live-director';
    foreach ([$base,$base.'/'.$owner] as $dir) {
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir))throw new RuntimeException('Cannot initialize Director storage.');
        @chmod($dir,0700);
    }
    return $base.'/'.$owner;
}
function az_director_utf8_clip(string $value,int $max):string {
    $chars=preg_split('//u',$value,-1,PREG_SPLIT_NO_EMPTY);
    return is_array($chars)?implode('',array_slice($chars,0,$max)):'';
}
function az_director_clean_scene(array $value): array {
    $transition=(string)($value['transition']??'cut');
    $mode=(string)($value['switchMode']??'manual');
    $position=(string)($value['position']??'lower');
    $imgPos=(string)($value['imagePosition']??'top-right');
    $imageId=(string)($value['imageId']??'');
    if ($imageId!==''&&!az_valid_id($imageId)) throw new AzHttpError(422,'Invalid overlay image.','invalid_scene');
    return [
        'transition'=>in_array($transition,['cut','dissolve','fade'],true)?$transition:'cut',
        'switchMode'=>in_array($mode,['manual','fixed','random'],true)?$mode:'manual',
        'brand'=>($value['brand']??false)===true,'source'=>($value['source']??false)===true,
        'custom'=>($value['custom']??false)===true,
        'text'=>az_director_utf8_clip(trim((string)preg_replace('/[\x00-\x1f\x7f]+/u',' ',(string)($value['text']??''))),90),
        'position'=>in_array($position,['upper','lower'],true)?$position:'lower',
        'imageId'=>$imageId,'imageEnabled'=>($value['imageEnabled']??false)===true,
        'imagePosition'=>in_array($imgPos,['top-left','top-right','bottom-left','bottom-right'],true)?$imgPos:'top-right',
        'imageScale'=>max(10,min(50,(int)($value['imageScale']??24))),
        'cameraId'=>substr(preg_replace('/[^a-zA-Z0-9:_-]/','',(string)($value['cameraId']??'')),0,150),
        'cameraLabel'=>az_director_utf8_clip(trim((string)($value['cameraLabel']??'')),100),
        'layoutType'=>in_array(($value['layoutType']??''),['single','split','pip','grid'],true)?$value['layoutType']:'single',
        'layoutSources'=>array_map(static fn($x)=>is_string($x)&&preg_match('/^phone:[a-f0-9]{32}$/D',$x)?$x:'',array_slice(is_array($value['layoutSources']??null)?$value['layoutSources']:[],0,4)),
        'layoutLabels'=>array_map(static fn($x)=>az_director_utf8_clip(trim((string)$x),80),array_slice(is_array($value['layoutLabels']??null)?$value['layoutLabels']:[],0,4))
    ];
}
function az_director_scene_file(int $owner):string {return az_director_path($owner).'/scenes.json';}
function az_director_read_scenes(int $owner):array {
    $path=az_director_scene_file($owner);
    if (!is_file($path)) return [];
    $raw=file_get_contents($path);
    $decoded=$raw!==false?json_decode($raw,true):null;
    return is_array($decoded)?array_values(array_filter($decoded,static fn($v)=>is_array($v)&&isset($v['id'],$v['name'],$v['settings']))):[];
}
function az_director_scene_modify(int $owner,callable $change):array {
    $dir=az_director_path($owner);$lock=fopen($dir.'/scenes.lock','c+b');
    if (!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Scene editor is busy; try again.');
    try {
        $scenes=$change(az_director_read_scenes($owner));
        if (!is_array($scenes)||count($scenes)>12)throw new AzHttpError(422,'You can save up to 12 Director scenes.','scene_limit');
        $json=json_encode(array_values($scenes),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $tmp=tempnam($dir,'scene-');if($tmp===false)throw new RuntimeException('Scene storage is unavailable.');
        @chmod($tmp,0600);
        if (file_put_contents($tmp,$json,LOCK_EX)===false||!rename($tmp,az_director_scene_file($owner))) {
            @unlink($tmp);throw new RuntimeException('Scene could not be saved.');
        }
        return array_values($scenes);
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function az_director_scene_save(int $owner,array $body):array {
    $id=(string)($body['id']??'');if($id!==''&&!az_valid_id($id))throw new AzHttpError(422,'Invalid scene identifier.','invalid_scene');
    $name=trim((string)($body['name']??''));
    if($name===''||count(preg_split('//u',$name,-1,PREG_SPLIT_NO_EMPTY)?:[])>80)throw new AzHttpError(422,'Name this scene (up to 80 characters).','invalid_scene');
    $settings=az_director_clean_scene((array)($body['settings']??[]));
    if($settings['imageId']!==''&&!is_file(az_director_path($owner).'/overlay-'.$settings['imageId']))throw new AzHttpError(422,'The selected overlay image is missing.','invalid_overlay');
    if($id==='')$id=az_id();
    $scenes=az_director_scene_modify($owner,static function(array $scenes)use($id,$name,$settings){
        $entry=['id'=>$id,'name'=>$name,'settings'=>$settings,'updatedAt'=>az_now()];
        foreach($scenes as $index=>$scene)if($scene['id']===$id){$scenes[$index]=$entry;return $scenes;}
        $scenes[]=$entry;return $scenes;
    });
    return ['scenes'=>$scenes,'savedId'=>$id];
}
function az_director_scene_delete(int $owner,string $id):array {
    if(!az_valid_id($id))throw new AzHttpError(422,'Invalid scene identifier.','invalid_scene');
    return ['scenes'=>az_director_scene_modify($owner,static fn($scenes)=>array_values(array_filter($scenes,static fn($s)=>$s['id']!==$id)))];
}
function az_director_overlays(int $owner):array {
    $dir=az_director_path($owner);$out=[];
    foreach (glob($dir.'/overlay-'.str_repeat('?',32))?:[] as $path) {
        $id=substr(basename($path),8);if(!az_valid_id($id))continue;
        $mime=(string)(@mime_content_type($path)?:'');if(!in_array($mime,['image/png','image/jpeg','image/webp'],true))continue;
        $out[]=['id'=>$id,'name'=>'Overlay '.substr($id,0,6),'url'=>az_url('live-director-overlay.php?id='.$id),'mimeType'=>$mime];
    }
    return array_slice($out,0,24);
}
function az_director_overlay_upload(int $owner,mixed $file):array {
    if(!is_array($file)||($file['error']??null)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($file['tmp_name']??'')))
        throw new AzHttpError(422,'Choose an image file to upload.','invalid_upload');
    $bytes=(int)($file['size']??0);
    if($bytes<1||$bytes>4*1024*1024)throw new AzHttpError(413,'Overlay image must be 4 MB or smaller.','image_too_large');
    $info=@getimagesize($file['tmp_name']);
    if(!$info||!in_array($info['mime']??'',['image/png','image/jpeg','image/webp'],true)||$info[0]<1||$info[1]<1||$info[0]>4096||$info[1]>4096)
      throw new AzHttpError(422,'Use a PNG, JPEG or WebP image no larger than 4096 pixels.','invalid_image');
    if(count(az_director_overlays($owner))>=24)throw new AzHttpError(422,'Remove an old overlay before adding another.','overlay_limit');
    $id=az_id();$target=az_director_path($owner).'/overlay-'.$id;
    if(!move_uploaded_file($file['tmp_name'],$target))throw new RuntimeException('Could not store the overlay image.');
    @chmod($target,0600);
    return ['id'=>$id,'url'=>az_url('live-director-overlay.php?id='.$id),'mimeType'=>$info['mime']];
}
function az_director_overlay_delete(int $owner,string $id):array {
    if(!az_valid_id($id))throw new AzHttpError(422,'Invalid overlay image.','invalid_overlay');
    @unlink(az_director_path($owner).'/overlay-'.$id);
    return ['removed'=>true,'overlays'=>az_director_overlays($owner)];
}
