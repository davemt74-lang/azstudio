<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/universal-header.php';
require_once __DIR__.'/includes/member-layout.php';
$user=az_require_admin();az_shop_admin($user);
header('Cache-Control: private, no-store');header('X-Robots-Tag: noindex,nofollow');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Shop Homepage Editor · AZHottie</title><link rel="icon" href="assets/favicon.svg">
<link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"><link rel="stylesheet" href="assets/member-layout.css">
<link rel="stylesheet" href="assets/shop-homepage-editor.css?v=1.57.0"></head>
<body data-page="shop-homepage-editor"><?php az_render_universal_header('shop-homepage-editor',$user);az_render_member_layout_open('shop-homepage-editor',$user); ?>
<main class="azh-shop-edit" id="azh-shop-editor" data-csrf="<?= az_h(az_csrf_token()) ?>">
<header class="azh-shop-edit-header"><div><p class="azh-shop-edit-kicker">COMMERCE / STORE DESIGN</p><h1>Shop Homepage Editor</h1><p>Design your storefront, preview a private draft, and publish when ready. Hiding a section or collection never deletes its content.</p></div><a href="shop.php" target="_blank" rel="noopener">View published shop ↗</a></header>
<div class="azh-shop-edit-toolbar"><span id="azh-shop-editor-status" role="status" aria-live="polite">Loading shop settings…</span>
<div class="azh-shop-edit-actions"><button type="button" data-action="reset">Discard draft changes</button><button type="button" data-action="save">Save draft</button><button type="button" data-action="preview">Preview draft</button><button type="button" class="azh-shop-edit-publish" data-action="publish">Publish homepage</button></div></div>
<div class="azh-shop-edit-layout">
<form id="azh-shop-editor-form" class="azh-shop-edit-controls" autocomplete="off">
<details open><summary>Hero text &amp; buttons</summary><div class="azh-shop-edit-fields">
<label>Eyebrow<input name="eyebrow" maxlength="80"></label><label>Main heading<input name="heading" maxlength="100" required></label>
<label>Accent headline<input name="accent" maxlength="90"></label><label>Supporting description<textarea name="description" rows="3" maxlength="320"></textarea></label>
<label>Button text<input name="ctaText" maxlength="55"></label><label>Button destination<select name="ctaHref"><option value="#shop-products">Featured products</option><option value="#shop-categories">Shop by Collection</option><option value="#shop-browse">All products</option><option value="watch.php">Watch creators</option><option value="creator-clubs.php">Creator Clubs</option></select></label>
</div></details>
<details open><summary>Hero &amp; background images</summary><div class="azh-shop-edit-fields">
<label>Hero image<select name="heroImage" id="azh-edit-hero-image"></select></label>
<label>Page background image<select name="backgroundImage" id="azh-edit-background-image"></select></label>
<label>Image position<select name="heroPosition"><option value="center">Center</option><option value="left">Left</option><option value="right">Right</option></select></label>
<label>Text alignment<select name="heroAlignment"><option value="left">Left</option><option value="center">Centered</option></select></label>
<div class="azh-shop-edit-image-samples"><img src="assets/shop-editorial-hero-v157.webp" alt="Default AZHottie editorial hero with two models and boutique lifestyle products"></div>
<div class="azh-shop-edit-upload"><strong>Upload a custom hero or background</strong><p>JPEG, PNG or WebP · maximum 5 MB · minimum 600 × 280 pixels. Uploaded images stay in protected storage.</p>
<label>Use for<select name="uploadTarget" id="azh-edit-upload-target"><option value="heroImage">Hero image</option><option value="backgroundImage">Background image</option></select></label>
<label>Image file<input type="file" name="imageFile" id="azh-edit-upload-file" accept="image/jpeg,image/png,image/webp"></label>
<label>Image description<input name="uploadAlt" maxlength="180" placeholder="Describe the uploaded image"></label>
<button type="button" id="azh-edit-upload">Upload &amp; select</button><p id="azh-edit-upload-status" role="status"></p><div id="azh-edit-upload-list"></div></div>
</div></details>
<details><summary>Colors, typography &amp; layout</summary><div class="azh-shop-edit-fields azh-shop-edit-grid">
<label>Text color<input type="color" name="textColor"></label><label>Accent color<input type="color" name="accentColor"></label>
<label>Button color<input type="color" name="buttonColor"></label><label>Page background color<input type="color" name="backgroundColor"></label>
<label>Headline font<select name="fontStyle"><option value="modern">Modern bold</option><option value="editorial">Editorial serif</option><option value="classic">Classic serif</option></select></label>
<label>Hero height <output id="azh-hero-height-value"></output><input type="range" name="heroHeight" min="300" max="700" step="10"></label>
<label>Headline size <output id="azh-headline-size-value"></output><input type="range" name="headingSize" min="35" max="90" step="1"></label>
<label>Text background opacity <output id="azh-overlay-value"></output><input type="range" name="overlayStrength" min="0" max="80" step="5"></label></div></details>
<details open><summary>Homepage sections — show, hide &amp; reorder</summary><div class="azh-shop-edit-fields"><p>Changes are reversible. Use the arrows to change section order; disabled sections keep their saved settings.</p>
<label>Category heading<input name="categoriesTitle" maxlength="90"></label><label>Featured products heading<input name="featuredTitle" maxlength="90"></label><label>Browse products heading<input name="catalogTitle" maxlength="90"></label>
<div id="azh-edit-section-list" class="azh-shop-edit-choice-list"></div></div></details>
<details open><summary>Website product categories — show / hide</summary><div class="azh-shop-edit-fields"><p>Disabled categories and their products disappear from shop listings and product-detail links, without deleting saved products or stock.</p><div id="azh-edit-category-list" class="azh-shop-edit-choice-list"></div></div></details>
<details><summary>Store visibility &amp; sample catalog</summary><div class="azh-shop-edit-fields"><label class="azh-shop-edit-switch"><input type="checkbox" name="storeEnabled"> Enable public ecommerce storefront</label>
<label class="azh-shop-edit-switch"><input type="checkbox" name="sampleEnabled"> Display illustrative sample products</label>
<p>Samples are marked DEMO and have no checkout, payment, order, or inventory actions. To change the live setting immediately, use the quick controls in Commerce → Products.</p>
</div></details>
</form>
<section class="azh-shop-edit-preview" aria-label="Homepage preview"><div class="azh-shop-preview-header"><div><strong>Private draft preview</strong><small>Only administrators can open this preview. Changes are not public until published.</small></div><div><button type="button" id="azh-edit-desktop" aria-pressed="true">Desktop</button><button type="button" id="azh-edit-phone" aria-pressed="false">Mobile</button></div></div>
<div class="azh-shop-preview-frame-wrap" id="azh-preview-wrap"><iframe id="azh-shop-preview" src="shop.php?preview=1" title="Private AZHottie shop homepage draft" loading="lazy"></iframe></div>
<p class="azh-shop-preview-foot">Text and color edits update as you type. Save Draft and Preview to refresh all images, section visibility, product categories and sample data.</p>
</section></div></main>
<?php az_render_member_layout_close(false); ?><script src="assets/shop-homepage-editor.js?v=1.57.0" defer></script></body></html>
