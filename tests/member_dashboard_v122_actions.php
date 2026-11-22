<?php
declare(strict_types=1);
require __DIR__.'/../includes/member-dashboard.php';
function verify(bool $pass,string $message): void {if(!$pass)throw new RuntimeException($message);}
$now=new DateTimeImmutable('2026-11-21T17:00:00Z');
$make=static fn(string $id,string $status,?string $due):array=>['id'=>$id,'shot_title'=>'Shot '.$id,'project_title'=>'Private project','status'=>$status,'due_at'=>$due];
$assignments=[
    $make('late','accepted','2026-11-20T17:00:00Z'),
    $make('soon','accepted','2026-11-22T17:00:00Z'),
    $make('week','accepted','2026-11-26T17:00:00Z'),
    $make('future','accepted','2026-12-21T17:00:00Z'),
    $make('submitted','submitted','2026-11-19T17:00:00Z'),
    $make('changes','changes_requested',null),
    $make('awaiting','assigned',null),
];
$deadlines=az_dash_deadline_list($assignments,$now);
verify($deadlines['total']===4,'Only active, dated, unsubmitted assignments are deadlines.');
verify($deadlines['overdue']===1 && $deadlines['next7Days']===2,'Counts must distinguish overdue and coming week.');
verify(array_column($deadlines['items'],'id')===['late','soon','week','future'],'Deadlines must sort chronologically.');
verify(az_dash_deadline_info(null,$now)===null,'Missing due dates cannot be invented.');
verify(az_dash_deadline_info('2026-11-21T17:00:00Z',$now)['state']==='soon','Due now is due soon.');
verify(az_dash_deadline_info('2026-11-21T16:59:59Z',$now)['state']==='overdue','Past deadline is overdue.');
verify(az_dash_deadline_info('2026-12-05T17:00:00Z',$now)['state']==='later','Future deadline is not imminent.');
$videos=[['id'=>str_repeat('a',32),'title'=>'Private title','status'=>'changes_requested'],['id'=>str_repeat('b',32),'title'=>'Under review','status'=>'pending_review']];
$actions=az_dash_priority_actions($assignments,$videos,3,$now);
verify(count($actions)===6,'Only actionable assignment, change-request and admin events are counted.');
verify($actions[0]['label']==='Changes requested','Changes requests should be at the top.');
verify(in_array('my-videos.php?videoId='.str_repeat('a',32),array_column($actions,'href'),true),'Model action must deep link into own My Videos.');
verify(in_array('my-work.php?assignmentId=late',array_column($actions,'href'),true),'Assignment action must deep link to exact assignment.');
verify(!in_array('my-work.php?assignmentId=submitted',array_column($actions,'href'),true),'Submitted work cannot be action required.');
verify(count(array_filter($actions,fn($a)=>$a['href']==='user-submissions.php'))===1,'Admin review queue must be aggregated to one action.');
verify(count(az_dash_priority_actions([],[],0,$now))===0,'Empty state cannot invent actions.');
echo "PASS: deadline boundaries, overdue grouping, priority ordering, admin moderation, deep links, submitted exclusion.\n";
