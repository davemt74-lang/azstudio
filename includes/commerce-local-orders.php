<?php
declare(strict_types=1);
/**
 * Local Commerce orders. Cash is never treated as collected at checkout.
 * Separate fulfillment status from payment status and capture commissions
 * only after an administrator explicitly confirms physical cash collection.
 */
function az_local_receipt_token(string $requestKey): string
{
    return hash_hmac('sha256','azhottie-cash-receipt-v1|'.$requestKey,az_local_secret());
}
function az_local_customer_status(string $token): array
{
    if(!preg_match('/^[a-f0-9]{64}$/D',$token))
        throw new AzHttpError(404,'Cash order receipt not found.','receipt_not_found');
    $hash=hash('sha256',$token);
    $q=az_db()->prepare("SELECT l.*,o.quantity,o.amount_cents,o.state AS order_state,p.title
        FROM commerce_local_orders l JOIN commerce_orders o ON o.id=l.order_id
        JOIN commerce_products p ON p.id=o.product_id WHERE l.receipt_hash=?");
    $q->execute([$hash]);$r=$q->fetch(PDO::FETCH_ASSOC);
    if(!$r)throw new AzHttpError(404,'Cash order receipt not found.','receipt_not_found');
    return az_local_customer_view($r);
}
function az_local_customer_view(array $r): array
{
    $accepted=in_array($r['status'],['accepted','ready','out_for_delivery','fulfilled'],true);
    return [
        'reference'=>'AZ-'.strtoupper(substr((string)$r['order_id'],0,10)),
        'title'=>$r['title'],'quantity'=>(int)$r['quantity'],
        'method'=>$r['method'],'status'=>$r['status'],
        'paymentStatus'=>$r['payment_status'],
        'subtotalCents'=>(int)$r['subtotal_cents'],
        'deliveryFeeCents'=>(int)$r['delivery_fee_cents'],
        'estimatedBeforeTaxCents'=>(int)$r['subtotal_cents']+(int)$r['delivery_fee_cents'],
        'taxCents'=>$r['tax_cents']===null?null:(int)$r['tax_cents'],
        'totalCashDueCents'=>$r['total_due_cents']===null?null:(int)$r['total_due_cents'],
        'pickupDetails'=>$accepted&&$r['method']==='pickup'?$r['pickup_snapshot']:'',
        'updatedAt'=>$r['updated_at'],
        'notice'=>$r['status']==='pending'?'Waiting for staff to confirm the final amount and availability. Do not travel or expect delivery yet.':
            ($r['payment_status']==='collected'?'Cash collection has been recorded by staff.':
            ($r['status']==='cancelled'?'This cash order has been cancelled.':
            ($r['payment_status']==='refunded'?'Staff recorded a cash refund.':
            'Staff confirmed the order. Bring the displayed cash amount when collecting or receiving your items.')))
    ];
}
function az_local_create_order(array $body): array
{
    az_account_throttle('cash-order',6,3600);
    $product=az_commerce_row(az_text($body['productId']??'',32,'Product'));
    $s=az_local_options($product['id']);
    if($product['state']!=='active'||!$s['available'])
        throw new AzHttpError(409,'This product is not offered for cash pickup or delivery.','local_product_unavailable');
    $method=az_text($body['method']??'',12,'Fulfillment method');
    if(!in_array($method,['pickup','delivery'],true))
        throw new AzHttpError(400,'Choose pickup or local delivery.','invalid_method');
    if(($method==='pickup'&&!$s['pickupEnabled'])||($method==='delivery'&&!$s['deliveryEnabled']))
        throw new AzHttpError(409,'This fulfillment method is not available.','local_closed');
    $qty=az_integer($body['quantity']??1,1,10,'Quantity');
    $subtotal=(int)$product['price_cents']*$qty;
    if($subtotal<1||$subtotal>100000000)throw new AzHttpError(400,'Order amount is outside the supported range.','invalid_cart');
    $fee=$method==='delivery'?$s['deliveryFeeCents']:0;
    if($method==='delivery'&&$subtotal<$s['deliveryMinCents'])
        throw new AzHttpError(409,'This delivery order does not meet the configured minimum.','delivery_minimum');
    $contact=az_local_contact($body,$method,$s);
    [$promotionId,$channel,$sourceRef,$attributed]=az_stripe_attribution($product['id'],$body);
    $clientToken=az_text($body['clientToken']??'',32,'Request token');
    if(!az_valid_id($clientToken))throw new AzHttpError(400,'Choose a valid request token.','invalid_token');
    $session=session_id();
    if($session==='')throw new AzHttpError(403,'A browser session is required to place a cash order.','session_required');
    $key=hash_hmac('sha256','cash-order|'.$session.'|'.$clientToken,az_local_secret());
    $cart=hash_hmac('sha256',json_encode([$product['id'],$qty,$method,$subtotal,$fee,
        $promotionId,$channel,$sourceRef,$contact],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),az_local_secret());
    $receipt=az_local_receipt_token($key);
    $actor=(int)$product['owner_id'];
    $owner=az_account_user($actor);
    if(!$owner||$owner['status']!=='active'||!az_has_role($owner,'admin'))
        throw new AzHttpError(503,'This store is not accepting cash orders right now.','store_unavailable');
    return az_local_write(static function(PDO $db)use($product,$method,$qty,$subtotal,$fee,$contact,
        $promotionId,$channel,$sourceRef,$attributed,$key,$cart,$receipt,$actor,$s):array{
        $q=$db->prepare("SELECT l.*,o.quantity,o.amount_cents,p.title FROM commerce_local_orders l
            JOIN commerce_orders o ON o.id=l.order_id JOIN commerce_products p ON p.id=o.product_id
            WHERE l.request_key=?");
        $q->execute([$key]);$existing=$q->fetch(PDO::FETCH_ASSOC);
        if($existing){
            if(!hash_equals((string)$existing['cart_hash'],$cart))
                throw new AzHttpError(409,'This cash request token was used for different order details.','token_reused');
            return ['receiptToken'=>$receipt,'order'=>az_local_customer_view($existing),'duplicate'=>true];
        }
        $id=az_id();$now=az_now();$commission=$attributed?(int)$product['commission_bps']:0;
        $q=$db->prepare("INSERT INTO commerce_orders(id,external_reference,product_id,promotion_id,quantity,
            amount_cents,currency,commission_bps,commission_cents,state,recorded_by,provider,created_at,updated_at)
            VALUES(?,?,?,?,? ,?,'USD',?,0,'recorded',?,'cash',?,?)");
        $q->execute([$id,'cash:'.$id,$product['id'],$promotionId,$qty,$subtotal,$commission,$actor,$now,$now]);
        $pickupSnapshot=$method==='pickup'
            ?implode("\n",array_filter([(string)$s['pickupLocation'],(string)$s['pickupAddress'],
                (string)$s['pickupHours'],(string)$s['pickupInstructions']],static fn($v)=>$v!=='')):'';
        $db->prepare("INSERT INTO commerce_local_orders(order_id,request_key,cart_hash,receipt_hash,method,channel,source_ref,delivery_zip,
            contact_ciphertext,pickup_snapshot,delivery_fee_cents,subtotal_cents,status,payment_status,created_at,updated_at)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'pending','due',?,?)")
          ->execute([$id,$key,$cart,hash('sha256',$receipt),$method,$channel,$sourceRef,
            $method==='delivery'?$contact['zip']:null,az_local_encrypt($contact),$pickupSnapshot,$fee,$subtotal,$now,$now]);
        az_commerce_queue_admins('cash-request:'.$id,$actor,'cash_order_request',
            'Cash order request needs review',
            $product['title'].' · '.($method==='pickup'?'Local pickup':'Local delivery').
            ' request. Payment is due; confirm pricing and fulfillment before acceptance.',true);
        $view=['order_id'=>$id,'title'=>$product['title'],'quantity'=>$qty,'method'=>$method,
            'status'=>'pending','payment_status'=>'due','subtotal_cents'=>$subtotal,
            'delivery_fee_cents'=>$fee,'tax_cents'=>null,'total_due_cents'=>null,
            'pickup_snapshot'=>$pickupSnapshot,'updated_at'=>$now];
        return ['receiptToken'=>$receipt,'order'=>az_local_customer_view($view),'duplicate'=>false];
    });
}
/** A receipt holder may cancel only their own still-pending unpaid request. */
function az_local_customer_cancel(string $receiptToken): array
{
    if(!preg_match('/^[a-f0-9]{64}$/D',$receiptToken))
        throw new AzHttpError(404,'Cash receipt not found.','receipt_not_found');
    return az_local_write(static function(PDO $db)use($receiptToken):array{
        $q=$db->prepare("SELECT l.*,o.recorded_by,p.title FROM commerce_local_orders l
            JOIN commerce_orders o ON o.id=l.order_id JOIN commerce_products p ON p.id=o.product_id
            WHERE l.receipt_hash=?");
        $q->execute([hash('sha256',$receiptToken)]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if(!$r)throw new AzHttpError(404,'Cash receipt not found.','receipt_not_found');
        if($r['status']!=='pending'||$r['payment_status']!=='due')
            throw new AzHttpError(409,'This request can no longer be cancelled online; contact the store.','cancel_not_allowed');
        $now=az_now();
        $db->prepare("UPDATE commerce_local_orders SET status='cancelled',cancelled_at=?,updated_at=?,revision=revision+1
            WHERE order_id=? AND status='pending' AND payment_status='due'")
          ->execute([$now,$now,$r['order_id']]);
        $db->prepare("UPDATE commerce_orders SET state='cancelled',updated_at=? WHERE id=? AND state='recorded'")
          ->execute([$now,$r['order_id']]);
        az_commerce_queue_admins('cash-customer-cancel:'.$r['order_id'],(int)$r['recorded_by'],'cash_cancelled',
            'Cash order cancelled by customer',$r['title'].' · An unaccepted unpaid order request was withdrawn.',true);
        return ['cancelled'=>true,'reference'=>'AZ-'.strtoupper(substr($r['order_id'],0,10))];
    });
}
function az_local_admin_orders(array $user): array
{
    az_commerce_admin($user);
    $q=az_db()->query("SELECT l.order_id,l.method,l.delivery_zip,l.status,l.payment_status,l.revision,
        l.subtotal_cents,l.delivery_fee_cents,l.tax_cents,l.total_due_cents,l.created_at,l.updated_at,
        l.quote_confirmed_at,l.collected_at,o.quantity,o.promotion_id,o.commission_cents,p.title
        FROM commerce_local_orders l JOIN commerce_orders o ON o.id=l.order_id
        JOIN commerce_products p ON p.id=o.product_id
        ORDER BY CASE WHEN l.status IN ('pending','accepted','ready','out_for_delivery') THEN 0 ELSE 1 END,
        l.created_at DESC LIMIT 150");
    return array_map(static fn($r)=>[
        'id'=>$r['order_id'],'reference'=>'AZ-'.strtoupper(substr($r['order_id'],0,10)),
        'method'=>$r['method'],'zip'=>$r['delivery_zip'],
        'status'=>$r['status'],'paymentStatus'=>$r['payment_status'],
        'revision'=>(int)$r['revision'],'title'=>$r['title'],'quantity'=>(int)$r['quantity'],
        'subtotalCents'=>(int)$r['subtotal_cents'],'deliveryFeeCents'=>(int)$r['delivery_fee_cents'],
        'taxCents'=>$r['tax_cents']===null?null:(int)$r['tax_cents'],
        'totalDueCents'=>$r['total_due_cents']===null?null:(int)$r['total_due_cents'],
        'commissionCents'=>(int)$r['commission_cents'],'hasAffiliate'=>$r['promotion_id']!==null,
        'quoteConfirmed'=>$r['quote_confirmed_at']!==null,'createdAt'=>$r['created_at'],'updatedAt'=>$r['updated_at'],
        'collectedAt'=>$r['collected_at']
    ],$q->fetchAll(PDO::FETCH_ASSOC));
}
function az_local_admin_details(array $user,string $id): array
{
    az_commerce_admin($user);
    if(!az_valid_id($id))throw new AzHttpError(404,'Cash order not found.','not_found');
    $q=az_db()->prepare("SELECT l.contact_ciphertext,l.order_id,l.method,l.pickup_snapshot,
        o.quantity,o.amount_cents,p.title FROM commerce_local_orders l
        JOIN commerce_orders o ON o.id=l.order_id JOIN commerce_products p ON p.id=o.product_id
        WHERE l.order_id=?");
    $q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);
    if(!$r)throw new AzHttpError(404,'Cash order not found.','not_found');
    return ['id'=>$r['order_id'],'title'=>$r['title'],'method'=>$r['method'],
        'quantity'=>(int)$r['quantity'],'contact'=>az_local_decrypt($r['contact_ciphertext']),
        'pickupDetails'=>$r['pickup_snapshot']];
}
function az_local_admin_action(array $user,array $body): array
{
    az_commerce_admin($user);
    $id=az_text($body['id']??'',32,'Cash order');
    if(!az_valid_id($id))throw new AzHttpError(404,'Cash order not found.','not_found');
    $revision=az_integer($body['revision']??0,1,PHP_INT_MAX,'Order revision');
    $action=az_text($body['decision']??'',24,'Order action');
    if(!in_array($action,['quote','accept','ready','out_for_delivery','collect','complete','cancel','refund'],true))
        throw new AzHttpError(400,'Choose a supported cash-order action.','invalid_action');
    $actor=(int)$user['id'];
    $confirmed=($body['confirmCash']??false)===true;
    if(in_array($action,['collect','refund'],true)&&!$confirmed)
        throw new AzHttpError(400,'Explicitly confirm the cash action.','confirmation_required');
    $tax=$action==='quote'?az_integer($body['taxCents']??null,0,20000000,'Tax amount'):null;
    $refundReason=$action==='refund'?az_local_clean($body['reason']??'',160,'Refund reason',true):'';
    if($action==='refund'&&mb_strlen($refundReason)<8)
        throw new AzHttpError(400,'Record a short reason for the cash refund.','refund_reason_required');
    return az_local_write(static function(PDO $db)use($id,$revision,$action,$actor,$confirmed,$tax,$refundReason):array{
        $q=$db->prepare("SELECT l.*,o.state AS order_state,o.amount_cents,o.commission_bps,
            o.promotion_id,p.title,p.owner_id,m.model_id FROM commerce_local_orders l
            JOIN commerce_orders o ON o.id=l.order_id AND o.provider='cash'
            JOIN commerce_products p ON p.id=o.product_id
            LEFT JOIN commerce_promotions m ON m.id=o.promotion_id
            WHERE l.order_id=?");
        $q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if(!$r)throw new AzHttpError(404,'Cash order not found.','not_found');
        if((int)$r['revision']!==$revision)
            throw new AzHttpError(409,'Cash order changed; refresh before acting.','revision_conflict');
        $status=$r['status'];$payment=$r['payment_status'];$method=$r['method'];$now=az_now();
        $localStatus=$status;$newPayment=$payment;$orderState=$r['order_state'];$commission=null;
        $updates=['updated_at'=>$now,'last_actor_id'=>$actor];
        if($action==='quote'){
            if($status!=='pending'||$payment!=='due')
                throw new AzHttpError(409,'Only pending cash requests can be quoted.','invalid_transition');
            $total=(int)$r['subtotal_cents']+(int)$r['delivery_fee_cents']+(int)$tax;
            if($total<1||$total>120000000)
                throw new AzHttpError(400,'Confirmed cash total is outside the supported range.','invalid_total');
            $updates['tax_cents']=$tax;$updates['total_due_cents']=$total;$updates['quote_confirmed_at']=$now;
        }elseif($action==='accept'){
            if($status!=='pending'||$payment!=='due'||$r['quote_confirmed_at']===null)
                throw new AzHttpError(409,'Confirm the final tax and cash total before accepting.','quote_required');
            $localStatus='accepted';
        }elseif($action==='ready'){
            if($method!=='pickup'||$status!=='accepted')
                throw new AzHttpError(409,'Pickup must be accepted before it is ready.','invalid_transition');
            $localStatus='ready';
        }elseif($action==='out_for_delivery'){
            if($method!=='delivery'||$status!=='accepted')
                throw new AzHttpError(409,'Delivery must be accepted before dispatch.','invalid_transition');
            $localStatus='out_for_delivery';
        }elseif($action==='collect'){
            if($payment!=='due'||$r['quote_confirmed_at']===null
               ||($method==='pickup'&&$status!=='ready')
               ||($method==='delivery'&&$status!=='out_for_delivery'))
                throw new AzHttpError(409,'Cash may be recorded only when the order is ready or out for delivery.','invalid_transition');
            $newPayment='collected';$updates['collected_at']=$now;
            $orderState='paid';$commission=intdiv((int)$r['amount_cents']*(int)$r['commission_bps'],10000);
        }elseif($action==='complete'){
            if($payment!=='collected'||($method==='pickup'&&$status!=='ready')
                ||($method==='delivery'&&$status!=='out_for_delivery'))
                throw new AzHttpError(409,'Collect cash before completing the pickup or delivery.','invalid_transition');
            $localStatus='fulfilled';$updates['fulfilled_at']=$now;
        }elseif($action==='cancel'){
            if($payment!=='due'||!in_array($status,['pending','accepted','ready','out_for_delivery'],true))
                throw new AzHttpError(409,'Only unpaid, unfinished cash orders can be cancelled.','invalid_transition');
            $localStatus='cancelled';$updates['cancelled_at']=$now;$orderState='cancelled';
        }elseif($action==='refund'){
            if($payment!=='collected'||!in_array($status,['ready','out_for_delivery','fulfilled'],true))
                throw new AzHttpError(409,'Only collected cash orders can be refunded.','invalid_transition');
            $newPayment='refunded';$orderState='refunded';$commission=0;
            if($status!=='fulfilled'){$localStatus='cancelled';$updates['cancelled_at']=$now;}
        }
        $updates['status']=$localStatus;$updates['payment_status']=$newPayment;
        $sql='UPDATE commerce_local_orders SET ';
        $args=[];
        foreach($updates as $column=>$value){$sql.=$column.'=?, ';$args[]=$value;}
        $sql.='revision=revision+1 WHERE order_id=? AND revision=?';
        $args[]=$id;$args[]=$revision;
        $q=$db->prepare($sql);$q->execute($args);
        if($q->rowCount()!==1)throw new AzHttpError(409,'Order changed. Reload before acting.','revision_conflict');
        if($orderState!==$r['order_state']){
            if($orderState==='paid'){
                $db->prepare("UPDATE commerce_orders SET state='paid',commission_cents=?,paid_at=?,reviewed_by=?,evidence_reference='Cash received by authorized staff',updated_at=? WHERE id=? AND provider='cash' AND state='recorded'")
                  ->execute([$commission,$now,$actor,$now,$id]);
            }elseif($orderState==='refunded'){
                $db->prepare("UPDATE commerce_orders SET state='refunded',refunded_cents=amount_cents,commission_cents=0,reviewed_by=?,evidence_reference=?,updated_at=? WHERE id=? AND provider='cash' AND state='paid'")
                  ->execute([$actor,$refundReason,$now,$id]);
            }elseif($orderState==='cancelled'){
                $db->prepare("UPDATE commerce_orders SET state='cancelled',reviewed_by=?,updated_at=? WHERE id=? AND provider='cash' AND state='recorded'")
                  ->execute([$actor,$now,$id]);
            }
        }
        if(in_array($action,['accept','ready','out_for_delivery','collect','complete','cancel','refund'],true)){
            az_commerce_queue_admins('cash-action:'.$id.':'.$action.':'.$revision,$actor,'cash_'.$action,
                'Cash order '.str_replace('_',' ',$action),
                $r['title'].' · Cash order '.str_replace('_',' ',$action).'. Payment and fulfillment are tracked separately.',
                in_array($action,['collect','cancel','refund'],true));
        }
        if(!empty($r['model_id'])&&in_array($action,['collect','refund'],true)){
            az_commerce_queue('cash-creator:'.$id.':'.$action,$actor,(int)$r['model_id'],'cash_commission_'.$action,
                $action==='collect'?'Creator cash sale recorded':'Creator cash commission reversed',
                $action==='collect'?'Staff confirmed cash was collected for an attributed product. Commission is an unpaid estimate.':
                    'Staff recorded a cash refund, reversing the associated estimated commission.',
                'commerce.php',true);
        }
        return ['id'=>$id,'status'=>$localStatus,'paymentStatus'=>$newPayment,'revision'=>$revision+1,
            'totalDueCents'=>$r['total_due_cents']===null&&$action!=='quote'?null:($action==='quote'?$updates['total_due_cents']:(int)$r['total_due_cents'])];
    });
}
function az_local_briefing(array $user): array
{
    if(!az_has_role($user,'admin'))return ['cards'=>[],'counts'=>[]];
    $q=az_db()->query("SELECT status,payment_status,COUNT(*) AS n FROM commerce_local_orders
        WHERE status IN ('pending','accepted','ready','out_for_delivery')
        GROUP BY status,payment_status");
    $pending=0;$ready=0;$delivery=0;$unfulfilled=0;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $n=(int)$r['n'];
        if($r['status']==='pending')$pending+=$n;
        if($r['status']==='ready'&&$r['payment_status']==='due')$ready+=$n;
        if($r['status']==='out_for_delivery')$delivery+=$n;
        if($r['payment_status']==='collected')$unfulfilled+=$n;
    }
    $cards=[];
    if($pending)$cards[]=az_agent_workspace_card('cash_order','Cash orders awaiting confirmation',
        $pending.' local cash order requests need staff review and a final tax/total quote. No cash has been collected.',
        'commerce.php',1);
    if($ready)$cards[]=az_agent_workspace_card('cash_order','Pickup orders awaiting cash collection',
        $ready.' pickup orders are ready and unpaid. Confirm cash in person, then mark fulfilled.','commerce.php',1);
    if($delivery)$cards[]=az_agent_workspace_card('cash_order','Active local deliveries',
        $delivery.' deliveries are out for delivery. Staff should verify collection and completion.','commerce.php',2);
    if($unfulfilled)$cards[]=az_agent_workspace_card('cash_order','Cash collected; fulfillment still open',
        $unfulfilled.' cash orders have collection recorded but still need final fulfillment confirmation.','commerce.php',1);
    return ['cards'=>$cards,'counts'=>['localCashPending'=>$pending,'localPickupCashDue'=>$ready,
        'localDeliveryActive'=>$delivery,'localCollectedAwaitingFulfillment'=>$unfulfilled]];
}
function az_local_scan(): array
{
    $cutoff=gmdate('Y-m-d\TH:i:s\Z',time()-24*3600);
    $q=az_db()->prepare("SELECT l.order_id,l.status,l.payment_status,p.title,o.recorded_by
        FROM commerce_local_orders l JOIN commerce_orders o ON o.id=l.order_id
        JOIN commerce_products p ON p.id=o.product_id
        WHERE l.created_at<=? AND l.status IN ('pending','ready','out_for_delivery')
        ORDER BY l.created_at LIMIT 100");
    $q->execute([$cutoff]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r)az_commerce_queue_admins('cash-overdue:'.$r['order_id'].':'.$r['status'].':'.$r['payment_status'],
        (int)$r['recorded_by'],'cash_attention','Local cash order needs follow-up',
        $r['title'].' · An outstanding cash pickup/delivery order has been open over 24 hours. Review its current status.',
        true);
    return ['checked'=>count($rows),'delivery'=>az_commerce_dispatch(100)];
}
