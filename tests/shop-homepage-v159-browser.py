#!/usr/bin/env python3
"""Recovery/history acceptance through the real PHP editor/API and isolated SQLite.

No mocked API, production accounts or external providers. The storage-failure
case deliberately faults browser storage, then verifies an actual server save.
"""
import importlib.util
import json
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
OUT = Path(os.environ.get('RUNNER_TEMP', '/tmp')) / 'azh_shop_v159_browser'
OUT.mkdir(parents=True, exist_ok=True)
spec = importlib.util.spec_from_file_location('homepage158', ROOT / 'tests/shop-homepage-v158-browser.py')
fixture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture)


def seed(app):
    fixture.seed(app)
    result = subprocess.run(['php', str(ROOT / 'tests/fixtures/shop-homepage-v159.php'), str(app)],
                            capture_output=True, text=True, timeout=60)
    assert result.returncode == 0, result.stdout + result.stderr


def open_editor(context, base, expected=None):
    page = context.new_page()
    page.set_default_timeout(15000)
    page.on('dialog', lambda dialog: dialog.accept())
    response = page.goto(base + 'shop-homepage-editor.php', wait_until='domcontentloaded')
    assert response.status == 200, response.text()[:1000]
    ready(page, expected)
    return page


def ready(page, heading=None):
    page.wait_for_function('''() => document.querySelector('#azh-shop-editor-fields')?.disabled === false
        && document.querySelector('#azh-shop-editor-status')?.dataset.state !== 'loading' ''')
    if heading is not None:
        page.wait_for_function('(heading)=>document.querySelector("[name=heading]").value===heading', arg=heading)


def stored(page, heading):
    page.wait_for_function('''heading => Object.keys(localStorage).some(key => {
        if(!key.startsWith('azh-shop-recovery:v1:'))return false;
        try{return JSON.parse(localStorage.getItem(key)).design?.heading===heading;}catch{return false;}
    })''', arg=heading)


def has_copy(page, heading):
    return page.evaluate('''heading => Object.keys(localStorage).some(key => {
        if(!key.startsWith('azh-shop-recovery:v1:'))return false;
        try{return JSON.parse(localStorage.getItem(key)).design?.heading===heading;}catch{return false;}
    })''', heading)


def action(page, value):
    page.locator('[data-action="' + value + '"]').click()


def save_and_wait(page, heading):
    action(page, 'save')
    page.wait_for_function('''() => document.querySelector('#azh-shop-editor-status').textContent.includes('Draft saved')
        && !document.querySelector('#azh-shop-editor-fields').disabled''')
    assert page.locator('[name=heading]').input_value() == heading


