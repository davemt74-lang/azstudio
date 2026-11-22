<?php
declare(strict_types=1);

/** Production coverage is a checklist of reviewed source material, not visual AI. */
function az_upgrade_director_schema(PDO $db): void
{
    if (az_schema_version($db) >= 10) return;
    if (az_schema_version($db) < 9) throw new RuntimeException('Install the production planner schema before the Director.');
    az_prepare_storage();
    $lock = fopen(AZ_STORAGE . '/schema-migration.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Could not lock the Director database upgrade.');
    try {
        if (az_schema_version($db) >= 10) return;
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $uid = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        foreach ([
            "CREATE TABLE IF NOT EXISTS production_requirements (id CHAR(32) PRIMARY KEY,project_id CHAR(32) NOT NULL,title VARCHAR(200) NOT NULL,purpose VARCHAR(24) NOT NULL,minimum_selections INTEGER NOT NULL DEFAULT 1,target_duration_seconds INTEGER NOT NULL DEFAULT 0,is_required INTEGER NOT NULL DEFAULT 1,sort_order INTEGER NOT NULL DEFAULT 0,revision INTEGER NOT NULL DEFAULT 1,created_at VARCHAR(32) NOT NULL,updated_at VARCHAR(32) NOT NULL,FOREIGN KEY(project_id) REFERENCES production_projects(id) ON DELETE CASCADE)$suffix",
            "CREATE TABLE IF NOT EXISTS production_requirement_shots (requirement_id CHAR(32) NOT NULL,shot_id CHAR(32) NOT NULL,PRIMARY KEY(requirement_id,shot_id),FOREIGN KEY(requirement_id) REFERENCES production_requirements(id) ON DELETE CASCADE,FOREIGN KEY(shot_id) REFERENCES production_shots(id) ON DELETE CASCADE)$suffix",
            "CREATE TABLE IF NOT EXISTS production_selections (id CHAR(32) PRIMARY KEY,project_id CHAR(32) NOT NULL,requirement_id CHAR(32) NOT NULL,take_id CHAR(32) NOT NULL,label VARCHAR(200) NOT NULL,notes TEXT NOT NULL,start_ms INTEGER NOT NULL,end_ms INTEGER NOT NULL,preferred INTEGER NOT NULL DEFAULT 0,revision INTEGER NOT NULL DEFAULT 1,created_at VARCHAR(32) NOT NULL,updated_at VARCHAR(32) NOT NULL,FOREIGN KEY(project_id) REFERENCES production_projects(id) ON DELETE CASCADE,FOREIGN KEY(requirement_id) REFERENCES production_requirements(id) ON DELETE CASCADE,FOREIGN KEY(take_id) REFERENCES takes(id) ON DELETE CASCADE)$suffix",
            "CREATE TABLE IF NOT EXISTS director_requests (owner_id $uid NOT NULL,request_token VARCHAR(160) NOT NULL,project_id CHAR(32) NOT NULL,action VARCHAR(40) NOT NULL,request_hash CHAR(64) NOT NULL,resource_id CHAR(32) NULL,created_at VARCHAR(32) NOT NULL,PRIMARY KEY(owner_id,request_token),FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(project_id) REFERENCES production_projects(id) ON DELETE CASCADE)$suffix",
        ] as $sql) $db->exec($sql);
        foreach ([['director_requirements_project','production_requirements','project_id,sort_order'], ['director_selections_project','production_selections','project_id,requirement_id,take_id']] as [$name,$table,$columns]) {
            if ($mysql) {
                $q=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');$q->execute([$table,$name]);
                if (!(int)$q->fetchColumn()) $db->exec("CREATE INDEX $name ON $table ($columns)");
            } else $db->exec("CREATE INDEX IF NOT EXISTS $name ON $table ($columns)");
        }
        $db->exec('UPDATE studio_schema SET version=10 WHERE id=1');
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}

function az_director_project(string $id,array $user,bool $mutate=false): array
{
    $user=az_work_live_user($user);az_work_assert_admin($user);
    $project=az_work_project_row($id,$user,true);
    if ($mutate && !in_array($project['status'],['draft','active'],true)) throw new AzHttpError(409,'Reopen this production before changing its coverage or usable sections.','work_closed');
    return $project;
}

function az_director_requirement(string $id,string $projectId): array
{
    $row=az_work_query('SELECT * FROM production_requirements WHERE id=? AND project_id=?',[az_work_id($id),$projectId])->fetch();
    if (!$row) throw new AzHttpError(404,'This coverage requirement was not found.','not_found');
    return $row;
}

function az_director_requirement_data(array $r,array $shotIds=[]): array
{
    return ['id'=>$r['id'],'projectId'=>$r['project_id'],'title'=>$r['title'],'purpose'=>$r['purpose'],
        'minimumSelections'=>(int)$r['minimum_selections'],'targetDurationSeconds'=>(int)$r['target_duration_seconds'],
        'required'=>(bool)$r['is_required'],'sortOrder'=>(int)$r['sort_order'],'revision'=>(int)$r['revision'],
        'shotIds'=>$shotIds,'createdAt'=>$r['created_at'],'updatedAt'=>$r['updated_at']];
}

function az_director_selection_data(array $r,array $eligible): array
{
    $take=$eligible[$r['take_id']]??null;
    $valid=$take && (int)$r['start_ms']>=0 && (int)$r['end_ms']<=(int)$take['durationMs'] && (int)$r['end_ms']-(int)$r['start_ms']>=100;
    return ['id'=>$r['id'],'projectId'=>$r['project_id'],'requirementId'=>$r['requirement_id'],'takeId'=>$r['take_id'],
        'label'=>$r['label'],'notes'=>$r['notes'],'startMs'=>(int)$r['start_ms'],'endMs'=>(int)$r['end_ms'],
        'durationMs'=>(int)$r['end_ms']-(int)$r['start_ms'],'preferred'=>(bool)$r['preferred'],'revision'=>(int)$r['revision'],
        'eligible'=>(bool)$valid,'createdAt'=>$r['created_at'],'updatedAt'=>$r['updated_at']];
}

function az_director_union_duration(array $ranges): int
{
    usort($ranges,static fn($a,$b)=>$a[0]<=>$b[0] ?: $a[1]<=>$b[1]);
    $total=0;$start=null;$end=0;
    foreach($ranges as [$a,$b]) {
        if($start===null){$start=$a;$end=$b;}
        elseif($a<=$end)$end=max($end,$b);
        else{$total+=$end-$start;$start=$a;$end=$b;}
    }
    return $total+($start===null?0:$end-$start);
}

function az_director_candidate(array $requirement,array $take,?array $selection,string $kind): array
{
    return ['requirementId'=>$requirement['id'],'takeId'=>$take['id'],'selectionId'=>$selection['id']??null,
        'title'=>$requirement['title'],'label'=>$selection['label']??$take['label'],'notes'=>$selection['notes']??'',
        'startMs'=>$selection['startMs']??0,'endMs'=>$selection['endMs']??(int)$take['durationMs'],
        'preferred'=>(bool)($selection['preferred']??false),'isFavorite'=>(bool)$take['isFavorite'],
        'showCard'=>$kind==='interview'||($kind==='mixed'&&$take['sourceKind']==='interview'),
        'sourceKind'=>$take['sourceKind'],'durationSource'=>'uploaded_metadata'];
}

/** Pure, stable source snapshot. The editable timeline is intentionally absent. */
function az_director_snapshot(string $projectId,array $user): array
{
    $project=az_director_project($projectId,$user);
    $interview=az_interview_row($project['interview_id'],(int)$user['id']);
    // Use exactly the editor's current accepted-source and imported-provenance gate.
    $editor=az_work_prepare_editor(['id'=>$interview['id'],'state'=>[]],$user);
    $takes=$editor['takes']??[];$eligible=array_column($takes,null,'id');
    $shots=az_work_query('SELECT * FROM production_shots WHERE project_id=? ORDER BY sort_order,created_at,id',[$projectId])->fetchAll();
    $shotData=[];
    foreach($shots as $shot)$shotData[]=az_work_shot_data(az_work_shot_row($shot['id'],$user,true),$user);
    $links=az_work_query('SELECT rs.requirement_id,rs.shot_id FROM production_requirement_shots rs JOIN production_requirements r ON r.id=rs.requirement_id WHERE r.project_id=? ORDER BY rs.shot_id',[$projectId])->fetchAll();
    $shotLinks=[];foreach($links as $link)$shotLinks[$link['requirement_id']][]=$link['shot_id'];
    $requirements=array_map(static fn($r)=>az_director_requirement_data($r,$shotLinks[$r['id']]??[]),az_work_query('SELECT * FROM production_requirements WHERE project_id=? ORDER BY sort_order,created_at,id',[$projectId])->fetchAll());
    $rawSelections=az_work_query('SELECT * FROM production_selections WHERE project_id=? ORDER BY created_at,id',[$projectId])->fetchAll();
    $selections=array_map(static fn($r)=>az_director_selection_data($r,$eligible),$rawSelections);
    $selectionMap=[];foreach($selections as $s)$selectionMap[$s['requirementId']][$s['takeId']][]=$s;
    $pending=az_work_query("SELECT a.id AS assignment_id,a.shot_id,a.status AS assignment_status,s.id AS submission_id,s.status AS submission_status FROM shot_assignments a JOIN production_shots sh ON sh.id=a.shot_id LEFT JOIN shot_submissions s ON s.assignment_id=a.id WHERE sh.project_id=? AND a.status NOT IN ('cancelled','declined') ORDER BY a.id,s.id",[$projectId])->fetchAll();
    $candidates=[];
    $summary=['status'=>'unplanned','requiredCount'=>0,'readyCount'=>0,'missingCount'=>0,'awaitingReviewCount'=>0,'retakeCount'=>0,'totalRequirements'=>count($requirements)];
    foreach($requirements as &$requirement){
        $candidateTakes=[];$ranges=[];
        foreach($takes as $take){
            $explicit=$selectionMap[$requirement['id']][$take['id']]??[];
            if(!$explicit&&!in_array($take['questionId'],$requirement['shotIds'],true))continue;
            $valid=array_values(array_filter($explicit,static fn($s)=>$s['eligible']));
            // An invalid curated range does not silently widen to the whole take.
            if($explicit&&!$valid)continue;
            // A duration-less accepted upload cannot yet supply a usable clip,
            // even when the requirement has no minimum duration target.
            if(!$explicit&&(int)$take['durationMs']<100)continue;
            $candidateTakes[$take['id']]=true;
            if($explicit){foreach($valid as $s){$ranges[$take['id']][]=[$s['startMs'],$s['endMs']];$candidates[]=az_director_candidate($requirement,$take,$s,$project['kind']);}}
            elseif((int)$take['durationMs']>=100){$ranges[$take['id']][]=[0,(int)$take['durationMs']];$candidates[]=az_director_candidate($requirement,$take,null,$project['kind']);}
        }
        $duration=array_sum(array_map('az_director_union_duration',array_values($ranges)));
        $waiting=[];$retakes=[];$actions=[];
        foreach($pending as $p){
            if(!in_array($p['shot_id'],$requirement['shotIds'],true))continue;
            if($p['submission_status']==='submitted')$waiting[$p['assignment_id']]=true;
            // Actionable current assignment state, not historical rejected takes.
            if($p['assignment_status']==='changes_requested')$retakes[$p['assignment_id']]=true;
        }
        $missing=max(0,$requirement['minimumSelections']-count($candidateTakes));
        $durationMissing=max(0,$requirement['targetDurationSeconds']*1000-$duration);
        $status=$missing===0&&$durationMissing===0?'ready':($waiting?'awaiting_review':($retakes?'retake_needed':'missing'));
        if($status!=='ready')$actions[]=['type'=>'create_shot','label'=>'Create or open missing shot'];
        foreach($requirement['shotIds'] as $shotId)$actions[]=['type'=>'open_shot','label'=>'Open linked shot','shotId'=>$shotId,'url'=>az_url('productions.php?shotId='.$shotId)];
        foreach(array_keys($waiting) as $id)$actions[]=['type'=>'review','label'=>'Review submitted footage','assignmentId'=>$id,'url'=>az_url('productions.php?assignmentId='.$id)];
        foreach(array_keys($retakes) as $id)$actions[]=['type'=>'retake','label'=>'View retake request','assignmentId'=>$id,'url'=>az_url('productions.php?assignmentId='.$id)];
        $requirement['coverage']=['status'=>$status,'acceptedCount'=>count($candidateTakes),'minimumSelections'=>$requirement['minimumSelections'],
            'durationMs'=>$duration,'targetDurationMs'=>$requirement['targetDurationSeconds']*1000,'awaitingReviewCount'=>count($waiting),'retakeCount'=>count($retakes),
            'missingCount'=>$missing,'durationMissingMs'=>$durationMissing,'shotIds'=>$requirement['shotIds'],'actions'=>$actions];
        if($requirement['required']){$summary['requiredCount']++;if($status==='ready')$summary['readyCount']++;else$summary['missingCount']++;}
        $summary['awaitingReviewCount']+=count($waiting);$summary['retakeCount']+=count($retakes);
    }
    unset($requirement);
    if($requirements)$summary['status']=$summary['requiredCount']===$summary['readyCount']?'ready':'incomplete';
    $data=az_work_project_data($project,$user);
    $data['interviewId']=$project['interview_id'];$data['productionUrl']=az_url('productions.php?projectId='.$projectId);$data['shots']=$shotData;
    // Exclude derived URLs, CSRF and mutable timeline data from the digest.
    $digest=['project'=>array_intersect_key($project,array_flip(['id','title','brief','product_line','kind','status','revision'])),
        'requirements'=>array_map(static function($r){unset($r['coverage']);return$r;},$requirements),'selections'=>$selections,
        'shots'=>array_map(static fn($s)=>array_intersect_key($s,array_flip(['id','title','brief','status','revision','sort_order','orientation','target_duration_seconds'])),$shots),
        'takes'=>array_map(static fn($t)=>array_intersect_key($t,array_flip(['id','questionId','questionText','label','durationMs','isFavorite','reviewStatus','reviewNotes','sourceKind','submissionId'])),$takes),
        'pending'=>$pending];
    $fingerprint=hash('sha256',json_encode($digest,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    return ['project'=>$data,'requirements'=>$requirements,'selections'=>$selections,'eligibleTakes'=>$takes,'candidates'=>$candidates,
        'coverage'=>$summary,'fingerprint'=>$fingerprint,'csrf'=>az_csrf_token(),'limits'=>['maxRequirements'=>100,'maxSelections'=>500,'maxMinimumSelections'=>20,'maxTargetDurationSeconds'=>3600,'minSelectionMs'=>100]];
}

function az_director_save_requirement(array $body,array $project,array $user): void
{
    $old=isset($body['id'])?az_director_requirement(az_work_id($body['id']),$project['id']):null;
    if($old)az_work_revision($body,$old);
    $title=az_text($body['title']??$old['title']??'',200,'Coverage title');if($title==='')throw new AzHttpError(400,'Enter a coverage title.','invalid_field');
    $purpose=az_enum($body['purpose']??$old['purpose']??'other',['opening','product_detail','demonstration','answer','reaction','b_roll','closing','other'],'Shot purpose');
    $minimum=az_integer($body['minimumSelections']??$old['minimum_selections']??1,1,20,'Required usable takes');
    $duration=az_integer($body['targetDurationSeconds']??$old['target_duration_seconds']??0,0,3600,'Coverage duration target');
    $required=array_key_exists('required',$body)?az_bool($body['required'],'Required coverage'):(bool)($old['is_required']??true);
    $order=az_integer($body['sortOrder']??$old['sort_order']??0,0,10000,'Coverage order');
    $shotIds=$body['shotIds']??($old?az_work_query('SELECT shot_id FROM production_requirement_shots WHERE requirement_id=? ORDER BY shot_id',[$old['id']])->fetchAll(PDO::FETCH_COLUMN):[]);
    if(!is_array($shotIds)||!array_is_list($shotIds)||count($shotIds)>200)throw new AzHttpError(400,'Choose up to 200 linked shots.','invalid_field');
    foreach($shotIds as $shotId){$shot=az_work_shot_row(az_work_id($shotId),$user,true);if($shot['project_id']!==$project['id'])throw new AzHttpError(404,'A linked shot was not found in this production.','not_found');}
    $shotIds=array_values(array_unique($shotIds));sort($shotIds);
    if(!$old&&(int)az_work_query('SELECT COUNT(*) FROM production_requirements WHERE project_id=?',[$project['id']])->fetchColumn()>=100)throw new AzHttpError(409,'A production can contain up to 100 coverage requirements.','requirement_limit');
    $id=$old['id']??az_id();$now=az_now();
    if($old)az_work_query('UPDATE production_requirements SET title=?,purpose=?,minimum_selections=?,target_duration_seconds=?,is_required=?,sort_order=?,revision=revision+1,updated_at=? WHERE id=?',[$title,$purpose,$minimum,$duration,$required?1:0,$order,$now,$id]);
    else az_work_query('INSERT INTO production_requirements(id,project_id,title,purpose,minimum_selections,target_duration_seconds,is_required,sort_order,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$id,$project['id'],$title,$purpose,$minimum,$duration,$required?1:0,$order,$now,$now]);
    az_work_query('DELETE FROM production_requirement_shots WHERE requirement_id=?',[$id]);
    foreach($shotIds as $shotId)az_work_query('INSERT INTO production_requirement_shots(requirement_id,shot_id) VALUES(?,?)',[$id,$shotId]);
    az_work_event($project['id'],(int)$user['id'],$old?'coverage_updated':'coverage_created',($old?'Updated ':'Added ').$title.' coverage.');
}

function az_director_save_selection(array $body,array $project,array $user): void
{
    $old=null;
    if(isset($body['id'])){$old=az_work_query('SELECT * FROM production_selections WHERE id=? AND project_id=?',[az_work_id($body['id']),$project['id']])->fetch();if(!$old)throw new AzHttpError(404,'This usable section was not found.','not_found');az_work_revision($body,$old);}
    $requirementId=az_work_id($body['requirementId']??$old['requirement_id']??null);az_director_requirement($requirementId,$project['id']);
    $takeId=az_work_id($body['takeId']??$old['take_id']??null);
    $snapshot=az_director_snapshot($project['id'],$user);$takes=array_column($snapshot['eligibleTakes'],null,'id');$take=$takes[$takeId]??null;
    if(!$take)throw new AzHttpError(409,'This source is no longer approved for the production. Choose a current accepted take.','source_unavailable');
    $duration=(int)$take['durationMs'];
    if($duration<100)throw new AzHttpError(409,'This take needs a known duration before marking a usable section.','source_duration_unknown');
    $start=az_integer($body['startMs']??$old['start_ms']??null,0,$duration,'Section start');
    $end=az_integer($body['endMs']??$old['end_ms']??null,0,$duration,'Section end');
    if($end-$start<100)throw new AzHttpError(400,'Choose a usable section of at least one tenth of a second.','invalid_range');
    $label=az_text($body['label']??$old['label']??$take['label'],200,'Section label');
    $notes=az_text($body['notes']??$old['notes']??'',4000,'Section notes');
    $preferred=array_key_exists('preferred',$body)?az_bool($body['preferred'],'Preferred section'):(bool)($old['preferred']??false);
    if(!$old&&(int)az_work_query('SELECT COUNT(*) FROM production_selections WHERE project_id=?',[$project['id']])->fetchColumn()>=500)throw new AzHttpError(409,'A production can contain up to 500 usable sections.','selection_limit');
    $id=$old['id']??az_id();$now=az_now();
    if($old)az_work_query('UPDATE production_selections SET requirement_id=?,take_id=?,label=?,notes=?,start_ms=?,end_ms=?,preferred=?,revision=revision+1,updated_at=? WHERE id=?',[$requirementId,$takeId,$label,$notes,$start,$end,$preferred?1:0,$now,$id]);
    else az_work_query('INSERT INTO production_selections(id,project_id,requirement_id,take_id,label,notes,start_ms,end_ms,preferred,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$id,$project['id'],$requirementId,$takeId,$label,$notes,$start,$end,$preferred?1:0,$now,$now]);
}

function az_director_missing_shot(array $body,array $project,array $user): array
{
    $requirement=az_director_requirement(az_work_id($body['requirementId']??null),$project['id']);
    az_work_revision($body,$requirement);
    $id=az_work_query("SELECT s.id FROM production_requirement_shots rs JOIN production_shots s ON s.id=rs.shot_id WHERE rs.requirement_id=? AND s.status='active' ORDER BY s.sort_order,s.created_at,s.id LIMIT 1",[$requirement['id']])->fetchColumn();
    if($id)return ['shot'=>az_work_shot_detail($id,$user),'created'=>false];
    $shot=az_work_save_shot(['projectId'=>$project['id'],'title'=>$requirement['title'],
        'brief'=>'Record usable footage for '.$requirement['title'].'.'.((int)$requirement['target_duration_seconds']?' Aim for '.(int)$requirement['target_duration_seconds'].' seconds of usable coverage.':''),
        'mode'=>$body['mode']??'assigned','eligibleRoles'=>$body['eligibleRoles']??['model'],'maxContributors'=>$body['maxContributors']??1,
        'orientation'=>$body['orientation']??'any','targetDurationSeconds'=>(int)$requirement['target_duration_seconds'],
        'takesRequested'=>(int)$requirement['minimum_selections'],'sortOrder'=>(int)$requirement['sort_order']],$user);
    az_work_query('INSERT INTO production_requirement_shots(requirement_id,shot_id) VALUES(?,?)',[$requirement['id'],$shot['id']]);
    az_work_query('UPDATE production_requirements SET revision=revision+1,updated_at=? WHERE id=?',[az_now(),$requirement['id']]);
    return ['shot'=>$shot,'created'=>true];
}

function az_director_number(mixed $value,float $min,float $max,string $label): float
{
    if(!is_int($value)&&!is_float($value))throw new AzHttpError(400,$label.' must be a number.','invalid_field');
    $n=(float)$value;if(!is_finite($n)||$n<$min||$n>$max)throw new AzHttpError(400,$label.' is outside the supported range.','invalid_field');return$n;
}

/** The first cut is an explicit, reviewable selection; this function saves nothing. */
function az_director_propose(array $body,array $user): array
{
    $snapshot=az_director_snapshot(az_work_id($body['projectId']??null),$user);
    if($snapshot['project']['status']==='archived')throw new AzHttpError(409,'Restore this production before preparing a first cut.','project_archived');
    if(isset($body['fingerprint'])&&(!is_string($body['fingerprint'])||!hash_equals($snapshot['fingerprint'],$body['fingerprint'])))throw new AzHttpError(409,'The production changed. Refresh its coverage before proposing a cut.','source_changed');
    $target=az_integer($body['targetDurationSeconds']??0,0,3600,'Target duration');
    $kind=$snapshot['project']['kind'];
    $mode=az_enum($body['cardMode']??match($kind){'production'=>'none','mixed'=>'perClip',default=>'all'},['none','all','perClip'],'Card mode');
    $card=az_director_number($body['cardDuration']??3,.5,15,'Card duration');
    $fade=az_director_number($body['fadeDuration']??.45,0,3,'Fade duration');
    $settings=['cardMode'=>$mode,'cardDuration'=>$card,'fadeDuration'=>$fade,'aspect'=>'16:9','resolution'=>720,'fit'=>'contain','brand'=>'AZHOTTIE','modelName'=>''];
    $pool=[];foreach($snapshot['candidates'] as $candidate)$pool[$candidate['requirementId']][]=$candidate;
    $items=[];$used=[];$warnings=[];
    foreach($snapshot['requirements'] as $requirement){
        $choices=$pool[$requirement['id']]??[];
        usort($choices,static fn($a,$b)=>(int)$b['preferred']<=>(int)$a['preferred'] ?: (int)$b['isFavorite']<=>(int)$a['isFavorite'] ?: strcmp($a['takeId'],$b['takeId']) ?: $a['startMs']<=>$b['startMs'] ?: strcmp($a['selectionId']??'',$b['selectionId']??''));
        $picked=0;
        foreach($choices as $choice){
            if(isset($used[$choice['takeId']]))continue;
            $used[$choice['takeId']]=true;$picked++;
            $wanted=$choice['endMs']-$choice['startMs'];
            if($requirement['targetDurationSeconds']>0)$wanted=min($wanted,max(100,(int)ceil($requirement['targetDurationSeconds']*1000/$requirement['minimumSelections'])));
            $items[]=$choice+['reason'=>$choice['preferred']?'Preferred usable section':($choice['isFavorite']?'Favorite accepted take':($choice['selectionId']?'Marked usable section':'Accepted take')),'wantedMs'=>$wanted];
            if($picked>=$requirement['minimumSelections'])break;
        }
        if($picked<$requirement['minimumSelections'])$warnings[]=$requirement['title'].': '.($requirement['minimumSelections']-$picked).' more distinct usable take(s) needed for this cut.';
        elseif($requirement['coverage']['durationMissingMs']>0)$warnings[]=$requirement['title'].': accepted usable footage is shorter than its coverage duration target.';
    }
    if(!$snapshot['requirements'])throw new AzHttpError(409,'Add coverage requirements and link accepted footage before creating a first cut.','coverage_required');
    if(!$items)throw new AzHttpError(409,'No eligible take with a known usable duration is available for this cut.','usable_footage_required');
    $cardMs=(int)round($card*1000);$cards=0;
    foreach($items as $item)if($mode==='all'||($mode==='perClip'&&$item['showCard']))$cards++;
    $overhead=$cards*$cardMs;$wanted=array_sum(array_column($items,'wantedMs'));$budget=$target>0?$target*1000-$overhead:$wanted;
    if($budget<count($items)*100)throw new AzHttpError(400,'The target is too short for these question cards and selected answers. Increase the duration or turn cards off.','target_too_short');
    $lengths=array_column($items,'wantedMs');
    if($budget<$wanted){
        $remaining=$budget-count($items)*100;$weight=$wanted-count($items)*100;$allocated=0;
        foreach($lengths as $i=>$length){$extra=$weight>0?(int)floor(($length-100)*$remaining/$weight):0;$lengths[$i]=100+$extra;$allocated+=$lengths[$i];}
        // Rounding remainder is bounded by the number of clips; keep every range inside its cap.
        for($i=0;$allocated<$budget&&$i<count($lengths);$i++)if($lengths[$i]<$items[$i]['wantedMs']){$lengths[$i]++;$allocated++;}
    }
    $edit=['order'=>[],'segments'=>[],'settings'=>$settings];$total=$overhead;
    foreach($items as $i=>&$item){
        $item['allowedStartMs']=$item['startMs'];$item['allowedEndMs']=$item['endMs'];
        $item['endMs']=$item['startMs']+$lengths[$i];$item['durationMs']=$lengths[$i];unset($item['wantedMs']);
        $edit['order'][]=$item['takeId'];$edit['segments'][$item['takeId']]=['included'=>true,'title'=>$item['title'],'trimStart'=>$item['startMs']/1000,'trimEnd'=>$item['endMs']/1000,'showCard'=>$item['showCard']];
        $total+=$lengths[$i];
    }
    unset($item);
    if($target>0&&$total<$target*1000)$warnings[]='The selected usable footage produces '.round($total/1000,2).' seconds of the requested '.$target.' seconds; no source range was extended.';
    if($snapshot['coverage']['status']!=='ready')$warnings[]='Required production coverage is incomplete. This is a draft using the material currently available.';
    return ['projectId'=>$snapshot['project']['id'],'fingerprint'=>$snapshot['fingerprint'],'edit'=>$edit,'items'=>$items,'warnings'=>array_values(array_unique($warnings)),'durationMs'=>$total,'targetDurationMs'=>$target*1000,'coverage'=>$snapshot['coverage']];
}

function az_director_canonical(mixed $value): mixed
{
    if(!is_array($value))return$value;
    if(!array_is_list($value))ksort($value);
    foreach($value as $key=>$item)$value[$key]=az_director_canonical($item);
    return$value;
}

function az_director_get(string $action,array $query,array $user): array
{
    if($action==='project')return az_director_snapshot(az_work_id($query['projectId']??null),$user);
    throw new AzHttpError(404,'This Director action was not found.','unknown_action');
}

/** Transaction-neutral for callers that apply a complete reviewed operation. */
function az_director_post(string $action,array $body,array $user): array
{
    $user=az_work_live_user($user);az_work_assert_admin($user);
    if($action==='cut.propose')return az_director_propose($body,$user);
    if(!in_array($action,['requirement.save','requirement.delete','requirement.shot','selection.save','selection.delete'],true))throw new AzHttpError(404,'This Director action was not found.','unknown_action');
    $project=az_director_project(az_work_id($body['projectId']??null),$user);
    $editorial=in_array($action,['selection.save','selection.delete'],true);
    $token=null;$hash=null;
    if(isset($body['requestId'])){
        $token=az_text($body['requestId'],160,'Request ID');if(!preg_match('/\A[a-zA-Z0-9_.:-]{8,160}\z/D',$token))throw new AzHttpError(400,'Use a unique request ID of 8 to 160 letters, digits or separators.','invalid_request_id');
        $canonical=$body;unset($canonical['requestId']);$hash=hash('sha256',json_encode(az_director_canonical($canonical),JSON_THROW_ON_ERROR));
        $prior=az_work_query('SELECT * FROM director_requests WHERE owner_id=? AND request_token=?',[$user['id'],$token])->fetch();
        if($prior){
            if($prior['project_id']!==$project['id']||$prior['action']!==$action||!hash_equals($prior['request_hash'],$hash))throw new AzHttpError(409,'This request ID was already used for another change.','request_conflict');
            $overview=az_director_snapshot($project['id'],$user);
            if($action==='requirement.shot')return ['shot'=>az_work_shot_detail($prior['resource_id'],$user),'created'=>false,'overview'=>$overview];
            return $overview;
        }
    }
    if(!in_array($project['status'],$editorial?['draft','active','completed']:['draft','active'],true))throw new AzHttpError(409,$editorial?'Restore this production before changing usable sections.':'Reopen this production before changing its coverage plan.','work_closed');
    $shotResult=null;
    if($action==='requirement.save')az_director_save_requirement($body,$project,$user);
    elseif($action==='selection.save')az_director_save_selection($body,$project,$user);
    elseif($action==='requirement.shot')$shotResult=az_director_missing_shot($body,$project,$user);
    elseif($action==='requirement.delete'){
        $row=az_director_requirement(az_work_id($body['id']??null),$project['id']);az_work_revision($body,$row);
        az_work_query('DELETE FROM production_requirements WHERE id=?',[$row['id']]);
        az_work_event($project['id'],(int)$user['id'],'coverage_deleted','Removed '.$row['title'].' coverage; original footage and shots were preserved.');
    }else{
        $row=az_work_query('SELECT * FROM production_selections WHERE id=? AND project_id=?',[az_work_id($body['id']??null),$project['id']])->fetch();
        if(!$row)throw new AzHttpError(404,'This usable section was not found.','not_found');az_work_revision($body,$row);
        az_work_query('DELETE FROM production_selections WHERE id=?',[$row['id']]);
    }
    if($token)az_work_query('INSERT INTO director_requests(owner_id,request_token,project_id,action,request_hash,resource_id,created_at) VALUES(?,?,?,?,?,?,?)',[$user['id'],$token,$project['id'],$action,$hash,$shotResult['shot']['id']??null,az_now()]);
    $overview=az_director_snapshot($project['id'],$user);
    return $shotResult?($shotResult+['overview'=>$overview]):$overview;
}
