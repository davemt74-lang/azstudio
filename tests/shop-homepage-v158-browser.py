#!/usr/bin/env python3
"""Shop 2.2 acceptance through the real editor, PHP API, database and storefront.

Only marked temporary application copies are served. No mock fetch, copied
storefront markup, live accounts, provider calls, or production data are used.
"""
import copy
import importlib.util
import os
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.request
from pathlib import Path

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
OUT = Path(os.environ.get('RUNNER_TEMP', '/tmp')) / 'azh_shop_v158_browser'
OUT.mkdir(parents=True, exist_ok=True)
spec = importlib.util.spec_from_file_location('shop_renderer_fixture', ROOT / 'tests/shop-hero-v1571-browser.py')
fixture_module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture_module)
PRODUCT_A, PRODUCT_B = '1' * 32, '2' * 32
IMAGE_A, IMAGE_B, IMAGE_C = 'a' * 32, 'b' * 32, 'c' * 32
IMAGE_D = 'd' * 32
HIDDEN_CANDY_PRODUCT = format(1000, '032x')


def seed(app):
    fixture_module.copy_application(app)
    result = subprocess.run(['php', str(ROOT / 'tests/fixtures/shop-homepage-v158.php'), str(app)],
                            capture_output=True, text=True, timeout=60)
    assert result.returncode == 0, result.stdout + result.stderr


def api(context, base, action='state', body=None, csrf=None, expected=200):
    url = base + 'shop-homepage-api.php?action=' + action
    if body is None:
        response = context.request.get(url)
    else:
        response = context.request.post(url, data=body, headers={'X-CSRF-Token': csrf or ''})
    assert response.status == expected, (action, response.status, response.text()[:1400])
    payload = response.json()
    assert payload['ok'] == (expected == 200), payload
    return payload.get('data', payload.get('error'))


def frame(page):
    result = page.locator('#azh-shop-preview').element_handle().content_frame()
    assert result is not None, 'Editor preview iframe is missing'
    return result


def reveal(locator):
    locator.evaluate('''element => {
        for (let parent=element.parentElement;parent;parent=parent.parentElement) {
            if(parent.tagName==='DETAILS')parent.open=true;
        }
    }''')
    return locator


def field(page, name, value):
    element = reveal(page.locator('[name="' + name + '"]'))
    kind = element.get_attribute('type')
    if element.evaluate('(element)=>element.tagName') == 'SELECT':
        element.select_option(str(value))
    elif kind == 'checkbox':
        element.set_checked(bool(value))
    elif kind in ['range', 'color']:
        element.evaluate('''(element,value)=>{
            element.value=String(value);
            element.dispatchEvent(new Event('input',{bubbles:true}));
            element.dispatchEvent(new Event('change',{bubbles:true}));
        }''', value)
    else:
        element.fill(str(value))


def until_preview(page, expression, arg=None):
    frame(page).wait_for_function(expression, arg=arg, timeout=15000)
    page.wait_for_function('document.querySelector("#azh-shop-preview-status")?.dataset.state === "ready"', timeout=15000)


def featured_ids(view):
    return view.locator('[data-featured-product-id]').evaluate_all('(nodes)=>nodes.map(node=>node.dataset.featuredProductId)')


def assert_featured(page, ids):
    until_preview(page, '(ids)=>JSON.stringify([...document.querySelectorAll("[data-featured-product-id]")].map(node=>node.dataset.featuredProductId))===JSON.stringify(ids)', ids)


SNAPSHOT = '''() => {
 const hero=document.querySelector('[data-section=hero]'),root=document.querySelector('#azh-shop');
 const h1=document.querySelector('#azh-hero-headline'),copy=document.querySelector('.azh-shop-hero-copy');
 const hs=getComputedStyle(hero),cs=getComputedStyle(copy),headline=getComputedStyle(h1);
 return {width:innerWidth,heading:document.querySelector('#azh-hero-heading').textContent,
  catalog:document.querySelector('[data-section=catalog] h2')?.textContent,
  font:root.dataset.font,heroImage:hs.backgroundImage,position:hs.backgroundPosition,
  height:hs.minHeight,headingSize:headline.fontSize,textColor:headline.color,
  placement:hs.alignItems,alignment:cs.textAlign,overlay:getComputedStyle(document.querySelector('.azh-hero-overlay')).backgroundImage,
  backdrop:getComputedStyle(root,'::before').backgroundImage,
  categories:[...document.querySelectorAll('[data-category-card]')].map(node=>[node.dataset.categoryCard,node.querySelector('.azh-category-title').textContent,node.querySelector('img')?.src||'']),
  featured:[...document.querySelectorAll('[data-featured-product-id]')].map(node=>node.dataset.featuredProductId),
  sections:[...document.querySelectorAll('[data-section]')].sort((a,b)=>Number(getComputedStyle(a).order)-Number(getComputedStyle(b).order)).map(node=>node.dataset.section)};
}'''


