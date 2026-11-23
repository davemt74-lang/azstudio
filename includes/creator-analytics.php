<?php
declare(strict_types=1);
/**
 * v1.50.0: role-scoped creator/video reporting from already recorded activity.
 * Identifiers in the click ledger describe approved videos/products, never viewers.
 * A click, playback start, Checkout attempt and signed paid order are separate events.
 */
function az_upgrade_creator_analytics_schema(PDO $db): void
{
    if (az_schema_version($db) >= 48 || az_schema_version($db) < 47) return;
    az_prepare_storage();
    $lock = fopen(AZ_STORAGE . '/schema-migration.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Could not lock creator analytics migration.');
    try {
        if (az_schema_version($db) >= 48) return;
        $suffix = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_video_engagement_daily (
            video_id CHAR(32) NOT NULL, product_id CHAR(32) NOT NULL,
            promotion_id CHAR(32) NOT NULL, view_day CHAR(10) NOT NULL,
            clicks BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY(video_id,product_id,promotion_id,view_day))$suffix");
        $db->exec('UPDATE studio_schema SET version=48 WHERE id=1');
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function az_creator_analytics_assert(array $user, ?int $modelId = null): ?int
{
    if (($user['status'] ?? '') !== 'active' ||
        (!az_has_role($user, 'admin') && !az_has_role($user, 'model')))
        throw new AzHttpError(403, 'Analytics requires an active creator or administrator account.', 'role_required');
    if ($modelId !== null && $modelId < 1)
        throw new AzHttpError(400, 'Invalid creator filter.', 'invalid_creator');
    return az_has_role($user, 'admin') ? $modelId : (int)$user['id'];
}

/** A server-verified public promotion, one click per 30-minute browser session. */
function az_creator_video_click(?array $viewer, string $videoId, string $productId, string $promotionId): bool
{
    if (!az_valid_id($videoId) || !az_valid_id($productId) || !az_valid_id($promotionId))
        throw new AzHttpError(404, 'Product not available.', 'not_found');
    if (!isset($_SESSION) || !is_array($_SESSION)) return false;
    if (preg_match('/bot|crawler|spider|slurp|preview|headless|lighthouse/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''))
        || !empty($_SERVER['HTTP_PURPOSE']) || !empty($_SERVER['HTTP_SEC_PURPOSE'])) return false;
    $video = az_model_video_public_row($videoId);
    if ($viewer && (az_has_role($viewer, 'admin') || (int)$viewer['id'] === (int)$video['user_id'])) return false;
    // Never trust a forged browser promotion: require the actual current public product CTA.
    $valid = false;
    foreach (az_commerce_video_products($videoId) as $product) {
        if ($product['id'] === $productId && $product['promotionId'] === $promotionId) {
            $valid = true;
            break;
        }
    }
    if (!$valid) throw new AzHttpError(404, 'Product not available.', 'not_found');
    $now = time();
    $key = $videoId . ':' . $productId . ':' . $promotionId;
    $all = $_SESSION['azh_video_product_clicks'] ?? [];
    if (!is_array($all)) $all = [];
    foreach ($all as $k => $timestamp) {
        if (!is_int($timestamp) || $now - $timestamp >= 1800) unset($all[$k]);
    }
    if (isset($all[$key])) { $_SESSION['azh_video_product_clicks'] = $all; return false; }
    $db = az_db();
    $values = [$videoId, $productId, $promotionId, gmdate('Y-m-d', $now)];
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $sql = 'INSERT INTO commerce_video_engagement_daily (video_id,product_id,promotion_id,view_day,clicks)
            VALUES(?,?,?,?,1) ON DUPLICATE KEY UPDATE clicks=clicks+1';
    } else {
        $sql = 'INSERT INTO commerce_video_engagement_daily (video_id,product_id,promotion_id,view_day,clicks)
            VALUES(?,?,?,?,1) ON CONFLICT(video_id,product_id,promotion_id,view_day)
            DO UPDATE SET clicks=clicks+1';
    }
    $db->prepare($sql)->execute($values);
    $all[$key] = $now;
    if (count($all) > 80) $all = array_slice($all, -80, null, true);
    $_SESSION['azh_video_product_clicks'] = $all;
    return true;
}

function az_creator_analytics_fields(): array
{
    return ['starts' => 0, 'completions' => 0, 'watchedSeconds' => 0,
        'clicks' => 0, 'checkoutStarts' => 0, 'verifiedOrders' => 0,
        'verifiedNetCents' => 0, 'cashOrders' => 0, 'cashNetCents' => 0,
        'commissionCents' => 0];
}

/** Return only current public video titles; withdrawn content is not exposed by reports. */
function az_creator_analytics_report(array $user, int $days = 30, string $kind = 'all', ?int $modelId = null,
    ?PDO $db = null, ?DateTimeImmutable $now = null): array
{
    $scope = az_creator_analytics_assert($user, $modelId);
    if (!in_array($days, [7, 30, 90], true) || !in_array($kind, ['all', 'creator', 'production'], true))
        throw new AzHttpError(400, 'Invalid report filters.', 'invalid_report_filter');
    $db ??= az_db();
    $today = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
    $start = $today->modify('-' . ($days - 1) . ' days');
    $earlier = $start->modify('-' . $days . ' days');
    $end = $today->modify('+1 day');
    $startDay = $start->format('Y-m-d');
    $earlierDay = $earlier->format('Y-m-d');
    $endDay = $end->format('Y-m-d');
    $startUtc = $start->format('Y-m-d\TH:i:s\Z');
    $earlierUtc = $earlier->format('Y-m-d\TH:i:s\Z');
    $endUtc = $end->format('Y-m-d\TH:i:s\Z');
    $allowed = [];
    if ($kind !== 'production') {
        $sql = "SELECT p.id,p.title,p.user_id AS creator_id,u.name AS creator_name,p.published_at AS published_at
            FROM model_videos p JOIN users u ON u.id=p.user_id AND u.status='active'
            WHERE p.status='published' AND p.published_at IS NOT NULL AND p.rights_confirmed=1
            AND EXISTS(SELECT 1 FROM user_roles ur WHERE ur.user_id=p.user_id AND ur.role='model')";
        $args = [];
        if ($scope !== null) { $sql .= ' AND p.user_id=?'; $args[] = $scope; }
        $q = $db->prepare($sql);
        $q->execute($args);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = 'creator:' . $row['id'];
            $allowed[$key] = ['kind'=>'creator', 'id'=>$row['id'], 'title'=>$row['title'],
                'creatorId'=>(int)$row['creator_id'], 'creatorName'=>$row['creator_name'],
                'publishedAt'=>$row['published_at'], 'watchUrl'=>'watch.php?id='.$row['id']]
                + az_creator_analytics_fields();
        }
    }
    if ($kind !== 'creator') {
        $sql = "SELECT v.id,v.fan_title AS title,v.fan_published_at AS published_at,
            COALESCE(mp.linked_user_id,0) AS creator_id,COALESCE(cu.name,'Uncredited production') AS creator_name
            FROM finished_videos v JOIN interviews i ON i.id=v.interview_id
            JOIN users u ON u.id=v.owner_id
            LEFT JOIN model_profiles mp ON mp.id=v.model_id AND mp.status='active'
            LEFT JOIN users cu ON cu.id=mp.linked_user_id AND cu.status='active'
            WHERE " . az_public_video_conditions();
        $args = [];
        if ($scope !== null) { $sql .= " AND mp.linked_user_id=? AND mp.status='active' AND cu.status='active'"; $args[] = $scope; }
        $q = $db->prepare($sql);
        $q->execute($args);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = 'production:' . $row['id'];
            $allowed[$key] = ['kind'=>'production', 'id'=>$row['id'], 'title'=>$row['title'],
                'creatorId'=>(int)$row['creator_id'], 'creatorName'=>$row['creator_name'],
                'publishedAt'=>$row['published_at'], 'watchUrl'=>'watch.php?id='.$row['id']]
                + az_creator_analytics_fields();
        }
    }
    $current = az_creator_analytics_fields();
    $previous = az_creator_analytics_fields();
    $daily = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $day = $today->modify('-'.$i.' days')->format('Y-m-d');
        $daily[$day] = ['day'=>$day] + az_creator_analytics_fields();
    }
    // Contribute only to the verified visible set. Existing private/withdrawn footage
    // cannot leak titles through cached aggregate engagement rows.
    $q = $db->prepare("SELECT video_kind,video_id,view_day,play_starts,player_completions,watched_seconds
        FROM published_video_engagement_daily WHERE view_day>=? AND view_day<?");
    $q->execute([$earlierDay, $endDay]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = $row['video_kind'] . ':' . $row['video_id'];
        if (!isset($allowed[$key])) continue;
        $day = $row['view_day'];
        $bucket = $day >= $startDay ? 'current' : 'previous';
        foreach (['play_starts'=>'starts','player_completions'=>'completions','watched_seconds'=>'watchedSeconds'] as $column=>$field) {
            $value = max(0,(int)$row[$column]);
            ${$bucket}[$field] += $value;
            if ($bucket === 'current') { $allowed[$key][$field] += $value; $daily[$day][$field] += $value; }
        }
    }
    if ($kind !== 'production') {
        $q = $db->prepare('SELECT video_id,view_day,SUM(clicks) AS clicks FROM commerce_video_engagement_daily
            WHERE view_day>=? AND view_day<? GROUP BY video_id,view_day');
        $q->execute([$earlierDay, $endDay]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = 'creator:' . $row['video_id'];
            if (!isset($allowed[$key])) continue;
            $value = max(0,(int)$row['clicks']);
            $day = $row['view_day'];
            $bucket = $day >= $startDay ? 'current' : 'previous';
            ${$bucket}['clicks'] += $value;
            if ($bucket === 'current') { $allowed[$key]['clicks'] += $value; $daily[$day]['clicks'] += $value; }
        }
        // These are created Stripe Checkout sessions, not page impressions or purchases.
        $q = $db->prepare("SELECT a.source_ref,a.created_at FROM commerce_checkout_attempts a
            JOIN commerce_promotions p ON p.id=a.promotion_id AND p.video_id=a.source_ref
            WHERE a.channel='video' AND a.session_id IS NOT NULL AND a.created_at>=? AND a.created_at<?");
        $q->execute([$earlierUtc, $endUtc]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = 'creator:' . $row['source_ref'];
            if (!isset($allowed[$key])) continue;
            $day = substr((string)$row['created_at'],0,10);
            $bucket = $day >= $startDay ? 'current' : 'previous';
            ${$bucket}['checkoutStarts']++;
            if ($bucket === 'current') { $allowed[$key]['checkoutStarts']++; $daily[$day]['checkoutStarts']++; }
        }
        // An order is Stripe-verified only if it came from the signed provider path.
        // The Checkout attempt's channel and original source must both match the promotion.
        $q = $db->prepare("SELECT p.video_id,o.amount_cents,o.refunded_cents,o.commission_cents,o.state,o.paid_at
            FROM commerce_orders o
            JOIN commerce_checkout_attempts a ON a.id=SUBSTR(o.external_reference,8)
                AND a.channel='video' AND a.promotion_id=o.promotion_id
            JOIN commerce_promotions p ON p.id=a.promotion_id AND p.video_id=a.source_ref
            WHERE o.provider='stripe' AND o.paid_at>=? AND o.paid_at<?
                AND o.state IN ('paid','partially_refunded','refunded','disputed','reversed')");
        $q->execute([$earlierUtc, $endUtc]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = 'creator:' . $row['video_id'];
            if (!isset($allowed[$key])) continue;
            $net = in_array($row['state'],['refunded','disputed','reversed'],true) ? 0
                : max(0,(int)$row['amount_cents']-(int)$row['refunded_cents']);
            $orders = in_array($row['state'],['paid','partially_refunded'],true) ? 1 : 0;
            $commission = $orders ? max(0,(int)$row['commission_cents']) : 0;
            $day = substr((string)$row['paid_at'],0,10);
            $bucket = $day >= $startDay ? 'current' : 'previous';
            foreach (['verifiedOrders'=>$orders,'verifiedNetCents'=>$net,'commissionCents'=>$commission] as $field=>$value) {
                ${$bucket}[$field] += $value;
                if ($bucket==='current') { $allowed[$key][$field]+=$value; $daily[$day][$field]+=$value; }
            }
        }
        $q = $db->prepare("SELECT p.video_id,o.amount_cents,o.refunded_cents,o.state,o.paid_at
            FROM commerce_orders o JOIN commerce_local_orders l ON l.order_id=o.id AND l.channel='video'
            JOIN commerce_promotions p ON p.id=o.promotion_id AND p.video_id=l.source_ref
            WHERE o.provider='cash' AND o.paid_at>=? AND o.paid_at<?
                AND o.state IN ('paid','partially_refunded','refunded')");
        $q->execute([$earlierUtc,$endUtc]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key='creator:'.$row['video_id'];
            if (!isset($allowed[$key])) continue;
            $net=$row['state']==='refunded'?0:max(0,(int)$row['amount_cents']-(int)$row['refunded_cents']);
            $orders=$row['state']==='refunded'?0:1;
            $day=substr((string)$row['paid_at'],0,10);
            $bucket=$day>=$startDay?'current':'previous';
            foreach(['cashOrders'=>$orders,'cashNetCents'=>$net] as $field=>$value){
                ${$bucket}[$field]+=$value;
                if($bucket==='current'){$allowed[$key][$field]+=$value;$daily[$day][$field]+=$value;}
            }
        }
    }
    // Current-state snapshot, not an immutable history of every review decision.
    $review = ['submitted'=>0,'pending'=>0,'reviewed'=>0,'approved'=>0,'changesRequested'=>0,
        'rejected'=>0,'avgReviewHours'=>null];
    $sql = 'SELECT status,submitted_at,reviewed_at FROM model_videos WHERE
        (submitted_at>=? AND submitted_at<?) OR (reviewed_at>=? AND reviewed_at<?)';
    $params=[$startUtc,$endUtc,$startUtc,$endUtc];
    if ($scope !== null) {
        $sql='SELECT status,submitted_at,reviewed_at FROM model_videos WHERE user_id=? AND
            ((submitted_at>=? AND submitted_at<?) OR (reviewed_at>=? AND reviewed_at<?))';
        array_unshift($params,$scope);
    }
    $q=$db->prepare($sql);$q->execute($params);
    $reviewSeconds=0;$reviewTimes=0;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        $submitted=(string)($row['submitted_at']??'');
        $reviewed=(string)($row['reviewed_at']??'');
        if($submitted>=$startUtc&&$submitted<$endUtc){
            $review['submitted']++;
            if($row['status']==='pending_review')$review['pending']++;
        }
        if($reviewed<$startUtc||$reviewed>=$endUtc||!in_array($row['status'],['published','scheduled','changes_requested','rejected'],true))continue;
        $review['reviewed']++;
        if(in_array($row['status'],['published','scheduled'],true))$review['approved']++;
        elseif($row['status']==='changes_requested')$review['changesRequested']++;
        else $review['rejected']++;
        $elapsed=strtotime($reviewed)-strtotime($submitted);
        if($submitted!==''&&$elapsed>=0&&$elapsed<=365*86400){$reviewSeconds+=$elapsed;$reviewTimes++;}
    }
    if($reviewTimes)$review['avgReviewHours']=round($reviewSeconds/$reviewTimes/3600,1);
    $creators=[];
    foreach($allowed as $video){
        $id=$video['creatorId'];
        if(!isset($creators[$id]))$creators[$id]=['id'=>$id,'name'=>$video['creatorName'],'videos'=>0]+az_creator_analytics_fields();
        $creators[$id]['videos']++;
        foreach(az_creator_analytics_fields() as $metric=>$unused)$creators[$id][$metric]+=$video[$metric];
    }
    $videos=array_values($allowed);
    usort($videos,static fn($a,$b)=>($b['starts']<=>$a['starts']) ?: ($b['verifiedNetCents']<=>$a['verifiedNetCents']) ?: strcmp((string)$a['title'],(string)$b['title']));
    $creators=array_values($creators);
    usort($creators,static fn($a,$b)=>($b['starts']<=>$a['starts']) ?: ($b['verifiedNetCents']<=>$a['verifiedNetCents']) ?: strcmp((string)$a['name'],(string)$b['name']));
    return ['days'=>$days,'kind'=>$kind,'scopeModelId'=>$scope,'admin'=>az_has_role($user,'admin'),
        'startDay'=>$startDay,'endDay'=>$today->format('Y-m-d'),'current'=>$current,'previous'=>$previous,
        'completionRate'=>$current['starts']?round(100*$current['completions']/$current['starts'],1):null,
        'trend'=>array_values($daily),'videos'=>$videos,'creators'=>$creators,'reviews'=>$review,
        'basis'=>'Public-video playback starts/completions and approximate watched seconds are session-limited; not unique viewers or video-page impressions. Product CTA taps are session-deduplicated and begin with v1.50. Checkout starts are Stripe sessions, not taps or purchases. Verified net is based on signed Stripe paid orders less refunds; staff-recorded cash is separate. Creator commissions are estimates, not transfers. Withdrawn/private videos are excluded, and review outcomes are current-state snapshots rather than an immutable decision history.'];
}

/** Explicit formula neutralization for spreadsheet applications opening exported CSV. */
function az_creator_analytics_csv_safe(string $value): string
{
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);
    return preg_match('/^\s*[=+\-@]/u', $value) ? "'".$value : $value;
}
function az_creator_analytics_csv(array $report): string
{
    $stream=fopen('php://temp','w+');
    if (!$stream) throw new RuntimeException('Cannot create analytics CSV.');
    $write=static function(array $row)use($stream):void{
        fputcsv($stream,array_map(static fn($v)=>is_string($v)?az_creator_analytics_csv_safe($v):$v,$row));
    };
    $write(['AZHottie creator analytics','Period (days)',$report['days'],'From',$report['startDay'],'Through',$report['endDay']]);
    $write(['Basis',$report['basis']]);
    $write([]);
    $write(['Video kind','Video ID','Title','Creator','Play starts','Completions','Watched minutes','Product CTA taps','Stripe checkout sessions','Stripe verified orders','Stripe verified net USD','Staff-recorded cash orders','Staff-recorded cash net USD','Estimated Stripe commission USD']);
    foreach($report['videos'] as $video)$write([$video['kind'],$video['id'],$video['title'],$video['creatorName'],
        $video['starts'],$video['completions'],round($video['watchedSeconds']/60,1),$video['clicks'],
        $video['checkoutStarts'],$video['verifiedOrders'],number_format($video['verifiedNetCents']/100,2,'.',''),
        $video['cashOrders'],number_format($video['cashNetCents']/100,2,'.',''),
        number_format($video['commissionCents']/100,2,'.','')]);
    $write([]);
    $write(['Creator','Published videos','Play starts','Completions','Watched minutes','Product CTA taps','Stripe checkouts','Stripe verified net USD']);
    foreach($report['creators'] as $creator)$write([$creator['name'],$creator['videos'],$creator['starts'],
        $creator['completions'],round($creator['watchedSeconds']/60,1),$creator['clicks'],
        $creator['checkoutStarts'],number_format($creator['verifiedNetCents']/100,2,'.','')]);
    rewind($stream);$csv=stream_get_contents($stream);fclose($stream);
    return (string)$csv;
}
