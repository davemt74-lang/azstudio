<?php
declare(strict_types=1);
/**
 * Calendar & Scheduling Specialist. All facts come from authenticated schedule/work
 * records. No external calendar sync, autonomous booking, or speculative availability.
 */
function az_upgrade_agent_scheduling_schema(PDO $db): void
{
    if (az_schema_version($db)>=33 || az_schema_version($db)<32) return;
    az_prepare_storage();
    $lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Cannot lock scheduling specialist upgrade.');
    try {
        if (az_schema_version($db)>=33) return;
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $uid=$mysql?'BIGINT UNSIGNED':'INTEGER';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS model_schedule_availability (
          user_id $uid PRIMARY KEY, weekly_json TEXT NOT NULL, revision INTEGER NOT NULL DEFAULT 1,
          updated_at VARCHAR(32) NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS model_schedule_blackouts (
          id CHAR(32) PRIMARY KEY, user_id $uid NOT NULL, start_at VARCHAR(32) NOT NULL,
          end_at VARCHAR(32) NOT NULL, reason VARCHAR(160) NOT NULL, created_at VARCHAR(32) NOT NULL,
          FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS schedule_agent_deliveries (
          id CHAR(32) PRIMARY KEY, recipient_id $uid NOT NULL, item_key VARCHAR(80) NOT NULL,
          rule VARCHAR(40) NOT NULL, fingerprint CHAR(64) NOT NULL, created_at VARCHAR(32) NOT NULL,
          UNIQUE(recipient_id,rule,fingerprint), FOREIGN KEY(recipient_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS schedule_agent_runs (
          scope VARCHAR(80) PRIMARY KEY, completed_at VARCHAR(32) NOT NULL,
          checked_count INTEGER NOT NULL, delivered_count INTEGER NOT NULL, truncated INTEGER NOT NULL DEFAULT 0)$suffix");
        if ($mysql) {
            $q=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
            $q->execute(['model_schedule_blackouts','schedule_blackouts_user']);
            if (!(int)$q->fetchColumn()) $db->exec('CREATE INDEX schedule_blackouts_user ON model_schedule_blackouts(user_id,start_at,end_at)');
        } else $db->exec('CREATE INDEX IF NOT EXISTS schedule_blackouts_user ON model_schedule_blackouts(user_id,start_at,end_at)');
        $db->exec('UPDATE studio_schema SET version=33 WHERE id=1');
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}

function az_agent_schedule_is_model(array $user): bool {return az_has_role($user,'model');}
function az_agent_schedule_time(string $utc): int {return strtotime($utc)?:0;}
function az_agent_schedule_interval_overlap(string $a,string $b,string $c,string $d): bool {return $a<$d && $c<$b;}
/** Accept max three non-overlapping, fifteen-minute aligned weekly windows per weekday. */
function az_agent_schedule_validate_weekly(mixed $weekly): array
{
    if(!is_array($weekly))throw new AzHttpError(400,'Weekly availability must be keyed by weekday.','invalid_availability');
    $out=[];
    for($d=0;$d<7;$d++){
        $windows=$weekly[(string)$d]??$weekly[$d]??[];
        if(!is_array($windows)||!array_is_list($windows)||count($windows)>3)throw new AzHttpError(400,'Choose up to three time ranges per day.','invalid_availability');
        $seen=[];
        foreach($windows as $window){
            if(!is_array($window)||!is_string($window['start']??null)||!is_string($window['end']??null))throw new AzHttpError(400,'Enter valid availability times.','invalid_availability');
            foreach(['start','end'] as $key)if(!preg_match('/\A(?:[01][0-9]|2[0-3]):(?:00|15|30|45)\z/D',$window[$key]))throw new AzHttpError(400,'Availability uses fifteen-minute increments.','invalid_availability');
            if($window['start']>=$window['end'])throw new AzHttpError(400,'Availability must end after it begins.','invalid_availability');
            $seen[]=['start'=>$window['start'],'end'=>$window['end']];
        }
        usort($seen,static fn($a,$b)=>strcmp($a['start'],$b['start']));
        for($i=1;$i<count($seen);$i++)if($seen[$i-1]['end']>$seen[$i]['start'])throw new AzHttpError(400,'Availability windows must not overlap.','invalid_availability');
        $out[(string)$d]=$seen;
    }
    foreach($weekly as $key=>$ignored)if(!preg_match('/\A[0-6]\z/D',(string)$key))throw new AzHttpError(400,'Unknown weekday.','invalid_availability');
    return $out;
}
function az_agent_schedule_model_availability(int $modelId,array $requester): array
{
    if ($modelId!==(int)$requester['id']) throw new AzHttpError(403,'You can only edit your own model availability.','role_required');
    if(!az_agent_schedule_is_model($requester))throw new AzHttpError(403,'Model access required.','role_required');
    $q=az_db()->prepare('SELECT weekly_json,revision,updated_at FROM model_schedule_availability WHERE user_id=?');$q->execute([$modelId]);$row=$q->fetch();
    $q=az_db()->prepare('SELECT id,start_at,end_at,reason FROM model_schedule_blackouts WHERE user_id=? AND end_at>=? ORDER BY start_at,id LIMIT 100');$q->execute([$modelId,az_now()]);
    $blackouts=array_map(static fn($r)=>['id'=>$r['id'],'startAt'=>$r['start_at'],'endAt'=>$r['end_at'],'reason'=>$r['reason']],$q->fetchAll());
    return ['configured'=>(bool)$row,'revision'=>$row?(int)$row['revision']:0,
      'weekly'=>$row?(json_decode($row['weekly_json'],true)?:[]):[], 'blackouts'=>$blackouts,'timezone'=>AZ_SCHEDULE_TIMEZONE,'updatedAt'=>$row['updated_at']??null];
}
function az_agent_schedule_save_availability(array $body,array $user): array
{
    if(!az_agent_schedule_is_model($user))throw new AzHttpError(403,'Model access required.','role_required');
    $weekly=az_agent_schedule_validate_weekly($body['weekly']??null);
    $current=az_agent_schedule_model_availability((int)$user['id'],$user);
    if(!is_int($body['revision']??null)||$body['revision']!==$current['revision'])throw new AzHttpError(409,'Availability changed elsewhere. Reload and retry.','revision_conflict');
    $json=json_encode($weekly,JSON_THROW_ON_ERROR);
    if($current['revision']){
        $q=az_db()->prepare('UPDATE model_schedule_availability SET weekly_json=?,revision=revision+1,updated_at=? WHERE user_id=? AND revision=?');
        $q->execute([$json,az_now(),$user['id'],$current['revision']]);
        if($q->rowCount()!==1)throw new AzHttpError(409,'Availability changed. Reload first.','revision_conflict');
    } else az_db()->prepare('INSERT INTO model_schedule_availability(user_id,weekly_json,updated_at) VALUES(?,?,?)')->execute([$user['id'],$json,az_now()]);
    az_agent_workspace_emit((int)$user['id'],(int)$user['id'],'schedule_availability_saved','Weekly availability updated','Your model availability has been saved.','calendar.php');
    return az_agent_schedule_model_availability((int)$user['id'],$user);
}
function az_agent_schedule_add_blackout(array $body,array $user): array
{
    if(!az_agent_schedule_is_model($user))throw new AzHttpError(403,'Model access required.','role_required');
    [$start,$end]=az_sched_span($body);
    if($start<az_now()||strtotime($end)>time()+366*86400)throw new AzHttpError(400,'Choose a future block within one year.','invalid_time_range');
    if(array_filter(az_agent_schedule_busy((int)$user['id'],$start,$end),static fn($item)=>$item['type']!=='blackout'))
        throw new AzHttpError(409,'This blocked time overlaps a confirmed appointment or registered event. Resolve it first.','schedule_conflict');
    $q=az_db()->prepare('SELECT COUNT(*) FROM model_schedule_blackouts WHERE user_id=? AND start_at<? AND end_at>?');$q->execute([$user['id'],$end,$start]);
    if((int)$q->fetchColumn()>0)throw new AzHttpError(409,'This block overlaps another unavailable time.','blackout_overlap');
    $id=az_id();$reason=az_text($body['reason']??'Unavailable',160,'Reason');
    az_db()->prepare('INSERT INTO model_schedule_blackouts(id,user_id,start_at,end_at,reason,created_at) VALUES(?,?,?,?,?,?)')->execute([$id,$user['id'],$start,$end,$reason,az_now()]);
    az_agent_workspace_emit((int)$user['id'],(int)$user['id'],'schedule_time_blocked','Unavailable time added','Your unavailable time was saved.','calendar.php');
    return az_agent_schedule_model_availability((int)$user['id'],$user);
}
function az_agent_schedule_remove_blackout(array $body,array $user): array
{
    if(!az_agent_schedule_is_model($user))throw new AzHttpError(403,'Model access required.','role_required');
    if(!is_string($body['id']??null)||!az_valid_id($body['id']))throw new AzHttpError(400,'Invalid time block.','invalid_id');
    $q=az_db()->prepare('DELETE FROM model_schedule_blackouts WHERE id=? AND user_id=?');$q->execute([$body['id'],$user['id']]);
    if($q->rowCount()!==1)throw new AzHttpError(404,'This time block is not available.','not_found');
    return az_agent_schedule_model_availability((int)$user['id'],$user);
}
/** Explicitly unconfigured is *unknown*, never 'available'. Avoid counting assignment deadlines as busy. */
function az_agent_schedule_window_coverage(array $weekly,string $start,string $end): bool
{
    if($start>=$end)return false;
    $tz=new DateTimeZone(AZ_SCHEDULE_TIMEZONE);
    $from=(new DateTimeImmutable($start))->setTimezone($tz);
    $until=(new DateTimeImmutable($end))->setTimezone($tz);
    if($from->format('Y-m-d')!==$until->format('Y-m-d'))return false;
    $weekday=(string)$from->format('w');$begin=$from->format('H:i');$finish=$until->format('H:i');
    foreach($weekly[$weekday]??[] as $window)if($begin>=$window['start']&&$finish<=$window['end'])return true;
    return false;
}
function az_agent_schedule_busy(int $userId,string $start,string $end): array
{
    $busy=[];
    $q=az_db()->prepare("SELECT id,start_at,end_at FROM schedule_appointments WHERE status='confirmed' AND (customer_id=? OR professional_id=?) AND start_at<? AND end_at>? ORDER BY start_at LIMIT 30");
    $q->execute([$userId,$userId,$end,$start]);
    foreach($q->fetchAll() as $a)$busy[]=['type'=>'appointment','id'=>$a['id']];
    $q=az_db()->prepare("SELECT e.id FROM schedule_events e LEFT JOIN schedule_registrations r ON r.event_id=e.id AND r.user_id=? AND r.status='registered' WHERE e.status='published' AND (r.user_id IS NOT NULL OR e.instructor_id=?) AND e.start_at<? AND e.end_at>? ORDER BY e.start_at LIMIT 30");
    $q->execute([$userId,$userId,$end,$start]);
    foreach($q->fetchAll() as $a)$busy[]=['type'=>'event','id'=>$a['id']];
    $q=az_db()->prepare('SELECT id FROM model_schedule_blackouts WHERE user_id=? AND start_at<? AND end_at>? LIMIT 30');$q->execute([$userId,$end,$start]);
    foreach($q->fetchAll() as $a)$busy[]=['type'=>'blackout','id'=>$a['id']];
    return $busy;
}
function az_agent_schedule_model_fit(int $modelId,string $start,string $end): array
{
    $q=az_db()->prepare('SELECT weekly_json FROM model_schedule_availability WHERE user_id=?');$q->execute([$modelId]);$json=$q->fetchColumn();
    if($json===false)return ['state'=>'unknown','reason'=>'Model has not set availability'];
    $weekly=json_decode((string)$json,true)?:[];
    if(!az_agent_schedule_window_coverage($weekly,$start,$end))return ['state'=>'unavailable','reason'=>'Outside declared model availability'];
    $conflicts=az_agent_schedule_busy($modelId,$start,$end);
    return $conflicts?['state'=>'conflict','reason'=>'Existing booking, registered event, or blocked time','count'=>count($conflicts)]:['state'=>'available','reason'=>'Declared available with no known calendar conflict'];
}
/** Read-only, role-scoped calendar. Private booking notes and other-member rosters never leave this function. */
function az_agent_schedule_timeline(array $user,string $from,string $to): array
{
    $uid=(int)$user['id'];$list=[];
    $q=az_db()->prepare("SELECT e.id,e.type,e.title,e.start_at,e.end_at,e.location FROM schedule_events e
       LEFT JOIN schedule_registrations r ON r.event_id=e.id AND r.user_id=? AND r.status='registered'
       WHERE e.status='published' AND (r.user_id IS NOT NULL OR e.instructor_id=? OR e.created_by=?) AND e.start_at<? AND e.end_at>? ORDER BY e.start_at LIMIT 150");
    $q->execute([$uid,$uid,$uid,$to,$from]);
    foreach($q->fetchAll() as $r)$list[]=['id'=>$r['id'],'type'=>$r['type'],'title'=>$r['title'],'startAt'=>$r['start_at'],'endAt'=>$r['end_at'],'href'=>'calendar.php','busy'=>true];
    $q=az_db()->prepare("SELECT id,type_name,start_at,end_at FROM schedule_appointments WHERE status='confirmed' AND (customer_id=? OR professional_id=?) AND start_at<? AND end_at>? ORDER BY start_at LIMIT 150");
    $q->execute([$uid,$uid,$to,$from]);
    foreach($q->fetchAll() as $r)$list[]=['id'=>$r['id'],'type'=>'appointment','title'=>$r['type_name'],'startAt'=>$r['start_at'],'endAt'=>$r['end_at'],'href'=>'appointments.php','busy'=>true];
    $q=az_db()->prepare("SELECT p.id,p.title,e.start_at,e.end_at FROM production_projects p JOIN schedule_events e ON e.id=p.event_id AND e.status='published'
       LEFT JOIN production_members m ON m.project_id=p.id AND m.user_id=? AND m.status='active'
       WHERE p.status='active' AND (p.owner_id=? OR m.user_id IS NOT NULL) AND e.start_at<? AND e.end_at>? ORDER BY e.start_at LIMIT 100");
    $q->execute([$uid,$uid,$to,$from]);
    foreach($q->fetchAll() as $r)$list[]=['id'=>$r['id'],'type'=>'production','title'=>$r['title'],'startAt'=>$r['start_at'],'endAt'=>$r['end_at'],'href'=>az_has_role($user,'admin')?'productions.php':'my-work.php','busy'=>false];
    $q=az_db()->prepare("SELECT p.id,p.title,a.start_at,a.end_at FROM production_projects p JOIN schedule_appointments a ON a.id=p.appointment_id AND a.status='confirmed'
       LEFT JOIN production_members m ON m.project_id=p.id AND m.user_id=? AND m.status='active'
       WHERE p.status='active' AND (p.owner_id=? OR m.user_id IS NOT NULL)
         AND (a.customer_id=? OR a.professional_id=?) AND a.start_at<? AND a.end_at>? ORDER BY a.start_at LIMIT 100");
    $q->execute([$uid,$uid,$uid,$uid,$to,$from]);
    foreach($q->fetchAll() as $r)$list[]=['id'=>$r['id'],'type'=>'production','title'=>$r['title'],'startAt'=>$r['start_at'],'endAt'=>$r['end_at'],'href'=>az_has_role($user,'admin')?'productions.php':'my-work.php','busy'=>false];
    $q=az_db()->prepare("SELECT a.id,s.title,s.due_at FROM shot_assignments a JOIN production_shots s ON s.id=a.shot_id AND s.status='active'
       JOIN production_projects p ON p.id=s.project_id AND p.status='active' WHERE a.user_id=? AND a.status IN ('assigned','accepted','changes_requested')
       AND s.due_at>=? AND s.due_at<? ORDER BY s.due_at LIMIT 100");
    $q->execute([$uid,$from,$to]);
    foreach($q->fetchAll() as $r)$list[]=['id'=>$r['id'],'type'=>'assignment','title'=>$r['title'].' deadline','startAt'=>$r['due_at'],'endAt'=>az_sched_shift($r['due_at'],15),'href'=>'my-work.php?assignmentId='.$r['id'],'busy'=>false];
    usort($list,static fn($a,$b)=>strcmp($a['startAt'],$b['startAt'])?:strcmp($a['id'],$b['id']));
    return array_slice($list,0,200);
}
function az_agent_schedule_collisions(array $items): array
{
    $busy=array_values(array_filter($items,static fn($x)=>($x['busy']??false)===true));
    $conflicts=[];
    foreach($busy as $i=>$one)foreach(array_slice($busy,$i+1) as $two){
        if($two['startAt']>=$one['endAt'])break;
        if(az_agent_schedule_interval_overlap($one['startAt'],$one['endAt'],$two['startAt'],$two['endAt']) && $one['id']!==$two['id'])$conflicts[]=['first'=>$one['id'],'second'=>$two['id'],'startAt'=>$two['startAt']];
        if(count($conflicts)>=20)break 2;
    }
    return $conflicts;
}
function az_agent_schedule_overview(array $user): array
{
    $from=az_now();$to=gmdate('Y-m-d\TH:i:s\Z',time()+30*86400);
    $items=az_agent_schedule_timeline($user,$from,$to);
    $conflicts=az_agent_schedule_collisions($items);
    $counts=['upcoming'=>count($items),'appointments'=>count(array_filter($items,static fn($i)=>$i['type']==='appointment')),
      'assignments'=>count(array_filter($items,static fn($i)=>$i['type']==='assignment')),'conflicts'=>count($conflicts)];
    return ['timezone'=>AZ_SCHEDULE_TIMEZONE,'items'=>$items,'conflicts'=>$conflicts,'counts'=>$counts,
      'availability'=>az_agent_schedule_is_model($user)?az_agent_schedule_model_availability((int)$user['id'],$user):null,'generatedAt'=>az_now()];
}
/** Admin may compare only active roster members for a project they own, no private schedules exposed. */
function az_agent_schedule_recommend(array $admin,array $query): array
{
    if(!az_has_role($admin,'admin'))throw new AzHttpError(403,'Admin access required.','role_required');
    $project=$query['projectId']??null;
    if(!is_string($project)||!az_valid_id($project))throw new AzHttpError(400,'Choose a production.','invalid_project');
    [$start,$end]=az_sched_span($query);
    if($start<az_now()||strtotime($start)>time()+90*86400)throw new AzHttpError(400,'Choose a future production slot within 90 days.','invalid_time_range');
    $q=az_db()->prepare("SELECT id,title FROM production_projects WHERE id=? AND owner_id=? AND status='active'");$q->execute([$project,$admin['id']]);$row=$q->fetch();
    if(!$row)throw new AzHttpError(404,'Production not found.','not_found');
    $q=az_db()->prepare("SELECT DISTINCT u.id,u.name FROM production_members m JOIN users u ON u.id=m.user_id JOIN user_roles r ON r.user_id=u.id AND r.role='model'
       WHERE m.project_id=? AND m.status='active' AND u.status='active' ORDER BY u.name,u.id LIMIT 60");$q->execute([$project]);
    $matches=[];
    foreach($q->fetchAll() as $model){
        $ready=az_agent_casting_onboarding((int)$model['id']);
        if(!$ready||!($ready['ready']??false))continue;
        $fit=az_agent_schedule_model_fit((int)$model['id'],$start,$end);
        $matches[]=['modelId'=>(int)$model['id'],'label'=>$model['name'],'availability'=>$fit['state'],'reason'=>$fit['reason']];
    }
    $order=['available'=>0,'unknown'=>1,'conflict'=>2,'unavailable'=>3];
    usort($matches,static fn($a,$b)=>($order[$a['availability']]??4)<=>($order[$b['availability']]??4) ?: strcmp($a['label'],$b['label']));
    return ['projectId'=>$project,'startAt'=>$start,'endAt'=>$end,'models'=>$matches,'recommendationOnly'=>true,'note'=>'No assignment or booking has been changed. Unconfigured availability is unknown, not available.'];
}
/** Fingerprints include the authoritative event/appointment start and status. */
function az_agent_schedule_reminder_fingerprint(string $key,string $start,string $rule): string {return hash('sha256',$key.'|'.$start.'|'.$rule);}
function az_agent_schedule_deliver(PDO $db,int $userId,string $key,string $start,string $rule,string $title,string $href): bool
{
    $role=$db->prepare("SELECT COUNT(*) FROM user_roles r JOIN users u ON u.id=r.user_id WHERE r.user_id=? AND u.status='active' AND r.role IN ('model','admin')");
    $role->execute([$userId]);if(!(int)$role->fetchColumn())return false;
    $fingerprint=az_agent_schedule_reminder_fingerprint($key,$start,$rule);
    try{$db->prepare('INSERT INTO schedule_agent_deliveries(id,recipient_id,item_key,rule,fingerprint,created_at) VALUES(?,?,?,?,?,?)')
        ->execute([az_id(),$userId,$key,$rule,$fingerprint,az_now()]);}
    catch(PDOException $e){$driver=$db->getAttribute(PDO::ATTR_DRIVER_NAME);$errno=$e->errorInfo[1]??null;
        if(($driver==='mysql' && (int)$errno===1062)||($driver==='sqlite' && (int)$errno===19 && str_contains((string)($e->errorInfo[2]??''),'UNIQUE constraint failed')))return false;throw $e;}
    az_agent_workspace_emit($userId,$userId,'scheduling_'.$rule,$title,'Your upcoming schedule needs attention. Open the calendar to review details.',$href,true);
    return true;
}
/** Called only from the same locked private hourly cron or explicit admin check. */
function az_agent_schedule_scan(?int $ownerId=null): array
{
    $db=az_db();$now=time();$until=gmdate('Y-m-d\TH:i:s\Z',$now+24*3600);$from=az_now();
    $checked=0;$delivered=0;$truncated=false;$max=400;
    $db->beginTransaction();
    try{
        $sql="SELECT a.id,a.start_at,a.customer_id,a.professional_id FROM schedule_appointments a JOIN users c ON c.id=a.customer_id AND c.status='active'
          WHERE a.status='confirmed' AND a.start_at>=? AND a.start_at<?".($ownerId!==null?' AND (a.customer_id=? OR a.professional_id=?)':'')." ORDER BY a.start_at,a.id LIMIT ".($max+1);
        $args=[$from,$until];if($ownerId!==null)array_push($args,$ownerId,$ownerId);
        $q=$db->prepare($sql);$q->execute($args);
        foreach($q->fetchAll() as $a){if($checked>=$max){$truncated=true;break;}$checked++;
            foreach(array_unique([(int)$a['customer_id'],(int)$a['professional_id']]) as $id){
                if($id<1)continue;
                if(az_agent_schedule_deliver($db,$id,'appointment:'.$a['id'],$a['start_at'],'appointment_24h','Appointment in the next 24 hours','appointments.php'))$delivered++;
            }
        }
        $sql="SELECT e.id,e.start_at,r.user_id FROM schedule_events e JOIN schedule_registrations r ON r.event_id=e.id AND r.status='registered'
          JOIN users u ON u.id=r.user_id AND u.status='active' WHERE e.status='published' AND e.start_at>=? AND e.start_at<?".($ownerId!==null?' AND r.user_id=?':'')." ORDER BY e.start_at,e.id LIMIT ".($max+1);
        $args=[$from,$until];if($ownerId!==null)$args[]=$ownerId;
        $q=$db->prepare($sql);$q->execute($args);
        foreach($q->fetchAll() as $e){if($checked>=$max){$truncated=true;break;}$checked++;
            if(az_agent_schedule_deliver($db,(int)$e['user_id'],'event:'.$e['id'],$e['start_at'],'event_24h','Registered event in the next 24 hours','calendar.php'))$delivered++;
        }
        // Assigned instructors and event creators need their own reminders, even if
        // they are not separately registered. Recipient-role gating remains in deliver().
        $sql="SELECT e.id,e.start_at,e.instructor_id,e.created_by FROM schedule_events e
          WHERE e.status='published' AND e.start_at>=? AND e.start_at<?".($ownerId!==null?' AND (e.instructor_id=? OR e.created_by=?)':'')." ORDER BY e.start_at,e.id LIMIT ".($max+1);
        $args=[$from,$until];if($ownerId!==null)array_push($args,$ownerId,$ownerId);
        $q=$db->prepare($sql);$q->execute($args);
        foreach($q->fetchAll() as $e){if($checked>=$max){$truncated=true;break;}$checked++;
            foreach(array_unique([(int)$e['instructor_id'],(int)$e['created_by']]) as $id){
                if($id>0 && az_agent_schedule_deliver($db,$id,'event:'.$e['id'],$e['start_at'],'event_24h','Upcoming assigned event in the next 24 hours','calendar.php'))$delivered++;
            }
        }
        $scope=$ownerId===null?'cron':'admin:'.$ownerId;$completed=az_now();
        $q=$db->prepare('SELECT scope FROM schedule_agent_runs WHERE scope=?');$q->execute([$scope]);
        if($q->fetchColumn()!==false)$db->prepare('UPDATE schedule_agent_runs SET completed_at=?,checked_count=?,delivered_count=?,truncated=? WHERE scope=?')->execute([$completed,$checked,$delivered,(int)$truncated,$scope]);
        else $db->prepare('INSERT INTO schedule_agent_runs(scope,completed_at,checked_count,delivered_count,truncated) VALUES(?,?,?,?,?)')->execute([$scope,$completed,$checked,$delivered,(int)$truncated]);
        $db->commit();return ['checked'=>$checked,'delivered'=>$delivered,'truncated'=>$truncated,'completedAt'=>$completed,'scope'=>$scope];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function az_agent_schedule_scan_status(?int $ownerId=null): array
{
    $q=az_db()->prepare('SELECT completed_at,checked_count,delivered_count,truncated FROM schedule_agent_runs WHERE scope=?');
    $q->execute([$ownerId===null?'cron':'admin:'.$ownerId]);$r=$q->fetch();
    return $r?['completedAt'=>$r['completed_at'],'checked'=>(int)$r['checked_count'],'delivered'=>(int)$r['delivered_count'],'truncated'=>(bool)$r['truncated']]:['completedAt'=>null,'checked'=>0,'delivered'=>0,'truncated'=>false];
}
function az_agent_schedule_briefing(array $user): array
{
    $overview=az_agent_schedule_overview($user);
    $cards=[];$counts=$overview['counts'];
    if($counts['conflicts'])$cards[]=az_agent_workspace_card('schedule_conflict','Calendar conflicts to review',$counts['conflicts'].' overlapping booked commitments need a scheduling decision.','calendar.php',1);
    foreach(array_slice($overview['items'],0,3) as $item)$cards[]=az_agent_workspace_card('upcoming_schedule','Upcoming: '.$item['title'],az_sched_local($item['startAt']).' Arizona time · '.ucfirst($item['type']),$item['href'],4);
    if(az_agent_schedule_is_model($user) && !$overview['availability']['configured'])$cards[]=az_agent_workspace_card('availability','Set your model availability','Your availability has not been recorded. Production matching will mark it unknown.','calendar.php',2);
    return ['cards'=>$cards,'counts'=>$counts,'upcoming'=>array_slice($overview['items'],0,6),'scan'=>az_agent_schedule_scan_status(),
      'timezone'=>AZ_SCHEDULE_TIMEZONE,'availabilityConfigured'=>(bool)($overview['availability']['configured']??false)];
}
/** No names, booking notes or private title details sent to AI providers. */
function az_agent_schedule_chat_snapshot(array $user): array
{
    $data=az_agent_schedule_briefing($user);
    return ['timezone'=>$data['timezone'],'counts'=>$data['counts'],'nextTypes'=>array_map(static fn($i)=>['type'=>$i['type'],'startAt'=>$i['startAt']],$data['upcoming']),
      'availabilityConfigured'=>$data['availabilityConfigured'],'reminderScanCompletedAt'=>$data['scan']['completedAt']];
}
