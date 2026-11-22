#!/usr/bin/env python3
"""Exercise actual editor agent controls against a deterministic local API fixture.
No production account, video, or server is contacted.
"""
from pathlib import Path
from playwright.sync_api import sync_playwright
import re
root=Path(__file__).resolve().parents[1]
src=(root/'my-editor.php').read_text()
match=re.search(r'<section class="vs-pane" data-vs-panel="assist" hidden>(.*?)</section>',src,re.S)
assert match, 'Missing actual Video Studio assistant pane'
markup=match.group(1)
css=(root/'assets/video-agent.css').read_text()
script=(root/'assets/video-agent.js').read_text()
with sync_playwright() as pw:
    browser=pw.chromium.launch(headless=True, executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage'])
    for w in [1440,768,390,320]:
        page=browser.new_page(viewport={'width':w,'height':900});errs=[]
        page.on('pageerror',lambda e:errs.append(str(e)))
        page.set_content('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><base href="http://localhost/"></head><body style="margin:0;color:#eef;background:#101b2c;font-family:Arial"><main class="personal-timeline" data-csrf="fixture" style="max-width:410px;width:100%;box-sizing:border-box;padding:8px;margin:auto"><section class="vs-pane">'+markup+'</section></main></body></html>')
        page.add_style_tag(content='''
          .vs-label{display:block;margin:.6rem 0}.vs-label input,.vs-label select{display:block;max-width:100%;width:100%;box-sizing:border-box;background:#132539;color:#fff;border:1px solid #a4b4c6;padding:.5rem}
          .vs-btn{border:1px solid #a4b4c6;background:#273b51;color:#fff;padding:.5rem;cursor:pointer;border-radius:5px}.vs-btn:disabled{opacity:.5}
          .vs-check{display:block;margin:.65rem 0}.vs-hint{font-size:.9rem;line-height:1.4}.vs-two{display:grid;grid-template-columns:1fr 1fr;gap:.5rem}
          .vs-accordion{padding:.5rem;background:#182b40}
        '''+css)
        page.evaluate('''() => {
          window.__plans=[];window.__notes=[];window.__posts=[];window.confirm=()=>true;
          window.fetch=async (url,options={})=>{
            const query=new URL(url).searchParams,act=query.get('action'),body=options.body?JSON.parse(options.body):null;
            if(body)window.__posts.push([act,body]);
            let result={};
            if(act==='state')result={plans:window.__plans,moments:window.__notes};
            else if(act==='plan')result=window.__plans.find(p=>p.id===query.get('id'));
            else if(act==='addMoment'){
              result={id:'m'.repeat(32),mediaId:body.mediaId,startMs:body.startMs,endMs:body.endMs,kind:body.kind,note:body.note};window.__notes.push(result);
            }else if(act==='generate'){
              result={id:'p'.repeat(32),projectId:body.projectId,projectRevision:body.revision,status:'proposed',targetSeconds:body.targetSeconds,goal:body.goal,proposal:{basis:'source timing',durationMs:5000,selectedCount:1,sourceCount:1,warnings:['Review before export'],timeline:{clips:[{id:'x'.repeat(32),trimStartMs:1000,trimEndMs:6000}]}}};
              window.__plans.unshift(result);
            }else if(act==='apply'){
              window.__plans[0]={...window.__plans[0],status:'applied',appliedRevision:3};
              result={plan:window.__plans[0],project:{revision:3}};
            }else if(act==='revert'){
              window.__plans[0]={...window.__plans[0],status:'reverted'};
              result={plan:window.__plans[0],project:{revision:4}};
            }else if(act==='discard'){
              window.__plans[0]={...window.__plans[0],status:'discarded'};result=window.__plans[0];
            }else if(act==='removeMoment'){window.__notes=[];result={removed:true};}
            return {ok:true,status:200,json:async()=>({ok:true,data:structuredClone(result)})};
          };
        }''')
        page.add_script_tag(type='module',content=script)
        page.get_by_text('Save an edit before generating plans.').wait_for(timeout=5000)
        page.evaluate('''() => { const r=document.querySelector('.personal-timeline');r.dataset.videoMediaId='a'.repeat(32);r.dataset.videoStartMs='1000';r.dataset.videoEndMs='6000';window.dispatchEvent(new CustomEvent('azh:video-editor-clip')); }''')
        page.evaluate('''() => window.dispatchEvent(new CustomEvent('azh:video-editor-project',{detail:{projectId:'c'.repeat(32),revision:2}}))''')
        page.locator('details.vs-accordion').evaluate('(el)=>el.open=true')
        page.locator('#vagent-note-text').fill('Creator-verified dialogue note')
        page.locator('#vagent-note-add').click()
        page.get_by_text('Creator-verified dialogue note').wait_for(timeout=5000)
        page.locator('#vagent-generate').click()
        page.get_by_text('Proposed cut',exact=False).last.wait_for(timeout=5000)
        assert page.locator('#vagent-preview-clips li').count()==1
        page.locator('#vagent-approve').click()
        page.wait_for_function('window.__plans[0]?.status==="applied"',timeout=5000)
        page.locator('#vagent-revert').click()
        page.wait_for_function('window.__plans[0]?.status==="reverted"',timeout=5000)
        assert page.evaluate("window.__posts.some(x=>x[0]==='apply'&&x[1].approve===true)")
        assert page.evaluate("document.documentElement.scrollWidth<=window.innerWidth"),(w,'overflow')
        assert errs==[],(w,errs)
        page.close()
    browser.close()
print('Video Agent browser fixture PASS: 4 viewports, source notes, approve, revert, overflow, errors')
