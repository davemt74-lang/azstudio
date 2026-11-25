<?php
declare(strict_types=1);
/** Shared presentation for Creator Clubs. Authorization remains in the model. */
function az_creator_club_page_start(string $title,string $route,array $viewer):void
{
    header('Cache-Control: private, no-store');
    header('X-Robots-Tag: noindex,nofollow');
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?= az_h($title) ?> · AZHottie</title><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/member-layout.css?v=1.57.1"><link rel="stylesheet" href="assets/creator-club.css?v=1.60.0"></head><body data-page="<?= az_h($route) ?>"><?php
    az_render_universal_header($route,$viewer);
    az_render_member_layout_open($route,$viewer);
    ?><main class="azh-club" id="azh-creator-club"><?php
}
function az_creator_club_page_end():void
{
    ?></main><?php az_render_member_layout_close(); ?><script defer src="assets/creator-club.js?v=1.60.0"></script></body></html><?php
}
function az_creator_club_navigation(string $current,array $viewer):void
{
    $links=[['creator-clubs.php','Explore clubs','explore'],['creator-club-workspace.php','My Clubs','mine']];
    if(az_has_role($viewer,'model'))$links[]=['creator-club.php','Manage my club','manage'];
    if(az_has_role($viewer,'admin'))$links[]=['creator-club-admin.php','Membership reviews','review'];
    ?><nav class="club-tabs" aria-label="Creator Club pages"><?php foreach($links as [$url,$label,$key]): ?><a href="<?= az_h($url) ?>"<?= $key===$current?' aria-current="page"':'' ?>><?= az_h($label) ?></a><?php endforeach; ?></nav><?php
}
function az_creator_club_request_value(string $name,string $default=''):string
{
    return isset($_GET[$name])&&is_string($_GET[$name])?trim($_GET[$name]):$default;
}
function az_creator_club_link(string $path,array $values=[],string $fragment=''):string
{
    $values=array_filter($values,static fn($value):bool=>$value!==''&&$value!==null);
    return $path.($values?'?'.http_build_query($values,'','&',PHP_QUERY_RFC3986):'').($fragment!==''?'#'.$fragment:'');
}
function az_creator_club_form_hidden(string $action,int $creator=0,?array $row=null,string $return='club'):void
{
    ?><input type="hidden" name="csrf" value="<?= az_h(az_csrf_token()) ?>"><input type="hidden" name="action" value="<?= az_h($action) ?>"><input type="hidden" name="creator" value="<?= $creator ?>"><input type="hidden" name="return" value="<?= az_h($return) ?>"><?php
    if($row): ?><input type="hidden" name="id" value="<?= az_h((string)$row['id']) ?>"><input type="hidden" name="revision" value="<?= (int)($row['revision']??0) ?>"><?php endif;
}
function az_creator_club_post_state(string $state):string
{
    return ['draft'=>'Draft','pending'=>'In review','scheduled'=>'Scheduled','approved'=>'Published','rejected'=>'Rejected','withdrawn'=>'Withdrawn'][$state]??ucfirst($state);
}
function az_creator_club_display_date(string $date,string $format='M j, Y'):string
{
    try{return (new DateTimeImmutable($date,new DateTimeZone('UTC')))->format($format);}catch(Throwable $e){return '';}
}
function az_creator_club_schedule_label(array $row):string
{
    if(empty($row['publish_at']))return 'After staff approval';
    try{
        $timezone=(string)($row['publish_timezone']??'America/Phoenix');
        return (new DateTimeImmutable((string)$row['publish_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone))->format('M j, Y · g:i A').' · '.$timezone;
    }catch(Throwable $e){return 'Review the scheduled release time';}
}
function az_creator_club_feedback(array $row):void
{
    if(trim((string)($row['review_note']??''))==='')return;
    ?><div class="club-feedback"><strong>Staff feedback</strong><p><?= nl2br(az_h((string)$row['review_note'])) ?></p></div><?php
}
function az_creator_club_publishing_status(bool $admin=false):void
{
    $status=az_creator_club_publish_status();
    $state=(string)($status['status']??'not_run');
    $expectedInterval=max(1,min(60,(int)($status['expectedIntervalMinutes']??15)));
    $messages=[
        'not_run'=>['Automatic publishing not yet verified','The publishing job has not completed a recorded run. Approved scheduled posts stay private until the job runs or staff releases due posts.'],
        'running'=>['Scheduled publishing is running','The publishing job is checking approved posts whose release time has arrived.'],
        'ok'=>['Scheduled publishing is active','The publishing job completed its latest check. Only approved posts are eligible to be released.'],
        'attention'=>['Scheduled publishing needs attention','Some scheduled posts could not be released. They remain private; staff can review the issue and retry.'],
        'stale'=>['Scheduled publishing needs a check','The publishing job has not reported a recent completed run. Scheduled posts remain private until they are released.'],
    ];
    [$heading,$message]=$messages[$state]??$messages['not_run'];
    ?><aside class="club-publishing-status<?= $state==='ok'?' is-active':'' ?>" data-club-publishing-status="<?= az_h($state) ?>"><div><strong><?= az_h($heading) ?></strong><p><?= az_h($message) ?></p><?php if(!empty($status['completedAt'])): ?><small>Last automatic check: <?= az_h(az_creator_club_display_date((string)$status['completedAt'],'M j, Y · g:i A')) ?> UTC.</small><?php endif; ?><?php if($admin): ?><small>Expected automatic check: every <?= $expectedInterval ?> <?= $expectedInterval===1?'minute':'minutes' ?>.</small><?php endif; ?></div><?php if($admin): ?><form method="post" action="creator-club-action.php" data-club-form class="club-inline"><?php az_creator_club_form_hidden('publishDue'); ?><button type="submit">Publish due posts now</button><span class="club-help">Runs one manual check of approved posts. This does not verify the automatic job.</span></form><?php endif; ?></aside><?php
}
function az_creator_club_tier_fields(array $row=[]):void
{
    ?><label>Tier name<input name="title" required maxlength="100" value="<?= az_h((string)($row['title']??'')) ?>" placeholder="Behind the scenes"></label><label>Monthly price, USD (proposal only)<input name="price" required type="number" step="0.01" min="1" max="500" value="<?= number_format(((int)($row['monthly_cents']??1000))/100,2,'.','') ?>"></label><label class="club-wide">Benefits<textarea name="description" maxlength="1200" rows="3" placeholder="What members would receive"><?= az_h((string)($row['description']??'')) ?></textarea></label><?php
}
function az_creator_club_post_fields(array $tiers,array $media,array $row=[]):void
{
    $audience=$row['audience']??'followers';
    $timezone=(string)($row['form_timezone']??$row['publish_timezone']??'America/Phoenix');
    $publishLocal='';
    if(array_key_exists('form_local',$row))$publishLocal=(string)$row['form_local'];
    elseif(!empty($row['publish_at'])){
        try{$publishLocal=(new DateTimeImmutable((string)$row['publish_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d\TH:i');}catch(Throwable $e){$timezone='America/Phoenix';}
    }
    $localType='datetime-local';
    if($publishLocal!==''){
        $localDate=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$publishLocal,new DateTimeZone('UTC'));
        if(!$localDate||$localDate->format('Y-m-d\TH:i')!==$publishLocal)$localType='text';
    }
    $selectedTier=(string)($row['tier_id']??'');$selectedMedia=(string)($row['media_id']??'');
    $availableTiers=array_filter($tiers,static fn(array $tier):bool=>$tier['state']==='approved'&&empty($tier['archived']));
    $availableMedia=array_filter($media,static fn(array $item):bool=>str_starts_with((string)$item['mimeType'],'video/'));
    $zones=['America/Phoenix'=>'Arizona · America/Phoenix','America/Los_Angeles'=>'Pacific · America/Los_Angeles','America/Denver'=>'Mountain · America/Denver','America/Chicago'=>'Central · America/Chicago','America/New_York'=>'Eastern · America/New_York','UTC'=>'UTC'];
    if(!isset($zones[$timezone]))$zones[$timezone]=$timezone!==''?$timezone:'Choose a time zone';
    ?><label class="club-wide">Post title<input name="title" maxlength="160" required value="<?= az_h((string)($row['title']??'')) ?>" placeholder="Give your update a title"></label><label class="club-wide">Update<textarea name="body" aria-label="Update" maxlength="6000" rows="5" placeholder="Share a moment, a note, or what you’re working on."><?= az_h((string)($row['body']??'')) ?></textarea><span class="club-help">Text, a private video, or both. Drafts can be unfinished.</span></label>
    <label>Who can see this post?<select name="audience" data-club-audience><option value="followers"<?= $audience==='followers'?' selected':'' ?>>Free followers</option><option value="tier"<?= $audience==='tier'?' selected':'' ?>>One approved membership tier</option><?php if(!in_array($audience,['followers','tier'],true)): ?><option value="<?= az_h((string)$audience) ?>" selected>Original audience is invalid; choose an audience</option><?php endif; ?></select></label>
    <label data-club-tier-field>Membership tier<select name="tier_id"<?= $audience==='tier'?' required':'' ?>><option value="">Choose an approved tier</option><?php foreach($availableTiers as $tier): ?><option value="<?= az_h($tier['id']) ?>"<?= $selectedTier===$tier['id']?' selected':'' ?>><?= az_h($tier['title']) ?></option><?php endforeach; ?><?php if($selectedTier!==''&&!in_array($selectedTier,array_column($availableTiers,'id'),true)): ?><option value="<?= az_h($selectedTier) ?>" selected>Previously selected tier is unavailable</option><?php endif; ?></select><span class="club-help">Access applies only to the selected tier.</span></label>
    <label class="club-wide">Private video from your media library (optional)<select name="media_id" data-club-media><option value="">Text only</option><?php foreach($availableMedia as $item): ?><option value="<?= az_h($item['id']) ?>" data-preview-url="<?= az_h('personal-media-file.php?id='.rawurlencode((string)$item['id'])) ?>"<?= $selectedMedia===$item['id']?' selected':'' ?>><?= az_h($item['title']) ?></option><?php endforeach; ?><?php if($selectedMedia!==''&&!in_array($selectedMedia,array_column($availableMedia,'id'),true)): ?><option value="<?= az_h($selectedMedia) ?>" selected>Previously selected video is unavailable</option><?php endif; ?></select><span class="club-help">Only videos from your own private media library are listed.</span></label>
    <div class="club-schedule-heading club-wide"><strong>Release timing</strong><p>Leave the time blank to publish after approval, or choose a future release. Staff must approve every submitted version. Approved scheduled posts release on the next automatic check after the requested time.</p></div>
    <label>Publish at (optional)<input type="<?= $localType ?>" aria-label="Publish at (optional)" name="publishLocal" value="<?= az_h($publishLocal) ?>"<?= $localType==='text'?' placeholder="YYYY-MM-DDTHH:MM"':'' ?>><span class="club-help"><?= $localType==='text'?'Correct the retained time using YYYY-MM-DDTHH:MM.':'Enter a date and time in the selected time zone.' ?></span></label><label>Time zone<select name="publishTimezone" aria-label="Time zone"><?php foreach($zones as $zone=>$label): ?><option value="<?= az_h($zone) ?>"<?= $zone===$timezone?' selected':'' ?>><?= az_h($label) ?></option><?php endforeach; ?></select><span class="club-help">Default: America/Phoenix (Arizona).</span></label>
    <label class="club-rights club-wide"><input type="checkbox" name="rights_confirmed" value="1" required<?= !empty($row['rights_confirmed'])?' checked':'' ?>> <span>I confirm I own or have permission to share this content, and all participants have consented to this use.</span></label><?php
}
/** Recover a rejected native POST without reading/rebasing the saved post revision. */
function az_creator_club_failed_post_form(array $user,array $submitted):void
{
    $creator=filter_var($submitted['creator']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!az_has_role($user,'model')||!$creator||$creator!==(int)$user['id'])return;
    $value=static function(string $name,string $fallback='',string $default='')use($submitted):string{
        $raw=$submitted[$name]??($fallback!==''?($submitted[$fallback]??$default):$default);
        if(is_string($raw)||is_int($raw)||is_bool($raw))return (string)$raw;
        return json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)?:'';
    };
    $row=['title'=>$value('title'),'body'=>$value('body'),'audience'=>$value('audience','','followers'),
        'tier_id'=>$value('tierId','tier_id'),'media_id'=>$value('mediaId','media_id'),
        'rights_confirmed'=>$value('rightsConfirmed','rights_confirmed')==='1',
        'form_local'=>$value('publishLocal'),'form_timezone'=>$value('publishTimezone','','America/Phoenix')];
    $tiers=[];$media=[];
    try{$tiers=az_creator_club_tiers((int)$user['id'],true);}catch(Throwable $e){}
    try{$media=az_personal_media_list((int)$user['id']);}catch(Throwable $e){}
    ?><section class="club-section" id="club-recovered-post"><header><div><p class="club-eyebrow">YOUR WORK IS STILL HERE</p><h2>Retained post entries</h2></div><a class="club-text-link" href="creator-club.php?creator=<?= (int)$user['id'] ?>#club-posts" target="_blank" rel="noopener">Open the saved version in another tab ↗</a></header><p class="club-muted">Your entries are retained below; if the saved version changed, open it in another tab before retrying.</p><form class="club-form" action="creator-club-action.php" method="post" data-club-form data-club-editor data-club-recovery><?php
    az_creator_club_form_hidden('postSave',(int)$user['id']);
    foreach(['id','revision'] as $key){
        if(!array_key_exists($key,$submitted))continue;
        // Invalid array-shaped identities stay invalid; they must never become a new post.
        $name=is_array($submitted[$key])?$key.'[]':$key;
        ?><input type="hidden" name="<?= az_h($name) ?>" value="<?= az_h($value($key)) ?>"><?php
    }
    az_creator_club_post_fields($tiers,$media,$row);az_creator_club_post_actions();
    ?></form></section><?php
}
function az_creator_club_post_actions():void
{
    ?><div class="club-editor-actions club-wide"><button type="submit" name="postIntent" value="submit" class="club-primary">Submit for review</button><button type="submit" name="postIntent" value="draft" class="club-secondary" formnovalidate>Save draft</button><button type="button" data-club-preview-open class="club-preview-button">Preview</button></div><p class="club-help club-wide">Saved drafts stay private to you. Submitting sends this version to staff. Scheduled posts stay private until approved and released.</p><section class="club-preview club-wide" data-club-preview role="region" aria-label="Post preview" tabindex="-1" hidden><div class="club-card-top"><span class="club-badge club-badge-draft">Private preview</span><button type="button" data-club-preview-close>Close preview</button></div><p class="club-help" data-preview-audience></p><h3 data-preview-title></h3><p class="club-preview-body" data-preview-body></p><video controls playsinline preload="none" data-preview-video hidden></video><p class="club-help" data-preview-media-error hidden>This private video could not be loaded. Your written preview is still available.</p><p class="club-preview-timing" data-preview-schedule></p><p class="club-help">This preview is not saved, submitted, or published.</p></section><?php
}
function az_creator_club_notice():void
{
    if(isset($_GET['done'])): ?><p role="status" class="club-notice club-success">Your change was saved.</p><?php endif;
}