def assert_frame_fits(page, expected_width):
    page.wait_for_function('(width)=>document.querySelector("#azh-shop-preview").contentWindow.innerWidth===width', arg=expected_width)
    until_preview(page, 'document.documentElement.scrollHeight <= innerHeight + 4')
    assert frame(page).evaluate('document.documentElement.scrollWidth <= innerWidth + 2'), 'Preview has horizontal overflow'
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'), 'Editor has horizontal overflow'
    assert frame(page).evaluate('getComputedStyle(document.documentElement).overflowY') == 'hidden', 'Embedded frame must use its parent page scroll'
    geometry = page.locator('#azh-shop-preview').evaluate('(el)=>({width:el.getBoundingClientRect().width,parent:el.closest("#azh-preview-wrap").getBoundingClientRect().width})')
    assert geometry['width'] <= geometry['parent'] + 2, ('Preview is not scaled to its container', geometry)


def open_editor(context, base):
    response = context.request.post(base + '_shop-fixture-login.php')
    assert response.status == 204, response.text()
    page = context.new_page()
    page.set_default_timeout(12000)
    errors, mutations, purchases = [], [], []
    page.on('pageerror', lambda error: errors.append(str(error)))

    def request_seen(request):
        if 'shop-homepage-api.php?action=' in request.url and request.method == 'POST':
            mutations.append(request.url.split('action=', 1)[1])
        if any(token in request.url for token in ['commerce-checkout-api.php', 'stripe.com', 'fixture-checkout/']):
            purchases.append(request.url)

    page.on('request', request_seen)
    assert page.goto(base + 'shop-homepage-editor.php', wait_until='domcontentloaded').status == 200
    page.wait_for_function('document.querySelector("[name=heading]")?.value === "Saved private collection"')
    until_preview(page, 'document.querySelector("#azh-hero-heading")?.textContent === "Saved private collection"')
    return page, errors, mutations, purchases


def assert_preview_navigation_stays_inside(page, context, base):
    preview = frame(page)
    before = preview.url
    assert preview.locator('#azh-aw-bell').is_disabled(), 'Embedded preview must disable agent work actions'
    assert preview.locator('#azh-aw-drawer').evaluate('(drawer)=>drawer.inert'), 'Agent action drawer must be inert in preview'
    requests = []
    listener = lambda request: requests.append(request.url) if request.is_navigation_request() else None
    page.on('request', listener)
    preview.locator('#azh-global-header .azh-global-header__brand').click()
    assert frame(page).url == before, 'Preview brand link escaped the embedded shop'
    preview.locator('#azh-account-menu summary').click()
    preview.locator('#azh-account-menu a[href]').first.click()
    assert frame(page).url == before, 'Preview account navigation escaped the embedded shop'
    if not preview.locator('#azh-account-menu').evaluate('(menu)=>menu.open'):
        preview.locator('#azh-account-menu summary').click()
    preview.locator('#azh-account-menu form[action$="logout.php"] button[type=submit]').click()
    assert frame(page).url == before, 'Preview sign-out must not navigate'
    page.remove_listener('request', listener)
    assert not requests, ('Embedded preview initiated a navigation', requests)
    assert api(context, base)['draft'], 'Preview sign-out must not destroy the editor session'
    if preview.locator('#azh-account-menu').evaluate('(menu)=>menu.open'):
        preview.locator('#azh-account-menu summary').click()


