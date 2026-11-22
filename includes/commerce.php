<?php
declare(strict_types=1);
/**
 * Commerce & Creator Affiliate Specialist (v1.30).
 * Real products, private briefs, approval-gated promotions and admin-reconciled
 * order records. No payment capture, provider verification or payouts are implied.
 */
function az_upgrade_commerce_schema(PDO $db): void
{
    if (az_schema_version($db)>=36 || az_schema_version($db)<35) return;
    az_prepare_storage();
    $lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Commerce migration lock unavailable.');
    try {
        if (az_schema_version($db)>=36) return;
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $uid=$mysql?'BIGINT UNSIGNED':'INTEGER';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_products (
            id CHAR(32) PRIMARY KEY, owner_id $uid NOT NULL, title VARCHAR(160) NOT NULL,
            description TEXT NOT NULL, price_cents BIGINT NOT NULL, currency CHAR(3) NOT NULL DEFAULT 'USD',
            checkout_url VARCHAR(500) NOT NULL DEFAULT '', commission_bps INTEGER NOT NULL DEFAULT 1000,
            state VARCHAR(16) NOT NULL DEFAULT 'draft', revision INTEGER NOT NULL DEFAULT 1,
            created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL,
            FOREIGN KEY(owner_id) REFERENCES users(id))$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_briefs (
            product_id CHAR(32) PRIMARY KEY, title VARCHAR(160) NOT NULL, instructions TEXT NOT NULL,
            state VARCHAR(16) NOT NULL DEFAULT 'closed', revision INTEGER NOT NULL DEFAULT 1,
            updated_at VARCHAR(32) NOT NULL,
            FOREIGN KEY(product_id) REFERENCES commerce_products(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_interests (
            product_id CHAR(32) NOT NULL, model_id $uid NOT NULL, state VARCHAR(16) NOT NULL,
            created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL,
            PRIMARY KEY(product_id,model_id),
            FOREIGN KEY(product_id) REFERENCES commerce_products(id) ON DELETE CASCADE,
            FOREIGN KEY(model_id) REFERENCES users(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_promotions (
            id CHAR(32) PRIMARY KEY, product_id CHAR(32) NOT NULL, model_id $uid NOT NULL,
            video_id CHAR(32) NOT NULL, video_revision INTEGER NOT NULL, state VARCHAR(16) NOT NULL,
            approved_by $uid NULL, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL,
            UNIQUE(product_id,model_id,video_id),
            FOREIGN KEY(product_id) REFERENCES commerce_products(id) ON DELETE CASCADE,
            FOREIGN KEY(model_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(video_id) REFERENCES model_videos(id) ON DELETE CASCADE,
            FOREIGN KEY(approved_by) REFERENCES users(id))$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_orders (
            id CHAR(32) PRIMARY KEY, external_reference VARCHAR(120) NOT NULL UNIQUE,
            product_id CHAR(32) NOT NULL, promotion_id CHAR(32) NULL, quantity INTEGER NOT NULL,
            amount_cents BIGINT NOT NULL, currency CHAR(3) NOT NULL DEFAULT 'USD',
            commission_bps INTEGER NOT NULL DEFAULT 0, commission_cents BIGINT NOT NULL DEFAULT 0,
            state VARCHAR(16) NOT NULL DEFAULT 'recorded', evidence_reference VARCHAR(200) NOT NULL DEFAULT '',
            recorded_by $uid NOT NULL, reviewed_by $uid NULL,
            created_at VARCHAR(32) NOT NULL, paid_at VARCHAR(32) NULL, updated_at VARCHAR(32) NOT NULL,
            FOREIGN KEY(product_id) REFERENCES commerce_products(id),
            FOREIGN KEY(promotion_id) REFERENCES commerce_promotions(id),
            FOREIGN KEY(recorded_by) REFERENCES users(id),
            FOREIGN KEY(reviewed_by) REFERENCES users(id))$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_live_features (
            room_id CHAR(32) PRIMARY KEY, product_id CHAR(32) NOT NULL,
            selected_by $uid NOT NULL, updated_at VARCHAR(32) NOT NULL,
            FOREIGN KEY(room_id) REFERENCES az_live_rooms(id) ON DELETE CASCADE,
            FOREIGN KEY(product_id) REFERENCES commerce_products(id),
            FOREIGN KEY(selected_by) REFERENCES users(id))$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_activity (
            event_key CHAR(64) PRIMARY KEY, actor_id $uid NOT NULL, recipient_id $uid NOT NULL,
            kind VARCHAR(48) NOT NULL, title VARCHAR(200) NOT NULL, detail VARCHAR(600) NOT NULL,
            href VARCHAR(240) NOT NULL, announce INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(32) NOT NULL, delivered_at VARCHAR(32) NULL,
            FOREIGN KEY(actor_id) REFERENCES users(id),
            FOREIGN KEY(recipient_id) REFERENCES users(id))$suffix");
        if ($mysql) {
            $q=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
            foreach ([['commerce_activity','commerce_activity_due','(delivered_at,created_at)'],
                      ['commerce_orders','commerce_orders_state','(state,created_at)'],
                      ['commerce_promotions','commerce_promotion_model','(model_id,state)']] as [$table,$name,$columns]) {
                $q->execute([$table,$name]);if(!(int)$q->fetchColumn())$db->exec("CREATE INDEX $name ON $table $columns");
            }
        } else {
            $db->exec('CREATE INDEX IF NOT EXISTS commerce_activity_due ON commerce_activity (delivered_at,created_at)');
            $db->exec('CREATE INDEX IF NOT EXISTS commerce_orders_state ON commerce_orders (state,created_at)');
            $db->exec('CREATE INDEX IF NOT EXISTS commerce_promotion_model ON commerce_promotions (model_id,state)');
        }
        $db->exec('UPDATE studio_schema SET version=36 WHERE id=1');
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}

function az_commerce_admin(array $user): void
{
    if (($user['status']??'')!=='active' || !az_has_role($user,'admin'))
        throw new AzHttpError(403,'Administrator access required.','role_required');
}
function az_commerce_model(array $user): void
{
    if (($user['status']??'')!=='active' || !az_has_role($user,'model'))
        throw new AzHttpError(403,'An active model account is required.','role_required');
}
function az_commerce_url(mixed $input): string
{
    $url=az_text($input,500,'Hosted checkout URL');
    if ($url==='')return '';
    $parts=parse_url($url);
    if (!filter_var($url,FILTER_VALIDATE_URL) || !is_array($parts)
        || strtolower((string)($parts['scheme']??''))!=='https'
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || preg_match('/^(localhost|.*\.local)$/i',(string)$parts['host']))
        throw new AzHttpError(400,'Use a valid HTTPS hosted-checkout link.','invalid_checkout_url');
    return $url;
}
function az_commerce_row(string $id): array
{
    if (!az_valid_id($id))throw new AzHttpError(404,'Product not found.','not_found');
    $q=az_db()->prepare('SELECT * FROM commerce_products WHERE id=?');$q->execute([$id]);
    $row=$q->fetch();if(!$row)throw new AzHttpError(404,'Product not found.','not_found');return $row;
}
function az_commerce_active(array $row): bool
{
    if($row['state']!=='active')return false;
    $online=(($row['checkout_mode']??'external')==='external')
        ? ($row['checkout_url']??'')!=='' : az_stripe_product_ready($row);
    if($online)return true;
    // Video-attribution joins expose product_id rather than the product row's id.
    $productId=(string)($row['id']??$row['product_id']??'');
    return az_valid_id($productId) && az_local_options($productId)['available'];
}
function az_commerce_model_ids(): array
{
    return array_map('intval',az_db()->query("SELECT DISTINCT u.id FROM users u JOIN user_roles r ON r.user_id=u.id AND r.role='model' WHERE u.status='active'")->fetchAll(PDO::FETCH_COLUMN));
}
/** Persistent, recipient-specific outbox. Queue in the same DB transaction as the source action. */
function az_commerce_queue(string $key,int $actor,int $recipient,string $kind,string $title,string $detail,string $href='commerce.php',bool $announce=true): void
{
    if ($actor<1||$recipient<1)return;
    $eventKey=hash('sha256',$key.'|'.$recipient);
    $db=az_db();
    $q=$db->prepare('SELECT event_key FROM commerce_activity WHERE event_key=?');
    $q->execute([$eventKey]);if($q->fetchColumn())return;
    try {
        $db->prepare('INSERT INTO commerce_activity(event_key,actor_id,recipient_id,kind,title,detail,href,announce,created_at) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$eventKey,$actor,$recipient,$kind,mb_safe_trim($title,200),mb_safe_trim($detail,600),az_agent_workspace_href($href),$announce?1:0,az_now()]);
    }catch(PDOException $e) {
        // Only a competing insert of exactly the same event may be ignored.
        $q->execute([$eventKey]);if(!$q->fetchColumn())throw $e;
    }
}
function az_commerce_queue_admins(string $key,int $actor,string $kind,string $title,string $detail,bool $announce=true): void
{
    foreach(az_agent_workspace_admin_ids() as $admin)
        az_commerce_queue($key,$actor,$admin,$kind,$title,$detail,'commerce.php',$announce);
}
/** Deterministic proactive Chat message, not a fabricated AI analysis or auto-reply. */
function az_commerce_chat_append(int $recipient,array $event): void
{
    $db=az_db();
    $q=$db->prepare("SELECT id FROM agent_chat_threads WHERE user_id=? AND title='Commerce Agent Updates' ORDER BY created_at LIMIT 1");
    $q->execute([$recipient]);$thread=$q->fetchColumn();
    $now=az_now();
    if (!$thread) {
        $thread=az_id();
        $db->prepare('INSERT INTO agent_chat_threads(id,user_id,title,created_at,updated_at) VALUES(?,?,?,?,?)')
            ->execute([$thread,$recipient,'Commerce Agent Updates',$now,$now]);
    }
    $q=$db->prepare('SELECT COALESCE(MAX(position),0) FROM agent_chat_messages WHERE thread_id=?');
    $q->execute([$thread]);$position=(int)$q->fetchColumn()+1;
    $message='Commerce update: '.$event['title']."\n".$event['detail']."\nOpen: ".$event['href']."\nThis notice is based on recorded commerce activity; no action was taken for you.";
    $db->prepare('INSERT INTO agent_chat_messages(id,thread_id,user_id,position,role,content,client_token,state,created_at,updated_at) VALUES(?,?,?,?,?, ?,NULL,?,?,?)')
        ->execute([az_id(),$thread,$recipient,$position,'assistant',mb_safe_trim($message,2200),'complete',$now,$now]);
    $db->prepare('UPDATE agent_chat_threads SET updated_at=? WHERE id=? AND user_id=?')->execute([$now,$thread,$recipient]);
}
/** Retryable delivery to existing bell, History and Agent Chat. Uses the same Chat serialization lock. */
function az_commerce_dispatch(int $limit=60): array
{
    $lock=az_community_lock();$delivered=0;$failed=0;
    try {
        $db=az_db();$q=$db->prepare('SELECT * FROM commerce_activity WHERE delivered_at IS NULL ORDER BY created_at,event_key LIMIT ?');
        $q->bindValue(1,max(1,min(200,$limit)),PDO::PARAM_INT);$q->execute();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $event) {
            try {
                $db->beginTransaction();
                $fresh=$db->prepare('SELECT delivered_at FROM commerce_activity WHERE event_key=?');
                $fresh->execute([$event['event_key']]);
                if ($fresh->fetchColumn()===null) {
                    $actor=(int)$event['actor_id'];$recp=(int)$event['recipient_id'];
                    az_agent_workspace_emit($actor,$recp,'commerce_'.$event['kind'],$event['title'],$event['detail'],$event['href'],$actor!==$recp || (int)$event['announce']===1);
                    if ((int)$event['announce']===1)az_commerce_chat_append($recp,$event);
                    $db->prepare('UPDATE commerce_activity SET delivered_at=? WHERE event_key=? AND delivered_at IS NULL')->execute([az_now(),$event['event_key']]);
                    $delivered++;
                }
                $db->commit();
            }catch(Throwable $e) {
                if($db->inTransaction())$db->rollBack();
                $failed++;error_log('Commerce event delivery failed: '.$e->getMessage());
            }
        }
    } finally {flock($lock,LOCK_UN);fclose($lock);}
    return ['delivered'=>$delivered,'failed'=>$failed];
}

/** All mutations are explicit actions; the writer owns rollback. */
function az_commerce_write(callable $fn): array
{
    $db=az_db();$db->beginTransaction();
    try {$result=$fn($db);$db->commit();}
    catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    // Delivery never changes the status of an already-committed business action.
    try {az_commerce_dispatch(45);}catch(Throwable $e){error_log('Commerce dispatch deferred: '.$e->getMessage());}
    return $result;
}
function az_commerce_product_save(array $user,array $body): array
{
    az_commerce_admin($user);$title=az_text($body['title']??'',160,'Product title');
    if ($title==='')throw new AzHttpError(400,'Enter a product title.','invalid_title');
    $description=az_text($body['description']??'',4000,'Description');
    $price=az_integer($body['priceCents']??0,1,100000000,'Price in cents');
    $commission=az_integer($body['commissionBps']??1000,0,5000,'Commission basis points');
    $url=az_commerce_url($body['checkoutUrl']??'');
    $mode=az_text($body['checkoutMode']??'external',12,'Checkout mode');
    $state=az_text($body['state']??'draft',16,'Product state');
    $id=az_text($body['id']??'',32,'Product ID');
    $localInput=$body['localSettings']??null;
    if($localInput instanceof stdClass)$localInput=get_object_vars($localInput);
    if($localInput!==null&&!is_array($localInput))throw new AzHttpError(400,'Invalid product pickup/delivery settings.','invalid_local_settings');
    $localSettings=$localInput===null?null:az_local_prepare_settings($localInput);
    $fulfillmentInput=$body['fulfillmentSettings']??null;
    if($fulfillmentInput instanceof stdClass)$fulfillmentInput=get_object_vars($fulfillmentInput);
    if($fulfillmentInput!==null&&!is_array($fulfillmentInput))throw new AzHttpError(400,'Invalid shipping and inventory settings.','invalid_fulfillment_settings');
    $fulfillmentSettings=$fulfillmentInput===null?null:az_fulfillment_prepare($fulfillmentInput);
    $cashReady=$localSettings!==null?$localSettings['available']:($id!==''&&az_valid_id($id)?az_local_options($id)['available']:false);
    if(!in_array($mode,['external','stripe'],true)||!in_array($state,['draft','active','archived'],true)
        ||($state==='active'&&$mode==='external'&&$url===''&&!$cashReady))
        throw new AzHttpError(400,'Active external products need an HTTPS checkout link.','invalid_product_state');
    if($state==='active'&&$mode==='stripe'&&!az_stripe_config()['ready']&&!$cashReady)
        throw new AzHttpError(503,'Configure Stripe keys and a trusted public URL before activating Stripe products.','checkout_unavailable');
    if($mode==='stripe')$url='';
    $actor=(int)$user['id'];
    return az_commerce_write(static function(PDO $db)use($id,$actor,$title,$description,$price,$commission,$url,$mode,$state,$localSettings,$fulfillmentSettings,$body):array{
        $now=az_now();
        if($id===''){
            $idNew=az_id();
            $db->prepare("INSERT INTO commerce_products(id,owner_id,title,description,price_cents,currency,checkout_url,checkout_mode,commission_bps,state,created_at,updated_at) VALUES(?,?,?,?,?,'USD',?,?,?,?,?,?)")
                ->execute([$idNew,$actor,$title,$description,$price,$url,$mode,$commission,$state,$now,$now]);
            $idOut=$idNew;
        }else{
            $old=az_commerce_row($id);
            $revision=az_integer($body['revision']??0,1,PHP_INT_MAX,'Product revision');
            $q=$db->prepare('UPDATE commerce_products SET title=?,description=?,price_cents=?,checkout_url=?,checkout_mode=?,commission_bps=?,state=?,revision=revision+1,updated_at=? WHERE id=? AND revision=?');
            $q->execute([$title,$description,$price,$url,$mode,$commission,$state,$now,$id,$revision]);
            if($q->rowCount()!==1)throw new AzHttpError(409,'Product changed. Reload before saving.','revision_conflict');
            $idOut=$id;
        }
        if($localSettings!==null)az_local_save_product_settings($db,$idOut,$localSettings);
        if($fulfillmentSettings!==null)az_fulfillment_save($db,$idOut,$fulfillmentSettings,$actor);
        az_commerce_queue('product:'.$idOut.':'.$now.':'.$state,$actor,$actor,'product_saved','Product saved: '.$title,
            'State: '.$state.'. Checkout remains on the configured hosted provider.','commerce.php',false);
        return ['id'=>$idOut,'state'=>$state];
    });
}
function az_commerce_brief_save(array $user,array $body): array
{
    az_commerce_admin($user);
    $product=az_commerce_row(az_text($body['productId']??'',32,'Product ID'));
    $title=az_text($body['title']??'',160,'Brief title');
    $instructions=az_text($body['instructions']??'',5000,'Creator brief');
    $state=az_text($body['state']??'closed',16,'Brief state');
    if($title===''||$instructions===''||!in_array($state,['active','closed'],true)||($state==='active'&&!az_commerce_active($product)))
        throw new AzHttpError(400,'A public creator brief requires an active product, title and instructions.','invalid_brief');
    $actor=(int)$user['id'];
    return az_commerce_write(static function(PDO $db)use($product,$title,$instructions,$state,$actor,$body):array{
        $q=$db->prepare('SELECT revision FROM commerce_briefs WHERE product_id=?');
        $q->execute([$product['id']]);$old=$q->fetchColumn();$now=az_now();
        if($old===false){
            $db->prepare('INSERT INTO commerce_briefs(product_id,title,instructions,state,updated_at) VALUES(?,?,?,?,?)')
                ->execute([$product['id'],$title,$instructions,$state,$now]);
        }else{
            $revision=az_integer($body['revision']??0,1,PHP_INT_MAX,'Brief revision');
            $q=$db->prepare('UPDATE commerce_briefs SET title=?,instructions=?,state=?,revision=revision+1,updated_at=? WHERE product_id=? AND revision=?');
            $q->execute([$title,$instructions,$state,$now,$product['id'],$revision]);
            if($q->rowCount()!==1)throw new AzHttpError(409,'Brief changed. Reload before saving.','revision_conflict');
        }
        if($state==='active'){
            $version=$old===false?1:(int)$old+1;
            foreach(az_commerce_model_ids() as $model)az_commerce_queue('brief:'.$product['id'].':'.$version,$actor,$model,
                'brief_open','New creator opportunity: '.$title,'A product creator brief is available to review in Commerce.','commerce.php',true);
        }
        return ['productId'=>$product['id'],'state'=>$state];
    });
}
function az_commerce_interest_action(array $user,array $body): array
{
    az_commerce_model($user);$product=az_commerce_row(az_text($body['productId']??'',32,'Product'));
    $decision=az_text($body['decision']??'interested',16,'Decision');
    if(!in_array($decision,['interested','withdrawn'],true))throw new AzHttpError(400,'Invalid interest action.','invalid_decision');
    $model=(int)$user['id'];$productId=$product['id'];
    return az_commerce_write(static function(PDO $db)use($product,$decision,$model,$productId):array {
        $q=$db->prepare('SELECT state FROM commerce_briefs WHERE product_id=?');$q->execute([$productId]);
        if(!az_commerce_active($product)||$q->fetchColumn()!=='active')throw new AzHttpError(409,'Creator brief is not open.','brief_closed');
        $q=$db->prepare('SELECT state FROM commerce_interests WHERE product_id=? AND model_id=?');$q->execute([$productId,$model]);$old=$q->fetchColumn();
        if($old==='approved')throw new AzHttpError(409,'An approved affiliation cannot be withdrawn here; contact the administrator.','already_approved');
        if($old===$decision)return ['productId'=>$productId,'state'=>$decision];
        if($old===false)$db->prepare('INSERT INTO commerce_interests(product_id,model_id,state,created_at,updated_at) VALUES(?,?,?,?,?)')
            ->execute([$productId,$model,$decision,az_now(),az_now()]);
        else $db->prepare('UPDATE commerce_interests SET state=?,updated_at=? WHERE product_id=? AND model_id=?')
            ->execute([$decision,az_now(),$productId,$model]);
        az_commerce_queue_admins('interest:'.$productId.':'.$model.':'.$decision.':'.az_now(),$model,'interest',
            $decision==='interested'?'Model interested in product':'Model withdrew product interest',
            $product['title'].' · Review creator interest and approve explicitly.',true);
        return ['productId'=>$productId,'state'=>$decision];
    });
}
function az_commerce_interest_review(array $user,array $body): array
{
    az_commerce_admin($user);
    $product=az_commerce_row(az_text($body['productId']??'',32,'Product'));
    $model=az_integer($body['modelId']??0,1,PHP_INT_MAX,'Model');
    $decision=az_text($body['decision']??'',16,'Decision');
    if(!in_array($decision,['approved','declined'],true))throw new AzHttpError(400,'Choose approve or decline.','invalid_decision');
    $actor=(int)$user['id'];
    return az_commerce_write(static function(PDO $db)use($product,$model,$decision,$actor):array{
        $q=$db->prepare('UPDATE commerce_interests SET state=?,updated_at=? WHERE product_id=? AND model_id=? AND state=?');
        $q->execute([$decision,az_now(),$product['id'],$model,'interested']);
        if($q->rowCount()!==1)throw new AzHttpError(409,'Interest was already reviewed or withdrawn.','revision_conflict');
        az_commerce_queue('interest-review:'.$product['id'].':'.$model.':'.$decision,$actor,$model,'interest_review',
            $decision==='approved'?'Creator interest approved':'Creator interest declined',
            'Product: '.$product['title'].'. Open Commerce for your next step.','commerce.php',true);
        return ['productId'=>$product['id'],'modelId'=>$model,'state'=>$decision];
    });
}
function az_commerce_promotion_request(array $user,array $body): array
{
    az_commerce_model($user);$id=az_text($body['productId']??'',32,'Product');
    $product=az_commerce_row($id);$model=(int)$user['id'];
    $videoId=az_text($body['videoId']??'',32,'Video');
    $video=az_model_video_row($videoId,$model);
    if(!az_commerce_active($product)||$video['status']!=='published'||(int)$video['rights_confirmed']!==1)
        throw new AzHttpError(409,'The product and your published, consented video must be active.','promotion_ineligible');
    $q=az_db()->prepare("SELECT state FROM commerce_interests WHERE product_id=? AND model_id=?");
    $q->execute([$id,$model]);if($q->fetchColumn()!=='approved')throw new AzHttpError(403,'Creator affiliation requires admin approval.','approval_required');
    return az_commerce_write(static function(PDO $db)use($product,$model,$video):array{
        $q=$db->prepare('SELECT id,state FROM commerce_promotions WHERE product_id=? AND model_id=? AND video_id=?');
        $q->execute([$product['id'],$model,$video['id']]);$existing=$q->fetch();
        if($existing)throw new AzHttpError(409,'This video has already been proposed for this product.','already_proposed');
        $id=az_id();$now=az_now();
        $db->prepare("INSERT INTO commerce_promotions(id,product_id,model_id,video_id,video_revision,state,created_at,updated_at) VALUES(?,?,?,?,?,'pending',?,?)")
            ->execute([$id,$product['id'],$model,$video['id'],(int)$video['revision'],$now,$now]);
        az_commerce_queue_admins('promotion:'.$id,$model,'promotion_review','Product video needs approval',
            $product['title'].' · A model proposed a published video for product promotion. Review its current consent and revision.',true);
        return ['id'=>$id,'state'=>'pending'];
    });
}
function az_commerce_promotion_review(array $user,array $body): array
{
    az_commerce_admin($user);$id=az_text($body['id']??'',32,'Promotion');
    if(!az_valid_id($id))throw new AzHttpError(404,'Promotion not found.','not_found');
    $decision=az_text($body['decision']??'',16,'Decision');
    if(!in_array($decision,['approved','rejected','retired'],true))throw new AzHttpError(400,'Invalid decision.','invalid_decision');
    $actor=(int)$user['id'];
    return az_commerce_write(static function(PDO $db)use($id,$decision,$actor):array{
        $q=$db->prepare('SELECT p.*,v.status AS video_status,v.revision AS current_revision,v.rights_confirmed,c.title,c.state AS product_state,c.checkout_url,u.status AS model_status FROM commerce_promotions p JOIN model_videos v ON v.id=p.video_id AND v.user_id=p.model_id JOIN commerce_products c ON c.id=p.product_id JOIN users u ON u.id=p.model_id WHERE p.id=?');
        $q->execute([$id]);$row=$q->fetch();if(!$row)throw new AzHttpError(404,'Promotion not found.','not_found');
        if($decision==='retired'?$row['state']!=='approved':$row['state']!=='pending')
            throw new AzHttpError(409,'Promotion changed. Reload it.','revision_conflict');
        if($decision==='approved'&&($row['video_status']!=='published'||(int)$row['current_revision']!==(int)$row['video_revision']
            ||(int)$row['rights_confirmed']!==1||$row['model_status']!=='active'||$row['product_state']!=='active'||!az_commerce_active(az_commerce_row($row['product_id']))))
            throw new AzHttpError(409,'Product, model, consent or video revision changed.','promotion_ineligible');
        $q=$db->prepare('UPDATE commerce_promotions SET state=?,approved_by=?,updated_at=? WHERE id=? AND state=?');
        $q->execute([$decision,$actor,az_now(),$id,$row['state']]);
        if($q->rowCount()!==1)throw new AzHttpError(409,'Promotion was already reviewed.','revision_conflict');
        az_commerce_queue('promotion-review:'.$id.':'.$decision,$actor,(int)$row['model_id'],'promotion_decision',
            'Product video '.$decision,'Product: '.$row['title'].'. No social post or payment was initiated.','commerce.php',true);
        return ['id'=>$id,'state'=>$decision];
    });
}
function az_commerce_order_record(array $user,array $body): array
{
    az_commerce_admin($user);
    $product=az_commerce_row(az_text($body['productId']??'',32,'Product'));
    $ref=az_text($body['externalReference']??'',120,'External order reference');
    if(strlen($ref)<6)throw new AzHttpError(400,'Enter the order reference from your checkout provider.','reference_required');
    $quantity=az_integer($body['quantity']??1,1,100,'Quantity');
    $promotionId=az_text($body['promotionId']??'',32,'Promotion');
    $commission=0;$promotion=null;
    if($promotionId!=='') {
        if(!az_valid_id($promotionId))throw new AzHttpError(404,'Promotion not found.','not_found');
        $q=az_db()->prepare("SELECT p.*,v.status AS video_status,v.revision AS current_revision,v.rights_confirmed,u.status AS model_status
            FROM commerce_promotions p JOIN model_videos v ON v.id=p.video_id AND v.user_id=p.model_id
            JOIN users u ON u.id=p.model_id WHERE p.id=? AND p.product_id=?");
        $q->execute([$promotionId,$product['id']]);$promotion=$q->fetch();
        if(!$promotion||$promotion['state']!=='approved'||$promotion['video_status']!=='published'
            ||(int)$promotion['video_revision']!==(int)$promotion['current_revision']
            ||(int)$promotion['rights_confirmed']!==1||$promotion['model_status']!=='active')
            throw new AzHttpError(409,'Product attribution is no longer an approved, eligible video.','invalid_attribution');
        $commission=(int)$product['commission_bps'];
    }
    $actor=(int)$user['id'];
    return az_commerce_write(static function(PDO $db)use($product,$promotionId,$commission,$actor,$ref,$quantity):array{
        $q=$db->prepare('SELECT id FROM commerce_orders WHERE external_reference=?');$q->execute([$ref]);
        if($q->fetchColumn())throw new AzHttpError(409,'This order reference has already been recorded.','duplicate_order');
        $id=az_id();$now=az_now();$amount=(int)$product['price_cents']*$quantity;
        $db->prepare("INSERT INTO commerce_orders(id,external_reference,product_id,promotion_id,quantity,amount_cents,currency,commission_bps,state,recorded_by,created_at,updated_at)
            VALUES(?,?,?,?,?,?,'USD',?,'recorded',?,?,?)")
            ->execute([$id,$ref,$product['id'],$promotionId?:null,$quantity,$amount,$commission,$actor,$now,$now]);
        az_commerce_queue_admins('order:'.$id.':recorded',$actor,'order_review','Order recorded, payment needs confirmation',
            $product['title'].' · Reconcile the external order with the provider before marking paid.',false);
        return ['id'=>$id,'state'=>'recorded'];
    });
}
function az_commerce_order_review(array $user,array $body): array
{
    az_commerce_admin($user);$id=az_text($body['id']??'',32,'Order');
    if(!az_valid_id($id))throw new AzHttpError(404,'Order not found.','not_found');
    $decision=az_text($body['decision']??'',16,'Decision');
    if(!in_array($decision,['paid','refunded','cancelled'],true))throw new AzHttpError(400,'Invalid order decision.','invalid_decision');
    $evidence=az_text($body['evidenceReference']??'',200,'Provider evidence reference');
    if($decision!=='cancelled'&&strlen($evidence)<6)throw new AzHttpError(400,'Enter a provider transaction or refund reference.','evidence_required');
    $actor=(int)$user['id'];
    return az_commerce_write(static function(PDO $db)use($id,$decision,$actor,$evidence):array{
        $q=$db->prepare('SELECT o.*,p.title,m.model_id FROM commerce_orders o JOIN commerce_products p ON p.id=o.product_id LEFT JOIN commerce_promotions m ON m.id=o.promotion_id WHERE o.id=?');
        $q->execute([$id]);$row=$q->fetch();if(!$row)throw new AzHttpError(404,'Order not found.','not_found');
        if(($row['provider']??'manual')!=='manual')throw new AzHttpError(403,'Stripe orders change only through verified provider events.','provider_controlled');
        if(($decision==='paid'&&$row['state']!=='recorded')
           ||($decision==='cancelled'&&$row['state']!=='recorded')
           ||($decision==='refunded'&&$row['state']!=='paid'))
            throw new AzHttpError(409,'Order already reconciled or changed.','revision_conflict');
        $commission=$decision==='paid'?intdiv((int)$row['amount_cents']*(int)$row['commission_bps'],10000):(int)$row['commission_cents'];
        $paidAt=$decision==='paid'?az_now():$row['paid_at'];
        $q=$db->prepare('UPDATE commerce_orders SET state=?,evidence_reference=?,commission_cents=?,paid_at=?,reviewed_by=?,updated_at=? WHERE id=? AND state=?');
        $q->execute([$decision,$evidence,$commission,$paidAt,$actor,az_now(),$id,$row['state']]);
        if($q->rowCount()!==1)throw new AzHttpError(409,'Order changed. Reload.','revision_conflict');
        az_commerce_queue_admins('order:'.$id.':'.$decision,$actor,'order_'.$decision,
            'Commerce order '.$decision,$row['title'].' · The administrator recorded the provider reconciliation result.',true);
        if(!empty($row['model_id'])) {
            $msg=$decision==='paid'?'A product order was recorded as paid. Your pending commission is now listed in Commerce.':
                ($decision==='refunded'?'A previously recorded paid order was refunded; its commission is reversed.':'An order was cancelled before payment.');
            az_commerce_queue('model-order:'.$id.':'.$decision,$actor,(int)$row['model_id'],'commission_'.$decision,
                'Affiliate order '.$decision,$msg,'commerce.php',true);
        }
        return ['id'=>$id,'state'=>$decision,'commissionCents'=>$decision==='paid'?$commission:0];
    });
}
function az_commerce_live_select(array $user,array $body): array
{
    az_commerce_admin($user);
    $room=az_live_active_room();
    if(!$room)throw new AzHttpError(409,'Start a live broadcast before featuring a product.','live_offline');
    $product=az_commerce_row(az_text($body['productId']??'',32,'Product'));
    if(!az_commerce_active($product))throw new AzHttpError(409,'Only active checkout-ready products can be featured.','product_inactive');
    $actor=(int)$user['id'];
    $promotion=az_text($body['promotionId']??'',32,'Creator promotion');
    if($promotion!=='')az_stripe_promotion_row($promotion,$product['id']);
    return az_commerce_write(static function(PDO $db)use($room,$product,$actor,$promotion):array {
        $q=$db->prepare('SELECT room_id FROM commerce_live_features WHERE room_id=?');$q->execute([$room['id']]);
        if($q->fetchColumn())$db->prepare('UPDATE commerce_live_features SET product_id=?,promotion_id=?,selected_by=?,updated_at=? WHERE room_id=?')
            ->execute([$product['id'],$promotion?:null,$actor,az_now(),$room['id']]);
        else $db->prepare('INSERT INTO commerce_live_features(room_id,product_id,promotion_id,selected_by,updated_at) VALUES(?,?,?,?,?)')
            ->execute([$room['id'],$product['id'],$promotion?:null,$actor,az_now()]);
        az_commerce_queue_admins('live:'.$room['id'].':'.$product['id'],$actor,'live_feature','Live shopping feature selected',
            $product['title'].' is available beside the active live player. This does not initiate a sale.',true);
        return ['roomId'=>$room['id'],'productId'=>$product['id'],'promotionId'=>$promotion?:null];
    });
}
function az_commerce_public_products(): array
{
    $q=az_db()->query("SELECT id,title,description,price_cents,currency,checkout_url,checkout_mode,state
        FROM commerce_products WHERE state='active' ORDER BY updated_at DESC LIMIT 100");
    $rows=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $local=az_local_options($r['id']);
        if(!az_commerce_active($r))continue;
        $rows[]=['id'=>$r['id'],'title'=>$r['title'],'description'=>$r['description'],
            'priceCents'=>(int)$r['price_cents'],'currency'=>$r['currency'],
            'checkoutMode'=>$r['checkout_mode'],'stripeReady'=>az_stripe_product_ready($r),
            'localEnabled'=>$local['available'],'fulfillment'=>az_fulfillment_public($r),
            'checkoutUrl'=>$r['checkout_mode']==='external'?$r['checkout_url']:''];
        if(count($rows)>=48)break;
    }
    return $rows;
}
function az_commerce_live_feature(): ?array
{
    $room=az_live_active_room();if(!$room)return null;
    $q=az_db()->prepare("SELECT p.id,p.title,p.description,p.price_cents,p.currency,p.checkout_url,p.checkout_mode,p.state,f.promotion_id
        FROM commerce_live_features f JOIN commerce_products p ON p.id=f.product_id AND p.state='active'
        WHERE f.room_id=?");
    $q->execute([$room['id']]);$r=$q->fetch();
    if(!$r||!az_commerce_active($r))return null;
    $promotion=$r['promotion_id'];
    if($promotion){
        try{az_stripe_promotion_row($promotion,$r['id']);}
        catch(AzHttpError){$promotion=null;}
    }
    return ['roomId'=>$room['id'],'id'=>$r['id'],'title'=>$r['title'],'description'=>$r['description'],
        'priceCents'=>(int)$r['price_cents'],'currency'=>$r['currency'],'checkoutMode'=>$r['checkout_mode'],
        'stripeReady'=>az_stripe_product_ready($r),'localEnabled'=>az_local_options($r['id'])['available'],
        'fulfillment'=>az_fulfillment_public($r),
        'promotionId'=>$promotion,'checkoutUrl'=>$r['checkout_mode']==='external'?$r['checkout_url']:''];
}
function az_commerce_video_products(string $videoId): array
{
    if(!az_valid_id($videoId))return [];
    $q=az_db()->prepare("SELECT a.id AS promotion_id,a.product_id,p.title,p.description,p.price_cents,p.currency,p.checkout_url,p.checkout_mode,p.state
        FROM commerce_promotions a JOIN commerce_products p ON p.id=a.product_id
        JOIN model_videos v ON v.id=a.video_id AND v.user_id=a.model_id
        JOIN users u ON u.id=a.model_id AND u.status='active'
        WHERE a.video_id=? AND a.state='approved' AND a.video_revision=v.revision
        AND v.status='published' AND v.published_at IS NOT NULL AND v.rights_confirmed=1 AND p.state='active'
        ORDER BY a.updated_at DESC LIMIT 12");
    $q->execute([$videoId]);$rows=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        if(!az_commerce_active($r))continue;
        $rows[]=['id'=>$r['product_id'],'title'=>$r['title'],'description'=>$r['description'],
            'promotionId'=>$r['promotion_id'],'videoId'=>$videoId,'priceCents'=>(int)$r['price_cents'],
            'currency'=>$r['currency'],'checkoutMode'=>$r['checkout_mode'],
            'stripeReady'=>az_stripe_product_ready($r),'localEnabled'=>az_local_options($r['product_id'])['available'],
            'fulfillment'=>az_fulfillment_public(['id'=>$r['product_id']]),
            'checkoutUrl'=>$r['checkout_mode']==='external'?$r['checkout_url']:''];
    }
    return $rows;
}
function az_commerce_state(array $user): array
{
    if(!az_agent_workspace_available($user))throw new AzHttpError(403,'Commerce workspace is for models and administrators.','role_required');
    $db=az_db();$admin=az_has_role($user,'admin');$id=(int)$user['id'];
    $q=$db->prepare("SELECT p.*,b.title AS brief_title,b.instructions AS brief_instructions,b.state AS brief_state,b.revision AS brief_revision,
        i.state AS my_interest FROM commerce_products p LEFT JOIN commerce_briefs b ON b.product_id=p.id
        LEFT JOIN commerce_interests i ON i.product_id=p.id AND i.model_id=?"
        .($admin?'':" WHERE p.state='active' AND b.state='active'")." ORDER BY p.updated_at DESC LIMIT 100");
    $q->execute([$id]);$products=$q->fetchAll(PDO::FETCH_ASSOC);
    if(!$admin)$products=array_values(array_filter($products,'az_commerce_active'));
    $list=array_map(static function($r)use($admin,$user):array{
        $item=['id'=>$r['id'],'title'=>$r['title'],'description'=>$r['description'],'priceCents'=>(int)$r['price_cents'],
            'currency'=>$r['currency'],'checkoutMode'=>$r['checkout_mode'],'checkoutUrl'=>$r['checkout_mode']==='external'?$r['checkout_url']:'',
            'localEnabled'=>az_local_options($r['id'])['available'],'stripeReady'=>az_stripe_product_ready($r),
            'fulfillment'=>az_fulfillment_public($r),'state'=>$r['state'],
            'commissionBps'=>(int)$r['commission_bps'],'revision'=>(int)$r['revision'],
            'brief'=>($r['brief_title']===null?null:['title'=>$r['brief_title'],'instructions'=>$r['brief_instructions'],
               'state'=>$r['brief_state'],'revision'=>(int)$r['brief_revision']]),'myInterest'=>$r['my_interest']];
        if($admin){$item['localSettings']=az_local_settings_admin($user,$r['id']);
            $item['fulfillmentSettings']=az_fulfillment_admin($user,$r['id']);}
        else unset($item['revision']);
        return $item;
    },$products);
    $sql="SELECT i.product_id,i.model_id,i.state,i.created_at,p.title AS product_title,u.name AS model_name
        FROM commerce_interests i JOIN commerce_products p ON p.id=i.product_id
        JOIN users u ON u.id=i.model_id ".($admin?'':'WHERE i.model_id=? ')."ORDER BY i.updated_at DESC LIMIT 100";
    $q=$db->prepare($sql);$q->execute($admin?[]:[$id]);$interests=$q->fetchAll(PDO::FETCH_ASSOC);
    $sql="SELECT x.id,x.product_id,x.model_id,x.video_id,x.video_revision,x.state,p.title AS product_title,v.title AS video_title,v.revision AS current_revision,v.status AS video_status,v.rights_confirmed
        FROM commerce_promotions x JOIN commerce_products p ON p.id=x.product_id JOIN model_videos v ON v.id=x.video_id
        ".($admin?'':'WHERE x.model_id=? ')."ORDER BY x.updated_at DESC LIMIT 100";
    $q=$db->prepare($sql);$q->execute($admin?[]:[$id]);$promotions=$q->fetchAll(PDO::FETCH_ASSOC);
    $orders=[];
    if($admin){
        $q=$db->query("SELECT o.id,o.external_reference,o.product_id,o.promotion_id,o.quantity,o.amount_cents,o.commission_cents,o.currency,o.state,o.provider,o.refunded_cents,o.provider_total_cents,o.provider_tax_cents,o.created_at,o.updated_at,p.title AS product_title
          FROM commerce_orders o JOIN commerce_products p ON p.id=o.product_id ORDER BY o.updated_at DESC LIMIT 100");
        $orders=$q->fetchAll(PDO::FETCH_ASSOC);
    }else{
        $q=$db->prepare("SELECT o.id,o.product_id,o.quantity,o.amount_cents,o.commission_cents,o.currency,o.state,o.provider,o.refunded_cents,o.created_at,p.title AS product_title
          FROM commerce_orders o JOIN commerce_products p ON p.id=o.product_id JOIN commerce_promotions x ON x.id=o.promotion_id
          WHERE x.model_id=? ORDER BY o.updated_at DESC LIMIT 100");
        $q->execute([$id]);$orders=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    $videos=[];
    if(!$admin){
        $q=$db->prepare("SELECT id,title,revision FROM model_videos WHERE user_id=? AND status='published' AND rights_confirmed=1 ORDER BY updated_at DESC LIMIT 100");
        $q->execute([$id]);$videos=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    return ['role'=>$admin?'admin':'model','products'=>$list,'interests'=>$interests,'promotions'=>$promotions,'orders'=>$orders,
        'videos'=>$videos,'liveFeature'=>$admin?az_commerce_live_feature():null,
        'paymentBasis'=>'Manual orders are administrator-attested; only Stripe events with valid provider signatures confirm Stripe payments. Creator payouts are not automated.',
        'stripe'=>az_stripe_public_status(),'analytics'=>az_commerce_analytics($user)];
}
function az_commerce_briefing(array $user): array
{
    $admin=az_has_role($user,'admin');$id=(int)$user['id'];$db=az_db();$cards=[];
    if($admin){
        $products=(int)$db->query("SELECT COUNT(*) FROM commerce_products WHERE state='active'")->fetchColumn();
        $interests=(int)$db->query("SELECT COUNT(*) FROM commerce_interests WHERE state='interested'")->fetchColumn();
        $promos=(int)$db->query("SELECT COUNT(*) FROM commerce_promotions WHERE state='pending'")->fetchColumn();
        $orders=(int)$db->query("SELECT COUNT(*) FROM commerce_orders WHERE provider='manual' AND state='recorded'")->fetchColumn();
        $paid=(int)$db->query("SELECT COUNT(*) FROM commerce_orders WHERE state='paid'")->fetchColumn();
        $verified=(int)$db->query("SELECT COUNT(*) FROM commerce_orders WHERE provider='stripe' AND state IN ('paid','partially_refunded')")->fetchColumn();
        if($interests)$cards[]=az_agent_workspace_card('commerce','Creator interests awaiting approval',$interests.' models requested participation in product briefs.','commerce.php',1);
        if($promos)$cards[]=az_agent_workspace_card('commerce','Product video approvals',$promos.' product video associations need an approval decision.','commerce.php',1);
        if($orders)$cards[]=az_agent_workspace_card('commerce','External orders require reconciliation',$orders.' recorded orders need confirmation or cancellation against your checkout provider.','commerce.php',1);
        if($paid)$cards[]=az_agent_workspace_card('commerce','Review product conversion',$paid.' orders are recorded as paid. Compare verified and manual results in Commerce analytics.','commerce.php',3);
        if($verified>=3)$cards[]=az_agent_workspace_card('commerce','Verified commerce growth opportunity',
            $verified.' Stripe-paid orders have been received. Review the highest-selling approved product before planning the next live feature.','commerce.php',2);
        $counts=['commerceProducts'=>$products,'commerceInterests'=>$interests,'commercePromotions'=>$promos,
            'commerceOrdersToReview'=>$orders,'commerceRecordedPaidOrders'=>$paid,'commerceVerifiedPaidOrders'=>$verified];
    }else{
        $q=$db->prepare("SELECT COUNT(*) FROM commerce_briefs b JOIN commerce_products p ON p.id=b.product_id
            WHERE b.state='active' AND p.state='active' AND NOT EXISTS
            (SELECT 1 FROM commerce_interests i WHERE i.product_id=p.id AND i.model_id=?)");
        $q->execute([$id]);$open=(int)$q->fetchColumn();
        $q=$db->prepare("SELECT COUNT(*) FROM commerce_interests WHERE model_id=? AND state='approved'");
        $q->execute([$id]);$approved=(int)$q->fetchColumn();
        $q=$db->prepare("SELECT COUNT(*) FROM commerce_orders o JOIN commerce_promotions p ON p.id=o.promotion_id WHERE p.model_id=? AND o.state='paid'");
        $q->execute([$id]);$paid=(int)$q->fetchColumn();
        $q=$db->prepare("SELECT COUNT(*) FROM commerce_orders o JOIN commerce_promotions p ON p.id=o.promotion_id WHERE p.model_id=? AND o.provider='stripe' AND o.state IN ('paid','partially_refunded')");
        $q->execute([$id]);$verified=(int)$q->fetchColumn();
        if($open)$cards[]=az_agent_workspace_card('commerce','Creator product briefs available',$open.' product briefs invite your interest. Approval is required before promoting.','commerce.php',2);
        if($approved)$cards[]=az_agent_workspace_card('commerce','Your approved product affiliations',$approved.' product partnerships can be linked to a published, consented video.','commerce.php',2);
        if($paid)$cards[]=az_agent_workspace_card('commerce','Affiliate sales recorded',$paid.' attributed orders are recorded as paid, with commissions pending external settlement.','commerce.php',3);
        $counts=['commerceOpenBriefs'=>$open,'commerceApprovedBriefs'=>$approved,'commerceRecordedPaidOrders'=>$paid,'commerceVerifiedPaidOrders'=>$verified];
    }
    return ['cards'=>$cards,'counts'=>$counts,'basis'=>'Current commerce records and role-scoped approvals only. Orders are administrator-reconciled, not independently payment-provider verified.'];
}
function az_commerce_chat_snapshot(array $user): array
{
    $brief=az_commerce_briefing($user);
    return ['analytics'=>az_commerce_analytics_chat($user),'counts'=>$brief['counts'],'recommendations'=>array_map(static fn($r)=>['title'=>$r['title'],'href'=>$r['href']],array_slice($brief['cards'],0,3)),
        'basis'=>$brief['basis'],'nextStep'=>az_has_role($user,'admin')?'Review pending approvals and external order evidence.':'Review active briefs and submit an eligible published video for approval.'];
}
/** Hourly read-only follow-up scan; queue is deduplicated, never changes order state. */
function az_commerce_scan(): array
{
    $cutoff=gmdate('Y-m-d\TH:i:s\Z',time()-48*3600);
    $q=az_db()->prepare("SELECT o.id,p.title,o.recorded_by FROM commerce_orders o JOIN commerce_products p ON p.id=o.product_id WHERE o.provider='manual' AND o.state='recorded' AND o.created_at<=? ORDER BY o.created_at LIMIT 100");
    $q->execute([$cutoff]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $row)az_commerce_queue_admins('order:'.$row['id'].':overdue48h',(int)$row['recorded_by'],
        'order_attention','Order still needs reconciliation',$row['title'].' · External payment status has been unconfirmed for over 48 hours.',true);
    return ['checked'=>count($rows),'delivery'=>az_commerce_dispatch(100)];
}
