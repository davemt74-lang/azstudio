<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/universal-header.php';
require_once __DIR__.'/includes/member-layout.php';
$user=az_live_require_editor();$isAdmin=az_has_role($user,'admin');
header('Cache-Control: no-store');header('X-Robots-Tag: noindex,nofollow');
$data=$isAdmin?az_admin_command_data($user):null;
$management=$isAdmin?az_admin_intelligence_briefing($user,$data):null;
$room=$isAdmin?($data['live']['onAir']??false):az_live_active_room()!==null;
$shortcuts=[
 ['commerce.php','◆','Commerce','Orders, products & creator commissions'],
 ['casting.php','♧','Casting CRM','Model applications and interviews'],
 ['user-submissions.php','▷','Video review','Creator submissions and decisions'],
 ['live-director.php','◉','Live Director','Cameras, shows & shopping'],
 ['productions.php','▤','Productions','Projects, scripts & assignments'],
 ['videos.php','▣','Video library','Published and approved media'],
 ['publishing.php','✧','Publishing','Distribution and campaigns'],
 ['accounts.php','◇','Accounts','Roles and membership'],
];
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Studio Command Center · AZHottie</title><link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/member-layout.css"><link rel="stylesheet" href="assets/live-studio.css?v=1.40.0">
<link rel="stylesheet" href="assets/admin-command.css?v=1.43.0"></head>
<body data-page="admin-studio"><?php az_render_universal_header('admin-studio',$user);az_render_member_layout_open('admin-studio',$user); ?>
<main class="azd" id="azh-admin-command" data-csrf="<?= az_h(az_csrf_token()) ?>" aria-labelledby="azd-title">
  <header class="azd-hero">
    <div class="azd-hero-copy"><p class="azd-eyebrow"><span class="azd-spark" aria-hidden="true">✳</span> AZHOTTIE / STUDIO OPERATIONS</p>
      <h1 id="azd-title">Your studio, <em>in focus.</em></h1>
      <p><?= $isAdmin?'One place for commerce, new talent, content reviews and audience performance. Every number comes from your studio records.':'Live production tools and recordings for your authorized editor account.' ?></p>
      <div class="azd-hero-actions"><a class="azd-btn azd-btn-primary" href="live-director.php">Open Live Director <span aria-hidden="true">↗</span></a>
        <?php if($isAdmin): ?><a class="azd-btn azd-btn-soft" href="commerce.php?tab=agent">Commerce Agent briefing</a><?php else: ?><a class="azd-btn azd-btn-soft" href="live-productions.php">Saved live productions</a><?php endif; ?></div>
    </div>
    <div class="azd-broadcast" aria-label="Current broadcast status"><span class="azd-broadcast-label"><span class="azd-live-dot<?= $room?' is-live':'' ?>" aria-hidden="true"></span> LIVE BROADCAST</span>
      <strong><?= $room?'On air now':'Studio offline' ?></strong>
      <p><?= $room&&$isAdmin?az_h((string)($data['live']['title']??'Active broadcast')):($room?'An active broadcast is in progress.':'Your next production is ready when you are.') ?></p>
      <a href="live-director.php">Open studio <span aria-hidden="true">↗</span></a>
    </div>
  </header>
