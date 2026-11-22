<?php
declare(strict_types=1);
/**
 * Return state machine. Only a staff member can authorize, inspect, restock,
 * approve a refund/exchange, or confirm an external provider refund.
 */
function az_return_admin_transition(array $user,array $body): array
{
    az_commerce_admin($user);
    $id=az_text($body['id']??'',32,'Return case ID');
    if(!az_valid_id($id))throw new AzHttpError(404,'Return case not found.','not_found');
    $action=az_text($body['decision']??'',24,'Return action');
    if(!in_array($action,['authorize','reject','receive','inspect','approve_refund',
        'confirm_refund','approve_exchange','dispatch_exchange','cancel'],true))
        throw new AzHttpError(400,'Unsupported return operation.','invalid_return_action');
    $rev=az_integer($body['revision']??0,1,PHP_INT_MAX,'Return revision');
    $actor=(int)$user['id'];
    $confirmed=($body['confirm']??false)===true;
    $evidence=az_local_clean($body['evidence']??'',180,'Return verification or payment evidence');
    $restock=$action==='inspect'
        ?az_integer($body['restockQuantity']??0,0,100,'Physically inspected stock')
        :0;
    $condition=$action==='inspect'
        ?az_text($body['condition']??'',20,'Returned-item condition'):'';
    if($action==='inspect'&&!in_array($condition,['resellable','damaged','unopened','other'],true))
        throw new AzHttpError(400,'Record the inspected item condition.','condition_required');
    if(in_array($action,['authorize','approve_refund','confirm_refund','approve_exchange',
        'dispatch_exchange'],true)&&!$confirmed)
        throw new AzHttpError(400,'Explicit staff confirmation is required.','confirmation_required');
    if(in_array($action,['authorize','confirm_refund','dispatch_exchange'],true)
       &&mb_strlen(trim($evidence))<8)
        throw new AzHttpError(400,'Record an external evidence/reference (at least eight characters).','evidence_required');
    return az_commerce_returns_lock_write(static function(PDO $db)use($id,$action,$rev,$actor,$restock,$condition,$evidence):array{
        $row=az_return_row($id);
        if((int)$row['revision']!==$rev)
            throw new AzHttpError(409,'Return case changed. Reload before acting.','revision_conflict');
        $before=$row['status'];$state=$before;
        $now=az_now();$changes=['updated_at'=>$now,'last_actor_id'=>$actor];
        if($action==='authorize'){
            if($before!=='requested')throw new AzHttpError(409,'Only new requests can be authorized.','invalid_transition');
            if(!in_array($row['order_state'],['paid','partially_refunded'],true))
                throw new AzHttpError(409,'Order is not paid or has a payment dispute.','return_ineligible');
            az_return_quantity_guard($db,$row['order_id'],(int)$row['quantity'],$id);
            $state='authorized';
            $changes['authorized_at']=$now;
            $changes['authorization_evidence']=$evidence;
        }elseif($action==='reject'||$action==='cancel'){
            $allowed=$action==='reject'?['requested','authorized','received']:
                ['requested','authorized'];
            if(!in_array($before,$allowed,true))
                throw new AzHttpError(409,'Return cannot be closed from this state.','invalid_transition');
            $state=$action==='reject'?'rejected':'cancelled';
            $changes['resolved_at']=$now;
        }elseif($action==='receive'){
            if($before!=='authorized')throw new AzHttpError(409,'Authorize the return before receiving merchandise.','invalid_transition');
            $state='received';
        }elseif($action==='inspect'){
            if($before!=='received')throw new AzHttpError(409,'Mark returned goods received before inspecting.','invalid_transition');
            $qty=(int)$row['quantity'];
            if($restock>$qty||($condition==='damaged'&&$restock!==0))
                throw new AzHttpError(400,'Restock only physically received, resellable quantities.','invalid_restock');
            az_return_restock($db,$row,$restock,$actor);
            $state='inspected';
            $changes['inspected_condition']=$condition;
            $changes['restocked_quantity']=$restock;
            $changes['inspected_at']=$now;
        }elseif($action==='approve_refund'){
            if($before!=='inspected'||$row['kind']!=='refund')
                throw new AzHttpError(409,'Inspection of a refund return must be complete.','invalid_transition');
            if(!in_array($row['order_state'],['paid','partially_refunded'],true))
                throw new AzHttpError(409,'Payment has changed; reconcile the provider first.','payment_state_changed');
            $q=$db->prepare("SELECT COUNT(*) FROM commerce_returns
                WHERE order_id=? AND id<>? AND status='refund_approved'");
            $q->execute([$row['order_id'],$id]);
            if((int)$q->fetchColumn()>0)
                throw new AzHttpError(409,'Reconcile the existing approved refund before approving another on this order.','refund_in_progress');
            // Do not disburse money. This establishes an administrative hold.
            $state='refund_approved';
            $changes['refund_baseline_cents']=(int)$row['refunded_cents'];
        }elseif($action==='confirm_refund'){
            if($before!=='refund_approved'||$row['kind']!=='refund')
                throw new AzHttpError(409,'A refund must be approved before reconciliation.','invalid_transition');
            $expected=intdiv((int)$row['amount_cents']*(int)$row['quantity'],(int)$row['order_quantity']);
            $baseline=(int)$row['refund_baseline_cents'];
            $q=$db->prepare('SELECT state,refunded_cents FROM commerce_orders WHERE id=?');
            $q->execute([$row['order_id']]);$order=$q->fetch(PDO::FETCH_ASSOC);
            if(!$order)throw new AzHttpError(404,'Related order not found.','not_found');
            $provider=$row['provider'];
            if($provider==='stripe'){
                if(!in_array($order['state'],['partially_refunded','refunded'],true)
                    ||(int)$order['refunded_cents']-$baseline<$expected)
                    throw new AzHttpError(409,'A matching signed Stripe refund has not been verified for this approved return.','provider_refund_unverified');
            }else{
                // The current cash/manual platforms support externally recorded
                // full refunds only. We do not fabricate partial cash refunds.
                if($order['state']!=='refunded'||(int)$order['refunded_cents']<$expected)
                    throw new AzHttpError(409,'Staff must record the actual cash/external refund first.','provider_refund_unverified');
            }
            $q=$db->prepare("SELECT COUNT(*) FROM commerce_returns
                WHERE refund_reference=? AND id<>? AND status='refunded'");
            $q->execute([$evidence,$id]);
            if((int)$q->fetchColumn()>0)
                throw new AzHttpError(409,'This refund reference is already used by another return.','duplicate_refund_reference');
            $changes['refund_reference']=$evidence;
            $changes['resolved_at']=$now;
            $state='refunded';
        }elseif($action==='approve_exchange'){
            if($before!=='inspected'||$row['kind']!=='exchange')
                throw new AzHttpError(409,'An exchange requires inspected returned goods.','invalid_transition');
            if(!in_array($row['order_state'],['paid','partially_refunded'],true))
                throw new AzHttpError(409,'Resolve the payment state before issuing replacements.','payment_state_changed');
            // For like-for-like replacements, reserve fresh inventory. There is
            // no new sale, payout, or extra charge during exchange approval.
            az_inventory_reserve($db,$row['product_id'],(int)$row['quantity'],
                hash('sha256','exchange|'.$id),'exchange',$row['order_id'],null);
            $state='exchange_approved';
        }elseif($action==='dispatch_exchange'){
            if($before!=='exchange_approved'||$row['kind']!=='exchange')
                throw new AzHttpError(409,'Exchange replacement must be approved and stock reserved.','invalid_transition');
            az_inventory_resolve($db,hash('sha256','exchange|'.$id),'consumed');
            $q=$db->prepare("SELECT COUNT(*) FROM commerce_returns WHERE replacement_reference=? AND id<>?");
            $q->execute([$evidence,$id]);
            if((int)$q->fetchColumn()>0)throw new AzHttpError(409,'Replacement reference already used.','duplicate_replacement_reference');
            $changes['replacement_reference']=$evidence;
            $changes['resolved_at']=$now;
            $state='exchange_sent';
        }
        $changes['status']=$state;
        $sql='UPDATE commerce_returns SET ';$values=[];
        foreach($changes as $col=>$val){$sql.=$col.'=?, ';$values[]=$val;}
        $sql.='revision=revision+1 WHERE id=? AND revision=?';
        $values[]=$id;$values[]=$rev;
        $q=$db->prepare($sql);$q->execute($values);
        if($q->rowCount()!==1)throw new AzHttpError(409,'Return was modified before confirmation.','revision_conflict');
        az_return_history($db,$id,$actor,$action,
            $action==='inspect'?'Inspection recorded; resellable units '.(string)$restock:
            ($action==='confirm_refund'?'Verified refund evidence checked against payment ledger':
            ($action==='dispatch_exchange'?'Like-for-like replacement sent by staff':'Staff transition recorded')));
        az_commerce_queue_admins('return-action:'.$id.':'.$action.':'.$rev,$actor,
            'return_'.$action,'Return '.$action,
            $row['title'].' · Return moved from '.$before.' to '.$state.
            '. Financial and inventory changes require verified evidence.',true);
        if(!empty($row['model_id']) && in_array($action,['authorize','confirm_refund',
                'approve_exchange','dispatch_exchange','reject','cancel'],true)){
            $msg=match($action){
                'authorize'=>'An approved return has placed the related estimated affiliate commission on hold.',
                'confirm_refund'=>'The refund has been reconciled with the provider; your estimated commission was adjusted.',
                'approve_exchange'=>'A like-for-like replacement has been authorized; commission remains on hold.',
                'dispatch_exchange'=>'The replacement was recorded as dispatched. Commission eligibility follows the normal settlement policy.',
                default=>'The return request was closed. Commission eligibility follows the normal settlement policy.'
            };
            az_commerce_queue('return-model:'.$id.':'.$action.':'.$rev,$actor,(int)$row['model_id'],
                'return_commission_'.$action,'Affiliate return update',$msg,'commerce.php',true);
        }
        return ['id'=>$id,'status'=>$state,'revision'=>$rev+1,'restockedQuantity'=>$changes['restocked_quantity']??(int)$row['restocked_quantity']];
    });
}
