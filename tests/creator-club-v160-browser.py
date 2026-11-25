#!/usr/bin/env python3
"""Real PHP sessions, forms, CSRF, scheduled publishing and protected media acceptance."""
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

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[1]
OUT = Path(os.environ.get('RUNNER_TEMP', '/tmp')) / 'azh_club_v160_browser'
OUT.mkdir(parents=True, exist_ok=True)
spec = importlib.util.spec_from_file_location('club_v159_acceptance', ROOT / 'tests/creator-club-v159-browser.py')
previous = importlib.util.module_from_spec(spec)
spec.loader.exec_module(previous)
sql, visit, submit, api = previous.sql, previous.visit, previous.submit, previous.api
PRIVATE_BYTES = b'AZH-V160-PRIVATE-MEDIA-0123456789abcdefghijklmnopqrstuvwxyz\n'


def fixture(app, mode='init'):
    result = subprocess.run(['php', str(ROOT / 'tests/fixtures/creator-club-v160.php'), str(app), mode],
                            capture_output=True, text=True, timeout=60)
    if result.returncode:
        raise RuntimeError(result.stdout + result.stderr)
    return json.loads(result.stdout) if mode == 'due' else result.stdout


def navigate(page, target):
    with page.expect_navigation(wait_until='networkidle', timeout=20000):
        target.click()


def card_ids(page, attribute='data-club-post'):
    return page.locator('[' + attribute + ']').evaluate_all('(nodes, key) => nodes.map(node => node.getAttribute(key))', attribute)


def collect_pages(page, label, attribute='data-club-post'):
    seen, urls = [], set()
    for _ in range(30):
        assert page.url not in urls, ('Cursor pagination repeated a URL', page.url)
        urls.add(page.url)
        current = card_ids(page, attribute)
        assert len(current) == len(set(current)), ('Duplicate cards in one page', current)
        assert not set(seen).intersection(current), ('Cursor pagination repeated an item', current)
        seen.extend(current)
        assert 'SECRET_CROWDED_' not in page.content(), 'Hidden posts must not leak on any page.'
        more = page.get_by_role('link', name=label, exact=True)
        if not more.count():
            return seen
        navigate(page, more)
    raise AssertionError('Pagination did not terminate after 30 pages.')


def editor_for(page, post_id):
    card = page.locator('[data-club-post="' + post_id + '"]')
    card.locator('details > summary').first.click()
    return card.locator('form[data-club-editor]')


def assert_private_media(context, base, post_id, allowed):
    url = base + 'creator-club-media.php?post=' + post_id
    for method, headers in [('GET', {}), ('HEAD', {}), ('GET', {'Range': 'bytes=0-7'}), ('HEAD', {'Range': 'bytes=0-7'})]:
        response = context.request.fetch(url, method=method, headers=headers)
        expected = (206 if headers else 200) if allowed else 404
        assert response.status == expected, (post_id, method, headers, response.status, response.text())
        if allowed:
            assert 'no-store' in response.headers.get('cache-control', '')
            assert response.headers.get('accept-ranges') == 'bytes'
            expected_bytes = PRIVATE_BYTES[:8] if headers else PRIVATE_BYTES
            assert int(response.headers['content-length']) == len(expected_bytes)
            if headers:
                assert response.headers['content-range'] == 'bytes 0-7/' + str(len(PRIVATE_BYTES))
            assert response.body() == (b'' if method == 'HEAD' else expected_bytes)
        else:
            assert PRIVATE_BYTES not in response.body()
            assert 'content-range' not in response.headers, 'Denied requests must not disclose the file size.'


def assert_page_layout(page, role, width):
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'), ('Horizontal overflow', role, width)
    nested = page.evaluate('''() => [...document.querySelectorAll(
        '.azh-club,.club-section,.club-memberships,.azh-member-main,.azh-member-page,#azh-side-navigation'
    )].filter(node => node.clientHeight > 0 && node.scrollHeight > node.clientHeight + 2
        && ['auto','scroll'].includes(getComputedStyle(node).overflowY))
        .map(node => node.id || node.className)''')
    assert not nested, ('Nested page scroll container', role, width, nested)


