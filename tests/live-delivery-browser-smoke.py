"""Chromium local-media regression: fan video must play with late audio and muted autoplay.
No production accounts, camera permissions, or uploads required.
Run: python tests/live-delivery-browser-smoke.py
"""
from __future__ import annotations
from pathlib import Path
from base64 import b64encode
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parent.parent
def run():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True, executable_path='/usr/bin/chromium', args=['--no-sandbox'])
        context = browser.new_context(viewport={'width': 960, 'height': 700})
        page = context.new_page()
        errors=[]
        page.on('pageerror', lambda error: errors.append(str(error)))
        module='data:text/javascript;base64,'+b64encode((ROOT/'assets/live-viewer-core.js').read_bytes()).decode('ascii')
        html=(ROOT/'tests/live-delivery-fixture.html').read_text()
        html=html.replace("'../assets/live-viewer-core.js'",repr(module))
        page.set_content(html,wait_until='load')
        page.wait_for_function('window.fixture?.done === true', timeout=15000)
        result=page.evaluate('window.fixture')
        assert not errors, errors
        assert result['sameStream'], result
        assert result['tracks']==2, result
        assert result['mutedPlayback']=='playing', result
        assert result['frames']>=5, result
        print('PASS: Chromium maintains video attachment after audio ontrack; muted playback decoded', result['frames'], 'frames')
        context.close();browser.close()
if __name__=='__main__': run()
