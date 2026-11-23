<?php
declare(strict_types=1);
/**
 * v1.45 — evidence-based Operations & Performance Learning.
 *
 * Read-only analysis of existing task and action-history ledgers. No new
 * personal profiles, model training, autonomous approvals or external actions.
 * All counts are workflow events/tasks, never employee performance scores.
 */
function az_ops_learning_days(int $days):int
{
    if(!in_array($days,[7,30,90],true))
        throw new AzHttpError(400,'Choose 7, 30 or 90 days.','invalid_period');
    return $days;
}
function az_ops_learning_family(string $kind,string $stage):array
{
    // Never echo user-authored source details or task titles into aggregated AI context.
    if($kind==='casting')
        return ['casting',str_starts_with($stage,'new_')?'Unreviewed model applications':'Casting stage follow-ups'];
    if($kind==='video')return ['video','Creator video reviews'];
    if($kind==='production'){
        $map=[
          'production_assignment_due'=>'Production deadlines',
          'production_response_waiting'=>'Production acceptance delays',
          'production_review_waiting'=>'Production take reviews',
          'production_retake_waiting'=>'Production retake requests',
          'production_unstaffed_shot'=>'Production shots needing staffing'
        ];
        return ['production',$map[$stage]??'Other production follow-ups'];
    }
    if($kind==='queue'){
        $map=[
          'provider_exception'=>'Provider reconciliation',
          'manual_orders'=>'Manual order verification',
          'payout_batches'=>'Creator settlement review',
          'video_queue'=>'Video moderation queue',
          'casting_queue'=>'Casting review queue'
        ];
        return ['commerce',$map[$stage]??'Other studio review queues'];
    }
    return ['other','Other workflow follow-ups'];
}
function az_ops_learning_median(array $numbers):?float
{
    if(!$numbers)return null;
    sort($numbers,SORT_NUMERIC);
    $n=count($numbers);$at=intdiv($n,2);
    return round($n%2?$numbers[$at]:($numbers[$at-1]+$numbers[$at])/2,1);
}
function az_ops_learning_series(PDO $db,int $days,int $now,string $since,string $until):array
{
    $start=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('UTC'))
        ->setTime(0,0)->modify('-'.($days-1).' days');
    $rows=[];$byDate=[];
    for($i=0;$i<$days;$i++){
        $day=$start->modify('+'.$i.' days')->format('Y-m-d');
        $byDate[$day]=['day'=>$day,'created'=>0,'resolvedEvents'=>0,'reopenedEvents'=>0];
    }
    $q=$db->prepare('SELECT SUBSTR(created_at,1,10) AS day,COUNT(*) AS total FROM admin_action_tasks WHERE created_at>=? AND created_at<? GROUP BY SUBSTR(created_at,1,10)');
    $q->execute([$since,$until]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)
        if(isset($byDate[$r['day']]))$byDate[$r['day']]['created']=(int)$r['total'];
    $q=$db->prepare("SELECT SUBSTR(created_at,1,10) AS day,action,COUNT(*) AS total FROM admin_action_history WHERE action IN ('resolve','reopen') AND created_at>=? AND created_at<? GROUP BY SUBSTR(created_at,1,10),action");
    $q->execute([$since,$until]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        if(!isset($byDate[$r['day']]))continue;
        if($r['action']==='resolve')$byDate[$r['day']]['resolvedEvents']=(int)$r['total'];
        elseif($r['action']==='reopen')$byDate[$r['day']]['reopenedEvents']=(int)$r['total'];
    }
    return array_values($byDate);
}
/**
 * Pure recommendation engine: every signal carries its numeric evidence.
 * Never infer why a task took time or how well a person performed.
 */
