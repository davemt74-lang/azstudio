<?php
declare(strict_types=1);
require __DIR__.'/../includes/member-engagement.php';
class StubStmt extends PDOStatement {
    public array $params=[];
    public function __construct() {}
    public function execute(?array $params=null): bool { global $queries; $this->params=$params??[]; $queries[]=$this->params; return true; }
}
class StubPDO extends PDO {
    public function __construct(){}
    public function getAttribute(int $attribute): mixed {return $attribute===PDO::ATTR_DRIVER_NAME?'sqlite':null;}
    public function prepare(string $query, array $options=[]): PDOStatement|false { return new StubStmt(); }
}
$queries=[];
function az_db(): PDO {return $GLOBALS['fakeDb'];}
function az_valid_id(string $id): bool { return preg_match('/^[a-f0-9]{32}$/',$id)===1; }
function az_has_role(array $user, string $role): bool {return in_array($role,$user['roles']??[],true);}
function az_model_video_public_row(string $id): array {
    if ($id === str_repeat('f',32)) throw new Exception('unpublished must be refused');
    return ['user_id'=>11,'duration_ms'=>120000];
}
function az_public_video_row(string $id): array {return ['owner_id'=>99,'model_id'=>null,'duration_ms'=>50000];}
function confirm(bool $ok,string $message):void {if(!$ok) throw new RuntimeException($message);}
$GLOBALS['fakeDb']=new StubPDO();
$_SERVER['HTTP_USER_AGENT']='Mozilla/5.0';
$_SESSION=[];
$id=str_repeat('a',32);
$viewer=['id'=>55,'roles'=>['member']];
confirm(az_member_watch_event('creator',$id,'start',0,$viewer),'start should count');
confirm(count($queries)===1 && $queries[0][3]===1,'one play start');
confirm(az_member_watch_event('creator',$id,'start',0,$viewer),'repeat start accepted');
confirm(count($queries)===1,'same session must be deduplicated');
$_SESSION['azh_watch_engagement']['creator:'.$id]['lastAt']=time()-15;
confirm(az_member_watch_event('creator',$id,'progress',12,$viewer),'time reported');
confirm(count($queries)===2 && $queries[1][5]===12,'time should be constrained by wall clock');
confirm(az_member_watch_event('creator',$id,'progress',12,$viewer),'duplicate time request accepted');
confirm(count($queries)===2,'duplicate time cannot inflate watch time');
confirm(az_member_watch_event('creator',$id,'complete',0,$viewer),'ended should count');
confirm(count($queries)===3 && $queries[2][4]===1,'one valid completion');
confirm(az_member_watch_event('creator',$id,'complete',0,$viewer),'repeated ended accepted');
confirm(count($queries)===3,'completion deduplicated');
confirm(az_member_watch_event('creator',$id,'start',0,['id'=>11,'roles'=>['model']])===false,'self viewing excluded');
confirm(az_member_watch_event('creator',$id,'start',0,['id'=>1,'roles'=>['admin']])===false,'admin viewing excluded');
confirm(count($queries)===3,'excluded viewing never writes');
$other=str_repeat('b',32);
confirm(az_member_watch_event('creator',$other,'progress',10,$viewer)===false,'progress without start must not write');
confirm(count($queries)===3,'no phantom playback before start');
$_SESSION['azh_watch_engagement']['creator:'.$id]['startedAt']=time()-1900;
confirm(az_member_watch_event('creator',$id,'progress',10,$viewer)===false,'stale progress excluded');
confirm(az_member_watch_event('creator',$id,'start',0,$viewer),'new window counts new play');
confirm(count($queries)===4 && $queries[3][3]===1,'new 30-minute window counts again');
$_SERVER['HTTP_USER_AGENT']='Googlebot';
confirm(az_member_watch_event('creator',$other,'start',0,$viewer)===false,'bots excluded');
confirm(count($queries)===4,'bots cannot add counts');
try {az_member_watch_event('creator',str_repeat('f',32),'start',0,$viewer); throw new RuntimeException('unpublished accepted');} catch(Exception $e){confirm($e->getMessage()==='unpublished must be refused','unpublished video not accepted');}
try {az_member_watch_event('creator','not-an-id','start',0,$viewer); throw new RuntimeException('malformed accepted');} catch(Throwable $e){confirm($e->getMessage()!=='malformed accepted','malformed video not accepted');}
echo "PASS: live playback dedup, wall-time cap, completion once, self/admin/bot exclusion, public-only, expired sessions.\n";
