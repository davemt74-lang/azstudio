#!/usr/bin/env python3
"""Public video and cover regressions against a disposable PHP HTTP server.

Run: python3 tests/publication_integration.py --php /path/to/php
Uses the installation/upload harness without rerunning its inherited cases.
Only synthetic media and fictional accounts are created; real storage is untouched.
"""
from __future__ import annotations

import argparse
import base64
import html
import json
import pathlib
import re
import shutil
import sqlite3
import struct
import subprocess
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
import zlib

import backend_integration as harness


PUBLIC_FIELDS = {
    'id', 'title', 'description', 'modelName', 'publishedAt', 'updatedAt',
    'durationMs', 'mimeType', 'watchUrl', 'mediaUrl', 'coverUrl',
}
PRIVATE_SENTINEL = 'PRIVATE-CASTING-AND-EDIT-SENTINEL'
JPEG = base64.b64decode(
    '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwg'
    'JC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIy'
    'MjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAACAAIDASIAAhEBAxEB'
    '/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQR'
    'BRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdI'
    'SUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2'
    't7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEB'
    'AQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEI'
    'FEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2'
    'hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU'
    '1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDGooorpPvT/9k='
)
WEBP = base64.b64decode('UklGRjgAAABXRUJQVlA4ICwAAABwAQCdASoCAAIAAUAmJaACdAF1AAD+7U3cCStWbp/9pZ//2ln//aWf4qAAAA==')


def png(width=2, height=2, rgb=(231, 81, 153), header_only=False):
    """Generate a valid tiny PNG, or a dimension-validation header fixture."""
    def chunk(kind, data):
        return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data) & 0xffffffff)
    header = chunk(b'IHDR', struct.pack('>IIBBBBB', width, height, 8, 2, 0, 0, 0))
    data = b'\x00' + bytes(rgb) if header_only else b''.join(b'\x00' + bytes(rgb) * width for _ in range(height))
    return b'\x89PNG\r\n\x1a\n' + header + chunk(b'IDAT', zlib.compress(data)) + chunk(b'IEND', b'')


PNG = png()


