<?php
declare(strict_types=1);
/**
 * Staff-only provider exception triage. Query only existing local financial ledgers;
 * never contact Stripe/EasyPost, retry a POST, or reveal private provider identifiers.
 */
function az_provider_triage_snapshot(PDO $db,int $limit=24,?int $clock=null):array
{
    $limit=max(1,min(50,$limit));
    $now=$clock??time();
    $sources=[
        ['kind'=>'refund','tab'=>'returns','table'=>'commerce_provider_refunds','key'=>'return_id',
         'statuses'=>"('pending','needs_review','provider_pending')"],
        ['kind'=>'return_label','tab'=>'returns','table'=>'commerce_return_labels','key'=>'return_id',
         'statuses'=>"('pending','needs_review')"],
        ['kind'=>'connect_transfer','tab'=>'payouts','table'=>'commerce_connect_transfers','key'=>'batch_id',
         'statuses'=>"('pending','needs_review')"]
    ];
    $items=[];
    foreach($sources as $src){
        // SQL identifiers and state filters are hardcoded above. Not user-controlled.
        $sql="SELECT {$src['key']} AS record_id,status,mode,updated_at ".
             "FROM {$src['table']} WHERE status IN {$src['statuses']} ORDER BY updated_at ASC LIMIT 51";
        $q=$db->query($sql);
        while(($r=$q->fetch(PDO::FETCH_ASSOC))!==false){
            $id=(string)($r['record_id']??'');
            if(!az_valid_id($id))continue;
            $status=(string)$r['status'];
            $updated=(string)($r['updated_at']??'');
            $ts=strtotime($updated);
            $age=$ts===false?null:max(0,$now-$ts);
            $overdue=$age===null||$age>=86400;
            $urgency=$overdue?'overdue':($status==='needs_review'||$age>=1800?'attention':'watch');
            $reason=match($src['kind']){
                'refund'=>$status==='provider_pending'
                  ?'Refund request identified; a signed charge.refunded event and matching order ledger are still required.'
                  :'Original Stripe refund outcome needs investigation. Never submit another refund request.',
                'return_label'=>'Original EasyPost label purchase may be unresolved. Inspect the existing shipment; never buy another label.',
                default=>'Original Stripe Connect platform transfer may be unresolved. Reconcile before sending any new transfer.'
            };
            $items[]=[
                'kind'=>$src['kind'], 'reference'=>strtoupper(substr($id,-8)),
                'tab'=>$src['tab'],'status'=>$status,
                'environment'=>in_array($r['mode'],['test','live','production'],true)?$r['mode']:'unknown',
                'updatedAt'=>$ts===false?null:gmdate('Y-m-d\TH:i:s\Z',$ts),
                'ageMinutes'=>$age===null?null:intdiv($age,60),
                'urgency'=>$urgency,'nextAction'=>$reason
            ];
        }
    }
    $rank=['overdue'=>0,'attention'=>1,'watch'=>2];
    usort($items,static function(array $a,array $b)use($rank):int{
        return ($rank[$a['urgency']]<=>$rank[$b['urgency']])
            ?: (($b['ageMinutes']??PHP_INT_MAX)<=>($a['ageMinutes']??PHP_INT_MAX))
            ?: strcmp($a['kind'].$a['reference'],$b['kind'].$b['reference']);
    });
    $totalRows=count($items);
    return ['items'=>array_slice($items,0,$limit),
       'truncated'=>$totalRows>$limit || $totalRows>=153,
       'warningAfterMinutes'=>30,'overdueAfterMinutes'=>1440,
       'notice'=>'Read-only records. Provider verification may require the existing staff reconciliation controls. Repeating a charge, refund or transfer is never an automatic next step.'];
}
/** Evidence is descriptive only: local TEST-mode records do not certify hosted acceptance. */
function az_provider_test_evidence(PDO $db):array
{
    $specs=[
      ['id'=>'refund','label'=>'Stripe TEST refund ledger confirmation',
       'table'=>'commerce_provider_refunds','state'=>'ledger_verified','date'=>'verified_at',
       'limitation'=>'Signed refund and matched order ledger recorded; hosted acceptance still requires staff testing.'],
      ['id'=>'return_label','label'=>'EasyPost TEST return postage purchase',
       'table'=>'commerce_return_labels','state'=>'purchased','date'=>'purchased_at',
       'limitation'=>'Provider label response recorded; carrier webhook delivery and host setup are separate checks.'],
      ['id'=>'connect_transfer','label'=>'Stripe Connect TEST platform transfer',
       'table'=>'commerce_connect_transfers','state'=>'transferred','date'=>'reconciled_at',
       'limitation'=>'Platform transfer recorded, not a verified creator bank deposit.']
    ];
    $out=[];
    foreach($specs as $s){
        // Hardcoded SQL table and column identifiers: no query strings or API inputs.
        $q=$db->prepare("SELECT COUNT(*) AS n, MAX({$s['date']}) AS latest FROM {$s['table']} WHERE mode=? AND status=?");
        $q->execute(['test',$s['state']]);$r=$q->fetch(PDO::FETCH_ASSOC);
        $date=(string)($r['latest']??'');$ts=$date!==''?strtotime($date):false;
        $out[]=['id'=>$s['id'],'label'=>$s['label'],'observedCount'=>(int)($r['n']??0),
         'latestAt'=>$ts===false?null:gmdate('Y-m-d\TH:i:s\Z',$ts),
         'limitation'=>$s['limitation'], 'hostedAccepted'=>false];
    }
    return $out;
}
