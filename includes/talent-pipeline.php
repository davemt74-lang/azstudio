<?php
declare(strict_types=1);
/** v1.54: staff follow-through for the existing human-reviewed casting and onboarding workflow. */
function az_upgrade_talent_pipeline_schema(PDO $db):void
{
    if(az_schema_version($db)>=50||az_schema_version($db)<49)return;
    az_prepare_storage();$lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Talent follow-up migration lock unavailable.');
    try {
        if(az_schema_version($db)>=50)return;
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $uid=$mysql?'BIGINT UNSIGNED':'INTEGER';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $notes=$mysql?'TEXT':'TEXT';
        $db->exec("CREATE TABLE IF NOT EXISTS casting_followups(
            id CHAR(32) PRIMARY KEY,lead_id CHAR(32) NOT NULL,created_by $uid NOT NULL,
            next_action VARCHAR(240) NOT NULL,due_at VARCHAR(32) NOT NULL,
            state VARCHAR(16) NOT NULL,revision INTEGER NOT NULL DEFAULT 1,
            resolution_note $notes NOT NULL,created_at VARCHAR(32) NOT NULL,
            updated_at VARCHAR(32) NOT NULL,completed_at VARCHAR(32) NULL,
            FOREIGN KEY(lead_id) REFERENCES casting_leads(id) ON DELETE CASCADE,
            FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS casting_followup_history(
            id CHAR(32) PRIMARY KEY,followup_id CHAR(32) NOT NULL,actor_id $uid NOT NULL,
            action VARCHAR(20) NOT NULL,created_at VARCHAR(32) NOT NULL,
            FOREIGN KEY(followup_id) REFERENCES casting_followups(id) ON DELETE CASCADE)$suffix");
        if($mysql){
            $q=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
            foreach([['casting_followups','casting_followup_due','(state,due_at)'],['casting_followups','casting_followup_lead','(lead_id,state)']] as [$t,$idx,$columns]){
                $q->execute([$t,$idx]);if(!(int)$q->fetchColumn())$db->exec("CREATE INDEX $idx ON $t $columns");
            }
        }else{
            $db->exec('CREATE INDEX IF NOT EXISTS casting_followup_due ON casting_followups(state,due_at)');
            $db->exec('CREATE INDEX IF NOT EXISTS casting_followup_lead ON casting_followups(lead_id,state)');
        }
        $db->exec('UPDATE studio_schema SET version=50 WHERE id=1');
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function az_talent_due_time(mixed $text,?int $now=null):string
{
    if(!is_string($text)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d$/D',$text))
        throw new AzHttpError(400,'Choose a valid Arizona follow-up date and time.','invalid_date');
    $zone=new DateTimeZone('America/Phoenix');$date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$text,$zone);
    if(!$date||$date->format('Y-m-d\TH:i')!==$text)throw new AzHttpError(400,'Follow-up time is invalid.','invalid_date');
    $now??=time();$ts=$date->getTimestamp();
    if($ts<$now+300||$ts>$now+365*86400)throw new AzHttpError(400,'Choose a date between 5 minutes and one year ahead.','invalid_date');
    return gmdate('Y-m-d\TH:i:s\Z',$ts);
}
/** Only the already authenticated admin Casting page may call these mutations. */
function az_talent_create_followup(array $body,int $admin):array
{
    $lead=az_casting_lead((string)($body['leadId']??''));
    $next=az_text($body['nextAction']??'',240,'Next action');
    if(mb_strlen($next,'UTF-8')<5)throw new AzHttpError(400,'Describe the next action in at least five characters.','invalid_action');
    $due=az_talent_due_time($body['dueAt']??null);$now=az_now();$id=az_id();$db=az_db();
    $db->beginTransaction();
    try {
        $q=$db->prepare("INSERT INTO casting_followups(id,lead_id,created_by,next_action,due_at,state,resolution_note,created_at,updated_at)
            VALUES(?,?,?,?,?,'open','',?,?)");$q->execute([$id,$lead['id'],$admin,$next,$due,$now,$now]);
        $q=$db->prepare('INSERT INTO casting_followup_history(id,followup_id,actor_id,action,created_at) VALUES(?,?,?,?,?)');
        $q->execute([az_id(),$id,$admin,'created',$now]);$db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    return ['id'=>$id,'state'=>'open'];
}
function az_talent_decide_followup(array $body,int $admin):array
{
    $id=az_text($body['id']??'',32,'Follow-up');if(!az_valid_id($id))throw new AzHttpError(400,'Invalid task.','invalid_id');
    $revision=az_integer($body['revision']??null,1,PHP_INT_MAX,'Revision');
    $action=az_text($body['decision']??'',20,'Decision');
    if(!in_array($action,['done','cancelled'],true))throw new AzHttpError(400,'Invalid task decision.','invalid_decision');
    $note=az_text($body['note']??'',1200,'Private resolution note');
    if($action==='done'&&mb_strlen($note,'UTF-8')<8)throw new AzHttpError(400,'Add a short completion note for the audit record.','resolution_required');
    $db=az_db();$q=$db->prepare('SELECT * FROM casting_followups WHERE id=?');$q->execute([$id]);$row=$q->fetch();
    if(!$row)throw new AzHttpError(404,'Follow-up not found.','not_found');
    if($row['state']!=='open'||(int)$row['revision']!==$revision)throw new AzHttpError(409,'Task changed. Reload before updating.','revision_conflict');
    $now=az_now();$db->beginTransaction();
    try {
        $q=$db->prepare("UPDATE casting_followups SET state=?,resolution_note=?,revision=revision+1,updated_at=?,completed_at=? WHERE id=? AND state='open' AND revision=?");
        $q->execute([$action,$note,$now,$now,$id,$revision]);
        if($q->rowCount()!==1)throw new AzHttpError(409,'Task changed. Reload.','revision_conflict');
        $q=$db->prepare('INSERT INTO casting_followup_history(id,followup_id,actor_id,action,created_at) VALUES(?,?,?,?,?)');
        $q->execute([az_id(),$id,$admin,$action,$now]);$db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    return ['id'=>$id,'state'=>$action,'revision'=>$revision+1];
}
function az_talent_followups_for_lead(string $leadId):array
{
    if(!az_valid_id($leadId))throw new AzHttpError(400,'Invalid application.','invalid_id');
    $q=az_db()->prepare('SELECT * FROM casting_followups WHERE lead_id=? ORDER BY state ASC,due_at ASC,id ASC LIMIT 60');
    $q->execute([$leadId]);return $q->fetchAll();
}
/** Admin-only pipeline snapshot. No automatic applicant ranking or sensitive traits. */
function az_talent_pipeline_dashboard():array
{
    $db=az_db();$stats=['new'=>0,'contacted'=>0,'screening'=>0,'interview'=>0,'approved'=>0,'converted'=>0,'declined'=>0];
    foreach($db->query('SELECT status,COUNT(*) n FROM casting_leads GROUP BY status')->fetchAll() as $row)if(array_key_exists($row['status'],$stats))$stats[$row['status']]=(int)$row['n'];
    $q=$db->prepare("SELECT f.id,f.lead_id,f.next_action,f.due_at,f.state,f.revision,l.applicant_name,l.stage_name,l.status AS lead_status
        FROM casting_followups f JOIN casting_leads l ON l.id=f.lead_id
        WHERE f.state='open' ORDER BY f.due_at ASC,f.id ASC LIMIT 60");$q->execute();$queue=$q->fetchAll();
    foreach($queue as &$row){$row['overdue']=$row['due_at']<az_now();}unset($row);
    $q=$db->prepare("SELECT l.id AS lead_id,l.applicant_name,l.stage_name,l.converted_user_id,u.status AS account_status,
        (SELECT COUNT(*) FROM model_videos m WHERE m.user_id=l.converted_user_id AND m.status='published' AND m.rights_confirmed=1) AS public_videos,
        (SELECT COALESCE(SUM(d.play_starts),0) FROM published_video_engagement_daily d JOIN model_videos m ON m.id=d.video_id
           WHERE d.video_kind='creator' AND m.user_id=l.converted_user_id AND m.status='published' AND m.rights_confirmed=1 AND d.view_day>=?) AS recent_starts
        FROM casting_leads l LEFT JOIN users u ON u.id=l.converted_user_id
        WHERE l.status='converted' AND l.converted_user_id IS NOT NULL
        ORDER BY l.updated_at DESC,l.id DESC LIMIT 25");
    $q->execute([gmdate('Y-m-d',time()-29*86400)]);$roster=$q->fetchAll();
    foreach($roster as &$member){$member['onboarding']=az_agent_casting_onboarding((int)$member['converted_user_id']);}unset($member);
    return ['counts'=>$stats,'tasks'=>$queue,'roster'=>$roster,
        'basis'=>'Administratively recorded application stages, owner-specified follow-ups, verified onboarding checkpoints and aggregate public Watch play starts. No automated casting decisions.'];
}
function az_talent_due_count():int
{
    $q=az_db()->prepare("SELECT COUNT(*) FROM casting_followups WHERE state='open' AND due_at<=?");
    $q->execute([az_now()]);return (int)$q->fetchColumn();
}
