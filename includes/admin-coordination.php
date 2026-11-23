<?php
declare(strict_types=1);
/**
 * v1.44 production/schedule coordination. Read-only classification of existing
 * production, schedule and Action Center ledgers. No roster, booking, media,
 * screening or financial mutations are permitted here.
 */
function az_coordination_tables_ready(PDO $db):bool
{
    $names=['production_projects','production_shots','shot_assignments','schedule_events','schedule_appointments'];
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $q=$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('production_projects','production_shots','shot_assignments','schedule_events','schedule_appointments')");
        return count($q->fetchAll(PDO::FETCH_COLUMN))===5;
    }
    $q=$db->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('production_projects','production_shots','shot_assignments','schedule_events','schedule_appointments')");
    return count($q->fetchAll(PDO::FETCH_COLUMN))===5;
}
/** Rules depend only on assignment status, elapsed time and real due dates. */
function az_coordination_assignment_rule(array $r,int $now):?array
{
    $status=(string)($r['status']??'');
    $id=(string)($r['id']??'');$project=(string)($r['project_id']??'');
    if(!az_valid_id($id)||!az_valid_id($project)||!in_array($status,['assigned','accepted','submitted','changes_requested'],true))return null;
    $updated=strtotime((string)($r['updated_at']??''));$submitted=strtotime((string)($r['submitted_at']??''));
    $due=strtotime((string)($r['due_at']??''));
    $age=$updated===false?null:max(0,$now-$updated);
    $stage=null;$priority=2;$title='';$detail='';
    if($status==='submitted'&&$submitted!==false&&$now-$submitted>=24*3600){
        $stage='production_review_waiting';$priority=$now-$submitted>=72*3600?0:1;
        $title='Production take awaiting staff review';
        $detail='A submitted production assignment has waited for staff review for more than 24 hours. Inspect its current takes in the protected production workspace; no creative approval is inferred.';
    }elseif(in_array($status,['assigned','accepted','changes_requested'],true)&&$due!==false&&$due<=$now+48*3600){
        $stage='production_assignment_due';$priority=$due<=$now?0:1;
        $title=$due<=$now?'Production assignment past its due date':'Production assignment due within 48 hours';
        $detail='A production assignment is near or beyond its existing deadline. Review the source status, creator availability and rescheduling options before taking action.';
    }elseif($status==='changes_requested'&&$age!==null&&$age>=48*3600){
        $stage='production_retake_waiting';$priority=1;$title='Production retake request needs follow-up';
        $detail='A retake has remained outstanding for two days. Review the original feedback and deadline; no footage has been inspected by the Agent.';
    }elseif($status==='assigned'&&$age!==null&&$age>=48*3600){
        $stage='production_response_waiting';$priority=1;$title='Production assignment awaiting acceptance';
        $detail='An assignment has not received a response for two days. Review the roster and calendar before contacting the assigned creator.';
    }
    if($stage===null)return null;
    return ['sourceKind'=>'production','sourceId'=>$id,'sourceStage'=>$stage,
      'category'=>'production','priority'=>$priority,'title'=>$title,'detail'=>$detail,
      'href'=>'productions.php?assignmentId='.$id,'status'=>$status,
      'dueAt'=>$due===false?null:gmdate('Y-m-d\TH:i:s\Z',$due)];
}
/** An assigned-mode shot with no active assignment is operationally unstaffed. */
function az_coordination_unstaffed_rule(array $r,int $now):?array
{
    $id=(string)($r['id']??'');$project=(string)($r['project_id']??'');
    $due=strtotime((string)($r['due_at']??''));
    if(!az_valid_id($id)||!az_valid_id($project)||$due===false||$due>$now+7*86400)return null;
    $priority=$due<=$now+24*3600?0:1;
    return ['sourceKind'=>'production','sourceId'=>$id,'sourceStage'=>'production_unstaffed_shot',
      'category'=>'production','priority'=>$priority,'title'=>'Production shot needs an assigned contributor',
      'detail'=>'A shot in an active production has no currently assigned contributor and is due within seven days. Confirm roster availability and assignment in Productions.',
      'href'=>'productions.php?projectId='.$project,'status'=>'unassigned',
      'dueAt'=>gmdate('Y-m-d\TH:i:s\Z',$due)];
}
function az_coordination_candidates(PDO $db,int $now,int $limit=100):array
{
    $limit=max(1,min(150,$limit));
    if(!az_coordination_tables_ready($db))return ['items'=>[],'available'=>false,'truncated'=>false,'checked'=>0];
    $items=[];$checked=0;$truncated=false;
    // Production access and active-status rules are checked on the server.
    $q=$db->query("SELECT a.id,a.status,a.updated_at,a.submitted_at,s.due_at,p.id AS project_id
        FROM shot_assignments a JOIN production_shots s ON s.id=a.shot_id AND s.status='active'
        JOIN production_projects p ON p.id=s.project_id AND p.status='active'
        WHERE a.status IN ('assigned','accepted','submitted','changes_requested')
        ORDER BY COALESCE(s.due_at,a.updated_at) ASC,a.id ASC LIMIT ".($limit+1));
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)>$limit)$truncated=true;
    foreach(array_slice($rows,0,$limit) as $r){
        $checked++;$case=az_coordination_assignment_rule($r,$now);
        if($case)$items[]=$case;
    }
    $q=$db->prepare("SELECT s.id,s.project_id,s.due_at FROM production_shots s
      JOIN production_projects p ON p.id=s.project_id AND p.status='active'
      LEFT JOIN shot_assignments a ON a.shot_id=s.id AND a.status NOT IN ('declined','cancelled')
      WHERE s.status='active' AND s.mode='assigned' AND s.due_at IS NOT NULL AND s.due_at<=?
      GROUP BY s.id,s.project_id,s.due_at HAVING COUNT(a.id)=0
      ORDER BY s.due_at ASC,s.id ASC LIMIT ".($limit+1));
    $q->execute([gmdate('Y-m-d\TH:i:s\Z',$now+7*86400)]);
    $shots=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($shots)>$limit)$truncated=true;
    foreach(array_slice($shots,0,$limit) as $r){
        $checked++;$case=az_coordination_unstaffed_rule($r,$now);
        if($case)$items[]=$case;
    }
    usort($items,static fn(array $a,array $b):int=>($a['priority']<=>$b['priority'])?:
        strcmp($a['dueAt']??'9999',$b['dueAt']??'9999')?:strcmp($a['sourceId'],$b['sourceId']));
    $truncated=$truncated||count($items)>$limit;
    return ['items'=>array_slice($items,0,$limit),'available'=>true,'truncated'=>$truncated,'checked'=>$checked];
}
/** Original work state is authoritative when a tracked production case is due. */
function az_coordination_source_active(PDO $db,array $task,int $now):bool
{
    if(($task['source_kind']??'')!=='production'||!az_valid_id((string)($task['source_id']??''))||
       !az_coordination_tables_ready($db))return false;
    $stage=(string)($task['source_stage']??'');
    if($stage==='production_unstaffed_shot'){
        $q=$db->prepare("SELECT s.id,s.project_id,s.due_at FROM production_shots s
          JOIN production_projects p ON p.id=s.project_id AND p.status='active'
          LEFT JOIN shot_assignments a ON a.shot_id=s.id AND a.status NOT IN ('declined','cancelled')
          WHERE s.id=? AND s.status='active' AND s.mode='assigned'
          GROUP BY s.id,s.project_id,s.due_at HAVING COUNT(a.id)=0");
        $q->execute([$task['source_id']]);
        $r=$q->fetch(PDO::FETCH_ASSOC);
        return $r!==false&&az_coordination_unstaffed_rule($r,$now)!==null;
    }
    $q=$db->prepare("SELECT a.id,a.status,a.updated_at,a.submitted_at,s.due_at,p.id AS project_id
        FROM shot_assignments a JOIN production_shots s ON s.id=a.shot_id AND s.status='active'
        JOIN production_projects p ON p.id=s.project_id AND p.status='active' WHERE a.id=?");
    $q->execute([$task['source_id']]);$r=$q->fetch(PDO::FETCH_ASSOC);
    if(!$r)return false;
    $rule=az_coordination_assignment_rule($r,$now);
    return $rule!==null&&$rule['sourceStage']===$stage;
}
function az_coordination_metrics(PDO $db,int $now):array
{
    $week=gmdate('Y-m-d\TH:i:s\Z',$now-7*86400);
    $start=gmdate('Y-m-d\TH:i:s\Z',$now);$end=gmdate('Y-m-d\TH:i:s\Z',$now+7*86400);
    $stat=static function(PDO $db,string $sql,array $args=[]):int{
        $q=$db->prepare($sql);$q->execute($args);return (int)$q->fetchColumn();
    };
    $counts=['activeProductions'=>$stat($db,"SELECT COUNT(*) FROM production_projects WHERE status='active'"),
      'assignmentsApproved7d'=>$stat($db,"SELECT COUNT(*) FROM shot_assignments a
           JOIN production_shots s ON s.id=a.shot_id JOIN production_projects p ON p.id=s.project_id
           WHERE a.status='approved' AND a.reviewed_at>=?",[$week]),
      'actionResolutions7d'=>$stat($db,"SELECT COUNT(*) FROM admin_action_history WHERE action='resolve' AND created_at>=?",[$week]),
      'upcomingAppointments7d'=>$stat($db,"SELECT COUNT(*) FROM schedule_appointments WHERE status='confirmed' AND start_at>=? AND start_at<?",[$start,$end]),
      'upcomingEvents7d'=>$stat($db,"SELECT COUNT(*) FROM schedule_events WHERE status='published' AND start_at>=? AND start_at<?",[$start,$end]),
      'appointmentOverlapPairs7d'=>$stat($db,"SELECT COUNT(*) FROM schedule_appointments a
           JOIN schedule_appointments b ON a.id<b.id AND a.professional_id=b.professional_id
           AND a.start_at<b.end_at AND b.start_at<a.end_at AND b.status='confirmed'
           WHERE a.status='confirmed' AND a.end_at>? AND a.start_at<?",[$start,$end])];
    // Staff workload is by Action Center ownership, not by creator assignments.
    $staff=az_admin_action_staff($db);$countsByOwner=[];
    $q=$db->query("SELECT assignee_id,state,due_at,resolved_at FROM admin_action_tasks
       WHERE state IN ('open','acknowledged') OR resolved_at IS NOT NULL ORDER BY updated_at DESC LIMIT 3000");
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $task){
        $id=(int)$task['assignee_id'];
        if(!isset($countsByOwner[$id]))$countsByOwner[$id]=['active'=>0,'overdue'=>0,'due7d'=>0,'resolved7d'=>0];
        if($task['state']==='resolved'){
            if((string)$task['resolved_at']>=$week)$countsByOwner[$id]['resolved7d']++;
        }else{
            $countsByOwner[$id]['active']++;
            if($task['due_at']<$start)$countsByOwner[$id]['overdue']++;
            if($task['due_at']>=$start&&$task['due_at']<$end)$countsByOwner[$id]['due7d']++;
        }
    }
    $staffOut=[];foreach($staff as $person){
        $staffOut[]=['id'=>$person['id'],'name'=>$person['name']]+
           ($countsByOwner[$person['id']]??['active'=>0,'overdue'=>0,'due7d'=>0,'resolved7d'=>0]);
    }
    usort($staffOut,static fn($a,$b):int=>$b['overdue']<=>$a['overdue']?:
      $b['active']<=>$a['active']?:strcmp($a['name'],$b['name']));
    return ['counts'=>$counts,'staffLoad'=>array_slice($staffOut,0,25),
       'staffLimited'=>count($staffOut)>25];
}
function az_coordination_briefing(array $user,?PDO $db=null,?int $now=null):array
{
    az_admin_action_assert($user);$db??=az_db();$now??=time();
    $cases=az_coordination_candidates($db,$now,100);
    if(!$cases['available'])return ['available'=>false,'counts'=>[],
      'staffLoad'=>[],'cases'=>[],'nextStep'=>'Production/schedule records are unavailable.',
      'basis'=>'No coordination information could be verified from the source database.'];
    $metrics=az_coordination_metrics($db,$now);
    $counts=$metrics['counts'];
    $counts['productionAlerts']=count($cases['items']);
    $counts['urgentProductionAlerts']=count(array_filter($cases['items'],static fn($i)=>$i['priority']===0));
    $counts['unassignedShots']=count(array_filter($cases['items'],static fn($i)=>$i['sourceStage']==='production_unstaffed_shot'));
    $first=$cases['items'][0]??null;
    $next=$first?$first['title'].': '.$first['detail']:
       ($counts['appointmentOverlapPairs7d']>0?'Review overlapping professional appointments in the calendar.':
         'Review upcoming production milestones and staff task capacity.');
    return ['available'=>true,'generatedAt'=>gmdate('Y-m-d\TH:i:s\Z',$now),
      'counts'=>$counts,'cases'=>array_slice($cases['items'],0,10),
      'staffLoad'=>$metrics['staffLoad'],'staffLimited'=>$metrics['staffLimited'],
      'truncated'=>$cases['truncated'],'nextStep'=>$next,
      'actions'=>[
        ['title'=>'Production assignment board','href'=>'productions.php'],
        ['title'=>'Studio scheduling','href'=>'calendar.php'],
        ['title'=>'Staff Action Center','href'=>'admin-studio.php#azd-action-center']
      ],
      'basis'=>'Verified production status/due dates, recorded calendar bookings and staff-owned Action Center tasks. These are counts of assignments, appointment overlaps and task resolution events—not camera verification, creator availability promises or automatic staff decisions.'];
}
function az_coordination_chat_snapshot(array $user,?array $data=null):array
{
    az_admin_action_assert($user);
    $data??=az_coordination_briefing($user);
    if(!$data['available'])return ['available'=>false,'basis'=>$data['basis']];
    return ['available'=>true,'counts'=>$data['counts'],
      'nextStep'=>$data['nextStep'],'basis'=>$data['basis'],
      'followups'=>array_map(static fn($c)=>['title'=>$c['title'],'href'=>$c['href']],
        array_slice($data['cases'],0,3))];
}
