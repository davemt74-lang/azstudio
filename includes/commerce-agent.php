<?php
declare(strict_types=1);
/**
 * Unified read-only Commerce Agent. All source records remain in the existing
 * Commerce ledgers; this layer only summarizes, recommends, and queues follow-ups.
 * It NEVER initiates a financial provider action or certifies a bank payout.
 */
function az_commerce_agent_admin_report(array $metrics): array
{
    $get=static fn(string $key):int=>max(0,(int)($metrics[$key]??0));
    $actions=[];$findings=[];$questions=[];
    $add=static function(string $title,string $detail,string $href,int $priority=2)use(&$actions):void{
        $actions[]=['title'=>$title,'detail'=>$detail,'href'=>az_agent_workspace_href($href),'priority'=>$priority];
    };
    $provider=$get('refundsNeedsReconciliation')+$get('returnLabelsNeedReconciliation')+$get('connectTransfersNeedReconciliation');
    if($provider){
        $findings[]="$provider provider refund, return-label, or Connect-transfer operations require reconciliation.";
        $add('Reconcile original provider operations','Confirm each original provider outcome before any retry; a Connect transfer is not a bank payout.','commerce.php?tab=providers',0);
        $questions[]='Have the original provider outcomes and signed webhook records been checked?';
    }
    if($get('commissionOverpaidCents')>0){
        $findings[]='Recorded creator payments exceed adjusted commissions on some orders.';
        $add('Investigate commission overpayment','Review refunds, disputes, and earlier payout allocations before approving additional settlements.','commerce.php?tab=payouts',0);
    }
    $orders=$get('commerceOrdersToReview');
    if($orders){
        $findings[]="$orders external/manual orders still have unconfirmed payment status.";
        $add('Review external order evidence','Confirm the payment source before marking an order paid or allocating commissions.','commerce.php?tab=orders',1);
        $questions[]='Which manual orders have independent payment evidence?';
    }
    $returns=$get('returnRequests')+$get('returnAwaitingInspection')+$get('returnRefundApproved');
    if($returns){
        $findings[]="$returns return requests, inspections, or refund decisions need staff follow-through.";
        $add('Process return decisions','Inspect and approve explicitly; a refund approval is not a completed Stripe refund.','commerce.php?tab=returns',1);
    }
    $shipping=$get('shippingExceptions')+$get('carrierLabelsNeedReview');
    if($shipping){
        $findings[]="$shipping shipment exceptions or postage-label outcomes need review.";
        $add('Review shipping exceptions','Investigate original shipping and label records before purchasing replacement postage.','commerce.php?tab=shipping',1);
    }
    if($get('payoutApproved')+$get('payoutDrafts')>0){
        $findings[]=$get('payoutDrafts').' draft and '.$get('payoutApproved').' approved creator settlement batches need review.';
        $add('Review creator settlement batches','Recheck holds and refunds. Do not mark an external payment complete without its real transfer evidence.','commerce.php?tab=payouts',2);
    }
    if($get('localCashPending')>0)$add('Review cash pickup/delivery requests','Confirm totals and handoff; cash requests are not paid orders.','commerce.php?tab=local',2);
    if($get('shippingAwaitingDispatch')+$get('shippingPacked')>0)$add('Prepare paid orders for dispatch','Review stock and shipment confirmation before carrier handoff.','commerce.php?tab=shipping',2);
    if($get('commerceInterests')+$get('commercePromotions')>0)$add('Review creator product opportunities','Approve participation and video attribution only through the existing review controls.','commerce.php?tab=promotions',3);
    if(!$findings)$findings[]='No flagged payment, return, shipping or settlement exceptions were found in the current Commerce aggregates.';
    if(!$actions)$add('Review Commerce performance','Check orders and creator performance for verified opportunities; no financial action is required from this report.','commerce.php?tab=analytics',4);
    usort($actions,static fn($a,$b):int=>$a['priority']<=>$b['priority']?:strcmp($a['title'],$b['title']));
    $first=$actions[0];
    return ['role'=>'admin','keyFindings'=>array_slice($findings,0,6),
        'actionsFollowups'=>array_slice($actions,0,8),
        'openQuestions'=>array_slice($questions,0,3),
        'nextStep'=>$first['title'].': '.$first['detail'],
        'questionsForYou'=>['Which listed issue should the Commerce Agent help investigate first?'],
        'counts'=>array_filter($metrics,static fn($key)=>in_array($key,[
            'commerceOrdersToReview','commerceRecordedPaidOrders','commerceVerifiedPaidOrders',
            'returnRequests','returnAwaitingInspection','returnRefundApproved',
            'shippingExceptions','carrierLabelsNeedReview','shippingAwaitingDispatch','shippingPacked',
            'refundsNeedsReconciliation','returnLabelsNeedReconciliation','connectTransfersNeedReconciliation',
            'payoutDrafts','payoutApproved','eligibleCommissionCents','commissionOverpaidCents','localCashPending'
        ],true),ARRAY_FILTER_USE_KEY)];
}
function az_commerce_agent_creator_report(array $balance,array $batchCounts): array
{
    $c=static fn(string $k):int=>max(0,(int)($balance[$k]??0));
    $draft=max(0,(int)($batchCounts['draft']??0));$approved=max(0,(int)($batchCounts['approved']??0));
    $money=static fn(int $cents):string=>number_format($cents/100,2).' USD';
    $findings=[
        'Eligible, unallocated commissions: '.$money($c('eligibleCents')).'.',
        'Commissions on hold: '.$money($c('heldCents')).'; reserved in draft/approved batches: '.$money($c('reservedCents')).'.',
        'Recorded external/Connect settlements: '.$money($c('recordedPaidCents')).'. Stripe Connect platform transfers are not evidence of bank deposits.'
    ];
    $actions=[];
    if($c('overpaidCents')>0)$actions[]=['title'=>'Review commission adjustment','detail'=>'Refunds or disputes reduced earnings below recorded settlements. Staff must resolve this before another payout.','href'=>'commerce.php?tab=payouts','priority'=>0];
    if($approved>0)$actions[]=['title'=>'Check approved payout status','detail'=>$approved.' approved settlement batches are not automatically confirmed bank deposits.','href'=>'commerce.php?tab=payouts','priority'=>1];
    if($c('heldCents')>0)$actions[]=['title'=>'Review commission holds','detail'=>'Delivery, return, refund and standard settlement holds may delay eligibility.','href'=>'commerce.php?tab=payouts','priority'=>2];
    if($c('eligibleCents')>0)$actions[]=['title'=>'View eligible commissions','detail'=>'Available commissions can be reviewed for a staff-authorized payout; this report cannot send money.','href'=>'commerce.php?tab=payouts','priority'=>3];
    if(!$actions)$actions[]=['title'=>'Explore approved creator promotions','detail'=>'Review approved briefs and attributed sales while awaiting new transactions.','href'=>'commerce.php?tab=promotions','priority'=>4];
    $first=$actions[0];
    return ['role'=>'model','keyFindings'=>$findings,'actionsFollowups'=>$actions,
        'openQuestions'=>$approved?['Has staff supplied confirmation for an approved external payment?']:[],
        'nextStep'=>$first['title'].': '.$first['detail'],
        'questionsForYou'=>['Would you like an explanation of any recorded commission or settlement hold?'],
        'counts'=>['eligibleCommissionCents'=>$c('eligibleCents'),'heldCommissionCents'=>$c('heldCents'),
            'reservedCommissionCents'=>$c('reservedCents'),'recordedPaidCommissionCents'=>$c('recordedPaidCents'),
            'connectTransferredCents'=>$c('connectTransferredCents'),'overpaidCommissionCents'=>$c('overpaidCents'),
            'creatorPayoutDrafts'=>$draft,'creatorPayoutApproved'=>$approved]];
}
function az_commerce_agent_report(array $user):array
{
    az_agent_workspace_assert($user);
    if(az_has_role($user,'admin')){
        $metrics=[];
        foreach([
            az_commerce_briefing($user),az_local_briefing($user),az_fulfillment_briefing($user),
            az_returns_briefing($user),az_payout_briefing($user),az_provider_briefing($user)
        ] as $source)$metrics=array_merge($metrics,$source['counts']??[]);
        $report=az_commerce_agent_admin_report($metrics);
    }else{
        az_commerce_model($user);
        $modelId=(int)$user['id'];
        $balance=az_payout_model_balance($modelId);
        $q=az_db()->prepare("SELECT state,COUNT(*) AS n FROM commerce_payout_batches WHERE model_id=? AND state IN ('draft','approved') GROUP BY state");
        $q->execute([$modelId]);$batchCounts=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$batchCounts[$row['state']]=(int)$row['n'];
        $report=az_commerce_agent_creator_report($balance,$batchCounts);
    }
    return $report+[
        'generatedAt'=>az_now(),'automatedActions'=>false,
        'basis'=>'Role-scoped database facts and existing approval/settlement ledgers. Manual paid status is staff-attested; Stripe payment state uses signed webhooks. Commerce Agent cannot send refunds, buy labels, initiate transfers or confirm bank deposits.'
    ];
}
/** Read-only, scope-safe excerpt for Agent Chat. No customer names, addresses or provider IDs. */
function az_commerce_agent_chat_snapshot(array $user):array
{
    $report=az_commerce_agent_report($user);
    return ['role'=>$report['role'],'keyFindings'=>array_slice($report['keyFindings'],0,3),
        'nextStep'=>$report['nextStep'],
        'actions'=>array_map(static fn($a)=>['title'=>$a['title'],'href'=>$a['href']],array_slice($report['actionsFollowups'],0,3)),
        'counts'=>$report['counts'],'basis'=>$report['basis']];
}
/** Query only source rows that have crossed an attention threshold. */
function az_commerce_agent_due(PDO $db,int $clock,int $limit=80):array
{
    $limit=max(1,min(100,$limit));$cutoff=gmdate('Y-m-d\TH:i:s\Z',$clock-1800);
    $specs=[
        ['refund','commerce_provider_refunds','return_id','initiated_by',"('pending','provider_pending','needs_review')",'commerce.php?tab=providers'],
        ['return_label','commerce_return_labels','return_id','purchased_by',"('pending','needs_review')",'commerce.php?tab=providers'],
        ['connect_transfer','commerce_connect_transfers','batch_id','authorized_by',"('pending','needs_review')",'commerce.php?tab=providers']
    ];
    $events=[];
    foreach($specs as [$kind,$table,$idKey,$actorKey,$statuses,$href]){
        // All identifiers/states are hard-coded. No user-supplied SQL identifiers.
        $q=$db->prepare("SELECT $idKey AS source_id,$actorKey AS actor_id,status,updated_at FROM $table WHERE status IN $statuses AND updated_at<=? ORDER BY updated_at LIMIT $limit");
        $q->execute([$cutoff]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            $id=(string)$row['source_id'];$actor=(int)$row['actor_id'];
            if(!az_valid_id($id)||$actor<1)continue;
            $ts=strtotime((string)$row['updated_at']);if($ts===false||$ts>$clock)continue;
            $stage=$clock-$ts>=86400?'overdue':'attention';
            $label=match($kind){'refund'=>'Stripe refund','return_label'=>'EasyPost return label',default=>'Stripe Connect transfer'};
            $detail=$label.' (reference …'.strtoupper(substr($id,-8)).') has not reached a verified final state after '.
                ($stage==='overdue'?'24 hours':'30 minutes').'. Reconcile the ORIGINAL provider operation; never submit a replacement charge, postage purchase, refund, or transfer.';
            $events[]=['key'=>'commerce-agent:'.$kind.':'.$id.':'.$row['status'].':'.$stage,
                'actorId'=>$actor,'kind'=>'provider_followup','title'=>$label.' needs reconciliation',
                'detail'=>$detail,'href'=>$href,'priority'=>$stage==='overdue'?0:1,'stage'=>$stage];
        }
    }
    // An approved commission batch is not an external settlement. Flag after 48h.
    $payoutCutoff=gmdate('Y-m-d\TH:i:s\Z',$clock-48*3600);
    $q=$db->prepare("SELECT id,created_by,updated_at FROM commerce_payout_batches WHERE state='approved' AND updated_at<=? ORDER BY updated_at LIMIT $limit");
    $q->execute([$payoutCutoff]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        if(!az_valid_id((string)$row['id'])||(int)$row['created_by']<1)continue;
        $events[]=['key'=>'commerce-agent:payout:'.$row['id'].':approved48h',
            'actorId'=>(int)$row['created_by'],'kind'=>'commission_followup','title'=>'Approved creator payout needs confirmation',
            'detail'=>'A creator payout batch has been approved for more than 48 hours but has not been marked settled. Confirm independent payment evidence before any status change. No bank transfer was initiated.',
            'href'=>'commerce.php?tab=payouts','priority'=>1,'stage'=>'attention'];
    }
    usort($events,static fn($a,$b):int=>$a['priority']<=>$b['priority']?:strcmp($a['key'],$b['key']));
    return array_slice($events,0,$limit);
}
/** Idempotent, bounded notification scan for the EXISTING Agent Chat, bell, Brain and History. */
function az_commerce_agent_scan():array
{
    $events=az_commerce_agent_due(az_db(),time());$admins=az_agent_workspace_admin_ids();
    foreach($events as $event){
        foreach($admins as $admin){
            az_commerce_queue($event['key'],$event['actorId'],$admin,$event['kind'],
                $event['title'],$event['detail'],$event['href'],true);
        }
    }
    $delivery=az_commerce_dispatch(100);
    return ['checked'=>count($events),'adminRecipients'=>count($admins),'delivery'=>$delivery,
        'basis'=>'Existing idempotent Commerce outbox. Alerts do not initiate provider actions.'];
}
