<?php
declare(strict_types=1);
const AZ_STORAGE=__DIR__.'/.talent-v154-tmp';@mkdir(AZ_STORAGE,0700,true);
final class AzHttpError extends RuntimeException {
    public function __construct(public readonly int $status,string $message,public readonly string $errorCode='request_failed'){parent::__construct($message);}
}
function az_db():PDO{return $GLOBALS['db'];}
function az_schema_version(PDO $db):int{return (int)$db->query('SELECT version FROM studio_schema WHERE id=1')->fetchColumn();}
function az_prepare_storage():void{@mkdir(AZ_STORAGE,0700,true);}
function az_text(mixed $text,int $max,string $label):string{if(!is_string($text)||mb_strlen($text,'UTF-8')>$max)throw new AzHttpError(400,'Invalid '.$label);return trim($text);}
function az_integer(mixed $value,int $min,int $max,string $label):int{if(!is_int($value)&&(!is_string($value)||!ctype_digit($value)))throw new AzHttpError(400,'Invalid '.$label);$v=(int)$value;if($v<$min||$v>$max)throw new AzHttpError(400,'Invalid '.$label);return $v;}
function az_valid_id(string $id):bool{return (bool)preg_match('/^[0-9a-f]{32}$/D',$id);}
function az_id():string{return bin2hex(random_bytes(16));}
function az_now():string{return gmdate('Y-m-d\TH:i:s\Z');}
function az_casting_lead(string $id):array{$q=az_db()->prepare('SELECT * FROM casting_leads WHERE id=?');$q->execute([$id]);$r=$q->fetch();if(!$r)throw new AzHttpError(404,'Lead missing');return $r;}
function az_agent_casting_onboarding(int $id):?array{return ['modelId'=>$id,'complete'=>3,'total'=>4,'ready'=>false];}
require_once __DIR__.'/../includes/talent-pipeline.php';
function check(bool $value,string $label):void{if(!$value)throw new RuntimeException('v1.54 FAIL: '.$label);}
$db=new PDO('sqlite::memory:');$GLOBALS['db']=$db;$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');
foreach([
 'CREATE TABLE studio_schema(id INTEGER PRIMARY KEY,version INTEGER)',
 'CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT)',
 'CREATE TABLE casting_leads(id TEXT PRIMARY KEY,applicant_name TEXT,stage_name TEXT,status TEXT,converted_user_id INTEGER,updated_at TEXT)',
 'CREATE TABLE model_videos(id TEXT PRIMARY KEY,user_id INTEGER,status TEXT,rights_confirmed INTEGER)',
 'CREATE TABLE published_video_engagement_daily(video_kind TEXT,video_id TEXT,view_day TEXT,play_starts INTEGER)'
] as $sql)$db->exec($sql);
$db->exec('INSERT INTO studio_schema VALUES(1,49)');$db->exec("INSERT INTO users VALUES(7,'active'),(9,'active')");
$lead=str_repeat('a',32);$converted=str_repeat('b',32);
$db->prepare('INSERT INTO casting_leads VALUES(?,?,?,?,?,?)')->execute([$lead,'Prospect','Stage One','screening',null,az_now()]);
$db->prepare('INSERT INTO casting_leads VALUES(?,?,?,?,?,?)')->execute([$converted,'Converted Creator','Stage Two','converted',9,az_now()]);
$db->prepare('INSERT INTO model_videos VALUES(?,?,?,?)')->execute([str_repeat('c',32),9,'published',1]);
$db->prepare('INSERT INTO published_video_engagement_daily VALUES(?,?,?,?)')->execute(['creator',str_repeat('c',32),gmdate('Y-m-d'),5]);
az_upgrade_talent_pipeline_schema($db);check(az_schema_version($db)===50,'schema 49 -> 50');
az_upgrade_talent_pipeline_schema($db);check(az_schema_version($db)===50,'migration idempotent');
$now=strtotime('2026-11-24T12:00:00Z');check(az_talent_due_time('2026-11-25T10:00',$now)==='2026-11-25T17:00:00Z','Phoenix local UTC conversion');
foreach(['2026-02-30T10:00','2026-11-24T02:00','2026-11-24 10:00','<script>'] as $invalid){$bad=false;try{az_talent_due_time($invalid,$now);}catch(AzHttpError){$bad=true;}check($bad,'invalid date blocked');}
$future=(new DateTimeImmutable('+2 days',new DateTimeZone('America/Phoenix')))->format('Y-m-d\TH:i');
$new=az_talent_create_followup(['leadId'=>$lead,'nextAction'=>'Confirm availability for studio interview','dueAt'=>$future],7);
check($new['state']==='open','staff follow-up created');
$tasks=az_talent_followups_for_lead($lead);check(count($tasks)===1&&$tasks[0]['next_action']==='Confirm availability for studio interview','lead scoped task list');
$view=az_talent_pipeline_dashboard();check($view['counts']['screening']===1&&$view['counts']['converted']===1,'current stage counts');
check(count($view['roster'])===1&&$view['roster'][0]['public_videos']==1&&$view['roster'][0]['recent_starts']==5,'converted creator public performance');
check($view['roster'][0]['onboarding']['complete']===3,'existing onboarding reused');
$bad=false;try{az_talent_decide_followup(['id'=>$new['id'],'revision'=>1,'decision'=>'done','note'=>''],7);}catch(AzHttpError $e){$bad=$e->errorCode==='resolution_required';}check($bad,'requires completion evidence');
$bad=false;try{az_talent_decide_followup(['id'=>$new['id'],'revision'=>4,'decision'=>'done','note'=>'Spoke with applicant'],7);}catch(AzHttpError $e){$bad=$e->status===409;}check($bad,'stale revision rejected');
$done=az_talent_decide_followup(['id'=>$new['id'],'revision'=>1,'decision'=>'done','note'=>'Applicant confirmed an available date'],7);
check($done['state']==='done','completed with audit');
check((int)$db->query('SELECT COUNT(*) FROM casting_followup_history')->fetchColumn()===2,'created and completion recorded');
check(az_talent_due_count()===0,'completed follow-up removed from due queue');
$bad=false;try{az_talent_decide_followup(['id'=>$new['id'],'revision'=>2,'decision'=>'done','note'=>'Second action'],7);}catch(AzHttpError $e){$bad=$e->status===409;}check($bad,'no repeat completion');
$next=az_talent_create_followup(['leadId'=>$lead,'nextAction'=>'Request approved headshot','dueAt'=>$future],7);
$db->prepare('UPDATE casting_followups SET due_at=? WHERE id=?')->execute([gmdate('Y-m-d\TH:i:s\Z',time()-3600),$next['id']]);
$view=az_talent_pipeline_dashboard();check($view['tasks'][0]['overdue']===true&&az_talent_due_count()===1,'overdue task flagged');
@unlink(AZ_STORAGE.'/schema-migration.lock');@rmdir(AZ_STORAGE);
echo "v1.54 Talent pipeline migration, staff follow-up, audit, date and creator performance: PASS\n";
