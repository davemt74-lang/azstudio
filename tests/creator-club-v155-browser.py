#!/usr/bin/env python3
"""Responsive smoke for real creator club form/card CSS; no claims about provider billing or live auth."""
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
HTML='''<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;font:16px system-ui;background:#faf7f9">
<main class="azh-club" id="azh-creator-club"><header class="club-hero"><p class="club-eyebrow">AZHOTTIE · CREATOR CLUB</p><h1>Creator One's Club<span>.</span></h1><p>Follow for free. Subscription tiers are being prepared.</p><div class="club-stat"><strong>5</strong> followers <a href="#">View creator profile</a></div><form class="club-inline"><button class="club-primary">Follow for free</button></form></header>
<section class="club-section"><header><h2>Creator subscriptions</h2><small>Payments are not enabled</small></header><div class="club-grid"><article class="club-card"><span class="club-eyebrow">APPROVED</span><h3>Behind the scenes</h3><strong>$10.00 <span>/month · proposed</span></strong><p>Production updates</p><button disabled>Subscription checkout not available</button></article><article class="club-card"><h3>Studio Access</h3><strong>$20.00 <span>/month · proposed</span></strong><p>Extra videos</p><button disabled>Subscription checkout not available</button></article></div></section>
<section class="club-section"><header><h2>Propose a subscription tier</h2></header><form class="club-form"><label>Tier name<input required placeholder="Backstage"></label><label>Monthly price <input type="number" value="10"></label><label>Benefits <textarea rows="3"></textarea></label><button class="club-primary" type="submit">Submit tier for review</button></form></section>
<section class="club-section"><header><h2>Members-only library</h2></header><article class="club-post"><h3>Private video post</h3><p>Exclusive behind-the-scenes footage.</p><video controls playsinline></video></article></section></main>
</body></html>'''
with sync_playwright() as p:
    options={'headless':True,'args':['--no-sandbox']}
    if Path('/usr/bin/chromium').exists(): options['executable_path']='/usr/bin/chromium'
    else: options['channel']='chrome'
    browser=p.chromium.launch(**options)
    for width in [1440,768,390,320]:
        page=browser.new_page(viewport={'width':width,'height':800})
        errors=[]
        page.on('pageerror',lambda e:errors.append(str(e)))
        page.set_content(HTML)
        page.add_style_tag(path=str(ROOT/'assets/creator-club.css'))
        assert page.get_by_role('heading',name="Creator One's Club.").count()==1
        assert page.get_by_role('button',name='Follow for free').is_visible()
        assert page.get_by_role('button',name='Subscription checkout not available').count()==2
        assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),(width,'horizontal overflow')
        assert not errors,(width,errors)
        print('Creator Club responsive UI PASS',width)
        page.close()
    browser.close()