<?php if($isAdmin): ?>
<section class="azd-panel azd-agent-center" id="azd-management" aria-labelledby="azd-management-title">
  <div class="azd-panel-heading azd-agent-heading">
    <div><p class="azd-kicker">YOUR MANAGEMENT SPECIALIST</p><h2 id="azd-management-title">Agent Director Briefing</h2>
      <p class="azd-muted">A recorded workflow snapshot, not an automatic screening, publishing or payment decision.</p></div>
    <div class="azd-agent-tools"><button type="button" id="azd-management-check" class="azd-agent-check">Check follow-ups</button><a class="azd-link" href="agent-chat.php?topic=studio">Discuss with Agent ↗</a></div>
  </div>
  <div class="azd-agent-stats">
    <div><strong><?= (int)$management['counts']['newApplications'] ?></strong><span>New applications</span></div>
    <div><strong><?= (int)$management['counts']['pendingVideos'] ?></strong><span>Video reviews</span></div>
    <div><strong><?= (int)$management['counts']['providerExceptions'] ?></strong><span>Provider exceptions</span></div>
    <div><strong><?= (int)$management['counts']['agedWorkflowItems'] ?></strong><span>Aged workflow items</span></div>
  </div>
  <div class="azd-agent-columns">
    <div class="azd-agent-priorities">
      <div class="azd-agent-subhead"><h3>Prioritized follow-ups</h3><span>Staff decisions required</span></div>
      <?php if($management['actionsFollowups']): ?>
      <ol class="azd-agent-actions">
      <?php foreach(array_slice($management['actionsFollowups'],0,6) as $item): ?>
        <li><a href="<?= az_h($item['href']) ?>">
          <span class="azd-agent-priority" data-level="<?= (int)$item['priority'] ?>"><?= (int)$item['priority']===0?'Urgent':((int)$item['priority']===1?'Review':'Routine') ?></span>
          <span><strong><?= az_h($item['title']) ?></strong><small><?= az_h($item['detail']) ?></small></span><span class="azd-agent-arrow" aria-hidden="true">↗</span>
        </a></li>
      <?php endforeach; ?>
      </ol>
      <?php else: ?><p class="azd-empty">No management exceptions require immediate follow-up.</p><?php endif; ?>
      <p class="azd-agent-next"><strong>Recommended next step</strong> <?= az_h($management['nextStep']) ?></p>
    </div>
    <div class="azd-agent-opportunities">
      <div class="azd-agent-subhead"><h3>Opportunities & watchpoints</h3><span>Evidence-based</span></div>
      <?php foreach(array_slice($management['opportunities'],0,3) as $opportunity): ?>
        <a href="<?= az_h($opportunity['href']) ?>" class="azd-agent-opportunity">
          <strong><?= az_h($opportunity['title']) ?> <span aria-hidden="true">↗</span></strong>
          <small><?= az_h($opportunity['detail']) ?></small>
        </a>
      <?php endforeach; ?>
      <p class="azd-agent-next"><strong>Open question</strong> <?= az_h($management['openQuestions'][0]) ?></p>
    </div>
  </div>
  <p id="azd-management-status" class="azd-agent-status" role="status" aria-live="polite">Recommendations reflect current database records. No automated decisions or financial actions have been performed.</p>
  <?php if($management['truncated']): ?><p class="azd-disclaimer">Large workflow queue: a bounded sample was assessed. Review Casting and Video Submissions for remaining items.</p><?php endif; ?>
</section>
<?php endif; ?>
<?php if($isAdmin): ?>
<section class="azd-panel azd-action-center" id="azd-action-center" aria-labelledby="azd-action-title">
  <div class="azd-panel-heading azd-action-head">
    <div><p class="azd-kicker">OWNER · DUE DATE · FOLLOW-THROUGH</p><h2 id="azd-action-title">Agent Action Center</h2>
      <p class="azd-muted">Track management recommendations, assign a staff owner and record a resolution. Source workflows remain separate.</p></div>
    <div class="azd-agent-tools">
      <button type="button" class="azd-agent-check" data-actions-sync>Check & track open issues</button>
      <button type="button" class="azd-action-button" data-actions-refresh>Refresh tasks</button>
    </div>
  </div>
  <div class="azd-action-counts" data-actions-counts aria-label="Task status totals"></div>
  <div class="azd-action-suggest">
    <h3>Track a current management recommendation</h3>
    <div class="azd-action-suggest-list">
    <?php foreach(array_slice($management['actionsFollowups'],0,6) as $suggestion): ?>
      <div class="azd-action-suggest-item">
        <span><?= az_h($suggestion['title']) ?></span>
        <button type="button" class="azd-action-button" data-action-track
          data-kind="<?= az_h($suggestion['sourceKind']) ?>"
          data-source-id="<?= az_h($suggestion['sourceId']) ?>"
          data-stage="<?= az_h($suggestion['sourceStage']) ?>">Track</button>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
  <div class="azd-action-list-head">
    <h3>Staff-owned tasks</h3>
    <label>Show
      <select data-actions-filter aria-label="Filter tasks">
        <option value="active">Open & acknowledged</option><option value="all">All tasks</option>
        <option value="open">Open</option><option value="acknowledged">Acknowledged</option><option value="resolved">Resolved</option>
      </select>
    </label>
  </div>
  <p class="azd-action-summary" data-actions-status role="status" aria-live="polite">Loading the Action Center…</p>
  <div class="azd-action-list" data-actions-list></div>
  <p class="azd-disclaimer">Closing a follow-up never approves an applicant, publishes a video, changes an order or verifies a payout. Overdue reminders use the existing Agent bell, Brain and History.</p>
