<?php
declare(strict_types=1);
/**
 * Cash pickup / local delivery configuration and private contact handling.
 * Disabled by default. Neither personal delivery details nor contact fields
 * are fed to Agent Chat, public endpoints, creator reports, or application logs.
 */
function az_upgrade_commerce_local_schema(PDO $db): void
{
    if(az_schema_version($db)>=38 || az_schema_version($db)<37)return;
    az_prepare_storage();
    $lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Local Commerce migration lock unavailable.');
    try{
        if(az_schema_version($db)>=38)return;
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $uid=$mysql?'BIGINT UNSIGNED':'INTEGER';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        // MySQL DDL may commit each step. Like the Stripe migration, replay each column independently.
        if(!az_stripe_has_column($db,'commerce_products','local_enabled'))
            $db->exec('ALTER TABLE commerce_products ADD COLUMN local_enabled INTEGER NOT NULL DEFAULT 0');
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_local_settings (
            id INTEGER PRIMARY KEY, enabled INTEGER NOT NULL DEFAULT 0,
            pickup_enabled INTEGER NOT NULL DEFAULT 0, delivery_enabled INTEGER NOT NULL DEFAULT 0,
            pickup_location VARCHAR(160) NOT NULL DEFAULT '',
            pickup_address VARCHAR(320) NOT NULL DEFAULT '',
            pickup_hours VARCHAR(200) NOT NULL DEFAULT '',
            pickup_instructions VARCHAR(500) NOT NULL DEFAULT '',
            delivery_zips TEXT NOT NULL, delivery_state CHAR(2) NOT NULL DEFAULT 'AZ',
            delivery_fee_cents BIGINT NOT NULL DEFAULT 0,
            delivery_min_cents BIGINT NOT NULL DEFAULT 0,
            delivery_instructions VARCHAR(500) NOT NULL DEFAULT '',
            revision INTEGER NOT NULL DEFAULT 1, updated_at VARCHAR(32) NOT NULL)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_local_orders (
            order_id CHAR(32) PRIMARY KEY, request_key CHAR(64) NOT NULL UNIQUE,
            cart_hash CHAR(64) NOT NULL, receipt_hash CHAR(64) NOT NULL UNIQUE,
            method VARCHAR(12) NOT NULL, delivery_zip CHAR(5) NULL,
            contact_ciphertext TEXT NOT NULL, pickup_snapshot TEXT NOT NULL,
            delivery_fee_cents BIGINT NOT NULL DEFAULT 0,
            subtotal_cents BIGINT NOT NULL, tax_cents BIGINT NULL,
            total_due_cents BIGINT NULL, status VARCHAR(18) NOT NULL DEFAULT 'pending',
            payment_status VARCHAR(16) NOT NULL DEFAULT 'due',
            revision INTEGER NOT NULL DEFAULT 1, created_at VARCHAR(32) NOT NULL,
            updated_at VARCHAR(32) NOT NULL, quote_confirmed_at VARCHAR(32) NULL,
            collected_at VARCHAR(32) NULL, fulfilled_at VARCHAR(32) NULL,
            cancelled_at VARCHAR(32) NULL, last_actor_id $uid NULL,
            FOREIGN KEY(order_id) REFERENCES commerce_orders(id) ON DELETE CASCADE,
            FOREIGN KEY(last_actor_id) REFERENCES users(id))$suffix");
        if($mysql){
            $q=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
            foreach([['commerce_local_orders','commerce_local_queue','(status,payment_status,created_at)'],
                ['commerce_local_orders','commerce_local_age','(created_at)']] as [$table,$name,$cols]){
                $q->execute([$table,$name]);if(!(int)$q->fetchColumn())$db->exec("CREATE INDEX $name ON $table $cols");
            }
        }else{
            $db->exec('CREATE INDEX IF NOT EXISTS commerce_local_queue ON commerce_local_orders(status,payment_status,created_at)');
            $db->exec('CREATE INDEX IF NOT EXISTS commerce_local_age ON commerce_local_orders(created_at)');
        }
        $db->exec('UPDATE studio_schema SET version=38 WHERE id=1');
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function az_local_settings_row(): array
{
    $q=az_db()->query('SELECT * FROM commerce_local_settings WHERE id=1');
    $row=$q->fetch(PDO::FETCH_ASSOC);
    return $row?:[
        'id'=>1,'enabled'=>0,'pickup_enabled'=>0,'delivery_enabled'=>0,
        'pickup_location'=>'','pickup_address'=>'','pickup_hours'=>'','pickup_instructions'=>'',
        'delivery_zips'=>'[]','delivery_state'=>'AZ','delivery_fee_cents'=>0,'delivery_min_cents'=>0,
        'delivery_instructions'=>'','revision'=>0,'updated_at'=>''
    ];
}
function az_local_zips(mixed $input): array
{
    if(is_array($input)){
        if(!array_is_list($input))throw new AzHttpError(400,'Delivery ZIP list must be an array.','invalid_zips');
        $parts=$input;
    }elseif(is_string($input)){
        if(strlen($input)>1500)throw new AzHttpError(400,'Delivery ZIP list is too long.','invalid_zips');
        $parts=preg_split('/[\s,;]+/',trim($input),-1,PREG_SPLIT_NO_EMPTY)?:[];
    }else throw new AzHttpError(400,'Enter delivery ZIP codes.','invalid_zips');
    if(count($parts)>100)throw new AzHttpError(400,'Limit delivery to 100 ZIP codes.','invalid_zips');
    $zips=[];
    foreach($parts as $zip){
        if(!is_string($zip)||!preg_match('/^[0-9]{5}$/D',$zip))throw new AzHttpError(400,'Use five-digit US ZIP codes.','invalid_zips');
        $zips[$zip]=true;
    }
    $result=array_keys($zips);sort($result,SORT_STRING);
    return $result;
}
function az_local_clean(mixed $value,int $limit,string $field,bool $required=false): string
{
    $result=trim(az_text($value,$limit,$field));
    if(preg_match('/[\x00-\x08\x0B-\x1F\x7F]/',$result))
        throw new AzHttpError(400,'Remove control characters from '.$field.'.','invalid_contact');
    if($required&&$result==='')throw new AzHttpError(400,'Enter '.$field.'.','missing_contact');
    return $result;
}
function az_local_options(): array
{
    $s=az_local_settings_row();
    $zips=json_decode((string)$s['delivery_zips'],true);
    if(!is_array($zips))$zips=[];
    $pickup=(int)$s['enabled']===1&&(int)$s['pickup_enabled']===1
        &&trim((string)$s['pickup_address'])!==''&&trim((string)$s['pickup_hours'])!=='';
    $delivery=(int)$s['enabled']===1&&(int)$s['delivery_enabled']===1&&count($zips)>0;
    return [
        'available'=>$pickup||$delivery,
        'pickupEnabled'=>$pickup,'deliveryEnabled'=>$delivery,
        'pickupLocation'=>$pickup?(string)$s['pickup_location']:'',
        'pickupAddress'=>$pickup?(string)$s['pickup_address']:'',
        'pickupHours'=>$pickup?(string)$s['pickup_hours']:'',
        'pickupInstructions'=>$pickup?(string)$s['pickup_instructions']:'',
        'deliveryZips'=>$delivery?$zips:[],'deliveryState'=>$delivery?(string)$s['delivery_state']:'',
        'deliveryFeeCents'=>$delivery?(int)$s['delivery_fee_cents']:0,
        'deliveryMinCents'=>$delivery?(int)$s['delivery_min_cents']:0,
        'deliveryInstructions'=>$delivery?(string)$s['delivery_instructions']:'',
        'notice'=>'Cash orders are requests until staff approve the final price and timing. No cash is collected online. Any applicable tax is confirmed by staff before collection.'
    ];
}
function az_local_settings_admin(array $user): array
{
    az_commerce_admin($user);$s=az_local_settings_row();
    return ['revision'=>(int)$s['revision'],'enabled'=>(bool)$s['enabled'],
        'pickupEnabled'=>(bool)$s['pickup_enabled'],'deliveryEnabled'=>(bool)$s['delivery_enabled'],
        'pickupLocation'=>$s['pickup_location'],'pickupAddress'=>$s['pickup_address'],
        'pickupHours'=>$s['pickup_hours'],'pickupInstructions'=>$s['pickup_instructions'],
        'deliveryZips'=>implode(', ',(array)(json_decode((string)$s['delivery_zips'],true)?:[])),
        'deliveryState'=>$s['delivery_state'],'deliveryFeeCents'=>(int)$s['delivery_fee_cents'],
        'deliveryMinCents'=>(int)$s['delivery_min_cents'],
        'deliveryInstructions'=>$s['delivery_instructions'],'updatedAt'=>$s['updated_at']];
}
function az_local_write(callable $fn): array
{
    $lock=az_community_lock();
    try {
        $db=az_db();$db->beginTransaction();
        try{$result=$fn($db);$db->commit();}
        catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }finally{flock($lock,LOCK_UN);fclose($lock);}
    try{az_commerce_dispatch(90);}
    catch(Throwable $e){error_log('Cash Commerce delivery deferred: '.$e->getMessage());}
    return $result;
}
function az_local_settings_save(array $user,array $body): array
{
    az_commerce_admin($user);
    $enabled=az_bool($body['enabled']??false,'Cash ordering enabled');
    $pickup=az_bool($body['pickupEnabled']??false,'Pickup enabled');
    $delivery=az_bool($body['deliveryEnabled']??false,'Delivery enabled');
    $location=az_local_clean($body['pickupLocation']??'',160,'Pickup location');
    $address=az_local_clean($body['pickupAddress']??'',320,'Pickup address');
    $hours=az_local_clean($body['pickupHours']??'',200,'Pickup hours');
    $pickupNotes=az_local_clean($body['pickupInstructions']??'',500,'Pickup instructions');
    $deliveryNotes=az_local_clean($body['deliveryInstructions']??'',500,'Delivery instructions');
    $zips=az_local_zips($body['deliveryZips']??'');
    $state=strtoupper(az_local_clean($body['deliveryState']??'AZ',2,'Delivery state'));
    if(!preg_match('/^[A-Z]{2}$/D',$state))throw new AzHttpError(400,'Enter a two-letter US state.','invalid_state');
    $fee=az_integer($body['deliveryFeeCents']??0,0,1000000,'Delivery fee');
    $minimum=az_integer($body['deliveryMinCents']??0,0,100000000,'Minimum delivery subtotal');
    if($enabled&&!$pickup&&!$delivery)throw new AzHttpError(400,'Enable pickup, delivery, or both.','no_cash_methods');
    if($enabled&&$pickup&&($address===''||$hours===''))
        throw new AzHttpError(400,'Publish a pickup address and hours before enabling pickup.','pickup_unavailable');
    if($enabled&&$delivery&&!$zips)throw new AzHttpError(400,'Add delivery ZIP codes before enabling delivery.','delivery_unavailable');
    $rev=az_integer($body['revision']??0,0,PHP_INT_MAX,'Settings revision');
    $actor=(int)$user['id'];
    return az_local_write(static function(PDO $db)use($enabled,$pickup,$delivery,$location,$address,$hours,$pickupNotes,
        $deliveryNotes,$zips,$state,$fee,$minimum,$rev,$actor):array{
        $existing=az_local_settings_row();$now=az_now();$json=json_encode($zips,JSON_THROW_ON_ERROR);
        if($rev!==(int)$existing['revision'])
            throw new AzHttpError(409,'Cash settings changed. Reload before saving.','revision_conflict');
        if((int)$existing['revision']===0){
            $db->prepare('INSERT INTO commerce_local_settings(id,enabled,pickup_enabled,delivery_enabled,pickup_location,pickup_address,pickup_hours,pickup_instructions,delivery_zips,delivery_state,delivery_fee_cents,delivery_min_cents,delivery_instructions,revision,updated_at) VALUES(1,?,?,?,?,?,?,?,?,?,?,?,?,1,?)')
              ->execute([$enabled?1:0,$pickup?1:0,$delivery?1:0,$location,$address,$hours,$pickupNotes,$json,$state,$fee,$minimum,$deliveryNotes,$now]);
        }else{
            $q=$db->prepare('UPDATE commerce_local_settings SET enabled=?,pickup_enabled=?,delivery_enabled=?,pickup_location=?,pickup_address=?,pickup_hours=?,pickup_instructions=?,delivery_zips=?,delivery_state=?,delivery_fee_cents=?,delivery_min_cents=?,delivery_instructions=?,revision=revision+1,updated_at=? WHERE id=1 AND revision=?');
            $q->execute([$enabled?1:0,$pickup?1:0,$delivery?1:0,$location,$address,$hours,$pickupNotes,$json,$state,$fee,$minimum,$deliveryNotes,$now,$rev]);
            if($q->rowCount()!==1)throw new AzHttpError(409,'Cash settings changed before saving.','revision_conflict');
        }
        az_commerce_queue('local-settings:'.$now.':'.($rev+1),$actor,$actor,'local_settings',
            'Local cash ordering settings updated','Pickup and delivery availability was changed by an administrator.','commerce.php',false);
        return ['revision'=>$rev+1,'enabled'=>$enabled,'pickupEnabled'=>$pickup,'deliveryEnabled'=>$delivery];
    });
}
function az_local_secret(): string
{
    $secret=(string)(az_config()['app_secret']??'');
    if(strlen($secret)<32)throw new RuntimeException('Application secret is not available for private cash-order records.');
    return $secret;
}
function az_local_crypto_key(): string
{
    return hash_hmac('sha256','commerce-local-contact-v1',az_local_secret(),true);
}
function az_local_encrypt(array $contact): string
{
    if(!function_exists('openssl_encrypt'))throw new RuntimeException('OpenSSL is required for private cash orders.');
    $iv=random_bytes(12);$tag='';
    $plain=json_encode($contact,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $sealed=openssl_encrypt($plain,'aes-256-gcm',az_local_crypto_key(),OPENSSL_RAW_DATA,$iv,$tag,
        'azhottie-local-v1',16);
    if($sealed===false||strlen($tag)!==16)throw new RuntimeException('Contact protection is unavailable.');
    return base64_encode(chr(1).$iv.$tag.$sealed);
}
function az_local_decrypt(string $ciphertext): array
{
    if(!function_exists('openssl_decrypt'))throw new RuntimeException('OpenSSL is required for private cash orders.');
    $data=base64_decode($ciphertext,true);
    if($data===false||strlen($data)<30||ord($data[0])!==1)
        throw new RuntimeException('Cash order contact data is invalid.');
    $iv=substr($data,1,12);$tag=substr($data,13,16);$encrypted=substr($data,29);
    $plain=openssl_decrypt($encrypted,'aes-256-gcm',az_local_crypto_key(),OPENSSL_RAW_DATA,$iv,$tag,'azhottie-local-v1');
    if(!is_string($plain))throw new RuntimeException('Cash order contact integrity check failed.');
    $contact=json_decode($plain,true,32,JSON_THROW_ON_ERROR);
    if(!is_array($contact))throw new RuntimeException('Invalid cash order contact format.');
    return $contact;
}
function az_local_contact(array $body,string $method,array $settings): array
{
    $name=az_local_clean($body['name']??'',120,'Full name',true);
    if(mb_strlen($name)<2)throw new AzHttpError(400,'Enter your full name.','invalid_contact');
    $phone=az_local_clean($body['phone']??'',32,'Phone number',true);
    $digits=preg_replace('/[\s().-]+/','',$phone)??'';
    if(!preg_match('/^\+?[0-9]{10,15}$/D',$digits))
        throw new AzHttpError(400,'Enter a phone number with 10 to 15 digits.','invalid_phone');
    $email=az_local_clean($body['email']??'',254,'Email address');
    if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))
        throw new AzHttpError(400,'Enter a valid email address or leave it blank.','invalid_email');
    $note=az_local_clean($body['note']??'',500,'Order note');
    $contact=['name'=>$name,'phone'=>$digits,'email'=>strtolower($email),'note'=>$note];
    if($method==='delivery'){
        $street=az_local_clean($body['street']??'',180,'Street address',true);
        $unit=az_local_clean($body['unit']??'',80,'Apartment or unit');
        $city=az_local_clean($body['city']??'',100,'City',true);
        $state=strtoupper(az_local_clean($body['state']??'',2,'State',true));
        $zip=az_local_clean($body['zip']??'',5,'ZIP code',true);
        if(!preg_match('/^[A-Z]{2}$/D',$state)||$state!==$settings['deliveryState']
            ||!preg_match('/^[0-9]{5}$/D',$zip)||!in_array($zip,$settings['deliveryZips'],true))
            throw new AzHttpError(409,'Delivery is not configured for this state and ZIP code.','delivery_area_unavailable');
        if(mb_strlen($street)<5)throw new AzHttpError(400,'Enter a full street address.','invalid_address');
        $contact+=['street'=>$street,'unit'=>$unit,'city'=>$city,'state'=>$state,'zip'=>$zip];
    }
    return $contact;
}
