<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
ini_set('display_errors', '0');
header('Cache-Control: no-store');

try {
    $actor = az_require_auth(true);
    $action = az_text($_GET['action'] ?? '', 60, 'Action');
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        $csrf = az_csrf_token();
        session_write_close();
        if ($action === 'bootstrap') {
            az_json(['ok'=>true,'data'=>['user'=>$actor,'csrf'=>$csrf,'modelProfile'=>az_has_role($actor,'model')?az_member_profile((int)$actor['id']):null,'canManageAccounts'=>az_has_role($actor,'admin'),'roleChoices'=>['admin'=>'Administrator','model'=>'Model','fan'=>'Fan','customer'=>'Customer','professional'=>'Professional']]]);
        }
        if (!az_has_role($actor,'admin')) { throw new AzHttpError(403,'Administrator access is required.','role_required'); }
        if ($action === 'accounts') {
            $ids=az_db()->query('SELECT id FROM users ORDER BY created_at DESC,id DESC')->fetchAll(PDO::FETCH_COLUMN);
            az_json(['ok'=>true,'data'=>array_map(static fn($id)=>az_account_detail((int)$id,$actor),$ids)]);
        }
        if ($action === 'account') { az_json(['ok'=>true,'data'=>az_account_detail(az_integer($_GET['id']??null,1,PHP_INT_MAX,'Account ID'),$actor)]); }
        if ($action === 'modelProfiles') {
            $q=az_db()->prepare('SELECT id,stage_name,linked_user_id,status FROM model_profiles WHERE owner_id=? ORDER BY stage_name,id');$q->execute([$actor['id']]);
            az_json(['ok'=>true,'data'=>array_map(static fn($r)=>['id'=>$r['id'],'stageName'=>$r['stage_name'],'linkedUserId'=>$r['linked_user_id']===null?null:(int)$r['linked_user_id'],'status'=>$r['status']],$q->fetchAll())]);
        }
        throw new AzHttpError(404,'This account action was not found.','unknown_action');
    }
    if ($method !== 'POST') { header('Allow: GET, POST'); throw new AzHttpError(405,'Use GET or POST for this action.','method_not_allowed'); }
    az_require_csrf(); $body=az_json_body(); $lock=az_community_lock();
    try {
        $actor=az_require_auth(true);
        if ($action==='updateAccount') { $data=az_update_account($body,$actor); }
        elseif($action==='updateSelf'){ $data=az_update_self($body,$actor); }
        elseif($action==='requestRoles'){ $data=az_request_account_roles($body,$actor); }
        elseif($action==='changePassword'){
            az_account_throttle('change-password-'.$actor['id'],8,900);
            $password=az_account_password($body['newPassword']??null);
            $row=az_account_row((int)$actor['id']); $current=$body['currentPassword']??'';
            if(!is_string($current)||strlen($current)>72||!password_verify($current,$row['password_hash'])){throw new AzHttpError(400,'Your current password did not match.','password_mismatch');}
            az_db()->prepare('UPDATE users SET password_hash=?,password_version=password_version+1,revision=revision+1 WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$actor['id']]);
            az_login_user($actor); $data=['user'=>az_account_user((int)$actor['id']),'csrf'=>az_csrf_token()];
        }
        else{throw new AzHttpError(404,'This account action was not found.','unknown_action');}
    } finally { flock($lock,LOCK_UN);fclose($lock); }
    az_json(['ok'=>true,'data'=>$data]);
} catch(AzHttpError $error){az_json(['ok'=>false,'error'=>['code'=>$error->errorCode,'message'=>$error->getMessage()]],$error->status);}
catch(Throwable $error){az_log_exception($error);az_json(['ok'=>false,'error'=>['code'=>'server_error','message'=>'The account update could not be completed. Please try again.']],500);}
