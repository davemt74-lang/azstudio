<?php
declare(strict_types=1);
/**
 * v1.43 Admin Action Center. Owns operational follow-up tasks, NOT the underlying
 * applicant, publication, payment, or commerce transaction.
 * Source content and protected applicant/video data are never copied into tasks.
 */
function az_upgrade_admin_action_schema(PDO $db):void
{
    if(az_schema_version($db)>=46||az_schema_version($db)<45)return;
    az_prepare_storage();
    $lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Action Center migration lock unavailable.');
    try{
        if(az_schema_version($db)>=46)return;
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $uid=$mysql?'BIGINT UNSIGNED':'INTEGER';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS admin_action_tasks (
            id CHAR(32) PRIMARY KEY, source_kind VARCHAR(20) NOT NULL,source_id CHAR(32) NOT NULL,
            source_stage VARCHAR(40) NOT NULL,title VARCHAR(200) NOT NULL,detail VARCHAR(600) NOT NULL,
            href VARCHAR(240) NOT NULL,priority INTEGER NOT NULL DEFAULT 2,
            state VARCHAR(20) NOT NULL DEFAULT 'open', assignee_id $uid NOT NULL,
            due_at VARCHAR(32) NOT NULL,revision INTEGER NOT NULL DEFAULT 1,
            created_by $uid NOT NULL,updated_by $uid NOT NULL,
            created_at VARCHAR(32) NOT NULL,updated_at VARCHAR(32) NOT NULL,
            acknowledged_at VARCHAR(32) NULL,resolved_at VARCHAR(32) NULL,
            UNIQUE(source_kind,source_id),
            FOREIGN KEY(assignee_id) REFERENCES users(id),
            FOREIGN KEY(created_by) REFERENCES users(id),
            FOREIGN KEY(updated_by) REFERENCES users(id))$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS admin_action_history (
            id CHAR(32) PRIMARY KEY,task_id CHAR(32) NOT NULL,actor_id $uid NOT NULL,
            action VARCHAR(24) NOT NULL,from_state VARCHAR(20) NOT NULL,to_state VARCHAR(20) NOT NULL,
            note VARCHAR(500) NOT NULL DEFAULT '',created_at VARCHAR(32) NOT NULL,
            FOREIGN KEY(task_id) REFERENCES admin_action_tasks(id) ON DELETE CASCADE,
            FOREIGN KEY(actor_id) REFERENCES users(id))$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS admin_action_reminders (
            id CHAR(32) PRIMARY KEY,task_id CHAR(32) NOT NULL,
            recipient_id $uid NOT NULL, reminder_key VARCHAR(95) NOT NULL,created_at VARCHAR(32) NOT NULL,
            UNIQUE(task_id,recipient_id,reminder_key),
            FOREIGN KEY(task_id) REFERENCES admin_action_tasks(id) ON DELETE CASCADE,
            FOREIGN KEY(recipient_id) REFERENCES users(id))$suffix");
        if($mysql){
            $idx=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
            foreach([['admin_action_tasks','admin_action_due','state,due_at'],
                     ['admin_action_history','admin_action_audit','task_id,created_at']] as [$table,$name,$cols]){
                $idx->execute([$table,$name]);if(!(int)$idx->fetchColumn())$db->exec("CREATE INDEX $name ON $table ($cols)");
            }
        }else{
            $db->exec('CREATE INDEX IF NOT EXISTS admin_action_due ON admin_action_tasks(state,due_at)');
            $db->exec('CREATE INDEX IF NOT EXISTS admin_action_audit ON admin_action_history(task_id,created_at)');
        }
        $db->exec('UPDATE studio_schema SET version=46 WHERE id=1');
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function az_admin_action_assert(array $user):void
{
    az_admin_command_assert($user);
    if(($user['status']??'active')!=='active')throw new AzHttpError(403,'An active administrator is required.','role_required');
}
function az_admin_action_staff(PDO $db):array
{
    $q=$db->query("SELECT DISTINCT u.id,u.name FROM users u JOIN user_roles r ON r.user_id=u.id AND r.role='admin' WHERE u.status='active' ORDER BY u.name,u.id LIMIT 100");
    return array_map(static fn(array $r)=>['id'=>(int)$r['id'],'name'=>mb_safe_trim((string)$r['name'],120)],$q->fetchAll(PDO::FETCH_ASSOC));
}
function az_admin_action_staff_valid(PDO $db,int $id):bool
{
    if($id<1)return false;
    $q=$db->prepare("SELECT COUNT(*) FROM users u JOIN user_roles r ON r.user_id=u.id AND r.role='admin' WHERE u.id=? AND u.status='active'");
    $q->execute([$id]);return (int)$q->fetchColumn()>0;
}
/** Read-only task due dates use UTC; reject invalid dates and extreme ranges. */
function az_admin_action_date(string $date,int $now):string
{
    if(!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D',$date))
        throw new AzHttpError(400,'Enter a valid UTC date and time.','invalid_due_date');
    $dt=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$date,new DateTimeZone('UTC'));
    if(!$dt||$dt->format('Y-m-d\TH:i:s\Z')!==$date||$dt->getTimestamp()<$now-86400||
       $dt->getTimestamp()>$now+366*86400)
        throw new AzHttpError(400,'The due date must be within one day before today and one year ahead.','invalid_due_date');
    return $date;
}
function az_admin_action_is_duplicate(PDOException $e,PDO $db):bool
{
    $code=(int)($e->errorInfo[1]??0);
    return ($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'&&$code===1062)||
        ($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'&&$code===19&&
         str_contains((string)($e->errorInfo[2]??''),'UNIQUE constraint failed'));
}
/** Source references are server-owned management recommendations, never client descriptions. */
function az_admin_action_create(PDO $db,array $source,int $actor,int $now,string $origin):array
{
    $kind=(string)($source['sourceKind']??'');$id=(string)($source['sourceId']??'');
    if(!in_array($kind,['casting','video','queue'],true)||!az_valid_id($id))
        throw new AzHttpError(400,'Unsupported management source.','invalid_source');
    $q=$db->prepare('SELECT id FROM admin_action_tasks WHERE source_kind=? AND source_id=?');
    $q->execute([$kind,$id]);$existing=$q->fetchColumn();
    if($existing!==false)return ['id'=>(string)$existing,'created'=>false];
    $stage=mb_safe_trim((string)($source['sourceStage']??'review'),40);
    $title=mb_safe_trim((string)($source['title']??'Review workflow'),200);
    $detail=mb_safe_trim((string)($source['detail']??'Review current source status before taking any action.'),600);
    $href=az_agent_workspace_href((string)($source['href']??'admin-studio.php'));
    $priority=max(0,min(4,(int)($source['priority']??2)));
    $task=az_id();$date=gmdate('Y-m-d\TH:i:s\Z',$now);$due=gmdate('Y-m-d\TH:i:s\Z',$now+86400);
    try{
        $db->prepare("INSERT INTO admin_action_tasks
          (id,source_kind,source_id,source_stage,title,detail,href,priority,state,assignee_id,due_at,revision,
           created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,'open',?,?,1,?,?,?,?)")
          ->execute([$task,$kind,$id,$stage,$title,$detail,$href,$priority,$actor,$due,$actor,$actor,$date,$date]);
    }catch(PDOException $e){
        if(!az_admin_action_is_duplicate($e,$db))throw $e;
        $q->execute([$kind,$id]);$id2=$q->fetchColumn();
        if($id2===false)throw $e;
        return ['id'=>(string)$id2,'created'=>false];
    }
    $db->prepare("INSERT INTO admin_action_history(id,task_id,actor_id,action,from_state,to_state,note,created_at)
        VALUES(?,?,?,'created','', 'open',?,?)")->execute([az_id(),$task,$actor,
        $origin==='automatic'?'Created from existing aged workflow. Staff review required.':'Staff tracked an existing management recommendation.',$date]);
    return ['id'=>$task,'created'=>true];
}
function az_admin_action_create_candidate(PDO $db,array $c,int $actor,int $now):array
{
    return az_admin_action_create($db,[
        'sourceKind'=>$c['kind'],'sourceId'=>$c['sourceId'],'sourceStage'=>$c['stage'],
        'title'=>$c['title'],'detail'=>$c['detail'],'href'=>$c['href'],'priority'=>$c['priority']
    ],$actor,$now,'automatic');
}
/** Staff action requires a source still present in the CURRENT management briefing. */
function az_admin_action_track(array $user,array $input,?PDO $db=null,?int $now=null):array
{
    az_admin_action_assert($user);$db??=az_db();$now??=time();
    $kind=(string)($input['sourceKind']??'');$source=(string)($input['sourceId']??'');
    $stage=(string)($input['sourceStage']??'');
    if(!in_array($kind,['casting','video','queue'],true)||!az_valid_id($source)||
       !preg_match('/\A[a-z0-9_]{1,40}\z/D',$stage))
        throw new AzHttpError(400,'Invalid management recommendation.','invalid_source');
    $brief=az_admin_intelligence_briefing($user,null,$db,$now);
    $eligible=null;
    foreach($brief['actionsFollowups'] as $rec){
        if(($rec['sourceKind']??'')===$kind&&($rec['sourceId']??'')===$source&&
           ($rec['sourceStage']??'')===$stage){$eligible=$rec;break;}
    }
    if(!$eligible)throw new AzHttpError(409,'This recommendation is no longer available. Refresh the dashboard.','recommendation_changed');
    $db->beginTransaction();
    try{
        $result=az_admin_action_create($db,$eligible,(int)$user['id'],$now,'staff');
        $db->commit();
        return $result+['message'=>$result['created']?'Added to Action Center.':'This recommendation is already tracked.'];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function az_admin_action_change(array $user,array $body,?PDO $db=null,?int $now=null):array
{
    az_admin_action_assert($user);$db??=az_db();$now??=time();
    $id=(string)($body['id']??'');$revision=(int)($body['revision']??0);
    $operation=(string)($body['operation']??'');
    if(!az_valid_id($id)||$revision<1||!in_array($operation,['acknowledge','resolve','reopen','assign'],true))
        throw new AzHttpError(400,'Invalid action or task revision.','invalid_task_action');
    $db->beginTransaction();
    try{
        $q=$db->prepare('SELECT * FROM admin_action_tasks WHERE id=?');
        $q->execute([$id]);$task=$q->fetch(PDO::FETCH_ASSOC);
        if(!$task)throw new AzHttpError(404,'Action Center task not found.','not_found');
        if((int)$task['revision']!==$revision)throw new AzHttpError(409,'Task changed in another tab. Refresh.','revision_conflict');
        $before=$task['state'];$after=$before;$owner=(int)$task['assignee_id'];$due=$task['due_at'];
        $ack=$task['acknowledged_at'];$resolved=$task['resolved_at'];
        $note=trim((string)($body['note']??''));
        if(strlen($note)>500)throw new AzHttpError(400,'Keep the update note under 500 characters.','note_too_long');
        if($operation==='acknowledge'){
            if($before!=='open')throw new AzHttpError(409,'Only open tasks can be acknowledged.','invalid_transition');
            $after='acknowledged';$ack=gmdate('Y-m-d\TH:i:s\Z',$now);
            if($note==='')$note='Staff acknowledged the outstanding workflow.';
        }elseif($operation==='resolve'){
            if(!in_array($before,['open','acknowledged'],true))throw new AzHttpError(409,'Task is already closed.','invalid_transition');
            if(mb_strlen($note,'UTF-8')<10)throw new AzHttpError(400,'Describe the resolution in at least 10 characters.','resolution_note_required');
            $after='resolved';$resolved=gmdate('Y-m-d\TH:i:s\Z',$now);
        }elseif($operation==='reopen'){
            if($before!=='resolved')throw new AzHttpError(409,'Only resolved tasks can be reopened.','invalid_transition');
            if(mb_strlen($note,'UTF-8')<10)throw new AzHttpError(400,'Explain the reason for reopening this task.','reopen_note_required');
            $after='open';$resolved=null;$ack=null;$due=gmdate('Y-m-d\TH:i:s\Z',$now+86400);
        }else{
            if($before==='resolved')throw new AzHttpError(409,'Reopen a resolved task before reassigning.','invalid_transition');
            $target=(int)($body['assigneeId']??0);
            if(!az_admin_action_staff_valid($db,$target))throw new AzHttpError(403,'Choose an active administrator as the task owner.','assignee_not_allowed');
            $due=az_admin_action_date((string)($body['dueAt']??''),$now);$owner=$target;
            if($owner===(int)$task['assignee_id']&&$due===$task['due_at'])
                throw new AzHttpError(409,'There are no assignment or due-date changes.','no_changes');
            if($note==='')$note='Staff changed the task owner or due date.';
        }
        $stamp=gmdate('Y-m-d\TH:i:s\Z',$now);
        $stmt=$db->prepare('UPDATE admin_action_tasks SET state=?,assignee_id=?,due_at=?,acknowledged_at=?,resolved_at=?,revision=revision+1,updated_by=?,updated_at=? WHERE id=? AND revision=? AND state=?');
        $stmt->execute([$after,$owner,$due,$ack,$resolved,(int)$user['id'],$stamp,$id,$revision,$before]);
        if($stmt->rowCount()!==1)throw new AzHttpError(409,'Task changed in another session. Refresh.','revision_conflict');
        $db->prepare('INSERT INTO admin_action_history (id,task_id,actor_id,action,from_state,to_state,note,created_at) VALUES(?,?,?,?,?,?,?,?)')
           ->execute([az_id(),$id,(int)$user['id'],$operation,$before,$after,mb_safe_trim($note,500),$stamp]);
        if($operation==='assign'&&$owner!==(int)$user['id']){
            az_agent_workspace_emit((int)$user['id'],$owner,'admin_action_assignment','Studio task assigned',
                'A staff-owned studio follow-up is assigned to you. Review the current source and due date.','admin-studio.php',true);
        }
        $db->commit();
        return ['id'=>$id,'revision'=>$revision+1,'state'=>$after,'assigneeId'=>$owner,'dueAt'=>$due];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
/** Re-check source state before reminding; completed applicant/video workflows are never chased. */
function az_admin_action_source_active(PDO $db,array $task):bool
{
    $kind=$task['source_kind'];
    if($kind==='casting'||$kind==='video'){
        $table=$kind==='casting'?'casting_leads':'model_videos';
        $q=$db->prepare("SELECT status FROM $table WHERE id=?");$q->execute([$task['source_id']]);
        $status=$q->fetchColumn();
        if($status===false)return false;
        if($kind==='video')return $status==='pending_review';
        if(str_starts_with((string)$task['source_stage'],'new_'))return $status==='new';
        $expected=explode('_',(string)$task['source_stage'])[0];
        return $status===$expected;
    }
    if($kind!=='queue')return false;
    $sql=match($task['source_stage']){
        'provider_exception'=>"SELECT (SELECT COUNT(*) FROM commerce_provider_refunds WHERE status IN ('pending','provider_pending','needs_review'))+(SELECT COUNT(*) FROM commerce_return_labels WHERE status IN ('pending','needs_review'))+(SELECT COUNT(*) FROM commerce_connect_transfers WHERE status IN ('pending','needs_review'))",
        'manual_orders'=>"SELECT COUNT(*) FROM commerce_orders WHERE provider='manual' AND state='recorded'",
        'payout_batches'=>"SELECT COUNT(*) FROM commerce_payout_batches WHERE state IN ('draft','approved')",
        'video_queue'=>"SELECT COUNT(*) FROM model_videos WHERE status='pending_review'",
        'casting_queue'=>"SELECT COUNT(*) FROM casting_leads WHERE status='new'",
        default=>null
    };
    if(!$sql)return false;
    return (int)$db->query($sql)->fetchColumn()>0;
}
function az_admin_action_snapshot(array $user,?PDO $db=null):array
{
    az_admin_action_assert($user);$db??=az_db();
    $q=$db->query("SELECT t.*,u.name AS assignee_name FROM admin_action_tasks t
        LEFT JOIN users u ON u.id=t.assignee_id
        ORDER BY CASE t.state WHEN 'open' THEN 0 WHEN 'acknowledged' THEN 1 ELSE 2 END,
         t.priority ASC,t.due_at ASC,t.updated_at DESC LIMIT 100");
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);$tasks=[];$ids=[];
    foreach($rows as $r){
        $ids[]=$r['id'];
        $tasks[]=['id'=>$r['id'],'sourceKind'=>$r['source_kind'],'sourceStage'=>$r['source_stage'],
          'title'=>$r['title'],'detail'=>$r['detail'],'href'=>az_agent_workspace_href($r['href']),
          'priority'=>(int)$r['priority'],'state'=>$r['state'],'assigneeId'=>(int)$r['assignee_id'],
          'assigneeName'=>$r['assignee_name']?:'Inactive owner','dueAt'=>$r['due_at'],
          'revision'=>(int)$r['revision'],'updatedAt'=>$r['updated_at'],'resolvedAt'=>$r['resolved_at']];
    }
    $history=[];if($ids){
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $q=$db->prepare("SELECT h.task_id,h.action,h.from_state,h.to_state,h.note,h.created_at,u.name AS actor_name
            FROM admin_action_history h LEFT JOIN users u ON u.id=h.actor_id WHERE h.task_id IN ($marks)
            ORDER BY h.created_at DESC,h.id DESC LIMIT 350");
        $q->execute($ids);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $h){
            $id=$h['task_id'];if(!isset($history[$id]))$history[$id]=[];
            if(count($history[$id])>=8)continue;
            $history[$id][]=['action'=>$h['action'],'from'=>$h['from_state'],'to'=>$h['to_state'],
              'note'=>$h['note'],'actor'=>$h['actor_name']?:'Staff','createdAt'=>$h['created_at']];
        }
    }
    $counts=['open'=>0,'acknowledged'=>0,'resolved'=>0,'overdue'=>0];
    $stat=$db->query('SELECT state,COUNT(*) AS n FROM admin_action_tasks GROUP BY state');
    foreach($stat->fetchAll(PDO::FETCH_ASSOC) as $r)if(isset($counts[$r['state']]))$counts[$r['state']]=(int)$r['n'];
    $due=$db->prepare("SELECT COUNT(*) FROM admin_action_tasks WHERE state IN ('open','acknowledged') AND due_at<?");
    $due->execute([az_now()]);$counts['overdue']=(int)$due->fetchColumn();
    return ['tasks'=>$tasks,'history'=>$history,'counts'=>$counts,'staff'=>az_admin_action_staff($db),
        'truncated'=>array_sum(array_intersect_key($counts,array_flip(['open','acknowledged','resolved'])))>count($tasks),
        'basis'=>'Staff-owned follow-up tasks, not decisions on applicants, video quality, provider payments or bank transfers. Source status must be reviewed separately.'];
}
function az_admin_action_chat_summary(array $user,?PDO $db=null):array
{
    $s=az_admin_action_snapshot($user,$db);
    return ['counts'=>$s['counts'],'topTasks'=>array_map(static fn($t)=>[
        'title'=>$t['title'],'state'=>$t['state'],'dueAt'=>$t['dueAt'],'href'=>$t['href']],
        array_slice(array_values(array_filter($s['tasks'],static fn($t)=>$t['state']!=='resolved')),0,4)),
      'basis'=>$s['basis']];
}
/** Hourly cron and explicit staff check use same durable, bounded, deduplicated reminders. */
function az_admin_action_scan(?int $recipient=null,?PDO $db=null,?int $now=null):array
{
    $db??=az_db();$now??=time();
    $admins=az_admin_action_staff($db);
    if(!$admins)return ['created'=>0,'reminded'=>0,'checked'=>0,'truncated'=>false];
    $adminIds=array_column($admins,'id');
    $actor=$recipient??$adminIds[0];
    if(!in_array($actor,$adminIds,true))throw new AzHttpError(403,'Active administrator required.','role_required');
    az_prepare_storage();$lock=fopen(AZ_STORAGE.'/admin-action.lock','c+b');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new AzHttpError(409,'Action Center check is already running.','scan_in_progress');
    $created=0;$reminded=0;$checked=0;
    try{
        $sample=az_admin_intelligence_candidates($db,$now,100);
        $db->beginTransaction();
        foreach($sample['items'] as $candidate){
            $result=az_admin_action_create_candidate($db,$candidate,$actor,$now);
            if($result['created'])$created++;
        }
        $limit=150;$cutoff=gmdate('Y-m-d\TH:i:s\Z',$now);
        $q=$db->prepare("SELECT * FROM admin_action_tasks WHERE state IN ('open','acknowledged')
             AND due_at<=? ORDER BY due_at ASC,id ASC LIMIT ".($limit+1));
        $q->execute([$cutoff]);$dueRows=$q->fetchAll(PDO::FETCH_ASSOC);
        $truncated=$sample['truncated']||count($dueRows)>$limit;
        foreach(array_slice($dueRows,0,$limit) as $task){
            $checked++;
            if(!az_admin_action_source_active($db,$task))continue;
            $owner=(int)$task['assignee_id'];if(!in_array($owner,$adminIds,true))continue;
            $dueTs=strtotime($task['due_at']);
            if($dueTs===false)continue;
            $late=$now-$dueTs>=86400;
            $stage=$late?'overdue-'.gmdate('Y-m-d',$now):'due';
            $key=substr(hash('sha256',$task['due_at'].'|'.$stage),0,64);
            try{
                $db->prepare('INSERT INTO admin_action_reminders(id,task_id,recipient_id,reminder_key,created_at) VALUES(?,?,?,?,?)')
                  ->execute([az_id(),$task['id'],$owner,$key,gmdate('Y-m-d\TH:i:s\Z',$now)]);
            }catch(PDOException $e){if(az_admin_action_is_duplicate($e,$db))continue;throw $e;}
            az_agent_workspace_emit($actor,$owner,'admin_action_due',
                ($late?'Overdue studio task':'Studio task due').' · '.$task['title'],
                'A tracked workflow task remains open. Review the original source and its staff resolution history. No operational action was performed.',
                'admin-studio.php',true);
            $reminded++;
        }
        $db->commit();
        return ['created'=>$created,'reminded'=>$reminded,'checked'=>$checked,'truncated'=>$truncated,
          'note'=>'Staff follow-ups only; no application decisions, publishing actions or financial provider requests.'];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    finally{flock($lock,LOCK_UN);fclose($lock);}
}
