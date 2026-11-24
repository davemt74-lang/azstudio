#!/usr/bin/env python3
"""Real Chromium shop filters, galleries, admin merchandising and responsive layout.
No cards are charged and no private checkout secrets are ever used.
"""
from pathlib import Path
from urllib.parse import urlparse,parse_qs
from playwright.sync_api import sync_playwright
import os,json
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('RUNNER_TEMP','/tmp'))/'azh_shop_v156_browser'
OUT.mkdir(parents=True,exist_ok=True)
SHOP=(ROOT/'shop.php').read_text()+(ROOT/'includes/shop-homepage-view.php').read_text()
ADMIN=(ROOT/'commerce.php').read_text()
for anchor in ('data-stripe-checkout','data-local-order','data-shop-product','data-shop-filter','shop-product.php'):
 assert anchor in SHOP,anchor
for anchor in ('id="azh-shop-merch"','id="azh-shop-merch-components"','id="azh-shop-merch-gallery"'):
 assert anchor in ADMIN,anchor
HTML='''<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;background:#fff"><main id="azh-shop" class="azh-commerce azh-commerce-public azh-shop">
<header class="azh-shop-hero"><div class="azh-shop-eyebrow">CURATED FOR THE CURIOUS</div>
<h1>Discover your <em>next favorite.</em></h1><p>Creator-curated products from AZHottie</p></header>
<section class="azh-shop-tools"><div class="azh-shop-tools-title"><h2>Explore the collection</h2><span id="azh-shop-count"></span></div>
<div class="azh-shop-tool-row"><label class="azh-shop-search">Search products<input id="azh-shop-query" type="search"></label>
<label class="azh-shop-sort">Sort by<select id="azh-shop-sort"><option value="featured">Featured</option><option value="price-low">Low</option><option value="price-high">High</option><option value="name">Name</option></select></label></div>
<div class="azh-shop-filters"><button data-shop-filter="all" aria-pressed="true">All</button><button data-shop-filter="fitness" aria-pressed="false">Fitness</button><button data-shop-filter="apparel" aria-pressed="false">Clothing</button></div></section>
<section class="azh-commerce-list azh-commerce-grid azh-shop-grid" id="azh-shop-products">'''+''.join(f'''
<article class="azh-commerce-card azh-shop-card" data-shop-product data-category="{category}" data-title="{title}" data-search="{title} {category} {description}" data-price="{price}" data-featured="{featured}">
<div class="azh-shop-card-media"><div class="azh-shop-card-placeholder" role="img" aria-label="No product photo yet"><span aria-hidden="true">✳</span><small>AZHOTTIE</small></div></div>
<div class="azh-shop-card-body"><span class="azh-shop-card-kicker">{category}</span><h2>{title}</h2><p class="azh-shop-card-description">{description}</p>
<a class="azh-shop-details" href="shop-product.php?id={'a'*32}">View photos &amp; details</a>
<div class="azh-commerce-card-footer"><strong>${price/100:.2f} USD</strong><p>Available for pickup</p><form data-stripe-checkout><button class="azh-commerce-cta">Secure checkout</button></form></div></div></article>'''
for category,title,description,price,featured in [('fitness','Fitness Gear','Resistance training',1500,0),('apparel','White Hoodie','Warm hoodie',2500,1),('apparel','Basic Tee','Cotton tee',900,0)])+'''</section>
<div id="azh-shop-no-matches" class="azh-shop-empty" hidden>No matches</div></main></body></html>'''
GAL='''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0"><main class="azh-commerce azh-commerce-public azh-shop" id="azh-shop"><div class="azh-shop-detail">
<div class="azh-shop-gallery" data-shop-gallery><div class="azh-shop-gallery-main"><img id="azh-shop-current-image" alt="Front hoodie" src="data:image/gif;base64,R0lGODlhAQABAAAAACw="></div>
<div class="azh-shop-thumbnails"><button data-gallery-src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" data-gallery-alt="Front hoodie" aria-pressed="true"><img alt=""></button>
<button data-gallery-src="data:image/gif;base64,R0lGODlhAQABAAABACw=" data-gallery-alt="Back hoodie" aria-pressed="false"><img alt=""></button></div></div>
<div class="azh-shop-detail-copy"><h1>White Hoodie</h1><p>Gallery preview</p></div></div></main></body></html>'''
ADMIN_HTML='''<!doctype html><html><head><base href="https://azhottie.test/"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:8px">
<section class="azh-commerce-editor" id="azh-shop-merch" data-csrf="v156-admin-csrf">
<h2>Product galleries &amp; collections</h2><label>Product<select id="azh-shop-merch-product"></select></label>
<p id="azh-shop-merch-status"></p>
<form id="azh-shop-merch-form" hidden><input type="hidden" id="azh-shop-merch-revision">
<label>Collection<select id="azh-shop-merch-category"><option value="apparel">Clothing</option><option value="fitness">Fitness</option></select></label>
<label>Tagline<input id="azh-shop-merch-tagline" maxlength="180"></label><label>Featured<input type="checkbox" id="azh-shop-merch-featured"></label>
<div id="azh-shop-merch-components"></div><button type="submit" id="azh-shop-merch-save">Save storefront settings</button></form>
<form id="azh-shop-merch-upload" hidden><label>Photo<input type="file" id="azh-shop-merch-photo" name="photo"></label>
<label>Alt<input name="alt" required></label><button type="submit" id="azh-shop-merch-add-photo">Upload photo</button>
<div id="azh-shop-merch-image-note"></div><div id="azh-shop-merch-gallery"></div></form></section></body></html>'''
def run():
 with sync_playwright() as pw:
  opts={'headless':True,'args':['--no-sandbox']}
  if Path('/usr/bin/chromium').exists():opts['executable_path']='/usr/bin/chromium'
  else:opts['channel']='chrome'
  browser=pw.chromium.launch(**opts)
  for width in (1440,768,390,320):
   page=browser.new_page(viewport={'width':width,'height':880});errors=[]
   page.on('pageerror',lambda e:errors.append(str(e)))
   page.set_content(HTML);page.add_style_tag(path=str(ROOT/'assets/commerce.css'))
   page.add_style_tag(path=str(ROOT/'assets/shop-v156.css'))
   page.add_script_tag(path=str(ROOT/'assets/shop-v156.js'),type='module')
   page.wait_for_function("document.getElementById('azh-shop-count').textContent.includes('3 products')")
   page.get_by_role('button',name='Fitness').click()
   assert page.locator('[data-shop-product]:visible').count()==1,(width,'category filtering')
   assert page.locator('[data-shop-filter="fitness"]').get_attribute('aria-pressed')=='true'
   page.get_by_role('button',name='All',exact=True).click()
   page.get_by_label('Search products').fill('hoodie')
   assert page.locator('[data-shop-product]:visible').count()==1,(width,'search filtering')
   page.get_by_label('Search products').fill('not available anywhere')
   assert page.locator('#azh-shop-no-matches').is_visible(),(width,'empty search status')
   page.get_by_label('Search products').fill('')
   page.get_by_label('Sort by').select_option('price-low')
   assert page.locator('[data-shop-product]').first.get_attribute('data-title')=='Basic Tee',(width,'price sorting')
   assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),(width,'overflow')
   assert not errors,(width,errors)
   page.screenshot(path=str(OUT/f'shop_{width}.png'),full_page=True)
   page.close()
   gallery=browser.new_page(viewport={'width':width,'height':800})
   gallery.set_content(GAL);gallery.add_style_tag(path=str(ROOT/'assets/shop-v156.css'))
   gallery.add_script_tag(path=str(ROOT/'assets/shop-v156.js'),type='module')
   gallery.locator('[data-gallery-alt="Back hoodie"]').click()
   assert gallery.locator('#azh-shop-current-image').get_attribute('alt')=='Back hoodie'
   assert gallery.locator('[data-gallery-alt="Back hoodie"]').get_attribute('aria-pressed')=='true'
   assert gallery.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),(width,'gallery overflow')
   gallery.close();print('Shop filters / gallery / responsive PASS',width)
  # Admin controls: approve only curated metadata, no payment/order actions.
  page=browser.new_page(viewport={'width':390,'height':800})
  state={'products':[{'id':'a'*32,'title':'White Hoodie','state':'active','category':'apparel','tagline':'','featured':False,'revision':0,'components':[],'images':[]},
                    {'id':'b'*32,'title':'Fitness Gear','state':'active','category':'fitness','tagline':'','featured':False,'revision':0,'components':[],'images':[]}],
         'categories':{'apparel':'Clothing','fitness':'Fitness'}}
  calls=[]
  def respond(route):
   req=route.request;action=parse_qs(urlparse(req.url).query).get('action',[''])[0]
   calls.append((action,req.method,req.headers.get('x-csrf-token')))
   assert req.headers.get('x-csrf-token')=='v156-admin-csrf' or action=='state',calls
   if action=='state':output=state
   elif action=='save':
    data=json.loads(req.post_data);assert data['productId']=='a'*32 and data['components']==['b'*32]
    state['products'][0].update({'category':data['category'],'tagline':data['tagline'],'featured':data['featured'],'components':data['components'],'revision':1});output={'saved':True}
   elif action=='upload':
    assert req.method=='POST' and 'multipart/form-data' in req.headers.get('content-type','')
    state['products'][0]['images'].append({'id':'c'*32,'alt':'Hoodie photo','src':'shop-media.php?id='+'c'*32});output={'id':'c'*32}
   elif action=='image':
    data=json.loads(req.post_data);assert data['id']=='c'*32
    if data['action']=='delete':state['products'][0]['images']=[]
    output={'saved':True}
   else:raise AssertionError('Unexpected action '+action)
   route.fulfill(status=200,content_type='application/json',body=json.dumps({'ok':True,'data':output}))
  page.route('**/shop-catalog-api.php?*',respond)
  page.on('pageerror',lambda e:print('ADMIN PAGE ERROR',str(e),flush=True))
  page.set_content(ADMIN_HTML);page.add_style_tag(path=str(ROOT/'assets/commerce.css'))
  page.add_style_tag(path=str(ROOT/'assets/shop-merch-v156.css'))
  page.add_script_tag(path=str(ROOT/'assets/shop-merch-v156.js'),type='module')
  page.wait_for_function("document.querySelectorAll('#azh-shop-merch-product option').length>1",timeout=6000)
  page.locator('#azh-shop-merch-product').select_option('a'*32)
  page.locator('#azh-shop-merch-tagline').fill('Loved by our creators')
  page.locator('#azh-shop-merch-featured').check()
  page.locator('#azh-shop-merch-components input[value="'+'b'*32+'"]').check()
  page.locator('#azh-shop-merch-save').click(timeout=4000)
  page.wait_for_function("document.getElementById('azh-shop-merch-status').textContent.includes('saved')")
  assert state['products'][0]['featured'] is True
  page.locator('#azh-shop-merch-photo').set_input_files({'name':'photo.jpg','mimeType':'image/jpeg','buffer':b'fake-image-transport-test'})
  page.locator('#azh-shop-merch-upload input[name=alt]').fill('Hoodie photo')
  page.locator('#azh-shop-merch-add-photo').click(timeout=4000)
  page.wait_for_function("document.getElementById('azh-shop-merch-gallery').children.length===1",timeout=6000)
  page.on('dialog',lambda dialog:dialog.accept())
  page.get_by_role('button',name='Delete').click(timeout=4000)
  page.wait_for_function("document.getElementById('azh-shop-merch-gallery').children.length===0",timeout=6000)
  assert not any(name not in ('state','save','upload','image') for name,_,_ in calls)
  assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+2')
  page.screenshot(path=str(OUT/'admin_merch_390.png'),full_page=True)
  print('Admin merchandising CSRF, save, upload, delete PASS',len(calls),'API calls')
  page.close();browser.close()
if __name__=='__main__':run()

