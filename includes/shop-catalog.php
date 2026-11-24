<?php
declare(strict_types=1);
/** AZHottie 1.56 storefront merchandising: separate from charging and inventory. */
function az_shop_categories(): array
{
    return ['apparel'=>'Clothing','fitness'=>'Fitness','accessories'=>'Accessories',
        'candy'=>'Candy','digital'=>'Digital','lifestyle'=>'Lifestyle','other'=>'Other'];
}
function az_upgrade_shop_catalog_schema(PDO $db): void
{
    if (az_schema_version($db)>=52 || az_schema_version($db)<51) return;
    az_prepare_storage();
    $lock=fopen(AZ_STORAGE.'/schema-migration.lock','c+b');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Shop catalog migration lock unavailable.');
    try {
        if (az_schema_version($db)>=52) return;
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_product_catalog (
            product_id CHAR(32) PRIMARY KEY, category VARCHAR(24) NOT NULL DEFAULT 'lifestyle',
            tagline VARCHAR(180) NOT NULL DEFAULT '', featured INTEGER NOT NULL DEFAULT 0,
            revision INTEGER NOT NULL DEFAULT 1, updated_at VARCHAR(32) NOT NULL,
            FOREIGN KEY(product_id) REFERENCES commerce_products(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_product_images (
            id CHAR(32) PRIMARY KEY, product_id CHAR(32) NOT NULL,
            storage_name VARCHAR(64) NOT NULL UNIQUE, mime_type VARCHAR(24) NOT NULL,
            alt_text VARCHAR(180) NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(32) NOT NULL,
            FOREIGN KEY(product_id) REFERENCES commerce_products(id) ON DELETE CASCADE)$suffix");
        $db->exec("CREATE TABLE IF NOT EXISTS commerce_bundle_components (
            bundle_id CHAR(32) NOT NULL, component_id CHAR(32) NOT NULL,
            position INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(bundle_id,component_id),
            FOREIGN KEY(bundle_id) REFERENCES commerce_products(id) ON DELETE CASCADE,
            FOREIGN KEY(component_id) REFERENCES commerce_products(id) ON DELETE CASCADE)$suffix");
        if ($mysql) {
            foreach ([['commerce_product_images','shop_images_product','product_id,sort_order'],
                      ['commerce_bundle_components','shop_component_lookup','component_id']] as [$table,$index,$cols]) {
                $check=$db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
                $check->execute([$table,$index]);if (!(int)$check->fetchColumn())$db->exec("CREATE INDEX $index ON $table ($cols)");
            }
        } else {
            $db->exec('CREATE INDEX IF NOT EXISTS shop_images_product ON commerce_product_images(product_id,sort_order)');
            $db->exec('CREATE INDEX IF NOT EXISTS shop_component_lookup ON commerce_bundle_components(component_id)');
        }
        $db->exec('UPDATE studio_schema SET version=52 WHERE id=1');
    } finally { flock($lock,LOCK_UN);fclose($lock); }
}
function az_shop_admin(array $user): void
{
    if (($user['status']??'')!=='active' || !az_has_role($user,'admin'))
        throw new AzHttpError(403,'Administrator access required.','role_required');
}
function az_shop_catalog_default(): array
{
    return ['category'=>'lifestyle','tagline'=>'','featured'=>0,'revision'=>0];
}
function az_shop_catalog_row(string $id, ?PDO $db=null):array
{
    if (!az_valid_id($id))throw new AzHttpError(404,'Product not found.','not_found');
    $db??=az_db();$q=$db->prepare('SELECT * FROM commerce_product_catalog WHERE product_id=?');
    $q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    return $row?:az_shop_catalog_default();
}
function az_shop_image_rows(string $id,?PDO $db=null):array
{
    $db??=az_db();$q=$db->prepare('SELECT id,product_id,mime_type,alt_text,sort_order FROM commerce_product_images WHERE product_id=? ORDER BY sort_order,id LIMIT 6');
    $q->execute([$id]);return array_map(static fn(array $r):array=>[
        'id'=>$r['id'],'alt'=>$r['alt_text'],'src'=>'shop-media.php?id='.$r['id']
    ],$q->fetchAll(PDO::FETCH_ASSOC));
}
function az_shop_public_products(): array
{
    $products=az_commerce_public_products(); if(!$products)return [];
    $db=az_db();$ids=array_column($products,'id');$marks=implode(',',array_fill(0,count($ids),'?'));
    $catalog=[];$q=$db->prepare("SELECT * FROM commerce_product_catalog WHERE product_id IN ($marks)");
    $q->execute($ids);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$catalog[$row['product_id']]=$row;
    $images=[];$q=$db->prepare("SELECT id,product_id,alt_text FROM commerce_product_images WHERE product_id IN ($marks) ORDER BY sort_order,id");
    $q->execute($ids);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        if(count($images[$row['product_id']]??[])>=6)continue;
        $images[$row['product_id']][]=['id'=>$row['id'],'alt'=>$row['alt_text'],'src'=>'shop-media.php?id='.$row['id']];
    }
    $recommendations=[];
    $q=$db->prepare("SELECT a.product_id,v.id AS video_id,v.title AS video_title,v.public_credit
        FROM commerce_promotions a JOIN model_videos v ON v.id=a.video_id AND v.user_id=a.model_id
        JOIN users u ON u.id=a.model_id AND u.status='active'
        WHERE a.product_id IN ($marks) AND a.state='approved' AND a.video_revision=v.revision
        AND v.status='published' AND v.rights_confirmed=1 AND v.published_at IS NOT NULL
        ORDER BY a.updated_at DESC");
    $q->execute($ids);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        if(isset($recommendations[$row['product_id']]))continue;
        $credit=trim((string)$row['public_credit']);
        $recommendations[$row['product_id']]=['credit'=>$credit!==''?$credit:'An AZHottie creator',
            'videoTitle'=>$row['video_title'],'url'=>'watch.php?id='.$row['video_id']];
    }
    $components=[];
    $q=$db->prepare("SELECT c.bundle_id,c.component_id,p.title,p.state FROM commerce_bundle_components c
        JOIN commerce_products p ON p.id=c.component_id WHERE c.bundle_id IN ($marks) ORDER BY c.position,c.component_id");
    $q->execute($ids);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        if($row['state']==='active' && in_array($row['component_id'],$ids,true))
            $components[$row['bundle_id']][]=['id'=>$row['component_id'],'title'=>$row['title'],'url'=>'shop-product.php?id='.$row['component_id']];
    }
    $categories=az_shop_categories();
    foreach($products as &$product){
        $id=$product['id'];$c=$catalog[$id]??az_shop_catalog_default();
        $category=array_key_exists($c['category'],$categories)?$c['category']:'other';
        $product['category']=$category;$product['categoryLabel']=$categories[$category];
        $product['tagline']=$c['tagline'];$product['featured']=(bool)$c['featured'];
        $product['images']=$images[$id]??[];
        $product['recommendation']=$recommendations[$id]??null;
        $product['bundleContents']=$components[$id]??[];
    }unset($product);
    usort($products,static fn(array $a,array $b):int=>(int)$b['featured']<=>(int)$a['featured']);
    return $products;
}
function az_shop_catalog_state(array $user): array
{
    az_shop_admin($user);$db=az_db();$q=$db->query('SELECT id,title,state FROM commerce_products ORDER BY updated_at DESC LIMIT 150');
    $items=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $c=az_shop_catalog_row($r['id'],$db);
        $bundle=$db->prepare('SELECT component_id FROM commerce_bundle_components WHERE bundle_id=? ORDER BY position,component_id');
        $bundle->execute([$r['id']]);
        $items[]=['id'=>$r['id'],'title'=>$r['title'],'state'=>$r['state'],
            'category'=>$c['category'],'tagline'=>$c['tagline'],'featured'=>(bool)$c['featured'],
            'revision'=>(int)$c['revision'],'components'=>$bundle->fetchAll(PDO::FETCH_COLUMN),
            'images'=>az_shop_image_rows($r['id'],$db)];
    }
    return ['products'=>$items,'categories'=>az_shop_categories(),
        'notice'=>'Bundles are individually priced and fulfilled SKUs. Component stock is not reserved automatically.'];
}
function az_shop_catalog_save(array $user,array $body,?PDO $db=null):array
{
    az_shop_admin($user);$db??=az_db();
    $id=(string)($body['productId']??'');if(!az_valid_id($id))throw new AzHttpError(400,'Select a product.','invalid_product');
    $p=$db->prepare('SELECT id FROM commerce_products WHERE id=?');$p->execute([$id]);
    if(!$p->fetchColumn())throw new AzHttpError(404,'Product not found.','not_found');
    $cat=(string)($body['category']??'lifestyle');
    if(!array_key_exists($cat,az_shop_categories()))throw new AzHttpError(400,'Invalid collection.','invalid_category');
    $tagline=trim((string)($body['tagline']??''));
    if(strlen($tagline)>180 || preg_match('/[\x00-\x1f\x7f]/',$tagline))throw new AzHttpError(400,'Invalid product tagline.','invalid_tagline');
    $featured=($body['featured']??false)===true?1:0;
    $rev=$body['revision']??0;
    if(!is_int($rev)||$rev<0)throw new AzHttpError(400,'Invalid revision.','invalid_revision');
    $components=$body['components']??[];
    if(!is_array($components)||count($components)>4 || count(array_filter($components,'is_string'))!==count($components))
        throw new AzHttpError(400,'Choose up to four distinct bundle items.','invalid_bundle');
    if(count($components)!==count(array_unique($components)))
        throw new AzHttpError(400,'Bundle items must be distinct.','invalid_bundle');
    foreach($components as $child){
        if(!is_string($child)||!az_valid_id($child)||$child===$id)
            throw new AzHttpError(400,'Bundle cannot contain itself or invalid items.','invalid_bundle');
        $v=$db->prepare("SELECT id FROM commerce_products WHERE id=? AND state='active'");$v->execute([$child]);
        if(!$v->fetchColumn())throw new AzHttpError(400,'Bundle components must be active products.','invalid_bundle');
    }
    $db->beginTransaction();
    try {
        $now=az_now();$old=az_shop_catalog_row($id,$db);
        if((int)$old['revision']!==$rev)throw new AzHttpError(409,'Merchandising changed. Reload before saving.','revision_conflict');
        if($rev===0){
            $db->prepare('INSERT INTO commerce_product_catalog(product_id,category,tagline,featured,updated_at) VALUES(?,?,?,?,?)')
                ->execute([$id,$cat,$tagline,$featured,$now]);
        }else{
            $q=$db->prepare('UPDATE commerce_product_catalog SET category=?,tagline=?,featured=?,revision=revision+1,updated_at=? WHERE product_id=? AND revision=?');
            $q->execute([$cat,$tagline,$featured,$now,$id,$rev]);
            if($q->rowCount()!==1)throw new AzHttpError(409,'Merchandising changed. Reload before saving.','revision_conflict');
        }
        $db->prepare('DELETE FROM commerce_bundle_components WHERE bundle_id=?')->execute([$id]);
        $s=$db->prepare('INSERT INTO commerce_bundle_components(bundle_id,component_id,position) VALUES(?,?,?)');
        foreach($components as $i=>$child)$s->execute([$id,$child,$i]);
        $db->commit();return ['productId'=>$id,'revision'=>$rev+1,'saved'=>true];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function az_shop_image_input_check(array $file):array
{
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !isset($file['tmp_name']) || !is_string($file['tmp_name']))
        throw new AzHttpError(400,'Select an image to upload.','invalid_image');
    $size=(int)($file['size']??0);
    if($size<200 || $size>4*1024*1024)throw new AzHttpError(400,'Images must be under 4 MB.','image_size');
    $tmp=$file['tmp_name'];
    if(!is_uploaded_file($tmp))throw new AzHttpError(400,'Invalid file upload.','invalid_image');
    $info=@getimagesize($tmp);if(!$info || !isset($info[0],$info[1],$info['mime']))
        throw new AzHttpError(400,'Invalid image format.','invalid_image');
    [$width,$height]=$info;
    if($width<120 || $height<120 || $width>6000 || $height>6000 || $width*$height>24000000)
        throw new AzHttpError(400,'Images must be 120–6000 px and under 24 megapixels.','image_dimensions');
    $types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if(!isset($types[$mime])||$info['mime']!==$mime)
        throw new AzHttpError(400,'Upload a JPEG, PNG or WebP image.','image_type');
    return ['mime'=>$mime,'ext'=>$types[$mime],'width'=>$width,'height'=>$height];
}
function az_shop_images_dir():string
{
    az_prepare_storage();$dir=AZ_STORAGE.'/product-images';
    if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Product image storage unavailable.');
    $deny="<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";
    if(!is_file($dir.'/.htaccess'))file_put_contents($dir.'/.htaccess',$deny,LOCK_EX);
    return $dir;
}
function az_shop_upload_image(array $user,string $productId,string $alt,array $file):array
{
    az_shop_admin($user);$p=az_commerce_row($productId);
    $alt=trim($alt);if($alt===''||mb_strlen($alt)>180)throw new AzHttpError(400,'Add descriptive alt text.','invalid_alt');
    $check=az_shop_image_input_check($file);$db=az_db();
    $q=$db->prepare('SELECT COUNT(*) FROM commerce_product_images WHERE product_id=?');$q->execute([$productId]);
    if((int)$q->fetchColumn()>=6)throw new AzHttpError(409,'Maximum six product images.','image_limit');
    $id=az_id();$name=$id.'.'.$check['ext'];$dest=az_shop_images_dir().'/'.$name;
    if(!move_uploaded_file($file['tmp_name'],$dest))throw new RuntimeException('Could not store product image.');
    chmod($dest,0600);
    try{
        $q=$db->prepare('SELECT COALESCE(MAX(sort_order),-1)+1 FROM commerce_product_images WHERE product_id=?');$q->execute([$productId]);
        $pos=(int)$q->fetchColumn();
        $db->prepare('INSERT INTO commerce_product_images(id,product_id,storage_name,mime_type,alt_text,sort_order,created_at) VALUES(?,?,?,?,?,?,?)')
          ->execute([$id,$productId,$name,$check['mime'],$alt,$pos,az_now()]);
    }catch(Throwable $e){@unlink($dest);throw $e;}
    return ['id'=>$id,'src'=>'shop-media.php?id='.$id,'alt'=>$alt];
}
function az_shop_image_action(array $user,array $body):array
{
    az_shop_admin($user);$id=(string)($body['id']??'');$action=(string)($body['action']??'');
    if(!az_valid_id($id)||!in_array($action,['cover','delete','alt'],true))
        throw new AzHttpError(400,'Invalid image action.','invalid_image');
    $db=az_db();$q=$db->prepare('SELECT * FROM commerce_product_images WHERE id=?');$q->execute([$id]);
    $image=$q->fetch(PDO::FETCH_ASSOC);if(!$image)throw new AzHttpError(404,'Image not found.','image_missing');
    if($action==='alt'){
        $alt=trim((string)($body['alt']??''));
        if($alt===''||mb_strlen($alt)>180)throw new AzHttpError(400,'Invalid alt text.','invalid_alt');
        $db->prepare('UPDATE commerce_product_images SET alt_text=? WHERE id=?')->execute([$alt,$id]);
    }elseif($action==='cover'){
        $db->beginTransaction();
        try{
            $q=$db->prepare('SELECT id FROM commerce_product_images WHERE product_id=? ORDER BY sort_order,id');
            $q->execute([$image['product_id']]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
            $ids=array_values(array_filter($ids,static fn($x)=>$x!==$id));array_unshift($ids,$id);
            $q=$db->prepare('UPDATE commerce_product_images SET sort_order=? WHERE id=?');
            foreach($ids as $i=>$v)$q->execute([$i,$v]);$db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }else{
        $db->prepare('DELETE FROM commerce_product_images WHERE id=?')->execute([$id]);
        $file=az_shop_images_dir().'/'.$image['storage_name'];if(is_file($file))@unlink($file);
    }
    return ['id'=>$id,'action'=>$action,'saved'=>true];
}
function az_shop_image_access(string $id,?array $actor=null):array
{
    if(!az_valid_id($id))throw new AzHttpError(404,'Image not found.','image_missing');
    $db=az_db();$q=$db->prepare('SELECT i.*,p.state,p.checkout_mode,p.checkout_url,p.price_cents,p.currency FROM commerce_product_images i JOIN commerce_products p ON p.id=i.product_id WHERE i.id=?');
    $q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new AzHttpError(404,'Image not found.','image_missing');
    $admin=is_array($actor)&&($actor['status']??'')==='active'&&az_has_role($actor,'admin');
    if(!$admin && !az_commerce_active(array_replace($row,['id'=>$row['product_id']])))throw new AzHttpError(404,'Image not found.','image_missing');
    if(!preg_match('/\A[a-f0-9]{32}\.(jpg|png|webp)\z/D',$row['storage_name']))
        throw new AzHttpError(404,'Image not found.','image_missing');
    return $row;
}
