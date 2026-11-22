#!/usr/bin/env python3
"""Browser behavior with isolated fixture data. Never mistaken for live hosted media tests."""
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
HTML='''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body><main class="pg-workspace" data-csrf="fixturecsrf">
<div class="pg-header"><h1>Publishing & Growth</h1></div><p id="pg-message" hidden></p>
<section><div id="pg-metrics" class="pg-metrics"></div><div id="pg-chart" class="pg-chart"></div><p id="pg-analytics-basis"></p><div id="pg-top-videos"></div></section>
<section class="pg-grid"><div class="pg-panel"><div id="pg-scheduled"></div><button id="pg-check">Check releases</button></div>
<div class="pg-panel"><form id="pg-draft" class="pg-form"><select id="pg-video"></select><select id="pg-channel"><option value="onsite">On site</option><option value="social">Social</option></select><button type="button" id="pg-suggest">Use metadata</button><input id="pg-headline"><textarea id="pg-copy"></textarea><button type="submit">Save</button></form><div id="pg-promotions"></div></div></section>
<section class="pg-panel"><div id="pg-suggestions"></div></section></main></body></html>'''
video='a'*32
payload={'ok':True,'data':{'csrf':'fixturecsrf','brain':{'analytics':{'current':{'starts':14,'completions':5,'seconds':110},'previous':{'starts':10,'completions':4,'seconds':95},'watchMinutes':1,'trend':[{'day':'2026-11-22','starts':6},{'day':'2026-11-23','starts':8}],'topVideos':[{'title':'Studio Diary','starts':14,'completions':5,'completionRate':35.7}],'basis':'Aggregate public playback','timingObservation':{'message':'Small sample'}} ,'cards':[{'title':'New review','kind':'publishing','detail':'One pending approval','href':'user-submissions.php'}]},'promotions':[{'id':'b'*32,'title':'Studio Diary','channel':'onsite','state':'draft','headline':'New story','copy_text':'Human-authored text'}],'scheduled':[{'title':'Studio Diary','state':'scheduled','status':'scheduled','publish_at':'2026-12-01T16:00:00Z'}],'publicVideos':[{'id':video,'title':'Studio Diary','description':'A creator description.'}]}}

def main():
    with sync_playwright() as p:
        browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox']);steps=0
        for width in [1440,768,390,320]:
            page=browser.new_page(viewport={'width':width,'height':900})
            errors=[];calls=[];page.on('pageerror',lambda e:errors.append(str(e)))
            page.on('dialog',lambda d:d.accept())
            def route_fn(route):
                import json
                calls.append(route.request.url)
                route.fulfill(status=200,body=json.dumps(payload if 'action=state' in route.request.url else {'ok':True,'data':{'id':'d'*32,'state':'draft'}}),content_type='application/json')
            page.route('**/publishing-agent-api.php?*',route_fn)
            page.set_content('<base href="http://azhottie.test/">'+HTML)
            page.add_style_tag(path=str(ROOT/'assets/publishing-agent.css'))
            page.add_script_tag(path=str(ROOT/'assets/publishing-agent.js'))
            page.locator('.pg-metric').first.wait_for(timeout=8000)
            assert page.locator('.pg-metric').count()==4,('metrics',width)
            assert page.get_by_text('Studio Diary: 14 starts').count()==1,('measured analytics',width)
            page.locator('#pg-suggest').click()
            assert page.locator('#pg-headline').input_value()=='Studio Diary',width
            assert page.locator('#pg-copy').input_value()=='A creator description.',width
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'),('horizontal overflow',width)
            assert not errors,(width,errors)
            page.locator('#pg-draft button[type=submit]').click()
            page.wait_for_function("document.querySelector('#pg-message').textContent.includes('saved')")
            assert any('action=draftPromotion' in url for url in calls),('draft not saved',width)
            assert not errors,(width,errors)
            page.close();steps+=1
        browser.close();print(f'Publishing browser fixture PASS: {steps} responsive editor+analytics+draft workflows')
if __name__=='__main__':main()
