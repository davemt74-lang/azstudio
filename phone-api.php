<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/recordings.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
ini_set('display_errors', '0');

try {
    if (!az_is_installed()) { throw new AzHttpError(503, 'The studio is not installed yet.', 'not_installed'); }
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, ['GET', 'POST'], true)) { throw new AzHttpError(405, 'Use GET or POST for this operation.', 'method_not_allowed'); }
    $role = is_string($_GET['role'] ?? null) ? $_GET['role'] : 'phone';
    $action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
    $body = $method === 'GET' ? $_GET : (str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') ? $_POST : az_json_body());
    $user = null;
    if ($role === 'host') {
        $user = az_require_admin(true);
        $passwordVersion=(int)($_SESSION['password_version']??1);
        if ($method === 'POST') { az_require_csrf(); }
    } elseif ($role === 'phone') {
        if ($method === 'POST') { az_phone_origin(); }
    } else { throw new AzHttpError(400, 'Unknown phone role.', 'invalid_field'); }
    session_write_close();
    az_prepare_storage();
    $communityLock = az_community_lock();
    if ($role === 'host') {
        $user = az_account_user((int)$user['id']);
        if (!$user || $user['status'] !== 'active' || !az_has_role($user, 'admin')) { throw new AzHttpError(403, 'Administrator access is required.', 'role_required'); }
        if ((int)az_account_row((int)$user['id'])['password_version']!==$passwordVersion) { throw new AzHttpError(401,'Sign in again before managing phone cameras.','session_changed'); }
        $owner = $user['id'];
        $interviewId = az_phone_id($body['interviewId'] ?? null, 'Interview');
        az_interview_row($interviewId, $owner);
        $lock = fopen(AZ_STORAGE . '/uploads/admin-' . $owner . '.lock', 'c+b');
        if (!$lock || !flock($lock, LOCK_EX)) { throw new AzHttpError(503, 'Another change is being saved. Try again.', 'storage_busy'); }
        $room = az_phone_room($interviewId, $owner, true);
        if ($method === 'GET' && $action === 'list') {
            if ($room['interview_status'] === 'active') {
                az_db()->prepare('UPDATE phone_rooms SET host_seen_at = ? WHERE interview_id = ?')->execute([time(), $interviewId]);
            }
            $q = az_db()->prepare('SELECT * FROM phone_connections WHERE interview_id = ? AND owner_id = ? ORDER BY created_at DESC, id DESC');
            $q->execute([$interviewId, $owner]); $rows = $q->fetchAll();
            $offers = [];
            if ((int)$room['enabled'] && $room['interview_status'] === 'active') {
                foreach ($rows as $phone) { $offer = az_phone_offer_data($phone); if ($offer) { $offers[] = $offer; } }
            }
            $counts = az_db()->prepare('SELECT COUNT(*) FROM takes WHERE interview_id = ? AND owner_id = ?'); $counts->execute([$interviewId, $owner]);
            az_json(['ok' => true, 'data' => ['enabled' => (bool)$room['enabled'] && $room['interview_status'] === 'active', 'connections' => array_map('az_phone_connection_data', $rows), 'offers' => $offers, 'takeCount' => (int)$counts->fetchColumn(), 'iceServers' => az_phone_ice_servers()]]);
        }
        if ($method !== 'POST') { throw new AzHttpError(405, 'This phone action requires POST.', 'method_not_allowed'); }
        if ($action === 'invite') {
            if ($room['interview_status'] !== 'active') { throw new AzHttpError(409, 'Restore this interview before inviting phones.', 'interview_archived'); }
            $id = az_id(); $secret = bin2hex(random_bytes(32)); $expires = time() + 900;
            az_db()->prepare('DELETE FROM phone_invites WHERE interview_id = ?')->execute([$interviewId]);
            az_db()->prepare('INSERT INTO phone_invites (id,interview_id,owner_id,secret_hash,expires_at,created_at) VALUES (?,?,?,?,?,?)')->execute([$id,$interviewId,$owner,az_phone_secret($secret),$expires,az_now()]);
            az_db()->prepare('UPDATE phone_rooms SET enabled = 1, host_seen_at = ? WHERE interview_id = ?')->execute([time(),$interviewId]);
            az_json(['ok' => true, 'data' => ['id' => $id, 'url' => az_url('phone.php?invite=' . $id) . '#key=' . $secret, 'expiresAt' => $expires]]);
        }
        if ($action === 'close') { az_phone_close_interview(az_db(), $interviewId); az_json(['ok'=>true,'data'=>['closed'=>true]]); }
        if ($action === 'context') {
            $qid = az_text($body['questionId'] ?? 'phone-footage', 160, 'Question ID');
            $question = az_text($body['questionText'] ?? 'Behind the scenes', 4000, 'Question');
            $private = filter_var($body['isPrivate'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (!empty($room['project_id'])) {
                $shot=az_work_shot_row($qid,$user,true);
                if ($shot['project_id']!==$room['project_id'] || $shot['status']!=='active' || $shot['project_status']!=='active') { throw new AzHttpError(409,'Choose an active shot in this production.','shot_unavailable'); }
                $question=$shot['title']; $private=true;
            }
            az_db()->prepare('UPDATE phone_rooms SET question_id = ?, question_text = ?, is_private = ? WHERE interview_id = ?')->execute([$qid ?: 'phone-footage', $question ?: 'Behind the scenes', (int)$private, $interviewId]);
            az_json(['ok'=>true,'data'=>['saved'=>true]]);
        }
        if (in_array($action, ['approve','revoke','reject','answer'], true)) {
            $phone = az_phone_row(az_phone_id($body['id'] ?? null), $owner);
            if ($phone['interview_id'] !== $interviewId) { throw new AzHttpError(404, 'This phone belongs to a different interview.', 'phone_not_found'); }
            if ($action === 'approve') {
                az_phone_require_open($room);
                if ($phone['status'] !== 'pending' || (int)$phone['expires_at'] <= time()) { throw new AzHttpError(409, 'This request expired. Ask the phone to scan again.', 'phone_expired'); }
                az_db()->prepare("UPDATE phone_connections SET status = 'approved', expires_at = ? WHERE id = ?")->execute([time()+28800,$phone['id']]);
            } elseif ($action === 'answer') {
                az_phone_require_open($room);
                if ($phone['status'] !== 'approved' || (int)$phone['expires_at'] <= time()) { throw new AzHttpError(403, 'This phone is no longer approved.', 'phone_disconnected'); }
                $nonce = az_phone_id($body['nonce'] ?? null, 'Live connection'); $sdp = az_phone_sdp($body['sdp'] ?? null);
                $q = az_db()->prepare('SELECT * FROM phone_live WHERE connection_id = ? AND nonce = ? AND updated_at >= ?');
                $q->execute([$phone['id'],$nonce,time()-30]); $live = $q->fetch();
                if (!$live || ($live['answer_sdp'] !== null && $live['answer_sdp'] !== $sdp)) { throw new AzHttpError(409, 'This feed changed or another host tab already connected.', 'live_replaced'); }
                az_db()->prepare('UPDATE phone_live SET answer_sdp = ? WHERE connection_id = ? AND nonce = ?')->execute([$sdp,$phone['id'],$nonce]);
                az_json(['ok'=>true,'data'=>['accepted'=>true]]);
            } else {
                az_db()->prepare('UPDATE phone_connections SET status = ? WHERE id = ?')->execute([$action === 'reject' ? 'rejected' : 'revoked', $phone['id']]);
                az_db()->prepare('DELETE FROM phone_live WHERE connection_id = ?')->execute([$phone['id']]);
            }
            az_json(['ok'=>true,'data'=>az_phone_connection_data(az_phone_row($phone['id'],$owner))]);
        }
        throw new AzHttpError(404, 'This phone action was not found.', 'unknown_action');
    }
    if ($action === 'claim' && $method === 'POST') {
        $inviteId = az_phone_id($body['inviteId'] ?? null, 'Invitation'); $hash = az_phone_secret($body['key'] ?? null);
        $q = az_db()->prepare('SELECT * FROM phone_invites WHERE id = ?'); $q->execute([$inviteId]); $invite = $q->fetch();
        if (!$invite || !hash_equals($invite['secret_hash'],$hash) || (int)$invite['expires_at'] <= time()) { throw new AzHttpError(403, 'This QR code expired. Ask the host to show a new invitation.', 'invite_expired'); }
        $lock = fopen(AZ_STORAGE . '/uploads/admin-' . (int)$invite['owner_id'] . '.lock','c+b');
        if (!$lock || !flock($lock,LOCK_EX)) { throw new AzHttpError(503, 'Try joining again in a moment.', 'storage_busy'); }
        // Check invitation again inside the owner lock so closing/renewing it wins over a late claim.
        $q->execute([$inviteId]); $fresh = $q->fetch();
        if (!$fresh || (int)$fresh['expires_at'] <= time()) { throw new AzHttpError(403, 'This invitation was closed or renewed.', 'invite_expired'); }
        az_phone_require_open(az_phone_room($invite['interview_id'],(int)$invite['owner_id']));
        $q = az_db()->prepare("SELECT COUNT(*) FROM phone_connections WHERE interview_id = ? AND status IN ('pending','approved') AND expires_at > ?"); $q->execute([$invite['interview_id'],time()]);
        if ((int)$q->fetchColumn() >= 16) { throw new AzHttpError(429, 'This interview already has 16 phone requests or connections. Ask the host to disconnect an unused phone.', 'phone_limit'); }
        $label = az_text($body['label'] ?? '',100,'Phone name');
        if ($label === '' || preg_match('/[\x00-\x1f\x7f]/',$label)) { throw new AzHttpError(400, 'Enter a name for your phone.', 'invalid_field'); }
        $id = az_id(); $token = bin2hex(random_bytes(32));
        az_db()->prepare('INSERT INTO phone_connections (id,interview_id,owner_id,token_hash,label,expires_at,last_seen_at,created_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$id,$invite['interview_id'],(int)$invite['owner_id'],az_phone_secret($token),$label,time()+900,time(),az_now()]);
        az_json(['ok'=>true,'data'=>['id'=>$id,'token'=>$token,'interviewId'=>$invite['interview_id']]],201);
    }
    $id = az_phone_id($body['id'] ?? null);
    $phone = az_phone_authenticate($id, $action !== 'status');
    $owner = (int)$phone['owner_id'];
    $lock = fopen(AZ_STORAGE . '/uploads/admin-' . $owner . '.lock','c+b');
    if (!$lock || !flock($lock,LOCK_EX)) { throw new AzHttpError(503, 'This studio is saving another change. Try again.', 'storage_busy'); }
    $phone = az_phone_authenticate($id, $action !== 'status');
    if ($method === 'GET' && $action === 'status') {
        az_db()->prepare('UPDATE phone_connections SET last_seen_at = ? WHERE id = ?')->execute([time(),$id]);
        $phone['last_seen_at'] = time();
        az_json(['ok'=>true,'data'=>az_phone_status($phone)]);
    }
    if ($method === 'GET' && $action === 'upload') {
        az_json(['ok'=>true,'data'=>az_upload_data(az_phone_own_upload(az_phone_id($body['uploadId'] ?? null,'Upload'),$phone))]);
    }
    if ($method === 'GET' && $action === 'livePoll') {
        $nonce = az_phone_id($body['nonce'] ?? null,'Live connection');
        $room = az_phone_room($phone['interview_id'],$owner);
        if ((int)$room['host_seen_at'] < time()-25) { throw new AzHttpError(409,'The recording room is not open on the host. Keep this page open and try sharing again.','host_offline'); }
        $q = az_db()->prepare('SELECT answer_sdp FROM phone_live WHERE connection_id = ? AND nonce = ? AND updated_at >= ?'); $q->execute([$id,$nonce,time()-30]); $live = $q->fetch();
        if (!$live) { throw new AzHttpError(409,'This live connection expired. Reconnect the camera.','live_replaced'); }
        az_db()->prepare('UPDATE phone_live SET updated_at = ? WHERE connection_id = ? AND nonce = ?')->execute([time(),$id,$nonce]);
        az_json(['ok'=>true,'data'=>['answer'=>$live['answer_sdp']]]);
    }
    if ($method !== 'POST') { throw new AzHttpError(404,'This phone action was not found.','unknown_action'); }
    if ($action === 'beginUpload') {
        $room = az_phone_room($phone['interview_id'],$owner);
        $workContext=null;
        if (!empty($room['project_id'])) {
            $actor=az_work_media_actor(az_account_user($owner));
            $token='phone:'.$id.':'.hash('sha256', az_work_client_token($body['clientToken'] ?? ('phone-'.az_id())));
            $previous=az_db()->prepare('SELECT w.upload_id FROM work_uploads w JOIN phone_upload_links l ON l.upload_id=w.upload_id WHERE w.uploaded_by=? AND w.client_token=? AND l.connection_id=?');
            $previous->execute([$owner,$token,$id]);
            if ($previousId=$previous->fetchColumn()) {
                $prior=az_phone_own_upload($previousId,$phone);
                if ((int)$prior['total_bytes']!==(int)($body['totalBytes']??0)) { throw new AzHttpError(409,'This recovery token belongs to another recording.','upload_conflict'); }
                az_json(['ok'=>true,'data'=>az_upload_data($prior)]);
            }
            $workContext=['assignment'=>az_work_host_assignment($phone['interview_id'],$room['question_id'],$actor),'actor'=>$actor,'clientToken'=>$token];
        }
        $q = az_db()->prepare("SELECT COUNT(*) FROM uploads u JOIN phone_upload_links l ON l.upload_id = u.id WHERE l.connection_id = ? AND u.status = 'pending'"); $q->execute([$id]);
        if ((int)$q->fetchColumn() >= 4) { throw new AzHttpError(429,'Finish or cancel an earlier upload before starting another.','phone_upload_limit'); }
        // Target, question, privacy, ownership and upload purpose are chosen by the host, never by the phone.
        $safe = ['interviewId'=>$phone['interview_id'],'questionId'=>$room['question_id'],'questionText'=>$room['question_text'],'isPrivate'=>(bool)$room['is_private'],'purpose'=>'take',
            'label'=>az_text($body['label'] ?? '',140,'Clip title') ?: ('Phone footage · '.$phone['label']),
            'fileName'=>$body['fileName'] ?? 'phone-video.webm','mimeType'=>$body['mimeType'] ?? 'video/webm','totalBytes'=>$body['totalBytes'] ?? 0,
            'durationMs'=>$body['durationMs'] ?? 0,'answerStartMs'=>null];
        $upload = az_begin_upload($safe,$owner,$workContext);
        try { az_db()->prepare('INSERT INTO phone_upload_links (upload_id,connection_id) VALUES (?,?)')->execute([$upload['uploadId'],$id]); }
        catch (Throwable $e) { az_abandon_upload($upload['uploadId'],$owner); throw $e; }
        az_json(['ok'=>true,'data'=>$upload],201);
    }
    if (in_array($action,['uploadChunk','finishUpload','abandonUpload'],true)) {
        $uploadId = az_phone_id($body['uploadId'] ?? null,'Upload'); az_phone_own_upload($uploadId,$phone,$action!=='abandonUpload');
        if ($action === 'uploadChunk') {
            $file = az_uploaded_file('chunk'); $index = az_integer($body['index'] ?? -1,0,1000000,'Chunk index');
            az_json(['ok'=>true,'data'=>az_store_chunk($uploadId,$index,$file['tmp_name'],$owner)]);
        }
        if ($action === 'abandonUpload') { az_json(['ok'=>true,'data'=>az_abandon_upload($uploadId,$owner)]); }
        $result = az_finish_upload($uploadId,$owner);
        $take = $result['take'] ?? $result;
        // A phone gets its upload receipt, never an administrator's media-library URLs.
        az_json(['ok'=>true,'data'=>['takeId'=>$take['id'] ?? $take['takeId'] ?? null,'saved'=>true,'label'=>$take['label'] ?? 'Phone footage']]);
    }
    if ($action === 'liveOffer') {
        $nonce = az_phone_id($body['nonce'] ?? null,'Live connection'); $sdp = az_phone_sdp($body['sdp'] ?? null);
        $room = az_phone_room($phone['interview_id'],$owner);
        if ((int)$room['host_seen_at'] < time()-25) { throw new AzHttpError(409,'Open the interview recording room on the host before sharing live.','host_offline'); }
        $q = az_db()->prepare('SELECT nonce, offer_sdp FROM phone_live WHERE connection_id = ?');
        $q->execute([$id]); $existing = $q->fetch();
        if ($existing && $existing['nonce'] === $nonce) {
            if ($existing['offer_sdp'] !== $sdp) { throw new AzHttpError(409, 'This live connection already has a different offer. Reconnect the camera.', 'live_conflict'); }
            // A retried receipt must not erase an answer the host already posted.
            az_db()->prepare('UPDATE phone_live SET updated_at = ? WHERE connection_id = ? AND nonce = ?')->execute([time(),$id,$nonce]);
            az_json(['ok'=>true,'data'=>['accepted'=>true]],200);
        }
        if ($existing) {
            az_db()->prepare('UPDATE phone_live SET nonce = ?, offer_sdp = ?, answer_sdp = NULL, updated_at = ? WHERE connection_id = ?')->execute([$nonce,$sdp,time(),$id]);
        } else {
            az_db()->prepare('INSERT INTO phone_live (connection_id,nonce,offer_sdp,updated_at) VALUES (?,?,?,?)')->execute([$id,$nonce,$sdp,time()]);
        }
        az_json(['ok'=>true,'data'=>['accepted'=>true]],201);
    }
    if ($action === 'liveEnd') {
        $nonce = az_phone_id($body['nonce'] ?? null,'Live connection');
        az_db()->prepare('DELETE FROM phone_live WHERE connection_id = ? AND nonce = ?')->execute([$id,$nonce]);
        az_json(['ok'=>true,'data'=>['ended'=>true]]);
    }
    throw new AzHttpError(404,'This phone action was not found.','unknown_action');
} catch (AzHttpError $e) { az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status); }
catch (Throwable $e) { az_log_exception($e); az_json(['ok'=>false,'error'=>['code'=>'server_error','message'=>'The phone connection could not complete this request. Try again.']],500); }
