<?php
declare(strict_types=1);
/**
 * EasyPost tracker.updated signed webhook. Duplicate and out-of-order events
 * are idempotent. Carrier scan states are never supplied by a browser.
 */
function az_carrier_webhook_valid(string $raw,string $signature,string $secret): bool
{
    if(strlen($raw)>262144||strlen($signature)>180||strlen($secret)<24)return false;
    if(!preg_match('/^hmac-sha256-hex=([0-9a-f]{64})$/iD',trim($signature),$m))return false;
    $expected=hash_hmac('sha256',$raw,$secret);
    return hash_equals($expected,strtolower($m[1]));
}
function az_carrier_webhook_receive(string $raw,string $signature): array
{
    $cfg=az_carrier_config();
    if(!$cfg['webhookConfigured']||!$cfg['configured'])
        throw new AzHttpError(503,'Carrier webhook is not configured.','carrier_webhook_unavailable');
    if(!az_carrier_webhook_valid($raw,$signature,$cfg['webhookSecret']))
        throw new AzHttpError(400,'Invalid carrier webhook signature.','carrier_signature_invalid');
    $event=json_decode($raw,true,32);
    if(!is_array($event)||($event['object']??'')!=='Event'
        ||!is_string($event['id']??null)
        ||!preg_match('/^evt_[A-Za-z0-9_]{6,120}$/D',$event['id'])
        ||($event['mode']??'')!==$cfg['mode'])
        throw new AzHttpError(400,'Invalid carrier event for this environment.','carrier_event_invalid');
    $kind=(string)($event['description']??'');
    if(strlen($kind)>80)throw new AzHttpError(400,'Carrier event type invalid.','carrier_event_invalid');
    $result=$event['result']??null;
    $trackerId='';
    if($kind==='tracker.updated'){
        if(!is_array($result)||($result['object']??'')!=='Tracker'
            ||!is_string($result['id']??null)
            ||!preg_match('/^trk_[A-Za-z0-9_]{6,}$/D',$result['id']))
            throw new AzHttpError(400,'Carrier tracker payload invalid.','carrier_tracker_invalid');
        $trackerId=$result['id'];
    }
    $db=az_db();$db->beginTransaction();
    try{
        $q=$db->prepare('SELECT event_id FROM commerce_carrier_events WHERE event_id=?');
        $q->execute([$event['id']]);
        if($q->fetchColumn()!==false){
            $db->commit();return ['duplicate'=>true,'processed'=>false];
        }
        $processed=false;
        if($kind==='tracker.updated'){
            $q=$db->prepare("SELECT l.order_id,l.tracking_number,l.mode,l.tracking_updated_at,
                 s.status AS shipment_status,o.recorded_by,p.title
                 FROM commerce_carrier_labels l
                 JOIN commerce_shipments s ON s.order_id=l.order_id
                 JOIN commerce_orders o ON o.id=l.order_id
                 JOIN commerce_products p ON p.id=o.product_id
                 WHERE l.tracker_id=? AND l.status='purchased'");
            $q->execute([$trackerId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row)throw new AzHttpError(409,'Carrier tracking is not yet linked to a purchased label.','carrier_tracker_not_ready');
            if($row['mode']!==$cfg['mode']||
                (string)($result['tracking_code']??'')!==$row['tracking_number'])
                throw new AzHttpError(409,'Carrier tracking identity mismatched.','carrier_tracker_mismatch');
            $status=(string)($result['status']??'');
            $allow=['unknown','pre_transit','in_transit','out_for_delivery','delivered',
                'available_for_pickup','return_to_sender','failure','cancelled','error'];
            if(!in_array($status,$allow,true))$status='unknown';
            $when=(string)($result['updated_at']??$event['created_at']??'');
            $ts=strtotime($when);
            if($ts===false||$ts>time()+3600||$ts<0)
                throw new AzHttpError(400,'Carrier status timestamp invalid.','carrier_event_invalid');
            $when=gmdate('Y-m-d\TH:i:s\Z',$ts);
            $old=$row['tracking_updated_at']===null?0:(strtotime($row['tracking_updated_at'])?:0);
            if($ts>$old){
                $now=az_now();
                $db->prepare('UPDATE commerce_carrier_labels SET tracking_status=?,tracking_updated_at=?,updated_at=? WHERE order_id=?')
                    ->execute([$status,$when,$now,$row['order_id']]);
                $current=$row['shipment_status'];
                $next=$current;
                if($status==='delivered')$next='delivered';
                elseif(in_array($status,['in_transit','out_for_delivery','available_for_pickup'],true)&&$current!=='delivered')
                    $next='shipped';
                elseif(in_array($status,['return_to_sender','failure','cancelled','error'],true)&&$current!=='delivered')
                    $next='exception';
                // A newer "unknown" or "pre_transit" scan never demotes a
                // previously delivered parcel or a shipment staff marked shipped.
                $shipped=$next==='shipped'&&$current!=='shipped'?$now:null;
                $delivered=$next==='delivered'&&$current!=='delivered'?$now:null;
                $db->prepare('UPDATE commerce_shipments SET carrier_tracking_status=?,
                    carrier_tracking_updated_at=?,status=?,
                    shipped_at=COALESCE(shipped_at,?),delivered_at=COALESCE(delivered_at,?),
                    revision=revision+1,updated_at=? WHERE order_id=?')
                    ->execute([$status,$when,$next,$shipped,$delivered,$now,$row['order_id']]);
                if($status==='delivered'||$next==='exception'||$status==='out_for_delivery'){
                    az_commerce_queue_admins('carrier-update:'.$event['id'],
                        (int)$row['recorded_by'],
                        'carrier_'.str_replace(' ','_',$status),
                        $status==='delivered'?'Carrier confirmed delivery':
                        ($next==='exception'?'Carrier tracking exception':'Parcel out for delivery'),
                        $row['title'].' · EasyPost signed tracker event: '.$status.
                        '. Open Shipping for carrier details and any follow-up.',true);
                }
            }
            $processed=true;
        }
        $db->prepare('INSERT INTO commerce_carrier_events(event_id,tracker_id,event_kind,received_at) VALUES(?,?,?,?)')
            ->execute([$event['id'],$trackerId,$kind,az_now()]);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    try{az_commerce_dispatch(80);}catch(Throwable $e){error_log('Carrier event dispatch deferred.');}
    return ['duplicate'=>false,'processed'=>$processed];
}