function az_ops_learning_recommendations(array $totals,array $patterns):array
{
    $items=[];
    $add=static function(string $key,string $title,string $evidence,string $next,int $priority,string $href)use(&$items):void{
        $items[]=['key'=>$key,'title'=>$title,'evidence'=>$evidence,'nextStep'=>$next,'priority'=>$priority,'href'=>$href];
    };
    if(($totals['overdueNow']??0)>=3)$add('overdue',
        'Review the overdue Action Center queue',
        $totals['overdueNow'].' active tasks are past their recorded due dates.',
        'Confirm source status, distribute open tasks and update due dates explicitly in the Action Center.',
        0,'admin-studio.php');
    if(($totals['reopenedTasks']??0)>=2)$add('reopened',
        'Review recurring task reopenings',
        $totals['reopenedTasks'].' distinct tasks have a reopen event in the selected period.',
        'Examine existing resolution histories for missing verification steps before revising a checklist.',
        1,'admin-studio.php');
    if(($totals['resolutionSamples']??0)>=3&&($totals['medianResolutionHours']??0)>=72)$add('slow_resolution',
        'Investigate long-running resolution cycles',
        'Median creation-to-resolution-event time is '.number_format((float)$totals['medianResolutionHours'],1).' hours across '.$totals['resolutionSamples'].' distinct observed tasks.',
        'Check repeat hand-offs and time spent waiting for human review; do not infer an individual staff member caused delays.',
        1,'admin-studio.php');
    if(($totals['created']??0)>=5 && ($totals['created']-$totals['resolvedTasks']??0)>=3)
        $add('backlog_growth','Compare new follow-ups with resolution activity',
            $totals['created'].' tasks were created and '.$totals['resolvedTasks'].' distinct tasks had a resolution event during the selected period.',
            'Review task volume and due-date capacity; these are not identical created-task cohorts.',
            2,'admin-studio.php');
    foreach($patterns as $p){
        if($p['count']<3||$p['active']<2)continue;
        $add('repeat_'.$p['key'],'Standardize recurring '.$p['label'],
            $p['count'].' tasks were created in this category; '.$p['active'].' are still active.',
            'Review common workflow checkpoints and existing source states before suggesting procedural changes.',
            2,$p['href']);
        break;
    }
    usort($items,static fn(array $a,array $b):int=>$a['priority']<=>$b['priority']?:strcmp($a['key'],$b['key']));
    return array_slice($items,0,5);
}
function az_ops_learning_report(array $user,int $days=30,?PDO $db=null,?int $now=null):array
{
    az_admin_action_assert($user);$days=az_ops_learning_days($days);
    $db??=az_db();$now??=time();
    $start=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('UTC'))
        ->setTime(0,0)->modify('-'.($days-1).' days');
    $since=$start->format('Y-m-d\TH:i:s\Z');
    $until=gmdate('Y-m-d\TH:i:s\Z',$now+1);
    $scalar=static function(string $sql,array $params=[])use($db):int{
        $q=$db->prepare($sql);$q->execute($params);return (int)$q->fetchColumn();
    };
    $created=$scalar('SELECT COUNT(*) FROM admin_action_tasks WHERE created_at>=? AND created_at<?',[$since,$until]);
    $resolvedCohort=$scalar("SELECT COUNT(*) FROM admin_action_tasks WHERE created_at>=? AND created_at<? AND state='resolved'",[$since,$until]);
    $resolvedEvents=$scalar("SELECT COUNT(*) FROM admin_action_history WHERE action='resolve' AND created_at>=? AND created_at<?",[$since,$until]);
    $resolvedTasks=$scalar("SELECT COUNT(DISTINCT task_id) FROM admin_action_history WHERE action='resolve' AND created_at>=? AND created_at<?",[$since,$until]);
    $reopenEvents=$scalar("SELECT COUNT(*) FROM admin_action_history WHERE action='reopen' AND created_at>=? AND created_at<?",[$since,$until]);
    $reopenedTasks=$scalar("SELECT COUNT(DISTINCT task_id) FROM admin_action_history WHERE action='reopen' AND created_at>=? AND created_at<?",[$since,$until]);
    $acknowledgments=$scalar("SELECT COUNT(*) FROM admin_action_history WHERE action='acknowledge' AND created_at>=? AND created_at<?",[$since,$until]);
    $openNow=$scalar("SELECT COUNT(*) FROM admin_action_tasks WHERE state IN ('open','acknowledged')");
    $overdueNow=$scalar("SELECT COUNT(*) FROM admin_action_tasks WHERE state IN ('open','acknowledged') AND due_at<?",[gmdate('Y-m-d\TH:i:s\Z',$now)]);
    $resolutionQ=$db->prepare("SELECT h.task_id,h.created_at AS event_at,t.created_at AS started_at
        FROM admin_action_history h JOIN admin_action_tasks t ON t.id=h.task_id
        WHERE h.action='resolve' AND h.created_at>=? AND h.created_at<?
        ORDER BY h.created_at ASC,h.id ASC LIMIT 5001");
    $resolutionQ->execute([$since,$until]);
    $resolutionRows=$resolutionQ->fetchAll(PDO::FETCH_ASSOC);
    $sampleTruncated=count($resolutionRows)>5000;
    $observed=[];$duration=[];
    foreach(array_slice($resolutionRows,0,5000) as $row){
        $taskId=(string)$row['task_id'];
        if(isset($observed[$taskId]))continue; // One time sample per task, not each reopening.
        $startTs=strtotime((string)$row['started_at']);$endTs=strtotime((string)$row['event_at']);
        if($startTs===false||$endTs===false||$endTs<$startTs||$endTs>$now)continue;
        $observed[$taskId]=true;$duration[]=($endTs-$startTs)/3600;
    }
    $median=az_ops_learning_median($duration);
    $cohortPct=$created>0?round(100*$resolvedCohort/$created,1):null;
    // Normalized patterns, never arbitrary stored titles, notes, names or source IDs.
    $q=$db->prepare("SELECT source_kind,source_stage,COUNT(*) AS n,
        SUM(CASE WHEN state IN ('open','acknowledged') THEN 1 ELSE 0 END) AS active
        FROM admin_action_tasks WHERE created_at>=? AND created_at<?
        GROUP BY source_kind,source_stage ORDER BY n DESC LIMIT 100");
    $q->execute([$since,$until]);$grouped=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        [$kind,$label]=az_ops_learning_family((string)$row['source_kind'],(string)$row['source_stage']);
        $key=$kind.':'.$label;
        if(!isset($grouped[$key]))$grouped[$key]=['key'=>substr(hash('sha256',$key),0,12),
            'kind'=>$kind,'label'=>$label,'count'=>0,'active'=>0,'href'=>match($kind){
                'casting'=>'casting.php','video'=>'user-submissions.php',
                'production'=>'productions.php','commerce'=>'commerce.php',
                default=>'admin-studio.php'
            }];
        $grouped[$key]['count']+=(int)$row['n'];
        $grouped[$key]['active']+=(int)$row['active'];
    }
    $patterns=array_values($grouped);
    usort($patterns,static fn($a,$b):int=>$b['count']<=>$a['count']?:$b['active']<=>$a['active']?:strcmp($a['label'],$b['label']));
    $patterns=array_slice($patterns,0,6);
    $totals=[
        'created'=>$created,'cohortResolvedNow'=>$resolvedCohort,
        'cohortResolvedPct'=>$cohortPct,'resolvedEvents'=>$resolvedEvents,'resolvedTasks'=>$resolvedTasks,
        'reopenEvents'=>$reopenEvents,'reopenedTasks'=>$reopenedTasks,'acknowledgmentEvents'=>$acknowledgments,
        'openNow'=>$openNow,'overdueNow'=>$overdueNow,
        'resolutionSamples'=>count($duration),'medianResolutionHours'=>$median,
        'sampleTruncated'=>$sampleTruncated
    ];
    $recommendations=az_ops_learning_recommendations($totals,$patterns);
    return ['role'=>'admin','periodDays'=>$days,'periodStart'=>$since,'generatedAt'=>gmdate('Y-m-d\TH:i:s\Z',$now),
        'totals'=>$totals,'trend'=>az_ops_learning_series($db,$days,$now,$since,$until),
        'patterns'=>$patterns,'recommendations'=>$recommendations,'hasEvidence'=>$created>0||$resolvedEvents>0,
        'nextStep'=>$recommendations?($recommendations[0]['title'].': '.$recommendations[0]['nextStep']):
          'No improvement threshold was met. Continue monitoring actual workflow events and reviewing the Action Center.',
        'basis'=>'Evidence from recorded admin_action_tasks and immutable admin_action_history only. Resolution counts are events or distinct tasks as labeled; cohort completion is the current status of tasks created in the selected period. Times run from original task creation to its first recorded resolution event in this period and can include reopened work. Counts are not unique customers, creative quality, applicant suitability or individual staff performance. Recommendations are deterministic signals, not autonomous model training.',
        'automatedActions'=>false];
}
function az_ops_learning_chat_snapshot(array $user,?array $full=null):array
{
    az_admin_action_assert($user);
    $full??=az_ops_learning_report($user,30);
    return ['periodDays'=>$full['periodDays'],'totals'=>$full['totals'],
        'topPatterns'=>array_slice($full['patterns'],0,3),
        'recommendations'=>array_slice($full['recommendations'],0,2),
        'nextStep'=>$full['nextStep'],'basis'=>$full['basis'],'automatedActions'=>false];
}
