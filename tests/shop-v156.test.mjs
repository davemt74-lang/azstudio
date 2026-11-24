import test from 'node:test';import assert from 'node:assert/strict';import {readFileSync} from 'node:fs';
const read=p=>readFileSync(new URL('../'+p,import.meta.url),'utf8');
test('storefront keeps prior checkout and stock controls; no invented cart or instant-pay mutations',()=>{
 const src=read('shop.php'),detail=read('shop-product.php');
 for(const v of [src,detail]){
  assert.match(v,/data-stripe-checkout/);assert.match(v,/data-local-order/);
  assert.match(v,/data-channel="shop"/);assert.match(v,/fulfillment/);
  assert.match(v,/checkoutMode/);assert.match(v,/stripeReady/);
 }
 assert.match(src,/az_shop_public_products/);assert.match(src,/data-shop-product/);assert.match(src,/data-shop-filter/);
 assert.match(detail,/az_valid_id/);assert.match(detail,/shop-media.php|images/);
});
test('public product and image boundaries are server enforced, not just hidden in JS',()=>{
 const php=read('includes/shop-catalog.php');
 assert.match(php,/az_commerce_public_products\(\)/);
 assert.match(php,/a\.state='approved'/);assert.match(php,/v\.rights_confirmed=1/);
 assert.match(php,/a\.video_revision=v\.revision/);
 assert.match(php,/az_shop_image_access/);assert.match(php,/az_commerce_active/);
 assert.match(php,/p\.price_cents,p\.currency FROM commerce_product_images/);
 assert.match(php,/array_replace\(\$row,\['id'=>\$row\['product_id'\]\]\)/);
 assert.match(php,/is_uploaded_file/);assert.match(php,/getimagesize/);assert.match(php,/image\/webp/);
 assert.match(php,/24000000/);assert.match(php,/Require all denied/);
 const media=read('shop-media.php');assert.match(media,/az_shop_image_access/);
 assert.match(media,/X-Content-Type-Options/);assert.match(media,/Cache-Control: private, no-store/);
 assert.doesNotMatch(media,/HTTP_RANGE|Accept-Ranges/);
});
test('admin changes are role scoped, CSRF checked, validated and isolated from financial workflow',()=>{
 const api=read('shop-catalog-api.php'),php=read('includes/shop-catalog.php');
 assert.match(api,/az_require_admin\(true\)/);assert.match(api,/az_require_csrf/);
 assert.match(php,/az_shop_admin\(\$user\)/);
 assert.match(php,/revision_conflict/);assert.match(php,/invalid_bundle/);
 assert.doesNotMatch(php,/az_commerce_order_record|stripe.*charges|transferCreate|az_stripe_checkout/);
 assert.match(read('includes/database.php'),/az_upgrade_shop_catalog_schema/);
 assert.match(read('includes/bootstrap.php'),/shop-catalog\.php/);
});
test('search/gallery controls and admin tools are wired and accessible',()=>{
 const shop=read('assets/shop-v156.js'),merch=read('assets/shop-merch-v156.js');
 assert.match(shop,/aria-pressed/);assert.match(shop,/data-shop-filter/);assert.match(shop,/data-gallery-src/);
 assert.match(merch,/FormData/);assert.match(merch,/X-CSRF-Token/);
 assert.match(merch,/save storefront settings|Save storefront settings/i.test(read('commerce.php'))? /api\('save'/ : /FormData/);
 assert.match(read('commerce.php'),/id="azh-shop-merch"/);
 assert.match(read('shop.php'),/azh-shop-no-matches/);
 const version=JSON.parse(read('package.json')).version;
 assert.equal(typeof version,'string');
 assert.ok(read('live.php').includes('live-viewer.js?v='+version));
 assert.ok(read('live-director.php').includes('live-director.js?v='+version));
});
