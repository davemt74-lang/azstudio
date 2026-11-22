#!/usr/bin/env python3
"""Local static-browser layout test of the real Live Director template/CSS.
The PHP controller is not executed; actual camera and WebRTC routing are separately gated.
"""
import re,pathlib
from playwright.sync_api import sync_playwright
root=pathlib.Path(__file__).resolve().parents[1]
html=(root/'live-director.php').read_text()
html=re.sub(r'<\?.*?\?>','',html,flags=re.S)
html=re.sub(r'<script[^>]*>.*?</script>','',html,flags=re.S)
html=re.sub(r'<link[^>]*rel="stylesheet"[^>]*>','',html)
html=html.replace('</head>','<style>'+(root/'assets/style.css').read_text()+'\n'+(root/'assets/live-studio.css').read_text()+'</style></head>')
with sync_playwright() as p:
  browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
  for width in (1440,900,768,390,320):
    ctx=browser.new_context(viewport={'width':width,'height':900})
    page=ctx.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
    page.set_content(html)
    box=page.locator('.azh-live-agent-monitor');box.wait_for()
    assert box.get_by_role('button',name='Recheck').count()==1
    assert box.get_by_role('button',name='Direct camera relay').count()==1
    assert box.get_by_role('button',name='Restore Program').count()==1
    assert box.get_by_text('Off air · live health monitoring paused').count()==1
    size=page.evaluate('({sw:document.documentElement.scrollWidth,cw:document.documentElement.clientWidth})')
    assert size['sw']<=size['cw']+1,(width,size)
    assert not errors,errors
    if width==1440:
      page.screenshot(path=str(root.parent.parent/'AZHottie_v1.25.0_live_director_health_preview.png'),full_page=False)
    print('Director layout PASS',width)
    ctx.close()
  browser.close()
