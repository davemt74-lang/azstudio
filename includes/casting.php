<?php
declare(strict_types=1);
/** AZHottie v1.9: public casting form, versioned question bank, lead CRM, approved model invitations. */
function az_upgrade_casting_schema(PDO $db): void
{
    if (az_schema_version($db) >= 17) { return; }
    az_prepare_storage();
    $lock = fopen(AZ_STORAGE . '/schema-migration.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('Could not lock the casting upgrade.'); }
    try {
        if (az_schema_version($db) >= 17) { return; }
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $userId = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $longText = $mysql ? 'LONGTEXT' : 'TEXT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $db->exec("CREATE TABLE IF NOT EXISTS casting_questionnaire (id INTEGER PRIMARY KEY, revision INTEGER NOT NULL DEFAULT 1, questions_json $longText NOT NULL, updated_at VARCHAR(32) NOT NULL)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS casting_leads (
            id CHAR(32) PRIMARY KEY, applicant_name VARCHAR(120) NOT NULL, email VARCHAR(254) NOT NULL,
            stage_name VARCHAR(120) NOT NULL DEFAULT '', city VARCHAR(120) NOT NULL DEFAULT '',
            social_handles VARCHAR(500) NOT NULL DEFAULT '', phone VARCHAR(50) NOT NULL DEFAULT '',
            questions_json $longText NOT NULL, answers_json $longText NOT NULL,
            questionnaire_revision INTEGER NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'new',
            admin_notes $longText NOT NULL, revision INTEGER NOT NULL DEFAULT 1,
            converted_user_id $userId NULL, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL,
            FOREIGN KEY (converted_user_id) REFERENCES users(id) ON DELETE SET NULL)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS casting_invites (
            token_hash CHAR(64) PRIMARY KEY, lead_id CHAR(32) NOT NULL,
            created_by $userId NOT NULL, expires_at BIGINT NOT NULL,
            created_at VARCHAR(32) NOT NULL, used_at VARCHAR(32) NULL,
            FOREIGN KEY (lead_id) REFERENCES casting_leads(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id))$suffix");
        $check = $db->query('SELECT id FROM casting_questionnaire WHERE id=1')->fetchColumn();
        if ($check === false) {
            $db->prepare('INSERT INTO casting_questionnaire (id,revision,questions_json,updated_at) VALUES (1,1,?,?)')
                ->execute([json_encode(az_casting_default_questions(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),az_now()]);
        }
        $db->exec('UPDATE studio_schema SET version=17 WHERE id=1');
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function az_casting_default_questions(): array
{
    return [
        ['id'=>'interests','title'=>'What kinds of AZHottie projects interest you most?','type'=>'multiple','required'=>true,'help'=>'Select everything you would like to explore.','options'=>['Fashion and swimwear','Boudoir or editorial photography','Comedy sketches and hosting','Fitness and product campaigns','Games and digital entertainment','Photography or production work']],
        ['id'=>'experience','title'=>'How would you describe your on-camera experience?','type'=>'single','required'=>true,'help'=>'There is no minimum experience requirement.','options'=>['Professional modeling or acting','I regularly create social content','A few shoots or videos','I am just getting started']],
        ['id'=>'style','title'=>'Which creative style feels most like you?','type'=>'single','required'=>true,'help'=>'Choose the one that feels closest.','options'=>['Elegant, polished and cinematic','Playful and mischievous','Sporty, confident and energetic','Candid and conversational','A little of everything']],
        ['id'=>'comfort','title'=>'Which types of adult-themed styling would you consider discussing?','type'=>'multiple','required'=>false,'help'=>'Preferences are not consent to any particular shoot; every project is agreed separately.','options'=>['Swimwear','Lingerie','Implied nude editorial','Artistic nude photography','Flirtatious scripted comedy','Adult lifestyle product features','I would prefer to review projects individually']],
        ['id'=>'collaboration','title'=>'How do you like to work with other creators?','type'=>'single','required'=>true,'help'=>'','options'=>['I enjoy team shoots and collaborations','I prefer solo shoots','Both, depending on the project','I am open to learning']],
        ['id'=>'availability','title'=>'When are you generally available for Arizona shoots?','type'=>'multiple','required'=>true,'help'=>'Select all that apply.','options'=>['Weekdays','Evenings','Weekends','Flexible with advance notice','Remote projects only']],
        ['id'=>'first-project','title'=>'If you could pitch your first AZHottie project, what would it be?','type'=>'long','required'=>true,'help'=>'A few sentences are enough.','options'=>[]],
        ['id'=>'other','title'=>'Anything else you want the casting team to know?','type'=>'long','required'=>false,'help'=>'Optional. Share only what you are comfortable discussing.','options'=>[]],
    ];
}

function az_casting_form(): array
{
    $row = az_db()->query('SELECT revision,questions_json,updated_at FROM casting_questionnaire WHERE id=1')->fetch();
    if (!$row) { throw new RuntimeException('Public casting questionnaire was not initialized.'); }
    return ['revision'=>(int)$row['revision'],'questions'=>json_decode((string)$row['questions_json'],true,64,JSON_THROW_ON_ERROR),'updatedAt'=>$row['updated_at']];
}

/** Validates both the admin's editable question bank and the on-page question editor. */
function az_casting_validate_questions(mixed $questions): array
{
    if (!is_array($questions) || count($questions) < 1 || count($questions) > 32) {
        throw new AzHttpError(400, 'Keep between 1 and 32 questions in the public application.', 'invalid_questions');
    }
    $clean=[]; $seen=[];
    foreach ($questions as $question) {
        if (!is_array($question)) { throw new AzHttpError(400, 'A question is malformed.', 'invalid_question'); }
        $id=az_text($question['id']??'',48,'Question ID');
        if (!preg_match('/\A[a-zA-Z0-9_-]{4,48}\z/D',$id) || isset($seen[$id])) {
            throw new AzHttpError(400,'Every question needs a unique identifier.','invalid_question');
        }
        $seen[$id]=true;
        $title=az_text($question['title']??'',300,'Question');
        if ($title==='') { throw new AzHttpError(400,'Questions cannot be blank.','invalid_question'); }
        $type=az_text($question['type']??'',20,'Question type');
        if (!in_array($type,['single','multiple','short','long'],true)) { throw new AzHttpError(400,'Choose a supported question type.','invalid_question'); }
        $help=az_text($question['help']??'',500,'Question description');
        $required=filter_var($question['required']??false,FILTER_VALIDATE_BOOLEAN);
        $options=$question['options']??[];
        if (!is_array($options) || count($options)>12) { throw new AzHttpError(400,'A question has too many answers.','invalid_options'); }
        $choices=[];
        foreach ($options as $option) {
            $choice=az_text($option,180,'Answer choice');
            if ($choice === '' || in_array($choice,$choices,true)) { throw new AzHttpError(400,'Answer choices must be unique and nonempty.','invalid_options'); }
            $choices[]=$choice;
        }
        if (in_array($type,['single','multiple'],true) && count($choices)<2) { throw new AzHttpError(400,'Multiple-choice questions need at least two answers.','invalid_options'); }
        if (in_array($type,['short','long'],true) && count($choices)>0) { throw new AzHttpError(400,'Free-text questions cannot have answer choices.','invalid_options'); }
        $clean[]=['id'=>$id,'title'=>$title,'type'=>$type,'required'=>$required,'help'=>$help,'options'=>$choices];
    }
    return $clean;
}

function az_casting_save_form(array $body): array
{
    $revision=az_integer($body['revision']??null,1,PHP_INT_MAX,'Revision');
    $questions=az_casting_validate_questions($body['questions']??null);
    $json=json_encode($questions,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $query=az_db()->prepare('UPDATE casting_questionnaire SET questions_json=?, revision=revision+1, updated_at=? WHERE id=1 AND revision=?');
    $query->execute([$json,az_now(),$revision]);
    if ($query->rowCount()!==1) { throw new AzHttpError(409,'Someone else updated the application form. Reload before saving.','revision_conflict'); }
    return az_casting_form();
}

function az_casting_collect_answers(array $questions, array $answers): array
{
    $clean=[];$validIds=array_column($questions,'id');
    foreach (array_keys($answers) as $id) {
        if (!in_array($id,$validIds,true)) { throw new AzHttpError(400,'The application questions changed. Reload and submit again.','questions_changed'); }
    }
    foreach ($questions as $question) {
        $id=$question['id'];$input=$answers[$id]??null;
        if (in_array($question['type'],['single','multiple'],true)) {
            $values=$question['type']==='single' ? ($input===null?[]:[$input]) : ($input??[]);
            if (!is_array($values) || count($values)>12) { throw new AzHttpError(400,'Select valid answers for ' . $question['title'],'invalid_answer'); }
            $values=array_values(array_unique($values,SORT_REGULAR));
            foreach ($values as $value) { if (!is_string($value) || !in_array($value,$question['options'],true)) { throw new AzHttpError(400,'Select valid answers for ' . $question['title'],'invalid_answer'); } }
            if ($question['required'] && !$values) { throw new AzHttpError(400,'Please answer: '.$question['title'],'required_answer'); }
            $clean[$id]=$question['type']==='single'?($values[0]??''):$values;
        } else {
            $text=az_text($input??'', $question['type']==='short'?400:2500, 'Answer');
            if ($question['required'] && $text==='') { throw new AzHttpError(400,'Please answer: '.$question['title'],'required_answer'); }
            $clean[$id]=$text;
        }
    }
    return $clean;
}

function az_casting_submit_application(array $body): string
{
    az_account_throttle('casting-apply',20,3600);
    if (trim((string)($body['website']??''))!=='') { return 'received'; } // honeypot; no response distinguishing spam
    if (!filter_var($body['adultConfirmed']??false,FILTER_VALIDATE_BOOLEAN) || !filter_var($body['contactConsent']??false,FILTER_VALIDATE_BOOLEAN)) {
        throw new AzHttpError(400,'Please confirm you are 18 or older and agree to be contacted about your application.','consent_required');
    }
    $name=az_text($body['name']??'',120,'Name');
    $email=strtolower(az_text($body['email']??'',254,'Email address'));
    if ($name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL)) { throw new AzHttpError(400,'Enter your name and a valid email.','invalid_contact'); }
    $stage=az_text($body['stageName']??'',120,'Stage name');
    $city=az_text($body['city']??'',120,'Arizona city');
    $social=az_text($body['social']??'',500,'Social links');
    $phone=az_text($body['phone']??'',50,'Phone');
    if ($phone!==''&&!preg_match('/\A[+0-9() .\-]{7,50}\z/D',$phone)) { throw new AzHttpError(400,'Enter a valid phone number or leave it blank.','invalid_phone'); }
    $form=az_casting_form();
    if (az_integer($body['revision']??null,1,PHP_INT_MAX,'Questionnaire version')!==$form['revision']) {
        throw new AzHttpError(409,'The questionnaire was recently updated. Reload to see the current questions.','form_updated');
    }
    $answers=az_casting_collect_answers($form['questions'],is_array($body['answers']??null)?$body['answers']:[]);
    $lock=az_community_lock();
    try {
        // An open application for the same address is treated as a successful submission without exposing its existence.
        $q=az_db()->prepare("SELECT id FROM casting_leads WHERE email=? AND status IN ('new','contacted','screening','interview','approved','converted') LIMIT 1");
        $q->execute([$email]);if($q->fetchColumn()!==false){return 'received';}
        $leadId=az_id();
        az_db()->prepare('INSERT INTO casting_leads (id,applicant_name,email,stage_name,city,social_handles,phone,questions_json,answers_json,questionnaire_revision,status,admin_notes,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?, ?,?,?)')
            ->execute([$leadId,$name,$email,$stage,$city,$social,$phone,json_encode($form['questions'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($answers,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$form['revision'],'new','',az_now(),az_now()]);
        az_agent_casting_record('new_application','New casting application','A new public casting application is awaiting review.','casting.php?lead='.$leadId);
        return 'received';
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}

function az_casting_lead(string $id): array
{
    if (!az_valid_id($id)) { throw new AzHttpError(404,'Application not found.','lead_not_found'); }
    $q=az_db()->prepare('SELECT * FROM casting_leads WHERE id=?');$q->execute([$id]);
    $row=$q->fetch();if(!$row){throw new AzHttpError(404,'Application not found.','lead_not_found');}
    return $row;
}
function az_casting_list_leads(string $status = 'all', string $search = ''): array
{
    $statuses=['new','contacted','screening','interview','approved','declined','converted'];
    if ($status!=='all'&&!in_array($status,$statuses,true)){$status='all';}
    $sql='SELECT id,applicant_name,email,stage_name,city,status,revision,created_at,updated_at,converted_user_id FROM casting_leads';
    $conditions=[];$args=[];
    if($status!=='all'){$conditions[]='status=?';$args[]=$status;}
    $search=trim($search);
    if($search!==''){
        $conditions[]='(applicant_name LIKE ? OR email LIKE ? OR stage_name LIKE ? OR city LIKE ?)';
        $term='%'.$search.'%';
        $args=array_merge($args,[$term,$term,$term,$term]);
    }
    if($conditions){$sql.=' WHERE '.implode(' AND ',$conditions);}
    $query=az_db()->prepare($sql.' ORDER BY created_at DESC,id DESC LIMIT 500');
    $query->execute($args);return $query->fetchAll();
}
function az_casting_update_lead(array $body,int $actorId=0): void
{
    $lead=az_casting_lead((string)($body['id']??''));
    $revision=az_integer($body['revision']??null,1,PHP_INT_MAX,'Revision');
    $status=az_text($body['status']??'',24,'Status');
    if(!in_array($status,['new','contacted','screening','interview','approved','declined','converted'],true)){
        throw new AzHttpError(400,'Choose an application status.','invalid_status');
    }
    if($lead['status']==='converted' && $status!=='converted'){
        throw new AzHttpError(409,'Converted accounts cannot be moved back into casting.','converted_lead');
    }
    if($status==='converted' && $lead['status']!=='converted'){
        throw new AzHttpError(400,'Use the account invitation to convert a lead.','invalid_status');
    }
    $notes=az_text($body['notes']??'',10000,'Private casting notes');
    $q=az_db()->prepare('UPDATE casting_leads SET status=?,admin_notes=?,revision=revision+1,updated_at=? WHERE id=? AND revision=?');
    $q->execute([$status,$notes,az_now(),$lead['id'],$revision]);
    if($q->rowCount()!==1){throw new AzHttpError(409,'This application changed. Reload before saving.','revision_conflict');}
    az_agent_casting_resolve($lead['id']);
    az_agent_casting_record('review_saved','Casting review updated','An administrator saved the application stage and review.','casting.php?lead='.$lead['id'],$actorId);
}

function az_casting_issue_invitation(string $leadId, int $adminId): string
{
    $lead=az_casting_lead($leadId);
    if($lead['status']!=='approved' || $lead['converted_user_id']!==null){throw new AzHttpError(409,'Approve the application before issuing a model invitation.','approval_required');}
    $q=az_db()->prepare('SELECT id FROM users WHERE email=?');$q->execute([$lead['email']]);$existing=$q->fetchColumn();
    if($existing!==false){
        $roles=az_account_user((int)$existing)['roles']??[];
        if(in_array('model',$roles,true)){throw new AzHttpError(409,'This email already has a model account.','already_model');}
    }
    $token=bin2hex(random_bytes(32));
    $db=az_db();$db->beginTransaction();
    try{
        $db->prepare('DELETE FROM casting_invites WHERE lead_id=?')->execute([$leadId]);
        $db->prepare('INSERT INTO casting_invites (token_hash,lead_id,created_by,expires_at,created_at) VALUES (?,?,?,?,?)')
           ->execute([hash('sha256',$token),$leadId,$adminId,time()+7*86400,az_now()]);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    az_agent_casting_resolve($leadId);
    az_agent_casting_record('invite_issued','Model invitation created','A private invitation was generated after casting approval. It is not sent automatically.','casting.php?lead='.$leadId,$adminId);
    return 'model-invite.php?token='.$token;
}
function az_casting_invite_detail(string $token): ?array
{
    if(!preg_match('/\A[a-f0-9]{64}\z/D',$token)){return null;}
    $q=az_db()->prepare('SELECT i.*,l.applicant_name,l.email,l.stage_name,l.social_handles,l.status,l.converted_user_id FROM casting_invites i JOIN casting_leads l ON l.id=i.lead_id WHERE i.token_hash=?');
    $q->execute([hash('sha256',$token)]);$row=$q->fetch();
    return $row && !$row['used_at'] && (int)$row['expires_at']>time() && $row['status']==='approved' && $row['converted_user_id']===null ? $row : null;
}
/** Redeeming an invite is the ONLY public route that creates an approved model role. */
function az_casting_redeem_invitation(string $token,mixed $password, ?array $loggedIn): array
{
    $lock=az_community_lock();
    try{
        $invite=az_casting_invite_detail($token);
        if(!$invite){throw new AzHttpError(410,'This invitation expired or was already used. Ask the studio for another invitation.','invite_expired');}
        $db=az_db();$db->beginTransaction();
        try{
            $q=$db->prepare('SELECT id,status FROM users WHERE email=?');$q->execute([$invite['email']]);$existing=$q->fetch();
            if($existing){
                if(!$loggedIn || (int)$loggedIn['id']!==(int)$existing['id'] || $existing['status']!=='active'){
                    throw new AzHttpError(403,'Sign into the account using your application email before accepting this invitation.','account_login_required');
                }
                $userId=(int)$existing['id'];
            } else {
                $password=az_account_password($password);
                $db->prepare('INSERT INTO users (name,email,password_hash,created_at,status) VALUES (?,?,?,?,?)')
                    ->execute([$invite['applicant_name'],$invite['email'],password_hash($password,PASSWORD_DEFAULT),az_now(),'active']);
                $userId=(int)$db->lastInsertId();
            }
            foreach(['model','fan','customer'] as $role){
                $q=$db->prepare('SELECT 1 FROM user_roles WHERE user_id=? AND role=?');$q->execute([$userId,$role]);
                if(!$q->fetchColumn()){$db->prepare('INSERT INTO user_roles (user_id,role) VALUES (?,?)')->execute([$userId,$role]);}
            }
            $db->prepare("UPDATE user_role_requests SET status='approved',reviewed_at=? WHERE user_id=? AND role='model'")->execute([az_now(),$userId]);
            $q=$db->prepare('SELECT id FROM model_profiles WHERE linked_user_id=?');$q->execute([$userId]);
            if(!$q->fetchColumn()){
                $id=az_id();$stage=$invite['stage_name']!==''?$invite['stage_name']:$invite['applicant_name'];
                $db->prepare("INSERT INTO model_profiles (id,owner_id,stage_name,public_bio,social_handles,interests,availability,private_notes,status,created_at,updated_at,linked_user_id) VALUES (?,?,?,'',?,'','','','active',?,?,?)")
                   ->execute([$id,$invite['created_by'],$stage,$invite['social_handles'],az_now(),az_now(),$userId]);
            }
            $q=$db->prepare('UPDATE casting_invites SET used_at=? WHERE token_hash=? AND used_at IS NULL AND expires_at>?');
            $q->execute([az_now(),hash('sha256',$token),time()]);
            if($q->rowCount()!==1){throw new AzHttpError(409,'This invitation was already redeemed.','invite_used');}
            $q=$db->prepare("UPDATE casting_leads SET status='converted',converted_user_id=?,revision=revision+1,updated_at=? WHERE id=? AND status='approved' AND converted_user_id IS NULL");
            $q->execute([$userId,az_now(),$invite['lead_id']]);
            if($q->rowCount()!==1){throw new AzHttpError(409,'This application has already been converted.','already_converted');}
            $db->commit();
            try {
                az_agent_workspace_emit((int)$invite['created_by'],$userId,'casting_invitation_accepted','Welcome to AZHottie','Your model account is ready. Complete your production bio and availability in your account settings.','account.php');
                az_agent_casting_record('converted','Model invitation accepted','A model account was created or connected following an accepted invitation.','casting.php?lead='.$invite['lead_id']);
            } catch(Throwable $eventError){error_log('Casting conversion activity error: '.$eventError->getMessage());}
            return az_account_user($userId);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
