<?php
declare(strict_types=1);
/**
 * AZHottie Casting & Onboarding Specialist. Verified CRM/roster data only.
 * Neither the rules nor the AI make casting decisions, change work, or contact applicants.
 */
function az_upgrade_agent_casting_schema(PDO $db): void
{
    if (az_schema_version($db)>=32 || az_schema_version($db)<31) return;
    az_prepare_storage();
    $lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Unable to lock casting specialist upgrade.');
    try {
        if (az_schema_version($db)>=32) return;
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $uid=$mysql?'BIGINT UNSIGNED':'INTEGER';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS agent_casting_deliveries (
          id CHAR(32) PRIMARY KEY, lead_id CHAR(32) NOT NULL, recipient_id $uid NOT NULL,
          rule VARCHAR(48) NOT NULL, fingerprint CHAR(64) NOT NULL, created_at VARCHAR(32) NOT NULL,
          UNIQUE (lead_id,recipient_id,rule,fingerprint),
          FOREIGN KEY (lead_id) REFERENCES casting_leads(id) ON DELETE CASCADE,
          FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS agent_casting_scans (
          scope VARCHAR(80) PRIMARY KEY, completed_at VARCHAR(32) NOT NULL,
          checked_count INTEGER NOT NULL, delivered_count INTEGER NOT NULL, truncated INTEGER NOT NULL)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS model_onboarding_checkpoints (
          model_user_id $uid NOT NULL, checkpoint VARCHAR(40) NOT NULL, completed_by $uid NOT NULL,
          completed_at VARCHAR(32) NOT NULL,
          PRIMARY KEY (model_user_id,checkpoint),
          FOREIGN KEY (model_user_id) REFERENCES users(id) ON DELETE CASCADE,
          FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        $db->exec('UPDATE studio_schema SET version=32 WHERE id=1');
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}

/** Pure stage follow-up rules; no protected-attribute scoring or AI decisions. */
function az_agent_casting_rules(array $lead,int $now): array
{
    $status=(string)($lead['status']??'');
    $updated=strtotime((string)($lead['updated_at']??''));
    $age=$updated===false?0:$now-$updated;
    $expires=(int)($lead['expires_at']??0);
    $activeInvite=$expires>$now && empty($lead['used_at']);
    $rules=[];
    if($status==='new' && $age>=86400) $rules[]=['rule'=>'review_24h','title'=>'New casting application awaiting review','detail'=>'A new application has waited at least 24 hours. Review the answers and decide its next step.'];
    if($status==='contacted' && $age>=7*86400) $rules[]=['rule'=>'contacted_7d','title'=>'Casting follow-up needed','detail'=>'A contacted applicant has had no status update for seven days. Check whether they replied.'];
    if($status==='screening' && $age>=7*86400) $rules[]=['rule'=>'screening_7d','title'=>'Screening needs an update','detail'=>'A screening application has not progressed for seven days. Review the next step.'];
    if($status==='interview' && $age>=7*86400) $rules[]=['rule'=>'interview_7d','title'=>'Casting interview follow-up','detail'=>'An interview-stage application has had no update for seven days. Confirm the outcome.'];
    if($status==='converted' && !empty($lead['onboarding_pending']) && $age>=3*86400)
        $rules[]=['rule'=>'onboarding_incomplete','title'=>'Model onboarding needs attention',
          'detail'=>'An approved model account still has incomplete onboarding steps after three days. Review the checklist.'];
    if($status==='approved') {
        if(!$activeInvite && $age>=86400) $rules[]=['rule'=>'invitation_missing','title'=>'Approved model invitation needed','detail'=>'An approved applicant has no active invitation. Review before issuing a private invitation.'];
        elseif($activeInvite && $expires<=$now+86400) $rules[]=['rule'=>'invitation_expiring','title'=>'Model invitation expires soon','detail'=>'A model invitation will expire within 24 hours. Consider a private follow-up.'];
    }
    return $rules;
}
function az_agent_casting_fingerprint(array $lead,string $rule): string
{
    return hash('sha256',implode('|',[(string)$lead['id'],$rule,(string)$lead['status'],(string)$lead['revision'],(string)($lead['expires_at']??''),(string)($lead['used_at']??'')]));
}
/** Keeps private application notes and contact data entirely out of notifications. */
function az_agent_casting_record(string $kind,string $title,string $detail,string $href,int $actor=0,?array $recipients=null): void
{
    try {
        $recipients??=az_agent_workspace_admin_ids();
        foreach($recipients as $recipient){
            $id=(int)$recipient;
            if($id<1)continue;
            az_agent_workspace_emit($actor?:$id,$id,'casting_'.$kind,$title,$detail,$href,$actor===0);
        }
    } catch(Throwable $e){error_log('Casting agent event delivery failed: '.$e->getMessage());}
}
function az_agent_casting_resolve(string $leadId): void
{
    if(!az_valid_id($leadId))return;
    az_db()->prepare("UPDATE agent_workspace_events SET read_at=? WHERE kind LIKE 'casting_%' AND read_at IS NULL AND href=?")
      ->execute([az_now(),'casting.php?lead='.$leadId]);
}
/** Private applicant completeness only, not a merit or eligibility score. */
function az_agent_casting_lead_checklist(array $lead,int $now): array
{
    $questions=json_decode((string)($lead['questions_json']??'[]'),true)?:[];
    $answers=json_decode((string)($lead['answers_json']??'{}'),true)?:[];
    $missing=[];$required=0;
    foreach($questions as $q){
        if(empty($q['required']))continue;
        $required++;$v=$answers[$q['id']]??null;
        if($v===null||$v===''||$v===[])$missing[]=(string)($q['title']??'Required answer');
    }
    $updated=strtotime((string)($lead['updated_at']??''));
    return ['status'=>$lead['status']??'new','requiredCount'=>$required,'answeredRequired'=>$required-count($missing),
      'missingRequired'=>$missing,'daysSinceUpdate'=>$updated===false?null:max(0,(int)floor(($now-$updated)/86400)),
      'recommendations'=>az_agent_casting_rules($lead,$now),
      'experience'=>is_string($answers['experience']??null)?$answers['experience']:'Not provided',
      'availability'=>is_array($answers['availability']??null)?array_values(array_filter($answers['availability'],'is_string')):[]];
}

/** Operator-approved, revision-protected early stage progress only. Approvals stay in Casting CRM. */
function az_agent_casting_advance(array $admin,array $body): array
{
    if(!az_has_role($admin,'admin')) throw new AzHttpError(403,'Admin access required.','role_required');
    $leadId=$body['leadId']??null;$next=$body['nextStage']??null;$revision=$body['revision']??null;
    if(!is_string($leadId)||!az_valid_id($leadId)||!is_int($revision)||$revision<1||!is_string($next))throw new AzHttpError(400,'Invalid casting transition.','invalid_transition');
    $allowed=['new'=>'contacted','contacted'=>'screening','screening'=>'interview'];
    $db=az_db();$lock=az_community_lock();
    try {
        $lead=az_casting_lead($leadId);
        if(($allowed[$lead['status']]??'')!==$next)throw new AzHttpError(409,'This transition requires review in Casting CRM.','transition_not_allowed');
        if((int)$lead['revision']!==$revision)throw new AzHttpError(409,'The application changed. Refresh before confirming.','stale_revision');
        $q=$db->prepare('UPDATE casting_leads SET status=?,revision=revision+1,updated_at=? WHERE id=? AND status=? AND revision=?');
        $q->execute([$next,az_now(),$leadId,$lead['status'],$revision]);
        if($q->rowCount()!==1)throw new AzHttpError(409,'The application changed. Refresh and retry.','stale_revision');
        az_agent_casting_resolve($leadId);
    } finally {flock($lock,LOCK_UN);fclose($lock);}
    az_agent_casting_record('stage_changed','Casting stage updated','The application moved to '.$next.' after an administrator confirmed the action.','casting.php?lead='.$leadId,(int)$admin['id']);
    return ['id'=>$leadId,'stage'=>$next,'revision'=>$revision+1,'confirmed'=>true];
}
function az_agent_casting_onboarding_row(int $modelId): ?array
{
    $q=az_db()->prepare("SELECT u.id,u.name,u.status AS account_status,p.id AS profile_id,p.owner_id,p.status AS profile_status,p.public_bio,p.availability,p.stage_name
      FROM users u JOIN user_roles r ON r.user_id=u.id AND r.role='model'
      LEFT JOIN model_profiles p ON p.linked_user_id=u.id
      WHERE u.id=? ORDER BY p.updated_at DESC LIMIT 1");
    $q->execute([$modelId]);$r=$q->fetch(PDO::FETCH_ASSOC);
    return $r?:null;
}
function az_agent_casting_onboarding(int $modelId): ?array
{
    $r=az_agent_casting_onboarding_row($modelId);if(!$r)return null;
    $q=az_db()->prepare('SELECT checkpoint FROM model_onboarding_checkpoints WHERE model_user_id=?');$q->execute([$modelId]);
    $marks=array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN),true);
    $active=$r['account_status']==='active'&&$r['profile_id']!==null&&$r['profile_status']==='active';
    $items=[
      ['key'=>'profile','label'=>'Complete model bio','done'=>$active && trim((string)$r['public_bio'])!=='','canConfirm'=>false],
      ['key'=>'availability','label'=>'Set availability','done'=>$active && trim((string)$r['availability'])!=='','canConfirm'=>false],
      ['key'=>'studio_briefing','label'=>'Studio orientation completed','done'=>$active && isset($marks['studio_briefing']),'canConfirm'=>true],
      ['key'=>'production_ready','label'=>'Ready for production assignments','done'=>$active && isset($marks['production_ready']),'canConfirm'=>true],
    ];
    $complete=count(array_filter($items,static fn($x)=>$x['done']));
    return ['modelId'=>$modelId,'label'=>$r['stage_name']?:$r['name'],'active'=>$active,'items'=>$items,'complete'=>$complete,'total'=>count($items),'ready'=>$complete===count($items)];
}
/** Human review of onboarding checklists, not an automatic eligibility/approval system. */
function az_agent_casting_checkpoint(array $admin,array $body): array
{
    if(!az_has_role($admin,'admin'))throw new AzHttpError(403,'Admin access required.','role_required');
    $id=$body['modelId']??null;$kind=$body['checkpoint']??null;$complete=$body['complete']??null;
    if(!is_int($id)||$id<1||!in_array($kind,['studio_briefing','production_ready'],true)||!is_bool($complete)) throw new AzHttpError(400,'Invalid onboarding update.','invalid_checkpoint');
    $model=az_agent_casting_onboarding_row($id);
    if(!$model||$model['account_status']!=='active'||$model['profile_status']!=='active')throw new AzHttpError(404,'Active model not found.','model_not_found');
    $lock=az_community_lock();$changed=false;
    try {
        $db=az_db();$check=$db->prepare('SELECT checkpoint FROM model_onboarding_checkpoints WHERE model_user_id=? AND checkpoint=?');
        $check->execute([$id,$kind]);$exists=$check->fetchColumn()!==false;
        if($complete&&!$exists){$db->prepare('INSERT INTO model_onboarding_checkpoints(model_user_id,checkpoint,completed_by,completed_at) VALUES(?,?,?,?)')->execute([$id,$kind,(int)$admin['id'],az_now()]);$changed=true;}
        if(!$complete&&$exists){$db->prepare('DELETE FROM model_onboarding_checkpoints WHERE model_user_id=? AND checkpoint=?')->execute([$id,$kind]);$changed=true;}
    } finally {flock($lock,LOCK_UN);fclose($lock);}
    if(!$changed)return az_agent_casting_onboarding($id)??[];
    $href='agent-chat.php?topic=casting';
    try {
        az_agent_workspace_emit((int)$admin['id'],$id,'casting_onboarding','Model onboarding updated',str_replace('_',' ',$kind).($complete?' completed.':' requires follow-up.'),$href);
        az_agent_casting_record('onboarding_update','Onboarding checklist updated','An administrator updated a model onboarding checkpoint.','casting.php?tab=onboarding',(int)$admin['id']);
    }catch(Throwable $e){error_log('Onboarding agent event error: '.$e->getMessage());}
    return az_agent_casting_onboarding($id)??[];
}
/** Aggregated matches only; eligibility remains the canonical Work API's decision. */
function az_agent_casting_matches(array $admin,array $assignOptions,int $limit=3): array
{
    if(!az_has_role($admin,'admin'))return[];
    $items=[];
    foreach($assignOptions as $shot){
        foreach($shot['models'] as $member){
            $check=az_agent_casting_onboarding((int)$member['id']);
            if(!$check||!$check['ready'])continue;
            $items[]=['shotId'=>$shot['shotId'],'shot'=>$shot['label'],'modelId'=>$member['id'],
              'model'=>$member['name'],'onboardingReady'=>$check['ready'],
              'reason'=>'Active eligible production roster member with completed onboarding. Confirm actual availability and suitability before assignment.'];
            if(count($items)>=$limit)return $items;
        }
    }
    return $items;
}
/** One transparent report template shared by Brain and Chat; no fabricated activity. */
function az_agent_casting_report(array $counts,array $cards,bool $admin): array
{
    if(!$admin){
        $done=(int)($counts['onboardingComplete']??0);$total=(int)($counts['onboardingTotal']??0);
        return ['keyFindings'=>["$done of $total onboarding steps complete"],
          'actionsFollowups'=>$done<$total?['Update your model bio and availability; ask the studio to verify remaining steps.']:[],
          'openQuestions'=>['Confirm your availability with the producer for each individual assignment.'],
          'nextStep'=>$done<$total?'Review your model profile in Account Settings.':'Review your current assignments.',
          'questionsForYou'=>['Do you need help preparing for your next studio assignment?']];
    }
    $findings=[(int)($counts['newApplications']??0).' new applications',
      (int)($counts['onboardingPending']??0).' model onboarding checklists incomplete',
      (int)($counts['approved']??0).' approved applications'];
    $actions=array_map(static fn($c)=>(string)$c['title'],array_slice($cards,0,4));
    return ['keyFindings'=>$findings,'actionsFollowups'=>$actions,
      'openQuestions'=>['Confirm individual availability and production suitability with each model.'],
      'nextStep'=>$cards?($cards[0]['title'].'.'):'No casting items currently require a follow-up.',
      'questionsForYou'=>[$cards?'Which application or onboarding item should be reviewed first?':'Would you like to review upcoming production needs?']];
}

/** Public applicant text is never sent to AI. Admin-only summary contains workflow stages and IDs. */
function az_agent_casting_briefing(array $user,array $assignOptions=[]): array
{
    if(!az_agent_workspace_available($user))return ['counts'=>[],'cards'=>[],'onboarding'=>null,'matches'=>[]];
    if(!az_has_role($user,'admin')){
        $progress=az_agent_casting_onboarding((int)$user['id']);
        $cards=[];
        if($progress && !$progress['ready'])$cards[]=['kind'=>'onboarding','title'=>'Complete your model onboarding',
          'detail'=>($progress['total']-$progress['complete']).' checklist step(s) need attention. Complete your profile and availability; the studio confirms orientation and production readiness.',
          'href'=>'account.php','priority'=>2,'action'=>null];
        return ['counts'=>['onboardingComplete'=>$progress['complete']??0,'onboardingTotal'=>$progress['total']??0],
          'cards'=>$cards,'onboarding'=>$progress,'matches'=>[],'scan'=>null,
          'report'=>az_agent_casting_report(['onboardingComplete'=>$progress['complete']??0,'onboardingTotal'=>$progress['total']??0],$cards,false)];
    }
    $statuses=['new'=>0,'contacted'=>0,'screening'=>0,'interview'=>0,'approved'=>0,'converted'=>0,'declined'=>0];
    $q=az_db()->query('SELECT status,COUNT(*) AS total FROM casting_leads GROUP BY status');
    foreach($q->fetchAll() as $r)if(array_key_exists($r['status'],$statuses))$statuses[$r['status']]=(int)$r['total'];
    $q=az_db()->query("SELECT l.id,l.status,l.revision,l.created_at,l.updated_at,i.expires_at,i.used_at
      FROM casting_leads l LEFT JOIN casting_invites i ON i.lead_id=l.id
      WHERE l.status IN ('new','contacted','screening','interview','approved')
      ORDER BY l.updated_at ASC,l.id ASC LIMIT 250");
    $cards=[];$now=time();
    foreach($q->fetchAll() as $lead){
        $recs=az_agent_casting_rules($lead,$now);
        if(!$recs && $lead['status']==='new')$recs=[['rule'=>'new','title'=>'Review recent casting application','detail'=>'Review this application and decide the next step.']];
        if(!$recs)continue;
        $r=$recs[0];$next=['new'=>'contacted','contacted'=>'screening','screening'=>'interview'];
        $action=isset($next[$lead['status']])?['type'=>'casting_stage','id'=>$lead['id'],'revision'=>(int)$lead['revision'],'next'=>$next[$lead['status']]]:null;
        $cards[]=['kind'=>'casting','title'=>$r['title'],'detail'=>$r['detail'],'href'=>'casting.php?lead='.$lead['id'],
          'priority'=>$r['rule']==='review_24h'?1:2,'action'=>$action];
        if(count($cards)>=6)break;
    }
    $q=az_db()->query("SELECT l.converted_user_id FROM casting_leads l JOIN users u ON u.id=l.converted_user_id AND u.status='active' WHERE l.status='converted' AND l.converted_user_id IS NOT NULL GROUP BY l.converted_user_id ORDER BY MAX(l.updated_at) DESC LIMIT 80");
    $pending=0;$models=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $modelId){
        $progress=az_agent_casting_onboarding((int)$modelId);
        if($progress&&!$progress['ready']){$pending++;if(count($models)<10)$models[]=$progress;}
    }
    if($pending)$cards[]=['kind'=>'onboarding','title'=>$pending.' model onboarding checklist(s) need attention',
      'detail'=>'Review incomplete profile, availability, orientation, and production-readiness steps.',
      'href'=>'casting.php?tab=onboarding','priority'=>2,'action'=>null];
    if(function_exists('az_talent_due_count')&&az_schema_version(az_db())>=50){
        $followupsDue=az_talent_due_count();
        if($followupsDue)$cards[]=['kind'=>'casting','title'=>'Casting follow-ups due',
            'detail'=>$followupsDue.' staff-owned casting follow-up(s) need a human response. No applicants were contacted.',
            'href'=>'casting.php?tab=pipeline','priority'=>1,'action'=>null];
    }
    $matches=az_agent_casting_matches($user,$assignOptions);
    if($matches)$cards[]=['kind'=>'matching','title'=>'Eligible roster members available for review',
      'detail'=>count($matches).' production match suggestion(s) based on existing roster eligibility and onboarding status. Confirm availability and suitability before assigning.',
      'href'=>'productions.php','priority'=>3,'action'=>null];
    return ['counts'=>['newApplications'=>$statuses['new'],'screening'=>$statuses['screening'],'interviews'=>$statuses['interview'],'approved'=>$statuses['approved'],'converted'=>$statuses['converted'],'onboardingPending'=>$pending],
      'cards'=>$cards,'onboarding'=>null,'onboardingModels'=>$models,'matches'=>$matches,'scan'=>az_agent_casting_scan_status(),
      'report'=>az_agent_casting_report(['newApplications'=>$statuses['new'],'onboardingPending'=>$pending,'approved'=>$statuses['approved']],$cards,true)];
}
/** Converted accounts, including completed checklists, for the existing Casting CRM. */
function az_agent_casting_onboarding_list(array $admin,int $limit=75): array
{
    if(!az_has_role($admin,'admin'))throw new AzHttpError(403,'Admin access required.','role_required');
    $limit=max(1,min(100,$limit));
    $q=az_db()->prepare("SELECT l.converted_user_id FROM casting_leads l
      JOIN users u ON u.id=l.converted_user_id AND u.status='active'
      WHERE l.status='converted' AND l.converted_user_id IS NOT NULL
      GROUP BY l.converted_user_id ORDER BY MAX(l.updated_at) DESC LIMIT ?");
    $q->bindValue(1,$limit,PDO::PARAM_INT);$q->execute();
    $out=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){
        $row=az_agent_casting_onboarding((int)$id);
        if($row)$out[]=$row;
    }
    usort($out,static fn($a,$b)=>($a['ready']<=>$b['ready']) ?: strcmp($a['label'],$b['label']));
    return $out;
}

/** Everything sent to an LLM is deliberately anonymized, aggregate, and read-only. */
function az_agent_casting_chat_snapshot(array $user): array
{
    if(!az_agent_workspace_available($user))return [];
    $brief=az_agent_casting_briefing($user);
    return ['area'=>'casting_and_onboarding','role'=>az_has_role($user,'admin')?'admin':'model',
      'counts'=>$brief['counts'],'report'=>$brief['report']??[],
      'nextSteps'=>array_map(static fn($c)=>['type'=>$c['kind'],'summary'=>preg_replace('/\b[0-9a-f]{32}\b/i','[record]',(string)$c['title'])],array_slice($brief['cards'],0,6)),
      'access'=>'Read-only verified totals. Do not claim contact, approval, assignment, or onboarding status was changed.'];
}
function az_agent_casting_scan_status(?int $adminId=null): array
{
    $scope=$adminId===null?'cron':'admin:'.$adminId;
    $q=az_db()->prepare('SELECT completed_at,checked_count,delivered_count,truncated FROM agent_casting_scans WHERE scope=?');
    $q->execute([$scope]);$r=$q->fetch();
    return $r?['completedAt'=>$r['completed_at'],'checked'=>(int)$r['checked_count'],'delivered'=>(int)$r['delivered_count'],'truncated'=>(bool)$r['truncated']]:['completedAt'=>null,'checked'=>0,'delivered'=>0,'truncated'=>false];
}
function az_agent_casting_scan(?int $adminId=null,int $limit=10000): array
{
    if($adminId!==null&&$adminId<1)throw new InvalidArgumentException('Invalid admin');
    az_prepare_storage();$lock=fopen(AZ_STORAGE.'/agent-casting.lock','c+b');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new AzHttpError(409,'Casting scan is already running.','scan_in_progress');
    $db=az_db();$checked=0;$sent=0;$truncated=false;$cursor='';$limit=max(1,min(10000,$limit));
    try {
        $adminIds=$adminId===null?az_agent_workspace_admin_ids():[$adminId];
        $db->beginTransaction();
        $q=$db->prepare("SELECT l.id,l.status,l.revision,l.updated_at,l.converted_user_id,i.expires_at,i.used_at FROM casting_leads l
          LEFT JOIN casting_invites i ON i.lead_id=l.id
          WHERE l.status IN ('new','contacted','screening','interview','approved','converted') AND l.id>?
          ORDER BY l.id LIMIT 200");
        do {
            $q->execute([$cursor]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$count=count($rows);
            foreach($rows as $lead){
                $checked++;$cursor=$lead['id'];
                if($lead['status']==='converted' && (int)($lead['converted_user_id']??0)>0){
                    $progress=az_agent_casting_onboarding((int)$lead['converted_user_id']);
                    $lead['onboarding_pending']=$progress && $progress['active'] && !$progress['ready'];
                }
                foreach(az_agent_casting_rules($lead,time()) as $rule){
                  $recipients=$adminIds;
                  if($rule['rule']==='onboarding_incomplete' && !empty($lead['onboarding_pending']))$recipients[]=(int)$lead['converted_user_id'];
                  foreach(array_unique($recipients) as $recipient){
                    $fingerprint=az_agent_casting_fingerprint($lead,$rule['rule']);
                    try {
                        $db->prepare('INSERT INTO agent_casting_deliveries(id,lead_id,recipient_id,rule,fingerprint,created_at) VALUES(?,?,?,?,?,?)')
                          ->execute([az_id(),$lead['id'],$recipient,$rule['rule'],$fingerprint,az_now()]);
                    }catch(PDOException $e){
                        $driver=$db->getAttribute(PDO::ATTR_DRIVER_NAME);$code=$e->errorInfo[1]??null;
                        if(($driver==='mysql'&&(int)$code===1062)||($driver==='sqlite'&&(int)$code===19&&str_contains((string)($e->errorInfo[2]??''),'UNIQUE constraint failed')))continue;
                        throw $e;
                    }
                    $forModel=$rule['rule']==='onboarding_incomplete' && (int)$recipient===(int)($lead['converted_user_id']??0);
                    $href=$forModel?'account.php':'casting.php?lead='.$lead['id'];
                    az_agent_workspace_emit((int)$recipient,(int)$recipient,'casting_'.$rule['rule'],$rule['title'],$rule['detail'],$href,true);
                    $sent++;
                  }
                }
                if($checked>=$limit){$truncated=$count===200;break;}
            }
        }while($count===200&&!$truncated);
        $scope=$adminId===null?'cron':'admin:'.$adminId;
        $exists=$db->prepare('SELECT scope FROM agent_casting_scans WHERE scope=?');$exists->execute([$scope]);
        if($exists->fetchColumn()!==false)$db->prepare('UPDATE agent_casting_scans SET completed_at=?,checked_count=?,delivered_count=?,truncated=? WHERE scope=?')->execute([az_now(),$checked,$sent,$truncated?1:0,$scope]);
        else $db->prepare('INSERT INTO agent_casting_scans(scope,completed_at,checked_count,delivered_count,truncated) VALUES(?,?,?,?,?)')->execute([$scope,az_now(),$checked,$sent,$truncated?1:0]);
        $db->commit();
        return ['checked'=>$checked,'delivered'=>$sent,'truncated'=>$truncated,'completedAt'=>az_now()];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    finally{flock($lock,LOCK_UN);fclose($lock);}
}
