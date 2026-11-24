import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync,existsSync} from 'node:fs';
const read=file=>readFileSync(new URL('../'+file,import.meta.url),'utf8');
test('Shop2.1 uses approved photographic hero and separate public/admin pages',()=>{
 const page=read('shop.php');const admin=read('shop-homepage-editor.php');
 assert.match(page,/az_shop_homepage_state\(\)/);
 assert.match(page,/shop-editorial-hero-v157|az_shop_homepage_image_src/);
 assert.match(page,/data-shop-preview/);
 assert.match(admin,/Save draft/);assert.match(admin,/Publish homepage/);assert.match(admin,/Preview draft/);
 assert.ok(existsSync(new URL('../assets/shop-editorial-hero-v157.webp',import.meta.url)));
});
test('Admin editor controls all sections and categories with save/publish/CSRF',()=>{
 const php=read('includes/shop-homepage.php'),api=read('shop-homepage-api.php');
 assert.match(php,/shop_homepage_settings/);assert.match(php,/published_json/);assert.match(php,/draft_json/);
 assert.match(php,/revision=revision\+1/);assert.match(php,/image_valid/);assert.match(php,/categories/);
 assert.match(api,/az_require_admin\(true\)/);assert.match(api,/az_require_csrf\(\)/);
 assert.match(api,/az_shop_homepage_save/);assert.match(api,/az_shop_homepage_toggle/);
 const ui=read('assets/shop-homepage-editor.js');assert.match(ui,/sectionEnable/);assert.match(ui,/categoryEnable/);
 assert.match(ui,/beforeunload/);assert.match(ui,/publish/);
});
test('Sample catalog never seeds orders or uses checkout; published state controls visibility',()=>{
 const php=read('shop.php');const helper=read('includes/shop-homepage.php');
 assert.match(php,/!empty\(\$p\['demo'\]\)/);
 assert.match(php,/Demo only · Checkout unavailable/);assert.match(php,/\$layout\['storeEnabled'\]/);
 assert.match(php,/\$layout\['categories'\]/);assert.match(helper,/sampleEnabled'=>false/);
 assert.doesNotMatch(helper,/INSERT INTO commerce_orders|INSERT INTO commerce_products|UPDATE commerce_products/);
 assert.match(read('shop-product.php'),/az_shop_homepage_public/);
});
test('Uploaded home images are guarded and do not accept arbitrary URLs',()=>{
 const helper=read('includes/shop-homepage.php'),media=read('shop-homepage-media.php');
 assert.match(helper,/getimagesize/);assert.match(helper,/is_uploaded_file/);
 assert.match(helper,/5\*1024\*1024/);assert.match(helper,/shop-homepage-media.php\?id=/);
 assert.match(helper,/in_array\(\$id,\[\$live\['heroImage'\]/);
 assert.match(media,/az_shop_homepage_image_access/);
 assert.match(media,/Content-Security-Policy/);
 assert.doesNotMatch(helper,/file_get_contents\(\$body\['heroImage'\]/);
});
test('Existing checkout, camera and commerce stays integrated',()=>{
 const store=read('shop.php'),director=read('live-director.php'),fan=read('live.php');
 assert.match(store,/data-stripe-checkout/);assert.match(store,/data-local-order/);
 assert.match(store,/data-channel="shop"/);
 const v=JSON.parse(read('package.json')).version;
 assert.ok(director.includes('live-director.js?v='+v));assert.ok(fan.includes('live-viewer.js?v='+v));
});
