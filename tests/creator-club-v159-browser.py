#!/usr/bin/env python3
"""Real PHP/auth/CSRF Creator Club journeys, navigation and responsive acceptance."""
import importlib.util
import json
import os
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.request
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
OUT = Path(os.environ.get('RUNNER_TEMP', '/tmp')) / 'azh_club_v159_browser'
OUT.mkdir(parents=True, exist_ok=True)
spec = importlib.util.spec_from_file_location('shop_fixture', ROOT / 'tests/shop-hero-v1571-browser.py')
base_fixture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base_fixture)


def seed(app):
    result = subprocess.run(['php', str(ROOT / 'tests/fixtures/creator-club-v159.php'), str(app)], capture_output=True, text=True, timeout=60)
    if result.returncode:
        raise RuntimeError(result.stdout + result.stderr)
    return json.loads((app / 'storage/club-fixture.json').read_text())


def sql(app, statement, params=(), mutate=False):
    with sqlite3.connect(app / 'storage/interviews.sqlite') as db:
        db.row_factory = sqlite3.Row
        result = db.execute(statement, params)
        if mutate:
            db.commit()
            return None
        return [dict(row) for row in result.fetchall()]


def visit(page, base, path):
    response = page.goto(base + path, wait_until='networkidle', timeout=20000)
    assert response and response.status == 200, (path, response.status if response else None, page.content()[:1200])
    assert page.locator('#azh-side-navigation').count() == 1, ('duplicate navigation', path)
    assert page.locator('.azh-floating-sidebar').count() == 0, path
    assert page.locator('#azh-global-header').count() == 1, path
    assert page.locator('#azh-side-navigation [aria-current="page"]').count() == 1, ('missing active nav', path)


def submit(page, button):
    with page.expect_navigation(wait_until='networkidle', timeout=20000):
        button.click()
    expect(page.locator('.club-success')).to_have_text('Your change was saved.')


def api(page, base, fields, expected=200):
    token = page.locator('input[name="csrf"]').first.input_value()
    response = page.context.request.post(base + 'creator-club-action.php', form={'csrf': token, **fields}, headers={'Accept': 'application/json'})
    assert response.status == expected, (fields['action'], response.status, response.text())
    result = response.json()
    assert result['ok'] is (expected == 200), result
    return result


