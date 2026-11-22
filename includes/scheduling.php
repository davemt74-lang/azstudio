<?php
declare(strict_types=1);

const AZ_SCHEDULE_TIMEZONE = 'America/Phoenix';

function az_upgrade_schedule_schema(PDO $db): void
{
    if (az_schema_version($db) >= 5) return;
    az_prepare_storage();
    $lock = fopen(AZ_STORAGE . '/schema-migration.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Could not lock the calendar database upgrade.');
    try {
        if (az_schema_version($db) >= 5) return;
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $uid = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        foreach ([
            "CREATE TABLE IF NOT EXISTS schedule_classes (id CHAR(32) PRIMARY KEY, title VARCHAR(200) NOT NULL, description TEXT NOT NULL, instructor_id $uid NULL, duration_minutes INTEGER NOT NULL, min_capacity INTEGER NOT NULL, max_capacity INTEGER NOT NULL, allowed_roles_json TEXT NOT NULL, location VARCHAR(400) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'active', revision INTEGER NOT NULL DEFAULT 1, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, FOREIGN KEY (instructor_id) REFERENCES users(id))$suffix",
            "CREATE TABLE IF NOT EXISTS schedule_events (id CHAR(32) PRIMARY KEY, type VARCHAR(16) NOT NULL, class_id CHAR(32) NULL, title VARCHAR(200) NOT NULL, description TEXT NOT NULL, start_at VARCHAR(32) NOT NULL, end_at VARCHAR(32) NOT NULL, location VARCHAR(400) NOT NULL, min_capacity INTEGER NOT NULL, max_capacity INTEGER NOT NULL, allowed_roles_json TEXT NOT NULL, instructor_id $uid NULL, status VARCHAR(20) NOT NULL DEFAULT 'draft', revision INTEGER NOT NULL DEFAULT 1, created_by $uid NOT NULL, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, FOREIGN KEY (class_id) REFERENCES schedule_classes(id), FOREIGN KEY (instructor_id) REFERENCES users(id), FOREIGN KEY (created_by) REFERENCES users(id))$suffix",
            "CREATE TABLE IF NOT EXISTS schedule_registrations (event_id CHAR(32) NOT NULL, user_id $uid NOT NULL, status VARCHAR(20) NOT NULL, attendance VARCHAR(20) NOT NULL DEFAULT 'unmarked', queue_order BIGINT NOT NULL, registered_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, attendance_at VARCHAR(32) NULL, attendance_by $uid NULL, PRIMARY KEY (event_id,user_id), FOREIGN KEY (event_id) REFERENCES schedule_events(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id), FOREIGN KEY (attendance_by) REFERENCES users(id))$suffix",
            "CREATE TABLE IF NOT EXISTS appointment_types (id CHAR(32) PRIMARY KEY, professional_id $uid NOT NULL, name VARCHAR(200) NOT NULL, description TEXT NOT NULL, duration_minutes INTEGER NOT NULL, buffer_before_minutes INTEGER NOT NULL, buffer_after_minutes INTEGER NOT NULL, location VARCHAR(400) NOT NULL, min_lead_hours INTEGER NOT NULL, max_advance_days INTEGER NOT NULL, slot_step_minutes INTEGER NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'active', revision INTEGER NOT NULL DEFAULT 1, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, FOREIGN KEY (professional_id) REFERENCES users(id))$suffix",
            "CREATE TABLE IF NOT EXISTS professional_availability (professional_id $uid PRIMARY KEY, weekly_json TEXT NOT NULL, revision INTEGER NOT NULL DEFAULT 1, updated_at VARCHAR(32) NOT NULL, FOREIGN KEY (professional_id) REFERENCES users(id))$suffix",
            "CREATE TABLE IF NOT EXISTS schedule_blackouts (id CHAR(32) PRIMARY KEY, professional_id $uid NOT NULL, start_at VARCHAR(32) NOT NULL, end_at VARCHAR(32) NOT NULL, reason VARCHAR(500) NOT NULL, created_at VARCHAR(32) NOT NULL, FOREIGN KEY (professional_id) REFERENCES users(id))$suffix",
            "CREATE TABLE IF NOT EXISTS schedule_appointments (id CHAR(32) PRIMARY KEY, type_id CHAR(32) NOT NULL, type_name VARCHAR(200) NOT NULL, professional_id $uid NOT NULL, customer_id $uid NOT NULL, start_at VARCHAR(32) NOT NULL, end_at VARCHAR(32) NOT NULL, busy_start_at VARCHAR(32) NOT NULL, busy_end_at VARCHAR(32) NOT NULL, duration_minutes INTEGER NOT NULL, buffer_before_minutes INTEGER NOT NULL, buffer_after_minutes INTEGER NOT NULL, location VARCHAR(400) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'confirmed', notes TEXT NOT NULL, revision INTEGER NOT NULL DEFAULT 1, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, FOREIGN KEY (type_id) REFERENCES appointment_types(id), FOREIGN KEY (professional_id) REFERENCES users(id), FOREIGN KEY (customer_id) REFERENCES users(id))$suffix",
        ] as $sql) $db->exec($sql);
        foreach ([['schedule_events_time','schedule_events','start_at,end_at,status'],['schedule_events_instructor','schedule_events','instructor_id,status,start_at'],['schedule_registrations_user','schedule_registrations','user_id,status'],['schedule_registration_queue','schedule_registrations','event_id,status,queue_order'],['appointment_types_professional','appointment_types','professional_id,status'],['schedule_appointments_professional','schedule_appointments','professional_id,status,busy_start_at,busy_end_at'],['schedule_appointments_customer','schedule_appointments','customer_id,status,start_at'],['schedule_blackouts_professional','schedule_blackouts','professional_id,start_at,end_at']] as [$name,$table,$columns]) {
            if ($mysql) {
                $q=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');$q->execute([$table,$name]);
                if (!(int)$q->fetchColumn()) $db->exec("CREATE INDEX $name ON $table ($columns)");
            } else $db->exec("CREATE INDEX IF NOT EXISTS $name ON $table ($columns)");
        }
        $db->exec('UPDATE studio_schema SET version=5 WHERE id=1');
    } finally { flock($lock,LOCK_UN);fclose($lock); }
}

