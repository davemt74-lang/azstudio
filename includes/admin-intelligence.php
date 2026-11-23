<?php
declare(strict_types=1);
/**
 * Administrator-only management intelligence. Deterministic workflow triage,
 * never applicant suitability scoring, creative approval or financial execution.
 * Contact details, answers, private notes and unpublished media are excluded.
 */
function az_upgrade_admin_intelligence_schema(PDO $db): void
{
    if (az_schema_version($db)>=45 || az_schema_version($db)<44) return;
    az_prepare_storage();
    $lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if (!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Management Agent schema lock unavailable.');
    try {
        if (az_schema_version($db)>=45)return;
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $uid=$mysql?'BIGINT UNSIGNED':'INTEGER';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS admin_intelligence_deliveries (
            id CHAR(32) PRIMARY KEY, source_kind VARCHAR(20) NOT NULL,
            source_id CHAR(32) NOT NULL, recipient_id $uid NOT NULL,
            stage VARCHAR(32) NOT NULL, fingerprint CHAR(64) NOT NULL,
            created_at VARCHAR(32) NOT NULL,
            UNIQUE(source_kind,source_id,recipient_id,stage,fingerprint),
            FOREIGN KEY(recipient_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS admin_intelligence_scans (
            scope VARCHAR(80) PRIMARY KEY, completed_at VARCHAR(32) NOT NULL,
            checked_count INTEGER NOT NULL, delivered_count INTEGER NOT NULL,
            truncated INTEGER NOT NULL)$suffix");
        $db->exec('UPDATE studio_schema SET version=45 WHERE id=1');
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
/** Age alone determines follow-up urgency. Never rank a person's merit or protected traits. */
function az_admin_intelligence_rule(string $kind,array $row,int $now):?array
{
    $status=(string)($row['status']??'');
    $date=$kind==='video'?($row['submitted_at']??$row['updated_at']??''):($row['updated_at']??'');
    $ts=is_string($date)&&$date!==''?strtotime($date):false;
    if($ts===false||$ts>$now)return null;
    $hours=max(0,intdiv($now-$ts,3600));
    if($kind==='casting'){
        if($status==='new' && $hours>=24){
            $urgent=$hours>=72;
            return ['stage'=>$urgent?'new_72h':'new_24h','priority'=>$urgent?0:1,
              'title'=>$urgent?'New casting application awaiting review for 3+ days':'New casting application awaiting review',
              'detail'=>'Check the authenticated application questionnaire and decide the next workflow step. No candidate suitability or approval decision has been made.',
              'href'=>'casting.php?lead='.(string)($row['id']??''),'ageHours'=>$hours,'kind'=>'casting'];
        }
        if(in_array($status,['contacted','screening','interview'],true)&&$hours>=168){
            return ['stage'=>$status.'_7d','priority'=>1,'title'=>'Casting stage needs a follow-up',
              'detail'=>ucfirst($status).' has had no recorded update for seven days. Review the existing notes and contact history before making any change.',
              'href'=>'casting.php?lead='.(string)($row['id']??''),'ageHours'=>$hours,'kind'=>'casting'];
        }
        return null;
    }
    if($kind==='video' && $status==='pending_review' && $hours>=24){
        $urgent=$hours>=72;
        return ['stage'=>$urgent?'video_72h':'video_24h','priority'=>$urgent?0:1,
          'title'=>$urgent?'Creator video awaiting review for 3+ days':'Creator video awaiting staff review',
          'detail'=>'Review the submitted video, consent and rights in the protected submission queue. The Agent has not watched footage or approved publication.',
          'href'=>'user-submissions.php','ageHours'=>$hours,'kind'=>'video'];
    }
    return null;
}
/** Read only status/date/opaque ID fields; all detail stays in existing staff workspaces. */
function az_admin_intelligence_candidates(PDO $db,int $now,int $perSource=200):array
{
    $perSource=max(1,min(300,$perSource));$items=[];$checked=0;$truncated=false;
    $specs=[
      ['casting',"SELECT id,status,updated_at FROM casting_leads WHERE status IN ('new','contacted','screening','interview') ORDER BY updated_at ASC,id ASC LIMIT ".($perSource+1)],
      ['video',"SELECT id,status,updated_at,submitted_at FROM model_videos WHERE status='pending_review' ORDER BY submitted_at ASC,id ASC LIMIT ".($perSource+1)]
    ];
    foreach($specs as [$kind,$sql]){
        $rows=$db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>$perSource)$truncated=true;
        foreach(array_slice($rows,0,$perSource) as $row){
            $checked++;$id=(string)($row['id']??'');
            if(!az_valid_id($id))continue;
            $rule=az_admin_intelligence_rule($kind,$row,$now);
            if(!$rule)continue;
            $rule['sourceId']=$id;
            $rule['fingerprint']=hash('sha256',implode('|',[$kind,$id,$rule['stage'],(string)($row['status']??''),(string)($row['updated_at']??''),(string)($row['submitted_at']??'')]));
            $items[]=$rule;
        }
    }
    usort($items,static fn($a,$b):int=>($a['priority']<=>$b['priority'])?:($b['ageHours']<=>$a['ageHours'])?:strcmp($a['sourceId'],$b['sourceId']));
    return ['items'=>$items,'checked'=>$checked,'truncated'=>$truncated];
}
/** Pure prioritization of already-recorded operational facts. Nothing is executed. */
function az_admin_intelligence_priorities(array $dashboard,array $candidates):array
{
    $actions=[];
    foreach(($candidates['items']??[]) as $item){
        $actions[]=['category'=>$item['kind'],'title'=>$item['title'],'detail'=>$item['detail'],
          'href'=>$item['href'],'priority'=>$item['priority'],'ageHours'=>$item['ageHours'],'count'=>1,
            'sourceKind'=>$item['kind'],'sourceId'=>$item['sourceId'],'sourceStage'=>$item['stage']];
    }
    $make=static function(int $count,string $category,string $title,string $detail,string $href,int $priority)use(&$actions):void{
        if($count>0){
            $stage=match($href){
                'commerce.php?tab=providers'=>'provider_exception',
                'commerce.php?tab=orders'=>'manual_orders',
                'commerce.php?tab=payouts'=>'payout_batches',
                'user-submissions.php'=>'video_queue',
                'casting.php?status=new'=>'casting_queue',
                default=>'other'
            };
            $actions[]=['category'=>$category,'title'=>$title,'detail'=>$detail,
                'href'=>$href,'priority'=>$priority,'count'=>$count,
                'sourceKind'=>'queue',
                'sourceId'=>substr(hash('sha256','studio_management_queue:'.$stage),0,32),
                'sourceStage'=>$stage];
        }
    };
    $make((int)($dashboard['pendingProviders']??0),'commerce','Reconcile original provider operations',
        'Resolve uncertain refunds, return labels or Connect transfers using their original records before any new financial request.',
        'commerce.php?tab=providers',0);
    $make((int)($dashboard['pendingOrders']??0),'commerce','Review unverified external orders',
        'Verify independent seller evidence before treating a manually recorded order as paid.',
        'commerce.php?tab=orders',1);
    $make((int)($dashboard['pendingPayouts']??0),'commerce','Review creator settlement batches',
        'Check approved batches, holds and supporting evidence. A Connect transfer is not a bank deposit.',
        'commerce.php?tab=payouts',2);
    $make((int)($dashboard['submissions']['pending']??0),'video','Review newly submitted creator videos',
        'Review explicit submission consent and rights. The Agent cannot publish, reject or score videos.',
        'user-submissions.php',2);
    $make((int)($dashboard['casting']['new']??0),'casting','Review new model applications',
        'Read screening answers in Casting CRM. Workflow age is not a judgment of applicant suitability.',
        'casting.php?status=new',2);
    usort($actions,static fn($a,$b):int=>($a['priority']<=>$b['priority'])?:strcmp($a['title'],$b['title']));
    return array_slice($actions,0,12);
}
function az_admin_intelligence_opportunities(array $dashboard):array
{
    $items=[];$checkout=$dashboard['commerce']['checkout']??[];
    $starts=max(0,(int)($checkout['started']??0));$paid=max(0,(int)($checkout['paid']??0));
    if($starts>=10 && $paid/$starts<0.5){
        $items[]=['title'=>'Investigate Stripe checkout completion','detail'=>$paid.' of '.$starts.' recorded Stripe checkout sessions completed. Inspect payment errors and checkout friction; this is not a unique-user conversion rate.',
          'href'=>'commerce.php?tab=analytics','category'=>'commerce'];
    }
    $audience=$dashboard['audience']??[];
    $plays=max(0,(int)($audience['playStarts30d']??0));$completes=max(0,(int)($audience['videoCompletions30d']??0));
    if($plays>=20 && $completes/$plays<0.25){
        $items[]=['title'=>'Review video engagement patterns','detail'=>$completes.' completions across '.$plays.' recorded play starts. Inspect playback quality and publishing presentation; no individual viewer or creative-quality judgment is inferred.',
          'href'=>'videos.php','category'=>'audience'];
    }
    $views=max(0,(int)($audience['liveProductImpressions30d']??0));
    $clicks=max(0,(int)($audience['liveProductClicks30d']??0));
    if($views>=20 && $clicks/$views<0.05){
        $items[]=['title'=>'Review live-shopping product placement','detail'=>$clicks.' CTA taps across '.$views.' session-bounded product impressions. Consider testing how the product is featured; taps are not orders.',
          'href'=>'live-director.php','category'=>'live'];
    }
    if(!$items)$items[]=['title'=>'Review studio growth signals','detail'=>'No evidence-backed opportunity threshold fired. Examine the existing Commerce and views trends before changing campaigns.',
        'href'=>'commerce.php?tab=analytics','category'=>'overview'];
    return $items;
}
function az_admin_intelligence_briefing(array $user,?array $dashboard=null,?PDO $db=null,?int $clock=null):array
{
    az_admin_command_assert($user);
    $db??=az_db();$clock??=time();
    $dashboard??=az_admin_command_data($user);
    $sample=az_admin_intelligence_candidates($db,$clock);
    $actions=az_admin_intelligence_priorities($dashboard,$sample);
    // Only admin workflow references; never applicant free text, personal traits or unpublished media.
    $safeSample=array_map(static function(array $row):array{
        unset($row['sourceId'],$row['fingerprint']);
        return $row;
    },array_slice($sample['items'],0,8));
    $counts=['newApplications'=>(int)($dashboard['casting']['new']??0),
        'pendingVideos'=>(int)($dashboard['submissions']['pending']??0),
        'providerExceptions'=>(int)($dashboard['pendingProviders']??0),
        'unverifiedOrders'=>(int)($dashboard['pendingOrders']??0),
        'agedWorkflowItems'=>count($sample['items'])];
    $first=$actions[0]??null;
    return ['generatedAt'=>az_now(),'role'=>'admin','counts'=>$counts,
      'keyFindings'=>[
        $counts['newApplications'].' new model applications require an individual workflow review.',
        $counts['pendingVideos'].' creator videos are awaiting an administrator moderation decision.',
        $counts['providerExceptions'].' unresolved financial-provider operations need original-record reconciliation.'
      ],
      'actionsFollowups'=>$actions,'recentAgeAlerts'=>$safeSample,
      'opportunities'=>az_admin_intelligence_opportunities($dashboard),
      'openQuestions'=>['Which applications and submissions require staff review first?','Has each uncertain provider transaction been independently reconciled?'],
      'nextStep'=>$first?($first['title'].': '.$first['detail']):'No urgent management exceptions; review current studio performance.',
      'questionsForYou'=>['Which recommendation would you like to investigate in the existing workspace?'],
      'truncated'=>$sample['truncated'],'observed'=>$sample['checked'],
      'automatedDecisions'=>false,
      'basis'=>'Current workflow states, elapsed time and aggregate commerce/audience records. This is operational triage, not applicant suitability scoring, AI review of private videos, payment verification or an autonomous approval system.'];
}
function az_admin_intelligence_chat_snapshot(array $user,?array $report=null):array
{
    az_admin_command_assert($user);
    $report??=az_admin_intelligence_briefing($user);
    return ['counts'=>$report['counts'],'keyFindings'=>$report['keyFindings'],
      'nextStep'=>$report['nextStep'],
      'recommendedActions'=>array_map(static fn($a)=>['title'=>$a['title'],'href'=>$a['href']],array_slice($report['actionsFollowups'],0,4)),
      'basis'=>$report['basis']];
}
/** Delivery insert and Activity notification commit together; duplicate scan is silent. */
function az_admin_intelligence_scan(?int $adminId=null,int $limit=240,?int $clock=null,?PDO $db=null):array
{
    if($adminId!==null&&$adminId<1)throw new InvalidArgumentException('Invalid staff recipient');
    $db??=az_db();$clock??=time();$limit=max(1,min(300,$limit));
    az_prepare_storage();
    $lock=fopen(AZ_STORAGE.'/admin-intelligence.lock','c+b');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new AzHttpError(409,'Management scan is already running.','scan_in_progress');
    $delivered=0;$sample=az_admin_intelligence_candidates($db,$clock,$limit);
    try{
        $recipients=$adminId===null?az_agent_workspace_admin_ids():[$adminId];
        $db->beginTransaction();
        foreach($sample['items'] as $item){
            foreach($recipients as $recipient){
                $recipient=(int)$recipient;if($recipient<1)continue;
                try{
                    $db->prepare('INSERT INTO admin_intelligence_deliveries(id,source_kind,source_id,recipient_id,stage,fingerprint,created_at) VALUES(?,?,?,?,?,?,?)')
                      ->execute([az_id(),$item['kind'],$item['sourceId'],$recipient,$item['stage'],$item['fingerprint'],az_now()]);
                }catch(PDOException $e){
                    $driver=$db->getAttribute(PDO::ATTR_DRIVER_NAME);$code=(int)($e->errorInfo[1]??0);
                    if(($driver==='mysql'&&$code===1062)||
                       ($driver==='sqlite'&&$code===19&&str_contains((string)($e->errorInfo[2]??''),'UNIQUE constraint failed')))continue;
                    throw $e;
                }
                az_agent_workspace_emit($recipient,$recipient,'admin_intelligence_'.$item['kind'],
                    $item['title'],$item['detail'].' Review current status before acting.',$item['href'],true);
                $delivered++;
            }
        }
        $scope=$adminId===null?'cron':'admin:'.$adminId;$when=az_now();
        $q=$db->prepare('SELECT scope FROM admin_intelligence_scans WHERE scope=?');
        $q->execute([$scope]);
        if($q->fetchColumn()!==false)$db->prepare('UPDATE admin_intelligence_scans SET completed_at=?,checked_count=?,delivered_count=?,truncated=? WHERE scope=?')
            ->execute([$when,$sample['checked'],$delivered,$sample['truncated']?1:0,$scope]);
        else $db->prepare('INSERT INTO admin_intelligence_scans(scope,completed_at,checked_count,delivered_count,truncated) VALUES(?,?,?,?,?)')
            ->execute([$scope,$when,$sample['checked'],$delivered,$sample['truncated']?1:0]);
        $db->commit();
        return ['checked'=>$sample['checked'],'eligible'=>count($sample['items']),
            'delivered'=>$delivered,'truncated'=>$sample['truncated'],'completedAt'=>$when,
            'note'=>'Existing Agent bell, Brain and History received age-based reminders only. No screening, publication or payment action occurred.'];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    finally{flock($lock,LOCK_UN);fclose($lock);}
}
