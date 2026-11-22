<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/universal-header.php';

$installed = az_is_installed();
$member = $installed ? az_current_user() : null;
$memberUrl = $installed ? ($member ? 'dashboard.php' : 'signup.php') : 'install.php';
$memberLabel = $installed ? ($member ? 'My workspace' : 'Become a member') : 'Set up AZHottie';
$eventUrl = $installed ? ($member ? 'calendar.php' : 'signup.php') : 'install.php';
$classUrl = $installed ? ($member ? 'classes.php' : 'signup.php') : 'install.php';
$spaUrl = $installed ? ($member ? 'appointments.php' : 'signup.php') : 'install.php';
$watchUrl = $installed ? 'watch.php' : 'install.php';
$liveUrl = $installed ? 'live-shows.php' : 'install.php';
$applyUrl = $installed ? 'apply.php' : 'install.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#fffaf9">
  <meta name="description" content="Step inside AZHottie Studio: original model-led stories, interviews, behind-the-scenes shoots, live shows, private experiences, and casting in Arizona.">
  <meta property="og:title" content="AZHottie Studio · Every shoot has a story">
  <meta property="og:description" content="Meet the personalities, follow the cameras, and discover the stories behind AZHottie Studio.">
  <meta property="og:type" content="website">
  <meta property="og:image" content="assets/hero-reality-white.webp">
  <title>AZHottie Studio · Every shoot has a story</title>
  <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
  <link rel="preload" as="image" href="assets/hero-reality-white.webp" media="(min-width: 641px)" fetchpriority="high">
  <link rel="preload" as="image" href="assets/hero-reality-white-mobile.webp" media="(max-width: 640px)" fetchpriority="high">
  <link rel="stylesheet" href="assets/universal-header.css?v=1.18.1">
  <link rel="stylesheet" href="assets/landing.css?v=1.29.1">
