<?php
declare(strict_types=1);
/** Shared presentation for Creator Clubs. Authorization remains in the model. */
function az_creator_club_page_start(string $title,string $route,array $viewer):void
{
    header('Cache-Control: private, no-store');
    header('X-Robots-Tag: noindex,nofollow');
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?= az_h($title) ?> · AZHottie</title><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/member-layout.css?v=1.57.1"><link rel="stylesheet" href="assets/creator-club.css?v=1.59.0"></head><body data-page="<?= az_h($route) ?>"><?php
    az_render_universal_header($route,$viewer);
    az_render_member_layout_open($route,$viewer);
    ?><main class="azh-club" id="azh-creator-club"><?php
}
function az_creator_club_page_end():void
{
    ?></main><?php az_render_member_layout_close(); ?><script defer src="assets/creator-club.js?v=1.59.0"></script></body></html><?php
}
function az_creator_club_navigation(string $current,array $viewer):void
{
    $links=[['creator-clubs.php','Explore clubs','explore'],['creator-club-workspace.php','My Clubs','mine']];
    if(az_has_role($viewer,'model'))$links[]=['creator-club.php','Manage my club','manage'];
    if(az_has_role($viewer,'admin'))$links[]=['creator-club-admin.php','Membership reviews','review'];
    ?><nav class="club-tabs" aria-label="Creator Club pages"><?php foreach($links as [$url,$label,$key]): ?><a href="<?= az_h($url) ?>"<?= $key===$current?' aria-current="page"':'' ?>><?= az_h($label) ?></a><?php endforeach; ?></nav><?php
}
function az_creator_club_form_hidden(string $action,int $creator=0,?array $row=null,string $return='club'):void
{
    ?><input type="hidden" name="csrf" value="<?= az_h(az_csrf_token()) ?>"><input type="hidden" name="action" value="<?= az_h($action) ?>"><input type="hidden" name="creator" value="<?= $creator ?>"><input type="hidden" name="return" value="<?= az_h($return) ?>"><?php
    if($row): ?><input type="hidden" name="id" value="<?= az_h((string)$row['id']) ?>"><input type="hidden" name="revision" value="<?= (int)($row['revision']??0) ?>"><?php endif;
}
function az_creator_club_feedback(array $row):void
{
    if(trim((string)($row['review_note']??''))==='')return;
    ?><div class="club-feedback"><strong>Staff feedback</strong><p><?= nl2br(az_h((string)$row['review_note'])) ?></p></div><?php
}
function az_creator_club_tier_fields(array $row=[]):void
{
    ?><label>Tier name<input name="title" required maxlength="100" value="<?= az_h((string)($row['title']??'')) ?>" placeholder="Behind the scenes"></label><label>Monthly price, USD (proposal only)<input name="price" required type="number" step="0.01" min="1" max="500" value="<?= number_format(((int)($row['monthly_cents']??1000))/100,2,'.','') ?>"></label><label class="club-wide">Benefits<textarea name="description" maxlength="1200" rows="3" placeholder="What members would receive"><?= az_h((string)($row['description']??'')) ?></textarea></label><?php
}
function az_creator_club_post_fields(array $tiers,array $media,array $row=[]):void
{
    $audience=$row['audience']??'followers';
    ?><label>Who can see this post?<select name="audience" data-club-audience><option value="followers"<?= $audience==='followers'?' selected':'' ?>>Free followers</option><option value="tier"<?= $audience==='tier'?' selected':'' ?>>One approved membership tier</option></select></label>
    <label data-club-tier-field>Membership tier<select name="tier_id"<?= $audience==='tier'?' required':'' ?>><option value="">Choose a tier for tier-only posts</option><?php foreach($tiers as $tier):if($tier['state']!=='approved'||!empty($tier['archived']))continue; ?><option value="<?= az_h($tier['id']) ?>"<?= ($row['tier_id']??'')===$tier['id']?' selected':'' ?>><?= az_h($tier['title']) ?></option><?php endforeach; ?></select><span class="club-help">Free follower posts do not need a tier.</span></label>
    <label class="club-wide">Post title<input name="title" maxlength="160" required value="<?= az_h((string)($row['title']??'')) ?>"></label><label class="club-wide">Update<textarea name="body" maxlength="6000" rows="4"><?= az_h((string)($row['body']??'')) ?></textarea></label><label class="club-wide">Private video from your media library (optional)<select name="media_id"><option value="">Text only</option><?php foreach($media as $item):if(!str_starts_with((string)$item['mimeType'],'video/'))continue; ?><option value="<?= az_h($item['id']) ?>"<?= ($row['media_id']??'')===$item['id']?' selected':'' ?>><?= az_h($item['title']) ?></option><?php endforeach; ?></select></label>
    <label class="club-rights club-wide"><input type="checkbox" name="rights_confirmed" value="1" required> <span>I confirm I own or have permission to share this content, and all participants have consented to this use.</span></label><?php
}
function az_creator_club_notice():void
{
    if(isset($_GET['done'])): ?><p role="status" class="club-notice club-success">Your change was saved.</p><?php endif;
}
