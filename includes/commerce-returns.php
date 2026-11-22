<?php
declare(strict_types=1);
/**
 * v1.36: Returns, same-item exchanges and manually reconciled creator payouts.
 * Customer requests never move money or affect payouts until staff authorize.
 * Stripe refunds remain provider-signed; creator payouts are EXTERNAL/manual.
 */
function az_upgrade_commerce_settlement_schema(PDO $db): void
{
    if(az_schema_version($db)>=42||az_schema_version($db)<41)return;
    az_prepare_storage();$lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Returns/settlement migration lock unavailable.');
    try{
        if(az_schema_version($db)>=42)return;
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $uid=$mysql?'BIGINT UNSIGNED':'INTEGER';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_returns (
            id CHAR(32) PRIMARY KEY,
            order_id CHAR(32) NOT NULL,
            kind VARCHAR(12) NOT NULL,
            quantity INTEGER NOT NULL,
            reason VARCHAR(500) NOT NULL,
            source VARCHAR(12) NOT NULL,
            contact_ciphertext TEXT NULL,
            fingerprint CHAR(64) NOT NULL UNIQUE,
            status VARCHAR(22) NOT NULL DEFAULT 'requested',
            inspected_condition VARCHAR(20) NOT NULL DEFAULT '',
            restocked_quantity INTEGER NOT NULL DEFAULT 0,
            refund_baseline_cents BIGINT NULL,
            refund_reference VARCHAR(140) NOT NULL DEFAULT '',
            replacement_reference VARCHAR(140) NOT NULL DEFAULT '',
            authorization_evidence VARCHAR(180) NOT NULL DEFAULT '',
            revision INTEGER NOT NULL DEFAULT 1,
            created_at VARCHAR(32) NOT NULL,
            updated_at VARCHAR(32) NOT NULL,
            authorized_at VARCHAR(32) NULL,
            inspected_at VARCHAR(32) NULL,
            resolved_at VARCHAR(32) NULL,
            last_actor_id $uid NULL,
            FOREIGN KEY(order_id) REFERENCES commerce_orders(id),
            FOREIGN KEY(last_actor_id) REFERENCES users(id))$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_return_events (
            id CHAR(32) PRIMARY KEY,
            return_id CHAR(32) NOT NULL,
            actor_id $uid NULL,
            action VARCHAR(30) NOT NULL,
            detail VARCHAR(230) NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL,
            FOREIGN KEY(return_id) REFERENCES commerce_returns(id),
            FOREIGN KEY(actor_id) REFERENCES users(id))$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_payout_batches (
            id CHAR(32) PRIMARY KEY,
            model_id $uid NOT NULL,
            amount_cents BIGINT NOT NULL,
            state VARCHAR(14) NOT NULL DEFAULT 'draft',
            payment_channel VARCHAR(24) NOT NULL DEFAULT '',
            evidence_reference VARCHAR(160) NULL UNIQUE,
            revision INTEGER NOT NULL DEFAULT 1,
            created_by $uid NOT NULL,
            approved_by $uid NULL,
            confirmed_by $uid NULL,
            created_at VARCHAR(32) NOT NULL,
            approved_at VARCHAR(32) NULL,
            confirmed_at VARCHAR(32) NULL,
            updated_at VARCHAR(32) NOT NULL,
            FOREIGN KEY(model_id) REFERENCES users(id),
            FOREIGN KEY(created_by) REFERENCES users(id),
            FOREIGN KEY(approved_by) REFERENCES users(id),
            FOREIGN KEY(confirmed_by) REFERENCES users(id))$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_payout_allocations (
            batch_id CHAR(32) NOT NULL,
            order_id CHAR(32) NOT NULL,
            amount_cents BIGINT NOT NULL,
            PRIMARY KEY(batch_id,order_id),
            FOREIGN KEY(batch_id) REFERENCES commerce_payout_batches(id) ON DELETE CASCADE,
            FOREIGN KEY(order_id) REFERENCES commerce_orders(id))$suffix");
        if($mysql){
            $q=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
            foreach([
                ['commerce_returns','commerce_return_by_order','(order_id,status)'],
                ['commerce_returns','commerce_return_queue','(status,created_at)'],
                ['commerce_return_events','commerce_return_audit','(return_id,created_at)'],
                ['commerce_payout_batches','commerce_payout_model','(model_id,state,created_at)'],
                ['commerce_payout_allocations','commerce_payout_by_order','(order_id)']
            ] as [$table,$name,$columns]){
                $q->execute([$table,$name]);if(!(int)$q->fetchColumn())$db->exec("CREATE INDEX $name ON $table $columns");
            }
        }else{
            $db->exec('CREATE INDEX IF NOT EXISTS commerce_return_by_order ON commerce_returns(order_id,status)');
            $db->exec('CREATE INDEX IF NOT EXISTS commerce_return_queue ON commerce_returns(status,created_at)');
            $db->exec('CREATE INDEX IF NOT EXISTS commerce_return_audit ON commerce_return_events(return_id,created_at)');
            $db->exec('CREATE INDEX IF NOT EXISTS commerce_payout_model ON commerce_payout_batches(model_id,state,created_at)');
            $db->exec('CREATE INDEX IF NOT EXISTS commerce_payout_by_order ON commerce_payout_allocations(order_id)');
        }
        $db->exec('UPDATE studio_schema SET version=42 WHERE id=1');
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function az_commerce_returns_lock_write(callable $fn): array
{
    // Payout and returns share the same transactional lock as cash/refund
    // actions, avoiding concurrent draft allocation and inspection restock.
    return az_local_write($fn);
}
function az_return_order(string $id): array
{
    if(!az_valid_id($id))throw new AzHttpError(404,'Order not found.','not_found');
    $q=az_db()->prepare("SELECT o.*,p.title,p.owner_id,m.model_id
        FROM commerce_orders o JOIN commerce_products p ON p.id=o.product_id
        LEFT JOIN commerce_promotions m ON m.id=o.promotion_id WHERE o.id=?");
    $q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);
    if(!$r)throw new AzHttpError(404,'Order not found.','not_found');
    return $r;
}
function az_return_history(PDO $db,string $id,?int $actor,string $event,string $detail=''): void
{
    $db->prepare('INSERT INTO commerce_return_events(id,return_id,actor_id,action,detail,created_at) VALUES(?,?,?,?,?,?)')
       ->execute([az_id(),$id,$actor,$event,mb_safe_trim($detail,230),az_now()]);
}
function az_return_contact(string $email): string
{
    $email=mb_strtolower(trim($email));
    if(strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL))
        throw new AzHttpError(400,'Enter a valid email for the store to contact you.','invalid_contact');
    return $email;
}
/**
 * Public customer request is UNVERIFIED: generic receipt, no order details,
 * no commission hold, no automatic refund, no confirmation of reference match.
 */
function az_return_public_request(array $body): array
{
    az_account_throttle('commerce-return-request',6,3600);
    $reference=az_local_clean($body['reference']??'',120,'Order reference',true);
    if(strlen($reference)<6)throw new AzHttpError(400,'Enter the order reference from your receipt.','reference_required');
    $email=az_return_contact(az_local_clean($body['email']??'',254,'Contact email',true));
    $kind=az_text($body['kind']??'',12,'Requested resolution');
    if(!in_array($kind,['refund','exchange'],true))throw new AzHttpError(400,'Choose refund or exchange.','invalid_return_kind');
    $quantity=az_integer($body['quantity']??1,1,100,'Quantity');
    $reason=az_local_clean($body['reason']??'',500,'Reason for request',true);
    if(mb_strlen($reason)<8)throw new AzHttpError(400,'Please briefly explain your return request.','return_reason_required');
    $accepted=['received'=>true,'message'=>'If the reference matches an eligible order, the store will review your request. No refund or exchange has been authorized. Contact the merchant for updates.'];
    // Do not reveal whether a reference exists. Staff verifies order ownership
    // separately before authorization.
    $q=az_db()->prepare("SELECT id FROM commerce_orders WHERE external_reference=?");
    $q->execute([$reference]);$id=$q->fetchColumn();
    if(!$id)return $accepted;
    $order=az_return_order((string)$id);
    if(!in_array($order['state'],['paid','partially_refunded'],true))return $accepted;
    if($quantity>(int)$order['quantity'])return $accepted;
    $fingerprint=hash_hmac('sha256',implode('|',[(string)$id,$email,$kind,$quantity,
        gmdate('Y-m-d')]),az_local_secret());
    try {
        az_commerce_returns_lock_write(static function(PDO $db)use($id,$kind,$quantity,$reason,$email,$fingerprint,$order):array {
            $q=$db->prepare('SELECT id FROM commerce_returns WHERE fingerprint=?');$q->execute([$fingerprint]);
            if($q->fetchColumn())return ['received'=>true];
            $returnId=az_id();$now=az_now();
            $db->prepare("INSERT INTO commerce_returns(id,order_id,kind,quantity,reason,source,contact_ciphertext,
                fingerprint,status,created_at,updated_at)
                VALUES(?,?,?,?,?,'unverified',?,?,'requested',?,?)")
              ->execute([$returnId,$id,$kind,$quantity,$reason,az_local_encrypt(['email'=>$email]),$fingerprint,$now,$now]);
            az_return_history($db,$returnId,null,'requested','Unverified customer request; ownership not confirmed');
            az_commerce_queue_admins('return-request:'.$returnId,(int)$order['owner_id'],
                'return_request','Return or exchange request needs verification',
                $order['title'].' · Customer supplied an order reference. Verify ownership and eligibility before approving. No refund or commission change occurred.',true);
            return ['received'=>true];
        });
    }catch(PDOException $e){
        // Avoid exposing receipt/order existence from an exceptional duplicate.
        error_log('Commerce return request could not be recorded.');
    }
    return $accepted;
}
function az_return_admin_create(array $user,array $body): array
{
    az_commerce_admin($user);
    $id=az_text($body['orderId']??'',32,'Order');
    $order=az_return_order($id);
    if(!in_array($order['state'],['paid','partially_refunded'],true))
        throw new AzHttpError(409,'Only paid orders are eligible for return intake.','return_ineligible');
    $kind=az_text($body['kind']??'',12,'Return kind');
    if(!in_array($kind,['refund','exchange'],true))throw new AzHttpError(400,'Choose refund or exchange.','invalid_return_kind');
    $quantity=az_integer($body['quantity']??1,1,100,'Return quantity');
    $reason=az_local_clean($body['reason']??'',500,'Return reason',true);
    if(mb_strlen($reason)<8)throw new AzHttpError(400,'Record a reason for the requested return.','return_reason_required');
    if($quantity>(int)$order['quantity'])throw new AzHttpError(409,'Return exceeds quantity purchased.','invalid_return_quantity');
    $actor=(int)$user['id'];
    return az_commerce_returns_lock_write(static function(PDO $db)use($id,$quantity,$kind,$reason,$actor,$order):array{
        az_return_quantity_guard($db,$id,$quantity);
        $rid=az_id();$now=az_now();$fingerprint=hash('sha256','staff|'.$rid);
        $db->prepare("INSERT INTO commerce_returns(id,order_id,kind,quantity,reason,source,fingerprint,status,last_actor_id,created_at,updated_at)
            VALUES(?,?,?,?,?,'staff',?,'requested',?,?,?)")
           ->execute([$rid,$id,$kind,$quantity,$reason,$fingerprint,$actor,$now,$now]);
        az_return_history($db,$rid,$actor,'requested','Staff entered a customer return request');
        az_commerce_queue_admins('return-staff:'.$rid,$actor,'return_request',
            'Return request needs staff approval',$order['title'].' · Review ownership, order payment and return eligibility.',true);
        return ['id'=>$rid,'status'=>'requested','revision'=>1];
    });
}
function az_return_quantity_guard(PDO $db,string $orderId,int $quantity,?string $excludeId=null): void
{
    $q=$db->prepare("SELECT COALESCE(SUM(quantity),0) FROM commerce_returns
        WHERE order_id=? AND status NOT IN ('rejected','cancelled') AND (? IS NULL OR id<>?)");
    $q->execute([$orderId,$excludeId,$excludeId]);
    $reserved=(int)$q->fetchColumn();
    $q=$db->prepare('SELECT quantity FROM commerce_orders WHERE id=?');$q->execute([$orderId]);
    $purchased=(int)$q->fetchColumn();
    if($quantity+$reserved>$purchased)
        throw new AzHttpError(409,'Requested returns exceed remaining purchasable quantity. Review existing cases.','return_quantity_exceeded');
}
function az_return_row(string $id): array
{
    if(!az_valid_id($id))throw new AzHttpError(404,'Return case not found.','not_found');
    $q=az_db()->prepare("SELECT r.*,o.state AS order_state,o.provider,o.quantity AS order_quantity,
        o.amount_cents,o.refunded_cents,o.provider_payment_id,o.provider_total_cents,o.commission_bps,o.commission_cents,
        o.product_id,o.promotion_id,o.paid_at,p.title,p.owner_id,m.model_id
        FROM commerce_returns r JOIN commerce_orders o ON o.id=r.order_id
        JOIN commerce_products p ON p.id=o.product_id
        LEFT JOIN commerce_promotions m ON m.id=o.promotion_id WHERE r.id=?");
    $q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new AzHttpError(404,'Return case not found.','not_found');
    return $row;
}
function az_return_public_view(array $r): array
{
    return ['id'=>$r['id'],'orderId'=>$r['order_id'],'orderReference'=>'AZ-'.strtoupper(substr($r['order_id'],0,10)),
        'title'=>$r['title'],'kind'=>$r['kind'],'quantity'=>(int)$r['quantity'],
        'reason'=>$r['reason'],'source'=>$r['source'],'status'=>$r['status'],
        'condition'=>$r['inspected_condition'],
        'restockedQuantity'=>(int)$r['restocked_quantity'],
        'revision'=>(int)$r['revision'],'createdAt'=>$r['created_at'],'updatedAt'=>$r['updated_at'],
        'provider'=>$r['provider'],'refundReference'=>$r['refund_reference'],
        'replacementReference'=>$r['replacement_reference'],
        'providerRefund'=>az_provider_refund_public(az_provider_refund_row($r['id'])),
        'returnLabel'=>az_return_label_snapshot($r['id']),
        'approvedRefundCents'=>isset($r['amount_cents'],$r['order_quantity'])&&$r['order_quantity']>0
            ?intdiv((int)$r['amount_cents']*(int)$r['quantity'],(int)$r['order_quantity']):null,
        'verifiedPayoutStatus'=>'No refund or payout initiated by submitting a return request'];
}
function az_return_admin_list(array $user): array
{
    az_commerce_admin($user);
    $q=az_db()->query("SELECT r.*,o.provider,o.quantity AS order_quantity,o.amount_cents,p.title
        FROM commerce_returns r JOIN commerce_orders o ON o.id=r.order_id
        JOIN commerce_products p ON p.id=o.product_id
        ORDER BY CASE WHEN r.status IN ('requested','authorized','received','inspected','refund_approved','exchange_approved') THEN 0 ELSE 1 END,
        r.updated_at DESC LIMIT 150");
    return array_map('az_return_public_view',$q->fetchAll(PDO::FETCH_ASSOC));
}
function az_return_admin_contact(array $user,string $id): array
{
    az_commerce_admin($user);$row=az_return_row($id);
    $contact=$row['contact_ciphertext']!==null?az_local_decrypt((string)$row['contact_ciphertext']):[];
    return ['id'=>$id,'contact'=>$contact];
}
/** No restock unless staff explicitly inspected and counted physical goods. */
function az_return_restock(PDO $db,array $row,int $quantity,int $actor): void
{
    if($quantity===0)return;
    $stock=az_fulfillment_row($row['product_id']);
    if($stock['stock_mode']!=='tracked')return;
    $q=$db->prepare('UPDATE commerce_product_fulfillment SET on_hand=on_hand+?,revision=revision+1,updated_at=? WHERE product_id=?');
    $q->execute([$quantity,az_now(),$row['product_id']]);
    if($q->rowCount()!==1)throw new AzHttpError(409,'Inventory could not be reconciled.','inventory_unavailable');
    $db->prepare('INSERT INTO commerce_inventory_events(id,product_id,actor_id,event_kind,quantity_delta,detail,created_at) VALUES(?,?,?,?,?,?,?)')
      ->execute([az_id(),$row['product_id'],$actor,'return_inspected',$quantity,
        'Inspected restock from return '.substr($row['id'],0,10),az_now()]);
}

/** Return reports are composed solely from role-appropriate counts; no emails. */
function az_returns_briefing(array $user): array
{
    if(!az_has_role($user,'admin'))return ['cards'=>[],'counts'=>[]];
    $q=az_db()->query("SELECT status,COUNT(*) AS n FROM commerce_returns
        WHERE status IN ('requested','authorized','received','inspected','refund_approved','exchange_approved')
        GROUP BY status");
    $counts=['returnRequests'=>0,'returnAuthorized'=>0,'returnAwaitingInspection'=>0,
        'returnRefundApproved'=>0,'returnExchangesToShip'=>0];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $n=(int)$r['n'];
        if($r['status']==='requested')$counts['returnRequests']+=$n;
        elseif($r['status']==='authorized')$counts['returnAuthorized']+=$n;
        elseif(in_array($r['status'],['received','inspected'],true))$counts['returnAwaitingInspection']+=$n;
        elseif($r['status']==='refund_approved')$counts['returnRefundApproved']+=$n;
        elseif($r['status']==='exchange_approved')$counts['returnExchangesToShip']+=$n;
    }
    $cards=[];
    if($counts['returnRequests'])$cards[]=az_agent_workspace_card('commerce','Verify new return or exchange requests',
        $counts['returnRequests'].' customer return requests await eligibility and identity review; no refunds have been issued.','commerce.php',1);
    if($counts['returnAwaitingInspection'])$cards[]=az_agent_workspace_card('commerce','Returned merchandise needs inspection',
        $counts['returnAwaitingInspection'].' return cases need physical inspection or a refund/exchange decision.','commerce.php',1);
    if($counts['returnRefundApproved'])$cards[]=az_agent_workspace_card('commerce','Refunds approved but provider evidence is pending',
        $counts['returnRefundApproved'].' returns need a separate real refund and payment-provider reconciliation.','commerce.php',1);
    if($counts['returnExchangesToShip'])$cards[]=az_agent_workspace_card('commerce','Exchange replacements awaiting dispatch',
        $counts['returnExchangesToShip'].' same-product exchanges have reserved replacement stock; staff must confirm dispatch.','commerce.php',1);
    return ['cards'=>$cards,'counts'=>$counts];
}
function az_returns_scan(): array
{
    $cutoff=gmdate('Y-m-d\TH:i:s\Z',time()-48*3600);
    $q=az_db()->prepare("SELECT r.id,r.status,p.title,p.owner_id
        FROM commerce_returns r JOIN commerce_orders o ON o.id=r.order_id
        JOIN commerce_products p ON p.id=o.product_id
        WHERE r.updated_at<=? AND r.status IN
        ('requested','authorized','received','inspected','refund_approved','exchange_approved')
        ORDER BY r.updated_at LIMIT 100");
    $q->execute([$cutoff]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $r)az_commerce_queue_admins('return-stale:'.$r['id'].':'.$r['status'],
        (int)$r['owner_id'],'return_followup','Return or exchange needs follow-up',
        $r['title'].' · An unresolved return has been in the same state more than 48 hours. Review provider evidence and fulfillment. No refund was initiated.',true);
    return ['checked'=>count($rows),'dispatch'=>az_commerce_dispatch(90)];
}
