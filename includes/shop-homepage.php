<?php
declare(strict_types=1);
/** Shop 2.1: a published editorial layout + admin draft; no commerce or inventory mutation. */
function az_shop_homepage_sections(): array
{
    return ['hero'=>'Hero banner','categories'=>'Category tiles','featured'=>'Trending products',
        'catalog'=>'Shop all / filters','support'=>'Customer service links'];
}
function az_shop_homepage_default(): array
{
    return [
        'storeEnabled'=>true,'sampleEnabled'=>false,
        'eyebrow'=>'CURATED FOR THE CURIOUS','heading'=>'Discover your next',
        'accent'=>'favorite.','description'=>'Creator picks, curated bundles, and premium lifestyle products — all in one place.',
        'ctaText'=>'Shop the Collection','ctaHref'=>'#shop-products',
        'categoriesTitle'=>'Shop by Collection','featuredTitle'=>'Trending Now',
        'catalogTitle'=>'Explore the Collection',
        'heroImage'=>'default','backgroundImage'=>'none','heroPosition'=>'center',
        'heroAlignment'=>'left','heroHeight'=>440,'headingSize'=>65,
        'fontStyle'=>'modern','textColor'=>'#251d28','accentColor'=>'#cf2167',
        'buttonColor'=>'#d82676','backgroundColor'=>'#ffffff','overlayStrength'=>5,
        'sections'=>array_fill_keys(array_keys(az_shop_homepage_sections()),true),
        'categories'=>array_fill_keys(array_keys(az_shop_categories()),true),
        'order'=>array_keys(az_shop_homepage_sections())
    ];
}
function az_upgrade_shop_homepage_schema(PDO $db): void
{
    $current=az_schema_version($db);
    if($current>=53 || $current<52)return;
    az_prepare_storage();$file=AZ_STORAGE.'/schema-migration.lock';$lock=fopen($file,'c+b');
    if(!$lock || !flock($lock,LOCK_EX))throw new RuntimeException('Shop homepage migration lock unavailable.');
    try{
        if(az_schema_version($db)>=53)return;
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $text=$mysql?'LONGTEXT':'TEXT';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS shop_homepage_settings (
            id INTEGER PRIMARY KEY, published_json $text NOT NULL, draft_json $text NOT NULL,
            revision INTEGER NOT NULL DEFAULT 1, published_revision INTEGER NOT NULL DEFAULT 1,
            updated_at VARCHAR(32) NOT NULL, published_at VARCHAR(32) NOT NULL)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS shop_homepage_images (
            id CHAR(32) PRIMARY KEY, storage_name VARCHAR(64) NOT NULL UNIQUE,
            mime_type VARCHAR(30) NOT NULL, alt_text VARCHAR(180) NOT NULL,
            width INTEGER NOT NULL, height INTEGER NOT NULL, created_at VARCHAR(32) NOT NULL)$suffix");
        $q=$db->query('SELECT COUNT(*) FROM shop_homepage_settings WHERE id=1');
        if(!(int)$q->fetchColumn()){
            $json=json_encode(az_shop_homepage_default(),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $q=$db->prepare('INSERT INTO shop_homepage_settings(id,published_json,draft_json,revision,published_revision,updated_at,published_at) VALUES(1,?,?,1,1,?,?)');
            $q->execute([$json,$json,az_now(),az_now()]);
        }
        $db->exec('UPDATE studio_schema SET version=53 WHERE id=1');
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function az_shop_homepage_state(?PDO $db=null): array
{
    $db??=az_db();$row=$db->query('SELECT * FROM shop_homepage_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('Homepage configuration missing.');
    $defaults=az_shop_homepage_default();
    foreach(['published_json','draft_json'] as $key){
        $decoded=json_decode((string)$row[$key],true);
        if(!is_array($decoded))throw new RuntimeException('Homepage configuration is invalid.');
        $row[$key]=array_replace($defaults,$decoded);
    }
    $row['revision']=(int)$row['revision'];$row['published_revision']=(int)$row['published_revision'];
    return $row;
}
function az_shop_homepage_public(?PDO $db=null): array
{
    if(!az_is_installed())return az_shop_homepage_default();
    return az_shop_homepage_state($db)['published_json'];
}
function az_shop_homepage_image_src(string $id): string
{
    if($id==='default')return 'assets/shop-editorial-hero-v157.webp';
    return $id==='none'?'':'shop-homepage-media.php?id='.rawurlencode($id);
}
function az_shop_homepage_image_valid(string $id,PDO $db): bool
{
    if(in_array($id,['default','none'],true))return true;
    if(!az_valid_id($id))return false;
    $q=$db->prepare('SELECT id FROM shop_homepage_images WHERE id=?');$q->execute([$id]);
    return (bool)$q->fetchColumn();
}
function az_shop_homepage_validate(array $body, PDO $db): array
{
    $defaults=az_shop_homepage_default();$out=$defaults;
    foreach(['storeEnabled','sampleEnabled'] as $key){
        if(!isset($body[$key])||!is_bool($body[$key]))throw new AzHttpError(400,'Invalid store setting: '.$key,'invalid_setting');
        $out[$key]=$body[$key];
    }
    foreach(['eyebrow'=>80,'heading'=>100,'accent'=>90,'description'=>320,
            'ctaText'=>55,'categoriesTitle'=>90,'featuredTitle'=>90,'catalogTitle'=>90] as $key=>$limit){
        if(!isset($body[$key])||!is_string($body[$key]))throw new AzHttpError(400,'Missing text: '.$key,'invalid_text');
        $value=trim($body[$key]);
        if(strlen($value)>$limit || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))
            throw new AzHttpError(400,'Invalid text: '.$key,'invalid_text');
        if($key==='heading' && $value==='')throw new AzHttpError(400,'Hero heading is required.','invalid_text');
        $out[$key]=$value;
    }
    $links=['#shop-products','#shop-categories','#shop-browse','watch.php','creator-clubs.php'];
    if(!in_array($body['ctaHref']??null,$links,true))throw new AzHttpError(400,'Choose an internal shop link.','invalid_link');
    $out['ctaHref']=$body['ctaHref'];
    foreach(['heroImage','backgroundImage'] as $key){
        $id=$body[$key]??null;
        if(!is_string($id)||!az_shop_homepage_image_valid($id,$db))throw new AzHttpError(400,'Upload or select an approved image.','invalid_image');
        $out[$key]=$id;
    }
    foreach(['heroPosition'=>['left','center','right'],'heroAlignment'=>['left','center'],
              'fontStyle'=>['modern','editorial','classic']] as $key=>$allowed){
        if(!in_array($body[$key]??null,$allowed,true))throw new AzHttpError(400,'Invalid layout setting.','invalid_layout');
        $out[$key]=$body[$key];
    }
    foreach(['heroHeight'=>[300,700],'headingSize'=>[35,90],'overlayStrength'=>[0,80]] as $key=>[$min,$max]){
        $v=$body[$key]??null;
        if(!is_int($v)||$v<$min||$v>$max)throw new AzHttpError(400,'Invalid visual setting.','invalid_layout');
        $out[$key]=$v;
    }
    foreach(['textColor','accentColor','buttonColor','backgroundColor'] as $key){
        if(!is_string($body[$key]??null)||!preg_match('/^#[a-fA-F0-9]{6}$/D',$body[$key]))
            throw new AzHttpError(400,'Color must be #RRGGBB.','invalid_color');
        $out[$key]=strtolower($body[$key]);
    }
    foreach(['sections'=>az_shop_homepage_sections(),'categories'=>az_shop_categories()] as $key=>$keys){
        $incoming=$body[$key]??null;
        if($incoming instanceof stdClass)$incoming=get_object_vars($incoming);
        if(!is_array($incoming)||array_keys($incoming)!==array_keys($keys))
            throw new AzHttpError(400,'Invalid visibility settings.','invalid_visibility');
        foreach($incoming as $slug=>$enabled){
            if(!is_bool($enabled))throw new AzHttpError(400,'Visibility must be on or off.','invalid_visibility');
            $out[$key][$slug]=$enabled;
        }
    }
    $order=$body['order']??null;
    if(!is_array($order)||count($order)!==count($defaults['order'])||array_diff($order,$defaults['order'])||count(array_unique($order))!==count($order))
        throw new AzHttpError(400,'Invalid section order.','invalid_order');
    $out['order']=array_values($order);
    return $out;
}
function az_shop_homepage_admin_state(array $user,?PDO $db=null):array
{
    az_shop_admin($user);$db??=az_db();$state=az_shop_homepage_state($db);
    $images=$db->query('SELECT id,mime_type,alt_text,width,height FROM shop_homepage_images ORDER BY created_at DESC LIMIT 60')->fetchAll(PDO::FETCH_ASSOC);
    foreach($images as &$im)$im['src']=az_shop_homepage_image_src($im['id']);unset($im);
    return ['draft'=>$state['draft_json'],'published'=>$state['published_json'],
        'revision'=>$state['revision'],'publishedRevision'=>$state['published_revision'],
        'publishedAt'=>$state['published_at'],'sections'=>az_shop_homepage_sections(),
        'categories'=>az_shop_categories(),'images'=>$images];
}
function az_shop_homepage_save(array $user,array $body,?PDO $db=null):array
{
    az_shop_admin($user);$db??=az_db();
    $revision=$body['revision']??null;
    if(!is_int($revision)||$revision<1)throw new AzHttpError(400,'Invalid revision.','invalid_revision');
    $mode=(string)($body['action']??'');
    if(!in_array($mode,['saveDraft','publish','resetDraft'],true))throw new AzHttpError(400,'Invalid operation.','invalid_action');
    $state=az_shop_homepage_state($db);
    if($state['revision']!==$revision)throw new AzHttpError(409,'Homepage changed in another tab. Reload to continue.','revision_conflict');
    if($mode==='resetDraft')$draft=$state['published_json'];
    else {
        // az_json_body keeps nested JSON objects as stdClass. Normalize the
        // expected design object here; its fields still pass strict validation.
        $design=$body['design']??null;
        if($design instanceof stdClass)$design=get_object_vars($design);
        if(!is_array($design))throw new AzHttpError(400,'Invalid design payload.','invalid_design');
        $draft=az_shop_homepage_validate($design,$db);
    }
    $encoded=json_encode($draft,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $date=az_now();
    if($mode==='publish'){
        $q=$db->prepare('UPDATE shop_homepage_settings SET draft_json=?,published_json=?,revision=revision+1,published_revision=published_revision+1,updated_at=?,published_at=? WHERE id=1 AND revision=?');
        $q->execute([$encoded,$encoded,$date,$date,$revision]);
    }else{
        $q=$db->prepare('UPDATE shop_homepage_settings SET draft_json=?,revision=revision+1,updated_at=? WHERE id=1 AND revision=?');
        $q->execute([$encoded,$date,$revision]);
    }
    if($q->rowCount()!==1)throw new AzHttpError(409,'Homepage changed; reload before saving.','revision_conflict');
    return ['saved'=>true,'published'=>$mode==='publish','revision'=>$revision+1,'data'=>$draft];
}
function az_shop_homepage_toggle(array $user,array $body,?PDO $db=null):array
{
    az_shop_admin($user);$db??=az_db();
    $key=$body['key']??null;$enabled=$body['enabled']??null;$rev=$body['revision']??null;
    if(!in_array($key,['sampleEnabled','storeEnabled'],true)||!is_bool($enabled)||!is_int($rev))
        throw new AzHttpError(400,'Invalid store toggle.','invalid_toggle');
    $state=az_shop_homepage_state($db);
    if($state['revision']!==$rev)throw new AzHttpError(409,'Settings changed; refresh.','revision_conflict');
    $draft=$state['draft_json'];$live=$state['published_json'];
    $draft[$key]=$enabled;$live[$key]=$enabled;
    $q=$db->prepare('UPDATE shop_homepage_settings SET draft_json=?,published_json=?,revision=revision+1,published_revision=published_revision+1,updated_at=?,published_at=? WHERE id=1 AND revision=?');
    $now=az_now();$q->execute([json_encode($draft,JSON_THROW_ON_ERROR),json_encode($live,JSON_THROW_ON_ERROR),$now,$now,$rev]);
    if($q->rowCount()!==1)throw new AzHttpError(409,'Settings changed; refresh.','revision_conflict');
    return ['saved'=>true,'key'=>$key,'enabled'=>$enabled,'revision'=>$rev+1];
}
function az_shop_homepage_image_dir():string
{
    az_prepare_storage();$dir=AZ_STORAGE.'/homepage-images';
    if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Homepage image storage unavailable.');
    $deny="<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";
    if(!is_file($dir.'/.htaccess'))file_put_contents($dir.'/.htaccess',$deny,LOCK_EX);
    return $dir;
}
function az_shop_homepage_image_upload(array $user,array $file,string $alt,?PDO $db=null):array
{
    az_shop_admin($user);$db??=az_db();
    if(!is_string($file['tmp_name']??null)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']))
        throw new AzHttpError(400,'Choose a valid image.','invalid_upload');
    $size=(int)($file['size']??0);if($size<200||$size>5*1024*1024)throw new AzHttpError(400,'Image must be under 5 MB.','image_size');
    $info=@getimagesize($file['tmp_name']);$mime=(string)($info['mime']??'');
    $mimes=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if(!isset($mimes[$mime])||($info[0]??0)<600||($info[1]??0)<280||($info[0]??0)>6000||($info[1]??0)>6000)
        throw new AzHttpError(400,'Use a 600px+ JPEG, PNG or WebP image.','invalid_image');
    $alt=trim($alt);if($alt===''||mb_strlen($alt)>180)throw new AzHttpError(400,'Add a short image description.','invalid_alt');
    $count=(int)$db->query('SELECT COUNT(*) FROM shop_homepage_images')->fetchColumn();
    if($count>=24)throw new AzHttpError(409,'Delete an unused image before uploading another.','image_limit');
    $id=az_id();$storage=$id.'.'.$mimes[$mime];$dest=az_shop_homepage_image_dir().'/'.$storage;
    if(!move_uploaded_file($file['tmp_name'],$dest))throw new RuntimeException('Could not save homepage image.');
    @chmod($dest,0600);
    try{
        $q=$db->prepare('INSERT INTO shop_homepage_images(id,storage_name,mime_type,alt_text,width,height,created_at) VALUES(?,?,?,?,?,?,?)');
        $q->execute([$id,$storage,$mime,$alt,(int)$info[0],(int)$info[1],az_now()]);
    }catch(Throwable $e){@unlink($dest);throw $e;}
    return ['id'=>$id,'src'=>az_shop_homepage_image_src($id),'alt'=>$alt];
}
function az_shop_homepage_image_access(string $id,?array $user=null,?PDO $db=null):array
{
    if(!az_valid_id($id))throw new AzHttpError(404,'Image unavailable.','image_missing');
    $db??=az_db();$q=$db->prepare('SELECT * FROM shop_homepage_images WHERE id=?');$q->execute([$id]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new AzHttpError(404,'Image unavailable.','image_missing');
    $admin=is_array($user)&&($user['status']??'')==='active'&&az_has_role($user,'admin');
    if(!$admin){
        $live=az_shop_homepage_public($db);
        if(!in_array($id,[$live['heroImage'],$live['backgroundImage']],true))
            throw new AzHttpError(404,'Image unavailable.','image_missing');
    }
    if(!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/D',$row['storage_name']))
        throw new AzHttpError(404,'Image unavailable.','image_missing');
    return $row;
}
function az_shop_homepage_image_delete(array $user,string $id,?PDO $db=null):array
{
    az_shop_admin($user);$db??=az_db();$row=az_shop_homepage_image_access($id,$user,$db);
    $state=az_shop_homepage_state($db);
    foreach(['draft_json','published_json'] as $key){
        if(in_array($id,[$state[$key]['heroImage'],$state[$key]['backgroundImage']],true))
            throw new AzHttpError(409,'Image is used in a saved or published layout.','image_in_use');
    }
    $db->prepare('DELETE FROM shop_homepage_images WHERE id=?')->execute([$id]);
    @unlink(az_shop_homepage_image_dir().'/'.$row['storage_name']);
    return ['deleted'=>true,'id'=>$id];
}
function az_shop_homepage_demo_products():array
{
    $items=[
        ['hoodie','AZHottie Crop Hoodie','apparel',4999,'Signature comfort','Studio-inspired everyday style.','Bestseller'],
        ['bottle','AZHottie Water Bottle','fitness',2499,'Hydrate in style','Reusable blush pink fitness essential.','New'],
        ['bundle','Self Care Bundle','lifestyle',5999,'A little indulgence','Curated gift-ready lifestyle set.','Bundle'],
        ['print','Signed Print — Editorial','photography',3999,'Collect your favorite moments','Illustrative studio print preview.','Limited'],
        ['gift','Gift Card','gifts',5000,'Give them a choice','Example digital gifting option.','Preview'],
        ['tote','AZHottie Tote Bag','accessories',3499,'Carry your favorites','A versatile everyday carryall.','Studio pick'],
        ['candles','Candle Set','spa',2999,'Make time for yourself','Cozy candles for a relaxed evening.','Popular'],
        ['lounge','Lounge Set','apparel',8999,'Effortless everyday comfort','Coordinated soft lounge essentials.','New'],
    ];
    return array_map(static function(array $a):array{
        [$slug,$title,$category,$price,$tagline,$description,$badge]=$a;
        return ['id'=>'demo-'.$slug,'title'=>$title,'category'=>$category,'categoryLabel'=>az_shop_categories()[$category],
            'priceCents'=>$price,'tagline'=>$tagline,'description'=>$description,'badge'=>$badge,
            'featured'=>true,'demo'=>true,'images'=>[['src'=>'assets/shop-demo-'.$slug.'-v157.webp','alt'=>$title.' sample illustration']]];
    },$items);
}
