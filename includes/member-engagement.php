<?php
declare(strict_types=1);
/** Aggregate playback counters only. No IPs, cookies, user IDs, devices or
 * raw viewing sessions are written to the engagement table. Session-local
 * state prevents duplicate plays and excessive progress increments.
 */
function az_upgrade_member_engagement_schema(PDO $db): void
{
    if (az_schema_version($db) >= 28 || az_schema_version($db) < 27) return;
    az_prepare_storage();
    $lock = fopen(AZ_STORAGE . '/schema-migration.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Could not lock engagement analytics migration.');
    try {
        if (az_schema_version($db) >= 28) return;
        $suffix = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $db->exec("CREATE TABLE IF NOT EXISTS published_video_engagement_daily (
            video_kind VARCHAR(12) NOT NULL, video_id CHAR(32) NOT NULL, view_day CHAR(10) NOT NULL,
            play_starts BIGINT NOT NULL DEFAULT 0, player_completions BIGINT NOT NULL DEFAULT 0,
            watched_seconds BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (video_kind,video_id,view_day))$suffix");
        $db->exec('UPDATE studio_schema SET version=28 WHERE id=1');
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

/** Validates public eligibility on *every* event. Previously published but now
 * private/withdrawn videos cannot produce fresh analytics entries. */
function az_member_watch_public_source(string $kind, string $id): array
{
    if (!az_valid_id($id) || !in_array($kind, ['creator','production'], true))
        throw new AzHttpError(404, 'This video is unavailable.', 'not_found');
    if ($kind === 'creator') {
        $row = az_model_video_public_row($id);
        return ['owner_id'=>(int)$row['user_id'], 'credited_user_id'=>(int)$row['user_id'], 'duration_ms'=>(int)$row['duration_ms']];
    }
    $row = az_public_video_row($id);
    $credited = 0;
    if (!empty($row['model_id'])) {
        $q = az_db()->prepare("SELECT linked_user_id FROM model_profiles WHERE id=? AND status='active'");
        $q->execute([$row['model_id']]);
        $credited = (int)($q->fetchColumn() ?: 0);
    }
    return ['owner_id'=>(int)$row['owner_id'], 'credited_user_id'=>$credited, 'duration_ms'=>(int)$row['duration_ms']];
}

/** Records browser-play events with 30-minute session deduplication. The
 * application must check CSRF and POST before calling this function. */
function az_member_watch_event(string $kind, string $id, string $event, int $seconds, ?array $viewer): bool
{
    if (!in_array($event, ['start','progress','complete'], true) || $seconds < 0 || $seconds > 30)
        throw new AzHttpError(400, 'Invalid playback event.', 'invalid_event');
    $source = az_member_watch_public_source($kind, $id);
    if ($viewer && (az_has_role($viewer,'admin') || (int)$viewer['id'] === $source['owner_id'] ||
        (int)$viewer['id'] === $source['credited_user_id'])) return false;
    if (preg_match('/bot|crawler|spider|slurp|preview|headless|lighthouse/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''))) return false;
    if (!empty($_SERVER['HTTP_PURPOSE']) || !empty($_SERVER['HTTP_SEC_PURPOSE'])) return false;
    if (!isset($_SESSION) || !is_array($_SESSION)) return false;

    $key = $kind . ':' . $id; $now = time(); $today = gmdate('Y-m-d', $now);
    $all = $_SESSION['azh_watch_engagement'] ?? [];
    if (!is_array($all)) $all = [];
    foreach ($all as $other => $entry) {
        if (!is_array($entry) || $now - (int)($entry['startedAt'] ?? 0) > 5400) unset($all[$other]);
    }
    $state = $all[$key] ?? null;
    $expired = !is_array($state) || ($now - (int)($state['startedAt'] ?? 0) >= 1800);
    if ($event === 'start') {
        if ($expired) {
            $state = ['startedAt'=>$now,'lastAt'=>$now,'watched'=>0,'completed'=>false];
            az_member_watch_increment($kind,$id,$today,1,0,0);
        }
    } else {
        if ($expired) { $_SESSION['azh_watch_engagement']=$all; return false; }
        if ($event === 'progress') {
            // Server wall-clock upper bound prevents counting time repeatedly on
            // seeks or from rapid client requests; no watch-history records kept.
            $elapsed = max(0, $now - (int)($state['lastAt'] ?? $now));
            $credited = min($seconds, $elapsed, 30);
            $state['lastAt'] = $now;
            if ($credited > 0) {
                $state['watched'] = (int)$state['watched'] + $credited;
                az_member_watch_increment($kind,$id,$today,0,0,$credited);
            }
        } elseif (!$state['completed'] && ((int)$state['watched'] >= 2 || $source['duration_ms'] <= 2000)) {
            az_member_watch_increment($kind,$id,$today,0,1,0);
            $state['completed'] = true;
        }
    }
    $all[$key]=$state;
    if (count($all)>64) $all=array_slice($all,-64,null,true);
    $_SESSION['azh_watch_engagement']=$all;
    return true;
}

function az_member_watch_increment(string $kind, string $id, string $day, int $starts, int $completions, int $seconds): void
{
    $db = az_db();
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') {
        $sql='INSERT INTO published_video_engagement_daily(video_kind,video_id,view_day,play_starts,player_completions,watched_seconds) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE play_starts=play_starts+VALUES(play_starts),player_completions=player_completions+VALUES(player_completions),watched_seconds=watched_seconds+VALUES(watched_seconds)';
    } else {
        $sql='INSERT INTO published_video_engagement_daily(video_kind,video_id,view_day,play_starts,player_completions,watched_seconds) VALUES(?,?,?,?,?,?) ON CONFLICT(video_kind,video_id,view_day) DO UPDATE SET play_starts=play_starts+excluded.play_starts,player_completions=player_completions+excluded.player_completions,watched_seconds=watched_seconds+excluded.watched_seconds';
    }
    $db->prepare($sql)->execute([$kind,$id,$day,$starts,$completions,$seconds]);
}

/** All queries filter against currently public videos and the linked model's
 * account. A published video from somebody else's account cannot leak into
 * the signed-in user's chart. */
function az_member_engagement_summary(int $userId, int $days = 14): array
{
    $days = max(7,min(30,$days));
    $since = gmdate('Y-m-d', strtotime('-59 days'));
    $views = az_dash_rows('SELECT view_day,view_count FROM member_profile_views_daily WHERE user_id=? AND view_day>=?',[$userId,$since]);
    $creators = az_dash_rows("SELECT d.view_day,d.play_starts,d.player_completions,d.watched_seconds
        FROM published_video_engagement_daily d
        JOIN model_videos p ON p.id=d.video_id AND d.video_kind='creator'
        JOIN users u ON u.id=p.user_id
        WHERE p.user_id=? AND d.view_day>=? AND p.status='published' AND p.published_at IS NOT NULL
          AND p.rights_confirmed=1 AND u.status='active' AND EXISTS(
            SELECT 1 FROM user_roles ur WHERE ur.user_id=p.user_id AND ur.role='model')",[$userId,$since]);
    $interviews = az_dash_rows("SELECT d.view_day,d.play_starts,d.player_completions,d.watched_seconds
        FROM published_video_engagement_daily d
        JOIN finished_videos v ON v.id=d.video_id AND d.video_kind='production'
        JOIN model_profiles mp ON mp.id=v.model_id
        JOIN interviews i ON i.id=v.interview_id
        JOIN users u ON u.id=v.owner_id
        WHERE mp.linked_user_id=? AND mp.status='active' AND d.view_day>=?
          AND ".az_public_video_conditions(),[$userId,$since]);
    $calendar=[];
    foreach ($views as $row) $calendar[$row['view_day']]['profileViews']=(int)$row['view_count'];
    foreach ([$creators,$interviews] as $rows) foreach($rows as $row) {
        $day=$row['view_day'];
        foreach (['play_starts'=>'starts','player_completions'=>'completions','watched_seconds'=>'seconds'] as $column=>$metric)
            $calendar[$day][$metric]=($calendar[$day][$metric]??0)+(int)$row[$column];
    }
    $trend=[];$current=['profileViews'=>0,'starts'=>0,'completions'=>0,'seconds'=>0];$previous=$current;
    for ($offset=59; $offset>=0; $offset--) {
        $day=gmdate('Y-m-d',strtotime('-'.$offset.' days'));
        $row=['day'=>$day,'profileViews'=>0,'starts'=>0,'completions'=>0,'seconds'=>0];
        foreach ($current as $key=>$unused) $row[$key]=$calendar[$day][$key]??0;
        $group=$offset<30?'current':'previous';
        foreach($current as $key=>$unused) ${$group}[$key]+=$row[$key];
        if ($offset<$days) $trend[]=$row;
    }
    return ['days'=>$days,'trend'=>$trend,'current'=>$current,'previous'=>$previous,
        'totalPlayStarts'=>$current['starts'],'totalCompletions'=>$current['completions'],
        'watchMinutes'=>(int)floor($current['seconds']/60)];
}
