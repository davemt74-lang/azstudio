<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/universal-header.php';
require_once __DIR__ . '/includes/member-layout.php';
$user = az_require_auth();
$admin = az_has_role($user, 'admin');
$model = az_has_role($user, 'model');
$creator = $admin || $model || az_has_role($user, 'professional');
$reviewSummary = $admin ? az_model_video_dashboard_summary() : null;
$dashboard = az_dashboard_data($user);
$metrics = $dashboard['metrics'];
$engagement = $dashboard['engagement'];
$chartPeak = 1;
foreach ($engagement['trend'] as $point) $chartPeak=max($chartPeak,(int)$point['profileViews'],(int)$point['starts']);
$name = trim((string)($user['display_name'] ?? $user['name'] ?? ''));
$firstName = $name !== '' ? (preg_split('/\s+/u', $name)[0] ?? 'there') : 'there';
$assignmentLabels = [
  'assigned'=>['Needs response','needs-response'], 'accepted'=>['In progress','in-progress'],
  'submitted'=>['Awaiting review','in-review'], 'changes_requested'=>['Changes requested','needs-response']
];
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#ffffff"><meta name="robots" content="noindex,nofollow"><title>My dashboard · AZHottie</title><link rel="icon" href="assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/member-layout.css"><link rel="stylesheet" href="assets/dashboard.css?v=1.22.0"></head><body class="azh-dashboard-page" data-page="dashboard">
<?php az_render_universal_header('dashboard', $user); az_render_member_layout_open('dashboard', $user); ?>
<main class="workspace azh-dashboard" id="main-content">
  <section class="azh-stats-hero" aria-labelledby="azh-stats-title">
    <div class="azh-stats-hero-top">
      <div><p class="azh-kicker">YOUR AZHOTTIE DASHBOARD</p><h1 id="azh-stats-title"><?= az_h($firstName) ?>'s <em>studio overview.</em></h1><p>Track what you've published, see what needs your attention, and pick up where you left off.</p></div>
      <div class="azh-dash-actions"><a class="azh-dash-button azh-dash-button-light" href="user.php">View my profile ↗</a><?php if ($model): ?><a class="azh-dash-button azh-dash-button-dark" href="my-videos.php">Manage My Videos ↗</a><?php elseif ($creator): ?><a class="azh-dash-button azh-dash-button-dark" href="my-work.php">View my work ↗</a><?php endif; ?></div>
    </div>
    <div class="azh-stat-grid" aria-label="Your activity statistics">
      <article class="azh-stat-card"><span class="azh-stat-icon" aria-hidden="true">◉</span><p>Profile views</p><strong><?= number_format($metrics['profileViews']) ?></strong><small><?= number_format($metrics['profileViews30d']) ?> in the last 30 days</small></article>
      <article class="azh-stat-card"><span class="azh-stat-icon" aria-hidden="true">▷</span><p>Published interviews</p><strong><?= number_format($metrics['publishedInterviews']) ?></strong><small><?= $metrics['lastInterviewPublishedAt'] ? 'Last published ' . az_h(az_dash_date($metrics['lastInterviewPublishedAt'],'M j, Y')) : 'No published interviews yet' ?></small></article>
      <article class="azh-stat-card"><span class="azh-stat-icon" aria-hidden="true">✳</span><p>Live show appearances</p><strong aria-label="Not yet tracked">—</strong><small>Participant credits not yet tracked</small></article>
      <article class="azh-stat-card"><span class="azh-stat-icon" aria-hidden="true">▣</span><p><?= $model ? 'My published videos' : 'Active productions' ?></p><strong><?= number_format($model ? $metrics['publishedModelVideos'] : $dashboard['productionsCount']) ?></strong><small><?= $model ? number_format($metrics['pendingModelVideos']).' awaiting admin review' : 'From your production workspace' ?></small></article>
    </div>
    <p class="azh-stats-footnote">Private activity overview · Profile views are session-deduplicated page visits counted from this release, not unique viewers. Your own and admin visits are excluded.</p>
  </section>

  <div class="azh-dash-focus-grid" aria-label="Engagement and tasks needing attention">
    <section class="azh-dash-panel azh-dash-engagement" aria-labelledby="azh-engagement-title">
      <div class="azh-dash-panel-head"><div><p class="azh-kicker">YOUR VIEWER ENGAGEMENT</p><h2 id="azh-engagement-title">Engagement trends<span><?= (int)$engagement['days'] ?> days</span></h2></div><a href="watch.php">Open Watch ↗</a></div>
      <div class="azh-engagement-summary" aria-label="Public video engagement in the last 30 days">
        <div><strong><?= number_format($engagement['totalPlayStarts']) ?></strong><span>Video play sessions</span></div>
        <div><strong><?= number_format($engagement['totalCompletions']) ?></strong><span>Player completions</span></div>
        <div><strong><?= number_format($engagement['watchMinutes']) ?></strong><span>Minutes watched</span></div>
      </div>
      <div class="azh-engagement-chart-wrap">
        <div class="azh-chart-key"><strong>Daily activity</strong><span><i class="azh-key-profile"></i> Profile visits</span><span><i class="azh-key-play"></i> Video starts</span></div>
        <ol class="azh-engagement-chart" aria-label="Daily profile views and video play starts, last <?= (int)$engagement['days'] ?> days">
        <?php foreach($engagement['trend'] as $point):
          $profile=(int)$point['profileViews']; $plays=(int)$point['starts'];
          $profileHeight=round(100*$profile/$chartPeak,1);$playHeight=round(100*$plays/$chartPeak,1);
          $day=new DateTimeImmutable($point['day']); ?>
          <li aria-label="<?= az_h($day->format('M j')) ?>: <?= $profile ?> profile visits; <?= $plays ?> video starts">
            <span class="azh-chart-bars" aria-hidden="true"><i class="azh-chart-profile" style="height:<?= $profileHeight ?>%"></i><i class="azh-chart-play" style="height:<?= $playHeight ?>%"></i></span>
            <time datetime="<?= az_h($point['day']) ?>"><?= az_h($day->format('j')) ?></time>
          </li><?php endforeach; ?>
        </ol>
        <div class="azh-engagement-compare">
          <span><b><?= number_format($engagement['current']['profileViews']) ?></b> profile visits in 30 days</span>
          <span><b><?= number_format($engagement['current']['starts']) ?></b> video starts <?= $engagement['previous']['starts'] > 0 ? '(previous 30 days: '.number_format($engagement['previous']['starts']).')' : '(no earlier play data)' ?></span>
        </div>
        <?php if (!$engagement['totalPlayStarts'] && !$engagement['current']['profileViews']): ?><p class="azh-engagement-none">No measured public visits or video plays yet. The chart will fill as people view your profile and play published videos.</p><?php endif; ?>
      </div>
      <p class="azh-engagement-disclaimer">Private aggregate counts · Play sessions are not unique viewers. Watch-time minutes are estimated from active playback, not video impressions. Watch tracking starts in v1.22.0; profile visits started in v1.21.0. Only currently published videos credited to you are included.</p>
    </section>
    <section class="azh-dash-panel azh-dash-attention" aria-labelledby="azh-attention-title">
      <div class="azh-dash-panel-head"><div><p class="azh-kicker">NEEDS YOUR RESPONSE</p><h2 id="azh-attention-title">Action required <span><?= (int)$dashboard['actionCount'] ?></span></h2></div><?php if($creator): ?><a href="my-work.php">My work ↗</a><?php endif; ?></div>
      <?php if($dashboard['actionItems']): ?><ul class="azh-activity-list">
      <?php foreach($dashboard['actionItems'] as $action): ?><li><a class="azh-activity-row azh-attention-row" href="<?= az_h($action['href']) ?>">
        <span class="azh-attention-indicator <?= az_h($action['tone']) ?>" aria-hidden="true">!</span>
        <span class="azh-activity-content"><strong><?= az_h($action['title']) ?></strong><small><?= az_h($action['context']) ?> · <?= az_h($action['detail']) ?></small></span>
        <span class="azh-status-pill <?= $action['tone']==='urgent'?'is-overdue':'needs-response' ?>"><?= az_h($action['label']) ?></span>
      </a></li><?php endforeach; ?>
      </ul><?php else: ?><div class="azh-dash-empty"><span aria-hidden="true">✓</span><strong>You're all caught up</strong><p>Requested changes, assignments needing a response, approaching deadlines, and pending admin reviews appear here.</p></div><?php endif; ?>
      <div class="azh-dash-panel-foot"><a href="my-work.php">Assignment workspace ↗</a><?php if($model): ?><a href="my-videos.php">My Videos ↗</a><?php endif; ?><?php if($admin): ?><a href="user-submissions.php">Review submissions ↗</a><?php endif; ?></div>
    </section>
  </div>
  <?php if ($creator): ?><section class="azh-dash-panel azh-dash-deadlines" aria-labelledby="azh-deadlines-title">
    <div class="azh-dash-panel-head"><div><p class="azh-kicker">STAY ON SCHEDULE</p><h2 id="azh-deadlines-title">Assignment deadlines <span><?= (int)$dashboard['deadlines']['total'] ?></span></h2></div><a href="my-work.php?tab=assigned">All assignments ↗</a></div>
    <div class="azh-deadlines-summary"><span><strong><?= (int)$dashboard['deadlines']['overdue'] ?></strong> overdue</span><span><strong><?= (int)$dashboard['deadlines']['next7Days'] ?></strong> due in the next 7 days</span><small>Submitted shots awaiting review are excluded.</small></div>
    <?php if($dashboard['deadlines']['items']): ?><ul class="azh-deadline-list">
      <?php foreach($dashboard['deadlines']['items'] as $assignment): $due=$assignment['deadline']; ?><li><a href="my-work.php?assignmentId=<?= rawurlencode((string)$assignment['id']) ?>" class="azh-deadline-row">
        <span class="azh-deadline-title"><strong><?= az_h($assignment['shot_title']) ?></strong><small><?= az_h($assignment['project_title']) ?> · <?= az_h($due['dateText']) ?></small></span>
        <span class="azh-status-pill <?= $due['state']==='overdue'?'is-overdue':($due['state']==='soon'?'needs-response':'in-review') ?>"><?= az_h($due['label']) ?></span>
      </a></li><?php endforeach; ?>
    </ul><?php else: ?><div class="azh-dash-empty azh-dash-deadline-empty"><span aria-hidden="true">✓</span><strong>No assignment deadlines scheduled</strong><p>When your active assignments have due dates, they'll appear here.</p></div><?php endif; ?>
  </section><?php endif; ?>

  <?php if ($admin && $reviewSummary): ?><section class="azh-dash-review" aria-labelledby="azh-review-title">
    <div><p class="azh-kicker">ADMIN / CONTENT REVIEW</p><h2 id="azh-review-title">User Submissions</h2><p>Independent model videos are reviewed before appearing on Watch.</p>
      <div class="azh-dash-review-metrics"><span><strong><?= (int)$reviewSummary['pending'] ?></strong><small>Awaiting review</small></span><span><strong><?= (int)$reviewSummary['published'] ?></strong><small>Published</small></span><span><strong><?= (int)$reviewSummary['changes_requested'] ?></strong><small>Changes requested</small></span></div>
    </div><a class="azh-dash-button azh-dash-button-dark" href="user-submissions.php">Review submissions ↗</a>
  </section><?php endif; ?>

  <div class="azh-dash-section-heading"><div><p class="azh-kicker">YOUR NEXT MOVES</p><h2>What's happening in your world<span>.</span></h2></div></div>
  <div class="azh-dash-workgrid <?= $creator ? '' : 'azh-dash-workgrid-one' ?>">
    <?php if ($creator): ?>
    <section class="azh-dash-panel" aria-labelledby="azh-productions-title">
      <div class="azh-dash-panel-head"><div><p class="azh-kicker">IN PRODUCTION</p><h2 id="azh-productions-title">Active video productions <span><?= (int)$dashboard['productionsCount'] ?></span></h2></div><a href="<?= $admin ? 'productions.php' : 'my-work.php' ?>">All productions ↗</a></div>
      <?php if ($dashboard['productions']): ?><ul class="azh-activity-list">
        <?php foreach($dashboard['productions'] as $project): ?><li><a class="azh-activity-row" href="<?= $admin ? 'productions.php' : 'my-work.php' ?>"><span class="azh-activity-glyph" aria-hidden="true">▥</span><span class="azh-activity-content"><strong><?= az_h($project['title']) ?></strong><small><?= az_h(ucfirst((string)$project['kind'])) ?> · Updated <?= az_h(az_dash_date($project['updated_at'],'M j')) ?></small></span><span class="azh-status-pill is-active">Active</span></a></li><?php endforeach; ?>
      </ul><?php else: ?><div class="azh-dash-empty"><span aria-hidden="true">▤</span><strong>No active productions</strong><p>When you're added to an active production, it will appear here.</p></div><?php endif; ?>
      <div class="azh-dash-panel-foot"><a href="<?= $admin ? 'productions.php' : 'my-work.php' ?>">Open production workspace ↗</a></div>
    </section>
    <section class="azh-dash-panel" aria-labelledby="azh-assignments-title">
      <div class="azh-dash-panel-head"><div><p class="azh-kicker">YOUR WORK QUEUE</p><h2 id="azh-assignments-title">Open &amp; active assignments <span><?= (int)$dashboard['assignmentsCount'] ?></span></h2></div><a href="my-work.php">My assignments ↗</a></div>
      <?php if ($dashboard['assignments']): ?><ul class="azh-activity-list">
        <?php foreach($dashboard['assignments'] as $assignment): $status=$assignmentLabels[$assignment['status']]??['In progress','in-progress']; ?><li><a class="azh-activity-row" href="my-work.php"><span class="azh-activity-glyph" aria-hidden="true">▤</span><span class="azh-activity-content"><strong><?= az_h($assignment['shot_title']) ?></strong><small><?= az_h($assignment['project_title']) ?><?= $assignment['due_at'] ? ' · Due '.az_h(az_dash_date($assignment['due_at'],'M j, g:i A')) : '' ?></small></span><span class="azh-status-pill <?= az_h($status[1]) ?>"><?= az_h($status[0]) ?></span></a></li><?php endforeach; ?>
      </ul><?php else: ?><div class="azh-dash-empty"><span aria-hidden="true">✓</span><strong>Nothing waiting on you</strong><p>Assigned work and requested retakes will show up here.</p></div><?php endif; ?>
      <?php if($dashboard['openShotsCount']>0): ?><div class="azh-open-shots"><strong><?= (int)$dashboard['openShotsCount'] ?> open <?= $dashboard['openShotsCount']===1?'opportunity':'opportunities' ?></strong><p>Available production shots you can claim.</p><ul><?php foreach($dashboard['openShots'] as $shot): ?><li><?= az_h($shot['title']) ?> <span>· <?= az_h($shot['project_title']) ?></span></li><?php endforeach; ?></ul><a href="my-work.php">Browse open shots ↗</a></div><?php endif; ?>
      <div class="azh-dash-panel-foot"><a href="my-work.php">Review all assignments ↗</a></div>
    </section>
    <?php endif; ?>
    <section class="azh-dash-panel azh-dash-schedule-panel" aria-labelledby="azh-upcoming-title">
      <div class="azh-dash-panel-head"><div><p class="azh-kicker">ON YOUR CALENDAR</p><h2 id="azh-upcoming-title">Upcoming events &amp; appointments <span><?= (int)$dashboard['scheduleCount'] ?></span></h2></div><a href="calendar.php">Calendar ↗</a></div>
      <?php if($dashboard['schedule']): ?><ul class="azh-activity-list azh-schedule-list">
        <?php foreach($dashboard['schedule'] as $item): $date=new DateTimeImmutable($item['startAt']);$date=$date->setTimezone(new DateTimeZone('America/Phoenix')); ?><li><a class="azh-activity-row" href="<?= az_h($item['href']) ?>"><span class="azh-date-badge" aria-hidden="true"><b><?= az_h($date->format('M')) ?></b><strong><?= az_h($date->format('j')) ?></strong></span><span class="azh-activity-content"><strong><?= az_h($item['title']) ?></strong><small><?= az_h($date->format('D, g:i A')) ?><?= $item['location']!=='' ? ' · '.az_h($item['location']) : '' ?></small></span><span class="azh-status-pill <?= $item['status']==='waitlisted'?'is-waitlisted':'is-active' ?>"><?= az_h(ucfirst(str_replace('_',' ',$item['status']))) ?></span></a></li><?php endforeach; ?>
      </ul><?php else: ?><div class="azh-dash-empty"><span aria-hidden="true">▦</span><strong>Your schedule is clear</strong><p>Confirmed appointments and registered events will appear here.</p></div><?php endif; ?>
      <div class="azh-dash-panel-foot"><a href="calendar.php">Events calendar ↗</a><a href="appointments.php">Manage appointments ↗</a></div>
    </section>
    <?php if($model): ?><section class="azh-dash-panel azh-dash-videos-panel" aria-labelledby="azh-videos-title">
      <div class="azh-dash-panel-head"><div><p class="azh-kicker">YOUR OWN CREATIONS</p><h2 id="azh-videos-title">My Videos <span><?= array_sum($dashboard['videos']) ?></span></h2></div><a href="my-videos.php">Manage ↗</a></div>
      <div class="azh-dash-mini-stats"><div><b><?= (int)$dashboard['videos']['draft'] ?></b><span>Private drafts</span></div><div><b><?= (int)$dashboard['videos']['pending_review'] ?></b><span>Under review</span></div><div><b><?= (int)$dashboard['videos']['changes_requested'] ?></b><span>Needs changes</span></div></div>
      <p class="azh-dash-panel-explain">Independent videos stay private until you submit them and an admin approves publication.</p>
      <div class="azh-dash-panel-foot"><a href="my-videos.php">Continue your videos ↗</a><a href="my-media.php">Media gallery ↗</a></div>
    </section><?php endif; ?>
  </div>
  <section class="azh-dash-quick" aria-labelledby="azh-quick-title"><div><p class="azh-kicker">EXPLORE &amp; CREATE</p><h2 id="azh-quick-title">Quick access</h2></div><nav aria-label="More AZHottie tools"><a href="calendar.php">Events calendar ↗</a><a href="appointments.php">Book an appointment ↗</a><a href="classes.php">Classes ↗</a><a href="watch.php">Watch AZHottie ↗</a><?php if($creator): ?><a href="my-media.php">Media &amp; editor ↗</a><?php endif; ?></nav></section>
</main>
<?php az_render_member_layout_close(); ?>
<footer class="studio-footer"><span>AZHOTTIE · MEMBER SPACE</span><span>Arizona · Your space</span></footer>
</body></html>
