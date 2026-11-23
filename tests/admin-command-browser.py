#!/usr/bin/env python3
"""Responsive CSS acceptance plus static presence checks against real admin template."""
from pathlib import Path
import os
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('RUNNER_TEMP','/tmp'))/'azh_admin_command_browser'
OUT.mkdir(parents=True,exist_ok=True)
source=(ROOT/'admin-studio.php').read_text()
for anchor in ('az_admin_command_data($user)','azd-kpis','New model résumés','Video submissions',
               'Audience and views tracking','Broadcast commerce','Live Director'):
    assert anchor in source, f'Production template missing {anchor}'
assert '$data=$isAdmin?az_admin_command_data($user):null;' in source, 'Editor exposure risk'
css=(ROOT/'assets/admin-command.css').read_text()
assert 'min-width:0' in css and '@media(max-width:520px)' in css
html='''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">
</head><body style="margin:0;background:#fdfafb"><main class="azd" id="azh-admin-command">
<header class="azd-hero"><div class="azd-hero-copy"><p class="azd-eyebrow">✳ AZHOTTIE / STUDIO OPERATIONS</p>
<h1>Your studio, <em>in focus.</em></h1><p>One place for commerce, new talent, content reviews and audience performance.</p>
<div class="azd-hero-actions"><a href="#" class="azd-btn azd-btn-primary">Open Live Director ↗</a><a href="#" class="azd-btn azd-btn-soft">Commerce Agent briefing</a></div></div>
<div class="azd-broadcast"><span class="azd-broadcast-label"><i class="azd-live-dot"></i> LIVE BROADCAST</span><strong>Studio offline</strong><p>Your next production is ready when you are.</p><a href="#">Open studio ↗</a></div></header>
<section class="azd-section"><div class="azd-section-heading"><div><p class="azd-kicker">BUSINESS AT A GLANCE</p><h2>Studio pulse</h2></div><p class="azd-muted">Revenue last 30 days · Queues current</p></div>
<div class="azd-kpis">
<a href="#" class="azd-kpi azd-kpi-revenue"><span class="azd-kpi-label">Stripe verified net</span><strong>$1,293.50</strong><small>Last 30 days · signed payment events</small></a>
<a href="#" class="azd-kpi azd-kpi-revenue"><span class="azd-kpi-label">Cash recorded sales</span><strong>$250.00</strong><small>Last 30 days · staff recorded</small></a>
<a href="#" class="azd-kpi azd-kpi-revenue"><span class="azd-kpi-label">Live Stripe sales</span><strong>$450.00</strong><small>Last 30 days · live attributed</small></a>
<a href="#" class="azd-kpi azd-kpi-action"><span class="azd-kpi-label">New model applications</span><strong>8</strong><small>Unreviewed casting profiles</small></a>
<a href="#" class="azd-kpi azd-kpi-action"><span class="azd-kpi-label">Video submissions</span><strong>4</strong><small>Awaiting studio approval</small></a>
<a href="#" class="azd-kpi azd-kpi-audience"><span class="azd-kpi-label">Video play starts</span><strong>2,981</strong><small>Last 30 days · not unique viewers</small></a>
</div></section>
<div class="azd-columns azd-columns-main"><section class="azd-panel"><div class="azd-panel-heading"><h2>Needs attention</h2><span class="azd-count-pill">4 queues</span></div>
<div class="azd-task-list">'''+''.join(f'<a href="#" class="azd-task"><span class="azd-task-count">{n}</span><span class="azd-task-text"><strong>{t}</strong><small>Investigate the recorded activity and approve the next step explicitly.</small></span><span class="azd-task-arrow">↗</span></a>' for n,t in [(3,'Provider reconciliation'),(8,'New model applications'),(4,'Video submissions'),(2,'Creator settlements')])+'''</div></section>
<section class="azd-panel"><div class="azd-panel-heading"><h2>Commerce performance</h2></div><div class="azd-revenue-list">
<div><span>Stripe-verified product net</span><strong>$1,293.50</strong></div><div><span>Cash recorded product net</span><strong>$250.00</strong></div><div><span>Manual external order net</span><strong>$100.00</strong></div><div><span>Estimated Stripe commissions</span><strong>$120.00</strong></div></div><div class="azd-commerce-micro"><div><strong>12</strong><span>Active products</span></div><div><strong>25</strong><span>Checkouts</span></div><div><strong>32%</strong><span>Completion</span></div></div></section></div>
<div class="azd-columns azd-columns-review"><section class="azd-panel"><div class="azd-panel-heading"><h2>New model résumés</h2></div><div class="azd-stat-strip"><span><strong>8</strong>New</span><span><strong>4</strong>Screening</span><span><strong>2</strong>Interviews</span></div><div class="azd-review-list"><a href="#" class="azd-review-item"><span class="azd-avatar">A</span><span><strong>Test Applicant</strong><small>Phoenix · New application</small></span></a></div></section>
<section class="azd-panel"><div class="azd-panel-heading"><h2>Video submissions</h2></div><div class="azd-stat-strip"><span><strong>4</strong>Pending</span><span><strong>2</strong>Revisions</span><span><strong>17</strong>Published</span></div><div class="azd-review-list"><a href="#" class="azd-review-item"><span class="azd-avatar azd-avatar-video">▷</span><span><strong>Sample submission</strong><small>Awaiting approval</small></span></a></div></section></div>
<section class="azd-panel azd-audience"><div class="azd-panel-heading"><h2>Audience and views tracking</h2></div><div class="azd-audience-summary">'''+''.join(f'<div><strong>{v}</strong><span>{k}</span></div>' for v,k in [('1,431','Profile page views'),('2,981','Video play starts'),('412','Video completions'),('693','Minutes watched'),('278','Live impressions'),('42','CTA taps')])+'''</div>
<div class="azd-chart-legend"><span>Profile views</span><span>Video plays</span><span>Live product impressions</span></div><div class="azd-chart" role="img">'''+''.join(f'<div class="azd-chart-day"><div class="azd-chart-bars"><i class="is-profiles" style="height:{(j*11)%100}%"></i><i class="is-plays" style="height:{(j*19)%100}%"></i><i class="is-live" style="height:{(j*7)%100}%"></i></div><time>{j+1}</time></div>' for j in range(14))+'''</div><p class="azd-disclaimer">Session-limited events, not unique visitors.</p></section>
<div class="azd-columns azd-columns-bottom"><section class="azd-panel"><h2>Broadcast commerce</h2><div class="azd-empty">Nothing is on air right now.</div><div class="azd-live-summary"><span>Verified live Stripe net</span><strong>$450.00</strong></div></section><section class="azd-panel"><h2>Top products</h2><ol class="azd-top-products"><li><span>1</span><strong>Sample product<small>8 orders</small></strong><b>$380.00</b></li></ol></section></div>
</main></body></html>'''
with sync_playwright() as p:
 options={'headless':True,'args':['--no-sandbox']}
 if Path('/usr/bin/chromium').exists():options['executable_path']='/usr/bin/chromium'
 else:options['channel']='chrome'
 browser=p.chromium.launch(**options)
 for width in (1440,1024,768,390,320):
  page=browser.new_page(viewport={'width':width,'height':900})
  errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
  page.set_content(html)
  page.add_style_tag(path=str(ROOT/'assets/admin-command.css'))
  assert page.get_by_role('heading',name='Studio pulse').count()==1,width
  assert page.locator('.azd-kpi').count()==6,width
  assert page.get_by_role('heading',name='New model résumés').count()==1,width
  assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'),('horizontal overflow',width,page.evaluate('document.documentElement.scrollWidth'))
  assert not page.locator('.azd [style*="overflow: scroll"]').count(),width
  assert not errors,errors
  page.screenshot(path=str(OUT/f'admin_command_{width}.png'),full_page=True)
  print('Admin command responsive CSS PASS',width)
  page.close()
 browser.close()
