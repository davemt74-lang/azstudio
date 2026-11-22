<?php
declare(strict_types=1);
/** Role-scoped 30-day Commerce reporting, with amounts kept as integer USD cents. */
function az_commerce_analytics(array $user): array
{
    az_agent_workspace_assert($user);
    $db=az_db();$admin=az_has_role($user,'admin');$id=(int)$user['id'];
    $since=gmdate('Y-m-d\TH:i:s\Z',time()-30*86400);
    $q=$db->prepare("SELECT o.id,o.product_id,o.amount_cents,o.refunded_cents,o.commission_cents,o.provider,
            o.state,o.quantity,o.paid_at,p.title AS product_title,a.channel
        FROM commerce_orders o JOIN commerce_products p ON p.id=o.product_id
        LEFT JOIN commerce_checkout_attempts a ON a.id=SUBSTR(o.external_reference,8) AND o.provider='stripe'
        ".($admin?'':"JOIN commerce_promotions m ON m.id=o.promotion_id AND m.model_id=?")."
        WHERE o.paid_at>=? AND o.state IN ('paid','partially_refunded','refunded','disputed')
        ORDER BY o.paid_at DESC");
    $args=$admin?[$since]:[$id,$since];$q->execute($args);$orders=$q->fetchAll(PDO::FETCH_ASSOC);
    $empty=['orders'=>0,'grossCents'=>0,'refundedCents'=>0,'netCents'=>0,'commissionCents'=>0,'disputedCents'=>0];
    $verified=$empty;$manual=$empty;$products=[];$days=[];$channels=[];
    foreach($orders as $o){
        $kind=$o['provider']==='stripe'?'verified':'manual';
        $metrics=$kind==='verified'?$verified:$manual;
        $gross=(int)$o['amount_cents'];$refund=(int)$o['refunded_cents'];
        if($o['provider']!=='stripe'&&$o['state']==='refunded')$refund=$gross;
        $net=in_array($o['state'],['refunded','disputed'],true)?0:max(0,$gross-$refund);
        $metrics['orders']++;$metrics['grossCents']+=$gross;
        $metrics['refundedCents']+=$refund;$metrics['netCents']+=$net;
        $metrics['commissionCents']+=(int)($o['state']==='disputed'||$o['state']==='refunded'?0:$o['commission_cents']);
        if($o['state']==='disputed')$metrics['disputedCents']+=$gross-$refund;
        if($kind==='verified')$verified=$metrics;else $manual=$metrics;
        if($kind!=='verified')continue;
        $productId=$o['product_id'];
        if(!isset($products[$productId]))$products[$productId]=['id'=>$productId,'title'=>$o['product_title'],
            'orders'=>0,'netCents'=>0,'commissionCents'=>0];
        $products[$productId]['orders']++;$products[$productId]['netCents']+=$net;
        $products[$productId]['commissionCents']+=(int)($o['state']==='disputed'||$o['state']==='refunded'?0:$o['commission_cents']);
        $day=substr((string)$o['paid_at'],0,10);
        if(!isset($days[$day]))$days[$day]=['day'=>$day,'orders'=>0,'netCents'=>0];
        $days[$day]['orders']++;$days[$day]['netCents']+=$net;
        $channel=$o['channel']?:'unknown';
        if(!isset($channels[$channel]))$channels[$channel]=['channel'=>$channel,'orders'=>0,'netCents'=>0];
        $channels[$channel]['orders']++;$channels[$channel]['netCents']+=$net;
    }
    $q=$db->prepare("SELECT a.channel,a.state FROM commerce_checkout_attempts a
        ".($admin?'':"JOIN commerce_promotions m ON m.id=a.promotion_id AND m.model_id=?")."
        WHERE a.created_at>=? AND a.session_id IS NOT NULL");
    $q->execute($args);$attempts=$q->fetchAll(PDO::FETCH_ASSOC);
    $started=count($attempts);$completed=0;
    foreach($attempts as $a)if($a['state']==='paid')$completed++;
    $byProduct=array_values($products);
    usort($byProduct,static fn($a,$b)=>$b['netCents']<=>$a['netCents'] ?: $b['orders']<=>$a['orders']);
    ksort($days);$byChannel=array_values($channels);
    usort($byChannel,static fn($a,$b)=>$b['netCents']<=>$a['netCents']);
    return ['periodDays'=>30,'verified'=>$verified,'manual'=>$manual,
        'checkout'=>['started'=>$started,'paid'=>$completed,
            'paymentRatePercent'=>$started?round(100*$completed/$started,1):null,
            'basis'=>'Stripe paid Checkout Sessions divided by started Checkout Sessions, not product-page impressions.'],
        'topProducts'=>array_slice($byProduct,0,8),'channels'=>$byChannel,'trend'=>array_values($days),
        'basis'=>'30-day gross, refunds, net and commissions in integer USD cents. Stripe events are verified separately from administrator-recorded orders. Disputed revenue withheld. No automatic payouts.'];
}
function az_commerce_analytics_chat(array $user): array
{
    $a=az_commerce_analytics($user);
    return ['periodDays'=>30,'verifiedOrders'=>$a['verified']['orders'],
        'verifiedNetCents'=>$a['verified']['netCents'],
        'verifiedCommissionCents'=>$a['verified']['commissionCents'],
        'manualOrders'=>$a['manual']['orders'],
        'manualNetCents'=>$a['manual']['netCents'],
        'checkoutStarts'=>$a['checkout']['started'],
        'paidCheckoutSessions'=>$a['checkout']['paid'],
        'topProducts'=>array_map(static fn($p)=>['title'=>mb_safe_trim($p['title'],100),
            'verifiedOrders'=>$p['orders'],'verifiedNetCents'=>$p['netCents']],array_slice($a['topProducts'],0,3)),
        'basis'=>'Stripe-signed provider data is verified; administrator-entered sales are recorded but not automatically verified.'];
}