def access_and_validation(browser, context, page, base, baseline):
    csrf = page.locator('#azh-shop-editor').get_attribute('data-csrf')
    api(context, base, 'preview', {'design': baseline['draft']}, 'incorrect-csrf', expected=403)
    invalid = copy.deepcopy(baseline['draft'])
    invalid['heroImage'] = 'https://example.invalid/forged.svg'
    api(context, base, 'preview', {'design': invalid}, csrf, expected=400)
    invalid = copy.deepcopy(baseline['draft'])
    invalid['featuredProductIds'] = ['e' * 32]
    invalid['featuredMode'] = 'manual'
    api(context, base, 'preview', {'design': invalid}, csrf, expected=400)
    api(context, base, 'save', {'action': 'publish', 'revision': baseline['revision'] + 50, 'design': baseline['draft']}, csrf, expected=409)
    anonymous = browser.new_context()
    assert anonymous.request.get(base + 'shop.php?preview=1').status == 403
    assert anonymous.request.post(base + 'shop-homepage-api.php?action=preview', data={'design': baseline['draft']}).status in [401, 403]
    for image_id in [IMAGE_A, IMAGE_B, IMAGE_C, IMAGE_D]:
        assert anonymous.request.get(base + 'shop-homepage-media.php?id=' + image_id).status == 404
    member = browser.new_context()
    assert member.request.post(base + '_shop-fixture-member.php').status == 204
    assert member.request.get(base + 'shop-homepage-api.php?action=state').status == 403
    assert member.request.get(base + 'shop.php?preview=1').status == 403
    member.close()
    anonymous.close()
    assert api(context, base)['revision'] == baseline['revision'], 'Rejected requests must not change the draft'
    print('Preview authorization, CSRF, invalid image/product, revision and draft image privacy PASS', flush=True)