def run():
    assert shutil.which('php'), 'PHP with PDO SQLite is required.'
    with tempfile.TemporaryDirectory(prefix='azh-clubs-160-') as temp:
        root = Path(temp)
        app = root / 'www'
        previous.base_fixture.copy_application(app)
        fixture(app)
        ids = json.loads((app / 'storage/club-fixture-v160.json').read_text())
        sessions = root / 'sessions'
        sessions.mkdir()
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        base = f'http://127.0.0.1:{port}/'
        with (OUT / 'php-server.log').open('w+') as log:
            # This fixture rewrites config.php during the journey. The cli-server SAPI
            # otherwise caches the old flag even when opcache.enable_cli is disabled.
            server = subprocess.Popen(['php', '-d', 'opcache.enable=0', '-d', 'session.save_path=' + str(sessions), '-S',
                                       f'127.0.0.1:{port}', '-t', str(app)], stdout=log, stderr=log)
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
                        for role in ['fan', 'other_fan', 'model', 'other_model', 'admin', 'muted_fan']:
                            context = browser.new_context(viewport={'width': 1440, 'height': 900})
                            assert context.request.post(base + '_club-fixture-login.php?role=' + role).status == 204
                            contexts[role] = context
                            page = context.new_page()
                            page.on('pageerror', lambda error, role=role: errors.append([role, str(error)]))
                            pages[role] = page
                        fan, other, model, admin = [pages[role] for role in ['fan', 'other_fan', 'model', 'admin']]

                        # Discovery traverses the old eighty-profile cap, with actual public-name search.
                        visit(fan, base, 'creator-clubs.php')
                        discovered = collect_pages(fan, 'Load more creators', 'data-club-creator')
                        assert set(map(int, discovered)) == set(ids['discovery_creators'])
                        assert len(discovered) > 80 and '2' in discovered and '3' in discovered
                        visit(fan, base, 'creator-clubs.php')
                        navigate(fan, fan.get_by_role('link', name='Following', exact=True))
                        assert set(card_ids(fan, 'data-club-creator')) == {'2', '3'}
                        navigate(fan, fan.get_by_role('link', name='All creators', exact=True))
                        for query, expected_ids in [('Cactus Muse', ['3']), ('Copper cactus', ['3']), ('Other Creator', [])]:
                            fan.get_by_label('Search creators', exact=True).fill(query)
                            navigate(fan, fan.get_by_role('button', name='Search', exact=True))
                            assert card_ids(fan, 'data-club-creator') == expected_ids, (query, fan.content())
                        visit(fan, base, 'creator-clubs.php?q=Night+sky')
                        if fan.get_by_role('link', name='Load more creators', exact=True).count():
                            navigate(fan, fan.get_by_role('link', name='Load more creators', exact=True))
                            expect(fan.get_by_label('Search creators', exact=True)).to_have_value('Night sky')

                        # Combined feed and creator pages retrieve every eligible post, never hidden filler.
                        expected_posts = set(ids['archive_posts'] + [ids['follower_post'], ids['other_post']])
                        visit(fan, base, 'creator-club-workspace.php')
                        assert set(collect_pages(fan, 'Load older updates')) == expected_posts
                        visit(fan, base, 'creator-club.php?creator=2')
                        assert set(collect_pages(fan, 'Load older posts')) == set(ids['archive_posts'] + [ids['follower_post']])
                        assert sql(app, 'SELECT * FROM creator_club_post_reads') == [], 'Opening feeds, posts, or media is not marking read.'
                        assert_private_media(contexts['fan'], base, ids['follower_post'], True)
                        assert_private_media(contexts['other_fan'], base, ids['follower_post'], False)
                        assert_private_media(contexts['fan'], base, ids['tier_post'], False)

                        # Read/unread is explicit, persistent, account-owned, and usable with creator filtering.
                        visit(fan, base, 'creator-club-workspace.php')
                        selected = fan.locator('[data-club-post="' + ids['follower_post'] + '"]')
                        submit(fan, selected.get_by_role('button', name='Mark read', exact=True))
                        expect(fan.locator('[data-club-post="' + ids['follower_post'] + '"]')).to_have_attribute('data-club-read', 'true')
                        navigate(fan, fan.get_by_role('link', name='Unread', exact=False))
                        assert ids['follower_post'] not in card_ids(fan)
                        fan.reload(wait_until='networkidle')
                        assert ids['follower_post'] not in card_ids(fan)
                        navigate(fan, fan.get_by_role('link', name='All updates', exact=True))
                        submit(fan, fan.locator('[data-club-post="' + ids['follower_post'] + '"]').get_by_role('button', name='Mark unread', exact=True))
                        fan.get_by_label('Filter by creator', exact=True).select_option('3')
                        navigate(fan, fan.get_by_role('button', name='Apply filter', exact=True))
                        assert card_ids(fan) == [ids['other_post']]
                        submit(fan, fan.locator('[data-club-post="' + ids['other_post'] + '"]').get_by_role('button', name='Mark read', exact=True))
                        expect(fan.get_by_label('Filter by creator', exact=True)).to_have_value('3')
                        visit(other, base, 'creator-club-workspace.php')
                        assert card_ids(other) == []
                        api(other, base, {'action': 'readPost', 'postId': ids['follower_post'], 'read': '1', 'returnTo': 'mine'}, 404)
                        api(fan, base, {'action': 'readPost', 'postId': ids['tier_post'], 'read': '1', 'returnTo': 'mine'}, 404)
                        denied_csrf = fan.context.request.post(base + 'creator-club-action.php', form={'action': 'readPost', 'postId': ids['other_post'], 'read': '0'}, headers={'Accept': 'application/json'})
                        assert denied_csrf.status == 403
                        assert sql(app, 'SELECT user_id,post_id FROM creator_club_post_reads') == [{'user_id': 4, 'post_id': ids['other_post']}]

                        # Save an actually incomplete draft through the UI; preview has no server write.
                        visit(model, base, 'creator-club.php')
                        before_review_notices = len(sql(app, "SELECT event_key FROM commerce_activity WHERE kind='club_review_needed'"))
                        expect(model.locator('[data-club-publishing-status]')).to_have_attribute('data-club-publishing-status', 'not_run')
                        model.locator('#club-new-post > summary').click()
                        form = model.locator('#club-new-post form[data-club-editor]')
                        expect(form.get_by_label('Post title', exact=True)).to_have_value('')
                        submit(model, form.get_by_role('button', name='Save draft', exact=True))
                        draft = sql(app, "SELECT * FROM creator_club_posts WHERE state='draft'")[0]
                        assert draft['title'] == '' and draft['body'] == '' and draft['rights_confirmed'] == 0
                        assert draft['published_at'] is None
                        assert len(sql(app, "SELECT event_key FROM commerce_activity WHERE kind='club_review_needed'")) == before_review_notices
                        form = editor_for(model, draft['id'])
                        form.get_by_label('Post title', exact=True).fill('Scheduled follower premiere')
                        form.get_by_label('Update', exact=True).fill('A private scheduled update that requires staff approval.')
                        before_preview = sql(app, 'SELECT * FROM creator_club_posts WHERE id=?', (draft['id'],))[0]
                        form.get_by_role('button', name='Preview', exact=True).click()
                        expect(form.get_by_role('region', name='Post preview', exact=True)).to_be_visible()
                        expect(form.locator('[data-preview-title]')).to_have_text('Scheduled follower premiere')
                        assert sql(app, 'SELECT * FROM creator_club_posts WHERE id=?', (draft['id'],))[0] == before_preview
                        form.get_by_role('button', name='Close preview', exact=True).click()
                        form.locator('[name="media_id"]').select_option(ids['media'])
                        form.get_by_label('Publish at (optional)', exact=True).fill('2030-07-01T12:00')
                        form.get_by_label('Time zone', exact=True).select_option('America/New_York')
                        form.locator('[name="rights_confirmed"]').check()
                        submit(model, form.get_by_role('button', name='Save draft', exact=True))
                        saved = sql(app, 'SELECT * FROM creator_club_posts WHERE id=?', (draft['id'],))[0]
                        assert saved['state'] == 'draft' and saved['publish_at'] == '2030-07-01T16:00:00Z'
                        assert saved['publish_timezone'] == 'America/New_York' and saved['published_at'] is None
                        assert len(sql(app, "SELECT event_key FROM commerce_activity WHERE kind='club_review_needed'")) == before_review_notices

                        # A stale tab retains title, body, schedule, timezone, audience and media selections.
                        form = editor_for(model, draft['id'])
                        form.get_by_label('Post title', exact=True).fill('Unsaved title survives a stale tab')
                        form.get_by_label('Update', exact=True).fill('My unsaved text stays in the editor.')
                        api(model, base, {'action': 'postSave', 'creator': '2', 'id': draft['id'], 'revision': str(saved['revision']), 'postIntent': 'draft', 'title': 'Saved from another tab', 'body': 'The server has a newer revision.', 'audience': 'followers', 'mediaId': ids['media'], 'rightsConfirmed': '1', 'publishLocal': '2030-07-01T12:00', 'publishTimezone': 'America/New_York'})
                        form.get_by_role('button', name='Save draft', exact=True).click()
                        expect(form.locator('[role="alert"]')).to_be_visible()
                        expect(form.get_by_label('Post title', exact=True)).to_have_value('Unsaved title survives a stale tab')
                        expect(form.get_by_label('Update', exact=True)).to_have_value('My unsaved text stays in the editor.')
                        expect(form.get_by_label('Publish at (optional)', exact=True)).to_have_value('2030-07-01T12:00')
                        expect(form.get_by_label('Time zone', exact=True)).to_have_value('America/New_York')
                        expect(form.locator('[name="media_id"]')).to_have_value(ids['media'])
                        expect(form.locator('[name="rights_confirmed"]')).to_be_checked()
                        assert sql(app, 'SELECT title FROM creator_club_posts WHERE id=?', (draft['id'],))[0]['title'] == 'Saved from another tab'

                        # The native POST fallback keeps unsaved fields and the stale revision when JS is unavailable.
                        native = browser.new_context(java_script_enabled=False, viewport={'width': 1440, 'height': 900})
                        native_page = native.new_page()
                        try:
                            assert native.request.post(base + '_club-fixture-login.php?role=model').status == 204
                            visit(native_page, base, 'creator-club.php')
                            native_form = editor_for(native_page, draft['id'])
                            original_revision = native_form.locator('[name="revision"]').input_value()
                            native_form.get_by_label('Post title', exact=True).fill('Native unsaved title stays here')
                            native_form.get_by_label('Update', exact=True).fill('Native unsaved body stays here.')
                            api(model, base, {'action': 'postSave', 'creator': '2', 'id': draft['id'], 'revision': original_revision, 'postIntent': 'draft', 'title': 'Saved while native form was open', 'body': 'The durable draft is newer.', 'audience': 'followers', 'mediaId': ids['media'], 'rightsConfirmed': '1', 'publishLocal': '2030-07-01T12:00', 'publishTimezone': 'America/New_York'})
                            with native_page.expect_navigation(wait_until='networkidle', timeout=20000) as navigation:
                                native_form.get_by_role('button', name='Save draft', exact=True).click()
                            assert navigation.value.status == 409
                            recovery = native_page.locator('form[data-club-editor]')
                            expect(recovery.get_by_label('Post title', exact=True)).to_have_value('Native unsaved title stays here')
                            expect(recovery.get_by_label('Update', exact=True)).to_have_value('Native unsaved body stays here.')
                            expect(recovery.get_by_label('Publish at (optional)', exact=True)).to_have_value('2030-07-01T12:00')
                            expect(recovery.get_by_label('Time zone', exact=True)).to_have_value('America/New_York')
                            expect(recovery.locator('[name="revision"]')).to_have_value(original_revision)
                            durable = sql(app, 'SELECT title,revision FROM creator_club_posts WHERE id=?', (draft['id'],))[0]
                            assert durable == {'title': 'Saved while native form was open', 'revision': int(original_revision) + 1}
                        except Exception:
                            native_page.screenshot(path=str(OUT / 'failure_native.png'), full_page=True)
                            (OUT / 'failure_native.html').write_text(native_page.content())
                            raise
                        finally:
                            native.close()

                        # Explicit submit and exact-revision staff approval schedule, without publishing.
                        visit(model, base, 'creator-club.php')
                        form = editor_for(model, draft['id'])
                        form.get_by_label('Post title', exact=True).fill('Scheduled follower premiere')
                        form.get_by_label('Update', exact=True).fill('A private scheduled update that requires staff approval.')
                        submit(model, form.get_by_role('button', name='Submit for review', exact=True))
                        pending = sql(app, 'SELECT * FROM creator_club_posts WHERE id=?', (draft['id'],))[0]
                        assert pending['state'] == 'pending'
                        visit(fan, base, 'creator-club-workspace.php')
                        assert draft['id'] not in card_ids(fan) and 'Scheduled follower premiere' not in fan.content()
                        api(fan, base, {'action': 'postSave', 'creator': '2', 'postIntent': 'draft', 'title': 'Forbidden fan draft'}, 403)
                        api(fan, base, {'action': 'publishDue'}, 403)
                        visit(pages['other_model'], base, 'creator-club.php')
                        api(pages['other_model'], base, {'action': 'postSave', 'creator': '3', 'id': draft['id'], 'revision': str(pending['revision']), 'postIntent': 'draft', 'title': 'Wrong owner'}, 404)
                        visit(admin, base, 'creator-club-admin.php')
                        review = admin.locator('[data-club-review="' + draft['id'] + '"]')
                        review.get_by_label('Feedback to creator').fill('Approved for the requested release time.')
                        submit(admin, review.get_by_role('button', name='Approve post', exact=True))
                        scheduled = sql(app, 'SELECT * FROM creator_club_posts WHERE id=?', (draft['id'],))[0]
                        assert scheduled['state'] == 'scheduled' and scheduled['published_at'] is None
                        assert_private_media(contexts['fan'], base, draft['id'], False)
                        submit(admin, admin.get_by_role('button', name='Publish due posts now', exact=True))
                        assert sql(app, "SELECT completed_at FROM creator_club_publish_runs WHERE scope='cron'")[0]['completed_at'] is None
                        assert sql(app, "SELECT completed_at FROM creator_club_publish_runs WHERE scope='manual'")[0]['completed_at']
                        assert admin.context.request.get(base + 'includes/creator-club-cron.php').status in [403, 404]

                        # Move only the fixture clock boundary, then execute the real private due job twice.
                        before_alerts = sql(app, "SELECT COUNT(*) AS count FROM commerce_activity WHERE kind='club_post_published' AND recipient_id=4")[0]['count']
                        sql(app, "UPDATE creator_club_posts SET publish_at='2020-01-01T00:00:00Z' WHERE id=?", (draft['id'],), mutate=True)
                        visit(fan, base, 'creator-club-workspace.php')
                        assert draft['id'] not in card_ids(fan), 'Opening a fan feed must not run the publisher.'
                        assert fixture(app, 'due')['published'] == 1
                        assert fixture(app, 'due')['published'] == 0
                        published = sql(app, 'SELECT * FROM creator_club_posts WHERE id=?', (draft['id'],))[0]
                        assert published['state'] == 'approved' and published['published_at']
                        alerts = sql(app, "SELECT recipient_id,title,detail FROM commerce_activity WHERE kind='club_post_published'")
                        assert len([row for row in alerts if row['recipient_id'] == 4]) == before_alerts + 1
                        assert not [row for row in alerts if row['recipient_id'] in [5, 7]]
                        assert 'Scheduled follower premiere' not in json.dumps(alerts)
                        visit(fan, base, 'creator-club-workspace.php')
                        expect(fan.locator('[data-club-post="' + draft['id'] + '"]')).to_contain_text('Scheduled follower premiere')
                        state = fan.context.request.get(base + 'agent-workspace-api.php?action=state')
                        assert state.status == 200, state.text()
                        fan_state = state.json()['data']
                        updates = [item for item in fan_state['history'] if item['kind'] == 'commerce_club_post_published']
                        assert len(updates) == before_alerts + 1, fan_state['history']
                        assert 'Scheduled follower premiere' not in json.dumps(updates)
                        fan.locator('#azh-aw-bell').click()
                        expect(fan.locator('#azh-aw-drawer')).to_be_visible()
                        expect(fan.locator('#azh-aw-content')).to_contain_text('New Creator Club update')
                        fan.keyboard.press('Escape')
                        expect(fan.locator('#azh-aw-drawer')).to_be_hidden()
                        assert_private_media(contexts['fan'], base, draft['id'], True)
                        assert_private_media(contexts['other_fan'], base, draft['id'], False)
                        submit(fan, fan.locator('[data-club-post="' + draft['id'] + '"]').get_by_role('button', name='Mark read', exact=True))
                        fresh = browser.new_context(viewport={'width': 390, 'height': 900})
                        try:
                            assert fresh.request.post(base + '_club-fixture-login.php?role=fan').status == 204
                            fresh_page = fresh.new_page()
                            visit(fresh_page, base, 'creator-club-workspace.php?view=unread')
                            assert draft['id'] not in card_ids(fresh_page), 'Read state must survive an entirely new browser context.'
                        finally:
                            fresh.close()

                        # An independently edited video must not inherit the old post approval.
                        media_revision = sql(app, 'SELECT revision FROM personal_media WHERE id=?', (ids['media'],))[0]['revision']
                        sql(app, 'UPDATE personal_media SET revision=revision+1 WHERE id=?', (ids['media'],), mutate=True)
                        visit(fan, base, 'creator-club-workspace.php')
                        assert draft['id'] not in card_ids(fan)
                        assert_private_media(contexts['fan'], base, draft['id'], False)
                        # Restore only this disposable fixture so the next checks isolate withdrawal/access changes.
                        sql(app, 'UPDATE personal_media SET revision=? WHERE id=?', (media_revision, ids['media']), mutate=True)

                        # Every media method rechecks withdrawal, suspended tiers, revoked access and accounts.
                        visit(model, base, 'creator-club.php')
                        submit(model, model.locator('[data-club-post="' + draft['id'] + '"]').get_by_role('button', name='Withdraw post', exact=True))
                        visit(fan, base, 'creator-club-workspace.php')
                        assert draft['id'] not in card_ids(fan)
                        assert_private_media(contexts['fan'], base, draft['id'], False)
                        fixture(app, 'test-grants-on')
                        assert_private_media(contexts['fan'], base, ids['tier_post'], True)
                        sql(app, "UPDATE creator_club_tiers SET state='suspended' WHERE id=?", (ids['tier'],), mutate=True)
                        visit(fan, base, 'creator-club-workspace.php')
                        assert ids['tier_post'] not in card_ids(fan)
                        assert_private_media(contexts['fan'], base, ids['tier_post'], False)
                        sql(app, "UPDATE creator_club_tiers SET state='approved' WHERE id=?", (ids['tier'],), mutate=True)
                        visit(admin, base, 'creator-club-admin.php')
                        access = admin.locator('[data-club-access="' + ids['grant'] + '"]')
                        access.locator('summary').click()
                        access.locator('[name="confirmed"]').check()
                        submit(admin, access.get_by_role('button', name='Revoke access', exact=True))
                        assert_private_media(contexts['fan'], base, ids['tier_post'], False)
                        visit(fan, base, 'creator-club-workspace.php')
                        assert ids['tier_post'] not in card_ids(fan)
                        fixture(app, 'test-grants-off')
                        sql(app, "UPDATE users SET status='disabled' WHERE id=2", mutate=True)
                        visit(fan, base, 'creator-club-workspace.php')
                        assert card_ids(fan) == [ids['other_post']]
                        assert_private_media(contexts['fan'], base, ids['follower_post'], False)
                        sql(app, "UPDATE users SET status='active' WHERE id=2", mutate=True)

                        # Billing remains disabled, and all role surfaces use one naturally scrolling page.
                        visit(fan, base, 'creator-club.php?creator=2')
                        billing_buttons = fan.get_by_role('button', name='Subscription checkout not available', exact=True)
                        assert billing_buttons.count() == 2
                        for button in billing_buttons.all():
                            expect(button).to_be_disabled()
                        api(fan, base, {'action': 'subscribe', 'creator': '2', 'tierId': ids['tier']}, 400)
                        for width in [320, 390, 768, 1440]:
                            for role, path in [('fan', 'creator-club-workspace.php'), ('fan', 'creator-clubs.php'), ('model', 'creator-club.php'), ('admin', 'creator-club-admin.php')]:
                                page = pages[role]
                                page.set_viewport_size({'width': width, 'height': 900})
                                visit(page, base, path)
                                if role == 'model':
                                    page.locator('#club-new-post > summary').click()
                                    form = page.locator('#club-new-post form[data-club-editor]')
                                    form.get_by_label('Post title', exact=True).fill('A preview at every viewport')
                                    form.get_by_role('button', name='Preview', exact=True).click()
                                    expect(form.get_by_role('region', name='Post preview', exact=True)).to_be_visible()
                                assert_page_layout(page, role, width)
                                if width <= 850:
                                    expect(page.locator('.azh-member-sidebar')).to_be_hidden()
                                    page.locator('#azh-member-menu-button').click()
                                    expect(page.locator('.azh-member-sidebar')).to_be_visible()
                                    page.keyboard.press('Escape')
                                    expect(page.locator('.azh-member-sidebar')).to_be_hidden()
                                name = 'discovery' if path == 'creator-clubs.php' else role
                                page.screenshot(path=str(OUT / f'club_{name}_{width}.png'), full_page=True)
                        assert not errors, errors
                        print('Creator Club v1.60 real journeys, pagination, persisted reads, scheduled publication, protected media and responsive layout: PASS', flush=True)
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
