<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
function viewer_order_ok(bool $condition,string $message):void{
  if(!$condition)throw new RuntimeException($message);
}
function viewer_order_reject(callable $callback,string $code):void{
  try{$callback();throw new RuntimeException('Expected '.$code);}
  catch(AzHttpError $error){viewer_order_ok($error->errorCode===$code,'Wrong join denial '.$error->errorCode);}
}
if(!in_array('sqlite',PDO::getAvailableDrivers(),true))throw new RuntimeException('pdo_sqlite is required');
$now=time();
function viewer_nonce(int $milliseconds,string $suffix):string{
  return str_pad(dechex($milliseconds),12,'0',STR_PAD_LEFT).str_repeat($suffix,20);
}
$before=viewer_nonce($now*1000-3000,'a');
$after=viewer_nonce($now*1000+3000,'b');
$latest=viewer_nonce($now*1000+6000,'c');
viewer_order_ok(az_live_join_nonce_stamp($before,$now)===$now*1000-3000,'Ordered nonce timestamp missing');
viewer_order_ok(az_live_join_nonce_stamp($after,$now)>az_live_join_nonce_stamp($before,$now),'Join sequence must be monotonic');
viewer_order_ok(az_live_join_nonce_stamp('f'.str_repeat('0',31),$now)===null,'Legacy or out-of-range nonce must stay compatible');
viewer_order_ok(az_live_join_nonce_stamp('invalid',$now)===null,'Invalid nonce must not be ordered');
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE az_live_rooms(id TEXT PRIMARY KEY,owner_id INTEGER,title TEXT,status TEXT,started_epoch INTEGER,heartbeat_epoch INTEGER)');
$db->exec('CREATE TABLE az_live_viewers(id TEXT PRIMARY KEY,room_id TEXT,user_id INTEGER,nonce TEXT,
  offer_sdp TEXT,answer_sdp TEXT,status TEXT,updated_epoch INTEGER,created_epoch INTEGER)');
$room=str_repeat('d',32);$viewer=7;
$db->prepare('INSERT INTO az_live_rooms VALUES(?,?,?,?,?,?)')->execute([$room,1,'Private Test','live',$now-20,$now]);
$GLOBALS['az_database']=$db;
$_SERVER['HTTP_HOST']='localhost';
$_SERVER['HTTPS']='on';
$sdp="v=0\r\no=- 1 1 IN IP4 0.0.0.0\r\n";
$new=az_live_join($viewer,$room,$after,$sdp);
viewer_order_ok(az_valid_id($new['viewerId']),'Server failed to issue a viewer lease');
viewer_order_reject(static fn()=>az_live_join($viewer,$room,$before,$sdp),'viewer_stale_join');
$q=$db->prepare('SELECT id,nonce,status FROM az_live_viewers WHERE room_id=? AND user_id=? ORDER BY created_epoch,id');
$q->execute([$room,$viewer]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
viewer_order_ok(count($rows)===1,'Obsolete late Join must not create another viewer');
viewer_order_ok($rows[0]['nonce']===$after&&$rows[0]['status']==='waiting','Obsolete late Join evicted active viewer');
$again=az_live_join($viewer,$room,$after,$sdp);
viewer_order_ok($again['viewerId']===$new['viewerId'],'Same-nonce retry must remain idempotent');
$replaced=az_live_join($viewer,$room,$latest,$sdp);
viewer_order_ok($replaced['viewerId']!==$new['viewerId'],'Newer Join must replace the previous lease');
$q->execute([$room,$viewer]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
viewer_order_ok(count($rows)===2&&count(array_filter($rows,static fn($r)=>$r['status']==='waiting'))===1,
  'Newer session replacement must leave exactly one active viewer');
viewer_order_reject(static fn()=>az_live_join($viewer,$room,$after,$sdp),'viewer_stale_join');
viewer_order_ok(az_live_leave($viewer,$replaced['viewerId'])['left']===true,'Viewer explicit Leave failed');
viewer_order_reject(static fn()=>az_live_join($viewer,$room,$latest,$sdp),'viewer_stale_join');
$legacy=str_repeat('f',32);
$oldClient=az_live_join($viewer,$room,$legacy,$sdp);
viewer_order_ok(az_valid_id($oldClient['viewerId']),'Legacy random nonce Join compatibility broken');
viewer_order_ok($db->query("SELECT COUNT(*) FROM az_live_viewers WHERE status='waiting'")->fetchColumn()==1,
    'Legacy client must not create overlapping active slots');
$orderedAgain=az_live_join($viewer,$room,viewer_nonce($now*1000+9000,'e'),$sdp);
viewer_order_ok(az_valid_id($orderedAgain['viewerId']),'Ordered nonce cannot join after legacy client');
viewer_order_ok((int)$db->query("SELECT COUNT(*) FROM az_live_viewers WHERE status='waiting'")->fetchColumn()===1,
    'At most one active join may remain for the same member');
viewer_order_ok(AZ_LIVE_MAX_VIEWERS===6,'The existing six-viewer P2P cap must remain unchanged');
print "Live fan join ordering: late request denial, owner session preserved, idempotency, compatibility, six-slot cap PASS\n";
