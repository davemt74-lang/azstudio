<?php
declare(strict_types=1);

const AZ_ACCOUNT_ROLES = ['admin', 'editor', 'model', 'fan', 'customer', 'professional'];
const AZ_REQUESTABLE_ROLES = ['model', 'professional'];

function az_upgrade_accounts_schema(PDO $db): void
{
    if (az_schema_version($db) >= 4) { return; }
    az_prepare_storage();
    $lock = fopen(AZ_STORAGE . '/schema-migration.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('Could not lock the account upgrade.'); }
    try {
        if (az_schema_version($db) >= 4) { return; }
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $userId = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        foreach (['users' => ['status' => "VARCHAR(16) NOT NULL DEFAULT 'active'", 'revision' => 'INTEGER NOT NULL DEFAULT 1', 'password_version' => 'INTEGER NOT NULL DEFAULT 1'], 'model_profiles' => ['linked_user_id' => "$userId NULL"]] as $table => $columns) {
            if ($mysql) {
                $query = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
                $query->execute([$table]); $existing = $query->fetchAll(PDO::FETCH_COLUMN);
            } else { $existing = array_column($db->query("PRAGMA table_info($table)")->fetchAll(), 'name'); }
            foreach ($columns as $name => $definition) { if (!in_array($name, $existing, true)) { $db->exec("ALTER TABLE $table ADD COLUMN $name $definition"); } }
        }
        $db->exec("CREATE TABLE IF NOT EXISTS user_roles (user_id $userId NOT NULL, role VARCHAR(20) NOT NULL, PRIMARY KEY (user_id, role), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS user_role_requests (user_id $userId NOT NULL, role VARCHAR(20) NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'pending', requested_at VARCHAR(32) NOT NULL, reviewed_at VARCHAR(32) NULL, PRIMARY KEY (user_id, role), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        // Before version 1.2 every stored user was a studio administrator.
        $legacy = $db->query('SELECT id FROM users WHERE NOT EXISTS (SELECT 1 FROM user_roles WHERE user_roles.user_id = users.id)')->fetchAll(PDO::FETCH_COLUMN);
        $insert = $db->prepare('INSERT INTO user_roles (user_id, role) VALUES (?, ?)');
        foreach ($legacy as $id) { $insert->execute([(int)$id, 'admin']); }
        if ($mysql) {
            $query = $db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
            $query->execute(['model_profiles', 'profiles_linked_user']);
            if (!(int)$query->fetchColumn()) { $db->exec('CREATE UNIQUE INDEX profiles_linked_user ON model_profiles (linked_user_id)'); }
        } else { $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS profiles_linked_user ON model_profiles (linked_user_id)'); }
        $db->exec('UPDATE studio_schema SET version = 4 WHERE id = 1');
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function az_has_role(array $user, string $role): bool
{
    return in_array($role, $user['roles'] ?? [], true);
}

function az_account_home(array $user): string
{
    return 'dashboard.php';
}

function az_require_roles(array $roles, bool $json = false): array
{
    $user = az_require_auth($json);
    foreach ($roles as $role) { if (az_has_role($user, $role)) { return $user; } }
    if ($json) { throw new AzHttpError(403, 'This area is available to a different account role.', 'role_required'); }
    http_response_code(403);
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Account access · AZHottie</title><link rel="stylesheet" href="' . az_h(az_url('assets/auth.css')) . '"><main class="auth-shell"><section class="auth-card"><h1>Your member space is ready.</h1><p>This page requires a different account role.</p><a class="button" href="' . az_h(az_url('calendar.php')) . '">Open the calendar</a><p><a href="' . az_h(az_url('account-settings.php')) . '">View my account roles</a></p></section></main></html>';
    exit;
}

function az_require_admin(bool $json = false): array
{
    return az_require_roles(['admin'], $json);
}

function az_account_row(int $id): array
{
    $query = az_db()->prepare('SELECT * FROM users WHERE id = ?'); $query->execute([$id]);
    $row = $query->fetch();
    if (!$row) { throw new AzHttpError(404, 'This account was not found.', 'account_not_found'); }
    return $row;
}

function az_account_user(int $id): ?array
{
    $query = az_db()->prepare('SELECT id,name,email,status,revision,created_at,last_login_at FROM users WHERE id = ?'); $query->execute([$id]);
    $row = $query->fetch(); if (!$row) { return null; }
    $query = az_db()->prepare('SELECT role FROM user_roles WHERE user_id = ? ORDER BY role'); $query->execute([$id]);
    $roles = $query->fetchAll(PDO::FETCH_COLUMN);
    $query = az_db()->prepare("SELECT role FROM user_role_requests WHERE user_id = ? AND status = 'pending' ORDER BY role"); $query->execute([$id]);
    return ['id' => (int)$row['id'], 'name' => $row['name'], 'email' => $row['email'], 'roles' => $roles, 'status' => $row['status'], 'revision' => (int)$row['revision'], 'requestedRoles' => $query->fetchAll(PDO::FETCH_COLUMN), 'createdAt' => $row['created_at'], 'lastLoginAt' => $row['last_login_at']];
}

function az_community_lock()
{
    az_prepare_storage();
    $lock = fopen(AZ_STORAGE . '/community.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) { throw new AzHttpError(503, 'The studio is saving another change. Please try again.', 'community_busy'); }
    return $lock;
}

function az_account_roles(mixed $value, array $allowed = AZ_ACCOUNT_ROLES): array
{
    if (!is_array($value) || count($value) > 6) { throw new AzHttpError(400, 'Choose valid account roles.', 'invalid_roles'); }
    $roles = [];
    foreach ($value as $role) {
        if (!is_string($role) || !in_array($role, $allowed, true)) { throw new AzHttpError(400, 'Choose valid account roles.', 'invalid_roles'); }
        if (!in_array($role, $roles, true)) { $roles[] = $role; }
    }
    sort($roles); return $roles;
}

function az_account_password(mixed $password): string
{
    if (!is_string($password) || strlen($password) < 8 || strlen($password) > 72 || str_contains($password, "\0")) { throw new AzHttpError(400, 'Choose a password between 8 and 72 characters.', 'invalid_password'); }
    return $password;
}

function az_account_throttle(string $scope, int $limit, int $seconds): void
{
    $key = hash_hmac('sha256', $scope . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''), (string)az_config()['app_secret']);
    $query = az_db()->prepare('SELECT * FROM auth_attempts WHERE attempt_key = ?'); $query->execute([$key]); $old = $query->fetch(); $now = time();
    $inWindow = $old && (int)$old['window_started'] > $now - $seconds;
    if ($inWindow && (int)$old['attempt_count'] >= $limit) { throw new AzHttpError(429, 'Too many attempts. Please try again later.', 'rate_limited'); }
    if ($old) { az_db()->prepare('UPDATE auth_attempts SET attempt_count = ?, window_started = ? WHERE attempt_key = ?')->execute([$inWindow ? (int)$old['attempt_count'] + 1 : 1, $inWindow ? (int)$old['window_started'] : $now, $key]); }
    else { az_db()->prepare('INSERT INTO auth_attempts (attempt_key,attempt_count,window_started) VALUES (?,1,?)')->execute([$key,$now]); }
}

function az_create_member(array $body): array
{
    az_account_throttle('signup', 12, 3600);
    $name = az_text($body['name'] ?? '', 120, 'Name');
    $email = strtolower(az_text($body['email'] ?? '', 254, 'Email address'));
    $password = az_account_password($body['password'] ?? null);
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new AzHttpError(400, 'Enter your name and a valid email address.', 'invalid_account'); }
    if (!filter_var($body['adultConfirmed'] ?? false, FILTER_VALIDATE_BOOLEAN)) { throw new AzHttpError(400, 'Confirm that you are 18 or older to join AZHottie.', 'adult_confirmation_required'); }
    $requested = az_account_roles($body['roles'] ?? ['fan', 'customer'], ['fan','customer','model','professional']);
    $granted = array_values(array_intersect($requested, ['fan','customer']));
    if (!$granted) { $granted = ['customer']; }
    $pending = array_values(array_intersect($requested, AZ_REQUESTABLE_ROLES));
    $db = az_db();
    $query = $db->prepare('SELECT id FROM users WHERE email = ?'); $query->execute([$email]);
    if ($query->fetch()) { throw new AzHttpError(409, 'An account already uses this email address. Sign in instead.', 'email_exists'); }
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO users (name,email,password_hash,created_at,status) VALUES (?,?,?,?,?)')->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),az_now(),'active']);
        $id = (int)$db->lastInsertId();
        foreach ($granted as $role) { $db->prepare('INSERT INTO user_roles (user_id,role) VALUES (?,?)')->execute([$id,$role]); }
        foreach ($pending as $role) { $db->prepare('INSERT INTO user_role_requests (user_id,role,status,requested_at) VALUES (?,?,?,?)')->execute([$id,$role,'pending',az_now()]); }
        $db->commit();
        return az_account_user($id);
    } catch (Throwable $error) { if ($db->inTransaction()) { $db->rollBack(); } throw $error; }
}

function az_member_profile(int $userId): ?array
{
    $query = az_db()->prepare('SELECT id,stage_name,public_bio,social_handles,interests,availability,revision FROM model_profiles WHERE linked_user_id = ?'); $query->execute([$userId]); $row = $query->fetch();
    if (!$row) { return null; }
    return ['id'=>$row['id'],'stageName'=>$row['stage_name'],'publicBio'=>$row['public_bio'],'socialHandles'=>$row['social_handles'],'interests'=>$row['interests'],'availability'=>$row['availability'],'revision'=>(int)$row['revision']];
}

function az_account_detail(int $id, array $actor): array
{
    $user = az_account_user($id); if (!$user) { throw new AzHttpError(404, 'This account was not found.', 'account_not_found'); }
    $query = az_db()->prepare('SELECT id,stage_name,owner_id FROM model_profiles WHERE linked_user_id = ?'); $query->execute([$id]); $profile = $query->fetch();
    $user['modelProfileId'] = $profile && (int)$profile['owner_id'] === (int)$actor['id'] ? $profile['id'] : null;
    $user['modelProfileLinked'] = (bool)$profile;
    return $user;
}

function az_update_account(array $body, array $actor): array
{
    if (!az_has_role($actor,'admin')) { throw new AzHttpError(403, 'Administrator access is required.', 'role_required'); }
    $id = az_integer($body['id'] ?? null,1,PHP_INT_MAX,'Account ID');
    $old = az_account_row($id); $current = az_account_user($id);
    if (az_integer($body['revision'] ?? null,1,PHP_INT_MAX,'Revision') !== (int)$old['revision']) { throw new AzHttpError(409, 'This account changed in another tab. Reload it before saving.', 'revision_conflict'); }
    $roles = az_account_roles($body['roles'] ?? $current['roles']);
    if (!$roles) { throw new AzHttpError(400, 'Keep at least one account role.', 'invalid_roles'); }
    $status = $body['status'] ?? $old['status'];
    if (!in_array($status,['active','suspended'],true)) { throw new AzHttpError(400,'Choose an active or suspended account status.','invalid_status'); }
    if ($old['status'] === 'active' && in_array('admin',$current['roles'],true) && ($status !== 'active' || !in_array('admin',$roles,true))) {
        $count = (int)az_db()->query("SELECT COUNT(*) FROM users u JOIN user_roles r ON r.user_id=u.id AND r.role='admin' WHERE u.status='active'")->fetchColumn();
        if ($count <= 1) { throw new AzHttpError(409,'Keep at least one active administrator.','last_admin'); }
    }
    $name = az_text($body['name'] ?? $old['name'],120,'Name'); if ($name === '') { throw new AzHttpError(400,'Enter a name.','invalid_account'); }
    $profileId = array_key_exists('modelProfileId',$body) ? az_text($body['modelProfileId'],32,'Model profile') : null;
    $linkSpecified = array_key_exists('modelProfileId',$body);
    $createProfile = filter_var($body['createModelProfile'] ?? false,FILTER_VALIDATE_BOOLEAN);
    if (($profileId !== null && $profileId !== '' || $createProfile) && !in_array('model',$roles,true)) { throw new AzHttpError(400,'Add the model role before linking a model profile.','model_role_required'); }
    if ($profileId) {
        $profile = az_profile_row($profileId,(int)$actor['id']);
        if (!empty($profile['linked_user_id']) && (int)$profile['linked_user_id'] !== $id) { throw new AzHttpError(409,'This model profile is already linked to another account.','profile_already_linked'); }
    }
    $db = az_db(); $db->beginTransaction();
    try {
        $db->prepare('UPDATE users SET name=?,status=?,revision=revision+1 WHERE id=?')->execute([$name,$status,$id]);
        $db->prepare('DELETE FROM user_roles WHERE user_id=?')->execute([$id]);
        foreach ($roles as $role) { $db->prepare('INSERT INTO user_roles (user_id,role) VALUES (?,?)')->execute([$id,$role]); }
        foreach ($current['requestedRoles'] as $role) {
            if (in_array($role,$roles,true) || filter_var($body['declineRequested'] ?? false,FILTER_VALIDATE_BOOLEAN)) {
                $db->prepare('UPDATE user_role_requests SET status=?,reviewed_at=? WHERE user_id=? AND role=?')->execute([in_array($role,$roles,true)?'approved':'declined',az_now(),$id,$role]);
            }
        }
        if (!in_array('model',$roles,true) || $linkSpecified || $createProfile) { $db->prepare('UPDATE model_profiles SET linked_user_id=NULL,revision=revision+1,updated_at=? WHERE linked_user_id=?')->execute([az_now(),$id]); }
        if ($createProfile) {
            $profileId = az_id();
            $db->prepare("INSERT INTO model_profiles (id,owner_id,stage_name,public_bio,social_handles,interests,availability,private_notes,status,created_at,updated_at,linked_user_id) VALUES (?,?,?,'','','','','','active',?,?,?)")->execute([$profileId,$actor['id'],$name,az_now(),az_now(),$id]);
        } elseif ($profileId) { $db->prepare('UPDATE model_profiles SET linked_user_id=?,revision=revision+1,updated_at=? WHERE id=?')->execute([$id,az_now(),$profileId]); }
        if ($status === 'suspended' || !in_array('admin',$roles,true)) {
            // An account losing studio access also loses any phone capabilities it hosted.
            if (function_exists('az_phone_close_interview')) { $query=$db->prepare('SELECT id FROM interviews WHERE owner_id=?');$query->execute([$id]);foreach($query->fetchAll(PDO::FETCH_COLUMN) as $interviewId){az_phone_close_interview($db,$interviewId);} }
        }
        $db->commit();
        return az_account_detail($id,$actor);
    } catch (Throwable $error) { if ($db->inTransaction()) { $db->rollBack(); } throw $error; }
}

function az_update_self(array $body, array $actor): array
{
    $name=az_text($body['name']??$actor['name'],120,'Name'); if($name===''){throw new AzHttpError(400,'Enter your name.','invalid_account');}
    $revision=az_integer($body['revision']??null,1,PHP_INT_MAX,'Revision');
    $row=az_account_row((int)$actor['id']); if($revision!==(int)$row['revision']){throw new AzHttpError(409,'Your account changed. Reload before saving.','revision_conflict');}
    $profile = null;
    if (array_key_exists('modelProfile',$body)) {
        if(!az_has_role($actor,'model')){throw new AzHttpError(403,'The model role is required.','role_required');}
        $profile=az_member_profile((int)$actor['id']); if(!$profile){throw new AzHttpError(404,'Ask an administrator to link your model profile first.','profile_not_linked');}
        $input=is_object($body['modelProfile'])?get_object_vars($body['modelProfile']):$body['modelProfile'];
        if(!is_array($input)){throw new AzHttpError(400,'Enter valid model profile fields.','invalid_profile');}
        if(az_integer($input['revision']??null,1,PHP_INT_MAX,'Model profile revision')!==$profile['revision']){throw new AzHttpError(409,'Your model profile changed. Reload before saving.','revision_conflict');}
        foreach(['stageName'=>200,'publicBio'=>10000,'socialHandles'=>4000,'interests'=>4000,'availability'=>4000] as $field=>$max){$profile[$field]=az_text($input[$field]??$profile[$field],$max,$field);}
        if($profile['stageName']===''){throw new AzHttpError(400,'Enter a stage name.','invalid_profile');}
    }
    $db=az_db();$db->beginTransaction();
    try{
        $db->prepare('UPDATE users SET name=?,revision=revision+1 WHERE id=?')->execute([$name,$actor['id']]);
        if($profile){
            $update=$db->prepare('UPDATE model_profiles SET stage_name=?,public_bio=?,social_handles=?,interests=?,availability=?,revision=revision+1,updated_at=? WHERE id=? AND linked_user_id=? AND revision=?');
            $update->execute([$profile['stageName'],$profile['publicBio'],$profile['socialHandles'],$profile['interests'],$profile['availability'],az_now(),$profile['id'],$actor['id'],$profile['revision']]);
            if($update->rowCount()!==1){throw new AzHttpError(409,'Your model profile changed. Reload before saving.','revision_conflict');}
        }
        $db->commit();return ['user'=>az_account_user((int)$actor['id']),'modelProfile'=>az_member_profile((int)$actor['id'])];
    }catch(Throwable $error){if($db->inTransaction()){$db->rollBack();}throw $error;}
}

function az_request_account_roles(array $body,array $actor):array
{
    $roles=az_account_roles($body['roles']??[],AZ_REQUESTABLE_ROLES);
    if(!$roles){throw new AzHttpError(400,'Choose a role to request.','invalid_roles');}
    foreach($roles as $role){
        if(az_has_role($actor,$role)){continue;}
        $query=az_db()->prepare('SELECT status FROM user_role_requests WHERE user_id=? AND role=?');$query->execute([$actor['id'],$role]);$status=$query->fetchColumn();
        if($status==='pending'){continue;}
        if($status!==false){az_db()->prepare("UPDATE user_role_requests SET status='pending',requested_at=?,reviewed_at=NULL WHERE user_id=? AND role=?")->execute([az_now(),$actor['id'],$role]);}
        else{az_db()->prepare("INSERT INTO user_role_requests (user_id,role,status,requested_at) VALUES (?,?,'pending',?)")->execute([$actor['id'],$role,az_now()]);}
    }
    az_db()->prepare('UPDATE users SET revision=revision+1 WHERE id=?')->execute([$actor['id']]);
    return az_account_user((int)$actor['id']);
}
