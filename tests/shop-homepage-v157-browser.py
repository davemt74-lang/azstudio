#!/usr/bin/env python3
"""Actual shop CSS and homepage editor JS in Chromium; no live commerce/provider actions."""
import json, re, os, base64
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('RUNNER_TEMP','/tmp'))/'azh_shop_v157_browser'
OUT.mkdir(parents=True,exist_ok=True)
SHOP=(ROOT/'shop.php').read_text(encoding='utf8')
ADMIN=(ROOT/'shop-homepage-editor.php').read_text(encoding='utf8')
for needle in ('data-section="featured"','data-section="catalog"','data-shop-category-jump','az_shop_homepage_image_src','azh-shop-featured-grid','SAMPLE · NOT FOR SALE'):
    assert needle in SHOP, ('Shop template missing',needle)
for needle in ('Shop Homepage Editor','categories','data-action="publish"','id="azh-shop-preview"','name="heroImage"','name="backgroundImage"'):
    assert needle in ADMIN, ('Editor missing',needle)

def photo(name):
    data=(ROOT/'assets'/name).read_bytes()
    return 'data:image/webp;base64,'+base64.b64encode(data).decode('ascii')

def storefront_fixture():
    hero=photo('shop-editorial-hero-v157.webp'); hoodie=photo('shop-demo-hoodie-v157.webp'); bottle=photo('shop-demo-bottle-v157.webp')
    image=photo('shop-category-apparel-v157.webp')
    return '''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body data-page="shop">
    <main class="azh-commerce azh-commerce-public azh-shop azh-shop-v157" id="azh-shop"
      style="--az-hero-image:url('''+"'"+hero+"'"+''');--az-hero-height:440px;--az-hero-size:65px">
    <div class="azh-shop-sections"><section class="azh-shop-section azh-shop-hero-v157" data-section="hero" style="order:0">
      <div class="azh-hero-overlay"></div><div class="azh-shop-hero-copy"><p class="azh-shop-eyebrow" id="azh-hero-eyebrow">CURATED FOR THE CURIOUS</p>
      <h1 id="azh-hero-headline"><span id="azh-hero-heading">Discover your next</span> <em id="azh-hero-accent">favorite.</em></h1>
      <p id="azh-hero-description">Creator picks, curated bundles, and premium lifestyle products — all in one place.</p>
      <a id="azh-hero-cta" href="#shop-products" class="azh-shop-primary-cta"><span>Shop the Collection</span> <span>→</span></a></div></section>
    <section class="azh-shop-section azh-shop-category-section" data-section="categories" id="shop-categories" style="order:1">
      <div class="azh-shop-section-heading"><div><p class="azh-shop-kicker">EXPLORE AZHOTTIE</p><h2 id="azh-categories-title">Shop by Collection</h2></div></div>
      <div class="azh-category-grid"><a href="#shop-browse" class="azh-category-tile" data-shop-category-jump="apparel"><img src="'''+image+'''" alt=""><span class="azh-category-title">Clothing</span></a>
      <a href="#shop-browse" class="azh-category-tile" data-shop-category-jump="fitness"><img src="'''+bottle+'''" alt=""><span class="azh-category-title">Fitness</span></a></div></section>
    <section class="azh-shop-section azh-shop-featured" data-section="featured" id="shop-products" style="order:2"><div class="azh-shop-section-heading"><div><p class="azh-shop-kicker">FEATURED PRODUCTS</p><h2 id="azh-featured-title">Trending Now</h2></div></div>
      <div class="azh-shop-featured-grid"><article class="azh-featured-card"><div class="azh-featured-image"><img src="'''+hoodie+'''" alt="Hoodie"><span class="azh-featured-badge">SAMPLE · NOT FOR SALE</span></div>
      <div class="azh-featured-meta"><strong>AZHottie Crop Hoodie</strong><small>Clothing</small><b>$49.99</b><span class="azh-demo-button">Preview only</span></div></article></div></section>
    <section class="azh-shop-section azh-shop-catalog" data-section="catalog" id="shop-browse" style="order:3"><div class="azh-shop-section-heading"><h2>Explore the Collection</h2><span id="azh-shop-count"></span></div>
      <div class="azh-shop-tool-row"><label>Search<input id="azh-shop-query" type="search"></label><label>Sort<select id="azh-shop-sort"><option value="featured">Featured first</option><option value="name">Name</option></select></label></div>
      <div class="azh-shop-filters"><button data-shop-filter="all" class="is-current" aria-pressed="true">All</button><button data-shop-filter="apparel" aria-pressed="false">Clothing</button><button data-shop-filter="fitness" aria-pressed="false">Fitness</button></div>
      <div class="azh-commerce-list azh-commerce-grid azh-shop-grid" id="azh-shop-products">
        <article class="azh-commerce-card azh-shop-card" data-shop-product data-category="apparel" data-title="Hoodie" data-search="hoodie clothing" data-price="4999" data-featured="1"><div class="azh-shop-card-media"><img src="'''+hoodie+'''" alt="Hoodie"></div><div class="azh-shop-card-body"><h2>Hoodie</h2><button class="azh-demo-button" disabled>Demo only · Checkout unavailable</button></div></article>
        <article class="azh-commerce-card azh-shop-card" data-shop-product data-category="fitness" data-title="Bottle" data-search="bottle fitness" data-price="2499" data-featured="1"><div class="azh-shop-card-media"><img src="'''+bottle+'''" alt="Bottle"></div><div class="azh-shop-card-body"><h2>Bottle</h2><button class="azh-demo-button" disabled>Demo only · Checkout unavailable</button></div></article>
      </div><p id="azh-shop-no-matches" hidden>No products match those filters.</p></section></div></main></body></html>'''

