<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/universal-header.php';
if (!az_is_installed()) az_redirect('install.php');
$viewer=az_current_user();
$handle=isset($_GET['handle'])&&is_string($_GET['handle'])?strtolower($_GET['handle']):null;
$id=isset($_GET['id'])&&ctype_digit((string)$_GET['id'])?(int)$_GET['id']:($viewer?(int)$viewer['id']:0);
if ($id===0 && $handle===null) az_redirect('login.php');
$target=az_social_target($handle,$id);
$profile=$target?az_social_row((int)$target['id']):null;
if (!$target || !$profile || !az_social_visible($profile,$viewer,$target)) {
    http_response_code(404);
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><base href="<?= az_h(az_url('')) ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Profile unavailable · AZHottie</title><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/social.css"></head><body><main class="social-missing"><h1>Profile unavailable</h1><p>This member hasn't shared a profile with you.</p><a href="<?= $viewer?'dashboard.php':'index.php' ?>">Back to AZHottie</a></main></body></html><?php exit;
}
az_member_profile_record_view($target,$profile,$viewer);
$owner=$viewer && (int)$viewer['id']===(int)$target['id'];
$display=az_social_name($target);
$videos=az_social_videos((int)$target['id']);
$tagline=trim((string)$profile['tagline']);
$bio=trim((string)$profile['bio']);
$specialties=trim((string)$profile['specialties']);
if ($bio==='') {
    $model=az_member_profile((int)$target['id']);
    // A linked casting biography can be shown to the owner; it is not public
    // merely because this social page is viewable. Explicit social bio only.
    if ($owner && $model && !empty($model['publicBio'])) $bio=(string)$model['publicBio'];
}
$avatar=empty($profile['avatar_storage_name'])?'':az_url('user-media.php?u='.(int)$target['id'].'&type=avatar');
$cover=empty($profile['cover_storage_name'])?'':az_url('user-media.php?u='.(int)$target['id'].'&type=cover');
$share=empty($profile['handle'])?'user.php?id='.(int)$target['id']:'user/'.rawurlencode((string)$profile['handle']);
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if ($profile['visibility']!=='public') header('X-Robots-Tag: noindex, nofollow');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><base href="<?= az_h(az_url('')) ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#ffffff"><title><?= az_h($display) ?> · AZHottie</title><link rel="icon" href="assets/favicon.svg"><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/social.css"></head><body class="azh-social-profile-body" data-page="user">
<?php az_render_universal_header('user',$viewer); ?>
<main class="social-profile-page" id="social-profile" <?= $owner?'data-owner="true" data-csrf="'.az_h(az_csrf_token()).'"':'' ?>>
  <section class="social-profile-card" aria-label="AZHottie member profile">
    <div class="social-cover" <?= $cover?'style="background-image:url(\''.az_h($cover).'\')"':'' ?>>
      <?php if($owner): ?><label class="social-edit-cover"> <input type="file" data-image-kind="cover" accept="image/jpeg,image/png,image/webp" aria-label="Change cover photo"><span> <span aria-hidden="true">▧</span> Edit cover photo</span></label><?php endif; ?>
    </div>
    <div class="social-profile-head"><div class="social-avatar-wrap"><div class="social-avatar" aria-label="<?= az_h($display) ?> profile picture"><?php if($avatar):?><img src="<?= az_h($avatar) ?>" alt=""><?php else: ?><span aria-hidden="true"><?= az_h(strtoupper(substr($display,0,1))) ?></span><?php endif; ?></div><?php if($owner):?><label class="social-edit-avatar" title="Change profile picture"><input type="file" data-image-kind="avatar" accept="image/jpeg,image/png,image/webp" aria-label="Change profile picture"><span aria-hidden="true">✎</span></label><?php endif;?></div>
      <div class="social-identity"><p class="social-kicker">AZHOTTIE MEMBER</p><h1><?= az_h($display) ?></h1><p class="social-handle"><?= $profile['handle']?'@'.az_h((string)$profile['handle']):'AZHottie member #'.(int)$target['id'] ?></p><?php if($tagline):?><p class="social-tagline"><?= az_h($tagline) ?></p><?php endif; ?><div class="social-role-badges"><?php foreach($target['roles'] as $role):?><span><?= az_h(ucfirst($role)) ?></span><?php endforeach;?></div></div>
      <div class="social-profile-actions"><?php if($owner):?><a class="social-primary" href="account-settings.php#social-profile-settings">Edit profile</a><a class="social-outline" href="<?= az_h($share) ?>">View profile link</a><?php elseif(in_array('professional',$target['roles'],true)):?><a class="social-primary" href="<?= $viewer?'appointments.php':'signup.php' ?>">Explore appointments</a><?php else: ?><a class="social-outline" href="watch.php">Watch AZHottie</a><?php endif; ?></div>
    </div>
  </section>
  <?php if($owner):?><p class="social-upload-feedback" id="social-upload-feedback" role="status" aria-live="polite"></p><?php endif; ?>
  <div class="social-profile-grid"><div class="social-main-column">
    <nav class="social-tabs" aria-label="Profile sections"><a href="#social-posts">Videos <span><?= count($videos) ?></span></a><a href="#social-about">About</a></nav>
    <section id="social-posts" class="social-posts-section"><div class="social-section-heading"><div><p class="social-eyebrow">FROM THE STUDIO</p><h2>Published videos</h2></div><a href="watch.php">Explore Watch</a></div>
      <?php if($videos):?><div class="social-video-grid"><?php foreach($videos as $v):?><a class="social-video-tile" href="watch.php?id=<?= az_h($v['id']) ?>"><?php if(!empty($v['cover_storage_name'])):?><img loading="lazy" src="public-media.php?id=<?= az_h($v['id']) ?>&amp;type=cover" alt=""><?php else:?><div class="social-video-fallback"><span>▶</span></div><?php endif; ?><div><strong><?= az_h((string)($v['fan_title']?:'AZHottie video')) ?></strong><span><?= az_h(substr((string)($v['fan_description']??''),0,110)) ?></span></div></a><?php endforeach; ?></div><?php else:?><div class="social-empty-feed"><span aria-hidden="true">▣</span><h3>No public videos yet</h3><p><?= $owner?'Published AZHottie videos associated with your model profile will appear here. Your private uploads will not appear automatically.':'Check back for new AZHottie productions.' ?></p><?php if($owner && (in_array('model',$target['roles'],true)||in_array('professional',$target['roles'],true))):?><a href="my-media.php">Open my private gallery</a><?php endif; ?></div><?php endif; ?>
    </section>
  </div><aside class="social-side-column" id="social-about"><section class="social-about-card"><p class="social-eyebrow">GET TO KNOW ME</p><h2>About</h2><p class="social-about-text"><?= $bio?nl2br(az_h($bio)):($owner?'Tell your story in Account Settings.':'This member has not added a bio yet.') ?></p></section>
  <?php if($specialties):?><section class="social-about-card"><p class="social-eyebrow">WHAT I DO</p><h2>Specialties &amp; interests</h2><p class="social-about-text"><?= nl2br(az_h($specialties)) ?></p></section><?php endif; ?>
  <?php if($owner):?><section class="social-about-card social-private-note"><p class="social-eyebrow">PROFILE VISIBILITY</p><h2><?= az_h(ucfirst((string)$profile['visibility'])) ?></h2><p>Choose who sees your profile in Account Settings. Personal footage, appointments, private shoots and casting records are never displayed here.</p><a href="account-settings.php#social-profile-settings">Manage visibility</a></section><?php endif; ?>
  </aside></div>
</main>
<script type="module" src="assets/social-profile.js"></script></body></html>
