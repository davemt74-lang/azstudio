<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/live-director-settings.php';
function require_test(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$owner=999731;
try{
  $scene=az_director_clean_scene(['transition'=>'dissolve','switchMode'=>'fixed','brand'=>true,'imageScale'=>200,'text'=>str_repeat('X',160), 'cameraId'=>'bad/../../id']);
  require_test($scene['transition']==='dissolve'&&$scene['switchMode']==='fixed'&&$scene['imageScale']===50,'Scene options not validated');
  require_test(strlen($scene['text'])===90&&$scene['cameraId']==='badid','Unsafe scene content');
  $bad=az_director_clean_scene(['switchMode'=>'inject','transition'=>'inject','imagePosition'=>'inject']);
  require_test($bad['transition']==='cut'&&$bad['switchMode']==='manual'&&$bad['imagePosition']==='top-right','Invalid scene enum accepted');
  $name='Arizona interview';$first=az_director_scene_save($owner,['name'=>$name,'settings'=>['brand'=>true,'custom'=>true,'text'=>'Hi']]);
  require_test(count($first['scenes'])===1&&az_valid_id($first['savedId']),'Saving a scene failed');
  $again=az_director_scene_save($owner,['id'=>$first['savedId'],'name'=>'Arizona interview 2','settings'=>['position'=>'upper']]);
  require_test(count($again['scenes'])===1&&$again['scenes'][0]['settings']['position']==='upper','Scene overwrite duplicated the scene');
  require_test(count(az_director_read_scenes($owner))===1,'Scene persistence broken');
  $deleted=az_director_scene_delete($owner,$first['savedId']);require_test(count($deleted['scenes'])===0,'Scene delete failed');
  try{az_director_scene_save($owner,['name'=>'Bad','settings'=>['imageId'=>str_repeat('f',32)]]);throw new RuntimeException('Nonexistent overlay accepted');}
  catch(AzHttpError $e){require_test($e->status===422,'Wrong error for missing overlay');}
  echo "6 scene-storage checks passed\n";
} finally {
  $dir=AZ_STORAGE.'/live-director/'.$owner;
  foreach(glob($dir.'/*')?:[] as $file)@unlink($file);
  @rmdir($dir);
}