function az_sched_query(string $sql, array $args=[]): PDOStatement { $q=az_db()->prepare($sql);$q->execute($args);return $q; }
function az_sched_admin(array $user): bool { return az_has_role($user,'admin'); }
function az_sched_assert_admin(array $user): void { if (!az_sched_admin($user)) throw new AzHttpError(403,'Only an administrator can manage events and classes.','role_required'); }
function az_sched_id(mixed $id): string { if (!is_string($id)||!az_valid_id($id)) throw new AzHttpError(404,'This schedule item was not found.','not_found');return $id; }
function az_sched_row(string $table,mixed $id): array { $id=az_sched_id($id);$row=az_sched_query("SELECT * FROM $table WHERE id=?",[$id])->fetch();if(!$row)throw new AzHttpError(404,'This schedule item was not found.','not_found');return $row; }
function az_sched_enum(mixed $value,array $allowed,string $field): string { if(!is_string($value)||!in_array($value,$allowed,true))throw new AzHttpError(400,'Choose a valid '.$field.'.','invalid_field');return $value; }
function az_sched_revision(array $body,array $row): void { if(!isset($body['revision'])||az_integer($body['revision'],1,PHP_INT_MAX,'Revision')!==(int)$row['revision'])throw new AzHttpError(409,'This item changed. Reload it before saving.','revision_conflict'); }
function az_sched_time(mixed $value,bool $local=false): string
{
    if(!is_string($value))throw new AzHttpError(400,'Enter a valid date and time.','invalid_time');
    if($local){
        if(!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\z/D',$value))throw new AzHttpError(400,'Enter a date and time in the studio timezone.','invalid_time');
        $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new DateTimeZone(AZ_SCHEDULE_TIMEZONE));
        if(!$date||$date->format('Y-m-d\TH:i')!==$value)throw new AzHttpError(400,'That date or time does not exist.','invalid_time');
    }else{
        if(!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/D',$value))throw new AzHttpError(400,'Choose a valid available time.','invalid_time');
        try{$date=new DateTimeImmutable($value);}catch(Throwable){throw new AzHttpError(400,'Choose a valid available time.','invalid_time');}
        if($date->format('Y-m-d\TH:i:s')!==substr($value,0,19))throw new AzHttpError(400,'That date or time does not exist.','invalid_time');
    }
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}
function az_sched_local(string $utc): string { return (new DateTimeImmutable($utc))->setTimezone(new DateTimeZone(AZ_SCHEDULE_TIMEZONE))->format('Y-m-d\TH:i'); }
function az_sched_shift(string $utc,int $minutes): string { return gmdate('Y-m-d\TH:i:s\Z',strtotime($utc)+$minutes*60); }
function az_sched_span(array $body): array
{
    $start=isset($body['startLocal'])?az_sched_time($body['startLocal'],true):az_sched_time($body['startAt']??null);
    $end=isset($body['endLocal'])?az_sched_time($body['endLocal'],true):az_sched_time($body['endAt']??null);
    if($end<=$start||strtotime($end)-strtotime($start)>7*86400)throw new AzHttpError(400,'The end must follow the start, within seven days.','invalid_time_range');
    return [$start,$end];
}
function az_sched_range(array $query,bool $history=false): array
{
    $today=new DateTimeImmutable('today',new DateTimeZone(AZ_SCHEDULE_TIMEZONE));
    $from=$query['from']??$today->modify($history?'-90 days':'+0 days')->format('Y-m-d');$to=$query['to']??$today->modify('+90 days')->format('Y-m-d');
    foreach([$from,$to]as$date)if(!is_string($date)||!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$date))throw new AzHttpError(400,'Use calendar dates for this range.','invalid_range');
    $from=az_sched_time($from.'T00:00',true);$to=az_sched_time($to.'T00:00',true);
    if($to<=$from||strtotime($to)-strtotime($from)>370*86400)throw new AzHttpError(400,'Choose a calendar range of up to one year.','invalid_range');
    return[$from,$to];
}
function az_sched_roles(mixed $roles): array
{
    if(!is_array($roles)||!array_is_list($roles)||!$roles)throw new AzHttpError(400,'Choose at least one eligible role.','invalid_roles');
    foreach($roles as$role)az_sched_enum($role,['model','fan','customer','professional'],'eligible role');
    return array_values(array_unique($roles));
}
function az_sched_eligible(array $user,array $row): bool { return az_sched_admin($user)||(bool)array_intersect($user['roles']??[],json_decode($row['allowed_roles_json'],true)?:[]); }
function az_sched_professional(int $id,bool $required=true): ?array
{
    $user=az_account_user($id);
    if(!$user||$user['status']!=='active'||!az_has_role($user,'professional')){if($required)throw new AzHttpError(409,'This professional is not available for new bookings.','professional_unavailable');return null;}
    return$user;
}
function az_sched_provider_control(array $user,int $id): void { if(!az_sched_admin($user)&&(!az_has_role($user,'professional')||(int)$user['id']!==$id))throw new AzHttpError(403,'You can manage only your own professional schedule.','role_required'); }
function az_sched_instructor(mixed $value): ?int { if($value===null||$value==='')return null;$id=az_integer($value,1,PHP_INT_MAX,'Instructor');az_sched_professional($id);return$id; }
function az_sched_event_manage(array $event,array $user): bool { return az_sched_admin($user)||(az_has_role($user,'professional')&&(int)$event['instructor_id']===(int)$user['id']); }
function az_sched_event_visible(array $row,array $user): bool { return az_sched_event_manage($row,$user)||($row['status']!=='draft'&&az_sched_eligible($user,$row)); }
function az_sched_event(mixed $id,array $user,bool $visible=true): array { $row=az_sched_row('schedule_events',$id);if($visible&&!az_sched_event_visible($row,$user))throw new AzHttpError(404,'This event was not found.','not_found');return$row; }
function az_sched_registration(string $eventId,int $userId): ?array { return az_sched_query('SELECT * FROM schedule_registrations WHERE event_id=? AND user_id=?',[$eventId,$userId])->fetch()?:null; }
function az_sched_event_data(array $row,array $user): array
{
    $registered=(int)az_sched_query("SELECT COUNT(*) FROM schedule_registrations WHERE event_id=? AND status='registered'",[$row['id']])->fetchColumn();
    $waiting=(int)az_sched_query("SELECT COUNT(*) FROM schedule_registrations WHERE event_id=? AND status='waitlisted'",[$row['id']])->fetchColumn();
    $mine=az_sched_registration($row['id'],(int)$user['id']);$instructor=$row['instructor_id']?az_account_user((int)$row['instructor_id']):null;
    return['id'=>$row['id'],'type'=>$row['type'],'classId'=>$row['class_id'],'title'=>$row['title'],'description'=>$row['description'],'startAt'=>$row['start_at'],'endAt'=>$row['end_at'],'startLocal'=>az_sched_local($row['start_at']),'endLocal'=>az_sched_local($row['end_at']),'timezone'=>AZ_SCHEDULE_TIMEZONE,'location'=>$row['location'],'minCapacity'=>(int)$row['min_capacity'],'maxCapacity'=>(int)$row['max_capacity'],'allowedRoles'=>json_decode($row['allowed_roles_json'],true),'instructorId'=>$row['instructor_id']?(int)$row['instructor_id']:null,'instructorName'=>$instructor['name']??'','status'=>$row['status'],'revision'=>(int)$row['revision'],'registeredCount'=>$registered,'waitlistCount'=>$waiting,'spotsRemaining'=>max(0,(int)$row['max_capacity']-$registered),'minimumMet'=>$registered>=(int)$row['min_capacity'],'minimumRemaining'=>max(0,(int)$row['min_capacity']-$registered),'myRegistration'=>$mine?['status'=>$mine['status'],'attendance'=>$mine['attendance'],'registeredAt'=>$mine['registered_at']]:null,'canManage'=>az_sched_admin($user),'canManageAttendance'=>az_sched_event_manage($row,$user),'canRegister'=>$row['status']==='published'&&$row['start_at']>az_now()&&az_sched_eligible($user,$row)&&(int)$row['instructor_id']!==(int)$user['id']];
}
function az_sched_roster(array $event,array $user): array
{
    if(!az_sched_event_manage($event,$user))throw new AzHttpError(403,'Only the event administrator or assigned professional can view its attendance list.','roster_forbidden');
    $rows=az_sched_query('SELECT r.*,u.name FROM schedule_registrations r JOIN users u ON u.id=r.user_id WHERE event_id=? ORDER BY queue_order,user_id',[$event['id']])->fetchAll();
    return['event'=>az_sched_event_data($event,$user),'attendees'=>array_map(static fn($r)=>['userId'=>(int)$r['user_id'],'name'=>$r['name'],'status'=>$r['status'],'attendance'=>$r['attendance'],'registeredAt'=>$r['registered_at'],'attendanceAt'=>$r['attendance_at']],$rows)];
}
function az_sched_professional_conflict(int $id,string $start,string $end,?string $skipAppointment=null,?string $skipEvent=null,bool $blackouts=true): bool
{
    if(az_sched_person_conflict($id,$start,$end,$skipAppointment,$skipEvent))return true;
    if(az_sched_query("SELECT id FROM schedule_appointments WHERE professional_id=? AND status='confirmed' AND busy_start_at<? AND busy_end_at>? AND id<>?",[$id,$end,$start,$skipAppointment??''])->fetch())return true;
    if(az_sched_query("SELECT id FROM schedule_events WHERE instructor_id=? AND status='published' AND start_at<? AND end_at>? AND id<>?",[$id,$end,$start,$skipEvent??''])->fetch())return true;
    return$blackouts&&(bool)az_sched_query('SELECT id FROM schedule_blackouts WHERE professional_id=? AND start_at<? AND end_at>?',[$id,$end,$start])->fetch();
}
function az_sched_person_conflict(int $id,string $start,string $end,?string $skipAppointment=null,?string $skipEvent=null): bool
{
    if(az_sched_query("SELECT id FROM schedule_appointments WHERE status='confirmed' AND ((customer_id=? AND start_at<? AND end_at>?) OR (professional_id=? AND busy_start_at<? AND busy_end_at>?)) AND id<>?",[$id,$end,$start,$id,$end,$start,$skipAppointment??''])->fetch())return true;
    return(bool)az_sched_query("SELECT e.id FROM schedule_events e LEFT JOIN schedule_registrations r ON r.event_id=e.id AND r.user_id=? AND r.status='registered' WHERE e.status='published' AND (r.user_id IS NOT NULL OR e.instructor_id=?) AND e.start_at<? AND e.end_at>? AND e.id<>?",[$id,$id,$end,$start,$skipEvent??''])->fetch();
}
function az_sched_promote(array $event): void
{
    if($event['status']!=='published'||$event['start_at']<=az_now())return;
    $count=(int)az_sched_query("SELECT COUNT(*) FROM schedule_registrations WHERE event_id=? AND status='registered'",[$event['id']])->fetchColumn();
    $waiting=az_sched_query("SELECT * FROM schedule_registrations WHERE event_id=? AND status='waitlisted' ORDER BY queue_order,user_id",[$event['id']])->fetchAll();
    foreach($waiting as$row){
        if($count>=(int)$event['max_capacity'])break;
        $member=az_account_user((int)$row['user_id']);
        if(!$member||$member['status']!=='active'||!az_sched_eligible($member,$event)||az_sched_person_conflict((int)$row['user_id'],$event['start_at'],$event['end_at'],null,$event['id']))continue;
        az_sched_query("UPDATE schedule_registrations SET status='registered',updated_at=? WHERE event_id=? AND user_id=?",[az_now(),$event['id'],$row['user_id']]);$count++;
    }
}
function az_sched_capacity(array $body,array $old=[]): array
{
    $min=az_integer($body['minCapacity']??$old['min_capacity']??0,0,10000,'Minimum capacity');$max=az_integer($body['maxCapacity']??$old['max_capacity']??12,1,10000,'Maximum capacity');
    if($min>$max)throw new AzHttpError(400,'Minimum capacity cannot exceed maximum capacity.','invalid_capacity');return[$min,$max];
}
function az_sched_save_event(array $body,array $user): array
{
    az_sched_assert_admin($user);$old=isset($body['id'])?az_sched_row('schedule_events',$body['id']):null;if($old)az_sched_revision($body,$old);
    $title=az_text($body['title']??$old['title']??'',200,'Event title');if($title==='')throw new AzHttpError(400,'Enter an event title.','invalid_field');
    $description=az_text($body['description']??$old['description']??'',16000,'Description');$location=az_text($body['location']??$old['location']??'',400,'Location');
    [$start,$end]=(isset($body['startLocal'])||isset($body['startAt'])||isset($body['endLocal'])||isset($body['endAt']))?az_sched_span($body):[$old['start_at']??'', $old['end_at']??''];
    if(!$start||!$end)throw new AzHttpError(400,'Enter event start and end times.','invalid_time');
    [$min,$max]=az_sched_capacity($body,$old??[]);$roles=az_sched_roles($body['allowedRoles']??($old?json_decode($old['allowed_roles_json'],true):['model','fan','customer','professional']));
    $status=az_sched_enum($body['status']??$old['status']??'draft',['draft','published','cancelled','completed'],'event status');
    $instructorValue=$body['instructorId']??$old['instructor_id']??null;
    if(array_key_exists('instructorId',$body)&&$body['instructorId']===null)$instructorValue=null;
    $keepInactive=$old&&$status!=='published'&&(string)$instructorValue===(string)($old['instructor_id']??'');
    $instructor=$keepInactive?($old['instructor_id']?(int)$old['instructor_id']:null):az_sched_instructor($instructorValue);
    if($status==='completed'&&$start>az_now())throw new AzHttpError(400,'An event can be completed after it starts.','event_not_started');
    if($old){
        $registered=(int)az_sched_query("SELECT COUNT(*) FROM schedule_registrations WHERE event_id=? AND status='registered'",[$old['id']])->fetchColumn();
        $enrolled=(int)az_sched_query("SELECT COUNT(*) FROM schedule_registrations WHERE event_id=? AND status IN ('registered','waitlisted')",[$old['id']])->fetchColumn();
        if($status!=='cancelled'&&$max<$registered)throw new AzHttpError(409,'Maximum capacity cannot be lower than the registered attendance.','capacity_below_registrations');
        $oldRoles=json_decode($old['allowed_roles_json'],true);sort($oldRoles);$sorted=$roles;sort($sorted);
        if($enrolled&&$status!=='cancelled'&&($start!==$old['start_at']||$end!==$old['end_at']||(int)$instructor!==(int)$old['instructor_id']||$sorted!==$oldRoles||$status==='draft'))throw new AzHttpError(409,'This event has registrations. Cancel it before changing its time, instructor, eligible roles, or publication.','registered_event_change');
        if(in_array($old['status'],['cancelled','completed'],true)&&$status!==$old['status'])throw new AzHttpError(409,'Create a new event instead of reopening a cancelled or completed session.','event_closed');
    }
    if($status==='published'&&$instructor){az_sched_professional($instructor);if(az_sched_professional_conflict($instructor,$start,$end,null,$old['id']??null))throw new AzHttpError(409,'This professional already has an appointment, event, or blocked time here.','schedule_conflict');}
    $id=$old['id']??az_id();$now=az_now();$json=json_encode($roles,JSON_THROW_ON_ERROR);
    if($old)az_sched_query('UPDATE schedule_events SET title=?,description=?,start_at=?,end_at=?,location=?,min_capacity=?,max_capacity=?,allowed_roles_json=?,instructor_id=?,status=?,revision=revision+1,updated_at=? WHERE id=?',[$title,$description,$start,$end,$location,$min,$max,$json,$instructor,$status,$now,$id]);
    else az_sched_query('INSERT INTO schedule_events (id,type,class_id,title,description,start_at,end_at,location,min_capacity,max_capacity,allowed_roles_json,instructor_id,status,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$id,'event',null,$title,$description,$start,$end,$location,$min,$max,$json,$instructor,$status,$user['id'],$now,$now]);
    if($status==='cancelled')az_sched_query("UPDATE schedule_registrations SET status='cancelled',updated_at=? WHERE event_id=? AND status<>'cancelled'",[$now,$id]);
    $event=az_sched_row('schedule_events',$id);az_sched_promote($event);return az_sched_event_data($event,$user);
}
function az_sched_register(array $body,array $user): array
{
    $event=az_sched_event($body['eventId']??null,$user);
    if($event['status']!=='published'||$event['start_at']<=az_now()||!az_sched_eligible($user,$event))throw new AzHttpError(409,'Registration is not open for this event.','registration_closed');
    if((int)$event['instructor_id']===(int)$user['id'])throw new AzHttpError(409,'You are already scheduled as the instructor.','schedule_conflict');
    $old=az_sched_registration($event['id'],(int)$user['id']);if($old&&in_array($old['status'],['registered','waitlisted'],true))return az_sched_event_data($event,$user);
    if(az_sched_person_conflict((int)$user['id'],$event['start_at'],$event['end_at'],null,$event['id']))throw new AzHttpError(409,'You already have an event or appointment at this time.','schedule_conflict');
    az_sched_promote($event);
    $count=(int)az_sched_query("SELECT COUNT(*) FROM schedule_registrations WHERE event_id=? AND status='registered'",[$event['id']])->fetchColumn();
    $status=$count<(int)$event['max_capacity']?'registered':'waitlisted';$order=(int)az_sched_query('SELECT COALESCE(MAX(queue_order),0)+1 FROM schedule_registrations WHERE event_id=?',[$event['id']])->fetchColumn();$now=az_now();
    if($old)az_sched_query("UPDATE schedule_registrations SET status=?,attendance='unmarked',queue_order=?,registered_at=?,updated_at=?,attendance_at=NULL,attendance_by=NULL WHERE event_id=? AND user_id=?",[$status,$order,$now,$now,$event['id'],$user['id']]);
    else az_sched_query('INSERT INTO schedule_registrations (event_id,user_id,status,queue_order,registered_at,updated_at) VALUES (?,?,?,?,?,?)',[$event['id'],$user['id'],$status,$order,$now,$now]);
    return az_sched_event_data($event,$user);
}
function az_sched_cancel_registration(array $body,array $user): array
{
    $event=az_sched_event($body['eventId']??null,$user,false);$id=isset($body['userId'])?az_integer($body['userId'],1,PHP_INT_MAX,'Member'):(int)$user['id'];
    if($id!==(int)$user['id']&&!az_sched_event_manage($event,$user))throw new AzHttpError(403,'You can cancel only your own registration.','registration_forbidden');
    if($event['start_at']<=az_now()&&!az_sched_event_manage($event,$user))throw new AzHttpError(409,'This event has started. Ask the host to update attendance.','event_started');
    $old=az_sched_registration($event['id'],$id);if(!$old)throw new AzHttpError(404,'This registration was not found.','not_found');
    az_sched_query("UPDATE schedule_registrations SET status='cancelled',updated_at=? WHERE event_id=? AND user_id=?",[az_now(),$event['id'],$id]);az_sched_promote($event);
    if(!az_sched_event_visible($event,$user))return['id'=>$event['id'],'cancelled'=>true];return az_sched_event_data($event,$user);
}
function az_sched_class_data(array $row,array $user): array
{
    $p=$row['instructor_id']?az_account_user((int)$row['instructor_id']):null;
    return['id'=>$row['id'],'title'=>$row['title'],'description'=>$row['description'],'instructorId'=>$row['instructor_id']?(int)$row['instructor_id']:null,'instructorName'=>$p['name']??'','durationMinutes'=>(int)$row['duration_minutes'],'minCapacity'=>(int)$row['min_capacity'],'maxCapacity'=>(int)$row['max_capacity'],'allowedRoles'=>json_decode($row['allowed_roles_json'],true),'location'=>$row['location'],'status'=>$row['status'],'revision'=>(int)$row['revision'],'canManage'=>az_sched_admin($user)];
}
function az_sched_save_class(array $body,array $user): array
{
    az_sched_assert_admin($user);$old=isset($body['id'])?az_sched_row('schedule_classes',$body['id']):null;if($old)az_sched_revision($body,$old);
    $title=az_text($body['title']??$old['title']??'',200,'Class title');if(!$title)throw new AzHttpError(400,'Enter a class title.','invalid_field');
    $description=az_text($body['description']??$old['description']??'',16000,'Description');$location=az_text($body['location']??$old['location']??'',400,'Location');
    $status=az_sched_enum($body['status']??$old['status']??'active',['active','archived'],'class status');
    $instructorValue=$body['instructorId']??$old['instructor_id']??null;if(array_key_exists('instructorId',$body)&&$body['instructorId']===null)$instructorValue=null;
    $keepInactive=$old&&$status==='archived'&&(string)$instructorValue===(string)($old['instructor_id']??'');
    $instructor=$keepInactive?($old['instructor_id']?(int)$old['instructor_id']:null):az_sched_instructor($instructorValue);$duration=az_integer($body['durationMinutes']??$old['duration_minutes']??60,5,1440,'Duration');[$min,$max]=az_sched_capacity($body,$old??[]);
    $roles=az_sched_roles($body['allowedRoles']??($old?json_decode($old['allowed_roles_json'],true):['model','fan','customer','professional']));
    $id=$old['id']??az_id();$now=az_now();
    if($old)az_sched_query('UPDATE schedule_classes SET title=?,description=?,instructor_id=?,duration_minutes=?,min_capacity=?,max_capacity=?,allowed_roles_json=?,location=?,status=?,revision=revision+1,updated_at=? WHERE id=?',[$title,$description,$instructor,$duration,$min,$max,json_encode($roles),$location,$status,$now,$id]);
    else az_sched_query('INSERT INTO schedule_classes (id,title,description,instructor_id,duration_minutes,min_capacity,max_capacity,allowed_roles_json,location,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$title,$description,$instructor,$duration,$min,$max,json_encode($roles),$location,$status,$now,$now]);
    return az_sched_class_data(az_sched_row('schedule_classes',$id),$user);
}
function az_sched_class_sessions(array $body,array $user): array
{
    az_sched_assert_admin($user);$class=az_sched_row('schedule_classes',$body['classId']??null);if($class['status']!=='active')throw new AzHttpError(409,'Restore this class before scheduling sessions.','class_archived');
    $first=az_sched_time($body['startLocal']??null,true);$count=az_integer($body['count']??1,1,12,'Session count');$interval=az_integer($body['intervalDays']??7,1,90,'Session interval');$status=az_sched_enum($body['status']??'published',['draft','published'],'session status');$out=[];
    for($i=0;$i<$count;$i++){
        $start=az_sched_shift($first,$i*$interval*1440);$end=az_sched_shift($start,(int)$class['duration_minutes']);
        if($status==='published'&&$class['instructor_id']){az_sched_professional((int)$class['instructor_id']);if(az_sched_professional_conflict((int)$class['instructor_id'],$start,$end))throw new AzHttpError(409,'A session overlaps this professional’s appointment, event, or blocked time. No sessions were saved.','schedule_conflict');}
        $id=az_id();$now=az_now();az_sched_query('INSERT INTO schedule_events (id,type,class_id,title,description,start_at,end_at,location,min_capacity,max_capacity,allowed_roles_json,instructor_id,status,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$id,'class',$class['id'],$class['title'],$class['description'],$start,$end,$class['location'],$class['min_capacity'],$class['max_capacity'],$class['allowed_roles_json'],$class['instructor_id'],$status,$user['id'],$now,$now]);$out[]=az_sched_event_data(az_sched_row('schedule_events',$id),$user);
    }return$out;
}

function az_sched_type_data(array $row,array $user): array
{
    $professional=az_account_user((int)$row['professional_id']);
    return['id'=>$row['id'],'professionalId'=>(int)$row['professional_id'],'professionalName'=>$professional['name']??'','professionalActive'=>(bool)az_sched_professional((int)$row['professional_id'],false),'name'=>$row['name'],'description'=>$row['description'],'durationMinutes'=>(int)$row['duration_minutes'],'bufferBeforeMinutes'=>(int)$row['buffer_before_minutes'],'bufferAfterMinutes'=>(int)$row['buffer_after_minutes'],'location'=>$row['location'],'minLeadHours'=>(int)$row['min_lead_hours'],'maxAdvanceDays'=>(int)$row['max_advance_days'],'slotStepMinutes'=>(int)$row['slot_step_minutes'],'status'=>$row['status'],'revision'=>(int)$row['revision'],'canManage'=>az_sched_admin($user)||(az_has_role($user,'professional')&&(int)$user['id']===(int)$row['professional_id'])];
}
function az_sched_save_type(array $body,array $user): array
{
    $old=isset($body['id'])?az_sched_row('appointment_types',$body['id']):null;
    $professional=az_integer($body['professionalId']??$old['professional_id']??$user['id'],1,PHP_INT_MAX,'Professional');az_sched_provider_control($user,$professional);
    if($old){az_sched_provider_control($user,(int)$old['professional_id']);az_sched_revision($body,$old);if($professional!==(int)$old['professional_id'])throw new AzHttpError(409,'Create a separate appointment type for another professional.','professional_change');}
    $name=az_text($body['name']??$old['name']??'',200,'Appointment type');if($name==='')throw new AzHttpError(400,'Enter an appointment type name.','invalid_field');
    $description=az_text($body['description']??$old['description']??'',16000,'Description');$location=az_text($body['location']??$old['location']??'',400,'Location');
    $duration=az_integer($body['durationMinutes']??$old['duration_minutes']??60,5,480,'Duration');$before=az_integer($body['bufferBeforeMinutes']??$old['buffer_before_minutes']??0,0,240,'Preparation buffer');$after=az_integer($body['bufferAfterMinutes']??$old['buffer_after_minutes']??0,0,240,'After-appointment buffer');
    $lead=az_integer($body['minLeadHours']??$old['min_lead_hours']??2,0,720,'Minimum notice');$advance=az_integer($body['maxAdvanceDays']??$old['max_advance_days']??90,1,365,'Booking horizon');$step=az_integer($body['slotStepMinutes']??$old['slot_step_minutes']??15,5,60,'Slot interval');
    $status=az_sched_enum($body['status']??$old['status']??'active',['active','archived'],'appointment type status');if($status==='active')az_sched_professional($professional);
    $id=$old['id']??az_id();$now=az_now();
    if($old)az_sched_query('UPDATE appointment_types SET name=?,description=?,duration_minutes=?,buffer_before_minutes=?,buffer_after_minutes=?,location=?,min_lead_hours=?,max_advance_days=?,slot_step_minutes=?,status=?,revision=revision+1,updated_at=? WHERE id=?',[$name,$description,$duration,$before,$after,$location,$lead,$advance,$step,$status,$now,$id]);
    else az_sched_query('INSERT INTO appointment_types (id,professional_id,name,description,duration_minutes,buffer_before_minutes,buffer_after_minutes,location,min_lead_hours,max_advance_days,slot_step_minutes,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$professional,$name,$description,$duration,$before,$after,$location,$lead,$advance,$step,$status,$now,$now]);
    return az_sched_type_data(az_sched_row('appointment_types',$id),$user);
}
function az_sched_weekly(mixed $value): array
{
    if(!is_array($value)||!array_is_list($value)||count($value)>42)throw new AzHttpError(400,'Use up to 42 weekly working intervals.','invalid_availability');$out=[];
    foreach($value as$entry){
        if($entry instanceof stdClass)$entry=get_object_vars($entry);if(!is_array($entry))throw new AzHttpError(400,'Enter a valid working interval.','invalid_availability');
        $day=az_integer($entry['weekday']??null,0,6,'Weekday');$start=$entry['start']??'';$end=$entry['end']??'';
        if(!is_string($start)||!is_string($end)||!preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D',$start)||!preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D',$end)||$end<=$start)throw new AzHttpError(400,'Working intervals must start and end on the same day, with the end after the start.','invalid_availability');
        foreach($out as$existing)if($existing['weekday']===$day&&$existing['start']<$end&&$existing['end']>$start)throw new AzHttpError(400,'Weekly working intervals cannot overlap.','overlapping_availability');
        $out[]=['weekday'=>$day,'start'=>$start,'end'=>$end];
    }
    usort($out,static fn($a,$b)=>[$a['weekday'],$a['start']]<=>[$b['weekday'],$b['start']]);return$out;
}
function az_sched_weekly_for(int $id): array { $row=az_sched_query('SELECT weekly_json FROM professional_availability WHERE professional_id=?',[$id])->fetch();return$row?(json_decode($row['weekly_json'],true)?:[]):[]; }
function az_sched_within_hours(array $weekly,string $start,string $end): bool
{
    $a=(new DateTimeImmutable($start))->setTimezone(new DateTimeZone(AZ_SCHEDULE_TIMEZONE));$b=(new DateTimeImmutable($end))->setTimezone(new DateTimeZone(AZ_SCHEDULE_TIMEZONE));
    if($a->format('Y-m-d')!==$b->format('Y-m-d'))return false;
    foreach($weekly as$entry)if((int)$entry['weekday']===(int)$a->format('w')&&$a->format('H:i:s')>=$entry['start'].':00'&&$b->format('H:i:s')<=$entry['end'].':00')return true;
    return false;
}
function az_sched_blackout_data(array $row): array { return['id'=>$row['id'],'professionalId'=>(int)$row['professional_id'],'startAt'=>$row['start_at'],'endAt'=>$row['end_at'],'startLocal'=>az_sched_local($row['start_at']),'endLocal'=>az_sched_local($row['end_at']),'reason'=>$row['reason']]; }
function az_sched_availability(int $id,array $user): array
{
    az_sched_provider_control($user,$id);if(!az_account_user($id))throw new AzHttpError(404,'This professional was not found.','not_found');
    return['professionalId'=>$id,'timezone'=>AZ_SCHEDULE_TIMEZONE,'weekly'=>az_sched_weekly_for($id),'blackouts'=>array_map('az_sched_blackout_data',az_sched_query('SELECT * FROM schedule_blackouts WHERE professional_id=? ORDER BY start_at DESC LIMIT 1000',[$id])->fetchAll())];
}
function az_sched_save_availability(array $body,array $user): array
{
    $id=az_integer($body['professionalId']??$user['id'],1,PHP_INT_MAX,'Professional');az_sched_provider_control($user,$id);az_sched_professional($id);$weekly=az_sched_weekly($body['weekly']??null);
    foreach(az_sched_query("SELECT * FROM schedule_appointments WHERE professional_id=? AND status='confirmed' AND end_at>?",[$id,az_now()])->fetchAll()as$booking)if(!az_sched_within_hours($weekly,$booking['busy_start_at'],$booking['busy_end_at']))throw new AzHttpError(409,'These hours exclude an existing appointment. Reschedule or cancel that appointment first.','availability_has_bookings');
    $exists=az_sched_query('SELECT professional_id FROM professional_availability WHERE professional_id=?',[$id])->fetch();
    if($exists)az_sched_query('UPDATE professional_availability SET weekly_json=?,revision=revision+1,updated_at=? WHERE professional_id=?',[json_encode($weekly),az_now(),$id]);
    else az_sched_query('INSERT INTO professional_availability (professional_id,weekly_json,updated_at) VALUES (?,?,?)',[$id,json_encode($weekly),az_now()]);
    return az_sched_availability($id,$user);
}
function az_sched_save_blackout(array $body,array $user): array
{
    $id=az_integer($body['professionalId']??$user['id'],1,PHP_INT_MAX,'Professional');az_sched_provider_control($user,$id);az_sched_professional($id);[$start,$end]=az_sched_span($body);
    if(az_sched_professional_conflict($id,$start,$end,null,null,false))throw new AzHttpError(409,'Blocked time overlaps a confirmed appointment or assigned event. Reschedule or cancel it first.','schedule_conflict');
    $key=az_id();az_sched_query('INSERT INTO schedule_blackouts (id,professional_id,start_at,end_at,reason,created_at) VALUES (?,?,?,?,?,?)',[$key,$id,$start,$end,az_text($body['reason']??'',500,'Reason'),az_now()]);return az_sched_blackout_data(az_sched_row('schedule_blackouts',$key));
}
function az_sched_booking_manage(array $row,array $user): bool { return az_sched_admin($user)||(az_has_role($user,'professional')&&(int)$row['professional_id']===(int)$user['id']); }
function az_sched_booking(mixed $id,array $user): array { $row=az_sched_row('schedule_appointments',$id);if((int)$row['customer_id']!==(int)$user['id']&&!az_sched_booking_manage($row,$user))throw new AzHttpError(404,'This appointment was not found.','not_found');return$row; }
function az_sched_booking_data(array $row,array $user): array
{
    $p=az_account_user((int)$row['professional_id']);$c=az_account_user((int)$row['customer_id']);$editable=$row['status']==='confirmed'&&$row['start_at']>az_now();$manage=az_sched_booking_manage($row,$user);
    return['id'=>$row['id'],'typeId'=>$row['type_id'],'typeName'=>$row['type_name'],'professionalId'=>(int)$row['professional_id'],'professionalName'=>$p['name']??'','customerId'=>(int)$row['customer_id'],'customerName'=>$c['name']??'','startAt'=>$row['start_at'],'endAt'=>$row['end_at'],'startLocal'=>az_sched_local($row['start_at']),'endLocal'=>az_sched_local($row['end_at']),'timezone'=>AZ_SCHEDULE_TIMEZONE,'status'=>$row['status'],'notes'=>$row['notes'],'location'=>$row['location'],'durationMinutes'=>(int)$row['duration_minutes'],'bufferBeforeMinutes'=>(int)$row['buffer_before_minutes'],'bufferAfterMinutes'=>(int)$row['buffer_after_minutes'],'revision'=>(int)$row['revision'],'canManage'=>$manage,'canCancel'=>$row['status']==='confirmed'&&($editable||$manage),'canReschedule'=>$editable];
}
function az_sched_slot_span(array $type,string $start): array
{
    $end=az_sched_shift($start,(int)$type['duration_minutes']);return[$end,az_sched_shift($start,-(int)$type['buffer_before_minutes']),az_sched_shift($end,(int)$type['buffer_after_minutes'])];
}
function az_sched_slot_available(array $type,string $start,int $customer,?string $skip=null,?array $weekly=null): bool
{
    $stamp=strtotime($start);$local=(new DateTimeImmutable($start))->setTimezone(new DateTimeZone(AZ_SCHEDULE_TIMEZONE));$minutes=(int)$local->format('H')*60+(int)$local->format('i');
    if($stamp<time()+(int)$type['min_lead_hours']*3600||$stamp>time()+(int)$type['max_advance_days']*86400||$local->format('s')!=='00'||$minutes%(int)$type['slot_step_minutes']!==0)return false;
    [$end,$busyStart,$busyEnd]=az_sched_slot_span($type,$start);$professional=(int)$type['professional_id'];
    if(!az_sched_within_hours($weekly??az_sched_weekly_for($professional),$busyStart,$busyEnd))return false;
    if(az_sched_professional_conflict($professional,$busyStart,$busyEnd,$skip))return false;
    return!az_sched_person_conflict($customer,$start,$end,$skip);
}
function az_sched_slots(array $query,array $user): array
{
    $type=az_sched_row('appointment_types',$query['typeId']??null);$customer=(int)$user['id'];$skip=null;$existing=null;
    if(isset($query['appointmentId'])){
        $existing=az_sched_booking($query['appointmentId'],$user);if($existing['type_id']!==$type['id']||$existing['status']!=='confirmed'||$existing['start_at']<=az_now())throw new AzHttpError(409,'This appointment cannot be rescheduled.','appointment_closed');
        $skip=$existing['id'];$customer=(int)$existing['customer_id'];foreach(['duration_minutes','buffer_before_minutes','buffer_after_minutes']as$key)$type[$key]=$existing[$key];
    }elseif(isset($query['customerId'])){if(!az_sched_admin($user))throw new AzHttpError(403,'Only an administrator can book for another member.','role_required');$customer=az_integer($query['customerId'],1,PHP_INT_MAX,'Customer');}
    if($type['status']!=='active'&&!$existing)throw new AzHttpError(409,'This appointment type is no longer accepting bookings.','type_archived');
    az_sched_professional((int)$type['professional_id']);$member=az_account_user($customer);if(!$member||$member['status']!=='active')throw new AzHttpError(409,'This member account cannot book appointments.','account_unavailable');
    $date=$query['date']??'';if(!is_string($date)||!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$date))throw new AzHttpError(400,'Choose a date to see available times.','invalid_time');
    $midnight=az_sched_time($date.'T00:00',true);$slots=[];$weekly=az_sched_weekly_for((int)$type['professional_id']);
    if($customer!==(int)$type['professional_id'])for($minute=0;$minute<1440;$minute+=(int)$type['slot_step_minutes']){
        $start=az_sched_shift($midnight,$minute);if(!az_sched_slot_available($type,$start,$customer,$skip,$weekly))continue;[$end]=az_sched_slot_span($type,$start);$slots[]=['startAt'=>$start,'endAt'=>$end,'startLocal'=>az_sched_local($start),'endLocal'=>az_sched_local($end)];
    }
    return['date'=>$date,'timezone'=>AZ_SCHEDULE_TIMEZONE,'slots'=>$slots];
}
function az_sched_book(array $body,array $user): array
{
    $type=az_sched_row('appointment_types',$body['typeId']??null);$customer=(int)$user['id'];
    if(isset($body['customerId'])&&(int)$body['customerId']!==$customer){if(!az_sched_admin($user))throw new AzHttpError(403,'Only an administrator can book for another member.','role_required');$customer=az_integer($body['customerId'],1,PHP_INT_MAX,'Customer');}
    $member=az_account_user($customer);if(!$member||$member['status']!=='active')throw new AzHttpError(409,'This member account cannot book appointments.','account_unavailable');
    if($customer===(int)$type['professional_id'])throw new AzHttpError(400,'Choose another professional for your appointment.','self_booking');
    $start=az_sched_time($body['startAt']??null);
    $duplicate=az_sched_query("SELECT * FROM schedule_appointments WHERE type_id=? AND customer_id=? AND start_at=? AND status='confirmed'",[$type['id'],$customer,$start])->fetch();if($duplicate)return az_sched_booking_data($duplicate,$user);
    if($type['status']!=='active')throw new AzHttpError(409,'This appointment type is no longer accepting bookings.','type_archived');az_sched_professional((int)$type['professional_id']);
    if(!az_sched_slot_available($type,$start,$customer))throw new AzHttpError(409,'That time is no longer available. Choose a fresh available time.','slot_unavailable');
    [$end,$busyStart,$busyEnd]=az_sched_slot_span($type,$start);$id=az_id();$now=az_now();$notes=az_text($body['notes']??'',4000,'Appointment notes');
    az_sched_query('INSERT INTO schedule_appointments (id,type_id,type_name,professional_id,customer_id,start_at,end_at,busy_start_at,busy_end_at,duration_minutes,buffer_before_minutes,buffer_after_minutes,location,notes,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$type['id'],$type['name'],$type['professional_id'],$customer,$start,$end,$busyStart,$busyEnd,$type['duration_minutes'],$type['buffer_before_minutes'],$type['buffer_after_minutes'],$type['location'],$notes,$now,$now]);
    return az_sched_booking_data(az_sched_row('schedule_appointments',$id),$user);
}
function az_sched_reschedule(array $body,array $user): array
{
    $old=az_sched_booking($body['id']??null,$user);if($old['status']!=='confirmed'||$old['start_at']<=az_now())throw new AzHttpError(409,'Only a future confirmed appointment can be rescheduled.','appointment_closed');
    $member=az_account_user((int)$old['customer_id']);if(!$member||$member['status']!=='active')throw new AzHttpError(409,'This member account cannot book appointments.','account_unavailable');az_sched_professional((int)$old['professional_id']);
    $type=az_sched_row('appointment_types',$old['type_id']);foreach(['duration_minutes','buffer_before_minutes','buffer_after_minutes']as$key)$type[$key]=$old[$key];$start=az_sched_time($body['startAt']??null);
    if(!az_sched_slot_available($type,$start,(int)$old['customer_id'],$old['id']))throw new AzHttpError(409,'That time is no longer available. Choose a fresh available time.','slot_unavailable');
    [$end,$busyStart,$busyEnd]=az_sched_slot_span($type,$start);az_sched_query('UPDATE schedule_appointments SET start_at=?,end_at=?,busy_start_at=?,busy_end_at=?,updated_at=?,revision=revision+1 WHERE id=?',[$start,$end,$busyStart,$busyEnd,az_now(),$old['id']]);return az_sched_booking_data(az_sched_row('schedule_appointments',$old['id']),$user);
}
function az_sched_appointments(array $query,array $user): array
{
    [$from,$to]=az_sched_range($query,true);$args=[$to,$from];$sql='SELECT * FROM schedule_appointments WHERE start_at<? AND end_at>?';
    if(!az_sched_admin($user)){
        if(az_has_role($user,'professional')){$sql.=' AND (customer_id=? OR professional_id=?)';$args[]=$user['id'];$args[]=$user['id'];}
        else{$sql.=' AND customer_id=?';$args[]=$user['id'];}
    }
    if(isset($query['status'])&&$query['status']!==''){$sql.=' AND status=?';$args[]=az_sched_enum($query['status'],['confirmed','cancelled','completed','no_show'],'appointment status');}
    return array_map(static fn($row)=>az_sched_booking_data($row,$user),az_sched_query($sql.' ORDER BY start_at,id',$args)->fetchAll());
}
function az_schedule_get(string $action,array $query,array $user): mixed
{
    if($action==='bootstrap'){
        $professionals=az_sched_query("SELECT u.id,u.name FROM users u JOIN user_roles r ON r.user_id=u.id WHERE u.status='active' AND r.role='professional' ORDER BY u.name,u.id")->fetchAll();
        return['user'=>$user,'csrf'=>az_csrf_token(),'timezone'=>AZ_SCHEDULE_TIMEZONE,'professionals'=>array_map(static fn($p)=>['id'=>(int)$p['id'],'name'=>$p['name']],$professionals),'canManageEvents'=>az_sched_admin($user),'canManageAppointments'=>az_sched_admin($user)||az_has_role($user,'professional')];
    }
    if($action==='events'){
        [$from,$to]=az_sched_range($query);$sql='SELECT * FROM schedule_events WHERE start_at<? AND end_at>?';$args=[$to,$from];
        if(isset($query['type'])&&$query['type']!==''){$sql.=' AND type=?';$args[]=az_sched_enum($query['type'],['event','class'],'event type');}
        if(isset($query['classId'])&&$query['classId']!==''){$sql.=' AND class_id=?';$args[]=az_sched_id($query['classId']);}
        $rows=array_filter(az_sched_query($sql.' ORDER BY start_at,id',$args)->fetchAll(),static fn($row)=>az_sched_event_visible($row,$user));return array_values(array_map(static fn($row)=>az_sched_event_data($row,$user),$rows));
    }
    if($action==='event')return az_sched_event_data(az_sched_event($query['id']??null,$user),$user);
    if($action==='roster')return az_sched_roster(az_sched_event($query['eventId']??null,$user),$user);
    if($action==='classes'){
        $rows=az_sched_query('SELECT * FROM schedule_classes ORDER BY title,id')->fetchAll();$rows=array_filter($rows,static fn($r)=>az_sched_admin($user)||($r['status']==='active'&&az_sched_eligible($user,$r)));return array_values(array_map(static fn($r)=>az_sched_class_data($r,$user),$rows));
    }
    if($action==='class'){$row=az_sched_row('schedule_classes',$query['id']??null);if(!az_sched_admin($user)&&($row['status']!=='active'||!az_sched_eligible($user,$row)))throw new AzHttpError(404,'This class was not found.','not_found');return az_sched_class_data($row,$user);}
    if($action==='appointmentTypes'){
        $args=[];$sql='SELECT * FROM appointment_types';if(isset($query['professionalId'])&&$query['professionalId']!==''){$sql.=' WHERE professional_id=?';$args[]=az_integer($query['professionalId'],1,PHP_INT_MAX,'Professional');}$out=[];
        foreach(az_sched_query($sql.' ORDER BY name,id',$args)->fetchAll()as$row){$data=az_sched_type_data($row,$user);if(($row['status']==='active'&&$data['professionalActive'])||($data['canManage']&&($query['includeArchived']??'')==='1'))$out[]=$data;}return$out;
    }
    if($action==='availability')return az_sched_availability(az_integer($query['professionalId']??$user['id'],1,PHP_INT_MAX,'Professional'),$user);
    if($action==='slots')return az_sched_slots($query,$user);
    if($action==='appointments')return az_sched_appointments($query,$user);
    if($action==='appointment')return az_sched_booking_data(az_sched_booking($query['id']??null,$user),$user);
    throw new AzHttpError(404,'This calendar action was not found.','unknown_action');
}
function az_schedule_post(string $action,array $body,array $user): mixed
{
    if($action==='saveEvent')return az_sched_save_event($body,$user);
    if($action==='registerEvent')return az_sched_register($body,$user);
    if($action==='cancelRegistration')return az_sched_cancel_registration($body,$user);
    if($action==='setAttendance'){
        $event=az_sched_event($body['eventId']??null,$user);if(!az_sched_event_manage($event,$user))throw new AzHttpError(403,'Only the event administrator or assigned professional can record attendance.','attendance_forbidden');
        $member=az_integer($body['userId']??null,1,PHP_INT_MAX,'Member');$registration=az_sched_registration($event['id'],$member);if(!$registration||$registration['status']!=='registered')throw new AzHttpError(409,'Attendance is available only for registered members.','registration_required');
        $attendance=az_sched_enum($body['attendance']??null,['unmarked','present','absent','excused'],'attendance');az_sched_query('UPDATE schedule_registrations SET attendance=?,attendance_at=?,attendance_by=?,updated_at=? WHERE event_id=? AND user_id=?',[$attendance,$attendance==='unmarked'?null:az_now(),$attendance==='unmarked'?null:$user['id'],az_now(),$event['id'],$member]);return az_sched_roster($event,$user);
    }
    if($action==='saveClass')return az_sched_save_class($body,$user);
    if($action==='saveClassSessions')return az_sched_class_sessions($body,$user);
    if($action==='saveAppointmentType')return az_sched_save_type($body,$user);
    if($action==='saveAvailability')return az_sched_save_availability($body,$user);
    if($action==='saveBlackout')return az_sched_save_blackout($body,$user);
    if($action==='deleteBlackout'){$row=az_sched_row('schedule_blackouts',$body['id']??null);az_sched_provider_control($user,(int)$row['professional_id']);az_sched_query('DELETE FROM schedule_blackouts WHERE id=?',[$row['id']]);return['id'=>$row['id'],'deleted'=>true];}
    if($action==='bookAppointment')return az_sched_book($body,$user);
    if($action==='rescheduleAppointment')return az_sched_reschedule($body,$user);
    if($action==='cancelAppointment'){
        $row=az_sched_booking($body['id']??null,$user);if($row['status']==='cancelled')return az_sched_booking_data($row,$user);
        if($row['status']!=='confirmed'||($row['start_at']<=az_now()&&!az_sched_booking_manage($row,$user)))throw new AzHttpError(409,'This appointment can no longer be cancelled. Ask the professional to update its attendance.','appointment_closed');
        az_sched_query("UPDATE schedule_appointments SET status='cancelled',revision=revision+1,updated_at=? WHERE id=?",[az_now(),$row['id']]);return az_sched_booking_data(az_sched_row('schedule_appointments',$row['id']),$user);
    }
    if($action==='updateAppointmentStatus'){
        $row=az_sched_booking($body['id']??null,$user);if(!az_sched_booking_manage($row,$user))throw new AzHttpError(403,'Only the professional or an administrator can record appointment attendance.','role_required');
        $status=az_sched_enum($body['status']??null,['completed','no_show'],'appointment status');if($row['status']==='cancelled'||$row['start_at']>az_now())throw new AzHttpError(409,'Attendance can be recorded after a confirmed appointment starts.','appointment_not_started');
        az_sched_query('UPDATE schedule_appointments SET status=?,revision=revision+1,updated_at=? WHERE id=?',[$status,az_now(),$row['id']]);return az_sched_booking_data(az_sched_row('schedule_appointments',$row['id']),$user);
    }
    throw new AzHttpError(404,'This calendar action was not found.','unknown_action');
}
