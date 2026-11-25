<?php
declare(strict_types=1);
/** All fixture mutations are CLI-only and require the marked disposable copy. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=realpath($argv[1]??'');$mode=$argv[2]??'init';
if(!$root||!is_file($root.'/.shop-hero-test-copy'))throw new RuntimeException('A marked temporary application copy is required.');
if($mode==='init')require __DIR__.'/creator-club-v159.php';
else require $root.'/includes/bootstrap.php';
$db=az_db();
if($mode==='due'){
    // Run the real CLI entry point, including delivery of its committed outbox.
    require $root.'/includes/creator-club-cron.php';exit;
}
if($mode==='test-grants-on'||$mode==='test-grants-off'){
    $config=az_config();$config['features']['creator_club_test_grants']=$mode==='test-grants-on';
    file_put_contents(AZ_CONFIG_FILE,'<?php return '.var_export($config,true).';');
    echo "Set isolated fixture test-grant flag\n";exit;
}
if($mode!=='init')throw new RuntimeException('Unknown fixture mode.');
$ids=json_decode(file_get_contents($root.'/storage/club-fixture.json'),true,512,JSON_THROW_ON_ERROR);
$now=az_now();$staff=az_account_user(1);$creator=az_account_user(2);$otherCreator=az_account_user(3);$fan=az_account_user(4);
$config=az_config();
if($config['features']['creator_club_test_grants']??false)throw new RuntimeException('Fixture must start with production test-grant default disabled.');
$db->prepare('INSERT INTO users(id,name,email,password_hash,created_at) VALUES(7,?,?,?,?)')->execute(['Muted Fan','club7@example.invalid',password_hash('fixture-only-password',PASSWORD_DEFAULT),$now]);
$db->exec("INSERT INTO user_roles(user_id,role) VALUES(7,'fan')");
$db->prepare("INSERT INTO model_profiles(id,owner_id,stage_name,public_bio,social_handles,interests,availability,private_notes,status,created_at,updated_at,linked_user_id) VALUES(?,1,'Cactus Muse','','','','','','active',?,?,3)")->execute([str_repeat('3',32),$now,$now]);
$db->exec("UPDATE user_social_profiles SET tagline='Copper cactus inspiration and desert sketches' WHERE user_id=3");
az_creator_club_follow($fan,2,true,$db);az_creator_club_follow($fan,3,true,$db);
$muted=az_account_user(7);az_creator_club_follow($muted,2,true,$db);az_creator_club_notification_preference($muted,2,false,$db);

// Private bytes let the browser exercise the actual protected GET/HEAD/range route.
$mediaBytes="AZH-V160-PRIVATE-MEDIA-0123456789abcdefghijklmnopqrstuvwxyz\n";
foreach([[2,'c'],[3,'d']] as [$owner,$letter]){
    $mediaId=str_repeat($letter,32);$storage=$mediaId.'.bin';
    file_put_contents(az_media_path(['storage_name'=>$storage]),$mediaBytes);
    $db->prepare('INSERT INTO personal_media(id,user_id,title,description,mime_type,file_name,storage_name,size_bytes,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$mediaId,$owner,'Private fixture clip','Protected range acceptance bytes','video/mp4','private-fixture.mp4',$storage,strlen($mediaBytes),$now,$now]);
    $ids[$owner===2?'media':'other_media']=$mediaId;
}
$db->prepare('UPDATE creator_club_posts SET media_id=?,media_revision=1 WHERE id IN (?,?)')->execute([$ids['media'],$ids['follower_post'],$ids['tier_post']]);
$second=az_creator_club_post($otherCreator,'',$ids['other_media'],'A cactus studio update','An update from the second followed creator.',$db,true,'followers');
az_creator_club_review($staff,'post',$second['id'],$second['revision'],'approve',$db,'Ready for the community.');$ids['other_post']=$second['id'];

// Dense histories reproduce both former truncation limits and hidden-row crowding.
$postInsert=$db->prepare("INSERT INTO creator_club_posts(id,creator_id,tier_id,title,body,rights_confirmed,state,revision,created_at,updated_at,reviewed_at,reviewed_by,audience,published_at) VALUES(?,?,?,?,?,1,'approved',2,?,?,?,1,?,?)");
$ids['archive_posts']=[];
for($i=1;$i<=64;$i++){
    $id=az_id();$date=gmdate('Y-m-d\TH:i:s\Z',1704067200+$i);$ids['archive_posts'][]=$id;
    $postInsert->execute([$id,2,null,sprintf('Archive note %02d',$i),'Older free follower update '.$i,$date,$date,$date,'followers',$date]);
}
$lockedTier=az_creator_club_tier($creator,'Separate exact tier','Never included in another tier grant.',2400,$db);az_creator_club_review($staff,'tier',$lockedTier['id'],1,'approve',$db);
$ids['other_tier']=$lockedTier['id'];
for($i=1;$i<=72;$i++){
    $date='2025-01-01T00:00:00Z';
    $postInsert->execute([az_id(),2,$lockedTier['id'],'SECRET_CROWDED_TITLE_'.$i,'SECRET_CROWDED_BODY_'.$i,$date,$date,$date,'tier',$date]);
}
$password=password_hash('fixture-only-password',PASSWORD_DEFAULT);
$user=$db->prepare('INSERT INTO users(id,name,email,password_hash,created_at) VALUES(?,?,?,?,?)');
$role=$db->prepare("INSERT INTO user_roles(user_id,role) VALUES(?,'model')");
$social=$db->prepare("INSERT INTO user_social_profiles(user_id,tagline,bio,specialties,visibility,created_at,updated_at) VALUES(?,?,'Pagination fixture','','members',?,?)");
$ids['discovery_creators']=[2,3];
for($i=20;$i<105;$i++){
    $user->execute([$i,'Archive Creator '.$i,'archive'.$i.'@example.invalid',$password,$now]);$role->execute([$i]);$social->execute([$i,'Night sky journal '.$i,$now,$now]);$ids['discovery_creators'][]=$i;
}
foreach([[105,'Hidden Creator','private'],[106,'Disabled Creator','members']] as [$id,$name,$visibility]){
    $user->execute([$id,$name,'hidden'.$id.'@example.invalid',$password,$now]);$role->execute([$id]);$social->execute([$id,'Private discovery marker',$now,$now]);
    $db->prepare('UPDATE user_social_profiles SET visibility=? WHERE user_id=?')->execute([$visibility,$id]);
}
$db->exec("UPDATE users SET status='disabled' WHERE id=106");

file_put_contents($root.'/_club-fixture-login.php', <<<'LOGIN'
<?php
if(!is_file(__DIR__.'/.shop-hero-test-copy')){http_response_code(404);exit;}
require __DIR__.'/includes/bootstrap.php';
$allowed=['admin'=>1,'model'=>2,'other_model'=>3,'fan'=>4,'other_fan'=>5,'editor'=>6,'muted_fan'=>7];
$id=$allowed[$_GET['role']??'']??0;
if(!$id){http_response_code(400);exit;}
az_login_user(az_account_user($id));http_response_code(204);
LOGIN);
file_put_contents($root.'/storage/club-fixture-v160.json',json_encode($ids,JSON_THROW_ON_ERROR));
echo "Initialized Creator Club v1.60 actual PHP fixture\n";
