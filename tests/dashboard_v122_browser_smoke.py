#!/usr/bin/env python3
"""Render actual dashboard.php using an isolated, synthetic-only PHP stub and
check responsive overflow/contrast in Chromium. Needs PHP + Playwright.
"""
from pathlib import Path
import tempfile, subprocess, re, datetime
from playwright.sync_api import sync_playwright
app=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='azh-v122-fixture-',dir='/mnt/data') as scratch:
    directory=Path(scratch);(directory/'includes').mkdir()
    (directory/'dashboard.php').write_bytes((app/'dashboard.php').read_bytes())
    (directory/'includes/universal-header.php').write_text('<?php')
    (directory/'includes/member-layout.php').write_text('<?php')
    (directory/'includes/bootstrap.php').write_text(r'''<?php
function az_require_auth(){return ['id'=>1,'name'=>'Jordan Sample','display_name'=>'Jordan Sample','roles'=>[getenv('TEST_ROLE')?:'model']];}
function az_has_role($u,$r){return in_array($r,$u['roles'],true);}
function az_h($s){return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function az_render_universal_header($where,$user){echo '<header class="universal-header" style="height:54px;padding:12px;background:white">AZHOTTIE.</header>';}
function az_render_member_layout_open($a,$u){echo '<div class="azh-member-layout"><aside class="azh-member-sidebar"><div class="azh-side-navigation"><nav><a class="azh-side-link is-active" href="dashboard.php">Overview</a><a class="azh-side-link" href="my-work.php">My assignments</a><a class="azh-side-link" href="my-videos.php">My Videos</a></nav></div></aside><div class="azh-member-canvas"><div class="azh-canvas-topline">AZHOTTIE / OVERVIEW</div>';}
function az_render_member_layout_close(){echo '</div></div>';}
function az_model_video_dashboard_summary(){return ['pending'=>2,'published'=>3,'changes_requested'=>1];}
function az_dash_date($s,$fmt='M j, Y'){return (new DateTimeImmutable($s))->setTimezone(new DateTimeZone('America/Phoenix'))->format($fmt);}
function az_dashboard_data($u){
    $role=$u['roles'][0];$creator=in_array($role,['model','admin','professional']);$model=$role==='model';
    $days=[];$start=new DateTimeImmutable('2026-11-08');
    for($i=0;$i<14;$i++)$days[]=['day'=>$start->modify("+$i days")->format('Y-m-d'),'profileViews'=>[1,2,0,3,1,4,6,0,8,2,5,4,7,10][$i],'starts'=>[0,0,0,1,2,0,3,4,5,0,2,1,3,6][$i]];
    $assigned=['id'=>str_repeat('c',32),'shot_title'=>'Close-up camera introduction','project_title'=>'Autumn studio production','status'=>'accepted','due_at'=>'2026-11-22T22:00:00Z','updated_at'=>'2026-11-21T05:00:00Z'];
    $deadline=['state'=>'soon','label'=>'Due within 48 hours','dateText'=>'Nov 22, 3:00 PM MST','timestamp'=>1795384800];
    return ['metrics'=>['profileViews'=>75,'profileViews30d'=>53,'publishedInterviews'=>2,'lastInterviewPublishedAt'=>'2026-11-14T18:00:00Z','publishedModelVideos'=>3,'pendingModelVideos'=>1],
      'engagement'=>['days'=>14,'trend'=>$days,'totalPlayStarts'=>27,'totalCompletions'=>18,'watchMinutes'=>92,'current'=>['profileViews'=>53,'starts'=>27],'previous'=>['starts'=>10]],
      'productionsCount'=>$creator?2:0,'productions'=>$creator?[['title'=>'AZHottie Lifestyle Sessions','kind'=>'interview','updated_at'=>'2026-11-18T00:00:00Z']]:[],
      'assignmentsCount'=>$creator?1:0,'assignments'=>$creator?[$assigned]:[],
      'openShotsCount'=>0,'openShots'=>[],
      'deadlines'=>['total'=>$creator?1:0,'overdue'=>0,'next7Days'=>$creator?1:0,'items'=>$creator?[$assigned+['deadline'=>$deadline]]:[]],
      'actionCount'=>$creator?2:0,'actionItems'=>$creator?[['href'=>'my-work.php?assignmentId='.str_repeat('c',32),'title'=>'Close-up camera introduction','context'=>'Autumn studio production','detail'=>'Finish and submit your assigned shot.','tone'=>'soon','label'=>'Due soon'],['href'=>'my-videos.php?videoId='.str_repeat('d',32),'title'=>'Weekend behind the scenes','context'=>'My Videos · Admin review','detail'=>'Edit your video and resubmit for approval.','tone'=>'urgent','label'=>'Changes requested']]:[],
      'scheduleCount'=>1,'schedule'=>[['title'=>'Studio workshop','startAt'=>'2026-11-28T18:00:00Z','location'=>'Main studio','kind'=>'event','status'=>'registered','href'=>'calendar.php']],
      'videos'=>['draft'=>2,'published'=>3,'pending_review'=>1,'changes_requested'=>1]];
}
''')
    outputs={}
    for role in ('model','admin','member'):
        from os import environ
        env=dict(environ,TEST_ROLE=role)
        content=subprocess.check_output(['php',str(directory/'dashboard.php')],env=env,stderr=subprocess.STDOUT).decode()
        # Use actual production CSS, but isolate this fixture from the real host.
        content=re.sub(r'<link[^>]*href="assets/([^"]+)"[^>]*>', lambda m: '<style>'+((app/'assets'/m.group(1).split('?')[0]).read_text())+'</style>' if m.group(1).split('?')[0].endswith('.css') else '', content)
        path=directory/(role+'.html');path.write_text(content);outputs[role]=path
    with sync_playwright() as p:
        browser=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--allow-file-access-from-files'])
        for role in ('model','admin','member'):
            for width in (1440,1024,768,390,320):
                page=browser.new_page(viewport={'width':width,'height':850},device_scale_factor=1)
                errors=[];page.on('pageerror',lambda error:errors.append(str(error)))
                page.set_content(outputs[role].read_text(),wait_until='load')
                data=page.evaluate('''()=>{
                  const box=document.querySelector('.azh-dash-engagement'),bar=document.querySelectorAll('.azh-engagement-chart>li'),actions=document.querySelector('.azh-dash-attention');
                  const style=getComputedStyle(box);return {width:innerWidth,scrollWidth:document.documentElement.scrollWidth,background:style.backgroundColor,
                  color:style.color,barCount:bar.length,actionLinks:actions.querySelectorAll('a.azh-attention-row').length,
                  sectionCount:document.querySelectorAll('.azh-dash-deadlines').length};}''')
                assert data['scrollWidth']<=width+2,(role,width,data)
                assert data['barCount']==14,(role,width,data)
                assert data['background']=='rgb(255, 255, 255)',(role,width,data)
                assert not errors,(role,width,errors)
                if role=='model':
                    assert data['actionLinks']==2 and data['sectionCount']==1
                    if width==1440:page.screenshot(path='/mnt/data/AZHottie_v1.22.0_dashboard_desktop_preview.png',full_page=True)
                    if width==390:page.screenshot(path='/mnt/data/AZHottie_v1.22.0_dashboard_mobile_preview.png',full_page=True)
                if role=='member':assert data['actionLinks']==0 and data['sectionCount']==0
                print(role,width,'PASS',data)
                page.close()
        browser.close()
print('PASS: 15 synthetic Chromium dashboard layouts, white engagement cards, fourteen-day charts, no horizontal overflow.')
