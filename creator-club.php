<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/universal-header.php';
require_once __DIR__.'/includes/member-layout.php';
$viewer=az_require_auth();
$id=filter_var($_GET['creator']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$creatorId=$id?:((az_has_role($viewer,'model'))?(int)$viewer['id']:0);
try{
    $creator=az_creator_club_creator($creatorId);
    $target=az_account_user($creatorId);$social=az_social_row($creatorId);
    if(!az_social_visible($social,$viewer,$target))throw new AzHttpError(404,'Creator profile is not shared with you.','not_found');
}catch(AzHttpError $e){http_response_code(404);echo '<main><h1>Creator not available</h1><a href="watch.php">Explore videos</a></main>';exit;}
$owner=(int)$viewer['id']===$creatorId&&az_has_role($viewer,'model');
$admin=az_has_role($viewer,'admin');
$tiers=az_creator_club_tiers($creatorId,$owner||$admin);
$posts=az_creator_club_posts($creatorId,$viewer);
$following=az_creator_club_is_following($viewer,$creatorId);
$q=az_db()->prepare('SELECT COUNT(*) FROM creator_club_follows WHERE creator_id=?');$q->execute([$creatorId]);$followers=(int)$q->fetchColumn();
$media=$owner?az_personal_media_list($creatorId):[];
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex,nofollow');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?= az_h(az_social_name($target)) ?> · Creator Club · AZHottie</title><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/member-layout.css"><link rel="stylesheet" href="assets/creator-club.css?v=1.55.0"></head><body data-page="creator-club">
<?php az_render_universal_header('creator-club',$viewer);az_render_member_layout_open('creator-club',$viewer); ?>
<main class="azh-club" id="azh-creator-club">
 <header class="club-hero"><p class="club-eyebrow">AZHOTTIE · CREATOR CLUB</p><h1><?= az_h(az_social_name($target)) ?>'s Club<span>.</span></h1><p>Follow this creator for free. Subscription tiers and exclusive media are being prepared for an approved billing launch.</p><div class="club-stat"><strong><?= $followers ?></strong> followers <a href="user.php?id=<?= $creatorId ?>">View creator profile</a></div>
 <?php if(!$owner): ?><form method="post" action="creator-club-action.php" class="club-inline"><input type="hidden" name="csrf" value="<?= az_h(az_csrf_token()) ?>"><input type="hidden" name="creator" value="<?= $creatorId ?>"><input type="hidden" name="action" value="<?= $following?'unfollow':'follow' ?>"><button type="submit" class="club-primary"><?= $following?'Following · Unfollow':'Follow for free' ?></button></form><?php endif; ?>
 </header>
 <?php if(isset($_GET['done'])): ?><p role="status" class="club-notice">Your change was saved.</p><?php endif; ?>
 <section class="club-section" id="club-tiers"><header><div><p class="club-eyebrow">MEMBERSHIP OPTIONS</p><h2>Creator subscriptions</h2></div><small>Payments are not enabled</small></header>
 <div class="club-grid">
 <?php foreach($tiers as $tier): ?><article class="club-card"><span class="club-eyebrow"><?= az_h(strtoupper($tier['state'])) ?></span><h3><?= az_h($tier['title']) ?></h3><strong>$<?= number_format(((int)$tier['monthly_cents'])/100,2) ?><span>/month · proposed</span></strong><p><?= nl2br(az_h($tier['description'])) ?></p><button type="button" disabled aria-disabled="true">Subscription checkout not available</button></article><?php endforeach; ?>
 <?php if(!$tiers): ?><div class="club-empty"><h3>Membership tiers coming soon</h3><p>You can follow this creator for updates while membership plans are reviewed.</p></div><?php endif; ?>
 </div>
 </section>
 <?php if($owner): ?>
 <section class="club-section"><header><div><p class="club-eyebrow">CREATOR TOOLS</p><h2>Propose a subscription tier</h2></div></header>
 <form class="club-form" action="creator-club-action.php" method="post"><input type="hidden" name="csrf" value="<?= az_h(az_csrf_token()) ?>"><input type="hidden" name="action" value="tier"><input type="hidden" name="creator" value="<?= $creatorId ?>">
 <label>Tier name<input name="title" required maxlength="100" placeholder="Behind the scenes"></label><label>Monthly price, USD (proposal only)<input name="price" required type="number" step="1" min="1" max="500" value="10"></label><label>Benefits<textarea name="description" maxlength="1200" rows="3" placeholder="What members would receive"></textarea></label><button class="club-primary" type="submit">Submit tier for review</button><p>No charges are made. An administrator must approve the proposed tier.</p></form>
 </section>
 <section class="club-section"><header><div><p class="club-eyebrow">MEMBERS-ONLY LIBRARY</p><h2>Submit an exclusive post</h2></div></header>
 <form class="club-form" action="creator-club-action.php" method="post"><input type="hidden" name="csrf" value="<?= az_h(az_csrf_token()) ?>"><input type="hidden" name="action" value="post"><input type="hidden" name="creator" value="<?= $creatorId ?>">
 <label>Approved tier<select name="tier_id" required><option value="">Choose one</option><?php foreach($tiers as $tier):if($tier['state']!=='approved')continue;?><option value="<?= az_h($tier['id']) ?>"><?= az_h($tier['title']) ?></option><?php endforeach; ?></select></label>
 <label>Post title<input name="title" maxlength="160" required></label><label>Subscriber update<textarea name="body" maxlength="6000" rows="4"></textarea></label><label>Private video from your media library (optional)<select name="media_id"><option value="">Text only</option><?php foreach($media as $m):if(!str_starts_with((string)$m['mimeType'],'video/'))continue;?><option value="<?= az_h($m['id']) ?>"><?= az_h($m['title']) ?></option><?php endforeach; ?></select></label>
 <label class="club-rights"><input type="checkbox" name="rights_confirmed" value="1" required> I confirm I own or have permission to share this content, and all participants have consented to this use.</label><button class="club-primary" type="submit">Send post for staff review</button><p>Private files are never published to Watch or exposed by their original media URLs. You can withdraw a post at any time.</p></form>
 </section>
 <?php endif; ?>
 <section class="club-section" id="club-posts"><header><div><p class="club-eyebrow">THE COMMUNITY</p><h2><?= $owner?'My exclusive posts':'Member updates' ?></h2></div></header>
 <?php if(!$posts): ?><p class="club-empty">No posts are available for your membership access yet.</p><?php endif; ?>
 <?php foreach($posts as $post): ?><article class="club-post"><div><span class="club-eyebrow"><?= $owner||$admin?az_h(strtoupper($post['state'])):'MEMBERS ONLY' ?></span><h3><?= az_h($post['title']) ?></h3><small><?= az_h(substr($post['created_at'],0,10)) ?></small></div>
 <p><?= nl2br(az_h($post['body'])) ?></p><?php if($post['media_id']): ?><video controls playsinline preload="metadata" src="creator-club-media.php?post=<?= az_h($post['id']) ?>" aria-label="<?= az_h($post['title']) ?>"></video><?php endif; ?>
 <?php if($owner && in_array($post['state'],['approved','pending'],true)): ?><form class="club-inline" method="post" action="creator-club-action.php"><input type="hidden" name="csrf" value="<?= az_h(az_csrf_token()) ?>"><input type="hidden" name="action" value="withdraw"><input type="hidden" name="creator" value="<?= $creatorId ?>"><input type="hidden" name="id" value="<?= az_h($post['id']) ?>"><input type="hidden" name="revision" value="<?= (int)$post['revision'] ?>"><button type="submit">Withdraw post</button></form><?php endif; ?>
 </article><?php endforeach; ?>
 </section>
 <?php if($admin):?><a class="club-primary" href="creator-club-admin.php">Open membership review queue</a><?php endif; ?>
 </main><?php az_render_member_layout_close(); ?></body></html>
