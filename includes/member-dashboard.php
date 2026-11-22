<?php
declare(strict_types=1);
/** Member dashboard: aggregated, owner-scoped activity. No fixture numbers or
 * private production content is serialized into other accounts' dashboards.
 * Profile tracking is intentionally aggregate-only: no IP, UA or visitor ID.
 */
function az_upgrade_member_dashboard_schema(PDO $db): void
{
    if (az_schema_version($db) >= 27 || az_schema_version($db) < 26) return;
    az_prepare_storage();
    $lock = fopen(AZ_STORAGE . '/schema-migration.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Could not lock member analytics migration.');
    try {
        if (az_schema_version($db) >= 27) return;
        $owner = $db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? 'BIGINT UNSIGNED' : 'INTEGER';
        $suffix = $db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $db->exec("CREATE TABLE IF NOT EXISTS member_profile_views_daily (
            user_id $owner NOT NULL, view_day CHAR(10) NOT NULL, view_count BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (user_id,view_day), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        $db->exec('UPDATE studio_schema SET version=27 WHERE id=1');
    } finally { flock($lock,LOCK_UN);fclose($lock); }
}
/** Count at most one page view per browser session per profile per 30 minutes.
 * This is a page-view trend, NOT unique visitors or verified human reach.
 * Never block a public profile if analytics storage is temporarily unavailable.
 */
function az_member_profile_record_view(array $target, array $profile, ?array $viewer): void
{
    if (($profile['visibility']??'private')==='private' || !az_social_visible($profile,$viewer,$target)) return;
    if ($viewer && ((int)$viewer['id']===(int)$target['id'] || az_has_role($viewer,'admin'))) return;
    if (strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET') return;
    $ua=(string)($_SERVER['HTTP_USER_AGENT']??'');
    if (preg_match('/bot|crawler|spider|slurp|preview|headless|lighthouse/i',$ua)) return;
    if (!empty($_SERVER['HTTP_PURPOSE']) || !empty($_SERVER['HTTP_SEC_PURPOSE'])) return;
    $id=(int)$target['id'];$now=time();
    if (!isset($_SESSION) || !is_array($_SESSION)) return;
    $last=(int)($_SESSION['azh_profile_view_window'][$id]??0);
    if ($last>0 && $now-$last<1800) return;
    try {
        $db=az_db();$day=gmdate('Y-m-d',$now);
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $sql=$mysql
            ? 'INSERT INTO member_profile_views_daily(user_id,view_day,view_count) VALUES(?,?,1) ON DUPLICATE KEY UPDATE view_count=view_count+1'
            : 'INSERT INTO member_profile_views_daily(user_id,view_day,view_count) VALUES(?,?,1) ON CONFLICT(user_id,view_day) DO UPDATE SET view_count=view_count+1';
        $db->prepare($sql)->execute([$id,$day]);
        $_SESSION['azh_profile_view_window'][$id]=$now;
        if (count($_SESSION['azh_profile_view_window'])>100) $_SESSION['azh_profile_view_window']=[$id=>$now];
    } catch(Throwable $error) {
        error_log('AZHottie profile analytics unavailable: '.get_class($error));
    }
}
function az_dash_count(string $sql,array $params): int
{
    $q=az_db()->prepare($sql);$q->execute($params);return (int)$q->fetchColumn();
}
function az_dash_rows(string $sql,array $params): array
{
    $q=az_db()->prepare($sql);$q->execute($params);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function az_dash_date(string $utc,string $format='D, M j · g:i A'): string
{
    try { return (new DateTimeImmutable($utc))->setTimezone(new DateTimeZone('America/Phoenix'))->format($format); }
    catch(Throwable) { return ''; }
}
/** Deadline classifications are based on saved UTC instants, formatted to Arizona time.
 * A submitted assignment is awaiting producer review, not overdue work. */
function az_dash_deadline_info(?string $value, ?DateTimeImmutable $clock=null): ?array
{
    if (!$value) return null;
    try {
        $due=new DateTimeImmutable($value);
        $clock ??= new DateTimeImmutable('now',new DateTimeZone('UTC'));
    } catch(Throwable) { return null; }
    $seconds=$due->getTimestamp()-$clock->getTimestamp();
    $state=$seconds<0?'overdue':($seconds<=172800?'soon':($seconds<=604800?'week':'later'));
    $local=$due->setTimezone(new DateTimeZone('America/Phoenix'));
    $label=match($state){'overdue'=>'Overdue','soon'=>'Due within 48 hours','week'=>'Due this week',default=>'Upcoming'};
    return ['state'=>$state,'label'=>$label,'dueAt'=>$value,'timestamp'=>$due->getTimestamp(),
        'dateText'=>$local->format('M j, g:i A').' MST'];
}

/** Pure presentation selector. Already owner-scoped rows must be supplied from
 * az_dashboard_data; do not feed arbitrary untrusted project rows. */
function az_dash_priority_actions(array $assignments,array $modelVideos,int $adminPending=0,?DateTimeImmutable $clock=null): array
{
    $clock??=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $actions=[];
    foreach($assignments as $a) {
        $state=$a['status'];$deadline=az_dash_deadline_info($a['due_at']??null,$clock);
        if($state==='submitted') continue; // Awaiting review, not a member action.
        $priority=null;$label='';$detail='';
        if($state==='changes_requested') {$priority=0;$label='Changes requested';$detail='Review the producer’s feedback and submit a new take.';}
        elseif($state==='accepted' && $deadline && $deadline['state']==='overdue') {$priority=1;$label='Overdue assignment';$detail='This shot has passed its deadline.';}
        elseif($state==='assigned') {$priority=2;$label='Awaiting response';$detail='Accept or decline the assigned shot.';}
        elseif($state==='accepted' && $deadline && $deadline['state']==='soon') {$priority=4;$label='Due soon';$detail='Finish and submit your assigned shot.';}
        if($priority===null) continue;
        $actions[]=['priority'=>$priority,'timestamp'=>$deadline['timestamp']??0,
            'title'=>(string)$a['shot_title'],'context'=>(string)$a['project_title'],
            'detail'=>$detail,'label'=>$label,'href'=>'my-work.php?assignmentId='.rawurlencode((string)$a['id']),
            'tone'=>$priority<=1?'urgent':($priority===4?'soon':'notice')];
    }
    foreach($modelVideos as $video) {
        if($video['status']!=='changes_requested') continue;
        $actions[]=['priority'=>0,'timestamp'=>0,'title'=>(string)$video['title'],
            'context'=>'My Videos · Admin review','detail'=>'Edit your video and resubmit for approval.',
            'label'=>'Changes requested','href'=>'my-videos.php?videoId='.rawurlencode((string)$video['id']),'tone'=>'urgent'];
    }
    if($adminPending>0) $actions[]=['priority'=>3,'timestamp'=>0,'title'=>$adminPending.' video '.($adminPending===1?'submission':'submissions').' awaiting review',
        'context'=>'Admin Studio','detail'=>'Review submitted videos and record a decision.',
        'label'=>'Admin review','href'=>'user-submissions.php','tone'=>'notice'];
    usort($actions,static fn($a,$b)=>($a['priority']<=>$b['priority']) ?: ($a['timestamp']<=>$b['timestamp']) ?: strcmp($a['title'],$b['title']));
    return $actions;
}

function az_dash_deadline_list(array $assignments,?DateTimeImmutable $clock=null): array
{
    $rows=[];$overdue=0;$next7=0;
    foreach($assignments as $assignment) {
        if(!in_array($assignment['status'],['accepted','assigned','changes_requested'],true))continue;
        $due=az_dash_deadline_info($assignment['due_at']??null,$clock);
        if(!$due)continue;
        if($due['state']==='overdue')$overdue++;
        elseif(in_array($due['state'],['soon','week'],true))$next7++;
        $rows[]=$assignment+['deadline'=>$due];
    }
    usort($rows,static fn($a,$b)=>($a['deadline']['timestamp']<=>$b['deadline']['timestamp']) ?: strcmp($a['id'],$b['id']));
    return ['items'=>array_slice($rows,0,5),'total'=>count($rows),'overdue'=>$overdue,'next7Days'=>$next7];
}

function az_dashboard_data(array $user): array
{
    $id=(int)$user['id']; $creator=az_has_role($user,'admin')||az_has_role($user,'model')||az_has_role($user,'professional');
    $isAdmin=az_has_role($user,'admin'); $isModel=az_has_role($user,'model');
    $since=gmdate('Y-m-d',strtotime('-29 days'));$now=az_now();
    $views=az_dash_rows('SELECT COALESCE(SUM(view_count),0) AS total,COALESCE(SUM(CASE WHEN view_day>=? THEN view_count ELSE 0 END),0) AS recent FROM member_profile_views_daily WHERE user_id=?',[$since,$id])[0];
    $published=az_dash_rows("SELECT COUNT(DISTINCT i.id) AS total,MAX(v.fan_published_at) AS latest
        FROM model_profiles mp JOIN finished_videos v ON v.model_id=mp.id
        JOIN interviews i ON i.id=v.interview_id JOIN users u ON u.id=i.owner_id
        WHERE mp.linked_user_id=? AND mp.status='active' AND ".az_public_video_conditions(),[$id])[0];
    $videos=['published'=>0,'draft'=>0,'pending_review'=>0,'changes_requested'=>0];
    if ($isModel || $isAdmin) {
        foreach(az_dash_rows('SELECT status,COUNT(*) AS total FROM model_videos WHERE user_id=? GROUP BY status',[$id]) as $row) {
            if (array_key_exists($row['status'],$videos)) $videos[$row['status']]=(int)$row['total'];
        }
    }
    $result=['metrics'=>[
        'profileViews'=>(int)$views['total'],'profileViews30d'=>(int)$views['recent'],
        'publishedInterviews'=>(int)$published['total'],'lastInterviewPublishedAt'=>$published['latest'],
        'liveAppearances'=>null, // Live rooms do not currently associate model participants with a broadcast.
        'publishedModelVideos'=>$videos['published'],'pendingModelVideos'=>$videos['pending_review'],
    ], 'productions'=>[],'productionsCount'=>0,'assignments'=>[],'assignmentsCount'=>0,
       'openShots'=>[],'openShotsCount'=>0,'schedule'=>[],'scheduleCount'=>0,'videos'=>$videos,
       'deadlines'=>['items'=>[],'total'=>0,'overdue'=>0,'next7Days'=>0],
       'actionItems'=>[],'actionCount'=>0,'engagement'=>az_member_engagement_summary($id)];
    if ($creator) {
        // Both administrator-owned projects and roster members' projects are scoped to this account.
        $productionRows=az_dash_rows("SELECT p.id,p.title,p.kind,p.status,p.updated_at FROM production_projects p JOIN users owner ON owner.id=p.owner_id
            WHERE owner.status='active' AND p.status='active' AND (p.owner_id=? OR EXISTS(
              SELECT 1 FROM production_members pm WHERE pm.project_id=p.id AND pm.user_id=? AND pm.status='active'))
            ORDER BY p.updated_at DESC,p.id DESC",[$id,$id]);
        $result['productionsCount']=count($productionRows);
        $result['productions']=array_slice($productionRows,0,3);
        $assignmentRows=az_dash_rows("SELECT a.id,a.status,a.updated_at,s.title AS shot_title,s.due_at,p.title AS project_title
            FROM shot_assignments a JOIN production_shots s ON s.id=a.shot_id JOIN production_projects p ON p.id=s.project_id
            JOIN users owner ON owner.id=p.owner_id
            WHERE a.user_id=? AND p.status='active' AND s.status='active' AND owner.status='active'
              AND a.status IN ('assigned','accepted','submitted','changes_requested')
              AND (p.owner_id=? OR EXISTS(SELECT 1 FROM production_members pm
                  WHERE pm.project_id=p.id AND pm.user_id=? AND pm.status='active'))
            ORDER BY CASE a.status WHEN 'changes_requested' THEN 0 WHEN 'assigned' THEN 1 WHEN 'accepted' THEN 2 ELSE 3 END,
                CASE WHEN s.due_at IS NULL THEN 1 ELSE 0 END,s.due_at,a.updated_at DESC,a.id DESC",[$id,$id,$id]);
        $result['assignmentsCount']=count($assignmentRows);
        $result['assignments']=array_slice($assignmentRows,0,4);
        $result['deadlines']=az_dash_deadline_list($assignmentRows);
        $result['actionItems']=az_dash_priority_actions($assignmentRows,[],0);
        if (az_has_role($user,'model') || az_has_role($user,'professional')) {
            $shots=az_dash_rows("SELECT s.id,s.title,s.due_at,s.max_contributors,s.eligible_roles_json,p.title AS project_title,
                    (SELECT COUNT(*) FROM shot_assignments a2 WHERE a2.shot_id=s.id AND a2.user_id<>p.owner_id AND a2.status NOT IN ('declined','cancelled')) AS claimed
                FROM production_shots s JOIN production_projects p ON p.id=s.project_id
                JOIN users owner ON owner.id=p.owner_id
                JOIN production_members pm ON pm.project_id=p.id AND pm.user_id=? AND pm.status='active'
                WHERE owner.status='active' AND p.status='active' AND s.status='active' AND s.mode='open'
                  AND NOT EXISTS(SELECT 1 FROM shot_assignments a WHERE a.shot_id=s.id AND a.user_id=?
                    AND a.status NOT IN ('declined','cancelled'))
                ORDER BY CASE WHEN s.due_at IS NULL THEN 1 ELSE 0 END,s.due_at,s.sort_order,s.id",[$id,$id]);
            foreach($shots as $shot) {
                if (!az_work_eligible($shot,$user) || (int)$shot['claimed']>=(int)$shot['max_contributors']) continue;
                $result['openShotsCount']++;
                if (count($result['openShots'])<3) $result['openShots'][]=$shot;
            }
        }
    }
    $items=[];
    // Confirmed personal bookings; professional's own client appointments count as work too.
    $appointmentSql="SELECT a.id,a.type_name,a.start_at,a.location,a.status,
            CASE WHEN a.customer_id=? THEN 'appointment' ELSE 'professional_appointment' END AS kind
        FROM schedule_appointments a WHERE a.start_at>=? AND a.status='confirmed'";
    if (az_has_role($user,'professional')) {
        $appointmentSql.=' AND (a.customer_id=? OR a.professional_id=?) ORDER BY a.start_at,a.id LIMIT 12';
        $appointments=az_dash_rows($appointmentSql,[$id,$now,$id,$id]);
    } else {
        $appointmentSql.=' AND a.customer_id=? ORDER BY a.start_at,a.id LIMIT 12';
        $appointments=az_dash_rows($appointmentSql,[$id,$now,$id]);
    }
    foreach($appointments as $booking) $items[]=['id'=>$booking['id'],'title'=>$booking['type_name'],'kind'=>$booking['kind'],
        'status'=>'confirmed','startAt'=>$booking['start_at'],'location'=>$booking['location'],'href'=>'appointments.php'];
    $events=az_dash_rows("SELECT e.*,r.status AS registration_status FROM schedule_events e
        JOIN schedule_registrations r ON r.event_id=e.id AND r.user_id=?
        WHERE e.start_at>=? AND e.status='published' AND r.status IN ('registered','waitlisted')
        ORDER BY e.start_at,e.id LIMIT 24",[$id,$now]);
    foreach($events as $event) {
        if (!az_sched_event_visible($event,$user)) continue;
        $items[]=['id'=>$event['id'],'title'=>$event['title'],'kind'=>$event['type']==='class'?'class':'event',
          'status'=>$event['registration_status'],'startAt'=>$event['start_at'],'location'=>$event['location'],
          'href'=>$event['type']==='class'?'classes.php':'calendar.php'];
    }
    if (az_has_role($user,'professional')) {
        $instructed=az_dash_rows("SELECT e.id,e.title,e.type,e.start_at,e.location FROM schedule_events e
            WHERE e.instructor_id=? AND e.status='published' AND e.start_at>=? ORDER BY e.start_at,e.id LIMIT 12",[$id,$now]);
        foreach($instructed as $event) $items[]=['id'=>$event['id'],'title'=>$event['title'],'kind'=>'instructing','status'=>'hosting',
            'startAt'=>$event['start_at'],'location'=>$event['location'],'href'=>$event['type']==='class'?'classes.php':'calendar.php'];
    }
    usort($items,static fn($a,$b)=>strcmp($a['startAt'],$b['startAt']) ?: strcmp($a['id'],$b['id']));
    // A professional can also be an attendee; don't repeat the same event.
    $unique=[];foreach($items as $item)$unique[in_array($item['kind'],['event','class','instructing'],true)?'event:'.$item['id']:$item['kind'].':'.$item['id']]??=$item;
    $result['scheduleCount']=count($unique);
    $result['schedule']=array_slice(array_values($unique),0,5);
    $needChanges=($isModel || $isAdmin) ? az_dash_rows("SELECT id,title,status,updated_at FROM model_videos WHERE user_id=? AND status='changes_requested' ORDER BY updated_at DESC,id DESC",[$id]) : [];
    $reviewPending=$isAdmin?az_dash_count("SELECT COUNT(*) FROM model_videos WHERE status='pending_review'",[]):0;
    $result['actionItems']=az_dash_priority_actions($creator?$assignmentRows:[],$needChanges,$reviewPending);
    $result['actionCount']=count($result['actionItems']);
    $result['actionItems']=array_slice($result['actionItems'],0,5);
    return $result;
}
