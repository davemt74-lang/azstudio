<?php
declare(strict_types=1);
/**
 * AZHottie Agent follow-through. Deterministic, account-scoped in-app reminders.
 * The only scheduled entry point is the CLI cron script. Brain reads remain read-only.
 * Neither cron nor this service changes an assignment status or publishes footage.
 */
function az_upgrade_agent_followthrough_schema(PDO $db): void
{
    if (az_schema_version($db) >= 30 || az_schema_version($db) < 29) return;
    az_prepare_storage();
    $lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Cannot lock follow-through migration.');
    try {
        if (az_schema_version($db) >= 30) return;
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $uid=$mysql?'BIGINT UNSIGNED':'INTEGER';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS agent_followthrough_deliveries (
          id CHAR(32) PRIMARY KEY, assignment_id CHAR(32) NOT NULL, recipient_id $uid NOT NULL,
          rule VARCHAR(40) NOT NULL, fingerprint CHAR(64) NOT NULL, created_at VARCHAR(32) NOT NULL,
          UNIQUE (assignment_id,recipient_id,rule,fingerprint),
          FOREIGN KEY (assignment_id) REFERENCES shot_assignments(id) ON DELETE CASCADE,
          FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS agent_followthrough_runs (
          scope VARCHAR(80) PRIMARY KEY, completed_at VARCHAR(32) NOT NULL,
          checked_count INTEGER NOT NULL, delivered_count INTEGER NOT NULL, truncated INTEGER NOT NULL)$suffix");
        $db->exec('UPDATE studio_schema SET version=30 WHERE id=1');
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}

/** Pure rules for a verified active project/shot/roster/assignment row. */
function az_agent_followthrough_rules(array $row,int $now): array
{
    $rules=[];
    $status=(string)($row['status']??'');
    $updated=strtotime((string)($row['updated_at']??''));
    $due=strtotime((string)($row['due_at']??''));
    $submitted=strtotime((string)($row['submitted_at']??''));
    $model=(int)($row['user_id']??0);$owner=(int)($row['owner_id']??0);
    $shot=mb_safe_trim((string)($row['title']??'Assignment'),150);
    $project=mb_safe_trim((string)($row['project_title']??'Production'),120);
    $member=mb_safe_trim((string)($row['model_name']??'Contributor'),100);
    $toModel='my-work.php?assignmentId='.rawurlencode((string)$row['id']);
    $toAdmin='productions.php?assignmentId='.rawurlencode((string)$row['id']);
    if ($status==='assigned' && $updated!==false && $updated<=$now-86400) {
        $rules[]=['rule'=>'unanswered_24h','recipient'=>$model,'title'=>'Assignment needs your response','detail'=>$shot.' · '.$project.' has been awaiting your Accept/Decline decision for at least 24 hours.','href'=>$toModel];
        if ($updated<=$now-2*86400) $rules[]=['rule'=>'unanswered_48h','recipient'=>$owner,'title'=>'Unanswered assignment: '.$shot,'detail'=>$member.' has not responded for at least 48 hours. Review the assignment before contacting them.','href'=>$toAdmin];
    }
    if (in_array($status,['accepted','changes_requested'],true) && $due!==false) {
        if ($due>$now && $due<=$now+3*86400) {
            $rules[]=['rule'=>'due_72h','recipient'=>$model,'title'=>'Assignment deadline approaching','detail'=>$shot.' · '.$project.' is due within 72 hours. Review the brief and submit your work.','href'=>$toModel];
        } elseif ($due<=$now) {
            $rules[]=['rule'=>'overdue_model','recipient'=>$model,'title'=>'Assignment overdue: '.$shot,'detail'=>$project.' deadline has passed. Open the assignment to update or submit your work.','href'=>$toModel];
            $rules[]=['rule'=>'overdue_admin','recipient'=>$owner,'title'=>'Overdue contributor work: '.$shot,'detail'=>$member.' has an overdue assignment in '.$project.'. Review the current status and follow up.','href'=>$toAdmin];
        }
    }
    if ($status==='submitted' && $submitted!==false && $submitted<=$now-2*86400) {
        $rules[]=['rule'=>'review_48h','recipient'=>$owner,'title'=>'Submission waiting for review: '.$shot,'detail'=>$member.' submitted work over 48 hours ago. Review it or request changes.','href'=>$toAdmin];
    }
    return $rules;
}
/** A change of status, revision, or due date creates a new reminder cycle. */
function az_agent_followthrough_fingerprint(array $row,string $rule): string
{
    return hash('sha256',implode('|',[(string)$row['id'],$rule,(string)$row['status'],(string)$row['revision'],(string)($row['due_at']??''),(string)($row['submitted_at']??'')]));
}
/** Caller must hold a transaction; unique-key conflict means the notice already exists. */
function az_agent_followthrough_deliver(PDO $db,array $row,array $notice,string $fingerprint): bool
{
    $recipient=(int)$notice['recipient'];
    if ($recipient<1 || !az_valid_id((string)$row['id'])) return false;
    try {
        $q=$db->prepare('INSERT INTO agent_followthrough_deliveries(id,assignment_id,recipient_id,rule,fingerprint,created_at) VALUES(?,?,?,?,?,?)');
        $q->execute([az_id(),$row['id'],$recipient,$notice['rule'],$fingerprint,az_now()]);
    } catch (PDOException $e) {
        // Only duplicate-unique-key conflicts are harmless. Foreign-key or other DB failures are not.
        $driver=(string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $code=$e->errorInfo[1]??null;
        if (($driver==='mysql' && (int)$code===1062) || ($driver==='sqlite' && (int)$code===19 && str_contains((string)($e->errorInfo[2]??''),'UNIQUE constraint failed'))) return false;
        throw $e;
    }
    // The recipient is the FK-backed system actor placeholder. Display it as AZHottie Agent,
    // never as a user action. Explicitly unread even when actor_id equals recipient_id.
    az_agent_workspace_emit($recipient,$recipient,'followthrough_'.$notice['rule'],$notice['title'],$notice['detail'],$notice['href'],true);
    return true;
}
/** One locked pass is safe with concurrent cron/manual invocations and duplicate retries. */
function az_agent_followthrough_run(?int $ownerId=null,int $batchLimit=250,int $maxRows=10000): array
{
    if ($ownerId!==null && $ownerId<1) throw new InvalidArgumentException('Invalid owner.');
    az_prepare_storage();
    $lock=fopen(AZ_STORAGE.'/agent-followthrough.lock','c+b');
    if (!$lock) throw new RuntimeException('Cannot open the follow-through lock.');
    if (!flock($lock,LOCK_EX|LOCK_NB)) {fclose($lock);throw new AzHttpError(409,'An assignment check is already running.','check_in_progress');}
    $db=az_db();$checked=0;$delivered=0;$truncated=false;$cursor='';$now=time();
    try {
        $db->beginTransaction();
        $sql="SELECT a.id,a.user_id,a.status,a.revision,a.updated_at,a.submitted_at,
                     s.title,s.due_at,p.owner_id,p.title AS project_title,u.name AS model_name
              FROM shot_assignments a
              JOIN production_shots s ON s.id=a.shot_id AND s.status='active'
              JOIN production_projects p ON p.id=s.project_id AND p.status='active'
              JOIN production_members m ON m.project_id=p.id AND m.user_id=a.user_id AND m.status='active'
              JOIN users u ON u.id=a.user_id AND u.status='active'
              JOIN users o ON o.id=p.owner_id AND o.status='active'
              WHERE a.status IN ('assigned','accepted','changes_requested','submitted')
                AND a.id>?".($ownerId!==null?' AND p.owner_id=?':'')." ORDER BY a.id LIMIT ?";
        $q=$db->prepare($sql);
        $batchLimit=max(1,min($batchLimit,500));$maxRows=max($batchLimit,min($maxRows,50000));
        do {
            $q->bindValue(1,$cursor,PDO::PARAM_STR);
            $next=2;
            if($ownerId!==null)$q->bindValue($next++,$ownerId,PDO::PARAM_INT);
            $q->bindValue($next,$batchLimit,PDO::PARAM_INT);
            $q->execute();$rows=$q->fetchAll(PDO::FETCH_ASSOC);$n=count($rows);
            foreach($rows as $r){
                $checked++;$cursor=$r['id'];
                foreach(az_agent_followthrough_rules($r,$now) as $notice){
                    if(az_agent_followthrough_deliver($db,$r,$notice,az_agent_followthrough_fingerprint($r,$notice['rule']))) $delivered++;
                }
                if($checked>=$maxRows){$truncated=$n===$batchLimit;break;}
            }
        } while ($n===$batchLimit && !$truncated);
        $scope=$ownerId===null?'cron':'admin:'.$ownerId;
        $completed=az_now();
        $check=$db->prepare('SELECT scope FROM agent_followthrough_runs WHERE scope=?');$check->execute([$scope]);
        if ($check->fetchColumn()!==false){
            $w=$db->prepare('UPDATE agent_followthrough_runs SET completed_at=?,checked_count=?,delivered_count=?,truncated=? WHERE scope=?');
            $w->execute([$completed,$checked,$delivered,$truncated?1:0,$scope]);
        } else {
            $w=$db->prepare('INSERT INTO agent_followthrough_runs(scope,completed_at,checked_count,delivered_count,truncated) VALUES(?,?,?,?,?)');
            $w->execute([$scope,$completed,$checked,$delivered,$truncated?1:0]);
        }
        $db->commit();
        return ['checked'=>$checked,'delivered'=>$delivered,'truncated'=>$truncated,'completedAt'=>$completed,'scope'=>$scope];
    } catch (Throwable $e) {
        if ($db->inTransaction())$db->rollBack();
        throw $e;
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function az_agent_followthrough_status(?int $ownerId=null): array
{
    $q=az_db()->prepare('SELECT completed_at,checked_count,delivered_count,truncated FROM agent_followthrough_runs WHERE scope=?');
    $q->execute([$ownerId===null?'cron':'admin:'.$ownerId]);
    $r=$q->fetch();
    return $r?['completedAt'=>$r['completed_at'],'checked'=>(int)$r['checked_count'],'delivered'=>(int)$r['delivered_count'],'truncated'=>(bool)$r['truncated']]:['completedAt'=>null,'checked'=>0,'delivered'=>0,'truncated'=>false];
}
/** Close stale unread reminders after canonical assignment state transitions.
 * The events stay visible in History; they disappear from the unread badge.
 * This runs within the existing Work API transaction (no second transaction).
 */
function az_agent_followthrough_resolve_assignment(string $assignmentId): void
{
    if (!az_valid_id($assignmentId))return;
    $q=az_db()->prepare("UPDATE agent_workspace_events SET read_at=? WHERE read_at IS NULL
       AND kind LIKE 'followthrough_%' AND href IN (?,?)");
    $q->execute([az_now(),'my-work.php?assignmentId='.$assignmentId,'productions.php?assignmentId='.$assignmentId]);
}
/** Admin-confirmed nudge. Permission, revision, roster and state revalidated at send time. */
function az_agent_followthrough_remind(array $admin,array $body): array
{
    if (!az_has_role($admin,'admin')) throw new AzHttpError(403,'Admin access required.','role_required');
    $id=$body['assignmentId']??null;$revision=$body['revision']??null;
    if(!is_string($id)||!az_valid_id($id)||!is_int($revision)||$revision<1) throw new AzHttpError(400,'Invalid reminder request.','invalid_request');
    az_prepare_storage();$lock=fopen(AZ_STORAGE.'/agent-followthrough.lock','c+b');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)) throw new AzHttpError(409,'Another reminder is being processed.','check_in_progress');
    $db=az_db();
    try{
        $db->beginTransaction();
        $q=$db->prepare("SELECT a.id,a.user_id,a.status,a.revision,a.updated_at,a.submitted_at,s.title,s.due_at,p.owner_id,p.title AS project_title,u.name AS model_name
          FROM shot_assignments a JOIN production_shots s ON s.id=a.shot_id AND s.status='active'
          JOIN production_projects p ON p.id=s.project_id AND p.status='active'
          JOIN production_members m ON m.project_id=p.id AND m.user_id=a.user_id AND m.status='active'
          JOIN users u ON u.id=a.user_id AND u.status='active'
          JOIN users o ON o.id=p.owner_id AND o.status='active'
          WHERE a.id=? AND p.owner_id=?");$q->execute([$id,(int)$admin['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new AzHttpError(404,'Assignment unavailable.','not_found');
        if((int)$row['revision']!==$revision)throw new AzHttpError(409,'Assignment changed. Refresh before sending a reminder.','stale_revision');
        $due=strtotime((string)($row['due_at']??''));
        if($row['status']!=='assigned' && !(in_array($row['status'],['accepted','changes_requested'],true)&&$due!==false&&$due<=time()+3*86400))
            throw new AzHttpError(409,'This assignment no longer needs a reminder.','status_changed');
        $bucket=(string)intdiv(time(),12*3600);
        $notice=['rule'=>'manual_nudge','recipient'=>(int)$row['user_id'],'title'=>'Production reminder: '.$row['title'],
          'detail'=>'Your production team asks you to check '.$row['project_title'].' and update your assignment status.',
          'href'=>'my-work.php?assignmentId='.$row['id']];
        $fingerprint=hash('sha256',az_agent_followthrough_fingerprint($row,'manual_nudge').'|'.$bucket);
        if(!az_agent_followthrough_deliver($db,$row,$notice,$fingerprint))throw new AzHttpError(429,'A reminder was already sent for this assignment within 12 hours.','reminder_throttled');
        az_agent_workspace_emit((int)$admin['id'],(int)$admin['id'],'reminder_sent','Assignment reminder sent',$row['title'].' · '.$row['model_name'],'productions.php?assignmentId='.$row['id']);
        $db->commit();return ['sent'=>true,'assignmentId'=>$id];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    finally{flock($lock,LOCK_UN);fclose($lock);}
}
