#!/usr/bin/env python3
"""Exercise the actual PHP-rendered admin menu with real CSS and JS in Chromium."""
import os
import re
import shutil
import subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
OUT = Path(os.environ.get('RUNNER_TEMP', '/tmp')) / 'azh_admin_navigation_v1571'
OUT.mkdir(parents=True, exist_ok=True)


def fixture(page='shop-homepage-editor', role='admin'):
    html = subprocess.run(['php', str(ROOT / 'tests/admin-navigation-v1571.php'), '--render', page, role], check=True, capture_output=True, text=True).stdout
    # Run only the shared navigation behavior; notifications and live agent
    # requests are unrelated to this layout regression and have no fixture data.
    return re.sub(r'<script\b[^>]*>.*?</script>', '', html, flags=re.S)


def run():
    assert shutil.which('php'), 'PHP is required to test the real shared-shell output.'
    with sync_playwright() as pw:
        executable = next((shutil.which(name) for name in ['chromium', 'google-chrome', 'chromium-browser'] if shutil.which(name)), None)
        browser = pw.chromium.launch(executable_path=executable, headless=True, args=['--no-sandbox'])
        for width in [1440, 768, 390, 320]:
            page = browser.new_page(viewport={'width': width, 'height': 900})
            errors = []
            page.on('pageerror', lambda error: errors.append(str(error)))
            page.set_content(fixture(), wait_until='domcontentloaded')
            for asset in ['style.css', 'universal-header.css', 'member-layout.css']:
                page.add_style_tag(path=str(ROOT / 'assets' / asset))
            page.add_script_tag(path=str(ROOT / 'assets/member-layout.js'))
            groups = page.locator('.azh-side-group')
            assert groups.count() == 5
            assert page.locator('#azh-side-navigation').count() == 1
            if width <= 850:
                assert not page.locator('.azh-member-sidebar').is_visible()
                page.locator('#azh-member-menu-button').click()
                assert page.locator('.azh-member-sidebar').is_visible()
            assert page.locator('[data-side-group="commerce"]').get_attribute('open') is not None
            assert page.get_by_role('link', name='Shop Homepage Editor', exact=True).is_visible()
            account = page.locator('[data-side-group="account"] > summary')
            account.focus()
            page.keyboard.press('Enter')
            page.wait_for_function('document.querySelector("[data-side-group=account]").open && !document.querySelector("[data-side-group=commerce]").open')
            assert page.get_by_role('link', name='Manage accounts', exact=True).is_visible()
            assert not page.get_by_role('link', name='Shop Homepage Editor', exact=True).is_visible()
            # Space closes the focused native disclosure, then clicking another
            # summary opens it without relying on any application-side state.
            page.keyboard.press('Space')
            page.wait_for_function('!document.querySelector("[data-side-group=account]").open')
            page.locator('[data-side-group="content"] > summary').click()
            assert page.get_by_role('link', name='Live Director', exact=True).is_visible()
            assert page.locator('.azh-side-group[open]').count() == 1
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), ('horizontal overflow', width)
            assert page.locator('.azh-side-navigation').evaluate('(node) => !["auto","scroll"].includes(getComputedStyle(node).overflowY)'), 'Navigation should use the page scroll.'
            page.locator('[data-side-group="commerce"] > summary').click()
            page.screenshot(path=str(OUT / f'admin_navigation_{width}.png'), full_page=True)
            if width <= 850:
                page.keyboard.press('Escape')
                assert not page.locator('.azh-member-sidebar').is_visible()
                assert page.locator('#azh-member-menu-button').get_attribute('aria-expanded') == 'false'
            assert not errors, errors
            page.close()
            print('Actual admin navigation: keyboard, current category, roles and responsive layout PASS', width)

        # The basic disclosure controls work when JavaScript is disabled.
        context = browser.new_context(java_script_enabled=False, viewport={'width': 1440, 'height': 900})
        page = context.new_page()
        page.set_content(fixture(), wait_until='domcontentloaded')
        page.locator('[data-side-group="account"] > summary').click()
        assert page.locator('[data-side-group="account"]').get_attribute('open') is not None
        assert page.get_by_role('link', name='Manage accounts', exact=True).is_visible()
        context.close()
        browser.close()


if __name__ == '__main__':
    run()
