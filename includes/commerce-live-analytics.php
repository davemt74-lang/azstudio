<?php
declare(strict_types=1);
/**
 * Live product engagement uses aggregate daily counts only. No visitor identifiers,
 * IPs, browser fingerprints or browsing histories are persisted.
 */
function az_upgrade_commerce_live_analytics_schema(PDO $db): void
{
    if (az_schema_version($db)>=44 || az_schema_version($db)<43) return;
    az_prepare_storage();
    $lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Live commerce analytics migration lock unavailable.');
    try {
        if (az_schema_version($db)>=44) return;
        $suffix=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
            ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_live_engagement_daily (
            room_id CHAR(32) NOT NULL, product_id CHAR(32) NOT NULL,
            promotion_id CHAR(32) NOT NULL DEFAULT '', view_day CHAR(10) NOT NULL,
            impressions BIGINT NOT NULL DEFAULT 0, clicks BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (room_id,product_id,promotion_id,view_day))$suffix");
        $db->exec('UPDATE studio_schema SET version=44 WHERE id=1');
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}
/** Business-event capture: only the server-side active spotlight controls attribution. */
function az_live_shop_track(array $viewer,string $event): array
{
    if (!in_array($event,['view','click'],true)) throw new AzHttpError(400,'Unknown live shopping event.','invalid_event');
    if (!isset($_SESSION) || !is_array($_SESSION)) return ['tracked'=>false];
    if (az_has_role($viewer,'admin')) return ['tracked'=>false];
    if (preg_match('/bot|crawler|spider|slurp|preview|headless|lighthouse/i',(string)($_SERVER['HTTP_USER_AGENT']??''))) return ['tracked'=>false];
    if (!empty($_SERVER['HTTP_PURPOSE']) || !empty($_SERVER['HTTP_SEC_PURPOSE'])) return ['tracked'=>false];
    $feature=az_commerce_live_feature();
    if (!$feature) return ['tracked'=>false];
    $now=time();$key=$feature['roomId'].'|'.$feature['id'].'|'.($feature['promotionId']??'');
    $all=$_SESSION['azh_live_shop_windows']??[];
    if(!is_array($all)) $all=[];
    foreach($all as $k=>$state) if(!is_array($state)||$now-(int)($state['time']??0)>3600)unset($all[$k]);
    $current=$all[$key]??null;
    if (!is_array($current)||$now-(int)($current['time']??0)>=1800) $current=['time'=>$now,'view'=>false,'click'=>false];
    if ($current[$event]) return ['tracked'=>false];
    az_live_shop_increment(az_db(),$feature,gmdate('Y-m-d',$now),$event);
    $current[$event]=true;$all[$key]=$current;
    if(count($all)>80)$all=array_slice($all,-80,null,true);
    $_SESSION['azh_live_shop_windows']=$all;
    return ['tracked'=>true];
}
function az_live_shop_increment(PDO $db,array $feature,string $day,string $event): void
{
    if (!in_array($event,['view','click'],true))throw new InvalidArgumentException('Invalid analytics event');
    $views=$event==='view'?1:0;$clicks=$event==='click'?1:0;
    $values=[$feature['roomId'],$feature['id'],$feature['promotionId']??'',$day,$views,$clicks];
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        $sql='INSERT INTO commerce_live_engagement_daily(room_id,product_id,promotion_id,view_day,impressions,clicks) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE impressions=impressions+VALUES(impressions),clicks=clicks+VALUES(clicks)';
    }else{
        $sql='INSERT INTO commerce_live_engagement_daily(room_id,product_id,promotion_id,view_day,impressions,clicks) VALUES(?,?,?,?,?,?) ON CONFLICT(room_id,product_id,promotion_id,view_day) DO UPDATE SET impressions=impressions+excluded.impressions,clicks=clicks+excluded.clicks';
    }
    $db->prepare($sql)->execute($values);
}
/** Counts track session-bounded impressions / CTA taps, not unique shoppers. */
function az_live_shop_pulse(array $user,?array $room=null): array
{
    az_commerce_admin($user);
    $room??=az_live_active_room();
    if(!$room)return ['onAir'=>false,'roomId'=>null,'title'=>null,'featuredProduct'=>null,'featuredCreator'=>null,
        'impressions'=>0,'clicks'=>0,'checkoutStarts'=>0,'paidCheckoutSessions'=>0,'verifiedNetCents'=>0,
        'cashRecordedNetCents'=>0,'verifiedCommissionCents'=>0,'basis'=>'No live broadcast currently on air.'];
    $db=az_db();$rid=$room['id'];
    $q=$db->prepare('SELECT COALESCE(SUM(impressions),0) AS impressions,COALESCE(SUM(clicks),0) AS clicks FROM commerce_live_engagement_daily WHERE room_id=?');
    $q->execute([$rid]);$counts=$q->fetch(PDO::FETCH_ASSOC);
    $q=$db->prepare("SELECT COUNT(*) AS started,COALESCE(SUM(CASE WHEN state='paid' THEN 1 ELSE 0 END),0) AS paid
        FROM commerce_checkout_attempts WHERE channel='live' AND source_ref=? AND session_id IS NOT NULL");
    $q->execute([$rid]);$checkout=$q->fetch(PDO::FETCH_ASSOC);
    $join=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
        ? "o.external_reference=CONCAT('stripe:',a.id)"
        : "o.external_reference='stripe:'||a.id";
    $q=$db->prepare("SELECT o.amount_cents,o.refunded_cents,o.commission_cents,o.state
        FROM commerce_orders o JOIN commerce_checkout_attempts a ON $join
        WHERE a.channel='live' AND a.source_ref=? AND o.provider='stripe'");
    $q->execute([$rid]);$verifiedNet=0;$verifiedCommission=0;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $o){
        if(!in_array($o['state'],['paid','partially_refunded'],true))continue;
        $verifiedNet+=max(0,(int)$o['amount_cents']-(int)$o['refunded_cents']);
        $verifiedCommission+=(int)$o['commission_cents'];
    }
    $q=$db->prepare("SELECT o.amount_cents,o.refunded_cents,o.state
        FROM commerce_orders o JOIN commerce_local_orders l ON l.order_id=o.id
        WHERE l.channel='live' AND l.source_ref=? AND o.provider='cash'");
    $q->execute([$rid]);$cashNet=0;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $o)if($o['state']==='paid'||$o['state']==='partially_refunded')
        $cashNet+=max(0,(int)$o['amount_cents']-(int)$o['refunded_cents']);
    $q=$db->prepare("SELECT p.title,u.name AS creator_name FROM commerce_live_features f
        JOIN commerce_products p ON p.id=f.product_id
        LEFT JOIN commerce_promotions pr ON pr.id=f.promotion_id AND pr.state='approved'
        LEFT JOIN users u ON u.id=pr.model_id WHERE f.room_id=?");
    $q->execute([$rid]);$featured=$q->fetch(PDO::FETCH_ASSOC)?:[];
    return ['onAir'=>true,'roomId'=>$rid,'title'=>$room['title'],
        'featuredProduct'=>$featured['title']??null,'featuredCreator'=>$featured['creator_name']??null,
        'impressions'=>(int)($counts['impressions']??0),'clicks'=>(int)($counts['clicks']??0),
        'checkoutStarts'=>(int)($checkout['started']??0),'paidCheckoutSessions'=>(int)($checkout['paid']??0),
        'verifiedNetCents'=>$verifiedNet,'cashRecordedNetCents'=>$cashNet,
        'verifiedCommissionCents'=>$verifiedCommission,
        'basis'=>'Live spotlight views and taps are deduplicated per browser session for 30 minutes, not unique visitors. Stripe net sales use signed provider order status. Cash sales are staff-recorded. Creator commission is estimated, not paid.'];
}
