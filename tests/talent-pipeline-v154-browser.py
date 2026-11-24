#!/usr/bin/env python3
"""Real Chromium responsive acceptance of the new Talent Hub and private follow-up controls."""
from pathlib import Path
import os
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('RUNNER_TEMP','/tmp'))/'azh_talent_v154_browser';OUT.mkdir(parents=True,exist_ok=True)
source=(ROOT/'casting.php').read_text(encoding='utf-8')
for anchor in ('az_require_admin()', 'id="talent-hub-title"','id="casting-followup-title"','name="next_action"','name="due_at"','name="revision"',"'pipeline'"):
    assert anchor in source,anchor
HTML='''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;background:#fcf9fa">
<main class="az-casting-workspace" style="padding:12px;max-width:1400px;margin:auto">
<nav class="az-casting-tabs"><a href="#">Applicant CRM</a><a href="#">Edit questions</a><a href="#">Onboarding</a><a href="#" class="active">Talent Pipeline</a></nav>
<section class="az-casting-card az-talent-hub"><div class="az-card-heading"><div><h2 id="talent-hub-title">Talent Pipeline & Follow-ups</h2><p>Staff workflow only.</p></div><a href="#">Production assignments</a></div>
<div class="az-talent-stats">'''+''.join(f'<a href="#"><strong>{i+2}</strong><span>{name}</span></a>' for i,name in enumerate(['New','Contacted','Screening','Interview','Approved','Converted','Declined']))+'''</div>
<div class="az-talent-grid"><section class="az-talent-column"><h3>Next actions & deadlines</h3>
'''+''.join(f'<article class="az-talent-task"><div><strong>Stage Talent {n}</strong><span class="az-talent-state">screening</span></div><p>Confirm interview date and project availability with the applicant</p><small>Due Nov 25, 2:00 PM AZ</small></article>' for n in range(3))+'''</section>
<section class="az-talent-column"><h3>Converted creators & performance</h3>
'''+''.join(f'<article class="az-talent-task"><strong>Creator {n}</strong><p>6 published videos · 35 play starts</p><small>Onboarding 3/4</small><div class="az-talent-links"><a href="#">Performance</a><a href="#">Assignments</a></div></article>' for n in range(3))+'''</section></div></section>
<section class="az-casting-card az-talent-followup"><div class="az-card-heading"><h2 id="casting-followup-title">Private follow-ups</h2></div>
<form class="az-talent-form"><label>Next action<input name="next_action" value="Confirm availability"></label><label>Due time<input type="datetime-local" name="due_at"></label><button class="az-primary-button">Save follow-up</button></form>
<article class="az-talent-task"><strong>Confirm model interview</strong><form class="az-talent-resolve"><label>Private completion note<textarea name="resolution_note"></textarea></label><div class="az-talent-links"><button class="az-casting-outline">Mark complete</button><button class="az-casting-outline">Cancel</button></div></form></article></section>
</main></body></html>'''
with sync_playwright() as p:
  opts={'headless':True,'args':['--no-sandbox']}
  if Path('/usr/bin/chromium').exists():opts['executable_path']='/usr/bin/chromium'
  else:opts['channel']='chrome'
  browser=p.chromium.launch(**opts)
  for width in (1440,768,390,320):
    page=browser.new_page(viewport={'width':width,'height':840});errors=[]
    page.on('pageerror',lambda e:errors.append(str(e)))
    page.set_content(HTML);page.add_style_tag(path=str(ROOT/'assets/casting.css'))
    assert page.get_by_role('heading',name='Talent Pipeline & Follow-ups').count()==1
    assert page.get_by_role('button',name='Save follow-up').is_visible()
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'),('horizontal overflow',width,page.evaluate('document.documentElement.scrollWidth'))
    assert not errors,(width,errors)
    page.screenshot(path=str(OUT/f'talent_hub_{width}.png'),full_page=True)
    print('v1.54 Talent Hub responsive PASS',width)
    page.close()
  browser.close()
