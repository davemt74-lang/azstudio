<?php
declare(strict_types=1);
/** The browser suite creates and marks a temporary application copy first. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=realpath($argv[1]??'');
if(!$root||!is_file($root.'/.shop-hero-test-copy'))throw new RuntimeException('A marked temporary application copy is required.');
require $root.'/includes/bootstrap.php';
$db=az_db();$now=az_now();
foreach([[2,'Fixture Creator','model'],[3,'Other Creator','model'],[4,'Club Fan','fan'],[5,'Other Fan','fan'],[6,'Fixture Editor','editor']] as [$id,$name,$role]){
    $db->prepare('INSERT INTO users(id,name,email,password_hash,created_at) VALUES(?,?,?,?,?)')->execute([$id,$name,'club'.$id.'@example.invalid',password_hash('fixture-only-password',PASSWORD_DEFAULT),$now]);
    $db->prepare('INSERT INTO user_roles(user_id,role) VALUES(?,?)')->execute([$id,$role]);
}
foreach([2,3] as $id)$db->prepare("INSERT INTO user_social_profiles(user_id,tagline,bio,specialties,visibility,created_at,updated_at) VALUES(?,?,?,'','members',?,?)")->execute([$id,'Behind the scenes, shared with you.','Creator acceptance fixture',$now,$now]);
$staff=az_account_user(1);$creator=az_account_user(2);$fan=az_account_user(4);
$tier=az_creator_club_tier($creator,'Studio Circle','Proposed behind-the-scenes benefits.',1200,$db);
az_creator_club_review($staff,'tier',$tier['id'],1,'approve',$db,'Clear proposed benefits.');
$tierPost=az_creator_club_post($creator,$tier['id'],null,'PRIVATE TIER TITLE','PRIVATE TIER BODY',$db,true,'tier');
az_creator_club_review($staff,'post',$tierPost['id'],1,'approve',$db,'Approved private tier update.');
$followerPost=az_creator_club_post($creator,'',null,'A note for the community','Welcome to our free follower updates.',$db,true,'followers');
az_creator_club_review($staff,'post',$followerPost['id'],1,'approve',$db,'Ready for free followers.');
$grant=str_repeat('e',32);
$db->prepare('INSERT INTO creator_club_entitlements(id,fan_id,creator_id,tier_id,origin,begins_at,ends_at,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$grant,4,2,$tier['id'],'admin_test','2020-01-01T00:00:00Z','2099-01-01T00:00:00Z',1,$now]);
// Isolated test helpers never enter the source tree or deployment archive.
file_put_contents($root.'/_club-fixture-login.php', <<<'LOGIN'
<?php
if(!is_file(__DIR__.'/.shop-hero-test-copy')){http_response_code(404);exit;}
require __DIR__.'/includes/bootstrap.php';
$allowed=['admin'=>1,'model'=>2,'other_model'=>3,'fan'=>4,'other_fan'=>5,'editor'=>6];
$id=$allowed[$_GET['role']??'']??0;
if(!$id){http_response_code(400);exit;}
az_login_user(az_account_user($id));http_response_code(204);
LOGIN);
file_put_contents($root.'/storage/club-fixture.json',json_encode(['tier'=>$tier['id'],'tier_post'=>$tierPost['id'],'follower_post'=>$followerPost['id'],'grant'=>$grant],JSON_THROW_ON_ERROR));
echo "Initialized Creator Club v1.59 actual PHP fixture\n";