</head>
<body class="azh-home">
<a class="azh-home-skip" href="#main-content">Skip to main content</a>
<?php az_render_universal_header('index', $member, $installed); ?>
<main id="main-content">
  <section class="azh-home-opening" aria-labelledby="home-title">
    <div class="azh-home-masthead">
      <div class="azh-home-masthead-title">
        <p class="azh-home-eyebrow"><span aria-hidden="true" class="azh-home-spark">✳</span> AZHOTTIE ORIGINALS <span class="azh-home-dot" aria-hidden="true">•</span> ARIZONA</p>
        <h1 id="home-title">Every shoot<br>has a <em>story.</em></h1>
      </div>
      <div class="azh-home-masthead-copy">
        <p>Unscripted personalities. Unforgettable shoots. Get closer to the people, the cameras, and the moments that make AZHottie.</p>
        <div class="azh-home-actions">
          <a class="azh-home-button azh-home-button-primary" href="<?= az_h($watchUrl) ?>">Watch the stories <span aria-hidden="true">↗</span></a>
          <a class="azh-home-button azh-home-button-quiet" href="<?= az_h($memberUrl) ?>"><?= az_h($memberLabel) ?> <span aria-hidden="true">↗</span></a>
        </div>
      </div>
    </div>
    <figure class="azh-home-hero-media">
      <picture>
        <source media="(max-width: 640px)" srcset="assets/hero-reality-white-mobile.webp" type="image/webp">
        <img src="assets/hero-reality-white.webp" width="1754" height="896" alt="Five smiling adult models pose together in white styling on a bright studio set while a photographer takes their picture" fetchpriority="high" decoding="async">
      </picture>
      <figcaption class="azh-home-hero-caption"><span>AZHOTTIE / BEHIND THE CAMERA</span><span>ORIGINAL STORIES · REAL PERSONALITIES</span></figcaption>
    </figure>
    <nav class="azh-home-chapters" aria-label="Explore AZHottie">
      <a href="#the-show"><span>01 / THE SHOW</span><strong>Watch the stories</strong><span aria-hidden="true">↗</span></a>
      <a href="#the-world"><span>02 / THE WORLD</span><strong>Meet the experience</strong><span aria-hidden="true">↗</span></a>
      <a href="#casting"><span>03 / THE CAST</span><strong>Become part of it</strong><span aria-hidden="true">↗</span></a>
    </nav>
  </section>

  <div class="azh-home-marquee" aria-label="AZHottie Studio highlights">
    <span>STORIES WORTH WATCHING</span><b aria-hidden="true">✳</b><span>THE PEOPLE BEHIND THE LENS</span><b aria-hidden="true">✳</b><span>LIVE &amp; ON DEMAND</span><b aria-hidden="true">✳</b><span>YOUR AZHOTTIE WORLD</span>
  </div>

  <section class="azh-home-section azh-home-show" id="the-show" aria-labelledby="show-title">
    <div class="azh-home-section-heading">
      <div>
        <p class="azh-home-eyebrow">01 <span class="azh-home-dash" aria-hidden="true"></span> THE AZHOTTIE SERIES</p>
        <h2 id="show-title">The best moments<br>aren't <em>scripted.</em></h2>
      </div>
      <div class="azh-home-section-aside">
        <p>Follow the energy on set, the conversations after the cameras roll, and the personalities behind every production.</p>
        <a class="azh-home-underlink" href="<?= az_h($watchUrl) ?>">Explore AZHottie Watch <span aria-hidden="true">↗</span></a>
      </div>
    </div>
    <div class="azh-home-shows-grid">
      <a class="azh-home-show-card azh-home-show-card-photo" href="<?= az_h($watchUrl) ?>">
        <div class="azh-home-show-top"><span>THE PHOTOSHOOTS</span><span aria-hidden="true">01 / 03</span></div>
        <div class="azh-home-show-art azh-home-show-art-photo" aria-hidden="true"><span class="azh-home-art-aperture">✳</span></div>
        <div class="azh-home-show-bottom"><div><h3>On the set<span>.</span></h3><p>Camera-ready moments and everything that happens between them.</p></div><span class="azh-home-round-arrow" aria-hidden="true">↗</span></div>
      </a>
      <a class="azh-home-show-card azh-home-show-card-talk" href="<?= az_h($watchUrl) ?>">
        <div class="azh-home-show-top"><span>THE INTERVIEWS</span><span aria-hidden="true">02 / 03</span></div>
        <div class="azh-home-show-art azh-home-show-art-talk" aria-hidden="true"><span class="azh-home-art-quote">“</span><span class="azh-home-art-caption">IN THEIR<br>OWN WORDS</span></div>
        <div class="azh-home-show-bottom"><div><h3>Meet the personalities<span>.</span></h3><p>Real conversations with the people who make the stories.</p></div><span class="azh-home-round-arrow" aria-hidden="true">↗</span></div>
      </a>
      <a class="azh-home-show-card azh-home-show-card-live" href="<?= az_h($liveUrl) ?>">
        <div class="azh-home-show-top"><span>LIVE &amp; REPLAY</span><span aria-hidden="true">03 / 03</span></div>
        <div class="azh-home-show-art azh-home-show-art-live" aria-hidden="true"><span class="azh-home-art-play">▶</span><span class="azh-home-art-live-label">BE PART OF<br>THE MOMENT</span></div>
        <div class="azh-home-show-bottom"><div><h3>From the live room<span>.</span></h3><p>Catch published shows and discover the latest studio moments.</p></div><span class="azh-home-round-arrow" aria-hidden="true">↗</span></div>
      </a>
    </div>
  </section>

  <section class="azh-home-feature" aria-labelledby="feature-title">
    <div class="azh-home-feature-mark" aria-hidden="true">AZ<span>.</span></div>
    <div class="azh-home-feature-copy">
      <p class="azh-home-eyebrow">A LITTLE CLOSER TO THE STORY</p>
      <h2 id="feature-title">More than a show.<br><em>A whole world.</em></h2>
      <p>AZHottie brings original entertainment and real-life experiences together. Discover the latest stories, find something new to do, and stay close to the cast.</p>
      <a class="azh-home-button azh-home-button-primary" href="<?= az_h($memberUrl) ?>"><?= az_h($memberLabel) ?> <span aria-hidden="true">↗</span></a>
    </div>
    <div class="azh-home-feature-note"><span>AZHOTTIE WORLD</span><strong>ARIZONA<br>ORIGINAL</strong><small>STYLE • PERSONALITY • EXPERIENCE</small></div>
  </section>

  <section class="azh-home-section azh-home-world" id="the-world" aria-labelledby="world-title">
    <div class="azh-home-section-heading">
      <div><p class="azh-home-eyebrow">02 <span class="azh-home-dash" aria-hidden="true"></span> STEP INTO THE WORLD</p><h2 id="world-title">Off camera.<br><em>Still unforgettable.</em></h2></div>
      <p class="azh-home-section-description">An AZHottie membership connects you with private experiences, studio happenings, and the moments beyond the screen.</p>
    </div>
    <div class="azh-home-world-grid">
      <a class="azh-home-world-card" href="<?= az_h($eventUrl) ?>"><span class="azh-home-world-symbol" aria-hidden="true">✧</span><span class="azh-home-world-count">01 / GATHER</span><h3>Private events</h3><p>Explore member events, register for eligible gatherings, and follow your calendar.</p><span class="azh-home-world-link">Explore the calendar <span aria-hidden="true">↗</span></span></a>
      <a class="azh-home-world-card" href="<?= az_h($classUrl) ?>"><span class="azh-home-world-symbol" aria-hidden="true">✳</span><span class="azh-home-world-count">02 / EXPLORE</span><h3>Classes &amp; sessions</h3><p>Find workshops, creative sessions, and opportunities to experience something new.</p><span class="azh-home-world-link">Discover classes <span aria-hidden="true">↗</span></span></a>
      <a class="azh-home-world-card" href="<?= az_h($spaUrl) ?>"><span class="azh-home-world-symbol" aria-hidden="true">❋</span><span class="azh-home-world-count">03 / UNWIND</span><h3>Spa &amp; appointments</h3><p>Browse available services and book appointments with AZHottie professionals.</p><span class="azh-home-world-link">See appointments <span aria-hidden="true">↗</span></span></a>
    </div>
    <p class="azh-home-disclaimer">Schedules and availability are shown after sign-in. Membership does not guarantee event admission or appointment availability.</p>
  </section>

  <section class="azh-home-casting" id="casting" aria-labelledby="casting-title">
    <div class="azh-home-casting-vertical" aria-hidden="true">CASTING / ARIZONA</div>
    <div class="azh-home-casting-content"><p class="azh-home-eyebrow">03 <span class="azh-home-dash" aria-hidden="true"></span> THE NEXT CHAPTER</p><h2 id="casting-title">Your story could<br>be <em>next.</em></h2><p>Are you an Arizona-based adult model, performer, host, or creative personality? Tell us about yourself. Every application goes through a private casting review.</p><a class="azh-home-button azh-home-button-primary" href="<?= az_h($applyUrl) ?>">Apply to AZHottie <span aria-hidden="true">↗</span></a></div>
    <div class="azh-home-casting-motif" aria-hidden="true"><span>✳</span><b>BEHIND THE CAMERA.<br>IN FRONT OF THE WORLD.</b><small>AZHOTTIE STUDIO / CASTING</small></div>
  </section>

  <section class="azh-home-outro" aria-labelledby="outro-title"><span class="azh-home-outro-mark" aria-hidden="true">✳</span><div><p class="azh-home-eyebrow">DON'T MISS THE NEXT MOMENT</p><h2 id="outro-title">The story is just <em>beginning.</em></h2></div><a class="azh-home-button azh-home-button-quiet" href="<?= az_h($watchUrl) ?>">See what's on <span aria-hidden="true">↗</span></a></section>
</main>
<footer class="azh-home-footer">
  <div class="azh-home-footer-brand"><a href="index.php">AZHOTTIE<span>.</span></a><p>ARIZONA • ORIGINAL STORIES • REAL PERSONALITIES</p></div>
  <nav aria-label="Footer navigation"><a href="<?= az_h($watchUrl) ?>">Watch</a><a href="<?= az_h($liveUrl) ?>">Live shows</a><a href="<?= az_h($eventUrl) ?>">Events</a><a href="<?= az_h($classUrl) ?>">Classes</a><a href="<?= az_h($spaUrl) ?>">Appointments</a><a href="<?= az_h($applyUrl) ?>">Casting</a><a href="<?= az_h($memberUrl) ?>">Membership</a></nav>
  <small>© <?= date('Y') ?> AZHottie Studio · Adults 18+</small>
</footer>
</body>
</html>
