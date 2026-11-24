/** Shop 2.2: one private preview, explicit publish, and revision-protected changes. */
(() => {
  'use strict';
  const editor = document.getElementById('azh-shop-editor');
  if (!editor) return;
  const $ = id => document.getElementById(id);
  const form = $('azh-shop-editor-form');
  const fieldset = $('azh-shop-editor-fields');
  const frame = $('azh-shop-preview');
  const stage = $('azh-preview-stage');
  const panel = $('azh-shop-preview-panel');
  const dialog = $('azh-shop-preview-dialog');
  const csrf = editor.dataset.csrf;
  const strings = ['eyebrow','heading','accent','description','ctaText','ctaHref','categoriesTitle',
    'featuredTitle','catalogTitle','heroImage','backgroundImage','heroPosition','heroAlignment',
    'heroTextPosition','fontStyle','textColor','accentColor','buttonColor','backgroundColor',
    'mobileHeroImage','mobileHeroAlignment','mobileHeroTextPosition','featuredMode'];
  const numbers = ['heroHeight','headingSize','overlayStrength','heroFocalX','heroFocalY',
    'mobileHeroFocalX','mobileHeroFocalY','mobileHeroHeight','mobileHeadingSize','mobileOverlayStrength','featuredCount'];
  const flags = ['storeEnabled','sampleEnabled','showEmptyCategories'];
  const additions = {heroFocalX:50,heroFocalY:50,heroTextPosition:'center',mobileHeroImage:'inherit',
    mobileHeroFocalX:65,mobileHeroFocalY:50,mobileHeroHeight:510,mobileHeadingSize:44,
    mobileHeroAlignment:'left',mobileHeroTextPosition:'top',mobileOverlayStrength:30,
    featuredMode:'automatic',featuredCount:8,showEmptyCategories:true};
  const initialValues = Object.fromEntries([...strings,...numbers].map(name => [name, form.elements.namedItem(name)?.value ?? '']));
  let state = null, sectionLabels = {}, categoryLabels = {}, order = [], categoryOrder = [], featuredIds = [];
  let dirty = false, busy = false, resultsLimit = 12;
  let previewTimer = 0, previewController = null, previewSequence = 0, lastHtml = '', lastHtmlSequence = 0;
  let frameReady = false, frameHeight = 900, previewWidth = 1440, bodyOverflow = '';
  const clone = value => JSON.parse(JSON.stringify(value));
  const stable = value => JSON.stringify(value, (_key, item) => item && typeof item === 'object' && !Array.isArray(item)
    ? Object.fromEntries(Object.keys(item).sort().map(key => [key, item[key]])) : item);
  const status = (text, kind = 'draft') => {
    const label = $('azh-shop-editor-status');
    label.textContent = text; label.dataset.state = kind; label.dataset.error = kind === 'error' ? 'true' : 'false';
  };
  const previewStatus = (text, kind = 'ready') => {
    const label = $('azh-shop-preview-status'); label.textContent = text; label.dataset.state = kind;
  };
  const element = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };
  const button = (text, label) => {
    const node = element('button', '', text); node.type = 'button';
    if (label) node.setAttribute('aria-label', label);
    return node;
  };
  async function api(action, body = null, signal) {
    const uri = new URL('shop-homepage-api.php', document.baseURI); uri.searchParams.set('action', action);
    const upload = body instanceof FormData;
    const headers = body ? {'X-CSRF-Token':csrf} : {};
    if (body && !upload) headers['Content-Type'] = 'application/json';
    const response = await fetch(uri, {credentials:'same-origin',cache:'no-store',method:body ? 'POST' : 'GET',
      headers,signal,...(body ? {body:upload ? body : JSON.stringify(body)} : {})});
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) {
      const error = new Error(data?.error?.message || 'The shop request could not be completed. Please try again.');
      error.code = data?.error?.code; error.status = response.status; throw error;
    }
    return data.data;
  }
  function normalizeDesign(input = {}) {
    const design = {...additions,...input};
    for (const name of strings) if (design[name] === undefined) design[name] = initialValues[name];
    for (const name of numbers) if (design[name] === undefined) design[name] = Number(initialValues[name]);
    design.sections = {...Object.fromEntries(Object.keys(sectionLabels).map(key => [key,true])),...input.sections};
    design.categories = {...Object.fromEntries(Object.keys(categoryLabels).map(key => [key,true])),...input.categories};
    design.order = Array.isArray(input.order) ? [...input.order] : Object.keys(sectionLabels);
    design.categoryOrder = Array.isArray(input.categoryOrder) ? [...input.categoryOrder] : Object.keys(categoryLabels);
    design.categoryCards = Object.fromEntries(Object.entries(categoryLabels).map(([slug,title]) => [slug,
      {title,image:'default',...input.categoryCards?.[slug]}]));
    design.featuredProductIds = Array.isArray(input.featuredProductIds) ? [...input.featuredProductIds] : [];
    return design;
  }
  function acceptState(data) {
    sectionLabels = data.sections || {}; categoryLabels = data.categories || {};
    state = {...data,images:data.images || [],products:data.products || []};
    state.draft = normalizeDesign(data.draft); state.published = normalizeDesign(data.published);
    let published = 'Published revision ' + state.publishedRevision;
    const date = new Date(state.publishedAt);
    if (state.publishedAt && !Number.isNaN(date.getTime())) published += ' · ' + new Intl.DateTimeFormat(undefined,
      {month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}).format(date);
    $('azh-shop-published-status').textContent = published;
  }
  function fields() {
    const draft = clone(state.draft);
    for (const name of strings) draft[name] = form.elements.namedItem(name).value;
    for (const name of numbers) draft[name] = Number(form.elements.namedItem(name).value);
    for (const name of flags) draft[name] = form.elements.namedItem(name).checked;
    draft.order = [...order]; draft.categoryOrder = [...categoryOrder]; draft.featuredProductIds = [...featuredIds];
    for (const slug of Object.keys(sectionLabels)) {
      const input = form.querySelector(`[data-section-enable="${slug}"]`);
      if (input) draft.sections[slug] = input.checked;
    }
    for (const slug of Object.keys(categoryLabels)) {
      const enabled = form.querySelector(`[data-category-enable="${slug}"]`);
      const title = form.querySelector(`[data-category-title="${slug}"]`);
      const image = form.querySelector(`[data-category-image="${slug}"]`);
      if (enabled) draft.categories[slug] = enabled.checked;
      if (title && image) draft.categoryCards[slug] = {title:title.value,image:image.value};
    }
    return draft;
  }
  function showSaveState(message) {
    if (!state) return;
    dirty = stable(fields()) !== stable(state.draft);
    const kind = dirty ? 'unsaved' : stable(state.draft) === stable(state.published) ? 'published' : 'draft';
    status(message || (dirty ? 'Unsaved changes · preview only' : kind === 'published'
      ? 'Saved and published · storefront is up to date' : 'Draft saved · not visible to the public'), kind);
  }
  function setBusy(value, message) {
    busy = value; fieldset.disabled = value || !state; editor.setAttribute('aria-busy', String(value));
    for (const control of editor.querySelectorAll('[data-action]')) control.disabled = value || !state;
    if (message) status(message, 'saving');
  }
  function handleError(error) {
    status(error.message, 'error');
    if (error.code === 'revision_conflict') editor.querySelector('[data-action="reload"]').hidden = false;
  }
  function safeImage(src) {
    if (!src) return '';
    try { const url = new URL(src, document.baseURI); return url.origin === new URL(document.baseURI).origin ? url.href : ''; }
    catch { return ''; }
  }
  function imageInfo(id, category) {
    if (!id || id === 'none') return {src:'',title:'No image selected'};
    if (id === 'default') return {src:category ? state.categoryImages?.[category] || '' : 'assets/shop-editorial-hero-v157.webp',
      title:category ? 'Default ' + categoryLabels[category] + ' image' : 'AZHottie editorial hero'};
    const image = state.images.find(item => item.id === id);
    return image ? {src:image.src,title:image.alt_text || 'Uploaded image'} : {src:'',title:'Image unavailable — choose another'};
  }
  function fillImageSelect(select, selected, {mobile = false,category = false} = {}) {
    select.replaceChildren();
    if (mobile) select.add(new Option('Use desktop hero image','inherit'));
    select.add(new Option(category ? 'Default category image' : 'AZHottie editorial hero','default'));
    select.add(new Option('No image','none'));
    for (const item of state.images) select.add(new Option(item.alt_text || 'Uploaded image',item.id));
    if (![...select.options].some(option => option.value === selected)) select.add(new Option('Unavailable image',selected));
    select.value = selected;
  }
  function updateImagePreviews() {
    if (!state) return;
    for (const name of ['heroImage','mobileHeroImage','backgroundImage']) {
      const value = form.elements.namedItem(name).value;
      const inherited = name === 'mobileHeroImage' && value === 'inherit';
      const info = imageInfo(inherited ? form.elements.namedItem('heroImage').value : value);
      const img = editor.querySelector(`[data-image-preview="${name}"]`), empty = editor.querySelector(`[data-image-empty="${name}"]`);
      const src = safeImage(info.src);
      if (src) { if (img.src !== src) img.src = src; img.alt = info.title; }
      else img.removeAttribute('src');
      img.hidden = !src; empty.hidden = Boolean(src); empty.textContent = info.title;
      editor.querySelector(`[data-image-caption="${name}"]`).textContent = inherited ? 'Using desktop hero image' : info.title;
      editor.querySelector(`[data-image-remove="${name}"]`).disabled = value === 'none';
    }
    for (const slug of categoryOrder) {
      const select = form.querySelector(`[data-category-image="${slug}"]`), image = form.querySelector(`[data-category-preview="${slug}"]`);
      if (!select || !image) continue;
      const info = imageInfo(select.value,slug), src = safeImage(info.src);
      if (src) { if (image.src !== src) image.src = src; image.alt = info.title; } else image.removeAttribute('src');
      image.hidden = !src;
      const note = image.nextElementSibling; note.hidden = Boolean(src); note.textContent = info.title;
    }
    updateImageDeleteButtons();
  }
  function referencedImages(design) {
    if (!design) return new Set();
    return new Set([design.heroImage,design.mobileHeroImage,design.backgroundImage,
      ...Object.values(design.categoryCards || {}).map(card => card.image)]);
  }
  function imageIsUsed(id) {
    return [fields(),state.draft,state.published].some(design => referencedImages(design).has(id));
  }
  function updateImageDeleteButtons() {
    for (const control of editor.querySelectorAll('[data-image-delete]')) {
      const used = imageIsUsed(control.dataset.imageDelete); control.disabled = used;
      control.textContent = used ? 'In use' : 'Delete unused';
      control.title = used ? 'Remove this image from the current, saved and published designs before deleting it.' : '';
    }
  }
  function renderImageLibrary(design) {
    for (const name of ['heroImage','mobileHeroImage','backgroundImage']) fillImageSelect(form.elements.namedItem(name),design[name],{mobile:name === 'mobileHeroImage'});
    const target = $('azh-edit-upload-target'), oldTarget = target.value;
    target.replaceChildren(new Option('Desktop hero','heroImage'),new Option('Phone hero','mobileHeroImage'),new Option('Page background','backgroundImage'));
    for (const [slug,title] of Object.entries(categoryLabels)) target.add(new Option(title + ' category tile','category:' + slug));
    if ([...target.options].some(option => option.value === oldTarget)) target.value = oldTarget;
    const gallery = $('azh-edit-upload-list'); gallery.replaceChildren();
    if (!state.images.length) gallery.append(element('p','azh-editor-empty','Your uploaded images will appear here.'));
    for (const item of state.images) {
      const cell = element('div','azh-edit-image-preview'), img = element('img');
      img.src = safeImage(item.src); img.alt = item.alt_text || 'Uploaded image'; img.loading = 'lazy';
      const label = element('span','',item.alt_text || 'Uploaded image');
      const remove = button('Delete unused','Delete ' + (item.alt_text || 'uploaded image')); remove.dataset.imageDelete = item.id;
      cell.append(img,label,remove); gallery.append(cell);
    }
  }
  function moveControls(kind, slug, title, index, count) {
    const group = element('div','azh-choice-controls');
    for (const [direction,text] of [['up','Up'],['down','Down']]) {
      const control = button(text,'Move ' + title + ' ' + direction);
      control.dataset[kind + (direction === 'up' ? 'Up' : 'Down')] = slug;
      control.disabled = direction === 'up' ? index === 0 : index === count - 1;
      group.append(control);
    }
    return group;
  }
  function renderSections(design) {
    const list = $('azh-edit-section-list'); list.replaceChildren();
    order.forEach((slug,index) => {
      const row = element('div','azh-shop-edit-choice'); row.dataset.slug = slug;
      const label = element('label'), input = element('input'); input.type = 'checkbox';
      input.dataset.sectionEnable = slug; input.checked = Boolean(design.sections[slug]);
      label.append(input,document.createTextNode(sectionLabels[slug]));
      row.append(label,moveControls('section',slug,sectionLabels[slug],index,order.length)); list.append(row);
    });
  }
  function renderCategories(design) {
    const list = $('azh-edit-category-list'); list.replaceChildren();
    categoryOrder.forEach((slug,index) => {
      const card = element('div','azh-category-edit-card'); card.dataset.categoryRow = slug;
      const head = element('div','azh-shop-edit-choice'), label = element('label'), enabled = element('input');
      enabled.type = 'checkbox'; enabled.dataset.categoryEnable = slug; enabled.checked = Boolean(design.categories[slug]);
      label.append(enabled,document.createTextNode(categoryLabels[slug]));
      head.append(label,moveControls('category',slug,categoryLabels[slug],index,categoryOrder.length));
      const titleLabel = element('label','','Display name'), title = element('input');
      title.dataset.categoryTitle = slug; title.value = design.categoryCards[slug].title; title.maxLength = 60; title.required = true; titleLabel.append(title);
      const imageLabel = element('label','','Tile image'), select = element('select'); select.dataset.categoryImage = slug;
      fillImageSelect(select,design.categoryCards[slug].image,{category:true}); imageLabel.append(select);
      const figure = element('div','azh-category-image-preview'), img = element('img'), empty = element('span','','No image selected');
      img.dataset.categoryPreview = slug; img.alt = ''; figure.append(img,empty);
      const actions = element('div','azh-image-actions'), upload = button('Upload image','Upload image for ' + categoryLabels[slug]); upload.dataset.imageUpload = 'category:' + slug;
      const remove = button('Remove image','Remove image for ' + categoryLabels[slug]); remove.dataset.categoryImageRemove = slug;
      actions.append(upload,remove); card.append(head,titleLabel,imageLabel,figure,actions); list.append(card);
    });
  }
  function productInfo(id) {
    return state.products.find(product => product.id === id) || {id,title:'Unavailable product',category:'',priceCents:0,unavailable:true};
  }
  function productAvailability(product, design) {
    if (product.unavailable) return 'Unavailable · remove or replace';
    if (product.demo && !design.sampleEnabled) return 'Sample · enable sample catalog to show';
    if (!design.categories[product.category]) return 'Category hidden';
    return product.demo ? 'Sample · display only' : categoryLabels[product.category] || product.category;
  }
  function productDescription(product, design) {
    const body = element('div','azh-featured-product-copy');
    body.append(element('strong','',product.title),element('small','',productAvailability(product,design)));
    if (!product.unavailable) body.append(element('span','azh-featured-price',new Intl.NumberFormat(undefined,{style:'currency',currency:'USD'}).format(product.priceCents / 100)));
    return body;
  }
  function productPhoto(product) {
    const src = safeImage(product.imageSrc);
    if (!src) return element('span','azh-featured-photo-placeholder','AZ');
    const image = element('img','azh-featured-product-image'); image.src = src; image.alt = ''; image.loading = 'lazy'; return image;
  }
  function renderFeatured() {
    if (!state) return;
    const design = fields(), manual = design.featuredMode === 'manual';
    $('azh-featured-picker').hidden = !manual; $('azh-featured-automatic-note').hidden = manual;
    const selected = $('azh-featured-selected'); selected.replaceChildren();
    featuredIds.forEach((id,index) => {
      const product = productInfo(id), row = element('li','azh-featured-selected-item'); row.dataset.featuredId = id;
      const details = element('div','azh-featured-product-main'); details.append(productPhoto(product),productDescription(product,design));
      const actions = element('div','azh-featured-product-actions');
      actions.append(moveControls('featured',id,product.title,index,featuredIds.length));
      const remove = button('Remove','Remove ' + product.title); remove.dataset.featuredRemove = id; actions.append(remove);
      row.append(details,actions); selected.append(row);
    });
    const eligible = featuredIds.filter(id => { const p = productInfo(id); return !p.unavailable && design.categories[p.category] && (!p.demo || design.sampleEnabled); }).length;
    $('azh-featured-selection-status').textContent = featuredIds.length ? featuredIds.length + ' selected · up to ' + Math.min(eligible,design.featuredCount) + ' shown in this order'
      : 'Choose products below, then arrange them in the order you want.';
    renderProductResults(design);
  }
  function renderProductResults(design = fields()) {
    const query = $('azh-featured-search').value.trim().toLocaleLowerCase();
    const products = state.products.filter(product => (product.title + ' ' + (categoryLabels[product.category] || product.category)).toLocaleLowerCase().includes(query));
    const list = $('azh-featured-results'); list.replaceChildren();
    for (const product of products.slice(0,resultsLimit)) {
      const row = element('div','azh-featured-product-option'); row.dataset.productOption = product.id;
      row.append(productPhoto(product),productDescription(product,design));
      const picked = featuredIds.includes(product.id), add = button(picked ? 'Selected' : 'Add', (picked ? 'Selected ' : 'Add ') + product.title);
      add.dataset.featuredAdd = product.id; add.disabled = picked || featuredIds.length >= 24; row.append(add); list.append(row);
    }
    if (!products.length) list.append(element('p','azh-editor-empty',state.products.length ? 'No products match that search.' : 'Add products in Commerce or enable the sample catalog.'));
    const more = $('azh-featured-more'); more.hidden = products.length <= resultsLimit;
    more.textContent = 'Show more products (' + Math.max(0,products.length - resultsLimit) + ' remaining)';
  }
  function updateRangeLabels() {
    for (const output of form.querySelectorAll('[data-value-for]')) output.textContent = form.elements.namedItem(output.dataset.valueFor).value + (output.dataset.unit || '%');
  }
  function render(design = state.draft) {
    order = [...design.order]; categoryOrder = [...design.categoryOrder]; featuredIds = [...design.featuredProductIds];
    for (const name of [...strings,...numbers]) if (!['heroImage','mobileHeroImage','backgroundImage'].includes(name)) form.elements.namedItem(name).value = design[name];
    for (const name of flags) form.elements.namedItem(name).checked = Boolean(design[name]);
    renderImageLibrary(design); renderSections(design); renderCategories(design);
    updateRangeLabels(); updateImagePreviews(); renderFeatured();
  }
  function fitPreview() {
    const available = stage.clientWidth;
    if (!available) return;
    const scale = Math.min(1,available / previewWidth);
    frame.style.width = previewWidth + 'px'; frame.style.height = frameHeight + 'px';
    frame.style.transform = 'scale(' + scale + ')'; frame.style.left = Math.max(0,(available - previewWidth * scale) / 2) + 'px';
    stage.style.height = Math.ceil(frameHeight * scale) + 'px';
    $('azh-preview-viewport-label').textContent = previewWidth + ' px · ' + Math.round(scale * 100) + '%';
    $('azh-preview-wrap').classList.toggle('is-phone',previewWidth === 390);
  }
  function sendPreview() {
    if (!frameReady || !lastHtml || location.origin === 'null') return;
    frame.contentWindow?.postMessage({type:'azh-shop-render-preview',html:lastHtml,seq:lastHtmlSequence},location.origin);
  }
  async function requestPreview(sequence) {
    if (!state || sequence !== previewSequence) return;
    if (!form.checkValidity()) { previewStatus('Complete the required fields to update the preview.','error'); return; }
    const controller = new AbortController(); previewController = controller;
    try {
      const result = await api('preview',{design:fields()},controller.signal);
      if (sequence !== previewSequence) return;
      if (typeof result.html !== 'string') throw new Error('The preview could not be rendered.');
      lastHtml = result.html; lastHtmlSequence = sequence; sendPreview();
      previewStatus(frameReady ? 'Rendering private preview…' : 'Preview ready · loading storefront…','updating');
    } catch (error) {
      if (error.name === 'AbortError' || sequence !== previewSequence) return;
      previewStatus(error.message,'error');
    } finally { if (previewController === controller) previewController = null; }
  }
  function queuePreview(immediate = false) {
    if (!state) return;
    clearTimeout(previewTimer); previewController?.abort();
    const sequence = ++previewSequence;
    previewStatus('Updating private preview…','updating');
    if (immediate) void requestPreview(sequence);
    else previewTimer = window.setTimeout(() => void requestPreview(sequence),200);
  }
  function changed() {
    if (!state || busy) return;
    updateRangeLabels(); updateImagePreviews(); showSaveState(); queuePreview();
  }
  async function loadState() {
    const fresh = await api('state'); acceptState(fresh); render(); dirty = false;
    editor.querySelector('[data-action="reload"]').hidden = true;
    showSaveState('Ready · ' + (stable(state.draft) === stable(state.published) ? 'published revision ' + state.publishedRevision : 'saved draft, not published'));
    queuePreview(true);
  }
  async function save(mode) {
    if (busy || !state) return;
    if (!form.reportValidity()) return;
    if (mode === 'publish' && !window.confirm('Publish this homepage design to the public shop?')) return;
    const snapshot = fields(); setBusy(true,mode === 'publish' ? 'Publishing homepage…' : 'Saving draft…');
    try {
      const result = await api('save',{revision:state.revision,action:mode,design:snapshot});
      // Update the acknowledged state before the metadata read. If that read
      // fails, a successful save must not become a second stale-revision save.
      state.revision = result.revision; state.draft = normalizeDesign(result.data || snapshot);
      if (result.published) { state.published = clone(state.draft); state.publishedRevision++; }
      dirty = false;
      try { acceptState(await api('state')); } catch { /* The write itself was confirmed. */ }
      render(); showSaveState(result.published ? 'Homepage published successfully.' : 'Draft saved · not visible to the public.');
      editor.querySelector('[data-action="reload"]').hidden = true; queuePreview(true);
    } catch (error) { handleError(error); } finally { setBusy(false); }
  }
  async function resetDraft() {
    if (busy || !state || !window.confirm('Discard your saved and unsaved draft changes and restore the published design?')) return;
    setBusy(true,'Restoring the published design…');
    try {
      const result = await api('save',{revision:state.revision,action:'resetDraft'});
      state.revision = result.revision; state.draft = normalizeDesign(result.data || state.published);
      try { acceptState(await api('state')); } catch { /* The restore was confirmed. */ }
      render(); dirty = false; showSaveState('Published design restored to the draft.'); queuePreview(true);
    } catch (error) { handleError(error); } finally { setBusy(false); }
  }
  function openUpload(target) {
    $('azh-edit-upload-target').value = target;
    $('azh-image-library-controls').open = true;
    $('azh-image-library-controls').scrollIntoView({block:'nearest',behavior:'smooth'});
    $('azh-edit-upload-file').focus(); $('azh-edit-upload-file').click();
  }
  async function uploadImage() {
    if (busy || !state) return;
    const file = $('azh-edit-upload-file').files[0], target = $('azh-edit-upload-target').value;
    const alt = form.elements.namedItem('uploadAlt').value.trim();
    if (!file || !alt) { $('azh-edit-upload-status').textContent = 'Choose an image and add a short description.'; return; }
    const pending = fields(); setBusy(true,'Uploading image…');
    try {
      const data = new FormData(); data.set('image',file); data.set('alt',alt);
      const result = await api('upload',data);
      if (target.startsWith('category:')) pending.categoryCards[target.slice(9)].image = result.id;
      else pending[target] = result.id;
      // Uploading changes the image library, not the design revision. Keep the
      // original revision so another editor's save still produces a conflict.
      try {
        const fresh = await api('state');
        state.images = fresh.images || state.images; state.products = fresh.products || state.products;
        state.categoryImages = fresh.categoryImages || state.categoryImages;
      }
      catch { state.images = [{id:result.id,src:result.src,alt_text:result.alt || alt},...state.images]; }
      render(pending); showSaveState();
      $('azh-edit-upload-file').value = ''; form.elements.namedItem('uploadAlt').value = '';
      $('azh-edit-upload-status').textContent = 'Image selected in your private preview. Save draft to keep this choice.';
      queuePreview(true);
    } catch (error) { $('azh-edit-upload-status').textContent = error.message; handleError(error); }
    finally { setBusy(false); }
  }
  async function deleteImage(id) {
    if (busy || !state) return;
    if (imageIsUsed(id)) { status('This image is used in your current, saved or published design.','error'); return; }
    if (!window.confirm('Delete this unused image permanently?')) return;
    const pending = fields(); setBusy(true,'Deleting unused image…');
    try {
      await api('deleteImage',{id});
      state.images = state.images.filter(image => image.id !== id);
      render(pending); showSaveState();
      $('azh-edit-upload-status').textContent = 'Unused image deleted.';
    } catch (error) { handleError(error); } finally { setBusy(false); }
  }
  function moveItem(kind, slug, delta) {
    const list = kind === 'section' ? order : kind === 'category' ? categoryOrder : featuredIds;
    const index = list.indexOf(slug), next = index + delta;
    if (index < 0 || next < 0 || next >= list.length) return;
    const design = fields(); [list[index],list[next]] = [list[next],list[index]];
    if (kind === 'section') renderSections(design);
    else if (kind === 'category') renderCategories(design);
    else renderFeatured();
    changed();
    form.querySelector(`[data-${kind}-${delta < 0 ? 'up' : 'down'}="${slug}"]`)?.focus({preventScroll:true});
  }
  form.addEventListener('input',event => {
    if (!state || busy) return;
    if (event.target.id === 'azh-featured-search') { resultsLimit = 12; renderProductResults(); return; }
    if (['uploadAlt','uploadTarget','imageFile'].includes(event.target.name)) return;
    changed();
  });
  form.addEventListener('change',event => {
    if (!state || busy || ['uploadAlt','uploadTarget','imageFile'].includes(event.target.name)) return;
    if (event.target.name === 'heroFocalX') form.elements.namedItem('heroPosition').value = Number(event.target.value) < 34 ? 'left' : Number(event.target.value) > 66 ? 'right' : 'center';
    if (['featuredMode','featuredCount','sampleEnabled','storeEnabled'].includes(event.target.name) || event.target.dataset.categoryEnable) renderFeatured();
    changed();
  });
  form.addEventListener('click',event => {
    const control = event.target.closest('button');
    if (!control || busy || !state) return;
    for (const kind of ['section','category','featured']) {
      for (const [direction,delta] of [['Up',-1],['Down',1]]) {
        const key = kind + direction;
        if (control.dataset[key]) { moveItem(kind,control.dataset[key],delta); return; }
      }
    }
    if (control.dataset.featuredAdd && featuredIds.length < 24 && !featuredIds.includes(control.dataset.featuredAdd)) {
      featuredIds.push(control.dataset.featuredAdd); renderFeatured(); changed(); return;
    }
    if (control.dataset.featuredRemove) { featuredIds = featuredIds.filter(id => id !== control.dataset.featuredRemove); renderFeatured(); changed(); return; }
    if (control.dataset.imageUpload) { openUpload(control.dataset.imageUpload); return; }
    if (control.dataset.imageRemove) { form.elements.namedItem(control.dataset.imageRemove).value = 'none'; changed(); return; }
    if (control.dataset.categoryImageRemove) { form.querySelector(`[data-category-image="${control.dataset.categoryImageRemove}"]`).value = 'none'; changed(); return; }
    if (control.dataset.imageDelete) void deleteImage(control.dataset.imageDelete);
  });
  form.addEventListener('submit',event => { event.preventDefault(); void save('saveDraft'); });
  editor.querySelector('[data-action="save"]').addEventListener('click',() => void save('saveDraft'));
  editor.querySelector('[data-action="publish"]').addEventListener('click',() => void save('publish'));
  editor.querySelector('[data-action="reset"]').addEventListener('click',() => void resetDraft());
  editor.querySelector('[data-action="preview"]').addEventListener('click',() => { queuePreview(true); panel.scrollIntoView({block:'start',behavior:'smooth'}); });
  editor.querySelector('[data-action="reload"]').addEventListener('click',async () => {
    if (busy || dirty && !window.confirm('Reload the saved design and discard unsaved edits in this tab?')) return;
    setBusy(true,'Loading saved design…');
    try { await loadState(); } catch (error) { handleError(error); } finally { setBusy(false); }
  });
  $('azh-edit-upload').addEventListener('click',() => void uploadImage());
  $('azh-featured-more').addEventListener('click',() => { resultsLimit += 12; renderProductResults(); });
  for (const control of editor.querySelectorAll('[data-preview-width]')) control.addEventListener('click',() => {
    previewWidth = Number(control.dataset.previewWidth);
    for (const item of editor.querySelectorAll('[data-preview-width]')) item.setAttribute('aria-pressed',String(item === control));
    fitPreview(); if (location.origin !== 'null') frame.contentWindow?.postMessage({type:'azh-shop-preview-measure'},location.origin);
  });
  frame.addEventListener('load',() => { frameReady = true; fitPreview(); sendPreview(); });
  window.addEventListener('message',event => {
    if (event.origin !== location.origin || event.source !== frame.contentWindow) return;
    if (event.data?.type === 'azh-shop-preview-height' && Number.isFinite(event.data.height)) {
      frameHeight = Math.max(150,Math.min(60000,Math.ceil(event.data.height))); fitPreview();
    }
    if (event.data?.type === 'azh-shop-preview-ready') { frameReady = true; sendPreview(); }
    if (event.data?.type === 'azh-shop-preview-applied' && event.data.seq === lastHtmlSequence && lastHtmlSequence === previewSequence)
      previewStatus('Preview updated · changes remain private');
  });
  function movePanel(container) {
    if (typeof container.moveBefore === 'function') container.moveBefore(panel,null);
    else { frameReady = false; container.append(panel); }
    fitPreview();
  }
  $('azh-edit-fullscreen').addEventListener('click',() => {
    if (dialog.open) return;
    bodyOverflow = document.body.style.overflow; document.body.style.overflow = 'hidden';
    dialog.showModal(); movePanel($('azh-preview-dialog-content')); $('azh-edit-fullscreen').hidden = true;
    $('azh-edit-exit-fullscreen').focus();
  });
  $('azh-edit-exit-fullscreen').addEventListener('click',() => dialog.close());
  dialog.addEventListener('close',() => {
    movePanel($('azh-preview-home')); document.body.style.overflow = bodyOverflow;
    $('azh-edit-fullscreen').hidden = false; $('azh-edit-fullscreen').focus({preventScroll:true});
  });
  window.addEventListener('beforeunload',event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
  window.addEventListener('resize',fitPreview);
  if (typeof ResizeObserver !== 'undefined') new ResizeObserver(fitPreview).observe(stage);
  try { frameReady = frame.contentDocument?.readyState === 'complete' && frame.contentDocument.body?.dataset.shopPreview === '1'; } catch { /* Wait for the iframe load event. */ }
  fitPreview(); setBusy(true);
  loadState().catch(handleError).finally(() => setBusy(false));
})();