def full_lifecycle(browser, base):
    context = browser.new_context(viewport={'width': 1440, 'height': 1000})
    page, errors, mutations, purchases = open_editor(context, base)
    csrf = page.locator('#azh-shop-editor').get_attribute('data-csrf')
    baseline = api(context, base)
    assert len([product for product in baseline['products'] if not product['demo']]) > 48
    assert any(product['id'] == PRODUCT_A for product in baseline['products']), 'Old eligible products must remain selectable'
    access_and_validation(browser, context, page, base, baseline)
    mutations.clear()
    assert_preview_navigation_stays_inside(page, context, base)

    # Validation must remain discoverable even when a required control is in
    # a closed accordion. No failed attempt may turn into a saved draft.
    field(page, 'heading', '')
    page.locator('[name=heading]').evaluate('(input)=>input.closest("details").open=false')
    page.locator('[data-action=save]').click()
    page.wait_for_function('document.querySelector("#azh-shop-editor-status")?.dataset.state === "error"')
    assert page.locator('[name=heading]').is_visible(), 'Saving an invalid closed panel must reveal the required field'
    assert page.locator('[name=heading]').evaluate('(input)=>document.activeElement===input'), 'Invalid control must receive focus'
    assert set(mutations) <= {'preview'}, 'Required-field validation must not issue a save'
    field(page, 'heading', baseline['draft']['heading'])
    until_preview(page, 'document.querySelector("#azh-hero-heading")?.textContent === "Saved private collection"')

    assert not page.locator('[name=storeEnabled]').is_checked()
    assert frame(page).locator('[data-section=featured]').count() == 0
    field(page, 'storeEnabled', True)
    reveal(page.locator('[data-section-enable=featured]')).check()
    reveal(page.locator('[data-section-enable=support]')).check()
    reveal(page.locator('[data-category-enable=fitness]')).check()
    until_preview(page, 'document.querySelector("[data-section=featured]") && document.querySelector("[data-section=support]") && document.querySelector("[data-category-card=fitness]")')
    assert PRODUCT_A not in featured_ids(frame(page)), 'Fixture must put the older product beyond automatic first-page selection'

    field(page, 'heading', 'Designed in the real editor')
    field(page, 'catalogTitle', 'Our studio collection')
    field(page, 'heroImage', IMAGE_A)
    field(page, 'backgroundImage', IMAGE_B)
    field(page, 'mobileHeroImage', IMAGE_C)
    for name, value in {'heroFocalX':23, 'heroFocalY':41, 'heroHeight':610, 'headingSize':61,
                        'overlayStrength':25, 'mobileHeroFocalX':73, 'mobileHeroFocalY':19,
                        'mobileHeroHeight':690, 'mobileHeadingSize':43, 'mobileOverlayStrength':10}.items():
        field(page, name, value)
    for name, value in {'heroAlignment':'right', 'heroTextPosition':'bottom', 'mobileHeroAlignment':'left',
                        'mobileHeroTextPosition':'top', 'fontStyle':'classic', 'textColor':'#302134'}.items():
        field(page, name, value)
    until_preview(page, 'document.querySelector("#azh-hero-heading")?.textContent === "Designed in the real editor" && getComputedStyle(document.querySelector("[data-section=hero]")).backgroundPosition === "23% 41%"')
    for field_name, image_id in [('heroImage', IMAGE_A), ('mobileHeroImage', IMAGE_C), ('backgroundImage', IMAGE_B)]:
        assert image_id in page.locator('img[data-image-preview="' + field_name + '"]').get_attribute('src'), 'Selected thumbnail is stale'
    desktop = frame(page).evaluate(SNAPSHOT)
    assert desktop['height'] == '610px' and desktop['headingSize'] == '61px' and desktop['alignment'] == 'right', desktop
    assert desktop['placement'] == 'flex-end' and IMAGE_A in desktop['heroImage'] and IMAGE_B in desktop['backdrop'], desktop
    assert '0.25' in desktop['overlay'] and desktop['textColor'] == 'rgb(48, 33, 52)', desktop
    fixture_module.assert_image_decodes(frame(page), fixture_module.image_url(frame(page), '[data-section=hero]'),
                                        base + 'shop-homepage-media.php?id=' + IMAGE_A)
    fixture_module.assert_image_decodes(frame(page), fixture_module.image_url(frame(page), '#azh-shop', '::before'),
                                        base + 'shop-homepage-media.php?id=' + IMAGE_B)
    reveal(page.locator('[data-image-remove=heroImage]')).click()
    until_preview(page, 'getComputedStyle(document.querySelector("[data-section=hero]")).backgroundImage === "none"')
    assert page.locator('img[data-image-preview=heroImage]').is_hidden(), 'Removed image thumbnail must clear'
    field(page, 'heroImage', IMAGE_A)
    until_preview(page, '(id)=>getComputedStyle(document.querySelector("[data-section=hero]")).backgroundImage.includes(id)', IMAGE_A)
    for image_id in [IMAGE_A, IMAGE_B, IMAGE_C]:
        assert page.locator('[data-image-delete="' + image_id + '"]').is_disabled(), 'Unsaved image references must be protected in the editor'

    field(page, 'sampleEnabled', True)
    field(page, 'featuredMode', 'manual')
    for title, product_id in [('Studio sweatshirt', PRODUCT_A), ('Studio bottle', PRODUCT_B), ('Crop Hoodie', 'demo-hoodie')]:
        reveal(page.locator('#azh-featured-search')).fill(title)
        page.locator('[data-featured-add="' + product_id + '"]').click()
    page.locator('[data-featured-up="demo-hoodie"]').click()
    page.locator('[data-featured-up="demo-hoodie"]').click()
    field(page, 'featuredCount', 2)
    assert_featured(page, ['demo-hoodie', PRODUCT_A])
    field(page, 'sampleEnabled', False)
    assert_featured(page, [PRODUCT_A, PRODUCT_B])
    assert frame(page).locator('[data-stripe-checkout],[data-local-order],a[href*="fixture-checkout/"]').count() == 0, 'Real products must remain unpurchasable inside the preview'
    field(page, 'sampleEnabled', True)
    assert_featured(page, ['demo-hoodie', PRODUCT_A])
    reveal(page.locator('[data-category-enable=apparel]')).uncheck()
    assert_featured(page, [PRODUCT_B])
    reveal(page.locator('[data-category-enable=apparel]')).check()
    assert_featured(page, ['demo-hoodie', PRODUCT_A])

    reveal(page.locator('[data-category-title=fitness]')).fill('Move & train')
    page.locator('[data-category-image=fitness]').select_option(IMAGE_D)
    page.locator('[data-category-up=fitness]').click()
    reveal(page.locator('[data-category-enable=candy]')).uncheck()
    field(page, 'showEmptyCategories', False)
    until_preview(page, 'document.querySelector("[data-category-card=fitness] .azh-category-title")?.textContent === "Move & train" && !document.querySelector("[data-category-card=digital]")')
    field(page, 'showEmptyCategories', True)
    until_preview(page, 'document.querySelector("[data-category-card=digital]") && !document.querySelector("[data-category-card=candy]")')
    category_order = frame(page).locator('[data-category-card]').evaluate_all('(nodes)=>nodes.map(node=>node.dataset.categoryCard)')
    assert category_order[:2] == ['fitness', 'apparel'], category_order
    category_image = frame(page).locator('[data-category-card=fitness] img').evaluate('(image)=>image.src')
    fixture_module.assert_image_decodes(frame(page), category_image, base + 'shop-homepage-media.php?id=' + IMAGE_D)
    assert page.locator('[data-image-delete="' + IMAGE_D + '"]').is_disabled(), 'Unsaved category image must be protected'
    frame(page).locator('[data-shop-category-jump=digital]').click()
    frame(page).wait_for_function('!document.querySelector("#azh-shop-no-matches").hidden')
    frame(page).locator('[data-shop-filter=all]').click()
    frame(page).wait_for_function('document.querySelector("#azh-shop-no-matches").hidden')

    # Disabling and re-enabling an absent section must work without reloading
    # the frame or saving. Other unsaved merchandising changes must survive.
    reveal(page.locator('[data-section-enable=hero]')).uncheck()
    until_preview(page, '!document.querySelector("[data-section=hero]")')
    reveal(page.locator('[data-section-enable=hero]')).check()
    until_preview(page, 'document.querySelector("#azh-hero-heading")?.textContent === "Designed in the real editor"')
    reveal(page.locator('[data-section-down=hero]')).click()
    until_preview(page, 'getComputedStyle(document.querySelector("[data-section=hero]")).order === "1"')
    assert frame(page).evaluate(SNAPSHOT)['sections'][:2] == ['categories', 'hero']
    assert page.locator('[data-section-enable=hero]').is_checked() and not page.locator('[data-category-enable=candy]').is_checked(), 'Reordering must preserve section/category visibility'
    page.locator('[data-section-up=hero]').click()
    page.locator('[data-section-down=featured]').click()
    until_preview(page, 'getComputedStyle(document.querySelector("[data-section=catalog]")).order === "2" && getComputedStyle(document.querySelector("[data-section=hero]")).order === "0"')
    assert_featured(page, ['demo-hoodie', PRODUCT_A])
    untouched = api(context, base)
    assert untouched['draft'] == baseline['draft'] and untouched['published'] == baseline['published']
    assert untouched['revision'] == baseline['revision'], 'Live previews must not save or publish'
    assert set(mutations) <= {'preview'}, ('Preview made a write request', mutations)
    assert page.locator('#azh-shop-editor-status').get_attribute('data-state') == 'unsaved'

    # The iframe's layout viewport remains the requested width even on a narrow
    # editor. Its complete document is fitted without a second scrollbar.
    for viewport, button in [(1440, '#azh-edit-desktop'), (768, '#azh-edit-tablet'), (390, '#azh-edit-phone')]:
        page.locator(button).click()
        assert_frame_fits(page, viewport)
        if viewport == 390:
            phone = frame(page).evaluate(SNAPSHOT)
            assert phone['position'] == '73% 19%' and phone['height'] == '690px' and phone['headingSize'] == '43px', phone
            assert phone['alignment'] == 'left' and phone['placement'] == 'flex-start' and IMAGE_C in phone['heroImage'], phone
            assert '0.1' in phone['overlay'], phone
            fixture_module.assert_image_decodes(frame(page), fixture_module.image_url(frame(page), '[data-section=hero]'),
                                                base + 'shop-homepage-media.php?id=' + IMAGE_C)
    field(page, 'mobileHeroImage', 'inherit')
    until_preview(page, '(id)=>getComputedStyle(document.querySelector("[data-section=hero]")).backgroundImage.includes(id)', IMAGE_A)
    assert IMAGE_A in page.locator('img[data-image-preview=mobileHeroImage]').get_attribute('src'), 'Phone inherit thumbnail must follow the desktop image'
    field(page, 'mobileHeroImage', IMAGE_C)
    until_preview(page, '(id)=>getComputedStyle(document.querySelector("[data-section=hero]")).backgroundImage.includes(id)', IMAGE_C)
    page.set_viewport_size({'width': 390, 'height': 900})
    page.locator('#azh-edit-desktop').click()
    assert_frame_fits(page, 1440)
    page.set_viewport_size({'width': 1440, 'height': 1000})
    page.locator('#azh-edit-fullscreen').click()
    assert page.locator('#azh-shop-preview-dialog').evaluate('(dialog)=>dialog.open')
    assert_frame_fits(page, 1440)
    until_preview(page, 'document.querySelector("#azh-hero-heading")?.textContent === "Designed in the real editor"')
    assert_featured(page, ['demo-hoodie', PRODUCT_A])
    page.keyboard.press('Escape')
    assert not page.locator('#azh-shop-preview-dialog').evaluate('(dialog)=>dialog.open')
    page.wait_for_function('document.activeElement===document.querySelector("#azh-edit-fullscreen")')
    assert_frame_fits(page, 1440)
    until_preview(page, 'document.querySelector("#azh-hero-heading")?.textContent === "Designed in the real editor"')
    assert_featured(page, ['demo-hoodie', PRODUCT_A])

    page.locator('[data-action=save]').click()
    page.wait_for_function('document.querySelector("#azh-shop-editor-status")?.dataset.state === "draft"')
    saved = api(context, base)
    assert saved['revision'] == baseline['revision'] + 1
    assert saved['draft']['heading'] == 'Designed in the real editor'
    assert saved['draft']['featuredProductIds'][:3] == ['demo-hoodie', PRODUCT_A, PRODUCT_B]
    assert saved['draft']['categoryCards']['fitness'] == {'title':'Move & train', 'image':IMAGE_D}
    assert saved['draft']['order'] == ['hero', 'categories', 'catalog', 'featured', 'support']
    assert saved['published'] == baseline['published'], 'Save draft must not publish'
    anonymous = browser.new_context()
    for image_id in [IMAGE_A, IMAGE_B, IMAGE_C, IMAGE_D]:
        assert anonymous.request.get(base + 'shop-homepage-media.php?id=' + image_id).status == 404, 'Saved draft image leaked before publishing'
    anonymous.close()
    page.reload(wait_until='domcontentloaded')
    until_preview(page, 'document.querySelector("#azh-hero-heading")?.textContent === "Designed in the real editor"')
    assert_featured(page, ['demo-hoodie', PRODUCT_A])
    assert page.locator('[data-category-title=fitness]').input_value() == 'Move & train'
    csrf = page.locator('#azh-shop-editor').get_attribute('data-csrf')
    for image_id in [IMAGE_A, IMAGE_B, IMAGE_C, IMAGE_D]:
        api(context, base, 'deleteImage', {'id':image_id}, csrf, expected=409)
    page.once('dialog', lambda dialog: dialog.accept())
    page.locator('[data-action=publish]').click()
    page.wait_for_function('document.querySelector("#azh-shop-editor-status")?.dataset.state === "published"')
    published = api(context, base)
    assert published['published'] == published['draft'] == saved['draft']
    assert published['revision'] == saved['revision'] + 1

    anonymous = browser.new_context(viewport={'width':1440,'height':1000})
    storefront = anonymous.new_page()
    assert storefront.goto(base + 'shop.php', wait_until='networkidle').status == 200
    for width, button in [(1440, '#azh-edit-desktop'), (390, '#azh-edit-phone')]:
        page.locator(button).click()
        assert_frame_fits(page, width)
        storefront.set_viewport_size({'width':width,'height':1000})
        assert frame(page).evaluate(SNAPSHOT) == storefront.evaluate(SNAPSHOT), ('Preview and published layout diverged', width)
        storefront.screenshot(path=str(OUT / ('published_' + str(width) + '.png')), full_page=False)
    for image_id in [IMAGE_A, IMAGE_B, IMAGE_C, IMAGE_D]:
        assert anonymous.request.get(base + 'shop-homepage-media.php?id=' + image_id).status == 200
    detail = anonymous.new_page()
    assert detail.goto(base + 'shop-product.php?id=' + PRODUCT_A, wait_until='domcontentloaded').status == 200, 'An older featured product must have a reachable public detail page'
    assert detail.locator('.azh-shop-detail-copy h1').inner_text() == 'Studio sweatshirt'
    assert detail.locator('.azh-shop-bundle a').evaluate_all('(links)=>links.map(link=>new URL(link.href).searchParams.get("id"))') == [PRODUCT_B], 'Exact selected bundle must retain eligible components and omit hidden or inactive components'
    assert anonymous.request.get(base + 'shop-product.php?id=' + PRODUCT_B).status == 200
    assert anonymous.request.get(base + 'shop-product.php?id=' + HIDDEN_CANDY_PRODUCT).status == 404, 'Hidden category product detail must be unavailable'
    assert anonymous.request.get(base + 'shop-product.php?id=' + '3' * 32).status == 404, 'Inactive product detail must be unavailable'
    detail.close()
    headline = storefront.locator('#azh-hero-heading').inner_text()
    storefront.evaluate('window.postMessage({type:"azh-shop-render-preview",html:"<main id=azh-shop class=azh-shop>forged</main>"},location.origin)')
    storefront.wait_for_timeout(50)
    assert storefront.locator('#azh-hero-heading').inner_text() == headline, 'Public shop must ignore editor preview messages'
    assert not purchases, ('Preview initiated checkout', purchases)
    assert not errors, ('Editor JavaScript errors', errors)
    page.locator('#azh-edit-desktop').click()
    page.evaluate('window.scrollTo(0,0)')
    page.screenshot(path=str(OUT / 'editor_desktop.png'), full_page=False)
    anonymous.close()
    context.close()
    print('Real editor: live previews, device controls, hidden sections, merchandising, save/reopen/publish parity PASS', flush=True)


