<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/universal-header.php';
require_once __DIR__.'/includes/member-layout.php';
require_once __DIR__.'/includes/creator-club-view.php';
$viewer=az_require_auth();
$search=az_creator_club_request_value('q');
$filter=az_creator_club_request_value('filter')==='following'?'following':'all';
$cursor=az_creator_club_request_value('cursor');
$pageError='';
try{$page=az_creator_club_discovery_page($viewer,['q'=>$search,'filter'=>$filter,'cursor'=>$cursor,'limit'=>12]);}
catch(AzHttpError $e){$pageError=$e->getMessage();$page=['creators'=>[],'nextCursor'=>null];}
$creators=$page['creators'];
az_creator_club_page_start('Explore Creator Clubs','creator-clubs',$viewer);
az_creator_club_navigation('explore',$viewer);
?>
<header class="club-hero club-hero-split"><div><p class="club-eyebrow">YOUR CREATORS. A LITTLE CLOSER.</p><h1>Find your<br>favorite club<span>.</span></h1><p>Follow creators for free, catch their updates, and keep the clubs you love together in one place.</p><a class="club-primary" href="creator-club-workspace.php">Open My Clubs <span aria-hidden="true">↗</span></a></div><div class="club-hero-note"><span aria-hidden="true" class="club-hero-mark">♡</span><strong>A place to stay connected</strong><p>Creative notes, fresh moments, and a closer look at the people you follow.</p><span class="club-badge">Free to follow</span></div></header>
<section class="club-section" id="club-discovery"><header><div><p class="club-eyebrow">EXPLORE AZHOTTIE</p><h2>Meet the creators</h2></div><span class="club-muted"><?= count($creators) ?> <?= count($creators)===1?'club':'clubs' ?> on this page</span></header>
<form class="club-search-form" action="creator-clubs.php" method="get" role="search"><label for="club-search">Search creators</label><div class="club-search-row"><input type="search" id="club-search" name="q" value="<?= az_h($search) ?>" placeholder="Search by name or tagline" maxlength="160"><input type="hidden" name="filter" value="<?= az_h($filter) ?>"><button class="club-primary" type="submit">Search</button></div></form>
<div class="club-filter-bar"><nav class="club-filter-tabs" aria-label="Creator discovery filter"><a href="<?= az_h(az_creator_club_link('creator-clubs.php',['q'=>$search,'filter'=>'all'],'club-discovery')) ?>"<?= $filter==='all'?' aria-current="page"':'' ?>>All creators</a><a href="<?= az_h(az_creator_club_link('creator-clubs.php',['q'=>$search,'filter'=>'following'],'club-discovery')) ?>"<?= $filter==='following'?' aria-current="page"':'' ?>>Following</a></nav><?php if($search!==''||$filter!=='all'): ?><a class="club-text-link" href="creator-clubs.php#club-discovery">Clear filters</a><?php endif; ?></div>
<?php if($pageError): ?><p class="club-notice" role="alert"><?= az_h($pageError) ?> <a href="<?= az_h(az_creator_club_link('creator-clubs.php',['q'=>$search,'filter'=>$filter],'club-discovery')) ?>">Return to the first page</a></p><?php elseif($cursor!==''): ?><p class="club-page-context">More creators <a href="<?= az_h(az_creator_club_link('creator-clubs.php',['q'=>$search,'filter'=>$filter],'club-discovery')) ?>">Back to the first page</a></p><?php endif; ?>
<div class="club-grid">
<?php foreach($creators as $creator): ?><article class="club-card club-creator-card" data-club-creator="<?= (int)$creator['id'] ?>"><div class="club-card-top"><span class="club-avatar" aria-hidden="true"><?php if($creator['avatar']): ?><img src="user-media.php?u=<?= (int)$creator['id'] ?>&amp;type=avatar" alt="" loading="lazy"><?php else: ?><?= az_h(strtoupper(substr((string)$creator['name'],0,1))) ?><?php endif; ?></span><?php if($creator['following']): ?><span class="club-badge club-badge-approved">Following</span><?php else: ?><span class="club-badge club-badge-light">Free to follow</span><?php endif; ?></div><h3><?= az_h($creator['name']) ?></h3><p><?= az_h($creator['tagline']?:'Creator updates, behind the scenes, and a place to stay connected.') ?></p><a class="club-secondary" href="creator-club.php?creator=<?= (int)$creator['id'] ?>">View club <span aria-hidden="true">↗</span></a></article><?php endforeach; ?>
<?php if(!$creators&&!$pageError): ?><div class="club-empty club-empty-wide"><span class="club-empty-symbol" aria-hidden="true">♡</span><h3><?= $search!==''?'No clubs match your search':($filter==='following'?'Find your first favorite':'New connections are on the way') ?></h3><p><?= $search!==''?'Try another name or a few words from a creator’s tagline.':($filter==='following'?'Creators you follow will appear here. Explore all creators to find a club you like.':'Creators will appear here when they share their profiles with members.') ?></p><?php if($search!==''||$filter!=='all'): ?><a class="club-secondary" href="creator-clubs.php#club-discovery">Explore all creators</a><?php elseif(az_has_role($viewer,'model')): ?><a href="account-settings.php">Review your profile visibility</a><?php endif; ?></div><?php endif; ?>
</div>
<?php if(!empty($page['nextCursor'])): ?><div class="club-pagination"><a class="club-secondary" rel="next" href="<?= az_h(az_creator_club_link('creator-clubs.php',['q'=>$search,'filter'=>$filter,'cursor'=>$page['nextCursor']],'club-discovery')) ?>">Load more creators <span aria-hidden="true">↓</span></a><p>Keep exploring at your own pace.</p></div><?php endif; ?>
</section><p class="club-footnote">Following is free. Subscription payments are not enabled.</p>
<?php az_creator_club_page_end(); ?>
