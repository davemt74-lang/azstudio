<?php
declare(strict_types=1);
require __DIR__.'/../includes/agent-casting.php';
$now=1795300000;$id=str_repeat('a',32);
$base=['id'=>$id,'status'=>'new','revision'=>1,'updated_at'=>gmdate('Y-m-d\TH:i:s\Z',$now-86400),'expires_at'=>null,'used_at'=>null];
$assertions=0;
$check=static function(bool $condition,string $label)use(&$assertions):void{++$assertions;if(!$condition)throw new RuntimeException('FAIL '.$label);};
$rules=static fn($data):array=>array_column(az_agent_casting_rules($data,$now),'rule');
$check($rules($base)===['review_24h'],'new day');
$check($rules($base+[])===['review_24h'],'deterministic');
$check($rules(array_replace($base,['updated_at'=>gmdate('Y-m-d\TH:i:s\Z',$now-86399)]))===[],'before 24h');
foreach(['contacted'=>'contacted_7d','screening'=>'screening_7d','interview'=>'interview_7d'] as $stage=>$rule){
    $check($rules(array_replace($base,['status'=>$stage,'updated_at'=>gmdate('Y-m-d\TH:i:s\Z',$now-7*86400)]))===[$rule],'stalled '.$stage);
    $check($rules(array_replace($base,['status'=>$stage]))===[],'recent '.$stage);
}
$check($rules(array_replace($base,['status'=>'approved']))===['invitation_missing'],'missing invitation');
$check($rules(array_replace($base,['status'=>'approved','expires_at'=>$now+86000]))===['invitation_expiring'],'expiring invite');
$check($rules(array_replace($base,['status'=>'approved','expires_at'=>$now+86402]))===[],'valid invite');
$check($rules(array_replace($base,['status'=>'approved','expires_at'=>$now+86000,'used_at'=>gmdate('Y-m-d\TH:i:s\Z',$now-5)]))===['invitation_missing'],'used invite');
$check($rules(array_replace($base,['status'=>'converted']))===[],'converted quiet');
$check($rules(array_replace($base,['status'=>'converted','onboarding_pending'=>true,'updated_at'=>gmdate('Y-m-d\TH:i:s\Z',$now-3*86400)]))===['onboarding_incomplete'],'onboarding 72 hours');
$check($rules(array_replace($base,['status'=>'converted','onboarding_pending'=>false,'updated_at'=>gmdate('Y-m-d\TH:i:s\Z',$now-3*86400)]))===[],'completed onboarding no reminder');
$check($rules(array_replace($base,['status'=>'declined']))===[],'declined quiet');
$check(az_agent_casting_fingerprint($base,'review_24h')!==az_agent_casting_fingerprint(array_replace($base,['revision'=>2]),'review_24h'),'revision resets dedupe');
$check(az_agent_casting_fingerprint($base,'review_24h')===az_agent_casting_fingerprint(array_replace($base,['email'=>'private@example.com','admin_notes'=>'sensitive']), 'review_24h'),'PII not used for fingerprint');
$app=array_replace($base,['questions_json'=>json_encode([['id'=>'experience','required'=>true,'title'=>'Experience'],['id'=>'first-project','required'=>true,'title'=>'Project'],['id'=>'comfort','required'=>false,'title'=>'Preferences']]),'answers_json'=>json_encode(['experience'=>'A few shoots or videos','first-project'=>''])]);
$summary=az_agent_casting_lead_checklist($app,$now);
$check($summary['requiredCount']===2&&$summary['answeredRequired']===1,'required answer counts');
$check($summary['missingRequired']===['Project'],'missing answer is visible only inside admin review');
$check($summary['experience']==='A few shoots or videos','experience summary');
$check(count($summary['recommendations'])===1,'review recommendations');
echo "Agent casting rule tests: $assertions passed\n";
