<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/universal-header.php';
require_once __DIR__.'/includes/member-layout.php';
require_once __DIR__.'/includes/creator-club-view.php';
$viewer=az_require_auth();
$memberships=az_creator_club_my_memberships($viewer);
$following=count(array_filter($memberships,static fn(array $row):bool=>(bool)$row['following']));
$available=array_sum(array_column($memberships,'available_posts'));
$statusLabels=['active_test'=>'Active test access','expired'=>'Expired','revoked'=>'Revoked','scheduled'=>'Scheduled test access','disabled'=>'Test access disabled','test_disabled'=>'Test access disabled','unavailable'=>'Unavailable'];
az_creator_club_page_start('My Clubs','creator-club-workspace',$viewer);
az_creator_club_navigation('mine',$viewer);
?>
<header class="club-hero"><p class="club-eyebrow">YOUR CONNECTIONS</p><h1>My Clubs<span>.</span></h1><p>Your followed creators, available updates, and access records. A free follow never starts a paid subscription.</p><div class="club-stat-grid"><div><strong><?= $following ?></strong><span>Clubs followed</span></div><div><strong><?= $available ?></strong><span>Recent updates</span></div><div><strong>Free</strong><span>Following today</span></div></div></header>
<?php az_creator_club_notice(); ?>
<section class="club-section"><header><div><p class="club-eyebrow">STAY IN THE LOOP</p><h2>Your clubs &amp; access</h2></div><a class="club-text-link" href="creator-clubs.php">Explore creators ↗</a></header>
<?php if(!$memberships): ?><div class="club-empty club-empty-centered"><span class="club-empty-symbol" aria-hidden="true">♡</span><h3>Make yourself at home</h3><p>Follow a creator to find their club here. You control which clubs send updates to your notification bell.</p><a class="club-primary" href="creator-clubs.php">Find a creator</a></div><?php endif; ?>
<div class="club-memberships">
<?php foreach($memberships as $membership):
    $creatorId=(int)$membership['creator_id'];$target=az_account_user($creatorId);$social=az_social_row($creatorId);
    $visible=(bool)$membership['available']&&$target&&$target['status']==='active'&&az_social_visible($social,$viewer,$target);
    $name=$visible?az_social_name($target):'Unavailable creator';
?><article class="club-membership" data-club-membership="<?= $creatorId ?>"><div class="club-membership-heading"><div><p class="club-eyebrow"><?= $membership['following']?'FOLLOWING FOR FREE':'ACCESS HISTORY' ?></p><h3><?= az_h($name) ?></h3><p class="club-muted"><?= $visible?(int)$membership['available_posts'].' recent updates available to your account':'This creator is not currently sharing a profile with your account.' ?></p></div><?php if($visible): ?><a class="club-secondary" href="creator-club.php?creator=<?= $creatorId ?>">Open club ↗</a><?php endif; ?></div>
<?php if($membership['following']): ?><div class="club-follow-controls"><div><strong><?= $membership['notifications_enabled']?'Notifications on':'Notifications paused' ?></strong><p>New approved updates appear in your notification bell.</p></div><form action="creator-club-action.php" method="post" data-club-form class="club-inline"><?php az_creator_club_form_hidden('notifications',$creatorId,null,'mine'); ?><input type="hidden" name="enabled" value="<?= $membership['notifications_enabled']?'0':'1' ?>"><button type="submit"><?= $membership['notifications_enabled']?'Pause notifications':'Enable notifications' ?></button></form><form action="creator-club-action.php" method="post" data-club-form class="club-inline"><?php az_creator_club_form_hidden('unfollow',$creatorId,null,'mine'); ?><button type="submit" class="club-quiet">Unfollow</button></form></div><?php endif; ?>
<?php if(!empty($membership['access'])): ?><details class="club-disclosure"><summary>View access history <span><?= count($membership['access']) ?> <?= count($membership['access'])===1?'record':'records' ?></span></summary><div class="club-disclosure-body"><p class="club-help">These records describe access only. They are not payment receipts or active paid subscriptions.</p><?php foreach($membership['access'] as $access): ?><div class="club-access-record"><div><strong><?= az_h((string)$access['tier_title']) ?></strong><p><?= az_h($statusLabels[$access['status']]??ucwords(str_replace('_',' ',(string)$access['status']))) ?> · <?= az_h($access['origin']==='admin_test'?'Staff test grant':(string)$access['origin']) ?></p></div><div><small>Starts <?= az_h(substr((string)$access['begins_at'],0,10)) ?><br>Ends <?= az_h(substr((string)$access['ends_at'],0,10)) ?></small><?php if($access['revoked_at']): ?><small>Revoked <?= az_h(substr((string)$access['revoked_at'],0,10)) ?></small><?php endif; ?></div></div><?php endforeach; ?></div></details><?php endif; ?>
</article><?php endforeach; ?>
</div></section><p class="club-footnote">Subscription payments are not enabled. There is no billing account to manage or recurring payment to cancel in this release.</p>
<?php az_creator_club_page_end(); ?>
