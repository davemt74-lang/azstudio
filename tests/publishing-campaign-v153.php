<?php
declare(strict_types=1);
const AZ_STORAGE=__DIR__.'/.campaign-v153-tmp';
@mkdir(AZ_STORAGE,0700,true);
final class AzHttpError extends RuntimeException {
    public function __construct(public readonly int $status,string $message,public readonly string $errorCode='request_failed'){parent::__construct($message);}
}
function az_db(): PDO {return $GLOBALS['db'];}
function az_schema_version(PDO $db):int{return (int)$db->query('SELECT version FROM studio_schema WHERE id=1')->fetchColumn();}
function az_prepare_storage():void{@mkdir(AZ_STORAGE,0700,true);}
function az_text(mixed $t,int $max,string $name):string{
    if(!is_string($t)||mb_strlen($t,'UTF-8')>$max)throw new AzHttpError(400,'Invalid '.$name);return trim($t);
}
function az_integer(mixed $value,int $min,int $max,string $name):int {
    if(!is_int($value)&&(!is_string($value)||!ctype_digit($value)))throw new AzHttpError(400,'Invalid '.$name);
    $v=(int)$value;if($v<$min||$v>$max)throw new AzHttpError(400,'Invalid '.$name);return $v;
}
function az_valid_id(string $id):bool{return (bool)preg_match('/^[0-9a-f]{32}$/D',$id);}
function az_id():string{return bin2hex(random_bytes(16));}
function az_now():string{return gmdate('Y-m-d\TH:i:s\Z');}
function az_url(string $path=''):string{return '/'.ltrim($path,'/');}
function az_publishing_schedule_time(mixed $value,?int $now=null):string {
    if(!is_string($value)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d$/D',$value))throw new AzHttpError(400,'Bad time');
    $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new DateTimeZone('America/Phoenix'));
    if(!$d||$d->format('Y-m-d\TH:i')!==$value||$d->getTimestamp()<($now??time())+900)throw new AzHttpError(400,'Bad time');
    return gmdate('Y-m-d\TH:i:s\Z',$d->getTimestamp());
}
function az_model_video_public_row(string $id):array{
    $q=az_db()->prepare("SELECT * FROM model_videos WHERE id=? AND status='published' AND rights_confirmed=1");$q->execute([$id]);
    $r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)throw new AzHttpError(404,'Not public');return $r;
}
function az_model_video_public_data(array $row):array{return ['coverUrl'=>'/model-video-file.php?id='.$row['id'].'&type=cover'];}
require_once __DIR__.'/../includes/publishing-campaigns.php';
function check(bool $good,string $label):void {if(!$good)throw new RuntimeException('v1.53 FAIL '.$label);}
$db=new PDO('sqlite::memory:');$GLOBALS['db']=$db;$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
foreach([
 'CREATE TABLE studio_schema(id INTEGER PRIMARY KEY,version INTEGER NOT NULL)',
 'CREATE TABLE users(id INTEGER PRIMARY KEY)',
 'CREATE TABLE model_videos(id TEXT PRIMARY KEY,status TEXT,rights_confirmed INTEGER,revision INTEGER,title TEXT)',
 'CREATE TABLE publishing_agent_promotions(id TEXT PRIMARY KEY,video_id TEXT,channel TEXT,state TEXT,video_revision INTEGER,headline TEXT,copy_text TEXT)'
] as $sql)$db->exec($sql);
$db->exec('INSERT INTO studio_schema VALUES(1,48)');$db->exec('INSERT INTO users VALUES(7)');
$video=str_repeat('a',32);$promotion=str_repeat('b',32);
$db->prepare('INSERT INTO model_videos VALUES(?,?,?,?,?)')->execute([$video,'published',1,4,'Verified clip']);
$db->prepare('INSERT INTO publishing_agent_promotions VALUES(?,?,?,?,?,?,?)')->execute([$promotion,$video,'social','approved',4,'Story launch','Watch the story']);
az_upgrade_publishing_campaign_schema($db);check(az_schema_version($db)===49,'schema upgrade');
az_upgrade_publishing_campaign_schema($db);check(az_schema_version($db)===49,'idempotent migration');
foreach(['<script>','nonexistent','spotify'] as $bad){$failed=false;try{az_campaign_platform($bad);}catch(AzHttpError){$failed=true;}check($failed,'platform allowlist');}
$created=az_campaign_create(['promotionId'=>$promotion,'platform'=>'instagram'],7);
check($created['state']==='draft','draft only');
$all=az_campaign_list(7);check(count($all)===1&&$all[0]['ready']===true,'admin list and readiness');
check(str_starts_with($all[0]['shareUrl'],'/campaign.php?t='),'share URL is internal');
$token=substr($all[0]['shareUrl'],strlen('/campaign.php?t='));
check(az_campaign_resolve_link($token)==='watch.php','draft links disabled');
$blocked=false;try{az_campaign_decide(['id'=>$created['id'],'revision'=>2,'decision'=>'activate'],7);}catch(AzHttpError $e){$blocked=$e->status===409;}check($blocked,'stale revision blocked');
$activated=az_campaign_decide(['id'=>$created['id'],'revision'=>1,'decision'=>'activate'],7);check($activated['state']==='active','manual activation');
check(az_campaign_resolve_link($token)==='watch.php?id='.$video,'active tracked link works');
check(az_campaign_resolve_link($token)==='watch.php?id='.$video,'repeated opens still route');
check((int)$db->query('SELECT SUM(opens) FROM publishing_campaign_daily')->fetchColumn()===2,'public requests counted without persistent viewer data');
$accountTables=$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'publishing_campaign%'")->fetchAll(PDO::FETCH_COLUMN);
check(count($accountTables)===3,'only aggregate and audit campaign tables created');
$db->prepare('UPDATE model_videos SET rights_confirmed=0 WHERE id=?')->execute([$video]);
check(az_campaign_resolve_link($token)==='watch.php','consent withdrawn disables link immediately');
$db->prepare('UPDATE model_videos SET rights_confirmed=1 WHERE id=?')->execute([$video]);
$db->prepare('UPDATE publishing_agent_promotions SET state=? WHERE id=?')->execute(['retired',$promotion]);
check(az_campaign_resolve_link($token)==='watch.php','retired promotion disables link');
$db->prepare('UPDATE publishing_agent_promotions SET state=? WHERE id=?')->execute(['approved',$promotion]);
$done=az_campaign_decide(['id'=>$created['id'],'revision'=>2,'decision'=>'retire'],7);check($done['state']==='retired','retired explicitly');
check(az_campaign_resolve_link($token)==='watch.php','retired link blocked');
check((int)$db->query('SELECT COUNT(*) FROM publishing_campaign_audit')->fetchColumn()===3,'created, active and retired audited');
$future=(new DateTimeImmutable('+3 days',new DateTimeZone('America/Phoenix')))->format('Y-m-d\TH:i');
$new=az_campaign_create(['promotionId'=>$promotion,'platform'=>'tiktok','scheduleAt'=>$future],7);
$scheduled=az_campaign_decide(['id'=>$new['id'],'revision'=>1,'decision'=>'schedule'],7);check($scheduled['state']==='scheduled','handoff scheduled');
$early=false;try{az_campaign_decide(['id'=>$new['id'],'revision'=>2,'decision'=>'activate'],7);}catch(AzHttpError $e){$early=$e->errorCode==='not_due';}check($early,'early scheduled activation blocked');
$db->prepare('UPDATE publishing_campaigns SET schedule_at=? WHERE id=?')->execute([gmdate('Y-m-d\TH:i:s\Z',time()-3600),$new['id']]);
$all=az_campaign_list(7);$due=array_values(array_filter($all,static fn($item)=>$item['id']===$new['id']));check(count($due)===1&&$due[0]['handoffDue']===true,'due handoff appears');
$activated=az_campaign_decide(['id'=>$new['id'],'revision'=>2,'decision'=>'activate'],7);check($activated['state']==='active','due campaign activated manually');
@unlink(AZ_STORAGE.'/schema-migration.lock');@rmdir(AZ_STORAGE);
echo "v1.53 campaign migration, privacy, lifecycle and scheduling PASS\n";
