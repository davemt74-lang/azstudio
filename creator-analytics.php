<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/universal-header.php';
require_once __DIR__ . '/includes/member-layout.php';
$user=az_require_auth();
$admin=az_has_role($user,'admin');
$daysParam=(string)($_GET['days']??'30');
$kind=(string)($_GET['kind']??'all');
$days=in_array($daysParam,['7','30','90'],true)?(int)$daysParam:0;
$modelParam=(string)($_GET['model']??'');
if($modelParam!=='' && !preg_match('/^[1-9][0-9]{0,12}$/D',$modelParam)) {
    http_response_code(400);
    header('Cache-Control: private, no-store');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid creator selection.';
    exit;
}
$modelId=$modelParam!==''?(int)$modelParam:null;
try { $report=az_creator_analytics_report($user,$days,$kind,$modelId); }
catch(AzHttpError $e){
    http_response_code($e->status);header('Cache-Control: private, no-store');
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->status===403?'Creator analytics is not available for this account.':'Invalid analytics filters.';
    exit;
}
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex,nofollow');
if(($_GET['export']??'')==='csv'){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="AZHottie-Creator-Performance-'.$report['endDay'].'.csv"');
    echo az_creator_analytics_csv($report);
    exit;
}
$models=[];
if($admin){
    $q=az_db()->query("SELECT DISTINCT u.id,u.name FROM users u JOIN user_roles r ON r.user_id=u.id AND r.role='model'
        WHERE u.status='active' ORDER BY u.name,u.id LIMIT 500");
    $models=$q->fetchAll(PDO::FETCH_ASSOC);
}
$money=static fn(int $cents):string=>'$'.number_format($cents/100,2);
$ratio=static fn(int $value,int $denominator):string=>$denominator?number_format(100*$value/$denominator,1).'%':'—';
$exportParams=['days'=>$days,'kind'=>$kind,'export'=>'csv'];
if($admin&&$modelId)$exportParams['model']=$modelId;
$max=1;
$recent=array_slice($report['trend'],-min(30,$days));
foreach($recent as $point)$max=max($max,$point['starts']);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#ffffff">
<title>Creator &amp; Video Performance · AZHottie</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/universal-header.css?v=1.18.1">
<link rel="stylesheet" href="assets/member-layout.css"><link rel="stylesheet" href="assets/creator-analytics.css?v=1.50.0">
</head><body class="azca-page" data-page="creator-analytics">
<?php az_render_universal_header('creator-analytics',$user);az_render_member_layout_open('creator-analytics',$user); ?>
<main class="azca" id="main-content">
 <header class="azca-hero"><div><p class="azca-eyebrow">AZHOTTIE / PERFORMANCE STUDIO</p>
 <h1><?= $admin?'Creator & video':'My video' ?> <em>performance.</em></h1>
 <p>Public video engagement, creator product attribution and review outcomes from your studio records. No sample metrics.</p></div>
 <a class="azca-button azca-button-outline" href="<?= $admin?'admin-studio.php':'dashboard.php' ?>">Back to dashboard</a></header>
 <section class="azca-toolbar" aria-label="Performance report filters">
 <form method="get" action="creator-analytics.php" class="azca-filter">
  <label>Report period<select name="days">
    <?php foreach([7,30,90] as $period): ?><option value="<?= $period ?>"<?= $period===$days?' selected':'' ?>>Last <?= $period ?> days</option><?php endforeach; ?>
  </select></label>
  <label>Video type<select name="kind">
    <?php foreach(['all'=>'All public videos','creator'=>'Creator videos','production'=>'Published productions'] as $value=>$label): ?><option value="<?= $value ?>"<?= $value===$kind?' selected':'' ?>><?= az_h($label) ?></option><?php endforeach; ?>
  </select></label>
  <?php if($admin): ?><label>Creator<select name="model"><option value="">All creators</option>
    <?php foreach($models as $model): ?><option value="<?= (int)$model['id'] ?>"<?= (int)$model['id']===$modelId?' selected':'' ?>><?= az_h($model['name']) ?></option><?php endforeach; ?>
  </select></label><?php endif; ?>
  <button class="azca-button azca-button-primary" type="submit">Apply filters</button>
 </form>
 <a class="azca-button azca-button-export" href="creator-analytics.php?<?= az_h(http_build_query($exportParams)) ?>">Export CSV</a>
 </section>
 <p class="azca-date">Reporting <?= az_h($report['startDay']) ?> through <?= az_h($report['endDay']) ?> UTC · Previous equal period used for comparison</p>
 <section class="azca-cards" aria-label="Public video performance summary">
  <?php $cards=[
    ['Play starts',number_format($report['current']['starts']),'Previous: '.number_format($report['previous']['starts'])],
    ['Completed plays',number_format($report['current']['completions']),'Completion: '.($report['completionRate']===null?'—':number_format($report['completionRate'],1).'%')],
    ['Watched minutes',number_format((int)floor($report['current']['watchedSeconds']/60)),'Estimated active playback'],
    ['Product CTA taps',number_format($report['current']['clicks']),'Session-limited · since v1.50'],
    ['Stripe checkout starts',number_format($report['current']['checkoutStarts']),'Not paid orders'],
    ['Stripe verified net',$money($report['current']['verifiedNetCents']),'Signed payments less refunds'],
    ['Cash recorded net',$money($report['current']['cashNetCents']),'Staff-confirmed · separate'],
    ['Estimated commission',$money($report['current']['commissionCents']),'Not a payout']
   ];foreach($cards as [$label,$value,$caption]): ?>
   <article class="azca-card"><p><?= az_h($label) ?></p><strong><?= az_h($value) ?></strong><small><?= az_h($caption) ?></small></article>
  <?php endforeach; ?>
 </section>
 <div class="azca-grid">
 <section class="azca-panel" aria-labelledby="azca-trend-title">
  <div class="azca-panel-heading"><div><p class="azca-eyebrow">AUDIENCE ACTIVITY</p><h2 id="azca-trend-title">Play starts over time</h2></div><span>Latest <?= count($recent) ?> days</span></div>
  <div class="azca-chart" role="img" aria-label="Daily video play starts for the last <?= count($recent) ?> days">
   <?php foreach($recent as $point): ?><div class="azca-chart-col" title="<?= az_h($point['day'].' — '.number_format($point['starts']).' play starts') ?>">
    <i style="height:<?= round(100*$point['starts']/$max,1) ?>%"></i>
    <span><?= az_h(substr($point['day'],8,2)) ?></span></div><?php endforeach; ?>
  </div>
  <div class="azca-trend-summary"><span>Previous-period play starts <strong><?= number_format($report['previous']['starts']) ?></strong></span>
   <span>Previous-period verified Stripe net <strong><?= $money($report['previous']['verifiedNetCents']) ?></strong></span></div>
 </section>
 <section class="azca-panel" id="reviews" aria-labelledby="azca-review-title">
  <div class="azca-panel-heading"><div><p class="azca-eyebrow">SUBMISSION PIPELINE</p><h2 id="azca-review-title">Review turnaround</h2></div><span>Current-state snapshot</span></div>
  <div class="azca-review-stats">
   <div><strong><?= number_format($report['reviews']['submitted']) ?></strong><span>Submitted this period</span></div>
   <div><strong><?= number_format($report['reviews']['pending']) ?></strong><span>Still awaiting review from those submissions</span></div>
   <div><strong><?= number_format($report['reviews']['reviewed']) ?></strong><span>Current decisions reviewed this period</span></div>
   <div><strong><?= number_format($report['reviews']['approved']) ?></strong><span>Approved / scheduled · <?= $ratio($report['reviews']['approved'],$report['reviews']['reviewed']) ?> of current reviewed decisions</span></div>
   <div><strong><?= number_format($report['reviews']['changesRequested']) ?></strong><span>Changes requested</span></div>
   <div><strong><?= number_format($report['reviews']['rejected']) ?></strong><span>Rejected</span></div>
  </div>
  <p class="azca-help">Average review turnaround: <strong><?= $report['reviews']['avgReviewHours']===null?'—':number_format($report['reviews']['avgReviewHours'],1).' hours' ?></strong>. Current video status may change after a review; this is not a historical event-by-event approval rate.</p>
 </section>
 </div>
 <section class="azca-panel azca-videos" aria-labelledby="azca-videos-title">
  <div class="azca-panel-heading"><div><p class="azca-eyebrow">PERFORMANCE BY ASSET</p><h2 id="azca-videos-title">Published videos <span><?= count($report['videos']) ?></span></h2></div>
  <span>Publicly available videos only</span></div>
  <?php if(!$report['videos']): ?><p class="azca-empty">No public videos match these filters. The report does not invent results from unpublished material.</p>
  <?php else: ?>
  <table class="azca-table"><thead><tr><th scope="col">Video / creator</th><th scope="col">Plays</th><th scope="col">Completion</th><th scope="col">Watch min</th><th scope="col">Product taps</th><th scope="col">Checkouts</th><th scope="col">Stripe net</th><th scope="col">Cash net</th></tr></thead><tbody>
  <?php foreach(array_slice($report['videos'],0,150) as $video): ?><tr>
    <th scope="row"><a href="<?= az_h($video['watchUrl']) ?>"><?= az_h($video['title']?:'Untitled video') ?></a><small><?= az_h($video['creatorName']) ?> · <?= $video['kind']==='creator'?'Creator':'Production' ?></small></th>
    <td data-label="Plays"><?= number_format($video['starts']) ?></td>
    <td data-label="Completion"><?= $ratio($video['completions'],$video['starts']) ?></td>
    <td data-label="Watch min"><?= number_format((int)floor($video['watchedSeconds']/60)) ?></td>
    <td data-label="Product taps"><?= number_format($video['clicks']) ?></td>
    <td data-label="Checkouts"><?= number_format($video['checkoutStarts']) ?></td>
    <td data-label="Stripe net"><?= $money($video['verifiedNetCents']) ?></td>
    <td data-label="Cash net"><?= $money($video['cashNetCents']) ?></td>
   </tr><?php endforeach; ?>
  </tbody></table>
  <?php if(count($report['videos'])>150): ?><p class="azca-help">Showing top 150 videos. Export the CSV for all <?= count($report['videos']) ?> rows.</p><?php endif; ?>
  <?php endif; ?>
 </section>
 <?php if($admin): ?>
 <section class="azca-panel azca-videos" aria-labelledby="azca-creators-title"><div class="azca-panel-heading"><div><p class="azca-eyebrow">CREATOR COMPARISONS</p><h2 id="azca-creators-title">Creator performance</h2></div><span>Current public content</span></div>
  <?php if(!$report['creators']): ?><p class="azca-empty">No creators have eligible public videos in this scope.</p><?php else: ?>
  <table class="azca-table azca-creator-table"><thead><tr><th scope="col">Creator</th><th scope="col">Videos</th><th scope="col">Plays</th><th scope="col">Completion</th><th scope="col">Product taps</th><th scope="col">Stripe net</th><th scope="col">Cash net</th></tr></thead><tbody>
  <?php foreach($report['creators'] as $creator): ?><tr><th scope="row"><?= az_h($creator['name']) ?></th>
   <td data-label="Videos"><?= number_format($creator['videos']) ?></td><td data-label="Plays"><?= number_format($creator['starts']) ?></td>
   <td data-label="Completion"><?= $ratio($creator['completions'],$creator['starts']) ?></td>
   <td data-label="Product taps"><?= number_format($creator['clicks']) ?></td>
   <td data-label="Stripe net"><?= $money($creator['verifiedNetCents']) ?></td>
   <td data-label="Cash net"><?= $money($creator['cashNetCents']) ?></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
 </section>
 <?php endif; ?>
 <p class="azca-disclaimer"><strong>How to read this report:</strong> <?= az_h($report['basis']) ?></p>
</main><?php az_render_member_layout_close(); ?></body></html>
