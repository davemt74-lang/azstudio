<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/universal-header.php';
require_once __DIR__.'/includes/member-layout.php';
$user=az_live_require_editor();$isAdmin=az_has_role($user,'admin');
header('Cache-Control: no-store');header('X-Robots-Tag: noindex,nofollow');
$room=az_live_active_room();
$submissions=$isAdmin?az_model_video_dashboard_summary():null;
$sections=[
 ['live-director.php','●','Live Director','Switch connected cameras, send one selected program to members, and record the finished presentation.'],
 ['live-productions.php','▣','Saved live productions','Manage recordings, covers and explicit members-only or public publication.'],
];
if ($isAdmin) $sections=array_merge($sections,[
 ['productions.php','▥','Productions','Projects, scripts, model roster, footage assignments and production review.'],
 ['library.php','▣','Interviews','Questionnaires, saved interviews, recorded answers and editing.'],
 ['profiles.php','♧','Models','Casting profiles, member records and approved collaborators.'],
 ['casting.php','✧','Casting CRM','Applications, screening forms and account invitations.'],
 ['questionnaires.php','☷','Questionnaires','Edit the prebuilt interview question sets and answer choices.'],
 ['videos.php','▸','Finished videos','Review, cover images, approval and Watch publishing.'],
 ['user-submissions.php','▤','User Submissions','Review independent model videos, request changes, reject or approve for Watch.'],
 ['accounts.php','◈','Accounts and roles','Manage members and grant the Live editor role without full administrator access.'],
 ['admin.php','✳','AI settings','OpenAI and Claude keys, production assistants and member chat.'],
]);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Admin Studio · AZHottie</title><link rel="icon" href="assets/favicon.svg"><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/member-layout.css"><link rel="stylesheet" href="assets/live-studio.css?v=1.20.0"></head>
<body data-page="admin-studio"><?php az_render_universal_header('admin-studio',$user);az_render_member_layout_open('admin-studio',$user); ?>
<main class="azh-admin-home" aria-labelledby="admin-title"><h1 id="admin-title">Admin Studio<span class="azh-accent">.</span></h1><p>Production tools live here, separate from the member workspace. Your account still uses one login.</p>
<section class="azh-admin-status"><div><strong>Live program</strong><p><?= $room?'● On air · '.az_h($room['title']):'Offline · Ready for a new broadcast' ?></p></div><a class="azh-live-button" href="live-director.php">Open Live Director</a></section>
<?php if ($isAdmin && $submissions): ?><section class="azh-admin-submissions" aria-label="User submissions dashboard">
  <div><p class="azh-live-eyebrow">CONTENT REVIEW / SUBMISSION ACTIVITY</p><h2>User Submissions</h2><p><?= (int)$submissions['pending'] ?> awaiting review · <?= (int)$submissions['published'] ?> published · <?= (int)$submissions['changes_requested'] ?> awaiting changes</p>
  <?php if ($submissions['recent']): ?><ul class="azh-admin-queue-preview"><?php foreach ($submissions['recent'] as $item): ?><li><strong><?= az_h($item['title']) ?></strong><small><?= az_h($item['model_name']) ?> · Pending review</small></li><?php endforeach; ?></ul><?php else: ?><p class="azh-admin-empty">No videos awaiting review right now.</p><?php endif; ?></div>
  <a class="azh-live-button" href="user-submissions.php">Review submissions<?php if ($submissions['pending']): ?> (<?= (int)$submissions['pending'] ?>)<?php endif; ?></a></section><?php endif; ?>
<div class="azh-admin-grid"><?php foreach($sections as [$href,$icon,$title,$description]): ?><a class="azh-admin-card" href="<?= az_h($href) ?>"><span aria-hidden="true" class="azh-admin-icon"><?= az_h($icon) ?></span><strong><?= az_h($title) ?></strong><small><?= az_h($description) ?></small><span class="azh-admin-open">Open →</span></a><?php endforeach; ?></div></main>
<?php az_render_member_layout_close(); ?></body></html>
