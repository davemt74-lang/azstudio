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
<link rel="stylesheet" href="assets/shop-homepage-editor.css?v=1.58.0"></head>
<body data-page="shop-homepage-editor"><?php az_render_universal_header('shop-homepage-editor',$user);az_render_member_layout_open('shop-homepage-editor',$user); ?>
<main class="azh-shop-edit" id="azh-shop-editor" data-csrf="<?= az_h(az_csrf_token()) ?>">
<header class="azh-shop-edit-header"><div><p class="azh-shop-edit-kicker">COMMERCE / STORE DESIGN</p><h1>Shop Homepage Editor</h1><p>Shape your storefront and see every change in the private preview. Publish when you are ready.</p></div><a href="shop.php" target="_blank" rel="noopener">Open published shop</a></header>
<div class="azh-shop-edit-toolbar"><div class="azh-shop-edit-state"><span id="azh-shop-editor-status" role="status" aria-live="polite" data-state="loading">Loading shop settings…</span><small id="azh-shop-published-status"></small></div>
<div class="azh-shop-edit-actions"><button type="button" data-action="reload" hidden>Reload saved version</button><button type="button" data-action="reset" disabled>Discard draft changes</button><button type="button" data-action="save" disabled>Save draft</button><button type="button" data-action="preview" disabled>Preview draft</button><button type="button" class="azh-shop-edit-publish" data-action="publish" disabled>Publish homepage</button></div></div>
<div class="azh-shop-edit-layout">
<form id="azh-shop-editor-form" class="azh-shop-edit-controls" autocomplete="off"><fieldset id="azh-shop-editor-fields" disabled>
<details open><summary>Hero text &amp; buttons</summary><div class="azh-shop-edit-fields">
<label>Eyebrow<input name="eyebrow" maxlength="80"></label><label>Main heading<input name="heading" maxlength="100" required></label>
<label>Accent headline<input name="accent" maxlength="90"></label><label>Supporting description<textarea name="description" rows="3" maxlength="320"></textarea></label>
<label>Button text<input name="ctaText" maxlength="55"></label><label>Button destination<select name="ctaHref"><option value="#shop-products">Featured products</option><option value="#shop-categories">Shop by Collection</option><option value="#shop-browse">All products</option><option value="watch.php">Watch creators</option><option value="creator-clubs.php">Creator Clubs</option></select></label>
</div></details>
<details open><summary>Desktop hero</summary><div class="azh-shop-edit-fields">
<label>Hero image<select name="heroImage" id="azh-edit-hero-image"></select></label>
<figure class="azh-selected-image" data-image-frame="heroImage"><img data-image-preview="heroImage" alt="Selected desktop hero" hidden><span data-image-empty="heroImage">No image selected</span><figcaption data-image-caption="heroImage"></figcaption></figure>
<div class="azh-image-actions"><button type="button" data-image-upload="heroImage">Upload image</button><button type="button" data-image-remove="heroImage">Remove image</button></div>
<div class="azh-shop-edit-grid">
<label>Horizontal crop <output data-value-for="heroFocalX"></output><input type="range" name="heroFocalX" min="0" max="100" value="50"></label>
<label>Vertical crop <output data-value-for="heroFocalY"></output><input type="range" name="heroFocalY" min="0" max="100" value="50"></label>
<label>Text alignment<select name="heroAlignment"><option value="left">Left</option><option value="center">Centered</option><option value="right">Right</option></select></label>
<label>Text placement<select name="heroTextPosition"><option value="top">Top</option><option value="center" selected>Middle</option><option value="bottom">Bottom</option></select></label>
<label>Banner height <output id="azh-hero-height-value" data-value-for="heroHeight" data-unit="px"></output><input type="range" name="heroHeight" min="300" max="700" step="1" value="440"></label>
<label>Headline size <output id="azh-headline-size-value" data-value-for="headingSize" data-unit="px"></output><input type="range" name="headingSize" min="35" max="90" value="65"></label>
<label class="azh-edit-span">Text backdrop strength <output id="azh-overlay-value" data-value-for="overlayStrength"></output><input type="range" name="overlayStrength" min="0" max="80" step="1" value="5"></label>
</div><input type="hidden" name="heroPosition" value="center">
</div></details>
<details id="azh-mobile-hero-controls"><summary>Phone hero</summary><div class="azh-shop-edit-fields">
<p>Adjust the image and text for screens up to 640 pixels wide.</p>
<label>Phone image<select name="mobileHeroImage" id="azh-edit-mobile-hero-image"></select></label>
<figure class="azh-selected-image" data-image-frame="mobileHeroImage"><img data-image-preview="mobileHeroImage" alt="Selected phone hero" hidden><span data-image-empty="mobileHeroImage">No image selected</span><figcaption data-image-caption="mobileHeroImage"></figcaption></figure>
<div class="azh-image-actions"><button type="button" data-image-upload="mobileHeroImage">Upload image</button><button type="button" data-image-remove="mobileHeroImage">Remove image</button></div>
<div class="azh-shop-edit-grid">
<label>Horizontal crop <output data-value-for="mobileHeroFocalX"></output><input type="range" name="mobileHeroFocalX" min="0" max="100" value="65"></label>
<label>Vertical crop <output data-value-for="mobileHeroFocalY"></output><input type="range" name="mobileHeroFocalY" min="0" max="100" value="50"></label>
<label>Text alignment<select name="mobileHeroAlignment"><option value="left">Left</option><option value="center">Centered</option><option value="right">Right</option></select></label>
<label>Text placement<select name="mobileHeroTextPosition"><option value="top">Top</option><option value="center">Middle</option><option value="bottom">Bottom</option></select></label>
<label>Banner height <output data-value-for="mobileHeroHeight" data-unit="px"></output><input type="range" name="mobileHeroHeight" min="300" max="800" step="1" value="510"></label>
<label>Headline size <output data-value-for="mobileHeadingSize" data-unit="px"></output><input type="range" name="mobileHeadingSize" min="28" max="72" value="44"></label>
<label class="azh-edit-span">Text backdrop strength <output data-value-for="mobileOverlayStrength"></output><input type="range" name="mobileOverlayStrength" min="0" max="80" step="1" value="30"></label>
</div></div></details>
<details><summary>Colors, typography &amp; page background</summary><div class="azh-shop-edit-fields">
<div class="azh-shop-edit-grid"><label>Text color<input type="color" name="textColor"></label><label>Accent color<input type="color" name="accentColor"></label>
<label>Button color<input type="color" name="buttonColor"></label><label>Page background color<input type="color" name="backgroundColor"></label></div>
<label>Headline font<select name="fontStyle"><option value="modern">Modern bold</option><option value="editorial">Editorial serif</option><option value="classic">Classic serif</option></select></label>
<label>Page background image<select name="backgroundImage" id="azh-edit-background-image"></select></label>
<figure class="azh-selected-image" data-image-frame="backgroundImage"><img data-image-preview="backgroundImage" alt="Selected page background" hidden><span data-image-empty="backgroundImage">No image selected</span><figcaption data-image-caption="backgroundImage"></figcaption></figure>
<div class="azh-image-actions"><button type="button" data-image-upload="backgroundImage">Upload image</button><button type="button" data-image-remove="backgroundImage">Remove image</button></div>
</div></details>
<details open><summary>Homepage sections — show, hide &amp; reorder</summary><div class="azh-shop-edit-fields">
<label>Category heading<input name="categoriesTitle" maxlength="90"></label><label>Featured products heading<input name="featuredTitle" maxlength="90"></label><label>Browse products heading<input name="catalogTitle" maxlength="90"></label>
<div id="azh-edit-section-list" class="azh-shop-edit-choice-list"></div>
</div></details>
<details id="azh-category-controls"><summary>Category tiles — images, titles &amp; order</summary><div class="azh-shop-edit-fields"><p>Hidden categories keep their products and settings. Change their display names without changing product categories.</p>
<label class="azh-shop-edit-switch"><input type="checkbox" name="showEmptyCategories"> Show categories with no available products</label><div id="azh-edit-category-list" class="azh-shop-edit-choice-list"></div>
</div></details>
<details id="azh-featured-controls"><summary>Featured products</summary><div class="azh-shop-edit-fields">
<div class="azh-shop-edit-grid"><label>Choose products<select name="featuredMode"><option value="automatic">Automatic picks</option><option value="manual">Choose &amp; order myself</option></select></label><label>Products to show<select name="featuredCount"><option>1</option><option>2</option><option>3</option><option>4</option><option>5</option><option>6</option><option>7</option><option selected>8</option><option>9</option><option>10</option><option>11</option><option>12</option></select></label></div>
<p id="azh-featured-automatic-note">Automatic picks use your catalog's featured products first.</p>
<div id="azh-featured-picker" hidden><p id="azh-featured-selection-status" role="status"></p><p>Unavailable products, hidden categories and disabled samples are excluded.</p><ol id="azh-featured-selected" class="azh-featured-selection"></ol>
<label>Find products<input type="search" id="azh-featured-search" placeholder="Search product names or categories" autocomplete="off"></label><div id="azh-featured-results" class="azh-featured-results"></div><button type="button" id="azh-featured-more" hidden>Show more products</button></div>
</div></details>
<details id="azh-image-library-controls"><summary>Image library &amp; uploads</summary><div class="azh-shop-edit-fields">
<p>JPEG, PNG or WebP · up to 5 MB · at least 600 × 280 pixels.</p>
<label>Use image for<select name="uploadTarget" id="azh-edit-upload-target"><option value="heroImage">Desktop hero</option><option value="mobileHeroImage">Phone hero</option><option value="backgroundImage">Page background</option></select></label>
<label>Image file<input type="file" name="imageFile" id="azh-edit-upload-file" accept="image/jpeg,image/png,image/webp"></label>
<label>Image description<input name="uploadAlt" maxlength="180" placeholder="Describe the image"></label>
<button type="button" id="azh-edit-upload">Upload &amp; select</button><p id="azh-edit-upload-status" role="status" aria-live="polite"></p><div id="azh-edit-upload-list"></div>
</div></details>
<details id="azh-store-controls"><summary>Store visibility &amp; sample catalog</summary><div class="azh-shop-edit-fields"><label class="azh-shop-edit-switch"><input type="checkbox" name="storeEnabled"> Enable public ecommerce storefront</label>
<label class="azh-shop-edit-switch"><input type="checkbox" name="sampleEnabled"> Display illustrative sample products</label>
<p>Samples are labeled and cannot be purchased. These changes go live when you publish.</p>
</div></details>
</fieldset></form>
<div id="azh-preview-home"><section class="azh-shop-edit-preview" id="azh-shop-preview-panel" aria-label="Homepage preview"><div class="azh-shop-preview-header"><div><strong>Private live preview</strong><small id="azh-shop-preview-status" role="status" aria-live="polite" data-state="loading">Loading preview…</small></div><button type="button" id="azh-edit-fullscreen">Full-screen preview</button></div>
<div class="azh-shop-preview-devices" role="group" aria-label="Preview screen size"><button type="button" id="azh-edit-desktop" data-preview-width="1440" aria-pressed="true">Desktop</button><button type="button" id="azh-edit-tablet" data-preview-width="768" aria-pressed="false">Tablet</button><button type="button" id="azh-edit-phone" data-preview-width="390" aria-pressed="false">Phone</button><span id="azh-preview-viewport-label">1440 px</span></div>
<div class="azh-shop-preview-frame-wrap" id="azh-preview-wrap"><div id="azh-preview-stage"><iframe id="azh-shop-preview" src="shop.php?preview=1" title="Private AZHottie shop homepage draft" scrolling="no"></iframe></div></div>
<p class="azh-shop-preview-foot">Includes unsaved changes. Save draft keeps your work private; Publish homepage updates the public shop.</p>
</section></div></div>
<dialog id="azh-shop-preview-dialog" aria-labelledby="azh-fullscreen-title"><div class="azh-preview-dialog-header"><h2 id="azh-fullscreen-title">Homepage preview</h2><button type="button" id="azh-edit-exit-fullscreen">Close preview</button></div><div id="azh-preview-dialog-content"></div></dialog>
</main>
<?php az_render_member_layout_close(false); ?><script src="assets/shop-homepage-editor.js?v=1.58.0" defer></script></body></html>
