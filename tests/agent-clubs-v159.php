<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
if(!in_array('sqlite',PDO::getAvailableDrivers(),true))throw new RuntimeException('SQLite PDO is required.');
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('PRAGMA foreign_keys=ON');
$GLOBALS['az_database']=$db;
$GLOBALS['az_config_cache']=['features'=>['creator_club_test_grants'=>false]];
az_create_schema($db);
$checks=0;
function check159Agent(bool $condition,string $label):void {if(!$condition)throw new RuntimeException($label);$GLOBALS['checks']++;}
check159Agent(az_schema_version($db)===57,'Fresh installations run the homepage, Club workspace, publishing and feed migrations.');
$insert=$db->prepare('INSERT INTO users(id,name,email,password_hash,created_at) VALUES(?,?,?,?,?)');
foreach([[1,'Creator','model'],[2,'Fan One','fan'],[3,'Administrator','admin'],[4,'Fan Two','fan']] as [$id,$name,$role]){
    $insert->execute([$id,$name,'fixture'.$id.'@example.invalid','fixture-not-a-login-hash',az_now()]);
    $db->prepare('INSERT INTO user_roles(user_id,role) VALUES(?,?)')->execute([$id,$role]);
}
$fan=['id'=>2,'status'=>'active','roles'=>['fan']];
$other=['id'=>4,'status'=>'active','roles'=>['fan']];
check159Agent(az_agent_activity_available($fan),'Fans can open their own activity drawer.');
check159Agent(!az_agent_workspace_available($fan),'Fan activity does not grant the existing creator-commerce permission.');
check159Agent(!az_agent_activity_available(['id'=>2,'status'=>'disabled','roles'=>['fan']]),'Inactive fans cannot open activity.');
foreach([
    'existing creator workspace'=>fn()=>az_agent_workspace_assert($fan),
    'creator commerce briefs and commission rates'=>fn()=>az_commerce_state($fan),
    'creator commerce analytics'=>fn()=>az_commerce_analytics($fan),
    'creator commerce agent report'=>fn()=>az_commerce_agent_report($fan),
    'creator settlement records'=>fn()=>az_payout_role_state($fan),
    'creator connected account state'=>fn()=>az_connect_account_state($fan,2)
] as $label=>$read){
    $denied=false;try{$read();}catch(AzHttpError $e){$denied=$e->status===403;}
    check159Agent($denied,'Fan cannot read '.$label.'.');
}
check159Agent(az_agent_workspace_role(['roles'=>['admin','model','fan']])==='admin','Administrative roles keep their existing scope.');
foreach(['creator-clubs.php','creator-club.php?creator=1','creator-club-workspace.php','creator-club-admin.php'] as $href){
    check159Agent(az_agent_workspace_href($href)===$href,'Creator Club links use the shared navigation allowlist.');
}
foreach(['https://example.invalid','//example.invalid','creator-club.php?next=//evil.invalid','../creator-club.php'] as $href){
    try{az_agent_workspace_href($href);throw new RuntimeException('Unsafe href accepted.');}catch(InvalidArgumentException){$checks++;}
}
$db->beginTransaction();
az_commerce_queue('v159-rollback',3,2,'club_notice','Must roll back','No persisted source action.','creator-club-workspace.php',true,$db);
$db->rollBack();
check159Agent((int)$db->query('SELECT COUNT(*) FROM commerce_activity')->fetchColumn()===0,'Outbox rolls back with source transaction.');
az_commerce_queue('v159-one',3,2,'club_notice','Your club update','Open My Clubs for current access.','creator-club-workspace.php',true,$db);
az_commerce_queue('v159-one',3,2,'club_notice','Your club update','Open My Clubs for current access.','creator-club-workspace.php',true,$db);
az_commerce_queue('v159-other',3,4,'club_notice','Other account only','Other account only.','creator-club-workspace.php',true,$db);
check159Agent((int)$db->query('SELECT COUNT(*) FROM commerce_activity')->fetchColumn()===2,'Repeated source event queues once per recipient.');
$delivery=az_commerce_dispatch();
check159Agent($delivery['delivered']===2&&$delivery['failed']===0,'Notifications and Chat deliver through the existing transactional dispatcher.');
check159Agent(az_commerce_dispatch()['delivered']===0,'A retried delivery does not duplicate alerts.');
az_agent_workspace_emit(3,2,'casting_review_needed','PRIVATE STAFF RECORD','PRIVATE APPLICANT DETAILS','casting.php',true);
$state=az_agent_workspace_state($fan);
check159Agent($state['role']==='fan'&&$state['unreadCount']===1,'Fan badge counts only that fan’s recorded activity.');
check159Agent(count($state['history'])===1&&$state['history'][0]['title']==='Your club update','Cross-account notifications are absent.');
check159Agent($state['history'][0]['actor']==='Creator Club','Club notices use a generic actor label instead of a private account name.');
check159Agent(!str_contains(json_encode($state,JSON_THROW_ON_ERROR),'Administrator'),'Fan notices do not expose the reviewing administrator’s account name.');
check159Agent(!str_contains(json_encode($state,JSON_THROW_ON_ERROR),'PRIVATE STAFF RECORD'),'A fan role does not reveal earlier staff notifications after demotion.');
foreach(['casting','commerce','commerceAgent','management','actionCenter','operationsLearning','scheduling','video','liveHealth','followthrough'] as $key){
    check159Agent(!array_key_exists($key,$state['brain']),'Fan Brain omits staff context: '.$key);
}
check159Agent($state['brain']['assignOptions']===[],'Fan Brain offers no production assignment actions.');
$snapshot=az_agent_workspace_chat_snapshot($fan);
check159Agent($snapshot['role']==='fan'&&$snapshot['creatorClubs']['billingEnabled']===false,'Chat distinguishes free Club activity from paid subscriptions.');
check159Agent(array_keys($snapshot)===['role','counts','creatorClubs','suggestions'],'Fan AI context contains only the scoped Club snapshot.');
$chat=az_agent_chat_state(2);
check159Agent(count($chat['threads'])===1&&$chat['threads'][0]['title']==='Creator Club Updates','Club notices use an account-owned thread in existing Chat.');
check159Agent(!str_contains(json_encode($chat,JSON_THROW_ON_ERROR),'Other account only'),'Another fan’s Chat notice is private.');
$event=$state['history'][0]['id'];
az_agent_workspace_mark_read(4,['ids'=>[$event]],true);
check159Agent(az_agent_workspace_unread_count($fan)===1,'Another fan cannot mark this notification read.');
az_agent_workspace_mark_read(2,['ids'=>[$event]],true);
check159Agent(az_agent_workspace_unread_count($fan)===0&&az_agent_workspace_unread_count($other)===1,'Read status changes only for the authenticated recipient.');
az_agent_workspace_mark_read(2,['all'=>true],true);
check159Agent((int)$db->query("SELECT COUNT(*) FROM agent_workspace_events WHERE recipient_id=2 AND kind='casting_review_needed' AND read_at IS NULL")->fetchColumn()===1,'Fan mark-all-read leaves inaccessible staff records unchanged.');
echo 'AZHottie v1.59 shared Club activity integration: '.$checks." checks passed\n";
