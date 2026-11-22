<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
function ensure(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
$now=strtotime('2026-11-21T12:00:00Z');
$row=['id'=>str_repeat('a',32),'user_id'=>5,'owner_id'=>7,'status'=>'assigned','revision'=>1,
 'updated_at'=>gmdate('Y-m-d\TH:i:s\Z',$now-25*3600),'submitted_at'=>null,'due_at'=>null,
 'title'=>'First scene','project_title'=>'Autumn Show','model_name'=>'Casey'];
function names(array $row,int $now):array{return array_column(az_agent_followthrough_rules($row,$now),'rule');}
ensure(names($row,$now)===['unanswered_24h'],'24h model reminder');
$row['updated_at']=gmdate('Y-m-d\TH:i:s\Z',$now-23*3600);ensure(names($row,$now)===[],'No premature reminder');
$row['updated_at']=gmdate('Y-m-d\TH:i:s\Z',$now-49*3600);
ensure(names($row,$now)===['unanswered_24h','unanswered_48h'],'48h owner escalation');
$rules=az_agent_followthrough_rules($row,$now);
ensure($rules[0]['recipient']===5 && $rules[1]['recipient']===7,'No cross-account recipient');
ensure(str_contains($rules[0]['href'],'my-work.php') && str_contains($rules[1]['href'],'productions.php'),'Correct user-scoped destinations');
$row['status']='accepted';$row['due_at']=gmdate('Y-m-d\TH:i:s\Z',$now+48*3600);
ensure(names($row,$now)===['due_72h'],'Due soon alert');
$row['due_at']=gmdate('Y-m-d\TH:i:s\Z',$now+4*86400);ensure(names($row,$now)===[],'No early deadline');
$row['due_at']=gmdate('Y-m-d\TH:i:s\Z',$now-1);
ensure(names($row,$now)===['overdue_model','overdue_admin'],'Overdue both audiences');
$row['status']='changes_requested';ensure(names($row,$now)===['overdue_model','overdue_admin'],'Revisions also need follow-through');
$row['status']='submitted';$row['submitted_at']=gmdate('Y-m-d\TH:i:s\Z',$now-49*3600);
ensure(names($row,$now)===['review_48h'],'Unreviewed submission admin notice');
$row['submitted_at']=gmdate('Y-m-d\TH:i:s\Z',$now-12*3600);ensure(names($row,$now)===[],'No premature review alert');
$row['status']='approved';ensure(names($row,$now)===[],'Approved work never reminded');
$row['status']='declined';ensure(names($row,$now)===[],'Declined work never reminded');
$row['status']='assigned';$row['submitted_at']=null;$row['due_at']=null;$fp=az_agent_followthrough_fingerprint($row,'unanswered_24h');
$row['revision']=2;ensure(az_agent_followthrough_fingerprint($row,'unanswered_24h')!==$fp,'Revision starts new cycle');
$row['revision']=1;$row['due_at']=gmdate('Y-m-d\TH:i:s\Z',$now+100);ensure(az_agent_followthrough_fingerprint($row,'unanswered_24h')!==$fp,'Due date starts new cycle');
$row['due_at']=null;ensure(az_agent_followthrough_fingerprint($row,'unanswered_24h')===$fp,'Identical state is idempotent');
echo "Follow-through rule tests passed (15 scenarios).\n";
