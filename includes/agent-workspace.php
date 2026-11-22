<?php
declare(strict_types=1);
/**
 * Role-scoped activity inbox and rule-based work briefings for the existing AZHottie Agent.
 * This is an on-demand read model, not a background autonomous agent. Mutations remain
 * inside the existing role-, roster-, revision-, and CSRF-checked Work/Review APIs.
 */
function az_upgrade_agent_workspace_schema(PDO $db): void
{
    if (az_schema_version($db) >= 29 || az_schema_version($db) < 28) return;
    az_prepare_storage();
    $lock=fopen(AZ_STORAGE . '/schema-migration.lock','c+b');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Cannot lock agent activity upgrade.');
    try {
        if (az_schema_version($db) >= 29) return;
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $uid=$mysql?'BIGINT UNSIGNED':'INTEGER';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS agent_workspace_events (
            id CHAR(32) PRIMARY KEY, recipient_id $uid NOT NULL, actor_id $uid NOT NULL,
            kind VARCHAR(48) NOT NULL, title VARCHAR(200) NOT NULL, detail VARCHAR(600) NOT NULL,
            href VARCHAR(240) NOT NULL, created_at VARCHAR(32) NOT NULL, read_at VARCHAR(32) NULL,
            FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        if ($mysql) {
            $q=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
            $q->execute(['agent_workspace_events','agent_workspace_recipient']);
            if (!(int)$q->fetchColumn()) $db->exec('CREATE INDEX agent_workspace_recipient ON agent_workspace_events (recipient_id,created_at)');
        } else $db->exec('CREATE INDEX IF NOT EXISTS agent_workspace_recipient ON agent_workspace_events (recipient_id,created_at)');
        $db->exec('UPDATE studio_schema SET version=29 WHERE id=1');
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}

function az_agent_workspace_available(array $user): bool
{
    return ($user['status']??'active')==='active' && (az_has_role($user,'model') || az_has_role($user,'admin'));
}
function az_agent_workspace_assert(array $user): void
{
    if (!az_agent_workspace_available($user)) throw new AzHttpError(403,'Your account does not have access to the agent workspace.','role_required');
}
/** No external URLs or user-controlled raw HTML may be persisted as navigation targets. */
function az_agent_workspace_href(string $href): string
{
    if (!preg_match('~\A(?:my-videos|my-work|user-submissions|productions|admin-studio|agent-chat|casting|account|live-director|calendar|appointments)\.php(?:\?[A-Za-z0-9_=%&.-]{1,120})?\z~D',$href))
        throw new InvalidArgumentException('Agent activity contains an invalid destination.');
    return $href;
}
function az_agent_workspace_emit(int $actor,int $recipient,string $kind,string $title,string $detail,string $href,bool $forceUnread=false): void
{
    if ($recipient<1 || $actor<1) return;
    // Own actions belong in History without creating an unread alert. Actions taken
    // by another person (e.g. admin review or a new assignment) remain unread.
    $created=az_now();$read=(!$forceUnread && $actor===$recipient)?$created:null;
    $q=az_db()->prepare('INSERT INTO agent_workspace_events(id,recipient_id,actor_id,kind,title,detail,href,created_at,read_at) VALUES(?,?,?,?,?,?,?,?,?)');
    $q->execute([az_id(),$recipient,$actor,substr($kind,0,48),mb_safe_trim($title,200),mb_safe_trim($detail,600),az_agent_workspace_href($href),$created,$read]);
}
function az_agent_workspace_admin_ids(): array
{
    $q=az_db()->query("SELECT DISTINCT u.id FROM users u JOIN user_roles r ON r.user_id=u.id AND r.role='admin' WHERE u.status='active'");
    return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
}
/** Event delivery failure must not roll back a video the user already saved. */
function az_agent_workspace_video_event(int $actor,int $owner,string $kind,string $title,string $detail,string $videoId,bool $notifyAdmins): void
{
    if (!function_exists('az_agent_workspace_emit')) return;
    try {
        $modelHref=$kind==='video_deleted'?'my-videos.php':'my-videos.php?videoId='.rawurlencode($videoId);
        az_agent_workspace_emit($actor,$owner,$kind,$title,$detail,$modelHref);
        if ($notifyAdmins) {
            foreach (az_agent_workspace_admin_ids() as $admin) {
                if ($admin===$owner) continue;
                az_agent_workspace_emit($actor,$admin,$kind,$title,$detail,'user-submissions.php');
            }
        }
    } catch (Throwable $e) {error_log('Agent activity delivery error: '.$e->getMessage());}
}
/** Called from the canonical production event writer after an actual work mutation. */
function az_agent_workspace_work_event(string $projectId,int $actor,string $kind,string $message,?string $assignmentId): void
{
    try {
        $q=az_db()->prepare('SELECT owner_id FROM production_projects WHERE id=?');$q->execute([$projectId]);
        $owner=(int)($q->fetchColumn()?:0);
        $assigned=0;
        if ($assignmentId) {$q=az_db()->prepare('SELECT user_id FROM shot_assignments WHERE id=?');$q->execute([$assignmentId]);$assigned=(int)($q->fetchColumn()?:0);}
        // The existing work_notifications table already delivers to the other
        // participant. Keep just the actor audit here to avoid double alerts.
        $hrefAdmin='productions.php?'.($assignmentId?'assignmentId='.rawurlencode($assignmentId):'projectId='.rawurlencode($projectId));
        $hrefMember='my-work.php?'.($assignmentId?'assignmentId='.rawurlencode($assignmentId):'projectId='.rawurlencode($projectId));
        az_agent_workspace_emit($actor,$actor,$kind,'Production update',$message,$actor===$owner?$hrefAdmin:$hrefMember);
    } catch (Throwable $e) {error_log('Agent production event error: '.$e->getMessage());}
}

/** Existing production notifications are included, preserving legacy unread states. */
function az_agent_workspace_work_history(array $user): array
{
    if (!function_exists('az_work_notifications')) return [];
    $work=az_work_notifications($user);
    $admin=az_has_role($user,'admin');$items=[];
    foreach ($work['notifications'] as $r) {
        $href=($admin?'productions.php?':'my-work.php?').(!empty($r['assignmentId'])?'assignmentId='.rawurlencode($r['assignmentId']):'projectId='.rawurlencode($r['projectId']));
        $items[]=['id'=>'work:'.$r['id'],'kind'=>$r['kind'],'title'=>$r['title'],'detail'=>$r['message'],
          'href'=>$href,'createdAt'=>$r['createdAt'],'readAt'=>$r['readAt'],'actor'=>'Production team'];
    }
    return $items;
}
function az_agent_workspace_history(int $recipient): array
{
    $q=az_db()->prepare('SELECT e.id,e.kind,e.title,e.detail,e.href,e.created_at,e.read_at,e.actor_id,u.name AS actor_name FROM agent_workspace_events e JOIN users u ON u.id=e.actor_id WHERE e.recipient_id=? ORDER BY e.created_at DESC,e.id DESC LIMIT 100');
    $q->execute([$recipient]);
    return array_map(static fn($r):array=>[
        'id'=>$r['id'],'kind'=>$r['kind'],'title'=>$r['title'],'detail'=>$r['detail'],
        'href'=>$r['href'],'createdAt'=>$r['created_at'],'readAt'=>$r['read_at'],
        'actor'=>(str_starts_with($r['kind'],'followthrough_')||str_starts_with($r['kind'],'casting_review_')||str_starts_with($r['kind'],'casting_new_')||str_starts_with($r['kind'],'casting_contacted_')||str_starts_with($r['kind'],'casting_screening_')||str_starts_with($r['kind'],'casting_interview_')||str_starts_with($r['kind'],'casting_onboarding_incomplete')||str_starts_with($r['kind'],'casting_invitation_missing')||str_starts_with($r['kind'],'casting_invitation_expiring'))?'AZHottie Agent':((int)$r['actor_id']===$recipient?'You':$r['actor_name']),
    ],$q->fetchAll());
}
function az_agent_workspace_mark_read(int $recipient,array $body): void
{
    if (($body['all']??false) === true) {
        $q=az_db()->prepare('UPDATE agent_workspace_events SET read_at=? WHERE recipient_id=? AND read_at IS NULL');
        $q->execute([az_now(),$recipient]);
        $q=az_db()->prepare('UPDATE work_notifications SET read_at=? WHERE recipient_id=? AND read_at IS NULL');
        $q->execute([az_now(),$recipient]);return;
    }
    $ids=$body['ids']??null;
    if (!is_array($ids)||!array_is_list($ids)||count($ids)<1||count($ids)>100) throw new AzHttpError(400,'Select up to 100 activity items.','invalid_items');
    $q=az_db()->prepare('UPDATE agent_workspace_events SET read_at=? WHERE id=? AND recipient_id=? AND read_at IS NULL');
    $work=az_db()->prepare('UPDATE work_notifications SET read_at=? WHERE id=? AND recipient_id=? AND read_at IS NULL');
    foreach ($ids as $id) {
        if (!is_string($id))throw new AzHttpError(400,'Invalid activity ID.','invalid_items');
        if (str_starts_with($id,'work:')&&az_valid_id(substr($id,5))) $work->execute([az_now(),substr($id,5),$recipient]);
        elseif(az_valid_id($id)) $q->execute([az_now(),$id,$recipient]);
        else throw new AzHttpError(400,'Invalid activity ID.','invalid_items');
    }
}
function az_agent_workspace_card(string $kind,string $title,string $detail,string $href,int $priority=4,?array $action=null): array
{
    return ['kind'=>$kind,'title'=>$title,'detail'=>$detail,'href'=>$href,'priority'=>$priority,'action'=>$action];
}
/** Source-of-truth suggestions, recomputed on GET. Never assert a worker analyzed private footage. */
function az_agent_workspace_brain(array $user): array
{
    $id=(int)$user['id'];$admin=az_has_role($user,'admin');$cards=[];$now=az_now();$soon=gmdate('Y-m-d\TH:i:s\Z',time()+7*86400);
    if ($admin) {
        $q=az_db()->query("SELECT COUNT(*) FROM model_videos WHERE status='pending_review'");$pending=(int)$q->fetchColumn();
        if ($pending) $cards[]=az_agent_workspace_card('review','Review '.$pending.' model '.($pending===1?'video':'videos'), 'These videos are submitted with consent and are waiting for an admin decision.','user-submissions.php',1);
        $q=az_db()->prepare("SELECT COUNT(*) FROM shot_assignments a JOIN production_shots s ON s.id=a.shot_id JOIN production_projects p ON p.id=s.project_id WHERE p.owner_id=? AND a.status='submitted' AND p.status='active'");$q->execute([$id]);$submitted=(int)$q->fetchColumn();
        if ($submitted) $cards[]=az_agent_workspace_card('review','Review '.$submitted.' submitted '.($submitted===1?'assignment':'assignments'),'Footage is awaiting a production review.','productions.php',1);
        $q=az_db()->prepare("SELECT COUNT(*) FROM shot_assignments a JOIN production_shots s ON s.id=a.shot_id JOIN production_projects p ON p.id=s.project_id WHERE p.owner_id=? AND a.status='assigned' AND p.status='active'");$q->execute([$id]);$waiting=(int)$q->fetchColumn();
        if ($waiting) $cards[]=az_agent_workspace_card('followup',$waiting.' assignments awaiting a response','Check in with contributors who have not accepted or declined their work.','productions.php',2);
        $q=az_db()->prepare("SELECT COUNT(*) FROM shot_assignments a JOIN production_shots s ON s.id=a.shot_id JOIN production_projects p ON p.id=s.project_id WHERE p.owner_id=? AND p.status='active' AND a.status IN ('accepted','changes_requested') AND s.due_at IS NOT NULL AND s.due_at<?");$q->execute([$id,$now]);$late=(int)$q->fetchColumn();
        if ($late) $cards[]=az_agent_workspace_card('deadline',$late.' overdue active assignments','Review production deadlines and contact the contributors.','productions.php',2);
        // Specific, current action cards. A follow-up recommendation is never an assignment mutation.
        $q=az_db()->prepare("SELECT a.id,a.revision,a.status,a.updated_at,s.title,s.due_at,p.title AS project_title,u.name AS model_name
            FROM shot_assignments a JOIN production_shots s ON s.id=a.shot_id AND s.status='active'
            JOIN production_projects p ON p.id=s.project_id AND p.status='active'
            JOIN production_members m ON m.project_id=p.id AND m.user_id=a.user_id AND m.status='active'
            JOIN users u ON u.id=a.user_id AND u.status='active'
            WHERE p.owner_id=? AND a.status IN ('assigned','accepted','changes_requested')
            ORDER BY s.due_at,a.updated_at LIMIT 60");
        $q->execute([$id]);$specific=0;
        foreach($q->fetchAll() as $a) {
            $wait=strtotime($a['updated_at']);$due=strtotime((string)($a['due_at']??''));
            $unanswered=$a['status']==='assigned'&&$wait!==false&&$wait<=time()-86400;
            $isLate=in_array($a['status'],['accepted','changes_requested'],true)&&$due!==false&&$due<=time();
            if(!$unanswered && !$isLate)continue;
            $reason=$isLate?'Deadline passed':'No response for over 24 hours';
            $cards[]=az_agent_workspace_card('followup',($isLate?'Overdue: ':'Follow up: ').$a['title'],
              $a['model_name'].' · '.$a['project_title'].' · '.$reason.'. Confirm before sending a reminder.',
              'productions.php?assignmentId='.rawurlencode($a['id']),1,
              ['type'=>'remind','id'=>$a['id'],'revision'=>(int)$a['revision']]);
            if(++$specific>=5)break;
        }
        $assignmentChoices=az_agent_workspace_assign_options($user);
        if ($assignmentChoices) $cards[]=az_agent_workspace_card('assign','Assign available production shots','Choose an eligible roster member. Assignment is sent only after you confirm.','productions.php',3);
        $summary=['pendingReviews'=>$pending,'submittedAssignments'=>$submitted,'awaitingResponses'=>$waiting,'overdue'=>$late];
    } else {
        $q=az_db()->prepare("SELECT a.id,a.revision,a.status,s.id AS shot_id,s.title,s.due_at,p.title AS project_title FROM shot_assignments a JOIN production_shots s ON s.id=a.shot_id JOIN production_projects p ON p.id=s.project_id JOIN users owner ON owner.id=p.owner_id JOIN production_members m ON m.project_id=p.id AND m.user_id=a.user_id AND m.status='active' WHERE a.user_id=? AND p.status='active' AND s.status='active' AND owner.status='active' AND a.status IN ('assigned','accepted','changes_requested','submitted') ORDER BY CASE a.status WHEN 'changes_requested' THEN 0 WHEN 'assigned' THEN 1 WHEN 'accepted' THEN 2 ELSE 3 END,s.due_at,a.updated_at DESC LIMIT 60");$q->execute([$id]);
        $assigned=0;$underReview=0;$overdue=0;
        foreach ($q->fetchAll() as $a) {
            $href='my-work.php?assignmentId='.rawurlencode($a['id']);
            if($a['status']==='assigned') {
                $assigned++;
                $cards[]=az_agent_workspace_card('assignment','Respond to: '.$a['title'],'Assignment for '.$a['project_title'].'. Review the brief before accepting or declining.',$href,1,['type'=>'respond','id'=>$a['id'],'revision'=>(int)$a['revision']]);
            } elseif($a['status']==='changes_requested') $cards[]=az_agent_workspace_card('revision','Retake requested: '.$a['title'],'A producer needs another take. Open the assignment to review feedback.',$href,1);
            elseif($a['status']==='accepted' && $a['due_at'] && $a['due_at']<$now) { $overdue++;$cards[]=az_agent_workspace_card('deadline','Overdue: '.$a['title'],'The deadline has passed; open the assignment to plan the next submission.',$href,1); }
            elseif($a['status']==='accepted' && $a['due_at'] && $a['due_at']<=$soon) $cards[]=az_agent_workspace_card('deadline','Due soon: '.$a['title'],'An accepted assignment has a deadline within seven days.',$href,2);
            elseif($a['status']==='submitted')$underReview++;
        }
        $q=az_db()->prepare("SELECT id,title,status,review_note FROM model_videos WHERE user_id=? AND status IN ('changes_requested','pending_review') ORDER BY updated_at DESC LIMIT 30");$q->execute([$id]);$pending=0;
        foreach($q->fetchAll() as $video){
            if($video['status']==='changes_requested')$cards[]=az_agent_workspace_card('revision','Update your video: '.$video['title'], $video['review_note']?:'An administrator requested changes.','my-videos.php?videoId='.rawurlencode($video['id']),1);
            else $pending++;
        }
        if($pending) $cards[]=az_agent_workspace_card('review',$pending.' videos under review','You will see the admin decision here once recorded.','my-videos.php',4);
        if($underReview) $cards[]=az_agent_workspace_card('review',$underReview.' assignments submitted','These assignments are awaiting producer review.','my-work.php',4);
        $q=az_db()->prepare("SELECT s.id,s.title,s.due_at,p.title AS project_title,s.max_contributors,s.eligible_roles_json,p.owner_id, (SELECT COUNT(*) FROM shot_assignments a WHERE a.shot_id=s.id AND a.user_id<>p.owner_id AND a.status NOT IN ('declined','cancelled')) AS claimed FROM production_shots s JOIN production_projects p ON p.id=s.project_id JOIN production_members m ON m.project_id=p.id AND m.user_id=? AND m.status='active' JOIN users owner ON owner.id=p.owner_id WHERE s.mode='open' AND s.status='active' AND p.status='active' AND owner.status='active' AND NOT EXISTS (SELECT 1 FROM shot_assignments a WHERE a.shot_id=s.id AND a.user_id=? AND a.status NOT IN ('declined','cancelled')) ORDER BY s.due_at,s.id LIMIT 35");$q->execute([$id,$id]);$open=0;
        foreach($q->fetchAll() as $shot)if((int)$shot['claimed']<(int)$shot['max_contributors']&&az_work_eligible($shot,$user)){
            $open++;
            if($open<=3)$cards[]=az_agent_workspace_card('opportunity','Available shot: '.$shot['title'], $shot['project_title'].' · Claim this shot only if you can complete it.','my-work.php?shotId='.rawurlencode($shot['id']),3,['type'=>'claim','id'=>$shot['id']]);
        }
        $assignmentChoices=[];$summary=['awaitingResponse'=>$assigned,'submittedAssignments'=>$underReview,'overdue'=>$overdue,'videosUnderReview'=>$pending,'availableShots'=>$open];
    }
    $schedule=az_agent_schedule_briefing($user);
    foreach($schedule['cards'] as $item)$cards[]=$item;
    $summary=array_merge($summary,['upcomingSchedule'=>$schedule['counts']['upcoming'],'scheduleConflicts'=>$schedule['counts']['conflicts']]);
    $casting=az_agent_casting_briefing($user,$admin?$assignmentChoices:[]);
    foreach($casting['cards'] as $item)$cards[]=$item;
    $summary=array_merge($summary,$casting['counts']);
    usort($cards,static fn($a,$b)=>$a['priority']<=>$b['priority'] ?: strcmp($a['title'],$b['title']));
    $liveHealth=$admin?az_live_agent_status($user):null;
    if ($admin && $liveHealth['state']==='attention') {
        foreach ($liveHealth['issues'] as $issue) {
            array_unshift($cards,az_agent_workspace_card('live_health',$issue['title'],
                $issue['detail'].' '.$issue['advice'],'live-director.php',0));
        }
    }
    if ($admin) $summary['liveAlerts']=count($liveHealth['issues']??[]);
    $followthrough=['cron'=>az_agent_followthrough_status(), 'manual'=>$admin?az_agent_followthrough_status($id):null];
    return ['cards'=>array_slice($cards,0,16),'summary'=>$summary,'assignOptions'=>$assignmentChoices,'followthrough'=>$followthrough,
      'liveHealth'=>$liveHealth,'casting'=>$casting,'scheduling'=>$schedule,'generatedAt'=>$now,'basis'=>'Verified assignment, casting, scheduling, onboarding, review and live-health data. Casting decisions require an administrator; production matches require explicit confirmation. Scheduled reminders require private cron.'];
}
/** The only choices offered are owned active shots and eligible active roster members. */
function az_agent_workspace_assign_options(array $user): array
{
    if (!az_has_role($user,'admin'))return[];
    $id=(int)$user['id'];
    $q=az_db()->prepare("SELECT s.id,s.title,s.project_id,s.eligible_roles_json,s.max_contributors,p.title AS project_title,(SELECT COUNT(*) FROM shot_assignments a WHERE a.shot_id=s.id AND a.user_id<>p.owner_id AND a.status NOT IN ('declined','cancelled')) AS claimed FROM production_shots s JOIN production_projects p ON p.id=s.project_id WHERE p.owner_id=? AND p.status='active' AND s.status='active' ORDER BY s.updated_at DESC,s.id DESC LIMIT 25");
    $q->execute([$id]);$options=[];$accountCache=[];
    $memberQuery=az_db()->prepare("SELECT u.id,u.name FROM production_members m JOIN users u ON u.id=m.user_id WHERE m.project_id=? AND m.status='active' AND u.status='active' AND u.id<>? ORDER BY u.name,u.id LIMIT 50");
    $existingQuery=az_db()->prepare("SELECT status FROM shot_assignments WHERE shot_id=? AND user_id=?");
    foreach($q->fetchAll() as $shot){
        if((int)$shot['claimed']>=(int)$shot['max_contributors'])continue;
        $memberQuery->execute([$shot['project_id'],$id]);$models=[];
        foreach($memberQuery->fetchAll() as $r){
            if(!array_key_exists((int)$r['id'],$accountCache))$accountCache[(int)$r['id']]=az_account_user((int)$r['id']);
            $member=$accountCache[(int)$r['id']];
            if(!$member||!az_work_eligible($shot,$member))continue;
            $existingQuery->execute([$shot['id'],$r['id']]);$old=$existingQuery->fetchColumn();
            if($old!==false&&!in_array($old,['declined','cancelled'],true))continue;
            $models[]=['id'=>(int)$r['id'],'name'=>$r['name']];
        }
        if($models)$options[]=['shotId'=>$shot['id'],'label'=>$shot['project_title'].' · '.$shot['title'],'models'=>$models];
        if(count($options)>=12)break;
    }
    return $options;
}
function az_agent_workspace_unread_count(array $user): int
{
    $q=az_db()->prepare('SELECT COUNT(*) FROM agent_workspace_events WHERE recipient_id=? AND read_at IS NULL');
    $q->execute([(int)$user['id']]);
    return (int)$q->fetchColumn()+az_work_notifications($user)['unreadCount'];
}
function az_agent_workspace_state(array $user): array
{
    az_agent_workspace_assert($user);
    $events=array_merge(az_agent_workspace_history((int)$user['id']),az_agent_workspace_work_history($user));
    usort($events,static fn($a,$b)=>strcmp($b['createdAt'],$a['createdAt']) ?: strcmp($b['id'],$a['id']));
    $unread=array_values(array_filter($events,static fn($e)=>$e['readAt']===null));
    $unreadCount=az_agent_workspace_unread_count($user);
    return ['role'=>az_has_role($user,'admin')?'admin':'model','unreadCount'=>$unreadCount,'notifications'=>array_slice($unread,0,40),'history'=>array_slice($events,0,100),'brain'=>az_agent_workspace_brain($user),'checkedAt'=>az_now()];
}
