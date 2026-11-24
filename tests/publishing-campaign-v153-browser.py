#!/usr/bin/env python3
"""v1.53 real Chromium: publish-campaign UI on desktop & mobile; no external posting."""
from pathlib import Path
import os,json
from urllib.parse import urlparse, parse_qs
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('RUNNER_TEMP','/tmp'))/'azh_campaign_v153_browser'
OUT.mkdir(parents=True,exist_ok=True)
source=(ROOT/'publishing.php').read_text(encoding='utf-8')
assert 'id="pg-campaign-workspace"' in source
assert 'publishing-campaigns.js?v=1.53.0' in source
HTML='''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><base href="https://azhottie.test/"></head><body style="margin:0;background:#fbf8fa">
<main class="pg-workspace"><section class="pg-campaign-board" id="pg-campaign-workspace" data-csrf="test-csrf">
<header class="pg-heading"><div><h2>Campaign Studio</h2><p>Manual handoffs and measured campaign links</p></div><button type="button" class="pg-button pg-button-outline" id="pg-campaign-refresh">Refresh campaigns</button></header>
<p id="pg-campaign-summary" class="pg-note"></p><p id="pg-campaign-message" class="pg-message" role="status" hidden></p>
<form id="pg-campaign-form" class="pg-form"><div class="pg-campaign-controls">
<label>Approved social promotion<select id="pg-campaign-source" required></select><small id="pg-campaign-source-note"></small></label>
<label>Campaign channel<select id="pg-campaign-platform"><option value="instagram">Instagram</option><option value="tiktok">TikTok</option></select></label>
<label>Optional handoff<input id="pg-campaign-time" type="datetime-local"></label></div>
<button id="pg-campaign-create" class="pg-button" type="submit">Create campaign draft</button></form>
<div class="pg-lines" id="pg-campaigns" aria-live="polite"></div></section></main></body></html>'''
VID='a'*32;PROMO='b'*32;CID='c'*32;TOKEN='d'*32
with sync_playwright() as pw:
    opt={'headless':True,'args':['--no-sandbox']}
    if Path('/usr/bin/chromium').exists():opt['executable_path']='/usr/bin/chromium'
    else:opt['channel']='chrome'
    browser=pw.chromium.launch(**opt)
    for width in (1440,768,390,320):
        page=browser.new_page(viewport={'width':width,'height':850})
        calls=[];errors=[];campaign=[]
        page.on('pageerror',lambda e:errors.append(str(e)))
        page.on('dialog',lambda d:d.accept())
        def route(req):
            url=urlparse(req.request.url);action=parse_qs(url.query).get('action',[''])[0]
            body=json.loads(req.request.post_data or '{}') if req.request.method=='POST' else {}
            calls.append((url.path,action,req.request.method,body))
            if url.path.endswith('/publishing-agent-api.php'):
                result={'ok':True,'data':{'promotions':[{'id':PROMO,'headline':'New release','title':'Verified creator','channel':'social','state':'approved','copy_text':'Public clip'}]}}
            elif action=='state':
                result={'ok':True,'data':{'campaigns':campaign,'csrf':'test-csrf'}}
            elif action=='create':
                assert req.request.headers['x-csrf-token']=='test-csrf'
                assert body['promotionId']==PROMO and body['platform']=='instagram'
                campaign.append({'id':CID,'headline':'New release','copy_text':'Public clip','platform':'instagram','state':'draft',
                    'schedule_at':None,'revision':1,'shareUrl':'/campaign.php?t='+TOKEN,'videoUrl':'/watch.php?id='+VID,
                    'coverUrl':None,'opens':0,'ready':True,'handoffDue':False})
                result={'ok':True,'data':{'id':CID,'state':'draft'}}
            elif action=='decide':
                assert req.request.headers['x-csrf-token']=='test-csrf'
                assert body['revision']==campaign[0]['revision']
                campaign[0]['state']='active';campaign[0]['revision']+=1
                result={'ok':True,'data':{'id':CID,'state':'active','revision':campaign[0]['revision']}}
            else: raise AssertionError('Unexpected request '+req.request.url)
            req.fulfill(status=200,content_type='application/json',body=json.dumps(result))
        page.route('**/publishing-agent-api.php?*',route)
        page.route('**/publishing-campaign-api.php?*',route)
        page.set_content(HTML)
        page.add_style_tag(path=str(ROOT/'assets/publishing-agent.css'))
        page.add_script_tag(path=str(ROOT/'assets/publishing-campaigns.js'),type='module')
        page.get_by_role('button',name='Create campaign draft').wait_for(state='visible',timeout=9000)
        page.wait_for_function("document.querySelector('#pg-campaign-source option')?.value==='"+PROMO+"'",timeout=9000)
        page.get_by_role('button',name='Create campaign draft').click()
        page.get_by_role('button',name='Activate tracking link').wait_for(state='visible',timeout=9000)
        page.get_by_role('button',name='Activate tracking link').click()
        page.get_by_text('Instagram · active').wait_for(state='visible',timeout=9000)
        assert not any('external' in x[0] for x in calls)
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth+2'),('overflow',width)
        assert page.get_by_role('button',name='Copy caption + link').count()==1
        assert not errors,(width,errors)
        page.screenshot(path=str(OUT/f'publishing_campaign_{width}.png'),full_page=True)
        print('v1.53 campaign desktop/mobile approval and lifecycle PASS',width)
        page.close()
    browser.close()
