<?php
declare(strict_types=1);
/** Admin-only Command Center, reading original casting, publishing, viewing, and payment ledgers. */
function az_admin_command_assert(array $user):void
{
    if (!az_has_role($user,'admin'))throw new AzHttpError(403,'Administrator access required.','role_required');
}
function az_admin_command_counts(PDO $db,string $sql,array $args=[]):int
{
    $q=$db->prepare($sql);$q->execute($args);return (int)$q->fetchColumn();
}
/** Pure scorecards. Currency values are product-subtotal cents, not gross payment volume. */
function az_admin_command_scorecards(array $commerce,array $casting,array $submissions,array $audience,array $live):array
{
    return [
      ['key'=>'stripe','label'=>'Stripe verified net','valueCents'=>(int)($commerce['verified']['netCents']??0),
       'hint'=>'Last 30 days · signed payment events','href'=>'commerce.php?tab=analytics','tone'=>'revenue'],
      ['key'=>'cash','label'=>'Cash recorded sales','valueCents'=>(int)($commerce['cash']['netCents']??0),
       'hint'=>'Last 30 days · staff-recorded','href'=>'commerce.php?tab=local','tone'=>'revenue'],
      ['key'=>'live','label'=>'Live Stripe sales','valueCents'=>(int)($live['verifiedNetCents30d']??0),
       'hint'=>'Last 30 days · live-attributed, Stripe verified','href'=>'commerce.php?tab=analytics','tone'=>'revenue'],
      ['key'=>'applications','label'=>'New model applications','value'=>(int)($casting['new']??0),
       'hint'=>'Unreviewed casting profiles','href'=>'casting.php?status=new','tone'=>'action'],
      ['key'=>'submissions','label'=>'Video submissions','value'=>(int)($submissions['pending']??0),
       'hint'=>'Awaiting studio approval','href'=>'user-submissions.php','tone'=>'action'],
      ['key'=>'plays','label'=>'Video play starts','value'=>(int)($audience['playStarts30d']??0),
       'hint'=>'Last 30 days · not unique viewers','href'=>'videos.php','tone'=>'audience']
    ];
}
function az_admin_command_trend(array $rows,int $days=14,?DateTimeImmutable $now=null):array
{
    $days=max(7,min(30,$days));$now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $byDay=[];
    foreach($rows as $row){
        $day=(string)($row['day']??'');
        if(!preg_match('/^\d{4}-\d\d-\d\d$/D',$day))continue;
        $byDay[$day]=['profileViews'=>max(0,(int)($row['profileViews']??0)),
            'videoPlays'=>max(0,(int)($row['videoPlays']??0)),
            'liveProductImpressions'=>max(0,(int)($row['liveProductImpressions']??0))];
    }
    $trend=[];
    for($offset=$days-1;$offset>=0;$offset--){
        $day=$now->modify('-'.$offset.' days')->format('Y-m-d');
        $trend[]=['day'=>$day]+($byDay[$day]??['profileViews'=>0,'videoPlays'=>0,'liveProductImpressions'=>0]);
    }
    return $trend;
}
function az_admin_command_data(array $user):array
{
    az_admin_command_assert($user);
    $db=az_db();$now=time();$sinceDay=gmdate('Y-m-d',$now-29*86400);
    $since=gmdate('Y-m-d\TH:i:s\Z',$now-30*86400);
    $casting=['new'=>0,'contacted'=>0,'screening'=>0,'interview'=>0,'approved'=>0,'converted'=>0,'declined'=>0];
    foreach($db->query('SELECT status,COUNT(*) AS n FROM casting_leads GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $r)
        if(isset($casting[$r['status']]))$casting[$r['status']]=(int)$r['n'];
    $q=$db->prepare("SELECT id,applicant_name,stage_name,city,status,created_at
        FROM casting_leads WHERE status IN ('new','contacted','screening','interview')
        ORDER BY CASE WHEN status='new' THEN 0 ELSE 1 END,created_at DESC,id DESC LIMIT 6");
    $q->execute();$castingRecent=$q->fetchAll(PDO::FETCH_ASSOC);
    $submissions=az_model_video_dashboard_summary();
    $engagement=$db->prepare('SELECT view_day AS day,COALESCE(SUM(play_starts),0) AS plays,
        COALESCE(SUM(player_completions),0) AS completions,COALESCE(SUM(watched_seconds),0) AS seconds
        FROM published_video_engagement_daily WHERE view_day>=? GROUP BY view_day');
    $engagement->execute([$sinceDay]);$engRows=$engagement->fetchAll(PDO::FETCH_ASSOC);
    $profileQ=$db->prepare('SELECT view_day AS day,COALESCE(SUM(view_count),0) AS views
        FROM member_profile_views_daily WHERE view_day>=? GROUP BY view_day');
    $profileQ->execute([$sinceDay]);$profileRows=$profileQ->fetchAll(PDO::FETCH_ASSOC);
    $liveQ=$db->prepare('SELECT view_day AS day,COALESCE(SUM(impressions),0) AS impressions,
        COALESCE(SUM(clicks),0) AS clicks FROM commerce_live_engagement_daily
        WHERE view_day>=? GROUP BY view_day');
    $liveQ->execute([$sinceDay]);$liveRows=$liveQ->fetchAll(PDO::FETCH_ASSOC);
    $daily=[];$audience=['playStarts30d'=>0,'videoCompletions30d'=>0,'watchedMinutes30d'=>0,
        'profileViews30d'=>0,'liveProductImpressions30d'=>0,'liveProductClicks30d'=>0];
    foreach($engRows as $r){$day=$r['day'];$daily[$day]['videoPlays']=(int)$r['plays'];
        $audience['playStarts30d']+=(int)$r['plays'];$audience['videoCompletions30d']+=(int)$r['completions'];
        $audience['watchedMinutes30d']+=(int)$r['seconds'];}
    $audience['watchedMinutes30d']=intdiv($audience['watchedMinutes30d'],60);
    foreach($profileRows as $r){$daily[$r['day']]['profileViews']=(int)$r['views'];$audience['profileViews30d']+=(int)$r['views'];}
    foreach($liveRows as $r){$daily[$r['day']]['liveProductImpressions']=(int)$r['impressions'];
        $audience['liveProductImpressions30d']+=(int)$r['impressions'];$audience['liveProductClicks30d']+=(int)$r['clicks'];}
    $trendRows=[];
    foreach($daily as $day=>$metrics)$trendRows[]=['day'=>$day]+$metrics;
    $commerce=az_commerce_analytics($user);
    $byChannel=[];foreach($commerce['channels'] as $r)$byChannel[(string)$r['channel']]=$r;
    $liveNet=(int)($byChannel['live']['netCents']??0);
    $pendingOrders=az_admin_command_counts($db,"SELECT COUNT(*) FROM commerce_orders WHERE provider='manual' AND state='recorded'");
    $pendingPromotions=az_admin_command_counts($db,"SELECT COUNT(*) FROM commerce_promotions WHERE state='pending'");
    $pendingProviders=az_admin_command_counts($db,"SELECT COUNT(*) FROM commerce_provider_refunds WHERE status IN ('pending','provider_pending','needs_review')")
       +az_admin_command_counts($db,"SELECT COUNT(*) FROM commerce_return_labels WHERE status IN ('pending','needs_review')")
       +az_admin_command_counts($db,"SELECT COUNT(*) FROM commerce_connect_transfers WHERE status IN ('pending','needs_review')");
    $activeProducts=az_admin_command_counts($db,"SELECT COUNT(*) FROM commerce_products WHERE state='active'");
    $activeModels=az_admin_command_counts($db,"SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles r ON r.user_id=u.id WHERE u.status='active' AND r.role='model'");
    $activeProductions=az_admin_command_counts($db,"SELECT COUNT(*) FROM production_projects WHERE status='active'");
    $pendingPayouts=az_admin_command_counts($db,"SELECT COUNT(*) FROM commerce_payout_batches WHERE state IN ('draft','approved')");
    $room=az_live_active_room();$live=$room?az_live_shop_pulse($user,$room):['onAir'=>false];
    $actions=[];
    $push=static function(int $n,string $label,string $detail,string $href,int $priority)use(&$actions):void{
        if($n>0)$actions[]=['count'=>$n,'title'=>$label,'detail'=>$detail,'href'=>$href,'priority'=>$priority];
    };
    $push($pendingProviders,'Provider reconciliation','Investigate original financial operations before any repeat request.','commerce.php?tab=providers',0);
    $push($casting['new'],'New model applications','Read screening responses and assign the next step.','casting.php?status=new',1);
    $push((int)$submissions['pending'],'Video submissions','Approve, request changes or reject submitted creator videos.','user-submissions.php',1);
    $push($pendingOrders,'Unverified external orders','Confirm manual orders using independent seller evidence.','commerce.php?tab=orders',1);
    $push($pendingPayouts,'Creator settlements','Review payout batches; a Connect transfer is not a confirmed bank deposit.','commerce.php?tab=payouts',2);
    $push($pendingPromotions,'Creator product approvals','Review eligible approved video/product pairings.','commerce.php?tab=promotions',2);
    usort($actions,static fn($a,$b)=>$a['priority']<=>$b['priority']?:$b['count']<=>$a['count']);
    return ['generatedAt'=>az_now(),'casting'=>$casting,'castingRecent'=>$castingRecent,
        'submissions'=>$submissions,'commerce'=>$commerce,'audience'=>$audience,
        'trend'=>az_admin_command_trend($trendRows,14),'activeProducts'=>$activeProducts,
        'activeModels'=>$activeModels,'activeProductions'=>$activeProductions,
        'pendingPayouts'=>$pendingPayouts,'pendingOrders'=>$pendingOrders,
        'pendingProviders'=>$pendingProviders,'live'=>$live,
        'liveNet30d'=>$liveNet,'actions'=>$actions,
        'scorecards'=>az_admin_command_scorecards($commerce,$casting,$submissions,$audience,['verifiedNetCents30d'=>$liveNet]),
        'trackingBasis'=>'Profile page views, video play starts/completions, and live product impressions are session-limited events, not unique visitors. Stripe results reflect signed provider payments; cash and manual orders remain distinct.'];
}
function az_admin_command_money(int $cents):string{return '$'.number_format($cents/100,2);}
function az_admin_command_date(string $utc):string
{
    try {return (new DateTimeImmutable($utc))->setTimezone(new DateTimeZone('America/Phoenix'))->format('M j · g:i a');}
    catch(Throwable){return 'Date unavailable';}
}