def test_editor(browser, base):
    context = browser.new_context(viewport={'width': 1440, 'height': 1000})
    assert context.request.post(base + '_shop-fixture-login.php').status == 204
    page = open_editor(context, base, 'Saved private collection')
    errors = []
    context.on('page', lambda new: new.on('pageerror', lambda error: errors.append(str(error))))
    page.on('pageerror', lambda error: errors.append(str(error)))
    csrf = page.locator('#azh-shop-editor').get_attribute('data-csrf')
    baseline = fixture.api(context, base)
    initial = next(row for row in baseline['history'] if row['heading'] == 'Saved private collection')
    assert len(baseline['history']) == 3
    fixture.field(page, 'heading', 'Recovered private hero')
    fixture.field(page, 'heroImage', fixture.IMAGE_B)
    stored(page, 'Recovered private hero')
    assert fixture.api(context, base)['revision'] == baseline['revision'], 'Recovery changed server state'
    assert fixture.api(context, base)['published'] == baseline['published']
    page.close(run_before_unload=False)
    page = open_editor(context, base, 'Saved private collection')
    assert page.locator('#azh-shop-recovery').is_visible(), 'Reload did not offer unsaved recovery'
    action(page, 'recover')
    ready(page, 'Recovered private hero')
    fixture.until_preview(page, 'document.querySelector("#azh-hero-heading")?.textContent === "Recovered private hero"')
    assert fixture.api(context, base)['revision'] == baseline['revision'], 'Previewing recovery saved it'
    save_and_wait(page, 'Recovered private hero')
    saved = fixture.api(context, base)
    assert saved['revision'] == baseline['revision'] + 1 and saved['published'] == baseline['published']
    assert not has_copy(page, 'Recovered private hero'), 'Acknowledged save retained stale recovery'
    recovered_version = next(row for row in saved['history'] if row['heading'] == 'Recovered private hero')
    assert recovered_version['action'] == 'saveDraft'

    fixture.reveal(page.locator('[data-version-restore="' + initial['id'] + '"]')).click()
    page.wait_for_function('document.querySelector("#azh-shop-editor-status").textContent.includes("Version restored")')
    ready(page, 'Saved private collection')
    restored = fixture.api(context, base)
    assert restored['draft'] == baseline['draft'] and restored['published'] == baseline['published']
    assert restored['publishedRevision'] == baseline['publishedRevision'], 'Version restoration published'
    assert page.locator('[data-image-delete="' + fixture.IMAGE_B + '"]').is_disabled(), 'Retained version image is deletable'
    fixture.api(context, base, 'deleteImage', {'id': fixture.IMAGE_B}, csrf, expected=409)
    fixture.reveal(page.locator('[data-version-delete="' + recovered_version['id'] + '"]')).click()
    page.wait_for_function('document.querySelector("#azh-shop-editor-status").textContent.includes("Saved version removed")')
    assert not page.locator('[data-image-delete="' + fixture.IMAGE_B + '"]').is_disabled()
    assert fixture.api(context, base)['draft'] == baseline['draft'], 'Removing history changed saved draft'
    assert fixture.api(context, base)['revision'] == restored['revision'], 'Removing history changed revision'
    fixture.api(context, base, 'restoreVersion', {'id': recovered_version['id'], 'revision': restored['revision']}, csrf, expected=404)

    # Previously selected products may have been archived/deleted. Restore the
    # layout, preserve the eligible order and show the adjustment explicitly.
    older_curated = next(row for row in baseline['history'] if row['heading'] == 'Older curated design')
    fixture.reveal(page.locator('[data-version-restore="' + older_curated['id'] + '"]')).click()
    page.wait_for_function('document.querySelector("#azh-shop-editor-status").textContent.includes("2 unavailable featured products removed")')
    ready(page, 'Older curated design')
    adjusted = fixture.api(context, base)
    assert adjusted['draft']['featuredProductIds'] == [fixture.PRODUCT_A, 'demo-hoodie']
    assert adjusted['published'] == baseline['published'], 'Catalog-adjusted restoration published'
    assert adjusted['publishedRevision'] == baseline['publishedRevision']
    assert any(row['id'] == older_curated['id'] for row in adjusted['history']), 'Original version was removed'
    fixture.reveal(page.locator('[data-version-restore="' + initial['id'] + '"]')).click()
    page.wait_for_function('document.querySelector("#azh-shop-editor-status").textContent.includes("Version restored to the private draft")')
    ready(page, 'Saved private collection')

    # A newer server revision cannot be silently overwritten by recovered edits.
    fixture.field(page, 'heading', 'Older recovery awaiting decision')
    stored(page, 'Older recovery awaiting decision')
    current = fixture.api(context, base)
    newer = dict(current['draft']); newer['heading'] = 'Newer saved design'
    fixture.api(context, base, 'save', {'action': 'saveDraft', 'revision': current['revision'], 'design': newer}, csrf)
    page.reload(wait_until='domcontentloaded'); ready(page, 'Newer saved design')
    assert page.locator('#azh-shop-recovery').is_visible()
    action(page, 'recover'); ready(page, 'Older recovery awaiting decision')
    assert page.locator('[data-action=save]').is_disabled() and page.locator('[data-action=publish]').is_disabled()
    assert 'newer' in page.locator('#azh-shop-recovery-note').inner_text().lower() or 'revision' in page.locator('#azh-shop-recovery-note').inner_text()
    assert fixture.api(context, base)['draft']['heading'] == 'Newer saved design'
    action(page, 'rebaseRecovery')
    page.wait_for_function('document.querySelector("[data-action=save]").disabled===false')
    assert fixture.api(context, base)['draft']['heading'] == 'Newer saved design', 'Preparing recovery committed it'
    save_and_wait(page, 'Older recovery awaiting decision')
    assert fixture.api(context, base)['published'] == baseline['published']

    # Explicitly discarded browser recovery must not return after another reload.
    fixture.field(page, 'heading', 'Discarded recovery')
    stored(page, 'Discarded recovery')
    page.reload(wait_until='domcontentloaded'); ready(page, 'Older recovery awaiting decision')
    action(page, 'discardRecovery')
    page.reload(wait_until='domcontentloaded'); ready(page, 'Older recovery awaiting decision')
    assert not page.locator('#azh-shop-recovery').is_visible() and not has_copy(page, 'Discarded recovery')

    # Server reset also clears unsaved recovery after success.
    fixture.field(page, 'heading', 'Reset should clear recovery')
    stored(page, 'Reset should clear recovery')
    action(page, 'reset')
    page.wait_for_function('document.querySelector("#azh-shop-editor-status").textContent.includes("Published design restored")')
    assert not has_copy(page, 'Reset should clear recovery')

    # A shared browser account switch never displays the previous admin's copy.
    scope_one = page.locator('#azh-shop-editor').get_attribute('data-recovery-scope')
    fixture.field(page, 'heading', 'Only the first administrator')
    stored(page, 'Only the first administrator')
    assert context.request.post(base + '_shop-fixture-admin-two.php').status == 204
    page.goto(base + 'shop-homepage-editor.php', wait_until='domcontentloaded'); ready(page)
    assert page.locator('#azh-shop-editor').get_attribute('data-recovery-scope') != scope_one
    assert not page.locator('#azh-shop-recovery').is_visible()
    assert has_copy(page, 'Only the first administrator'), 'Account switch destroyed another account recovery'
    assert context.request.post(base + '_shop-fixture-login.php').status == 204
    page.goto(base + 'shop-homepage-editor.php', wait_until='domcontentloaded'); ready(page)
    assert page.locator('#azh-shop-recovery').is_visible()
    action(page, 'discardRecovery')

    # Real server role and CSRF enforcement covers both new mutation routes.
    current = fixture.api(context, base)
    history_id = current['history'][0]['id']
    for name in ['restoreVersion', 'deleteVersion']:
        fixture.api(context, base, name, {'id': history_id, 'revision': current['revision']}, expected=403)
    fan = browser.new_context()
    assert fan.request.post(base + '_shop-fixture-member.php').status == 204
    fixture.api(fan, base, expected=403)
    for name in ['restoreVersion', 'deleteVersion']:
        fixture.api(fan, base, name, {'id': history_id, 'revision': current['revision']}, csrf, expected=403)
    fan.close()
    assert fixture.api(context, base)['revision'] == current['revision']

    fixture.reveal(page.locator('#azh-homepage-history'))
    for width in [1440, 390, 320]:
        page.set_viewport_size({'width': width, 'height': 900})
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'), ('Editor overflow', width)
        page.locator('#azh-history-controls').scroll_into_view_if_needed()
        page.screenshot(path=str(OUT / ('history_' + str(width) + '.png')))
    assert errors == [], errors
    context.close()
    print('Real homepage browser recovery, stale recovery decision, history restore, account isolation and mobile layout: PASS', flush=True)


