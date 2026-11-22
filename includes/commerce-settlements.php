<?php
declare(strict_types=1);
/**
 * Creator settlements are EXTERNAL payment records, not payout transfers.
 * Draft allocations reserve a commission; approval and paid confirmation
 * revalidate original payment, returns, disputes and fulfillment.
 */
function az_payout_hold_days(): int
{
    $raw=getenv('AZH_COMMISSION_HOLD_DAYS');
    if(!is_string($raw)||!preg_match('/^[0-9]{1,2}$/D',$raw))return 14;
    return max(7,min(90,(int)$raw));
}
function az_payout_model_account(int $model): array
{
    if($model<1)throw new AzHttpError(400,'Select a creator.','model_required');
    $user=az_account_user($model);
    if(!$user||($user['status']??'')!=='active'||!az_has_role($user,'model'))
        throw new AzHttpError(404,'Eligible creator not found.','model_not_found');
    return $user;
}
function az_payout_order_rows(int $model): array
{
    $q=az_db()->prepare("SELECT o.*,p.title AS product_title,
        sh.status AS shipment_status,loc.status AS local_status,loc.payment_status AS local_payment_status
        FROM commerce_orders o
        JOIN commerce_promotions m ON m.id=o.promotion_id AND m.model_id=?
        JOIN commerce_products p ON p.id=o.product_id
        LEFT JOIN commerce_shipments sh ON sh.order_id=o.id
        LEFT JOIN commerce_local_orders loc ON loc.order_id=o.id
        ORDER BY o.paid_at DESC,o.id DESC");
    $q->execute([$model]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function az_payout_order_hold(PDO $db,array $order): ?string
{
    if(!in_array($order['state'],['paid','partially_refunded'],true))
        return 'Payment not eligible or under refund/dispute';
    if((int)$order['commission_cents']<=0)
        return 'No commission remains after adjustments';
    if($order['paid_at']===null)
        return 'Paid timestamp is not established';
    $paid=strtotime((string)$order['paid_at']);
    if(!$paid||$paid+az_payout_hold_days()*86400>time())
        return 'Standard post-payment settlement hold';
    if($order['provider']==='cash'){
        if(($order['local_payment_status']??'')!=='collected'||($order['local_status']??'')!=='fulfilled')
            return 'Cash not collected or order not fulfilled';
    }elseif($order['provider']==='stripe'&&$order['shipment_status']!==null
        &&$order['shipment_status']!=='delivered')
        return 'Shipment not confirmed delivered';
    $q=$db->prepare("SELECT COUNT(*) FROM commerce_returns WHERE order_id=?
        AND status IN ('authorized','received','inspected','refund_approved','exchange_approved')");
    $q->execute([$order['id']]);
    if((int)$q->fetchColumn()>0)return 'An authorized return, refund or exchange is unresolved';
    return null;
}
function az_payout_allocated(PDO $db,string $orderId,?string $state=null): int
{
    $sql="SELECT COALESCE(SUM(l.amount_cents),0) FROM commerce_payout_allocations l
        JOIN commerce_payout_batches b ON b.id=l.batch_id WHERE l.order_id=?";
    $args=[$orderId];
    if($state==='paid'){$sql.=" AND b.state='paid'";}
    elseif($state==='reserved'){$sql.=" AND b.state IN ('draft','approved')";}
    else $sql.=" AND b.state IN ('draft','approved','paid')";
    $q=$db->prepare($sql);$q->execute($args);return (int)$q->fetchColumn();
}
function az_payout_model_balance(int $model,bool $includeOrders=false): array
{
    az_payout_model_account($model);
    $db=az_db();$eligible=0;$held=0;$reserved=0;$paid=0;$debt=0;$rows=[];
    foreach(az_payout_order_rows($model) as $order){
        $value=max(0,(int)$order['commission_cents']);
        $total=az_payout_allocated($db,$order['id']);
        $paidToCreator=az_payout_allocated($db,$order['id'],'paid');
        $pending=az_payout_allocated($db,$order['id'],'reserved');
        $overpaid=max(0,$paidToCreator-$value);
        $debt+=$overpaid;$paid+=$paidToCreator;$reserved+=$pending;
        $reason=az_payout_order_hold($db,$order);
        $available=max(0,$value-$total);
        if($reason===null)$eligible+=$available;
        else $held+=$available;
        if($includeOrders){
            $rows[]=[
                'id'=>$order['id'],'title'=>$order['product_title'],
                'state'=>$order['state'],'provider'=>$order['provider'],
                'commissionCents'=>$value,'allocatedCents'=>$total,
                'paidCents'=>$paidToCreator,'pendingCents'=>$pending,
                'availableCents'=>$reason===null?$available:0,
                'holdReason'=>$reason,'overpaidCents'=>$overpaid,'paidAt'=>$order['paid_at']
            ];
        }
    }
    return ['modelId'=>$model,'eligibleCents'=>$eligible,'heldCents'=>$held,
        'reservedCents'=>$reserved,'recordedPaidCents'=>$paid,
        'overpaidCents'=>$debt,'newPayoutBlocked'=>$debt>0,
        'holdDays'=>az_payout_hold_days(),
        'orders'=>$includeOrders?$rows:[],
        'basis'=>'Gross merchandise commissions only; payment, settlement hold, delivery, returns and disputes are checked before drafting/approving a payout. Paid is a staff-confirmed external transfer, not money sent by AZHottie.'];
}
function az_payout_models(array $user): array
{
    az_commerce_admin($user);
    $db=az_db();
    $models=$db->query("SELECT DISTINCT u.id,u.name FROM users u
        JOIN user_roles r ON r.user_id=u.id AND r.role='model'
        JOIN commerce_promotions p ON p.model_id=u.id
        WHERE u.status='active' ORDER BY u.name LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    $result=[];
    foreach($models as $m){
        $balance=az_payout_model_balance((int)$m['id']);
        $result[]=['id'=>(int)$m['id'],'name'=>$m['name'],
            'eligibleCents'=>$balance['eligibleCents'],'heldCents'=>$balance['heldCents'],
            'reservedCents'=>$balance['reservedCents'],'paidCents'=>$balance['recordedPaidCents'],
            'overpaidCents'=>$balance['overpaidCents']];
    }
    return $result;
}
function az_payout_batch_row(string $id): array
{
    if(!az_valid_id($id))throw new AzHttpError(404,'Payout batch not found.','not_found');
    $q=az_db()->prepare('SELECT * FROM commerce_payout_batches WHERE id=?');
    $q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new AzHttpError(404,'Payout batch not found.','not_found');
    return $row;
}
function az_payout_batches(array $user): array
{
    az_agent_workspace_assert($user);
    $admin=az_has_role($user,'admin');$db=az_db();
    $sql="SELECT b.*,u.name AS model_name FROM commerce_payout_batches b
       JOIN users u ON u.id=b.model_id ".($admin?'':'WHERE b.model_id=? ').
       'ORDER BY b.created_at DESC,b.id DESC LIMIT 150';
    $q=$db->prepare($sql);$q->execute($admin?[]:[(int)$user['id']]);
    return array_map(static fn($r)=>[
        'id'=>$r['id'],'modelId'=>(int)$r['model_id'],'modelName'=>$r['model_name'],
        'amountCents'=>(int)$r['amount_cents'],'status'=>$r['state'],
        'paymentChannel'=>$r['payment_channel'],
        'evidenceReference'=>$r['state']==='paid'?$r['evidence_reference']:'',
        'revision'=>(int)$r['revision'],
        'createdAt'=>$r['created_at'],'approvedAt'=>$r['approved_at'],
        'confirmedAt'=>$r['confirmed_at']
    ],$q->fetchAll(PDO::FETCH_ASSOC));
}
/** Recheck all allocations, not merely the aggregate batch amount. */
function az_payout_verify_batch(PDO $db,array $batch): array
{
    $balance=az_payout_model_balance((int)$batch['model_id']);
    if($balance['overpaidCents']>0)
        throw new AzHttpError(409,'Previously paid commissions exceed the updated earned amount. Resolve this debt before any payout.','commission_overpaid');
    $q=$db->prepare("SELECT l.order_id,l.amount_cents FROM commerce_payout_allocations l WHERE l.batch_id=?");
    $q->execute([$batch['id']]);$lines=$q->fetchAll(PDO::FETCH_ASSOC);
    if(!$lines)throw new AzHttpError(409,'Payout has no commission allocations.','payout_empty');
    $byOrder=[];
    foreach(az_payout_order_rows((int)$batch['model_id']) as $order)$byOrder[$order['id']]=$order;
    $sum=0;
    foreach($lines as $line){
        $order=$byOrder[$line['order_id']]??null;
        if(!$order)throw new AzHttpError(409,'Payout references an unrelated order.','payout_model_mismatch');
        $hold=az_payout_order_hold($db,$order);
        if($hold!==null)throw new AzHttpError(409,'Payout is on hold: '.$hold,'commission_held');
        $allocated=az_payout_allocated($db,$line['order_id']);
        if($allocated>(int)$order['commission_cents'])
            throw new AzHttpError(409,'Commission changed since payout creation. Cancel or review the draft.','commission_changed');
        $sum+=(int)$line['amount_cents'];
    }
    if($sum!==(int)$batch['amount_cents'])
        throw new AzHttpError(409,'Payout allocation totals changed.','payout_amount_mismatch');
    return $lines;
}
/** Drafts are reservations of eligible commission, not real transfers. */
function az_payout_draft(array $user,array $body): array
{
    az_commerce_admin($user);
    $model=az_integer($body['modelId']??0,1,PHP_INT_MAX,'Creator');
    az_payout_model_account($model);
    $actor=(int)$user['id'];
    return az_commerce_returns_lock_write(static function(PDO $db)use($model,$actor):array{
        $balance=az_payout_model_balance($model);
        if($balance['overpaidCents']>0)
            throw new AzHttpError(409,'Creator has a post-payout refund liability. Resolve it before drafting another payout.','commission_overpaid');
        $allocations=[];$sum=0;
        foreach(az_payout_order_rows($model) as $row){
            if(az_payout_order_hold($db,$row)!==null)continue;
            $amount=max(0,(int)$row['commission_cents']-az_payout_allocated($db,$row['id']));
            if($amount<1)continue;
            $allocations[]=['orderId'=>$row['id'],'amountCents'=>$amount];
            $sum+=$amount;
            if(count($allocations)>=100)break;
        }
        if(!$allocations||$sum<1)
            throw new AzHttpError(409,'No eligible unallocated commissions are available.','no_eligible_commissions');
        $batch=az_id();$now=az_now();
        $db->prepare("INSERT INTO commerce_payout_batches(id,model_id,amount_cents,state,created_by,created_at,updated_at)
            VALUES(?,?,?,'draft',?,?,?)")
            ->execute([$batch,$model,$sum,$actor,$now,$now]);
        $insert=$db->prepare('INSERT INTO commerce_payout_allocations(batch_id,order_id,amount_cents) VALUES(?,?,?)');
        foreach($allocations as $a)$insert->execute([$batch,$a['orderId'],$a['amountCents']]);
        az_commerce_queue_admins('payout-draft:'.$batch,$actor,'commission_draft',
            'Creator payout draft needs approval',
            'An external creator payout draft of '.number_format($sum/100,2).' USD was reserved. No money was sent.',false);
        return ['id'=>$batch,'state'=>'draft','amountCents'=>$sum,'orders'=>count($allocations),'revision'=>1];
    });
}
function az_payout_change(array $user,array $body): array
{
    az_commerce_admin($user);
    $batchId=az_text($body['id']??'',32,'Payout batch');
    $action=az_text($body['decision']??'',12,'Payout decision');
    if(!in_array($action,['approve','paid','cancel'],true))
        throw new AzHttpError(400,'Invalid payout action.','invalid_payout_action');
    $revision=az_integer($body['revision']??0,1,PHP_INT_MAX,'Payout revision');
    if($action==='approve'&&($body['confirmApproval']??false)!==true)
        throw new AzHttpError(400,'Explicit payout approval required.','confirmation_required');
    if($action==='paid'&&($body['confirmPaid']??false)!==true)
        throw new AzHttpError(400,'Confirm the actual external transfer before marking paid.','confirmation_required');
    $reference=$action==='paid'?az_local_clean($body['evidenceReference']??'',160,'Payout transaction reference',true):'';
    $channel=$action==='paid'?az_text($body['paymentChannel']??'',24,'Payout channel'):'';
    if($action==='paid'&&(mb_strlen($reference)<8||!in_array($channel,['bank','cash','paypal','other'],true)))
        throw new AzHttpError(400,'Record the verified external transfer channel and reference.','payout_evidence_required');
    $actor=(int)$user['id'];
    return az_commerce_returns_lock_write(static function(PDO $db)use($batchId,$action,$revision,$reference,$channel,$actor):array{
        $batch=az_payout_batch_row($batchId);
        if((int)$batch['revision']!==$revision)
            throw new AzHttpError(409,'Payout changed. Reload before approving.','revision_conflict');
        if(($action==='approve'&&$batch['state']!=='draft')
            ||($action==='paid'&&$batch['state']!=='approved')
            ||($action==='cancel'&&!in_array($batch['state'],['draft','approved'],true)))
            throw new AzHttpError(409,'Payout cannot make that transition.','invalid_transition');
        if($action!=='cancel')az_payout_verify_batch($db,$batch);
        $now=az_now();
        if($action==='approve'){
            $db->prepare("UPDATE commerce_payout_batches SET state='approved',approved_by=?,
                approved_at=?,updated_at=?,revision=revision+1 WHERE id=? AND revision=?")
               ->execute([$actor,$now,$now,$batchId,$revision]);
        }elseif($action==='paid'){
            $q=$db->prepare('SELECT COUNT(*) FROM commerce_payout_batches WHERE evidence_reference=? AND id<>?');
            $q->execute([$reference,$batchId]);
            if((int)$q->fetchColumn()>0)
                throw new AzHttpError(409,'External payout reference already recorded.','payout_reference_reused');
            $db->prepare("UPDATE commerce_payout_batches SET state='paid',payment_channel=?,evidence_reference=?,
                confirmed_by=?,confirmed_at=?,updated_at=?,revision=revision+1 WHERE id=? AND revision=?")
               ->execute([$channel,$reference,$actor,$now,$now,$batchId,$revision]);
        }else{
            $db->prepare("UPDATE commerce_payout_batches SET state='cancelled',updated_at=?,
                revision=revision+1 WHERE id=? AND revision=?")
               ->execute([$now,$batchId,$revision]);
        }
        $state=match($action){'approve'=>'approved','paid'=>'paid',default=>'cancelled'};
        az_commerce_queue_admins('payout:'.$batchId.':'.$action,$actor,'commission_'.$action,
            'Creator payout '.$state,'An external commission settlement was '.$state.
            ' for '.number_format((int)$batch['amount_cents']/100,2).' USD. No transfer was initiated by AZHottie.',true);
        if($action==='paid')az_commerce_queue('payout-model:'.$batchId,$actor,(int)$batch['model_id'],
            'commission_paid','Creator commission payout recorded',
            'An administrator confirmed an external commission payment of '.
            number_format((int)$batch['amount_cents']/100,2).' USD. Verify the payment using your normal payout method.','commerce.php',true);
        return ['id'=>$batchId,'state'=>$state,'amountCents'=>(int)$batch['amount_cents'],
            'revision'=>$revision+1];
    });
}
function az_payout_role_state(array $user): array
{
    az_agent_workspace_assert($user);
    if(az_has_role($user,'admin')){
        return ['role'=>'admin','models'=>az_payout_models($user),'batches'=>az_payout_batches($user),
            'holdDays'=>az_payout_hold_days(),'notice'=>'Payouts are external manual records. AZHottie never transfers funds.'];
    }
    return ['role'=>'model','balance'=>az_payout_model_balance((int)$user['id'],true),
        'batches'=>az_payout_batches($user),
        'notice'=>'Paid means staff recorded a separate external transfer; no money is sent automatically by AZHottie.'];
}
function az_payout_briefing(array $user): array
{
    if(!az_has_role($user,'admin'))return ['cards'=>[],'counts'=>[]];
    $db=az_db();
    $draft=(int)$db->query("SELECT COUNT(*) FROM commerce_payout_batches WHERE state='draft'")->fetchColumn();
    $approved=(int)$db->query("SELECT COUNT(*) FROM commerce_payout_batches WHERE state='approved'")->fetchColumn();
    $debt=0;$eligible=0;
    foreach(az_payout_models($user) as $m){
        $debt+=(int)$m['overpaidCents'];
        $eligible+=(int)$m['eligibleCents'];
    }
    $cards=[];
    if($debt)$cards[]=az_agent_workspace_card('commerce','Commission overpayment needs reconciliation',
        number_format($debt/100,2).' USD of recorded creator payouts exceeds updated order earnings after refunds or disputes. Further payouts are blocked.','commerce.php',0);
    if($draft)$cards[]=az_agent_workspace_card('commerce','Creator commission drafts need approval',
        $draft.' payout drafts are reserved but not approved. Recheck orders and return holds before proceeding.','commerce.php',1);
    if($approved)$cards[]=az_agent_workspace_card('commerce','Approved creator payouts awaiting confirmation',
        $approved.' approved payouts require real external transfer evidence before they can be marked paid.','commerce.php',1);
    if($eligible>0)$cards[]=az_agent_workspace_card('commerce','Eligible commissions ready for payout review',
        number_format($eligible/100,2).' USD is eligible across creators subject to fresh refund/fulfillment checks.','commerce.php',2);
    return ['cards'=>$cards,'counts'=>['payoutDrafts'=>$draft,'payoutApproved'=>$approved,
        'eligibleCommissionCents'=>$eligible,'commissionOverpaidCents'=>$debt]];
}

/** No auto-payout: shared cron checks only overdue external payment evidence. */
function az_payout_scan(): array
{
    $cutoff=gmdate('Y-m-d\TH:i:s\Z',time()-7*86400);
    $q=az_db()->prepare("SELECT b.id,b.state,b.amount_cents,b.created_by
        FROM commerce_payout_batches b WHERE b.state IN ('draft','approved')
        AND b.updated_at<=? ORDER BY b.updated_at LIMIT 100");
    $q->execute([$cutoff]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $b){
        az_commerce_queue_admins('payout-stale:'.$b['id'].':'.$b['state'],
            (int)$b['created_by'],'payout_followup',
            'Creator payout needs reconciliation',
            'A '.$b['state'].' commission batch remains open after seven days. Recheck refund holds and external payment proof. No transfer is initiated.',true);
    }
    return ['checked'=>count($rows),'dispatch'=>az_commerce_dispatch(90)];
}