def admin_fixture():
    s=ADMIN[ADMIN.index('<main class="azh-shop-edit"'):ADMIN.index('</main>')+7]
    s=re.sub(r'<\?=\s*az_h\(az_csrf_token\(\)\)\s*\?>','fixture-csrf',s)
    assert 'fixture-csrf' in s
    return '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><base href="https://azhottie.test/"></head><body>'+s+'</body></html>'

STUB=r'''
window.confirm=()=>true; window.__posts=[];
window.__settings={revision:1,publishedRevision:1,publishedAt:'2026-11-24',
 draft:{storeEnabled:true,sampleEnabled:false,eyebrow:'CURATED FOR THE CURIOUS',heading:'Discover your next',accent:'favorite.',description:'Product preview',ctaText:'Shop',ctaHref:'#shop-products',
 categoriesTitle:'Shop by Collection',featuredTitle:'Trending Now',catalogTitle:'Explore the Collection',heroImage:'default',backgroundImage:'none',heroPosition:'center',heroAlignment:'left',
 heroHeight:440,headingSize:65,fontStyle:'modern',textColor:'#251d28',accentColor:'#cf2167',buttonColor:'#d82676',backgroundColor:'#ffffff',overlayStrength:5,
 sections:{hero:true,categories:true,featured:true,catalog:true,support:true},categories:{apparel:true,fitness:true,lifestyle:true,digital:true,candy:true,photography:true,spa:true,accessories:true,bundles:true,gifts:true,other:true},order:['hero','categories','featured','catalog','support']},
 published:{},images:[],sections:{hero:'Hero banner',categories:'Category tiles',featured:'Trending products',catalog:'Shop all / filters',support:'Customer service links'},
 categories:{apparel:'Clothing',fitness:'Fitness',lifestyle:'Adult Lifestyle',digital:'Digital Entertainment',candy:'Candy',photography:'Photography',spa:'Day Spa',accessories:'Accessories',bundles:'Bundles',gifts:'Gift Cards',other:'Other'}};
window.__settings.published=structuredClone(window.__settings.draft);
window.fetch=async(input,options={})=>{
 const a=new URL(input,document.baseURI).searchParams.get('action');
 if(a==='state')return new Response(JSON.stringify({ok:true,data:window.__settings}),{status:200,headers:{'Content-Type':'application/json'}});
 if(options.headers?.['X-CSRF-Token']!=='fixture-csrf')throw Error('Missing admin CSRF');
 const body=JSON.parse(options.body);window.__posts.push({a,body});
 if(a==='save'){
   if(body.revision!==window.__settings.revision)throw Error('Invalid version in test');
   window.__settings.revision++;
   if(body.action==='resetDraft')window.__settings.draft=structuredClone(window.__settings.published);
   else{window.__settings.draft=structuredClone(body.design);if(body.action==='publish'){window.__settings.published=structuredClone(body.design);window.__settings.publishedRevision++;}}
   return new Response(JSON.stringify({ok:true,data:{saved:true,published:body.action==='publish',revision:window.__settings.revision}}),{status:200,headers:{'Content-Type':'application/json'}});
 }
 throw Error('Unexpected editor action '+a);
};
void 0;
'''