def subdirectory_smoke(browser, base):
    context = browser.new_context(viewport={'width':1280,'height':900})
    page, errors, mutations, purchases = open_editor(context, base)
    baseline = api(context, base)
    field(page, 'heroImage', IMAGE_A)
    field(page, 'mobileHeroImage', IMAGE_C)
    field(page, 'backgroundImage', IMAGE_B)
    until_preview(page, '(image)=>getComputedStyle(document.querySelector("[data-section=hero]")).backgroundImage.includes(image)', IMAGE_A)
    value = frame(page).evaluate(SNAPSHOT)
    assert base + 'shop-homepage-media.php?id=' + IMAGE_A in value['heroImage']
    assert base + 'shop-homepage-media.php?id=' + IMAGE_B in value['backdrop']
    page.locator('#azh-edit-phone').click()
    assert_frame_fits(page, 390)
    assert base + 'shop-homepage-media.php?id=' + IMAGE_C in frame(page).evaluate(SNAPSHOT)['heroImage']
    assert api(context, base)['revision'] == baseline['revision']
    assert not purchases and not errors
    context.close()
    print('Actual /studio editor, preview API and protected image URL resolution PASS', flush=True)


def run():
    assert shutil.which('php'), 'PHP with PDO SQLite is required for the real editor acceptance test.'
    with tempfile.TemporaryDirectory(prefix='azh-shop-editor-') as temp:
        root = Path(temp)
        app = root / 'www'
        seed(app)
        seed(app / 'studio')
        sessions = root / 'sessions'
        sessions.mkdir()
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        origin = 'http://127.0.0.1:' + str(port)
        with (OUT / 'php-server.log').open('w+') as log:
            server = subprocess.Popen(['php', '-d', 'session.save_path=' + str(sessions), '-S',
                                       '127.0.0.1:' + str(port), '-t', str(app)], stdout=log, stderr=log)
            try:
                deadline = time.monotonic() + 10
                while True:
                    try:
                        urllib.request.urlopen(origin + '/assets/favicon.svg', timeout=1).close()
                        break
                    except OSError:
                        if server.poll() is not None or time.monotonic() > deadline:
                            log.seek(0)
                            raise RuntimeError('PHP fixture server did not start: ' + log.read())
                        time.sleep(0.05)
                with sync_playwright() as pw:
                    executable = next((shutil.which(name) for name in ['chromium','google-chrome','chromium-browser'] if shutil.which(name)), None)
                    browser = pw.chromium.launch(executable_path=executable, headless=True, args=['--no-sandbox'])
                    full_lifecycle(browser, origin + '/')
                    subdirectory_smoke(browser, origin + '/studio/')
                    browser.close()
            finally:
                server.terminate()
                try:
                    server.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait()
    print('Shop 2.2 real editor and published storefront acceptance: PASS')


if __name__ == '__main__':
    run()
