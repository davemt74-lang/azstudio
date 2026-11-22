#!/usr/bin/env python3
"""Chromium DOM/layout test using real calendar markup/JS and mocked API values.
No app server or production database is contacted.
"""
from pathlib import Path
from playwright.sync_api import sync_playwright
import subprocess
root=Path(__file__).resolve().parents[1]
markup=subprocess.check_output(['php','-r',f'function az_h(?string $s):string{{return htmlspecialchars($s??"",ENT_QUOTES,"UTF-8");}} require "{root}/includes/schedule-views.php"; az_render_schedule_view("calendar");'],text=True)
css=(root/'assets/schedule-agent.css').read_text()+'\n'+(root/'assets/schedule.css').read_text()
code=(root/'assets/schedule-agent.js').read_text().replace("import {scheduleApi,setScheduleCsrf} from './schedule-api.js';","const scheduleApi=window.__mockScheduleApi;const setScheduleCsrf=()=>{};")
with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    tests=0
    for width in [1440,768,390,320]:
        for admin in (False,True):
            page=browser.new_page(viewport={'width':width,'height':850})
            errors=[]
            page.on('pageerror',lambda err:errors.append(str(err)))
            page.set_content('<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body>'+markup+'</body></html>')
            page.add_style_tag(content=css)
            page.evaluate("""(admin) => {
              window.__posts=[];
              window.__mockScheduleApi=async (action,payload=null,query={})=>{
                if(payload!==null){window.__posts.push([action,payload]);return {};}
                if(action==='bootstrap')return {csrf:'fixture',canManageEvents:admin,user:{id:1,roles:admin?['admin']:['model']}};
                if(action==='unified')return {timezone:'America/Phoenix',items:[
                  {id:'a'.repeat(32),type:'appointment',title:'Private session',startAt:'2026-11-25T17:00:00Z',href:'appointments.php'},
                  {id:'b'.repeat(32),type:'event',title:'Studio event',startAt:'2026-11-26T20:00:00Z',href:'calendar.php'},
                  {id:'c'.repeat(32),type:'production',title:'Video session',startAt:'2026-11-27T17:00:00Z',href:'productions.php'},
                  {id:'d'.repeat(32),type:'assignment',title:'Shot deadline',startAt:'2026-11-29T17:00:00Z',href:'my-work.php?assignmentId='+('d'.repeat(32))}],
                  counts:{upcoming:4,appointments:1,assignments:1,conflicts:1},conflicts:[{first:'a',second:'b'}],
                  availability:admin?null:{configured:false,revision:0,weekly:{},blackouts:[]}};
                if(action==='productionOptions')return [{id:'e'.repeat(32),title:'Studio production'}];
                if(action==='recommendModels')return {models:[{modelId:2,label:'Roster model',availability:'available',reason:'No conflict'},{modelId:3,label:'Other model',availability:'unknown',reason:'Not configured'}]};
                return {};
              };
            }""", admin)
            page.add_script_tag(type='module',content=code)
            page.locator('.sa-metric').first.wait_for(timeout=10000)
            assert page.get_by_text('4',exact=True).count()>=1
            overflow=page.evaluate('document.documentElement.scrollWidth>window.innerWidth')
            assert not overflow,(admin,width,'horizontal overflow')
            if admin:
                page.get_by_text('Production scheduling recommendations').wait_for()
                page.locator('.sa-match-form input[type="datetime-local"]').first.fill('2026-11-25T10:00')
                page.locator('.sa-match-form input[type="datetime-local"]').nth(1).fill('2026-11-25T11:00')
                page.get_by_role('button',name='Check eligible models').click()
                page.get_by_text('Roster model').wait_for()
                assert page.get_by_text('UNKNOWN',exact=False).count()>=1
            else:
                if not page.locator('details.sa-availability').evaluate('(e)=>e.open'):
                    page.get_by_text('Set my model availability').click()
                page.get_by_role('button',name='Add time').nth(1).click()
                page.get_by_role('button',name='Save weekly availability').click()
                page.wait_for_function('document.querySelector("#schedule-agent-status").textContent.includes("Availability saved")')
                assert page.evaluate('window.__posts.some(p=>p[0]==="saveModelAvailability"&&p[1].weekly["1"].length===1)')
            assert errors==[],(admin,width,errors)
            tests+=1
            page.close()
    browser.close()
print(f'Calendar Agent browser fixture PASS: {tests} role/viewport scenarios, edits and roster matching')