def storage_failure(browser, base):
    context = browser.new_context(viewport={'width': 390, 'height': 850})
    context.add_init_script("Storage.prototype.setItem=function(){throw new DOMException('Fixture quota exhausted','QuotaExceededError');}")
    assert context.request.post(base + '_shop-fixture-login.php').status == 204
    page = open_editor(context, base)
    assert 'unavailable' in page.locator('#azh-shop-recovery-status').inner_text()
    fixture.field(page, 'heading', 'Server save with browser storage unavailable')
    save_and_wait(page, 'Server save with browser storage unavailable')
    assert fixture.api(context, base)['draft']['heading'] == 'Server save with browser storage unavailable'
    assert 'unavailable' in page.locator('#azh-shop-recovery-status').inner_text(), 'Storage fault was hidden'
    page.screenshot(path=str(OUT / 'storage_unavailable_390.png'))
    context.close()
    print('Unavailable browser storage remains visible and actual server draft save works: PASS', flush=True)


def run():
    assert shutil.which('php'), 'PHP with PDO SQLite is required.'
    with tempfile.TemporaryDirectory(prefix='azh-homepage-history-') as temp:
        root = Path(temp); app = root / 'www'; seed(app); seed(app / 'studio')
        sessions = root / 'sessions'; sessions.mkdir()
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
        origin = 'http://127.0.0.1:' + str(port)
        with (OUT / 'php-server.log').open('w+') as log:
            server = subprocess.Popen(['php', '-d', 'session.save_path=' + str(sessions), '-S',
                                       '127.0.0.1:' + str(port), '-t', str(app)], stdout=log, stderr=log)
            try:
                deadline = time.monotonic() + 10
                while True:
                    try:
                        urllib.request.urlopen(origin + '/assets/favicon.svg', timeout=1).close(); break
                    except OSError:
                        if server.poll() is not None or time.monotonic() > deadline:
                            log.seek(0); raise RuntimeError('PHP fixture did not start: ' + log.read())
                        time.sleep(.05)
                with sync_playwright() as pw:
                    executable = next((shutil.which(name) for name in ['chromium', 'google-chrome', 'chromium-browser'] if shutil.which(name)), None)
                    browser = pw.chromium.launch(executable_path=executable, headless=True, args=['--no-sandbox'])
                    try:
                        test_editor(browser, origin + '/')
                        storage_failure(browser, origin + '/studio/')
                    except Exception as error:
                        (OUT / 'failure.json').write_text(json.dumps({'error': str(error)}, indent=2))
                        for ci, context in enumerate(browser.contexts):
                            for pi, page in enumerate(context.pages):
                                try:
                                    page.screenshot(path=str(OUT / ('failure_' + str(ci) + '_' + str(pi) + '.png')))
                                    (OUT / ('failure_' + str(ci) + '_' + str(pi) + '.html')).write_text(page.content())
                                except Exception:
                                    pass
                        raise
                    finally:
                        browser.close()
            finally:
                server.terminate()
                try: server.wait(timeout=5)
                except subprocess.TimeoutExpired: server.kill(); server.wait()
    print('Homepage v1.59 recovery and version history real browser acceptance: PASS')


if __name__ == '__main__':
    run()
