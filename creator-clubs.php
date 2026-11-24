<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/universal-header.php';
require_once __DIR__.'/includes/member-layout.php';
require_once __DIR__.'/includes/creator-club-view.php';
$viewer=az_require_auth();
$q=az_db()->query("SELECT u.id,u.name FROM users u JOIN user_roles r ON r.user_id=u.id LEFT JOIN user_social_profiles s ON s.user_id=u.id WHERE u.status='active' AND r.role='model' AND (s.visibility='public' OR s.visibility='members') ORDER BY u.id DESC LIMIT 80");
$creators=[];
foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
    $member=az_account_user((int)$row['id']);$social=az_social_row((int)$row['id']);
    if($member&&az_social_visible($social,$viewer,$member))$creators[]=[
        'id'=>(int)$row['id'],'name'=>az_social_name($member),'tagline'=>$social['tagline'],
        'avatar'=>!empty($social['avatar_storage_name']),'following'=>az_creator_club_is_following($viewer,(int)$row['id']),
    ];
}
az_creator_club_page_start('Explore Creator Clubs','creator-clubs',$viewer);
az_creator_club_navigation('explore',$viewer);
?>
<header class="club-hero club-hero-split"><div><p class="club-eyebrow">YOUR CREATORS. A LITTLE CLOSER.</p><h1>Find your<br>favorite club<span>.</span></h1><p>Follow creators for free, catch their approved updates, and keep the clubs you love together in one place.</p><a class="club-primary" href="creator-club-workspace.php">Open My Clubs <span aria-hidden="true">↗</span></a></div><div class="club-hero-note"><span aria-hidden="true" class="club-hero-mark">♡</span><strong>Made for connection</strong><p>Free follows are open now. Paid memberships are being prepared; subscription payments are not enabled.</p></div></header>
<section class="club-section"><header><div><p class="club-eyebrow">EXPLORE AZHOTTIE</p><h2>Meet the creators</h2></div><span class="club-muted"><?= count($creators) ?> <?= count($creators)===1?'club':'clubs' ?></span></header><div class="club-grid">
<?php foreach($creators as $creator): ?><article class="club-card club-creator-card"><div class="club-card-top"><span class="club-avatar" aria-hidden="true"><?php if($creator['avatar']): ?><img src="user-media.php?u=<?= $creator['id'] ?>&amp;type=avatar" alt="" loading="lazy"><?php else: ?><?= az_h(strtoupper(substr($creator['name'],0,1))) ?><?php endif; ?></span><?php if($creator['following']): ?><span class="club-badge club-badge-approved">Following</span><?php endif; ?></div><h3><?= az_h($creator['name']) ?></h3><p><?= az_h($creator['tagline']?:'Creator updates, behind the scenes, and a place to stay connected.') ?></p><a class="club-secondary" href="creator-club.php?creator=<?= $creator['id'] ?>">View club <span aria-hidden="true">↗</span></a></article><?php endforeach; ?>
<?php if(!$creators): ?><div class="club-empty"><h3>New connections are on the way</h3><p>Creators will appear here when they share their profiles with members.</p><?php if(az_has_role($viewer,'model')): ?><a href="account-settings.php">Review your profile visibility</a><?php endif; ?></div><?php endif; ?>
</div></section>
<?php az_creator_club_page_end(); ?>