</section>
<?php endif; ?>
<?php if($isAdmin): ?>
  <section class="azd-section" aria-labelledby="azd-pulse-title"><div class="azd-section-heading"><div><p class="azd-kicker">BUSINESS AT A GLANCE</p><h2 id="azd-pulse-title">Studio pulse</h2></div><p class="azd-muted">Revenue and audience: rolling 30 days · Queues: current</p></div>
  <div class="azd-kpis">
    <?php foreach($data['scorecards'] as $card): ?><a class="azd-kpi azd-kpi-<?= az_h($card['tone']) ?>" href="<?= az_h($card['href']) ?>">
      <span class="azd-kpi-label"><?= az_h($card['label']) ?></span>
      <strong><?= isset($card['valueCents'])?az_admin_command_money($card['valueCents']):number_format($card['value']??0) ?></strong>
      <small><?= az_h($card['hint']) ?></small><span class="azd-kpi-arrow" aria-hidden="true">↗</span></a><?php endforeach; ?>
  </div></section>

  <div class="azd-columns azd-columns-main">
    <section class="azd-panel azd-attention" aria-labelledby="azd-attention-title">
      <div class="azd-panel-heading"><div><p class="azd-kicker">YOUR NEXT DECISIONS</p><h2 id="azd-attention-title">Needs attention</h2></div><span class="azd-count-pill"><?= count($data['actions']) ?> queues</span></div>
      <?php if($data['actions']): ?><div class="azd-task-list">
      <?php foreach(array_slice($data['actions'],0,6) as $action): ?><a class="azd-task" href="<?= az_h($action['href']) ?>"><span class="azd-task-count"><?= (int)$action['count'] ?></span>
        <span class="azd-task-text"><strong><?= az_h($action['title']) ?></strong><small><?= az_h($action['detail']) ?></small></span><span class="azd-task-arrow" aria-hidden="true">↗</span></a><?php endforeach; ?></div>
      <?php else: ?><div class="azd-empty">No outstanding review queues were identified. Review the activity summaries below for growth opportunities.</div><?php endif; ?>
      <div class="azd-panel-foot"><a href="agent-chat.php?topic=commerce">Discuss priorities with AZHottie Agent <span aria-hidden="true">↗</span></a></div>
    </section>
    <section class="azd-panel" aria-labelledby="azd-commerce-title"><div class="azd-panel-heading"><div><p class="azd-kicker">30-DAY REVENUE</p><h2 id="azd-commerce-title">Commerce performance</h2></div><a class="azd-link" href="commerce.php?tab=analytics">Full report ↗</a></div>
      <div class="azd-revenue-list">
        <div><span>Stripe-verified product net</span><strong><?= az_admin_command_money((int)$data['commerce']['verified']['netCents']) ?></strong></div>
        <div><span>Cash recorded product net</span><strong><?= az_admin_command_money((int)$data['commerce']['cash']['netCents']) ?></strong></div>
        <div><span>Manual external order net <small>(staff-attested)</small></span><strong><?= az_admin_command_money((int)$data['commerce']['manual']['netCents']) ?></strong></div>
        <div><span>Estimated Stripe commissions</span><strong><?= az_admin_command_money((int)$data['commerce']['verified']['commissionCents']) ?></strong></div>
      </div>
      <div class="azd-commerce-micro"><div><strong><?= (int)$data['activeProducts'] ?></strong><span>Active products</span></div>
        <div><strong><?= (int)$data['commerce']['checkout']['started'] ?></strong><span>Stripe checkouts</span></div>
        <div><strong><?= $data['commerce']['checkout']['paymentRatePercent']===null?'—':number_format($data['commerce']['checkout']['paymentRatePercent'],1).'%' ?></strong><span>Stripe checkout completion</span></div></div>
      <p class="azd-disclaimer">Product-subtotal reporting. Cash and manually attested sales are not Stripe-verified. Commission estimates are not creator payouts.</p>
    </section>
  </div>

  <div class="azd-columns azd-columns-review">
    <section class="azd-panel" aria-labelledby="azd-casting-title"><div class="azd-panel-heading"><div><p class="azd-kicker">MODEL PIPELINE</p><h2 id="azd-casting-title">New model résumés</h2></div><a class="azd-link" href="casting.php">Casting CRM ↗</a></div>
      <div class="azd-stat-strip"><span><strong><?= (int)$data['casting']['new'] ?></strong> New applications</span><span><strong><?= (int)$data['casting']['screening'] ?></strong> Screening</span><span><strong><?= (int)$data['casting']['interview'] ?></strong> Interviews</span></div>
      <?php if($data['castingRecent']): ?><div class="azd-review-list">
      <?php foreach(array_slice($data['castingRecent'],0,5) as $lead): ?><a href="casting.php?lead=<?= rawurlencode($lead['id']) ?>" class="azd-review-item"><span class="azd-avatar"><?= az_h(az_admin_command_initial((string)($lead['stage_name']?:$lead['applicant_name']))) ?></span>
        <span><strong><?= az_h($lead['stage_name']?:$lead['applicant_name']) ?></strong><small><?= az_h($lead['city']?:'City not provided') ?> · <?= az_h(ucfirst(str_replace('_',' ',$lead['status']))) ?> · <?= az_h(az_admin_command_date($lead['created_at'])) ?></small></span><span aria-hidden="true">↗</span></a><?php endforeach; ?></div>
      <?php else: ?><div class="azd-empty">No open casting profiles. Public applications will appear here once submitted.</div><?php endif; ?>
      <p class="azd-disclaimer">Application profiles include questionnaire answers in Casting CRM. No résumé file upload is implied.</p>
    </section>
    <section class="azd-panel" aria-labelledby="azd-submissions-title"><div class="azd-panel-heading"><div><p class="azd-kicker">CONTENT REVIEW</p><h2 id="azd-submissions-title">Video submissions</h2></div><a class="azd-link" href="user-submissions.php">Review videos ↗</a></div>
      <div class="azd-stat-strip"><span><strong><?= (int)$data['submissions']['pending'] ?></strong> Pending</span><span><strong><?= (int)$data['submissions']['changes_requested'] ?></strong> Revisions</span><span><strong><?= (int)$data['submissions']['published'] ?></strong> Published</span></div>
      <?php if($data['submissions']['recent']): ?><div class="azd-review-list">
      <?php foreach(array_slice($data['submissions']['recent'],0,5) as $video): ?><a class="azd-review-item" href="user-submissions.php"><span class="azd-avatar azd-avatar-video" aria-hidden="true">▷</span>
        <span><strong><?= az_h($video['title']) ?></strong><small><?= az_h($video['model_name']) ?> · <?= az_h(az_admin_command_date($video['submitted_at'])) ?> · Awaiting review</small></span><span aria-hidden="true">↗</span></a><?php endforeach; ?></div>
      <?php else: ?><div class="azd-empty">No videos are awaiting review right now.</div><?php endif; ?>
      <div class="azd-panel-foot"><a href="videos.php">Open finished video library ↗</a></div>
    </section>
  </div>

  <section class="azd-panel azd-audience" aria-labelledby="azd-audience-title"><div class="azd-panel-heading"><div><p class="azd-kicker">ACTIVITY & ENGAGEMENT</p><h2 id="azd-audience-title">Audience and views tracking</h2></div><span class="azd-basis">14-day trend · 30-day totals</span></div>
    <div class="azd-audience-summary">
      <div><strong><?= number_format($data['audience']['profileViews30d']) ?></strong><span>Profile page views</span></div>
      <div><strong><?= number_format($data['audience']['playStarts30d']) ?></strong><span>Video play starts</span></div>
      <div><strong><?= number_format($data['audience']['videoCompletions30d']) ?></strong><span>Video completions</span></div>
      <div><strong><?= number_format($data['audience']['watchedMinutes30d']) ?></strong><span>Minutes watched</span></div>
      <div><strong><?= number_format($data['audience']['liveProductImpressions30d']) ?></strong><span>Live product impressions</span></div>
      <div><strong><?= number_format($data['audience']['liveProductClicks30d']) ?></strong><span>Live shopping CTA taps</span></div>
    </div>
    <?php $peak=1;foreach($data['trend'] as $day)$peak=max($peak,$day['profileViews'],$day['videoPlays'],$day['liveProductImpressions']); ?>
    <div class="azd-chart-legend"><span><i class="azd-chart-dot is-profiles"></i>Profile views</span><span><i class="azd-chart-dot is-plays"></i>Video plays</span><span><i class="azd-chart-dot is-live"></i>Live product impressions</span></div>
    <div class="azd-chart" role="img" aria-label="Fourteen-day comparison of daily profile page views, video play starts, and live-product impressions">
      <?php foreach($data['trend'] as $point): ?>
        <div class="azd-chart-day" title="<?= az_h($point['day'].' — '.$point['profileViews'].' profile views, '.$point['videoPlays'].' video starts, '.$point['liveProductImpressions'].' live impressions') ?>">
          <div class="azd-chart-bars"><i class="is-profiles" style="height:<?= round(100*$point['profileViews']/$peak,1) ?>%"></i><i class="is-plays" style="height:<?= round(100*$point['videoPlays']/$peak,1) ?>%"></i><i class="is-live" style="height:<?= round(100*$point['liveProductImpressions']/$peak,1) ?>%"></i></div>
          <time datetime="<?= az_h($point['day']) ?>"><?= az_h(substr($point['day'],8,2)) ?></time></div>
      <?php endforeach; ?>
    </div>
    <p class="azd-disclaimer"><?= az_h($data['trackingBasis']) ?></p>
  </section>

  <div class="azd-columns azd-columns-bottom">
    <section class="azd-panel" aria-labelledby="azd-live-title"><div class="azd-panel-heading"><div><p class="azd-kicker">LIVE SHOPPING</p><h2 id="azd-live-title">Broadcast commerce</h2></div><a class="azd-link" href="live-director.php">Director ↗</a></div>
      <?php if($data['live']['onAir']): ?><p class="azd-live-feature"><span class="azd-live-dot is-live"></span><?= az_h($data['live']['featuredProduct']?:'Broadcast running — no product featured') ?></p>
        <?php if($data['live']['featuredCreator']): ?><p class="azd-muted">Featuring creator: <?= az_h($data['live']['featuredCreator']) ?></p><?php endif; ?>
        <div class="azd-commerce-micro"><div><strong><?= (int)$data['live']['impressions'] ?></strong><span>Impressions this broadcast</span></div><div><strong><?= (int)$data['live']['clicks'] ?></strong><span>CTA taps this broadcast</span></div><div><strong><?= (int)$data['live']['checkoutStarts'] ?></strong><span>Stripe checkouts</span></div></div>
      <?php else: ?><div class="azd-empty">Nothing is on air. Feature an approved product in Live Director to begin session-bounded product engagement tracking.</div><?php endif; ?>
      <div class="azd-live-summary"><span>Last 30 days · verified live-attributed Stripe net</span><strong><?= az_admin_command_money($data['liveNet30d']) ?></strong></div>
      <p class="azd-disclaimer">Attribution uses the original active broadcast product and approved creator promotion. Clicks do not mean purchases.</p>
    </section>
    <section class="azd-panel" aria-labelledby="azd-products-title"><div class="azd-panel-heading"><div><p class="azd-kicker">BEST SELLERS</p><h2 id="azd-products-title">Top products</h2></div><a class="azd-link" href="commerce.php?tab=analytics">See analytics ↗</a></div>
      <?php if($data['commerce']['topProducts']): ?><ol class="azd-top-products"><?php foreach(array_slice($data['commerce']['topProducts'],0,5) as $i=>$p): ?><li><span><?= $i+1 ?></span><strong><?= az_h($p['title']) ?><small><?= (int)$p['orders'] ?> Stripe-paid orders</small></strong><b><?= az_admin_command_money($p['netCents']) ?></b></li><?php endforeach; ?></ol>
      <?php else: ?><div class="azd-empty">No verified Stripe product sales in the last 30 days.</div><?php endif; ?>
      <div class="azd-commerce-micro"><div><strong><?= (int)$data['activeModels'] ?></strong><span>Active models</span></div><div><strong><?= (int)$data['activeProductions'] ?></strong><span>Active productions</span></div><div><strong><?= (int)$data['pendingPayouts'] ?></strong><span>Open payout batches</span></div></div>
    </section>
  </div>

  <section class="azd-shortcuts" aria-labelledby="azd-tools-title"><div class="azd-section-heading"><div><p class="azd-kicker">STUDIO TOOLKIT</p><h2 id="azd-tools-title">Workspaces</h2></div></div><div class="azd-shortcut-grid">
    <?php foreach($shortcuts as [$href,$icon,$title,$description]): ?><a href="<?= az_h($href) ?>" class="azd-shortcut"><span class="azd-shortcut-icon" aria-hidden="true"><?= az_h($icon) ?></span><strong><?= az_h($title) ?></strong><small><?= az_h($description) ?></small><span aria-hidden="true">↗</span></a><?php endforeach; ?></div>
  </section>
<?php else: ?>
  <section class="azd-shortcuts" aria-label="Editor tools"><h2>Live production workspaces</h2><div class="azd-shortcut-grid">
    <?php foreach([['live-director.php','◉','Live Director','Broadcast cameras and program'],['live-productions.php','▣','Saved shows','Recorded productions']] as [$href,$icon,$title,$description]): ?><a href="<?= az_h($href) ?>" class="azd-shortcut"><span class="azd-shortcut-icon"><?= az_h($icon) ?></span><strong><?= az_h($title) ?></strong><small><?= az_h($description) ?></small></a><?php endforeach; ?></div>
  </section>
<?php endif; ?>
</main><?php az_render_member_layout_close(); ?><script type="module" src="assets/admin-intelligence.js?v=1.42.0"></script><script type="module" src="assets/admin-action-center.js?v=1.43.0"></script></body></html>
