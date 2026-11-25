<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/universal-header.php';
require_once __DIR__.'/includes/member-layout.php';
require_once __DIR__.'/includes/creator-club-view.php';
$viewer=az_require_auth();
$id=filter_var($_GET['creator']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$creatorId=$id?:((az_has_role($viewer,'model'))?(int)$viewer['id']:0);
try{
    $creator=az_creator_club_creator($creatorId);
    $target=az_account_user($creatorId);$social=az_social_row($creatorId);
    if(!$target||!az_social_visible($social,$viewer,$target))throw new AzHttpError(404,'Creator profile is not shared with you.','not_found');
}catch(AzHttpError $e){
    http_response_code(404);az_creator_club_page_start('Creator not available','creator-clubs',$viewer);
    ?><section class="club-section"><h1>Creator not available</h1><p>This creator is not currently sharing a club with your account.</p><a class="club-primary" href="creator-clubs.php">Explore Creator Clubs</a></section><?php
    az_creator_club_page_end();exit;
}
$owner=(int)$viewer['id']===$creatorId&&az_has_role($viewer,'model');
$admin=az_has_role($viewer,'admin');
$tiers=az_creator_club_tiers($creatorId,$owner||$admin);
$cursor=az_creator_club_request_value('cursor');
$pageError='';
try{$postPage=az_creator_club_posts_page($creatorId,$viewer,['cursor'=>$cursor,'limit'=>12]);}
catch(AzHttpError $e){$pageError=$e->getMessage();$postPage=['posts'=>[],'nextCursor'=>null];}
$posts=$postPage['posts'];
$following=az_creator_club_is_following($viewer,$creatorId);
$q=az_db()->prepare('SELECT COUNT(*) FROM creator_club_follows WHERE creator_id=?');$q->execute([$creatorId]);$followers=(int)$q->fetchColumn();
$media=$owner?az_personal_media_list($creatorId):[];
$stats=$owner?az_creator_club_creator_stats($viewer):[];
$name=az_social_name($target);
az_creator_club_page_start($name.' · Creator Club','creator-club',$viewer);
az_creator_club_navigation($owner?'manage':'',$viewer);
?>
<header class="club-hero"><p class="club-eyebrow"><?= $owner?'YOUR CREATOR WORKSPACE':'AZHOTTIE · CREATOR CLUB' ?></p><h1><?= az_h($name) ?>'s Club<span>.</span></h1><p><?= $owner?'Share updates with your free followers, prepare membership tiers, and keep track of staff feedback.':az_h($social['tagline']?:'Come a little closer. Follow for free to see this creator’s approved follower updates.') ?></p><div class="club-stat"><strong><?= $followers ?></strong> <?= $followers===1?'follower':'followers' ?><a href="user.php?id=<?= $creatorId ?>">View creator profile ↗</a></div>
<?php if(!$owner): ?><form method="post" action="creator-club-action.php" data-club-form class="club-inline"><?php az_creator_club_form_hidden($following?'unfollow':'follow',$creatorId); ?><button type="submit" class="club-primary"><?= $following?'Following · Unfollow':'Follow for free' ?></button><a href="creator-club-workspace.php">Manage my clubs</a></form><?php else: ?><div class="club-stat-grid club-stat-grid-four"><div><strong><?= (int)$stats['published'] ?></strong><span>Published posts</span></div><div><strong><?= (int)$stats['pending'] ?></strong><span>Awaiting review</span></div><div><strong><?= (int)($stats['scheduled']??0) ?></strong><span>Scheduled</span></div><div><strong><?= (int)($stats['draft']??0) ?></strong><span>Private drafts</span></div></div><?php endif; ?>
</header>
<?php az_creator_club_notice(); ?>
<?php if($owner&&($social['visibility']??'private')==='private'): ?><div class="club-notice"><strong>Your profile is private.</strong> Share your profile with members before fans can discover and follow your club. <a href="user.php">Edit profile visibility</a></div><?php endif; ?>
<?php if($owner): ?>
<section class="club-section" id="club-create"><header><div><p class="club-eyebrow">YOUR CREATIVE SPACE</p><h2>Share an update</h2></div><a class="club-text-link" href="my-media.php">Open media library ↗</a></header><p class="club-muted">Start with a private draft, preview it, then send it to staff when you’re ready.</p><div class="club-workflow" aria-label="Publishing steps"><span><b>01</b> Save a private draft</span><span><b>02</b> Submit for review</span><span><b>03</b> Publish or schedule</span></div>
<details class="club-disclosure" id="club-new-post"><summary>Write a new post <span aria-hidden="true">＋</span></summary><div class="club-disclosure-body"><form class="club-form" action="creator-club-action.php" method="post" data-club-form data-club-editor><?php az_creator_club_form_hidden('postSave',$creatorId);az_creator_club_post_fields($tiers,$media);az_creator_club_post_actions(); ?></form></div></details>
<?php az_creator_club_publishing_status(); ?>
</section>
<?php endif; ?>
<section class="club-section" id="club-posts"><header><div><p class="club-eyebrow"><?= $owner?'CONTENT & FEEDBACK':'FROM THE CREATOR' ?></p><h2><?= $owner?'Manage your posts':'Latest updates' ?></h2></div><span class="club-muted"><?= $owner?count($posts).' posts on this page':'Available to your account' ?></span></header>
<?php if($pageError): ?><p class="club-notice" role="alert"><?= az_h($pageError) ?> <a href="creator-club.php?creator=<?= $creatorId ?>#club-posts">Return to the latest posts</a></p><?php elseif($cursor!==''): ?><p class="club-page-context">Showing older posts <a href="creator-club.php?creator=<?= $creatorId ?>#club-posts">Back to latest</a></p><?php endif; ?>
<?php if(!$posts&&!$pageError): ?><div class="club-empty"><h3><?= $owner?'Your next update starts here':($following?'You’re in. Watch this space.':'Get a little closer') ?></h3><p><?= $owner?'Open the composer above to save your first private draft. Free follower posts work even before you have a membership tier.':($following?'Published updates for your access will appear here. Tier-only posts require that tier’s access.':'Follow this creator for free to see their published follower updates.') ?></p></div><?php endif; ?>
<?php foreach($posts as $post):$date=(string)(($post['state']==='approved'?($post['published_at']??null):($post['updated_at']??null))?:$post['created_at']); ?><article class="club-post" data-club-post="<?= az_h($post['id']) ?>" data-post-state="<?= az_h($post['state']) ?>"><div class="club-card-top"><span class="club-badge club-badge-<?= az_h($post['state']) ?>"><?= az_h(az_creator_club_post_state($post['state'])) ?></span><span class="club-muted"><?= ($post['audience']??'tier')==='followers'?'Free followers':'Tier members' ?> · <time datetime="<?= az_h($date) ?>"><?= az_h(az_creator_club_display_date($date)) ?></time></span></div><h3><?= az_h($post['title']?:'Untitled draft') ?></h3><?php if(trim((string)$post['body'])!==''): ?><p class="club-post-body"><?= nl2br(az_h($post['body'])) ?></p><?php elseif(empty($post['media_id'])&&$owner): ?><p class="club-muted">Add your update when you’re ready.</p><?php endif; ?><?php if($post['media_id']): ?><video controls playsinline preload="metadata" src="creator-club-media.php?post=<?= az_h($post['id']) ?>" aria-label="<?= az_h($post['title']?:'Private draft video') ?>"></video><?php endif; ?>
<?php if(($owner||$admin)&&!empty($post['publish_at'])&&$post['state']!=='approved'): ?><div class="club-release-note"><span aria-hidden="true">◷</span><div><strong><?= $post['state']==='scheduled'?'Scheduled release':'Requested release' ?></strong><p><?= az_h(az_creator_club_schedule_label($post)) ?></p><small><?= $post['state']==='scheduled'?'Approved. This post stays private until it is released.':'Staff approval is required. If this time has passed when approved, the post publishes on approval.' ?></small></div></div><?php endif; ?>
<?php if(($owner||$admin)&&!empty($post['publish_error'])): ?><div class="club-feedback club-publish-error"><strong>Release needs attention</strong><p><?= az_h((string)$post['publish_error']) ?></p><p>This post is still private. Resolve the issue before the next publishing check.</p></div><?php endif; ?>
<?php if($owner||$admin)az_creator_club_feedback($post); ?>
<?php if($owner&&in_array($post['state'],['approved','pending','scheduled'],true)): ?><form class="club-inline" method="post" action="creator-club-action.php" data-club-form><?php az_creator_club_form_hidden('withdraw',$creatorId,$post); ?><button type="submit">Withdraw post</button><?php if(in_array($post['state'],['approved','scheduled'],true)): ?><span class="club-help">Withdraw before editing. Publishing again requires a new staff review.</span><?php endif; ?></form><?php endif; ?>
<?php if($owner&&in_array($post['state'],['draft','pending','rejected','withdrawn'],true)): ?><details class="club-disclosure"><summary><?= $post['state']==='draft'?'Edit draft':'Edit &amp; resubmit this post' ?></summary><div class="club-disclosure-body"><?php if($post['state']==='pending'): ?><p class="club-help">Saving as a draft removes this version from the review queue. Submit again when your changes are ready.</p><?php endif; ?><form class="club-form" method="post" action="creator-club-action.php" data-club-form data-club-editor><?php az_creator_club_form_hidden('postSave',$creatorId,$post);az_creator_club_post_fields($tiers,$media,$post);az_creator_club_post_actions(); ?></form></div></details><?php endif; ?>
</article><?php endforeach; ?>
<?php if(!empty($postPage['nextCursor'])): ?><div class="club-pagination"><a class="club-secondary" rel="next" href="<?= az_h(az_creator_club_link('creator-club.php',['creator'=>$creatorId,'cursor'=>$postPage['nextCursor']],'club-posts')) ?>">Load older posts <span aria-hidden="true">↓</span></a></div><?php endif; ?>
</section>
<section class="club-section" id="club-tiers"><header><div><p class="club-eyebrow">MEMBERSHIP PLANS</p><h2><?= $owner?'Manage membership tiers':'Creator subscriptions' ?></h2></div><span class="club-badge">Payments are not enabled</span></header><p class="club-muted"><?= $owner?'Approved tiers keep their benefits fixed. Archive a tier to hide it from new choices while preserving existing access.':'These are proposed membership plans. Following is free, and no subscription payment is taken here.' ?></p>
<div class="club-grid">
<?php foreach($tiers as $tier): ?><article class="club-card" data-club-tier="<?= az_h($tier['id']) ?>"><div class="club-card-top"><span class="club-badge club-badge-<?= az_h($tier['state']) ?>"><?= az_h(ucfirst($tier['state'])) ?></span><?php if(!empty($tier['archived'])): ?><span class="club-badge">Archived</span><?php endif; ?></div><h3><?= az_h($tier['title']) ?></h3><strong class="club-price">$<?= number_format(((int)$tier['monthly_cents'])/100,2) ?><span>/month · proposed</span></strong><p><?= nl2br(az_h($tier['description'])) ?></p>
<?php if($owner||$admin)az_creator_club_feedback($tier); ?>
<?php if($owner): ?>
<?php if(in_array($tier['state'],['pending','rejected'],true)&&empty($tier['archived'])): ?><details class="club-disclosure"><summary>Edit &amp; resubmit tier</summary><div class="club-disclosure-body"><form class="club-form club-form-stacked" method="post" action="creator-club-action.php" data-club-form><?php az_creator_club_form_hidden('tier_update',$creatorId,$tier);az_creator_club_tier_fields($tier); ?><button type="submit" class="club-primary">Resubmit tier for review</button></form></div></details><?php endif; ?>
<form class="club-inline" method="post" action="creator-club-action.php" data-club-form><?php az_creator_club_form_hidden(!empty($tier['archived'])?'tier_restore':'tier_archive',$creatorId,$tier); ?><button type="submit"><?= !empty($tier['archived'])?'Restore tier':'Archive tier' ?></button></form>
<?php else: ?><button type="button" disabled aria-disabled="true">Subscription checkout not available</button><?php endif; ?></article><?php endforeach; ?>
<?php if(!$tiers): ?><div class="club-empty"><h3>Membership tiers coming soon</h3><p><?= $owner?'Propose your first tier below when you are ready. Free follower posts work without a tier.':'You can follow this creator for free while membership plans are prepared.' ?></p></div><?php endif; ?>
</div>
<?php if($owner): ?><details class="club-disclosure" id="club-new-tier"><summary>Propose a new tier <span aria-hidden="true">＋</span></summary><div class="club-disclosure-body"><form class="club-form" action="creator-club-action.php" method="post" data-club-form><?php az_creator_club_form_hidden('tier',$creatorId);az_creator_club_tier_fields(); ?><button class="club-primary" type="submit">Submit tier for review</button><p class="club-help">No charges are made. An administrator must approve the proposed tier. You can keep up to five pending or approved tiers available at once.</p></form></div></details><?php endif; ?>
</section>
<?php if($owner):
    $history=az_creator_club_history($viewer,$creatorId);
    $access=az_creator_club_access_history($viewer,$creatorId);
?>
<section class="club-section" id="club-records"><header><div><p class="club-eyebrow">YOUR CLUB RECORDS</p><h2>Review &amp; access history</h2></div></header><details class="club-disclosure"><summary>Recent review activity <span><?= count($history) ?></span></summary><div class="club-disclosure-body"><?php if(!$history): ?><p class="club-muted">Staff decisions and your content changes will appear here.</p><?php else: ?><ol class="club-history"><?php foreach($history as $event): ?><li><strong><?= az_h(ucwords(str_replace('_',' ',(string)($event['event']??'')))) ?></strong><p><?= az_h((string)($event['object_title']??$event['object_type']??'Club item')) ?></p><?php if(!empty($event['note'])): ?><p><?= nl2br(az_h((string)$event['note'])) ?></p><?php endif; ?><small><?= az_h(substr((string)$event['created_at'],0,16)) ?> UTC</small></li><?php endforeach; ?></ol><?php endif; ?></div></details><details class="club-disclosure"><summary>Member access records <span><?= count($access) ?></span></summary><div class="club-disclosure-body"><p class="club-help">These records show granted access, not paid subscriber counts or revenue. Staff test access is disabled on production by default.</p><?php if(!$access): ?><p class="club-muted">No access records yet. Fans can follow your club for free.</p><?php else: ?><?php foreach($access as $record): ?><div class="club-access-record"><div><strong><?= az_h((string)($record['fan_name']??'Member')) ?></strong><p><?= az_h((string)($record['tier_title']??'Membership tier')) ?></p></div><div><span class="club-badge"><?= az_h(ucwords(str_replace('_',' ',(string)($record['status']??'recorded')))) ?></span><small>Ends <?= az_h(substr((string)$record['ends_at'],0,10)) ?></small></div></div><?php endforeach; ?><?php endif; ?></div></details></section>
<?php endif; ?>
<p class="club-footnote">Following is free. Subscription payments are not enabled.</p>
<?php az_creator_club_page_end(); ?>