class PublicationIntegration(harness.BackendIntegration):
    member_serial = 0

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        source = pathlib.Path(__file__).resolve().parents[1]
        for name in ['content-api.php', 'public-media.php', 'watch.php']:
            shutil.copy2(source / name, cls.root / name)
        cls.anonymous = harness.Client(cls.base)

    @classmethod
    def start_server(cls):
        # Exceed the application cover limit so its 5MiB validation is exercised,
        # rather than only testing PHP's multipart size rejection.
        cls.server = subprocess.Popen([
            cls.php, '-d', 'upload_max_filesize=8M', '-d', 'post_max_size=10M',
            '-d', 'session.save_path=' + str(cls.root / 'sessions'),
            '-d', 'display_errors=0', '-S', f'127.0.0.1:{cls.port}', '-t', str(cls.root),
        ], stdout=cls.log, stderr=cls.log)
        for _ in range(100):
            try:
                with urllib.request.urlopen(cls.base + '/index.php', timeout=0.3):
                    return
            except (OSError, urllib.error.URLError):
                if cls.server.poll() is not None:
                    raise AssertionError('Temporary publication server failed to start.')
                time.sleep(0.03)
        raise AssertionError('Temporary publication server did not become ready.')

    def result(self, action, data=None, query=None, expected=200, client=None, csrf=True):
        status, response = (client or self.client).api(action, data, query, csrf=csrf)
        self.assertEqual(status, expected, response)
        return response['data'] if expected < 400 else response

    def public(self, action='feed', query=None, expected=200, client=None):
        route = 'content-api.php?' + urllib.parse.urlencode({'action': action, **(query or {})})
        status, _, body = (client or self.anonymous).request(route)
        try:
            response = json.loads(body)
        except ValueError as error:
            raise AssertionError(f'Invalid public JSON ({status}): {body[:300]!r}') from error
        self.assertEqual(status, expected, response)
        self.assertEqual(response['ok'], expected < 400)
        return response['data'] if expected < 400 else response

    def watch(self, query=None, expected=200):
        route = 'watch.php' + ('?' + urllib.parse.urlencode(query) if query else '')
        status, headers, body = self.anonymous.request(route)
        self.assertEqual(status, expected, body[:500])
        headers = {name.lower(): value for name, value in headers.items()}
        self.assertIn('text/html', headers['content-type'])
        self.assertEqual(headers['x-content-type-options'], 'nosniff')
        self.assertIn('no-store', headers['cache-control'])
        return body.decode()

    def account_request(self, action, data=None, query=None, client=None, expected=200):
        client = client or self.client
        route = 'accounts-api.php?' + urllib.parse.urlencode({'action': action, **(query or {})})
        headers = {'Accept': 'application/json'}
        payload = None
        if data is not None:
            payload = json.dumps(data).encode()
            headers.update({'Content-Type': 'application/json', 'X-CSRF-Token': client.csrf})
        status, _, body = client.request(route, payload, headers)
        response = json.loads(body)
        self.assertEqual(status, expected, response)
        return response['data'] if expected < 400 else response

    def new_member(self, roles):
        cls = type(self)
        cls.member_serial += 1
        email = f'publication-{cls.member_serial}@example.invalid'
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            password_hash = db.execute('SELECT password_hash FROM users WHERE id=1').fetchone()[0]
            cursor = db.execute('INSERT INTO users (name,email,password_hash,created_at) VALUES (?,?,?,?)',
                                ['Fictional publication member', email, password_hash, '2026-01-01T00:00:00Z'])
            user_id = cursor.lastrowid
            db.executemany('INSERT INTO user_roles (user_id,role) VALUES (?,?)', [(user_id, role) for role in roles])
        client = harness.Client(self.base)
        status, _, page = client.request('login.php')
        self.assertEqual(status, 200)
        token = re.search(rb'name="csrf" value="([a-f0-9]+)"', page)[1].decode()
        status, _, body = client.form('login.php', {'csrf': token, 'email': email, 'password': self.password})
        self.assertEqual(status, 303, body[:300])
        client.csrf = self.account_request('bootstrap', client=client)['csrf']
        return user_id, client

    def finished(self, client=None, interview=None, **extra):
        client = client or self.client
        if interview is None:
            interview = self.result('createInterview', {
                'title': 'Private production interview', 'participantName': 'Public Stage Credit',
                'notes': PRIVATE_SENTINEL, 'state': {'responses': {'private': {'text': PRIVATE_SENTINEL}}},
            }, expected=201, client=client)
        content = (pathlib.Path(__file__).resolve().parents[1] / 'qa/fixtures/answer-test.webm').read_bytes()
        upload = self.result('beginUpload', {
            'interviewId': interview['id'], 'purpose': 'finished', 'title': 'Public episode candidate',
            'description': 'An approved description', 'status': 'ready',
            'sourceEdit': {'privateCastingNotes': PRIVATE_SENTINEL}, 'fileName': 'private-original-filename.webm',
            'mimeType': 'video/webm', 'durationMs': 6000, 'totalBytes': len(content), **extra,
        }, expected=201, client=client)
        for index, offset in enumerate(range(0, len(content), upload['chunkBytes'])):
            status, response = client.multipart('uploadChunk', {'uploadId': upload['uploadId'], 'index': index},
                                                'chunk', content[offset:offset + upload['chunkBytes']])
            self.assertEqual(status, 200, response)
        video = self.result('finishUpload', {'uploadId': upload['uploadId']}, client=client)['video']
        return interview, video, content

    def publish(self, video, published=True, client=None, expected=200):
        return self.result('publishVideo', {'id': video['id'], 'revision': video['revision'], 'published': published},
                           expected=expected, client=client)

    def upload_cover(self, video, content=PNG, file_name='cover.png', mime='image/png', client=None, expected=200):
        status, response = (client or self.client).multipart('uploadVideoCover',
            {'id': video['id'], 'revision': video['revision']}, 'cover', content, file_name, mime)
        self.assertEqual(status, expected, response)
        return response['data'] if expected < 400 else response

    def media_files(self):
        return set((self.root / 'storage/media').glob('*'))

    def assert_hidden(self, video):
        self.public('video', {'id': video['id']}, expected=404)
        page = self.watch({'id': video['id']}, expected=404)
        self.assertIn('This video is not available.', page)
        self.assertNotIn('<video ', page)
        self.assertNotIn(PRIVATE_SENTINEL, page)
        for suffix in ['', '&type=cover']:
            status, _, _ = self.anonymous.request('public-media.php?id=' + video['id'] + suffix)
            self.assertEqual(status, 404)
        self.assertNotIn(video['id'], [item['id'] for item in self.public()['videos']])

    def test_publication_01_explicit_publication_and_private_allowlist(self):
        empty = self.watch()
        self.assertIn('The next story is on its way.', empty)
        self.assertNotIn('class="watch-card"', empty)
        title = 'SSR public <script>alert("demo")</script> & Studio'
        description = '<img src=x onerror="alert(1)"> A & B.\nSecond public line.'
        interview, video, content = self.finished(status='draft', title=title, description=description)
        self.assertFalse(video['fanPublished'], 'An internal status is not consent to publish publicly')
        self.assertIsNone(video['coverUrl'])
        self.assert_hidden(video)
        video = self.result('updateVideo', {'id': video['id'], 'revision': video['revision'], 'status': 'published'})
        self.assert_hidden(video)
        video = self.publish(video)
        self.assertTrue(video['fanPublished'])
        # A UTC publication just after midnight must retain its Arizona local date.
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            db.execute('UPDATE finished_videos SET fan_published_at=? WHERE id=?',
                       ['2026-11-18T02:00:00Z', video['id']])
        public = self.public('video', {'id': video['id']})
        self.assertEqual(set(public), PUBLIC_FIELDS)
        self.assertEqual(public['title'], video['title'])
        self.assertEqual(public['modelName'], 'Public Stage Credit')
        self.assertIsNone(public['coverUrl'])
        serialized = json.dumps(public)
        for private in [PRIVATE_SENTINEL, interview['id'], 'private-original-filename.webm']:
            self.assertNotIn(private, serialized)
        matching = [item for item in self.public()['videos'] if item['id'] == video['id']]
        self.assertEqual(matching, [public])
        self.assertEqual(self.anonymous.request(public['mediaUrl'].lstrip('/'))[2], content)
        detail = self.watch({'id': video['id']})
        self.assertIn('<h1>' + html.escape(title) + '</h1>', detail)
        self.assertIn(html.escape(description).replace('\n', '<br />\n'), detail)
        self.assertIn('Nov 17, 2026', detail)
        self.assertIn('src="' + html.escape(public['mediaUrl']) + '"', detail)
        self.assertNotIn(' poster=', detail)
        self.assertNotIn('<script>alert("demo")</script>', detail)
        self.assertNotIn('<img src=x', detail)
        for private in [PRIVATE_SENTINEL, interview['id'], 'private-original-filename.webm', 'sourceEdit']:
            self.assertNotIn(private, detail)
        feed = self.watch({'search': 'SSR public'})
        self.assertIn('1 video for', feed)
        self.assertIn('href="' + html.escape(public['watchUrl']) + '"', feed)
        self.assertIn(html.escape(title), feed)
        self.assertNotIn(PRIVATE_SENTINEL, feed)
        no_match_query = '<script>alert("query")</script>'
        no_match = self.watch({'search': no_match_query})
        self.assertIn('No matches just yet.', no_match)
        self.assertIn(html.escape(no_match_query), no_match)
        self.assertNotIn(no_match_query, no_match)
        beyond_end = self.watch({'search': 'SSR public', 'page': 2})
        self.assertIn('Page 2', beyond_end)
        self.assertIn('href="watch.php?search=SSR+public&amp;page=1"', beyond_end)
        self.assertNotIn('class="watch-card"', beyond_end)

    def test_publication_02_snapshot_updates_only_on_explicit_publish(self):
        _, video, _ = self.finished(title='Approved public title', description='Approved public description')
        video = self.publish(video)
        original = self.public('video', {'id': video['id']})
        changed = self.result('updateVideo', {'id': video['id'], 'revision': video['revision'],
                                             'title': 'Unreviewed private edit', 'description': PRIVATE_SENTINEL})
        self.assertEqual(self.public('video', {'id': video['id']})['title'], original['title'])
        self.assertEqual(self.public('video', {'id': video['id']})['description'], original['description'])
        snapshot_page = self.watch({'id': video['id']})
        self.assertIn(original['title'], snapshot_page)
        self.assertIn(original['description'], snapshot_page)
        self.assertNotIn('Unreviewed private edit', snapshot_page)
        self.assertNotIn(PRIVATE_SENTINEL, snapshot_page)
        self.publish(video, expected=409)
        changed = self.publish(changed)
        public = self.public('video', {'id': video['id']})
        self.assertEqual(public['title'], changed['title'])
        self.assertEqual(public['description'], changed['description'])
        self.assertEqual(public['publishedAt'], original['publishedAt'])
        changed = self.publish(changed, False)
        self.assertFalse(changed['fanPublished'])
        self.assert_hidden(changed)
        changed = self.result('updateVideo', {'id': changed['id'], 'revision': changed['revision'], 'status': 'published'})
        self.assert_hidden(changed)

    def test_publication_03_auth_roles_ownership_csrf_and_raw_media(self):
        interview, video, _ = self.finished()
        take, _ = self.direct_take(interview['id'])
        _, other = self.new_member(['admin'])
        _, fan = self.new_member(['fan', 'customer'])
        body = {'id': video['id'], 'revision': video['revision'], 'published': True}
        self.result('publishVideo', body, client=self.anonymous, expected=401)
        self.result('publishVideo', body, client=fan, expected=403)
        self.result('publishVideo', body, client=other, expected=404)
        self.result('publishVideo', body, csrf=False, expected=403)
        self.upload_cover(video, client=other, expected=404)
        self.result('removeVideoCover', {'id': video['id'], 'revision': video['revision']}, client=other, expected=404)
        self.assertEqual(self.anonymous.request(video['mediaUrl'].lstrip('/'))[0], 401)
        self.assertEqual(fan.request(video['mediaUrl'].lstrip('/'))[0], 403)
        self.assertEqual(other.request(video['mediaUrl'].lstrip('/'))[0], 404)
        video = self.publish(video)
        self.assertEqual(self.public('video', {'id': video['id']}, client=fan)['id'], video['id'])
        for route, expected in [('public-media.php?id=' + take['id'], 404),
                                ('public-media.php?type=take&id=' + take['id'], 400),
                                ('public-media.php?type=cover&id=' + take['id'], 404)]:
            with self.subTest(route=route):
                self.assertEqual(self.anonymous.request(route)[0], expected)
        self.public('video', {'id': take['id']}, expected=404)
        self.assertEqual(self.anonymous.request(take['mediaUrl'].lstrip('/'))[0], 401)
        self.assertEqual(fan.request(take['mediaUrl'].lstrip('/'))[0], 403)

    def test_publication_04_public_media_ranges_heads_and_revocation(self):
        _, video, content = self.finished()
        video = self.publish(video)
        route = self.public('video', {'id': video['id']})['mediaUrl'].lstrip('/')
        status, headers, data = self.anonymous.request(route, headers={'Range': 'bytes=7-31'})
        self.assertEqual(status, 206); self.assertEqual(data, content[7:32])
        self.assertEqual(headers['Content-Type'], 'video/webm')
        self.assertEqual(headers['Content-Range'], f'bytes 7-31/{len(content)}')
        self.assertIn('no-store', headers['Cache-Control'])
        self.assertEqual(headers['X-Content-Type-Options'], 'nosniff')
        status, headers, data = self.anonymous.request(route, method='HEAD')
        self.assertEqual(status, 200); self.assertEqual(data, b'')
        self.assertEqual(headers['Content-Length'], str(len(content)))
        self.assertEqual(self.anonymous.request(route, headers={'Range': f'bytes={len(content)}-'})[0], 416)
        self.assertEqual(self.anonymous.request(route, data=b'')[0], 405)
        self.assertEqual(self.anonymous.request('content-api.php?action=feed', data=b'')[0], 405)
        self.publish(video, False)
        self.assertEqual(self.anonymous.request(route)[0], 404)
        self.watch({'id': video['id']}, expected=404)

    def test_publication_05_archive_and_restore_never_republish(self):
        for archive_parent in [False, True]:
            with self.subTest(archive_parent=archive_parent):
                interview, video, _ = self.finished()
                video = self.upload_cover(video)
                video = self.publish(video)
                action = 'archiveInterview' if archive_parent else 'archiveVideo'
                target = interview if archive_parent else video
                self.result(action, {'id': target['id'], 'archived': True})
                self.assert_hidden(video)
                current = self.result('video', query={'id': video['id']})
                self.assertFalse(current['fanPublished'])
                self.publish(current, expected=409)
                self.result(action, {'id': target['id'], 'archived': False})
                current = self.result('video', query={'id': video['id']})
                self.assertFalse(current['fanPublished'])
                self.assert_hidden(current)
                self.publish(current)
                self.public('video', {'id': video['id']})

    def test_publication_06_suspended_owner_is_hidden(self):
        owner_id, owner = self.new_member(['admin'])
        _, video, _ = self.finished(client=owner)
        video = self.upload_cover(video, client=owner)
        video = self.publish(video, client=owner)
        public = self.public('video', {'id': video['id']})
        self.assertEqual(self.anonymous.request(public['coverUrl'].lstrip('/'))[0], 200)
        account = self.account_request('account', query={'id': owner_id})
        suspended = self.account_request('updateAccount', {'id': owner_id, 'revision': account['revision'], 'status': 'suspended'})
        try:
            self.assert_hidden(video)
            self.assertEqual(self.anonymous.request(public['coverUrl'].lstrip('/'))[0], 404)
        finally:
            self.account_request('updateAccount', {'id': owner_id, 'revision': suspended['revision'], 'status': 'active'})

    def test_publication_07_cover_lifecycle_and_public_snapshot(self):
        _, video, _ = self.finished(title='Approved cover episode', description='Approved cover description')
        before = self.media_files()
        old_revision = video['revision']
        video = self.upload_cover(video)
        self.assertEqual(video['revision'], old_revision + 1)
        first_files = self.media_files() - before
        self.assertEqual(len(first_files), 1)
        private_url = video['coverUrl'].lstrip('/')
        status, headers, data = self.client.request(private_url)
        self.assertEqual(status, 200); self.assertEqual(headers['Content-Type'], 'image/png'); self.assertEqual(data, PNG)
        self.assertEqual(self.anonymous.request(private_url)[0], 401)
        _, other = self.new_member(['admin'])
        self.assertEqual(other.request(private_url)[0], 404)
        self.assert_hidden(video)
        video = self.publish(video)
        public = self.public('video', {'id': video['id']})
        self.assertEqual(set(public), PUBLIC_FIELDS)
        public_cover = public['coverUrl'].lstrip('/')
        self.assertTrue(public_cover.startswith('public-media.php?'))
        self.assertEqual(self.anonymous.request(public_cover)[2], PNG)
        page = self.watch({'id': video['id']})
        self.assertIn('poster="' + html.escape(public['coverUrl']) + '"', page)
        self.assertNotIn(PRIVATE_SENTINEL, page)
        video = self.result('updateVideo', {'id': video['id'], 'revision': video['revision'],
                                           'title': 'Private unsaved public title', 'description': PRIVATE_SENTINEL})
        replacement = png(rgb=(18, 147, 211))
        video = self.upload_cover(video, content=replacement)
        self.assertTrue(all(not path.exists() for path in first_files))
        public_after = self.public('video', {'id': video['id']})
        self.assertEqual(public_after['title'], public['title'])
        self.assertEqual(public_after['description'], public['description'])
        self.assertEqual(self.anonymous.request(public_after['coverUrl'].lstrip('/'))[2], replacement)
        replacement_page = self.watch({'id': video['id']})
        self.assertIn('poster="' + html.escape(public_after['coverUrl']) + '"', replacement_page)
        self.assertNotIn('Private unsaved public title', replacement_page)
        self.assertNotIn(PRIVATE_SENTINEL, replacement_page)
        replacement_files = self.media_files() - before
        self.assertEqual(len(replacement_files), 1)
        prior_revision = video['revision']
        video = self.result('removeVideoCover', {'id': video['id'], 'revision': video['revision']})
        self.assertEqual(video['revision'], prior_revision + 1)
        self.assertIsNone(video['coverUrl'])
        self.assertIsNone(self.public('video', {'id': video['id']})['coverUrl'])
        self.assertNotIn(' poster=', self.watch({'id': video['id']}))
        self.assertTrue(all(not path.exists() for path in replacement_files))
        self.assertEqual(self.anonymous.request(public_cover)[0], 404)
        self.assertEqual(self.client.request(private_url)[0], 404)

    def test_publication_08_invalid_cover_and_stale_edits_preserve_good_image(self):
        _, initial, _ = self.finished()
        video = self.upload_cover(initial)
        baseline_files = self.media_files()
        cover_url = video['coverUrl'].lstrip('/')
        invalid = [
            ('forged PNG', b'<svg onload="alert(1)"></svg>', 'fake.png', 'image/png', 415),
            ('truncated image', b'\x89PNG\r\n\x1a\n', 'truncated.png', 'image/png', 415),
            ('zero dimensions', png(0, 0, header_only=True), 'zero.png', 'image/png', 415),
            ('excess width', png(12001, 1, header_only=True), 'wide.png', 'image/png', 415),
            ('excess pixels', png(8000, 6000, header_only=True), 'pixels.png', 'image/png', 415),
            ('over 5MiB', PNG + bytes(5 * 1024 * 1024 + 1 - len(PNG)), 'large.png', 'image/png', 413),
        ]
        for name, content, file_name, mime, expected in invalid:
            with self.subTest(name=name):
                self.upload_cover(video, content, file_name, mime, expected=expected)
                self.assertEqual(self.media_files(), baseline_files)
                self.assertEqual(self.client.request(cover_url)[2], PNG)
                self.assertEqual(self.result('video', query={'id': video['id']})['revision'], video['revision'])
        self.upload_cover(initial, expected=409)
        self.result('removeVideoCover', {'id': initial['id'], 'revision': initial['revision']}, expected=409)
        status, response = self.client.multipart('uploadVideoCover', {'id': video['id']}, 'cover', PNG, 'cover.png', 'image/png')
        self.assertEqual(status, 400, response)
        self.result('removeVideoCover', {'id': video['id']}, expected=400)
        self.assertEqual(self.media_files(), baseline_files)
        self.assertEqual(self.client.request(cover_url)[2], PNG)

    def test_publication_09_detects_image_content_and_accepts_supported_formats(self):
        _, video, _ = self.finished()
        for content, mime in [(PNG, 'image/png'), (JPEG, 'image/jpeg'), (WEBP, 'image/webp')]:
            with self.subTest(mime=mime):
                # Client-provided extension and MIME are deliberately misleading.
                video = self.upload_cover(video, content, 'misnamed-upload.bin', 'application/octet-stream')
                status, headers, body = self.client.request(video['coverUrl'].lstrip('/'))
                self.assertEqual(status, 200); self.assertEqual(headers['Content-Type'], mime)
                self.assertEqual(body, content)

    def test_publication_10_deleting_video_or_parent_removes_covers(self):
        for delete_parent in [False, True]:
            with self.subTest(delete_parent=delete_parent):
                before = self.media_files()
                interview, video, _ = self.finished()
                video = self.upload_cover(video)
                video = self.publish(video)
                public = self.public('video', {'id': video['id']})
                created_files = self.media_files() - before
                self.assertEqual(len(created_files), 2)
                action = 'deleteInterview' if delete_parent else 'deleteVideo'
                self.result(action, {'id': interview['id'] if delete_parent else video['id']})
                self.assertTrue(all(not path.exists() for path in created_files))
                self.assert_hidden(video)
                self.assertEqual(self.anonymous.request(public['coverUrl'].lstrip('/'))[0], 404)
                self.assertEqual(self.client.request(video['coverUrl'].lstrip('/'))[0], 404)
                self.result('video', query={'id': video['id']}, expected=404)


def load_tests(loader, tests, pattern):
    return unittest.TestSuite(PublicationIntegration(name) for name in loader.getTestCaseNames(PublicationIntegration)
                              if name.startswith('test_publication_'))


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--php', default='php')
    arguments, remaining = parser.parse_known_args()
    PublicationIntegration.php = arguments.php
    unittest.main(argv=[__file__, *remaining], verbosity=2)
