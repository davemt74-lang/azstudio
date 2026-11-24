<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/universal-header.php';
require_once __DIR__.'/includes/member-layout.php';
$viewer=az_require_auth();
$q=az_db()->query("SELECT u.id,u.name FROM users u JOIN user_roles r ON r.user_id=u.id LEFT JOIN user_social_profiles s ON s.user_id=u.id WHERE u.status='active' AND r.role='model' AND (s.visibility='public' OR s.visibility='members') ORDER BY u.id DESC LIMIT 80");
$creators=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
 $member=az_account_user((int)$row['id']);$social=az_social_row((int)$row['id']);
 if($member&&az_social_visible($social,$viewer,$member))$creators[]=['id'=>(int)$row['id'],'name'=>az_social_name($member),'tagline'=>$social['tagline']];
}
header('Cache-Control: private, no-store');header('X-Robots-Tag: noindex,nofollow');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Explore Creator Clubs · AZHottie</title><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/member-layout.css"><link rel="stylesheet" href="assets/creator-club.css?v=1.55.0"></head><body><?php az_render_universal_header('creator-clubs',$viewer);az_render_member_layout_open('creator-clubs',$viewer); ?><main class="azh-club"><header class="club-hero"><p class="club-eyebrow">MEET THE CREATORS</p><h1>Creator Clubs<span>.</span></h1><p>Follow your favorite creators for free. Paid memberships will be available only after payment processing and access requirements are approved.</p></header><section class="club-section"><h2>Explore models</h2><div class="club-grid"><?php foreach($creators as $c): ?><article class="club-card"><p class="club-eyebrow">AZHOTTIE CREATOR</p><h3><?= az_h($c['name']) ?></h3><p><?= az_h($c['tagline']) ?></p><a class="club-primary" href="creator-club.php?creator=<?= $c['id'] ?>">View club</a></article><?php endforeach; ?><?php if(!$creators):?><p class="club-empty">No creators have made a profile visible to members yet.</p><?php endif; ?></div></section></main><?php az_render_member_layout_close(); ?></body></html>