def run():
 with sync_playwright() as pw:
  browser=pw.chromium.launch(executable_path='/usr/bin/chromium' if Path('/usr/bin/chromium').exists() else None,
      headless=True,args=['--no-sandbox'])
  for width in [1440,768,390,320]:
   page=browser.new_page(viewport={'width':width,'height':900});errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
   page.set_content(storefront_fixture())
   page.add_style_tag(path=str(ROOT/'assets/shop-v156.css'))
   page.add_style_tag(path=str(ROOT/'assets/shop-v157.css'))
   page.add_script_tag(content=(ROOT/'assets/shop-v156.js').read_text(),type='module')
   page.add_script_tag(content=(ROOT/'assets/shop-v157.js').read_text())
   page.wait_for_function("document.querySelector('#azh-shop-count')?.textContent.includes('2')",timeout=5000)
   assert page.locator('.azh-shop-hero-v157').is_visible(),width
   assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'),('overflow',width,page.evaluate('document.documentElement.scrollWidth'))
   page.locator('[data-shop-category-jump="fitness"]').click()
   page.wait_for_function("document.querySelector('#azh-shop-count')?.textContent.includes('1')",timeout=5000)
   assert not page.locator('[data-shop-product][data-category="apparel"]').is_visible(),width
   assert page.locator('[data-shop-product][data-category="fitness"]').is_visible(),width
   assert page.locator('[data-stripe-checkout]').count()==0,'Sample fixture must not have checkout'
   assert not errors,(width,errors)
   page.screenshot(path=str(OUT/f'shop21_{width}.png'),full_page=True)
   page.close();print('Editorial shop, responsive tiles and category filtering PASS',width)
  page=browser.new_page(viewport={'width':1440,'height':850});errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
  html=admin_fixture().replace('src="shop.php?preview=1"','src="about:blank"')
  page.set_default_timeout(3000);page.set_content(html,wait_until='domcontentloaded');page.add_style_tag(path=str(ROOT/'assets/shop-homepage-editor.css'));print('admin fixture ready',flush=True)
  page.evaluate(STUB);print('stub evaluated',flush=True)
  page.add_script_tag(content=(ROOT/'assets/shop-homepage-editor.js').read_text());print('script added',flush=True)
  page.wait_for_function("document.getElementById('azh-shop-editor-status')?.textContent.includes('Ready')",timeout=8000)
  print('status ready',page.locator('#azh-shop-editor-status').inner_text(),flush=True)
  assert page.locator('[data-category-enable]').count()==11
  assert page.locator('[data-section-enable]').count()==5
  page.locator('input[name="heading"]').fill('Fresh boutique style')
  page.locator('[data-category-enable="candy"]').uncheck()
  page.locator('[data-section-enable="featured"]').uncheck()
  page.locator('[data-action="save"]').click()
  page.wait_for_function("document.getElementById('azh-shop-editor-status')?.textContent.includes('Draft saved')",timeout=8000)
  assert page.evaluate("window.__settings.published.heading")=='Discover your next','Draft must not auto publish'
  assert page.evaluate("window.__settings.draft.heading")=='Fresh boutique style'
  assert not page.evaluate("window.__settings.draft.categories.candy"),'Category visibility not saved'
  assert not page.evaluate("window.__settings.draft.sections.featured"),'Section visibility not saved'
  page.locator('[data-action="publish"]').click()
  page.wait_for_function("document.getElementById('azh-shop-editor-status')?.textContent.includes('published')",timeout=8000)
  assert page.evaluate("window.__settings.published.heading")=='Fresh boutique style'
  assert page.evaluate("window.__posts.every(x=>x.body.revision===1||x.body.revision===2)")
  assert not errors,errors
  page.screenshot(path=str(OUT/'shop21_admin_1440.png'),full_page=True)
  page.close()
  browser.close()
  print('Admin draft, category/section switches, publish, CSRF, responsive browser: PASS')

if __name__=='__main__':run()
