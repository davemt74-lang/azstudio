<?php
declare(strict_types=1);

/** Production assignments use the same mutation lock as accounts and scheduling.
 * Mutators are transaction-neutral so a proposed shot list can be applied atomically.
 * Media entry points take community.lock before an owner's recording lock.
 */
function az_upgrade_work_schema(PDO $db): void
{
    if (az_schema_version($db) >= 8 || az_schema_version($db) < 7) return;
    az_prepare_storage();
    $lock = fopen(AZ_STORAGE . '/schema-migration.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Could not lock the production database upgrade.');
    try {
        if (az_schema_version($db) >= 8) return;
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $uid = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        if ($mysql) {
            $q = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $q->execute(['interviews']); $columns = $q->fetchAll(PDO::FETCH_COLUMN);
        } else $columns = array_column($db->query('PRAGMA table_info(interviews)')->fetchAll(), 'name');
        if (!in_array('project_id', $columns, true)) $db->exec('ALTER TABLE interviews ADD COLUMN project_id CHAR(32) NULL');
        foreach ([
            "CREATE TABLE IF NOT EXISTS production_projects (id CHAR(32) PRIMARY KEY, owner_id $uid NOT NULL, interview_id CHAR(32) NOT NULL UNIQUE, title VARCHAR(200) NOT NULL, brief TEXT NOT NULL, product_line VARCHAR(200) NOT NULL, kind VARCHAR(20) NOT NULL DEFAULT 'production', status VARCHAR(20) NOT NULL DEFAULT 'draft', event_id CHAR(32) NULL, appointment_id CHAR(32) NULL, revision INTEGER NOT NULL DEFAULT 1, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, FOREIGN KEY (owner_id) REFERENCES users(id), FOREIGN KEY (interview_id) REFERENCES interviews(id) ON DELETE CASCADE, FOREIGN KEY (event_id) REFERENCES schedule_events(id) ON DELETE SET NULL, FOREIGN KEY (appointment_id) REFERENCES schedule_appointments(id) ON DELETE SET NULL)$suffix",
            "CREATE TABLE IF NOT EXISTS production_shots (id CHAR(32) PRIMARY KEY, project_id CHAR(32) NOT NULL, title VARCHAR(200) NOT NULL, brief TEXT NOT NULL, mode VARCHAR(16) NOT NULL, max_contributors INTEGER NOT NULL, eligible_roles_json TEXT NOT NULL, orientation VARCHAR(16) NOT NULL, target_duration_seconds INTEGER NOT NULL, takes_requested INTEGER NOT NULL, due_at VARCHAR(32) NULL, sort_order INTEGER NOT NULL DEFAULT 0, status VARCHAR(16) NOT NULL DEFAULT 'active', revision INTEGER NOT NULL DEFAULT 1, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, FOREIGN KEY (project_id) REFERENCES production_projects(id) ON DELETE CASCADE)$suffix",
            "CREATE TABLE IF NOT EXISTS shot_assignments (id CHAR(32) PRIMARY KEY, shot_id CHAR(32) NOT NULL, user_id $uid NOT NULL, status VARCHAR(24) NOT NULL, revision INTEGER NOT NULL DEFAULT 1, current_round INTEGER NOT NULL DEFAULT 0, note TEXT NOT NULL, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, submitted_at VARCHAR(32) NULL, reviewed_at VARCHAR(32) NULL, UNIQUE (shot_id,user_id), FOREIGN KEY (shot_id) REFERENCES production_shots(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id))$suffix",
            "CREATE TABLE IF NOT EXISTS shot_submissions (id CHAR(32) PRIMARY KEY, assignment_id CHAR(32) NOT NULL, contributor_id $uid NOT NULL, uploaded_by $uid NOT NULL, take_id CHAR(32) NOT NULL UNIQUE, client_token VARCHAR(160) NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'draft', note TEXT NOT NULL, review_note TEXT NOT NULL, reviewed_by $uid NULL, revision INTEGER NOT NULL DEFAULT 1, submitted_round INTEGER NOT NULL DEFAULT 0, review_round INTEGER NOT NULL DEFAULT 0, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, submitted_at VARCHAR(32) NULL, reviewed_at VARCHAR(32) NULL, UNIQUE (assignment_id,client_token), FOREIGN KEY (assignment_id) REFERENCES shot_assignments(id) ON DELETE CASCADE, FOREIGN KEY (take_id) REFERENCES takes(id) ON DELETE CASCADE, FOREIGN KEY (contributor_id) REFERENCES users(id), FOREIGN KEY (uploaded_by) REFERENCES users(id), FOREIGN KEY (reviewed_by) REFERENCES users(id))$suffix",
            "CREATE TABLE IF NOT EXISTS work_uploads (upload_id CHAR(32) PRIMARY KEY, assignment_id CHAR(32) NOT NULL, contributor_id $uid NOT NULL, uploaded_by $uid NOT NULL, assignment_revision INTEGER NOT NULL, shot_revision INTEGER NOT NULL, client_token VARCHAR(160) NOT NULL, submission_id CHAR(32) NULL, created_at VARCHAR(32) NOT NULL, UNIQUE (uploaded_by,client_token), FOREIGN KEY (upload_id) REFERENCES uploads(id) ON DELETE CASCADE, FOREIGN KEY (assignment_id) REFERENCES shot_assignments(id) ON DELETE CASCADE, FOREIGN KEY (contributor_id) REFERENCES users(id), FOREIGN KEY (uploaded_by) REFERENCES users(id), FOREIGN KEY (submission_id) REFERENCES shot_submissions(id) ON DELETE SET NULL)$suffix",
            "CREATE TABLE IF NOT EXISTS work_references (id CHAR(32) PRIMARY KEY, project_id CHAR(32) NOT NULL, shot_id CHAR(32) NULL, title VARCHAR(200) NOT NULL, storage_name VARCHAR(80) NOT NULL UNIQUE, mime_type VARCHAR(80) NOT NULL, file_name VARCHAR(250) NOT NULL, size_bytes BIGINT NOT NULL, created_by $uid NOT NULL, created_at VARCHAR(32) NOT NULL, FOREIGN KEY (project_id) REFERENCES production_projects(id) ON DELETE CASCADE, FOREIGN KEY (shot_id) REFERENCES production_shots(id) ON DELETE CASCADE, FOREIGN KEY (created_by) REFERENCES users(id))$suffix",
            "CREATE TABLE IF NOT EXISTS work_events (id CHAR(32) PRIMARY KEY, project_id CHAR(32) NOT NULL, assignment_id CHAR(32) NULL, actor_id $uid NOT NULL, kind VARCHAR(40) NOT NULL, message TEXT NOT NULL, created_at VARCHAR(32) NOT NULL, FOREIGN KEY (project_id) REFERENCES production_projects(id) ON DELETE CASCADE, FOREIGN KEY (assignment_id) REFERENCES shot_assignments(id) ON DELETE SET NULL, FOREIGN KEY (actor_id) REFERENCES users(id))$suffix",
            "CREATE TABLE IF NOT EXISTS work_notifications (id CHAR(32) PRIMARY KEY, recipient_id $uid NOT NULL, kind VARCHAR(40) NOT NULL, title VARCHAR(200) NOT NULL, message TEXT NOT NULL, project_id CHAR(32) NOT NULL, assignment_id CHAR(32) NULL, read_at VARCHAR(32) NULL, created_at VARCHAR(32) NOT NULL, FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (project_id) REFERENCES production_projects(id) ON DELETE CASCADE, FOREIGN KEY (assignment_id) REFERENCES shot_assignments(id) ON DELETE CASCADE)$suffix",
            "CREATE TABLE IF NOT EXISTS work_requests (user_id $uid NOT NULL, client_token VARCHAR(160) NOT NULL, action VARCHAR(40) NOT NULL, request_hash CHAR(64) NOT NULL, resource_type VARCHAR(20) NOT NULL, resource_id CHAR(32) NOT NULL, created_at VARCHAR(32) NOT NULL, PRIMARY KEY (user_id,client_token), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)$suffix",
            "CREATE TABLE IF NOT EXISTS work_source_takes (take_id CHAR(32) PRIMARY KEY, source_take_id CHAR(32) NULL, source_interview_id CHAR(32) NOT NULL, project_id CHAR(32) NOT NULL, created_by $uid NOT NULL, created_at VARCHAR(32) NOT NULL, FOREIGN KEY (take_id) REFERENCES takes(id) ON DELETE CASCADE, FOREIGN KEY (source_take_id) REFERENCES takes(id) ON DELETE SET NULL, FOREIGN KEY (project_id) REFERENCES production_projects(id) ON DELETE CASCADE, FOREIGN KEY (created_by) REFERENCES users(id))$suffix",
        ] as $sql) $db->exec($sql);
        foreach ([['interviews_project','interviews','project_id'],['projects_owner','production_projects','owner_id,status,updated_at'],['shots_project','production_shots','project_id,status,sort_order'],['assignments_user','shot_assignments','user_id,status'],['assignments_shot','shot_assignments','shot_id,status'],['submissions_assignment','shot_submissions','assignment_id,status,created_at'],['work_uploads_assignment','work_uploads','assignment_id,contributor_id'],['work_references_project','work_references','project_id,shot_id'],['work_events_project','work_events','project_id,created_at'],['work_notifications_recipient','work_notifications','recipient_id,read_at,created_at']] as [$name,$table,$cols]) {
            if ($mysql) {
                $q=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');$q->execute([$table,$name]);
                if (!(int)$q->fetchColumn()) $db->exec("CREATE INDEX $name ON $table ($cols)");
            } else $db->exec("CREATE INDEX IF NOT EXISTS $name ON $table ($cols)");
        }
        // Retry-safe for an interrupted v1.3 migration or a pre-release work table.
        foreach (['shot_assignments'=>['current_round'], 'shot_submissions'=>['submitted_round','review_round']] as $table=>$names) {
            if ($mysql) {
                $q=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);$existing=$q->fetchAll(PDO::FETCH_COLUMN);
            } else $existing=array_column($db->query('PRAGMA table_info('.$table.')')->fetchAll(),'name');
            foreach($names as$name)if(!in_array($name,$existing,true))$db->exec('ALTER TABLE '.$table.' ADD COLUMN '.$name.' INTEGER NOT NULL DEFAULT 0');
        }
        $db->exec('UPDATE studio_schema SET version=8 WHERE id=1');
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function az_work_query(string $sql, array $args=[]): PDOStatement { $q=az_db()->prepare($sql);$q->execute($args);return $q; }
function az_work_id(mixed $id): string { if(!is_string($id)||!az_valid_id($id))throw new AzHttpError(404,'This production item was not found.','not_found');return$id; }
function az_work_live_user(array $user): array
{
    $live=az_account_user((int)($user['id']??0));
    if(!$live||$live['status']!=='active')throw new AzHttpError(403,'This account is not active.','account_inactive');
    return$live;
}
function az_work_assert_role(array $user): void
{
    if(!array_intersect($user['roles']??[],['admin','model','professional']))throw new AzHttpError(403,'Production work is available to models, professionals and administrators.','role_required');
}
function az_work_assert_admin(array $user): void { if(!az_has_role($user,'admin'))throw new AzHttpError(403,'Administrator access is required to manage production.','role_required'); }
function az_work_manage(array $row,array $user): bool { return az_has_role($user,'admin')&&(int)$row['owner_id']===(int)$user['id']; }
function az_work_eligible(array $shot,array $user): bool { return (bool)array_intersect($user['roles']??[],json_decode($shot['eligible_roles_json'],true)?:[]); }
function az_work_revision(array $body,array $row): void { if(!isset($body['revision'])||az_integer($body['revision'],1,PHP_INT_MAX,'Revision')!==(int)$row['revision'])throw new AzHttpError(409,'This item changed. Reload before saving.','revision_conflict'); }
function az_work_raw_project(string $id): array
{
    $row=az_work_query("SELECT p.* FROM production_projects p JOIN users u ON u.id=p.owner_id WHERE p.id=? AND u.status='active' AND EXISTS(SELECT 1 FROM user_roles r WHERE r.user_id=u.id AND r.role='admin')",[az_work_id($id)])->fetch();
    if(!$row)throw new AzHttpError(404,'This production project was not found.','not_found');return$row;
}
function az_work_project_visible(array $row,array $user): bool
{
    if(az_work_manage($row,$user))return true;
    if($row['status']==='active'&&az_work_roster_contributor($user)&&az_work_roster_member($row['id'],(int)$user['id']))return true;
    $shots=az_work_query('SELECT s.*,a.id AS my_assignment_id FROM production_shots s LEFT JOIN shot_assignments a ON a.shot_id=s.id AND a.user_id=? WHERE s.project_id=?',[$user['id'],$row['id']])->fetchAll();
    foreach($shots as$shot)if(az_work_eligible($shot,$user)&&$shot['my_assignment_id'])return true;
    return false;
}
function az_work_project_row(string $id,array $user,bool $adminOnly=false): array
{
    $user=az_work_live_user($user);az_work_assert_role($user);$row=az_work_raw_project($id);
    if(($adminOnly&&!az_work_manage($row,$user))||!az_work_project_visible($row,$user))throw new AzHttpError(404,'This production project was not found.','not_found');return$row;
}
function az_work_shot_row(string $id,array $user,bool $adminOnly=false): array
{
    $user=az_work_live_user($user);az_work_assert_role($user);
    $shot=az_work_query('SELECT s.*,p.owner_id,p.interview_id,p.status AS project_status,p.title AS project_title FROM production_shots s JOIN production_projects p ON p.id=s.project_id WHERE s.id=?',[az_work_id($id)])->fetch();
    if(!$shot)throw new AzHttpError(404,'This shot was not found.','not_found');az_work_raw_project($shot['project_id']);
    if(az_work_manage($shot,$user))return$shot;
    $mine=az_work_query('SELECT id FROM shot_assignments WHERE shot_id=? AND user_id=?',[$shot['id'],$user['id']])->fetch();
    $open=$shot['project_status']==='active'&&$shot['status']==='active'&&$shot['mode']==='open'&&az_work_roster_member($shot['project_id'],(int)$user['id']);
    if($adminOnly||!az_work_eligible($shot,$user)||(!$mine&&!$open))throw new AzHttpError(404,'This shot was not found.','not_found');
    return$shot;
}
function az_work_assignment_access(string $id,array $user,bool $forUpload=false): array
{
    $user=az_work_live_user($user);az_work_assert_role($user);
    $row=az_work_query('SELECT a.*,s.project_id,s.title AS shot_title,s.status AS shot_status,s.revision AS shot_revision,s.eligible_roles_json,s.orientation,s.target_duration_seconds,s.takes_requested,s.due_at,p.owner_id,p.interview_id,p.status AS project_status,p.title AS project_title,u.name AS user_name FROM shot_assignments a JOIN production_shots s ON s.id=a.shot_id JOIN production_projects p ON p.id=s.project_id JOIN users u ON u.id=a.user_id WHERE a.id=?',[az_work_id($id)])->fetch();
    if(!$row)throw new AzHttpError(404,'This assignment was not found.','not_found');az_work_raw_project($row['project_id']);
    $manager=az_work_manage($row,$user);
    if(!$manager&&((int)$row['user_id']!==(int)$user['id']||!az_work_eligible($row,$user)))throw new AzHttpError(404,'This assignment was not found.','not_found');
    if($forUpload){
        $member=az_account_user((int)$row['user_id']);
        $host=$member&&az_has_role($member,'admin')&&(int)$member['id']===(int)$row['owner_id'];
        if(!$member||$member['status']!=='active'||(!$host&&!az_work_eligible($row,$member)))throw new AzHttpError(409,'The assigned contributor is no longer eligible for this shot.','contributor_unavailable');
        if($row['project_status']!=='active'||$row['shot_status']!=='active')throw new AzHttpError(409,'Uploads are available while the project and shot are active.','work_closed');
        if(!in_array($row['status'],$manager?['assigned','accepted','changes_requested']:['accepted','changes_requested'],true))throw new AzHttpError(409,'Accept this assignment or wait for a retake request before uploading.','assignment_not_uploadable');
    }
    return$row;
}
function az_work_submission_row(string $id,array $user,bool $adminOnly=false): array
{
    $row=az_work_query('SELECT ss.*,t.storage_name,t.mime_type,t.file_name,t.label,t.size_bytes,t.duration_ms,t.is_favorite,t.is_private,t.review_status,t.question_id,t.interview_id,t.owner_id,u.name AS contributor_name FROM shot_submissions ss JOIN takes t ON t.id=ss.take_id JOIN users u ON u.id=ss.contributor_id WHERE ss.id=?',[az_work_id($id)])->fetch();
    if(!$row)throw new AzHttpError(404,'This submission was not found.','not_found');
    $a=az_work_assignment_access($row['assignment_id'],$user);if($adminOnly&&!az_work_manage($a,$user))throw new AzHttpError(404,'This submission was not found.','not_found');
    if((int)$row['contributor_id']!==(int)$a['user_id']||$row['interview_id']!==$a['interview_id']||$row['question_id']!==$a['shot_id']||(int)$row['owner_id']!==(int)$a['owner_id'])throw new AzHttpError(404,'This submission was not found.','not_found');
    return$row+['project_id'=>$a['project_id'],'shot_id'=>$a['shot_id'],'assignment_status'=>$a['status'],'assignment_round'=>(int)$a['current_round'],'project_status'=>$a['project_status'],'shot_status'=>$a['shot_status']];
}
function az_work_reference_row(string $id,array $user): array
{
    $row=az_work_query('SELECT * FROM work_references WHERE id=?',[az_work_id($id)])->fetch();if(!$row)throw new AzHttpError(404,'This reference was not found.','not_found');
    if($row['shot_id']){
        $shot=az_work_shot_row($row['shot_id'],$user);
        if($shot['project_id']!==$row['project_id'])throw new AzHttpError(404,'This reference was not found.','not_found');
    }else az_work_project_row($row['project_id'],$user);return$row;
}
function az_work_event(string $projectId,int $actorId,string $kind,string $message,?string $assignmentId=null): void
{
    az_work_query('INSERT INTO work_events(id,project_id,assignment_id,actor_id,kind,message,created_at) VALUES(?,?,?,?,?,?,?)',[az_id(),$projectId,$assignmentId,$actorId,$kind,$message,az_now()]);
    if (function_exists('az_agent_workspace_work_event')) az_agent_workspace_work_event($projectId,$actorId,$kind,$message,$assignmentId);
    // A submitted/accepted/cancelled/reviewed assignment must not leave an old
    // automated reminder in the unread inbox. Preserve it in History.
    if ($assignmentId && function_exists('az_agent_followthrough_resolve_assignment') &&
        in_array($kind,['shot_assigned','shot_claimed','assignment_accepted','assignment_declined',
            'assignment_cancelled','assignment_submitted','take_accepted','take_retake'],true)) {
        az_agent_followthrough_resolve_assignment($assignmentId);
    }
}
function az_work_notify(int $recipientId,string $kind,string $title,string $message,string $projectId,?string $assignmentId=null,?string $shotId=null): void
{
    az_work_query('INSERT INTO work_notifications(id,recipient_id,kind,title,message,project_id,assignment_id,shot_id,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[az_id(),$recipientId,$kind,$title,$message,$projectId,$assignmentId,$shotId,az_now()]);
}
function az_work_reference_data(array $row): array
{
    return['id'=>$row['id'],'projectId'=>$row['project_id'],'shotId'=>$row['shot_id'],'title'=>$row['title'],'mimeType'=>$row['mime_type'],'sizeBytes'=>(int)$row['size_bytes'],'mediaUrl'=>az_url('work-media.php?type=reference&id='.$row['id'])];
}
function az_work_public_videos(string $interviewId): array
{
    if(!function_exists('az_public_video_conditions'))return[];
    return array_map('az_public_video_data',az_work_query('SELECT v.* FROM finished_videos v JOIN interviews i ON i.id=v.interview_id JOIN users u ON u.id=v.owner_id WHERE v.interview_id=? AND '.az_public_video_conditions().' ORDER BY v.fan_published_at DESC,v.id DESC',[$interviewId])->fetchAll());
}
function az_work_schedule(array $row,array $user): ?array
{
    if($row['event_id']){
        try{$event=az_sched_event($row['event_id'],$user);return['type'=>'event','id'=>$event['id'],'title'=>$event['title'],'startLocal'=>az_sched_local($event['start_at']),'endLocal'=>az_sched_local($event['end_at']),'location'=>$event['location']];}catch(AzHttpError){return null;}
    }
    if($row['appointment_id']){
        $a=az_work_query('SELECT * FROM schedule_appointments WHERE id=? AND (customer_id=? OR professional_id=?)',[$row['appointment_id'],$user['id'],$user['id']])->fetch();
        if($a)return['type'=>'appointment','id'=>$a['id'],'title'=>$a['type_name'],'startLocal'=>az_sched_local($a['start_at']),'endLocal'=>az_sched_local($a['end_at']),'location'=>$a['location']];
    }return null;
}
function az_work_project_data(array $row,array $user): array
{
    $manage=az_work_manage($row,$user);
    $counts=az_work_query("SELECT COUNT(DISTINCT s.id) AS shots,COUNT(DISTINCT CASE WHEN a.status NOT IN ('declined','cancelled') THEN a.id END) AS assignments,SUM(CASE WHEN ss.status='submitted' THEN 1 ELSE 0 END) AS submitted,SUM(CASE WHEN ss.status='accepted' THEN 1 ELSE 0 END) AS accepted FROM production_shots s LEFT JOIN shot_assignments a ON a.shot_id=s.id LEFT JOIN shot_submissions ss ON ss.assignment_id=a.id WHERE s.project_id=?".($manage?'':' AND (a.user_id=? OR s.mode=\'open\')'),$manage?[$row['id']]:[$row['id'],$user['id']])->fetch();
    $data=['id'=>$row['id'],'title'=>$row['title'],'brief'=>$row['brief'],'productLine'=>$row['product_line'],'kind'=>$row['kind'],'status'=>$row['status'],'revision'=>(int)$row['revision'],'eventId'=>$row['event_id'],'appointmentId'=>null,'schedule'=>az_work_schedule($row,$user),'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at'],'shotCount'=>(int)$counts['shots'],'assignmentCount'=>$manage?(int)$counts['assignments']:null,'submittedCount'=>$manage?(int)$counts['submitted']:null,'acceptedCount'=>$manage?(int)$counts['accepted']:null,'approvedAssignmentCount'=>$manage?(int)az_work_query("SELECT COUNT(*) FROM shot_assignments a JOIN production_shots s ON s.id=a.shot_id WHERE s.project_id=? AND a.status='approved'",[$row['id']])->fetchColumn():null,'canManage'=>$manage,'editorUrl'=>$manage?az_url('editor.php?id='.$row['interview_id']):null,'videos'=>az_work_public_videos($row['interview_id'])];
    $data['isMember']=az_work_roster_member($row['id'],(int)$user['id']);
    if($manage){$data['interviewId']=$row['interview_id'];$data['recordingUrl']=az_url('interview.php?id='.$row['interview_id']);$data['appointmentId']=$row['appointment_id'];$data['memberCount']=(int)az_work_query("SELECT COUNT(*) FROM production_members WHERE project_id=? AND status='active'",[$row['id']])->fetchColumn();}
    else{
        // A roster member sees eligible open shots and their own assignments only.
        // Counts must use that same visibility rule rather than reveal private shots.
        $visible=0;
        foreach(az_work_query('SELECT s.*,a.id AS my_assignment_id FROM production_shots s LEFT JOIN shot_assignments a ON a.shot_id=s.id AND a.user_id=? WHERE s.project_id=?',[$user['id'],$row['id']])->fetchAll()as$shot){
            if(az_work_eligible($shot,$user)&&($shot['my_assignment_id']||($data['isMember']&&$row['status']==='active'&&$shot['status']==='active'&&$shot['mode']==='open')))$visible++;
        }
        $data['shotCount']=$visible;
    }
    if(!$manage&&$data['schedule']&&$data['schedule']['type']==='appointment')$data['appointmentId']=$row['appointment_id'];
    return$data;
}
function az_work_assignment_data(array $row,array $user): array
{
    if(!isset($row['owner_id']))$row=az_work_assignment_access($row['id'],$user);
    $manage=az_work_manage($row,$user);$own=(int)$row['user_id']===(int)$user['id'];
    $active=$row['project_status']==='active'&&$row['shot_status']==='active';
    $counts=az_work_query("SELECT COUNT(*) AS total,SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END) AS drafts,SUM(CASE WHEN status='accepted' THEN 1 ELSE 0 END) AS accepted FROM shot_submissions WHERE assignment_id=?",[$row['id']])->fetch();
    $latest=az_work_query("SELECT review_note FROM shot_submissions WHERE assignment_id=? AND reviewed_at IS NOT NULL AND review_note<>'' ORDER BY reviewed_at DESC,updated_at DESC,id DESC LIMIT 1",[$row['id']])->fetchColumn();
    $eligible=true;if($manage){$member=az_account_user((int)$row['user_id']);$eligible=$member&&$member['status']==='active'&&(az_work_eligible($row,$member)||((int)$member['id']===(int)$row['owner_id']&&az_has_role($member,'admin')));}
    return['id'=>$row['id'],'shotId'=>$row['shot_id'],'projectId'=>$row['project_id'],'userId'=>(int)$row['user_id'],'userName'=>$row['user_name'],'shotTitle'=>$row['shot_title'],'projectTitle'=>$row['project_title'],'status'=>$row['status'],'revision'=>(int)$row['revision'],'round'=>(int)$row['current_round'],'note'=>$row['note'],'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at'],'submittedAt'=>$row['submitted_at'],'reviewedAt'=>$row['reviewed_at'],'dueAt'=>$row['due_at'],'dueLocal'=>$row['due_at']?az_sched_local($row['due_at']):null,'orientation'=>$row['orientation'],'targetDurationSeconds'=>(int)$row['target_duration_seconds'],'takesRequested'=>(int)$row['takes_requested'],'submissionCount'=>(int)$counts['total'],'acceptedCount'=>(int)$counts['accepted'],'canRespond'=>$own&&$active&&$row['status']==='assigned','canUpload'=>$active&&$eligible&&in_array($row['status'],$manage?['assigned','accepted','changes_requested']:['accepted','changes_requested'],true),'canSubmit'=>$active&&$eligible&&(int)$counts['drafts']>0&&in_array($row['status'],$manage?['assigned','accepted','changes_requested']:['accepted','changes_requested'],true),'canManage'=>$manage,'latestReviewNote'=>$latest?:'','questionUnreadCount'=>function_exists('az_work_thread_unread')?az_work_thread_unread($row['id'],$user):0,'watchVideos'=>az_work_public_videos($row['interview_id'])];
}
function az_work_shot_data(array $row,array $user): array
{
    if(!isset($row['owner_id']))$row=az_work_shot_row($row['id'],$user);
    $count=(int)az_work_query("SELECT COUNT(*) FROM shot_assignments WHERE shot_id=? AND status NOT IN ('declined','cancelled')",[$row['id']])->fetchColumn();
    $contributors=(int)az_work_query("SELECT COUNT(*) FROM shot_assignments WHERE shot_id=? AND user_id<>? AND status NOT IN ('declined','cancelled')",[$row['id'],$row['owner_id']])->fetchColumn();
    $accepted=(int)az_work_query("SELECT COUNT(*) FROM shot_submissions ss JOIN shot_assignments a ON a.id=ss.assignment_id WHERE a.shot_id=? AND ss.status='accepted'",[$row['id']])->fetchColumn();
    $mine=az_work_query('SELECT id FROM shot_assignments WHERE shot_id=? AND user_id=?',[$row['id'],$user['id']])->fetchColumn();
    $assignment=$mine?az_work_assignment_data(az_work_assignment_access($mine,$user),$user):null;$manage=az_work_manage($row,$user);
    $rostered=az_work_roster_member($row['project_id'],(int)$user['id']);
    $showCapacity=$manage||($rostered&&$row['mode']==='open'&&$row['status']==='active'&&$row['project_status']==='active');
    return['id'=>$row['id'],'projectId'=>$row['project_id'],'projectTitle'=>$row['project_title'],'title'=>$row['title'],'brief'=>$row['brief'],'mode'=>$row['mode'],'maxContributors'=>(int)$row['max_contributors'],'eligibleRoles'=>json_decode($row['eligible_roles_json'],true),'orientation'=>$row['orientation'],'targetDurationSeconds'=>(int)$row['target_duration_seconds'],'takesRequested'=>(int)$row['takes_requested'],'dueAt'=>$row['due_at'],'dueLocal'=>$row['due_at']?az_sched_local($row['due_at']):null,'sortOrder'=>(int)$row['sort_order'],'status'=>$row['status'],'revision'=>(int)$row['revision'],'slotsRemaining'=>$showCapacity?max(0,(int)$row['max_contributors']-$contributors):null,'contributorCount'=>$showCapacity?$contributors:null,'assignmentCount'=>$manage?$count:null,'acceptedCount'=>$manage?$accepted:null,'approvedAssignmentCount'=>$manage?(int)az_work_query("SELECT COUNT(*) FROM shot_assignments WHERE shot_id=? AND status='approved'",[$row['id']])->fetchColumn():null,'myAssignment'=>$assignment,'canClaim'=>$row['project_status']==='active'&&$row['status']==='active'&&$row['mode']==='open'&&($manage||$rostered)&&az_work_eligible($row,$user)&&$contributors<(int)$row['max_contributors']&&(!$assignment||in_array($assignment['status'],['declined','cancelled'],true)),'canManage'=>$manage];
}
function az_work_submission_data(array $row,array $user): array
{
    if(!isset($row['storage_name']))$row=az_work_submission_row($row['id'],$user);
    $phone=az_work_query('SELECT c.label FROM phone_upload_links l JOIN uploads u ON u.id=l.upload_id JOIN phone_connections c ON c.id=l.connection_id WHERE u.take_id=? LIMIT 1',[$row['take_id']])->fetchColumn();
    $source=$phone!==false?'approved_phone':((int)$row['uploaded_by']===(int)$row['owner_id']?'producer':'member');
    $sourceLabel=$phone!==false?'Approved phone · '.$phone:($source==='producer'?'Studio capture':'Contributor upload');
    return['id'=>$row['id'],'assignmentId'=>$row['assignment_id'],'takeId'=>$row['take_id'],'contributorId'=>(int)$row['contributor_id'],'contributorName'=>$row['contributor_name'],'uploadedBy'=>(int)$row['uploaded_by'],'source'=>$source,'sourceLabel'=>$sourceLabel,'label'=>$row['label'],'round'=>(int)$row['submitted_round'],'reviewRound'=>(int)$row['review_round'],'status'=>$row['status'],'note'=>$row['note'],'reviewNote'=>$row['review_note'],'reviewedAt'=>$row['reviewed_at'],'revision'=>(int)$row['revision'],'createdAt'=>$row['created_at'],'mediaUrl'=>az_url('work-media.php?type=submission&id='.$row['id']),'durationMs'=>(int)$row['duration_ms'],'mimeType'=>$row['mime_type'],'fileName'=>$row['file_name'],'sizeBytes'=>(int)$row['size_bytes'],'isFavorite'=>(bool)$row['is_favorite'],'reviewNotes'=>az_take_notes($row['take_id'],(int)$row['owner_id']),'canReview'=>az_work_manage($row,$user)&&$row['project_status']!=='archived'&&$row['status']!=='draft'&&!in_array($row['assignment_status'],['cancelled','declined'],true)];
}
function az_work_project_detail(string $id,array $user): array
{
    $row=az_work_project_row($id,$user);$data=az_work_project_data($row,$user);$shots=[];
    foreach(az_work_query('SELECT id FROM production_shots WHERE project_id=? ORDER BY sort_order,created_at,id',[$id])->fetchAll(PDO::FETCH_COLUMN) as$shotId){try{$shots[]=az_work_shot_data(az_work_shot_row($shotId,$user),$user);}catch(AzHttpError$e){if($e->status!==404)throw$e;}}
    $data['shots']=$shots;$data['references']=array_map('az_work_reference_data',az_work_query('SELECT * FROM work_references WHERE project_id=? AND shot_id IS NULL ORDER BY created_at,id',[$id])->fetchAll());
    $data['activity']=[];if($data['canManage'])$data['activity']=array_map(static fn($r)=>['id'=>$r['id'],'kind'=>$r['kind'],'message'=>$r['message'],'actorName'=>$r['actor_name'],'assignmentId'=>$r['assignment_id'],'createdAt'=>$r['created_at']],az_work_query('SELECT e.*,u.name AS actor_name FROM work_events e JOIN users u ON u.id=e.actor_id WHERE e.project_id=? ORDER BY e.created_at DESC,e.id DESC LIMIT 100',[$id])->fetchAll());
    if($data['canManage'])$data['roster']=az_work_roster_data($id,$user);
    return$data;
}
function az_work_shot_detail(string $id,array $user): array
{
    $row=az_work_shot_row($id,$user);$data=az_work_shot_data($row,$user);$data['project']=az_work_project_data(az_work_project_row($row['project_id'],$user),$user);
    $assignments=az_work_query('SELECT id FROM shot_assignments WHERE shot_id=?'.($data['canManage']?'':' AND user_id=?').' ORDER BY created_at,id',$data['canManage']?[$id]:[$id,$user['id']])->fetchAll(PDO::FETCH_COLUMN);
    $data['assignments']=array_map(static fn($a)=>az_work_assignment_data(az_work_assignment_access($a,$user),$user),$assignments);
    $data['references']=array_map('az_work_reference_data',az_work_query('SELECT * FROM work_references WHERE project_id=? AND (shot_id=? OR shot_id IS NULL) ORDER BY created_at,id',[$row['project_id'],$id])->fetchAll());if($data['canManage'])$data['roster']=az_work_roster_data($row['project_id'],$user);
    $data['planScene']=function_exists('az_production_plan_shot_context')?az_production_plan_shot_context($id,$user):null;return$data;
}
function az_work_assignment_detail(string $id,array $user): array
{
    $row=az_work_assignment_access($id,$user);$data=az_work_assignment_data($row,$user);$data['shot']=az_work_shot_data(az_work_shot_row($row['shot_id'],$user),$user);$data['project']=az_work_project_data(az_work_project_row($row['project_id'],$user),$user);
    $ids=az_work_query('SELECT id FROM shot_submissions WHERE assignment_id=? ORDER BY created_at,id',[$id])->fetchAll(PDO::FETCH_COLUMN);
    $data['submissions']=array_map(static fn($s)=>az_work_submission_data(az_work_submission_row($s,$user),$user),$ids);
    $data['references']=array_map('az_work_reference_data',az_work_query('SELECT * FROM work_references WHERE project_id=? AND (shot_id=? OR shot_id IS NULL) ORDER BY created_at,id',[$row['project_id'],$row['shot_id']])->fetchAll());
    $data['planScene']=function_exists('az_production_plan_shot_context')?az_production_plan_shot_context($row['shot_id'],$user):null;return$data;
}
function az_work_users(array $user): array
{
    az_work_assert_admin($user);$rows=az_work_query("SELECT id,name FROM users u WHERE status='active' AND (EXISTS(SELECT 1 FROM user_roles r WHERE r.user_id=u.id AND r.role IN ('model','professional')) OR u.id=?) ORDER BY name,id",[$user['id']])->fetchAll();
    return array_map(static function($r){$u=az_account_user((int)$r['id']);return['id'=>$u['id'],'name'=>$u['name'],'roles'=>$u['roles']];},$rows);
}
function az_work_schedule_links(array $user): array
{
    az_work_assert_admin($user);$events=az_work_query('SELECT id,title,start_at FROM schedule_events ORDER BY start_at DESC,id DESC LIMIT 250')->fetchAll();
    $appointments=az_work_query('SELECT id,type_name AS title,start_at FROM schedule_appointments WHERE customer_id=? OR professional_id=? ORDER BY start_at DESC,id DESC LIMIT 250',[$user['id'],$user['id']])->fetchAll();
    $map=static fn($r)=>['id'=>$r['id'],'title'=>$r['title'],'startLocal'=>az_sched_local($r['start_at'])];return['events'=>array_map($map,$events),'appointments'=>array_map($map,$appointments)];
}
function az_work_save_project(array $body,array $user): array
{
    az_work_assert_admin($user);$old=isset($body['id'])?az_work_project_row(az_work_id($body['id']),$user,true):null;if($old)az_work_revision($body,$old);
    $title=az_text($body['title']??$old['title']??'',200,'Project title');if($title==='')throw new AzHttpError(400,'Enter a project title.','invalid_field');
    $brief=az_text($body['brief']??$old['brief']??'',32000,'Project brief');$line=az_text($body['productLine']??$old['product_line']??'',200,'Product line');
    $kind=az_enum($body['kind']??$old['kind']??'production',['production','interview','mixed'],'Project kind');$status=az_enum($body['status']??$old['status']??'draft',['draft','active','completed','archived'],'Project status');
    $event=array_key_exists('eventId',$body)?$body['eventId']:($old['event_id']??null);$appointment=array_key_exists('appointmentId',$body)?$body['appointmentId']:($old['appointment_id']??null);
    if($event==='')$event=null;if($appointment==='')$appointment=null;
    if($event&&$appointment)throw new AzHttpError(400,'Link either an event or an appointment to this project.','schedule_link_conflict');
    if($event){$event=az_work_id($event);az_sched_event($event,$user);}
    if($appointment){$appointment=az_work_id($appointment);if(!az_work_query('SELECT id FROM schedule_appointments WHERE id=? AND (customer_id=? OR professional_id=?)',[$appointment,$user['id'],$user['id']])->fetch())throw new AzHttpError(404,'This appointment was not found in your schedule.','not_found');}
    $id=$old['id']??az_id();$now=az_now();
    if($old){
        az_work_query('UPDATE production_projects SET title=?,brief=?,product_line=?,kind=?,status=?,event_id=?,appointment_id=?,revision=revision+1,updated_at=? WHERE id=?',[$title,$brief,$line,$kind,$status,$event,$appointment,$now,$id]);
        az_work_query('UPDATE interviews SET title=?,status=?,archived_at=?,revision=revision+1,updated_at=? WHERE id=? AND owner_id=?',[$title,$status==='archived'?'archived':'active',$status==='archived'?$now:null,$now,$old['interview_id'],$user['id']]);
        if($status!=='active'&&function_exists('az_phone_close_interview'))az_phone_close_interview(az_db(),$old['interview_id']);
        if($status==='archived'&&function_exists('az_unpublish_interview'))az_unpublish_interview($old['interview_id']);
    }else{
        if((int)az_work_query('SELECT COUNT(*) FROM production_projects WHERE owner_id=?',[$user['id']])->fetchColumn()>=2000)throw new AzHttpError(409,'This account has reached the 2,000-project limit.','project_limit');
        $interview=az_id();$state=json_encode(['edit'=>['mode'=>$kind]],JSON_THROW_ON_ERROR);
        az_work_query('INSERT INTO interviews(id,owner_id,title,participant_name,notes,status,state_json,created_at,updated_at,archived_at,project_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$interview,$user['id'],$title,'','',$status==='archived'?'archived':'active',$state,$now,$now,$status==='archived'?$now:null,$id]);
        az_work_query('INSERT INTO production_projects(id,owner_id,interview_id,title,brief,product_line,kind,status,event_id,appointment_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$user['id'],$interview,$title,$brief,$line,$kind,$status,$event,$appointment,$now,$now]);
        az_work_roster_initialize($id);
    }
    az_work_event($id,(int)$user['id'],$old?'project_updated':'project_created',($old?'Updated ':'Created ').$title.'.');
    if($old&&$status!==$old['status']){
        $recipients=az_work_query("SELECT DISTINCT a.user_id FROM shot_assignments a JOIN production_shots s ON s.id=a.shot_id WHERE s.project_id=? AND a.status NOT IN ('declined','cancelled')",[$id])->fetchAll(PDO::FETCH_COLUMN);
        if($status==='active')$recipients=array_unique([...$recipients,...az_work_roster_recipients($id)]);
        foreach($recipients as$recipient)if((int)$recipient!==(int)$user['id'])az_work_notify((int)$recipient,'project_status','Project '.$status,$title.' is now '.$status.'.',$id);
    }return az_work_project_detail($id,$user);
}
function az_work_save_shot(array $body,array $user): array
{
    az_work_assert_admin($user);$old=isset($body['id'])?az_work_shot_row(az_work_id($body['id']),$user,true):null;if($old)az_work_revision($body,$old);
    $project=az_work_project_row(az_work_id($body['projectId']??$old['project_id']??null),$user,true);
    if($old&&$old['project_id']!==$project['id'])throw new AzHttpError(409,'A shot cannot be moved to another project.','project_change');
    if(!in_array($project['status'],['draft','active'],true))throw new AzHttpError(409,'Reopen this project before changing its shots.','work_closed');
    $title=az_text($body['title']??$old['title']??'',200,'Shot title');if($title==='')throw new AzHttpError(400,'Enter a shot title.','invalid_field');$brief=az_text($body['brief']??$old['brief']??'',32000,'Shot brief');
    $mode=az_enum($body['mode']??$old['mode']??'assigned',['assigned','open'],'Shot mode');$max=az_integer($body['maxContributors']??$old['max_contributors']??1,1,50,'Contributor limit');
    $roles=az_account_roles($body['eligibleRoles']??($old?json_decode($old['eligible_roles_json'],true):['model']),['model','professional']);if(!$roles)throw new AzHttpError(400,'Choose at least one eligible contributor role.','invalid_roles');
    $orientation=az_enum($body['orientation']??$old['orientation']??'portrait',['portrait','landscape','square','any'],'Orientation');$duration=az_integer($body['targetDurationSeconds']??$old['target_duration_seconds']??30,0,3600,'Target duration');$takes=az_integer($body['takesRequested']??$old['takes_requested']??1,1,20,'Requested takes');$order=az_integer($body['sortOrder']??$old['sort_order']??0,0,10000,'Shot order');$status=az_enum($body['status']??$old['status']??'active',['active','closed','archived'],'Shot status');
    $due=$old['due_at']??null;if(array_key_exists('dueLocal',$body))$due=$body['dueLocal']===null||$body['dueLocal']===''?null:az_sched_time($body['dueLocal'],true);
    if($old){
        $current=az_work_query("SELECT user_id FROM shot_assignments WHERE shot_id=? AND status NOT IN ('declined','cancelled')",[$old['id']])->fetchAll(PDO::FETCH_COLUMN);
        if(count(array_filter($current,static fn($id)=>(int)$id!==(int)$user['id']))>$max)throw new AzHttpError(409,'Contributor capacity cannot be lower than the current assignments.','capacity_below_assignments');
        $oldRoles=json_decode($old['eligible_roles_json'],true);sort($oldRoles);
        if($roles!==$oldRoles)foreach($current as$memberId){$member=az_account_user((int)$memberId);if((int)$memberId!==(int)$user['id']&&$member&&!array_intersect($roles,$member['roles']))throw new AzHttpError(409,'Cancel an ineligible assignment before changing this shot’s eligible roles.','role_change_with_assignments');}
    }elseif((int)az_work_query('SELECT COUNT(*) FROM production_shots WHERE project_id=?',[$project['id']])->fetchColumn()>=200)throw new AzHttpError(409,'A project can contain up to 200 shots.','shot_limit');
    $id=$old['id']??az_id();$now=az_now();$values=[$title,$brief,$mode,$max,json_encode($roles),$orientation,$duration,$takes,$due,$order,$status,$now];
    if($old)az_work_query('UPDATE production_shots SET title=?,brief=?,mode=?,max_contributors=?,eligible_roles_json=?,orientation=?,target_duration_seconds=?,takes_requested=?,due_at=?,sort_order=?,status=?,revision=revision+1,updated_at=? WHERE id=?',[...$values,$id]);
    else az_work_query('INSERT INTO production_shots(title,brief,mode,max_contributors,eligible_roles_json,orientation,target_duration_seconds,takes_requested,due_at,sort_order,status,updated_at,id,project_id,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[...$values,$id,$project['id'],$now]);
    az_work_query('UPDATE production_projects SET updated_at=? WHERE id=?',[$now,$project['id']]);az_work_event($project['id'],(int)$user['id'],$old?'shot_updated':'shot_created',($old?'Updated ':'Created ').$title.'.');
    if($old)foreach($current as$recipient)if((int)$recipient!==(int)$user['id'])az_work_notify((int)$recipient,'shot_updated','Shot brief updated',$title.' has an updated brief. Review it before uploading.',$project['id'],az_work_query('SELECT id FROM shot_assignments WHERE shot_id=? AND user_id=?',[$id,$recipient])->fetchColumn());
    if($project['status']==='active'&&$mode==='open'&&$status==='active'&&(!$old||$old['mode']!=='open'||$old['status']!=='active')){
        $newShot=az_work_shot_row($id,$user,true);
        foreach(az_work_roster_recipients($project['id'],$newShot)as$recipient){
            if($recipient===(int)$user['id']||az_work_query("SELECT id FROM shot_assignments WHERE shot_id=? AND user_id=? AND status NOT IN ('declined','cancelled')",[$id,$recipient])->fetchColumn())continue;
            az_work_notify($recipient,'open_shot','New open shot',$title.' is available in '.$project['title'].'.',$project['id'],null,$id);
        }
    }
    return az_work_shot_detail($id,$user);
}
function az_work_capacity(array $shot): void
{
    $count=(int)az_work_query("SELECT COUNT(*) FROM shot_assignments WHERE shot_id=? AND user_id<>? AND status NOT IN ('declined','cancelled')",[$shot['id'],$shot['owner_id']])->fetchColumn();
    if($count>=(int)$shot['max_contributors'])throw new AzHttpError(409,'This shot has no contributor slots remaining.','shot_full');
}
function az_work_assign(array $body,array $user,bool $claim=false): array
{
    if(!$claim)az_work_assert_admin($user);$shot=az_work_shot_row(az_work_id($body['shotId']??null),$user,!$claim);$memberId=$claim?(int)$user['id']:az_integer($body['userId']??null,1,PHP_INT_MAX,'Contributor');$member=az_account_user($memberId);
    $host=$member&&az_has_role($member,'admin')&&$memberId===(int)$shot['owner_id'];
    if(!$member||$member['status']!=='active'||(!$host&&!az_work_eligible($shot,$member)))throw new AzHttpError(409,'Choose an active contributor with an eligible role.','contributor_unavailable');
    if(($claim&&$shot['mode']!=='open')||$shot['status']!=='active'||!in_array($shot['project_status'],$claim?['active']:['draft','active'],true))throw new AzHttpError(409,'This shot is not open for assignments.','work_closed');
    $old=az_work_query('SELECT * FROM shot_assignments WHERE shot_id=? AND user_id=?',[$shot['id'],$memberId])->fetch();
    if($old&&!in_array($old['status'],['declined','cancelled'],true))return az_work_assignment_data(az_work_assignment_access($old['id'],$user),$user);
    if(!$host&&!az_work_roster_member($shot['project_id'],$memberId))throw new AzHttpError(409,'Add this contributor to the production models before assigning or claiming a new shot.','roster_required');
    if(!$host)az_work_capacity($shot);$id=$old['id']??az_id();$now=az_now();$state=$claim||$host?'accepted':'assigned';
    if($old)az_work_query('UPDATE shot_assignments SET status=?,revision=revision+1,note=?,submitted_at=NULL,reviewed_at=NULL,updated_at=? WHERE id=?',[$state,'',$now,$id]);
    else az_work_query('INSERT INTO shot_assignments(id,shot_id,user_id,status,note,created_at,updated_at) VALUES(?,?,?,?,?,?,?)',[$id,$shot['id'],$memberId,$state,'',$now,$now]);
    az_work_event($shot['project_id'],(int)$user['id'],$claim?'shot_claimed':'shot_assigned',($claim?'Claimed ':'Assigned ').$shot['title'].' to '.$member['name'].'.',$id);
    if($memberId!==(int)$user['id'])az_work_notify($memberId,'assignment','New shot assignment',$shot['title'].' is ready to review.',$shot['project_id'],$id);
    if($claim&&(int)$shot['owner_id']!==(int)$user['id'])az_work_notify((int)$shot['owner_id'],'claim','Open shot claimed',$member['name'].' claimed '.$shot['title'].'.',$shot['project_id'],$id);
    return az_work_assignment_data(az_work_assignment_access($id,$user),$user);
}
function az_work_host_assignment(string $interviewId,string $questionId,array $user): array
{
    az_work_assert_admin($user);$p=az_work_query('SELECT id FROM production_projects WHERE interview_id=? AND owner_id=?',[$interviewId,$user['id']])->fetchColumn();if(!$p)throw new AzHttpError(404,'This production project was not found.','not_found');
    $shot=az_work_shot_row($questionId,$user,true);if($shot['project_id']!==$p)throw new AzHttpError(404,'This shot was not found in this project.','not_found');
    $a=az_work_assign(['shotId'=>$questionId,'userId'=>$user['id']],$user);return az_work_assignment_access($a['id'],$user,true);
}
function az_work_respond(array $body,array $user,bool $cancel=false): array
{
    $row=az_work_assignment_access(az_work_id($body['assignmentId']??null),$user);az_work_revision($body,$row);$note=az_text($body['note']??'',8000,'Assignment note');
    if($cancel){if(!az_work_manage($row,$user))throw new AzHttpError(403,'Only the project administrator can cancel an assignment.','role_required');$state='cancelled';}
    else{
        if((int)$row['user_id']!==(int)$user['id'])throw new AzHttpError(403,'Only the assigned contributor can respond.','role_required');
        if($row['status']!=='assigned'||$row['project_status']!=='active'||$row['shot_status']!=='active')throw new AzHttpError(409,'This assignment is no longer awaiting a response.','assignment_state');
        $decision=az_enum($body['decision']??null,['accept','decline'],'Assignment response');$state=$decision==='accept'?'accepted':'declined';
    }
    if($row['status']===$state)return az_work_assignment_data($row,$user);
    if($cancel&&function_exists('az_unpublish_interview')&&(int)az_work_query("SELECT COUNT(*) FROM shot_submissions WHERE assignment_id=? AND status='accepted'",[$row['id']])->fetchColumn()>0)az_unpublish_interview($row['interview_id']);
    az_work_query('UPDATE shot_assignments SET status=?,note=?,revision=revision+1,updated_at=? WHERE id=?',[$state,$note,az_now(),$row['id']]);
    az_work_event($row['project_id'],(int)$user['id'],'assignment_'.$state,$row['shot_title'].' assignment '.$state.'.',$row['id']);
    $recipient=$cancel?(int)$row['user_id']:(int)$row['owner_id'];if($recipient!==(int)$user['id'])az_work_notify($recipient,'assignment_'.$state,'Assignment '.$state,$row['shot_title'].($note!==''?': '.$note:'.'),$row['project_id'],$row['id']);
    return az_work_assignment_data(az_work_assignment_access($row['id'],$user),$user);
}
function az_work_register_take(array $assignment,array $take,array $user,string $clientToken): array
{
    $token=az_text($clientToken,160,'Upload token');if($token==='')throw new AzHttpError(400,'An upload token is required.','invalid_token');
    $row=az_work_assignment_access(az_work_id($assignment['id']??null),$user);$takeId=az_work_id($take['id']??null);
    $existing=az_work_query('SELECT id,take_id,assignment_id,uploaded_by FROM shot_submissions WHERE take_id=? OR (assignment_id=? AND client_token=?)',[$takeId,$row['id'],$token])->fetch();
    if($existing){if($existing['assignment_id']!==$row['id']||$existing['take_id']!==$takeId||(int)$existing['uploaded_by']!==(int)$user['id'])throw new AzHttpError(409,'This upload token already belongs to a different recording.','upload_conflict');return az_work_submission_data(az_work_submission_row($existing['id'],$user),$user);}
    $row=az_work_assignment_access($row['id'],$user,true);$actual=az_take_row($takeId,(int)$row['owner_id']);
    if($actual['interview_id']!==$row['interview_id']||$actual['question_id']!==$row['shot_id'])throw new AzHttpError(409,'This recording does not belong to the assigned shot.','upload_target_mismatch');
    $id=az_id();$now=az_now();$db=az_db();$transaction=!$db->inTransaction();if($transaction)$db->beginTransaction();
    try{
        az_work_query('INSERT INTO shot_submissions(id,assignment_id,contributor_id,uploaded_by,take_id,client_token,status,note,review_note,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$id,$row['id'],$row['user_id'],$user['id'],$takeId,$token,'draft','','',$now,$now]);
        az_work_query("UPDATE takes SET is_private=1,review_status='unreviewed' WHERE id=?",[$takeId]);
        az_work_query('UPDATE shot_assignments SET updated_at=? WHERE id=?',[$now,$row['id']]);
        az_work_event($row['project_id'],(int)$user['id'],'take_uploaded','Uploaded a draft take for '.$row['shot_title'].'.',$row['id']);
        if($transaction)$db->commit();
    }catch(Throwable$e){if($transaction&&$db->inTransaction())$db->rollBack();throw$e;}
    return az_work_submission_data(az_work_submission_row($id,$user),$user);
}
function az_work_submit(array $body,array $user): array
{
    $row=az_work_assignment_access(az_work_id($body['assignmentId']??null),$user,true);az_work_revision($body,$row);$note=az_text($body['note']??'',8000,'Submission note');
    $drafts=(int)az_work_query("SELECT COUNT(*) FROM shot_submissions WHERE assignment_id=? AND status='draft'",[$row['id']])->fetchColumn();if(!$drafts)throw new AzHttpError(409,'Upload at least one new take before submitting this assignment.','no_new_takes');
    $now=az_now();$round=(int)$row['current_round']+1;az_work_query("UPDATE shot_submissions SET status='submitted',note=?,submitted_at=?,updated_at=?,submitted_round=?,revision=revision+1 WHERE assignment_id=? AND status='draft'",[$note,$now,$now,$round,$row['id']]);
    az_work_query("UPDATE shot_assignments SET status='submitted',note=?,submitted_at=?,updated_at=?,current_round=?,revision=revision+1 WHERE id=?",[$note,$now,$now,$round,$row['id']]);
    az_work_event($row['project_id'],(int)$user['id'],'assignment_submitted','Submitted '.$drafts.' take'.($drafts===1?'':'s').' for '.$row['shot_title'].'.',$row['id']);
    if((int)$row['owner_id']!==(int)$user['id'])az_work_notify((int)$row['owner_id'],'submission','New takes to review',$row['user_name'].' submitted '.$row['shot_title'].'.',$row['project_id'],$row['id']);
    return az_work_assignment_data(az_work_assignment_access($row['id'],$user),$user);
}
function az_work_review(array $body,array $user): array
{
    $row=az_work_submission_row(az_work_id($body['submissionId']??null),$user,true);az_work_revision($body,$row);$decision=az_enum($body['decision']??null,['accepted','retake'],'Review decision');$note=az_text($body['note']??'',8000,'Review feedback');
    if($row['project_status']==='archived'||in_array($row['assignment_status'],['declined','cancelled'],true))throw new AzHttpError(409,'Reopen the project or reassign this shot before reviewing its takes.','work_closed');
    if($row['status']==='draft')throw new AzHttpError(409,'Submit the assignment before reviewing this take.','take_not_submitted');
    if($decision==='retake'&&($row['project_status']!=='active'||$row['shot_status']!=='active'))throw new AzHttpError(409,'Reopen the project and shot before requesting another take.','work_closed');
    if($decision==='retake'&&$note==='')throw new AzHttpError(400,'Explain what should change in the retake.','feedback_required');
    if($row['status']==='accepted'&&$decision==='retake'&&function_exists('az_unpublish_interview'))az_unpublish_interview($row['interview_id']);
    $now=az_now();az_work_query('UPDATE shot_submissions SET status=?,review_note=?,reviewed_by=?,reviewed_at=?,updated_at=?,review_round=?,revision=revision+1 WHERE id=?',[$decision,$note,$user['id'],$now,$now,$row['assignment_round'],$row['id']]);
    az_work_query('UPDATE takes SET is_private=?,review_status=? WHERE id=?',[$decision==='accepted'?0:1,$decision==='accepted'?'shortlisted':'retake',$row['take_id']]);
    $counts=az_work_query("SELECT SUM(CASE WHEN status='accepted' THEN 1 ELSE 0 END) AS accepted,SUM(CASE WHEN status='submitted' THEN 1 ELSE 0 END) AS pending,SUM(CASE WHEN status='retake' AND review_round=? THEN 1 ELSE 0 END) AS retakes FROM shot_submissions WHERE assignment_id=?",[$row['assignment_round'],$row['assignment_id']])->fetch();
    // A requested retake remains actionable even when another take was approved.
    // A replacement submission advances the round; earlier retakes remain history.
    $state=(int)$counts['retakes']>0?'changes_requested':((int)$counts['pending']>0?'submitted':((int)$counts['accepted']>0?'approved':'changes_requested'));
    az_work_query('UPDATE shot_assignments SET status=?,reviewed_at=?,updated_at=?,revision=revision+1 WHERE id=?',[$state,$now,$now,$row['assignment_id']]);
    az_work_event($row['project_id'],(int)$user['id'],'take_'.$decision,$decision==='accepted'?'Accepted a submitted take.':'Requested a retake: '.$note,$row['assignment_id']);
    if((int)$row['contributor_id']!==(int)$user['id'])az_work_notify((int)$row['contributor_id'],'review',$decision==='accepted'?'Take approved':'Retake requested',$note!==''?$note:'Your take has been approved for the project edit.',$row['project_id'],$row['assignment_id']);
    return az_work_submission_data(az_work_submission_row($row['id'],$user),$user);
}
function az_work_notifications(array $user): array
{
    $rows=az_work_query('SELECT * FROM work_notifications WHERE recipient_id=? ORDER BY created_at DESC,id DESC LIMIT 200',[$user['id']])->fetchAll();$out=[];
    foreach($rows as$r){
        if(in_array($r['kind'],['production_member_added','open_shot'],true)&&!az_work_roster_member($r['project_id'],(int)$user['id']))continue;
        if($r['kind']==='open_shot'){
            try{$shot=az_work_shot_row($r['shot_id']??'',$user);if($shot['project_id']!==$r['project_id']||$shot['project_status']!=='active'||$shot['status']!=='active'||$shot['mode']!=='open')continue;}catch(AzHttpError){continue;}
        }
        try{if($r['assignment_id'])az_work_assignment_access($r['assignment_id'],$user);else az_work_project_row($r['project_id'],$user);}catch(AzHttpError){continue;}
        $out[]=['id'=>$r['id'],'kind'=>$r['kind'],'title'=>$r['title'],'message'=>$r['message'],'projectId'=>$r['project_id'],'assignmentId'=>$r['assignment_id'],'readAt'=>$r['read_at'],'createdAt'=>$r['created_at'],'url'=>az_url((az_has_role($user,'admin')?'productions.php?':'my-work.php?').($r['assignment_id']?'assignmentId='.$r['assignment_id']:'projectId='.$r['project_id']))];
    }
    return['notifications'=>$out,'unreadCount'=>count(array_filter($out,static fn($r)=>$r['readAt']===null))];
}
function az_work_get(string $action,array $query,array $user): mixed
{
    $user=az_work_live_user($user);az_work_assert_role($user);
    if($action==='bootstrap')return['user'=>$user,'csrf'=>az_csrf_token(),'timezone'=>AZ_SCHEDULE_TIMEZONE,'canManage'=>az_has_role($user,'admin'),'users'=>az_has_role($user,'admin')?az_work_users($user):[],'links'=>az_has_role($user,'admin')?az_work_schedule_links($user):['events'=>[],'appointments'=>[]],'limits'=>['maxShots'=>200,'maxContributors'=>50,'maxTakesRequested'=>20,'maxTargetDurationSeconds'=>3600]+az_limits(),'unreadCount'=>az_work_notifications($user)['unreadCount']];
    if($action==='users')return az_work_users($user);
    if($action==='scheduleLinks')return az_work_schedule_links($user);
    if($action==='roster')return az_work_roster_data(az_work_id($query['projectId']??null),$user);
    if($action==='project')return az_work_project_detail(az_work_id($query['id']??null),$user);
    if($action==='shot')return az_work_shot_detail(az_work_id($query['id']??null),$user);
    if($action==='assignment')return az_work_assignment_detail(az_work_id($query['id']??null),$user);
    if($action==='notifications')return az_work_notifications($user);
    if($action==='projects'){
        $rows=az_work_query("SELECT p.* FROM production_projects p JOIN users u ON u.id=p.owner_id WHERE u.status='active' AND EXISTS(SELECT 1 FROM user_roles r WHERE r.user_id=u.id AND r.role='admin') ORDER BY p.updated_at DESC,p.id DESC")->fetchAll();$out=[];
        foreach($rows as$r)if(az_work_project_visible($r,$user))$out[]=az_work_project_data($r,$user);return$out;
    }
    if($action==='myWork'){
        $out=['assigned'=>[],'open'=>[],'submitted'=>[],'completed'=>[],'projects'=>[]];
        foreach(az_work_query("SELECT p.* FROM production_projects p JOIN production_members m ON m.project_id=p.id WHERE m.user_id=? AND m.status='active' AND p.status='active' ORDER BY p.updated_at DESC,p.id DESC",[$user['id']])->fetchAll()as$project){
            try{$visible=az_work_project_row($project['id'],$user);$out['projects'][]=az_work_project_data($visible,$user);}catch(AzHttpError$e){if(in_array($e->status,[403,404],true))continue;throw$e;}
        }
        foreach(az_work_query('SELECT id FROM shot_assignments WHERE user_id=? ORDER BY updated_at DESC,id DESC',[$user['id']])->fetchAll(PDO::FETCH_COLUMN)as$id){
            try{$r=az_work_assignment_access($id,$user);$data=az_work_assignment_data($r,$user);}catch(AzHttpError$e){if(in_array($e->status,[403,404],true))continue;throw$e;}
            $bucket=$r['status']==='submitted'?'submitted':(in_array($r['status'],['approved','declined','cancelled'],true)||in_array($r['project_status'],['completed','archived'],true)?'completed':'assigned');$out[$bucket][]=$data;
        }
        foreach(az_work_query("SELECT s.id FROM production_shots s JOIN production_projects p ON p.id=s.project_id JOIN production_members m ON m.project_id=p.id AND m.user_id=? AND m.status='active' WHERE s.mode='open' AND s.status='active' AND p.status='active' ORDER BY s.due_at,s.sort_order,s.id",[$user['id']])->fetchAll(PDO::FETCH_COLUMN)as$id){try{$r=az_work_shot_row($id,$user);$data=az_work_shot_data($r,$user);if(!$data['myAssignment']||in_array($data['myAssignment']['status'],['declined','cancelled'],true))$out['open'][]=$data;}catch(AzHttpError$e){if(in_array($e->status,[403,404],true))continue;throw$e;}}
        return$out;
    }throw new AzHttpError(404,'This production action was not found.','unknown_action');
}
function az_work_post_mutate(string $action,array $body,array $user): mixed
{
    if($action==='saveProject')return az_work_save_project($body,$user);
    if($action==='saveShot')return az_work_save_shot($body,$user);
    if($action==='addMembers')return az_work_add_members($body,$user);
    if($action==='removeMember')return az_work_remove_member($body,$user);
    if($action==='assign'||$action==='claim')return az_work_assign($body,$user,$action==='claim');
    if($action==='respond'||$action==='cancelAssignment')return az_work_respond($body,$user,$action==='cancelAssignment');
    if($action==='submitAssignment')return az_work_submit($body,$user);
    if($action==='reviewSubmission')return az_work_review($body,$user);
    if(in_array($action,['favoriteSubmission','addSubmissionNote','deleteSubmissionNote'],true)){
        $row=az_work_submission_row(az_work_id($body['submissionId']??null),$user,true);
        if($action==='favoriteSubmission')az_work_query('UPDATE takes SET is_favorite=? WHERE id=?',[az_bool($body['favorite']??null,'Favorite')?1:0,$row['take_id']]);
        elseif($action==='addSubmissionNote')az_add_take_note(['takeId'=>$row['take_id'],'atMs'=>$body['atMs']??0,'text'=>$body['text']??''],(int)$user['id']);
        else az_delete_take_note(['takeId'=>$row['take_id'],'noteId'=>$body['noteId']??''],(int)$user['id']);
        return az_work_submission_data(az_work_submission_row($row['id'],$user),$user);
    }
    if($action==='markNotificationsRead'){
        if(az_bool($body['all']??false,'Mark all read'))az_work_query('UPDATE work_notifications SET read_at=? WHERE recipient_id=? AND read_at IS NULL',[az_now(),$user['id']]);
        else{
            $ids=$body['ids']??[];if(!is_array($ids)||!array_is_list($ids)||count($ids)>200)throw new AzHttpError(400,'Choose up to 200 notifications.','invalid_field');
            foreach($ids as$id)az_work_query('UPDATE work_notifications SET read_at=? WHERE id=? AND recipient_id=? AND read_at IS NULL',[az_now(),az_work_id($id),$user['id']]);
        }return['unreadCount'=>az_work_notifications($user)['unreadCount']];
    }
    throw new AzHttpError(404,'This production action was not found.','unknown_action');
}
function az_work_post(string $action,array $body,array $user): mixed
{
    $user=az_work_live_user($user);az_work_assert_role($user);
    $types=['saveProject'=>'project','saveShot'=>'shot','addMembers'=>'roster','removeMember'=>'roster','assign'=>'assignment','claim'=>'assignment','respond'=>'assignment','cancelAssignment'=>'assignment','submitAssignment'=>'assignment','reviewSubmission'=>'submission','favoriteSubmission'=>'submission','addSubmissionNote'=>'submission','deleteSubmissionNote'=>'submission'];
    $token=null;$hash=null;$type=$types[$action]??null;
    if(isset($body['requestId'])&&$type){
        $token=az_text($body['requestId'],160,'Request ID');if(!preg_match('/\A[a-zA-Z0-9_.:-]{8,160}\z/D',$token))throw new AzHttpError(400,'Use a unique request ID of 8 to 160 letters, digits or separators.','invalid_request_id');
        $canonical=$body;unset($canonical['requestId']);ksort($canonical);$hash=hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR));
        $old=az_work_query('SELECT * FROM work_requests WHERE user_id=? AND client_token=?',[$user['id'],$token])->fetch();
        if($old){
            if($old['action']!==$action||!hash_equals($old['request_hash'],$hash))throw new AzHttpError(409,'This request ID was already used for a different change.','request_conflict');
            return match($old['resource_type']){'project'=>az_work_project_detail($old['resource_id'],$user),'roster'=>az_work_roster_data($old['resource_id'],$user),'shot'=>az_work_shot_detail($old['resource_id'],$user),'assignment'=>az_work_assignment_data(az_work_assignment_access($old['resource_id'],$user),$user),'submission'=>az_work_submission_data(az_work_submission_row($old['resource_id'],$user),$user)};
        }
    }
    $data=az_work_post_mutate($action,$body,$user);
    if($token&&$type&&isset($data['id']))az_work_query('INSERT INTO work_requests(user_id,client_token,action,request_hash,resource_type,resource_id,created_at) VALUES(?,?,?,?,?,?,?)',[$user['id'],$token,$action,$hash,$type,$data['id'],az_now()]);
    return$data;
}
