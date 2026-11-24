#!/usr/bin/env python3
"""Render the actual PHP storefront with external CSS and real HTTP image URLs.

The previous fixture inlined CSS and image data, masking CSS-variable URL
resolution. This regression uses isolated root/subdirectory installations and
the production template, bootstrap, stylesheets, and protected media endpoint.
"""
import os
import re
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.request
from pathlib import Path

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
HELPER = ROOT / 'tests/fixtures/shop-hero-v1571.php'
OUT = Path(os.environ.get('RUNNER_TEMP', '/tmp')) / 'azh_shop_v1571_browser'
OUT.mkdir(parents=True, exist_ok=True)
EXCLUDE = {'.git', '.github', 'tests', 'scripts', 'node_modules', 'vendor', 'dist', 'storage', '__pycache__'}


def fixture(app, mode):
    result = subprocess.run(['php', str(HELPER), str(app), mode], capture_output=True, text=True, timeout=60)
    if result.returncode:
        raise RuntimeError('PHP renderer fixture failed: ' + result.stdout + result.stderr)


def copy_application(destination):
    shutil.copytree(ROOT, destination, ignore=shutil.ignore_patterns(*EXCLUDE))
    (destination / '.shop-hero-test-copy').write_text('Isolated renderer acceptance fixture\n')
    fixture(destination, 'init')


def image_url(page, selector, pseudo=None):
    value = page.locator(selector).evaluate('(el,pseudo) => getComputedStyle(el,pseudo).backgroundImage', pseudo)
    match = re.fullmatch(r'url\(["\']?(.*?)["\']?\)', value)
    assert match, ('Missing background image', selector, pseudo, value)
    return match.group(1)


def assert_image_decodes(page, actual, expected):
    assert actual == expected, ('Image URL must be relative to the installation, not the CSS directory', actual, expected)
    dimensions = page.evaluate('''async src => {
        const image = new Image();
        image.src = src;
        await image.decode();
        return [image.naturalWidth,image.naturalHeight];
    }''', actual)
    assert dimensions[0] >= 600 and dimensions[1] >= 280, ('Image did not decode', actual, dimensions)


def check_view(browser, origin, prefix, mode, preview):
    context = browser.new_context(viewport={'width': 1440, 'height': 900})
    base = origin + prefix
    if preview:
        response = context.request.post(base + '_shop-fixture-login.php')
        assert response.status == 204, ('Fixture sign-in failed', response.status, response.text())
    page = context.new_page()
    errors, requests, failed_images = [], [], []
    page.on('pageerror', lambda error: errors.append(str(error)))
    page.on('request', lambda request: requests.append(request.url))
    page.on('response', lambda response: failed_images.append((response.url, response.status))
            if response.request.resource_type == 'image' and response.status >= 400 else None)
    response = page.goto(base + 'shop.php' + ('?preview=1' if preview else ''), wait_until='networkidle', timeout=15000)
    assert response and response.status == 200, ('Shop render failed', response.status if response else None, page.content()[:1000])
    assert page.locator('body').get_attribute('data-shop-preview') == ('1' if preview else '0')
    assert page.locator('#azh-hero-heading').inner_text() == ('Private draft collection' if preview else 'Discover your next')
    assert any('/assets/shop-v157.css' in url for url in requests), 'Use the real external stylesheet'
    expected_hero = base + ('assets/shop-editorial-hero-v157.webp' if mode == 'default'
                            else 'shop-homepage-media.php?id=' + ('c' if preview else 'a') * 32)
    for width in [1440, 600, 390]:
        page.set_viewport_size({'width': width, 'height': 900})
        assert page.locator('[data-section="hero"]').is_visible()
        assert_image_decodes(page, image_url(page, '[data-section="hero"]'), expected_hero)
        if mode == 'uploaded':
            assert_image_decodes(page, image_url(page, '#azh-shop', '::before'),
                                 base + 'shop-homepage-media.php?id=' + 'b' * 32)
        else:
            assert page.locator('#azh-shop').evaluate('(el) => getComputedStyle(el,"::before").backgroundImage') == 'none'
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'), ('Horizontal overflow', prefix, mode, preview, width)
        if mode == 'default':
            name = ('root' if prefix == '/' else 'studio') + ('_draft' if preview else '_public')
            page.screenshot(path=str(OUT / f'hero_{name}_{width}.png'), full_page=False)
    assert not [url for url in requests if '/assets/assets/' in url or '/assets/shop-homepage-media.php' in url], requests
    assert not failed_images, ('Failed image requests', failed_images)
    assert not errors, ('Browser errors', errors)
    if mode == 'uploaded' and not preview:
        private_image = context.request.get(base + 'shop-homepage-media.php?id=' + 'c' * 32)
        assert private_image.status == 404, 'Anonymous visitors must not see the draft-only image'
    context.close()
    print('Actual PHP hero/image URL acceptance PASS', prefix, mode, 'draft' if preview else 'public', flush=True)


def run():
    if not shutil.which('php'):
        raise RuntimeError('PHP with PDO SQLite is required for the real storefront renderer test.')
    with tempfile.TemporaryDirectory(prefix='azh-shop-hero-') as temp:
        root = Path(temp)
        app = root / 'www'
        copy_application(app)
        copy_application(app / 'studio')
        sessions = root / 'sessions'
        sessions.mkdir()
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        origin = f'http://127.0.0.1:{port}'
        with (OUT / 'php-server.log').open('w+') as log:
            server = subprocess.Popen(['php', '-d', 'session.save_path=' + str(sessions), '-S',
                                       f'127.0.0.1:{port}', '-t', str(app)], stdout=log, stderr=log)
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
                    executable = next((shutil.which(name) for name in ['chromium', 'google-chrome', 'chromium-browser'] if shutil.which(name)), None)
                    browser = pw.chromium.launch(executable_path=executable,
                                                 headless=True, args=['--no-sandbox'])
                    for prefix, installation in [('/', app), ('/studio/', app / 'studio')]:
                        for mode in ['default', 'uploaded']:
                            fixture(installation, mode)
                            for preview in [False, True]:
                                check_view(browser, origin, prefix, mode, preview)
                    browser.close()
            finally:
                server.terminate()
                try:
                    server.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait()
    print('Root/subdirectory, public/draft, default/uploaded hero and page background: PASS')


if __name__ == '__main__':
    run()