def run():
    assert shutil.which('php'), 'PHP with PDO SQLite is required.'
    with tempfile.TemporaryDirectory(prefix='azh-clubs-159-') as temp:
        root = Path(temp)
        app = root / 'www'
        base_fixture.copy_application(app)
        ids = seed(app)
        sessions = root / 'sessions'
        sessions.mkdir()
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        base = f'http://127.0.0.1:{port}/'
        with (OUT / 'php-server.log').open('w+') as log:
            server = subprocess.Popen(['php', '-d', 'session.save_path=' + str(sessions), '-S', f'127.0.0.1:{port}', '-t', str(app)], stdout=log, stderr=log)
            try:
                deadline = time.monotonic() + 10
                while True:
                    try:
                        urllib.request.urlopen(base + 'assets/favicon.svg', timeout=1).close()
                        break
                    except OSError:
                        if server.poll() is not None or time.monotonic() > deadline:
                            log.seek(0)
                            raise RuntimeError(log.read())
                        time.sleep(0.05)
                with sync_playwright() as pw:
                    executable = next((shutil.which(name) for name in ['chromium', 'google-chrome', 'chromium-browser'] if shutil.which(name)), None)
                    browser = pw.chromium.launch(executable_path=executable, headless=True, args=['--no-sandbox'])
                    contexts, pages, errors = {}, {}, []
                    try:
                        for role in ['fan', 'other_fan', 'model', 'admin', 'editor']:
                            context = browser.new_context(viewport={'width': 1440, 'height': 900})
                            assert context.request.post(base + '_club-fixture-login.php?role=' + role).status == 204
                            contexts[role] = context
                            page = context.new_page()
                            page.on('pageerror', lambda error, role=role: errors.append([role, str(error)]))
                            pages[role] = page
                        fan, other, model, admin = [pages[role] for role in ['fan', 'other_fan', 'model', 'admin']]

                        # Authenticated discovery and details use exactly one existing shell.
                        for role, page in pages.items():
                            visit(page, base, 'creator-clubs.php')
                            assert page.locator('#azh-side-navigation .azh-side-link[href="creator-club.php"]').count() == (1 if role == 'model' else 0)
                        visit(fan, base, 'creator-club.php?creator=2')
                        assert 'PRIVATE TIER TITLE' not in fan.content() and 'PRIVATE TIER BODY' not in fan.content()
                        assert 'A note for the community' not in fan.content()
                        assert fan.locator('#azh-aw-bell').is_visible()
                        submit(fan, fan.get_by_role('button', name='Follow for free', exact=True))
                        expect(fan.get_by_role('heading', name='A note for the community', exact=True)).to_be_visible()
                        assert 'PRIVATE TIER TITLE' not in fan.content(), 'Free follows never unlock a tier.'
                        visit(other, base, 'creator-club.php?creator=2')
                        assert 'A note for the community' not in other.content(), 'Another fan must not inherit following.'

                        # Membership state persists and test grants remain clearly disabled.
                        visit(fan, base, 'creator-club-workspace.php')
                        membership = fan.locator('[data-club-membership="2"]')
                        expect(membership).to_contain_text('Following for free', ignore_case=True)
                        membership.locator('summary').click()
                        expect(membership).to_contain_text('Test access disabled')
                        submit(fan, fan.get_by_role('button', name='Pause notifications', exact=True))
                        expect(fan.locator('[data-club-membership="2"]')).to_contain_text('Notifications paused')
                        submit(fan, fan.get_by_role('button', name='Enable notifications', exact=True))
                        expect(fan.locator('[data-club-membership="2"]')).to_contain_text('Notifications on')

                        # A real creator submits a free follower post, then staff approves that exact revision.
                        visit(model, base, 'creator-club.php')
                        model.locator('#club-new-post > summary').click()
                        form = model.locator('#club-new-post form')
                        expect(form.locator('[data-club-tier-field]')).to_be_hidden()
                        form.get_by_label('Post title', exact=True).fill('Fresh follower update')
                        form.get_by_label('Update', exact=True).fill('A new studio update for our free followers.')
                        form.locator('[name="rights_confirmed"]').check()
                        submit(model, form.get_by_role('button', name='Send post for staff review', exact=True))
                        post = sql(app, "SELECT * FROM creator_club_posts WHERE title='Fresh follower update'")[0]
                        assert post['state'] == 'pending' and post['audience'] == 'followers' and post['tier_id'] is None
                        visit(fan, base, 'creator-club.php?creator=2')
                        assert 'Fresh follower update' not in fan.content(), 'Pending text is private.'
                        visit(admin, base, 'creator-club-admin.php')
                        review = admin.locator('[data-club-review="' + post['id'] + '"]')
                        review.get_by_label('Feedback to creator').fill('Ready to share with free followers.')
                        submit(admin, review.get_by_role('button', name='Approve post', exact=True))
                        visit(fan, base, 'creator-club.php?creator=2')
                        expect(fan.get_by_role('heading', name='Fresh follower update', exact=True)).to_be_visible()

                        # Notifications share the existing bell, History and Brain with fan-safe data only.
                        state = fan.context.request.get(base + 'agent-workspace-api.php?action=state')
                        assert state.status == 200, state.text()
                        data = state.json()['data']
                        assert data['role'] == 'fan'
                        updates = [item for item in data['history'] if item['kind'] == 'commerce_club_post_published']
                        assert len(updates) == 1, data['history']
                        assert 'Fresh follower update' not in json.dumps(updates)
                        assert 'PRIVATE TIER' not in json.dumps(data)
                        assert not data['brain'].get('assignOptions')
                        assert 'pendingReviews' not in data['brain'].get('summary', {})
                        assert fan.context.request.get(base + 'agent-workspace-api.php?action=castingBriefing').status == 403
                        assert fan.context.request.get(base + 'commerce-api.php?action=state').status == 403
                        assert fan.context.request.get(base + 'agent-workspace-api.php?action=actionCenter').status == 403
                        fan.locator('#azh-aw-bell').click()
                        expect(fan.locator('#azh-aw-drawer')).to_be_visible()
                        expect(fan.locator('#azh-aw-content')).to_contain_text('New Creator Club update')
                        fan.locator('#azh-aw-tab-history').click()
                        expect(fan.locator('#azh-aw-content')).to_contain_text('Your recorded Creator Club activity')
                        fan.locator('#azh-aw-tab-brain').click()
                        expect(fan.locator('#azh-aw-content')).to_contain_text('Your club activity')
                        assert fan.locator('#azh-aw-content .azh-aw-assign').count() == 0
                        fan.keyboard.press('Escape')
                        expect(fan.locator('#azh-aw-drawer')).to_be_hidden()
                        other_data = other.context.request.get(base + 'agent-workspace-api.php?action=state').json()['data']
                        assert not [item for item in other_data['history'] if item['kind'] == 'commerce_club_post_published']

                        # Muted followers receive no new post event; repeated/stale review cannot notify twice.
                        visit(fan, base, 'creator-club-workspace.php')
                        submit(fan, fan.get_by_role('button', name='Pause notifications', exact=True))
                        api(model, base, {'action':'post','creator':'2','audience':'followers','tier_id':'','media_id':'','title':'Muted update','body':'Muted followers should not receive this alert.','rights_confirmed':'1'})
                        muted = sql(app, "SELECT * FROM creator_club_posts WHERE title='Muted update'")[0]
                        api(admin, base, {'action':'review','type':'post','id':muted['id'],'revision':str(muted['revision']),'decision':'approve','note':'Approved muted update.'})
                        api(admin, base, {'action':'review','type':'post','id':muted['id'],'revision':str(muted['revision']),'decision':'approve','note':'Repeated review.'},409)
                        after = fan.context.request.get(base + 'agent-workspace-api.php?action=state').json()['data']
                        assert len([item for item in after['history'] if item['kind'] == 'commerce_club_post_published']) == 1

                        # Creator editing, feedback and stale writes retain the current form text.
                        visit(model, base, 'creator-club.php')
                        model.locator('#club-new-tier > summary').click()
                        proposal = model.locator('#club-new-tier form')
                        proposal.get_by_label('Tier name', exact=True).fill('New backstage tier')
                        proposal.get_by_label('Benefits', exact=True).fill('Weekly creative notes.')
                        submit(model, proposal.get_by_role('button', name='Submit tier for review', exact=True))
                        tier = sql(app, "SELECT * FROM creator_club_tiers WHERE title='New backstage tier'")[0]
                        visit(admin, base, 'creator-club-admin.php')
                        review = admin.locator('[data-club-review="' + tier['id'] + '"]')
                        review.get_by_role('button', name='Request changes', exact=True).click()
                        expect(review.locator('[role="alert"]')).to_contain_text('Add a short note')
                        review.get_by_label('Feedback to creator').fill('Clarify how often members receive updates.')
                        submit(admin, review.get_by_role('button', name='Request changes', exact=True))
                        visit(model, base, 'creator-club.php')
                        card = model.locator('[data-club-tier="' + tier['id'] + '"]')
                        expect(card).to_contain_text('Clarify how often members receive updates.')
                        card.locator('summary').click()
                        edit = card.locator('form').first
                        edit.get_by_label('Tier name', exact=True).fill('My unsaved revision stays here')
                        current = sql(app, 'SELECT revision FROM creator_club_tiers WHERE id=?', (tier['id'],))[0]['revision']
                        api(model, base, {'action':'tier_update','creator':'2','id':tier['id'],'revision':str(current),'title':'Saved elsewhere','description':'Updated from another tab.','price':'15.50'})
                        edit.get_by_role('button', name='Resubmit tier for review', exact=True).click()
                        expect(edit.locator('[role="alert"]')).to_be_visible()
                        expect(edit.get_by_label('Tier name', exact=True)).to_have_value('My unsaved revision stays here')
                        assert sql(app, 'SELECT title,monthly_cents FROM creator_club_tiers WHERE id=?', (tier['id'],))[0] == {'title':'Saved elsewhere','monthly_cents':1550}
                        api(fan, base, {'action':'tier_update','creator':'2','id':tier['id'],'revision':'1','title':'Unauthorized','description':'','price':'10'},403)

                        # Archive hides new tier choices while preserving recorded access; restoration is reversible.
                        visit(model, base, 'creator-club.php')
                        approved = model.locator('[data-club-tier="' + ids['tier'] + '"]')
                        submit(model, approved.get_by_role('button', name='Archive tier', exact=True))
                        expect(model.locator('[data-club-tier="' + ids['tier'] + '"]')).to_contain_text('Archived')
                        visit(fan, base, 'creator-club.php?creator=2')
                        assert fan.locator('[data-club-tier="' + ids['tier'] + '"]').count() == 0
                        submit(model, model.locator('[data-club-tier="' + ids['tier'] + '"]').get_by_role('button', name='Restore tier', exact=True))

                        # Revocation is a deliberate admin action, with history retained and no test-grant activation.
                        visit(admin, base, 'creator-club-admin.php')
                        access = admin.locator('[data-club-access="' + ids['grant'] + '"]')
                        access.locator('summary').click()
                        access.locator('[name="confirmed"]').check()
                        submit(admin, access.get_by_role('button', name='Revoke access', exact=True))
                        assert sql(app, 'SELECT revoked_at FROM creator_club_entitlements WHERE id=?', (ids['grant'],))[0]['revoked_at']
                        visit(fan, base, 'creator-club-workspace.php')
                        fan.locator('[data-club-membership="2"] summary').click()
                        expect(fan.locator('[data-club-membership="2"]')).to_contain_text('Revoked')
                        assert contexts['fan'].request.get(base + 'creator-club-admin.php', max_redirects=0).status == 403

                        # Revoked/withdrawn content disappears even though the generic old alert remains in history.
                        visit(model, base, 'creator-club.php')
                        submit(model, model.locator('[data-club-post="' + post['id'] + '"]').get_by_role('button', name='Withdraw post', exact=True))
                        visit(fan, base, 'creator-club.php?creator=2')
                        assert 'Fresh follower update' not in fan.content()
                        assert contexts['fan'].request.get(base + 'creator-club-media.php?post=' + post['id']).status == 404

                        # Role pages fit narrow screens, preserve one navigation and expose real controls.
                        for width in [1440,768,390,320]:
                            for role, path in [('fan','creator-club-workspace.php'),('model','creator-club.php'),('admin','creator-club-admin.php')]:
                                page = pages[role]
                                page.set_viewport_size({'width':width,'height':900})
                                visit(page, base, path)
                                assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'), ('horizontal overflow',role,width)
                                assert page.locator('.azh-side-navigation').evaluate('(node)=>!["auto","scroll"].includes(getComputedStyle(node).overflowY)')
                                if width <= 850:
                                    expect(page.locator('.azh-member-sidebar')).to_be_hidden()
                                    page.locator('#azh-member-menu-button').click()
                                    expect(page.locator('.azh-member-sidebar')).to_be_visible()
                                    page.keyboard.press('Escape')
                                    expect(page.locator('.azh-member-sidebar')).to_be_hidden()
                                page.screenshot(path=str(OUT / f'club_{role}_{width}.png'), full_page=True)

                        # Existing relationships can always be removed after a creator hides their profile.
                        sql(app, "UPDATE user_social_profiles SET visibility='private' WHERE user_id=2", mutate=True)
                        visit(fan, base, 'creator-club-workspace.php')
                        membership = fan.locator('[data-club-membership="2"]')
                        expect(membership).to_contain_text('Unavailable creator')
                        submit(fan, membership.get_by_role('button', name='Unfollow', exact=True))
                        assert not sql(app, 'SELECT 1 FROM creator_club_follows WHERE fan_id=4 AND creator_id=2')
                        expect(fan.locator('[data-club-membership="2"]')).to_contain_text('Access history', ignore_case=True)
                        assert not errors, errors
                        print('Creator Club real PHP journeys, scoped access, review history, fan notifications, stale forms, and responsive UI: PASS', flush=True)
                    except Exception:
                        for role, page in pages.items():
                            try:
                                page.screenshot(path=str(OUT / f'failure_{role}.png'), full_page=True)
                                (OUT / f'failure_{role}.html').write_text(page.content())
                            except Exception:
                                pass
                        (OUT / 'browser-errors.json').write_text(json.dumps(errors, indent=2))
                        raise
                    finally:
                        for context in contexts.values():
                            context.close()
                        browser.close()
            finally:
                server.terminate()
                try:
                    server.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait()


if __name__ == '__main__':
    run()
