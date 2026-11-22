"""Optional Chromium layout check; source-package only, requires playwright/chromium."""
from pathlib import Path
import re
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
source=(root/'live-director.php').read_text()
header=re.search(r'<header class="azh-director-toolbar">([\s\S]*?)</header>',source).group(0)
assert 'live-camera-search' not in header
style=(root/'assets/style.css').read_text()+'\n'+(root/'assets/live-studio.css').read_text()
fixture=f'''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>{style}</style></head><body class="azh-director-body" data-page="live-director"><main class="azh-director azh-director-app" id="azh-director">{header}<div class="azh-director-workspace"><aside class="azh-director-sources"><section class="azh-live-phones"><h2>Connected phone cameras</h2><div id="live-sources" class="azh-phone-camera-grid"><div class="azh-live-source">Camera 1</div><div class="azh-live-source">Camera 2</div></div></section></aside><section class="azh-director-program"><div class="azh-director-monitor"><div class="azh-monitor-label">PROGRAM</div><div class="azh-program-stage"><canvas width="1280" height="720" id="live-program-canvas"></canvas></div></div></section></div></main></body></html>'''
with sync_playwright() as playwright:
    browser=playwright.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage'])
    for width in (1440,1024,720,390,320):
        page=browser.new_page(viewport={'width':width,'height':800},device_scale_factor=1)
        page.set_content(fixture)
        rects=page.evaluate('''()=>{
          const rect=s=>{const r=document.querySelector(s).getBoundingClientRect();return {left:r.left,right:r.right,width:r.width,top:r.top,bottom:r.bottom}};
          return {documentWidth:document.documentElement.scrollWidth,viewportWidth:window.innerWidth,
            back:rect('.azh-director-back'),title:rect('#live-title'),onAir:rect('#live-onair-pill'),go:rect('#live-go'),end:rect('#live-end'),
            titleIdCount:document.querySelectorAll('#live-title').length,search:!!document.querySelector('#live-camera-search')}
          }''')
        assert rects['documentWidth']<=width+1,(width,rects)
        assert rects['titleIdCount']==1 and not rects['search'],(width,rects)
        assert rects['title']['left']>rects['back']['left'],(width,rects)
        assert rects['title']['width']>=130,(width,rects)
        for control in ('back','title','onAir','go','end'):
            assert rects[control]['left']>=-1 and rects[control]['right']<=width+1,(width,control,rects)
        print(width,rects)
        if width==1440:
            page.screenshot(path='/mnt/data/AZHottie_v1.22.1_live_director_header_preview.png')
        page.close()
    browser.close()
print('Director header viewport checks passed')
