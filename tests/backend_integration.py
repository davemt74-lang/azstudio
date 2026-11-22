#!/usr/bin/env python3
"""HTTP-level backend checks in an isolated, disposable PHP installation.

Run: python3 tests/backend_integration.py --php /path/to/php
No test account or recording is written into the real app's storage directory.
"""
from __future__ import annotations

import argparse
import atexit
import http.cookiejar
import io
import json
import pathlib
import re
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
import wave


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, new_url):
        return None


class Client:
    def __init__(self, base):
        self.base = base
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies), NoRedirect())
        self.csrf = ''

    def request(self, route, data=None, headers=None, method=None):
        request = urllib.request.Request(self.base + '/' + route, data=data, headers=headers or {}, method=method)
        try:
            response = self.opener.open(request, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.code, dict(response.headers.items()), response.read()

    def form(self, route, fields):
        return self.request(route, urllib.parse.urlencode(fields).encode(), {'Content-Type': 'application/x-www-form-urlencoded'})

    def api(self, action, data=None, query=None, csrf=True):
        route = 'api.php?' + urllib.parse.urlencode({'action': action, **(query or {})})
        headers = {'Accept': 'application/json'}
        encoded = None
        if data is not None:
            encoded = json.dumps(data, ensure_ascii=False).encode()
            headers['Content-Type'] = 'application/json'
            if csrf:
                headers['X-CSRF-Token'] = self.csrf
        status, response_headers, payload = self.request(route, encoded, headers)
        try:
            return status, json.loads(payload)
        except ValueError as error:
            raise AssertionError(f'Invalid API JSON, status {status}: {payload[:500]!r}') from error

    def multipart(self, action, fields, file_field, content, file_name='chunk.bin', mime='application/octet-stream'):
        boundary = '----AZHottieIsolatedTestBoundary'
        pieces = []
        for name, value in fields.items():
            pieces.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
        pieces.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{file_field}"; filename="{file_name}"\r\nContent-Type: {mime}\r\n\r\n'.encode())
        pieces.extend([content, f'\r\n--{boundary}--\r\n'.encode()])
        status, _, body = self.request('api.php?action=' + action, b''.join(pieces), {'Content-Type': f'multipart/form-data; boundary={boundary}', 'X-CSRF-Token': self.csrf})
        return status, json.loads(body)

    def bootstrap(self):
        status, result = self.api('bootstrap')
        assert status == 200, result
        self.csrf = result['data']['csrf']
        return result['data']

    def login(self, email, password):
        status, _, page = self.request('login.php')
        assert status == 200
        token = re.search(rb'name="csrf" value="([a-f0-9]+)"', page)[1].decode()
        status, _, _ = self.form('login.php', {'csrf': token, 'email': email, 'password': password})
        assert status == 303, f'Login returned {status}'
        self.bootstrap()


def make_wav(data_bytes=1024):
    output = io.BytesIO()
    with wave.open(output, 'wb') as recording:
        recording.setnchannels(1)
        recording.setsampwidth(2)
        recording.setframerate(16000)
        recording.writeframes(bytes(data_bytes + data_bytes % 2))
    return output.getvalue()


class BackendIntegration(unittest.TestCase):
    php = 'php'

    @classmethod
    def setUpClass(cls):
        cls.temporary = tempfile.TemporaryDirectory(prefix='azhottie-backend-test-')
        cls.root = pathlib.Path(cls.temporary.name)
        source = pathlib.Path(__file__).resolve().parents[1]
        (cls.root / 'includes').mkdir()
        (cls.root / 'storage').mkdir()
        (cls.root / 'sessions').mkdir()
        for name in ['api.php', 'media.php', 'install.php', 'login.php', 'logout.php', 'phone-api.php', 'phone.php', 'accounts-api.php', 'signup.php', 'account.php', 'accounts.php', 'schedule-api.php']:
            if not (source / name).exists():
                continue
            shutil.copy2(source / name, cls.root / name)
        for path in (source / 'includes').glob('*.php'):
            shutil.copy2(path, cls.root / 'includes' / path.name)
        (cls.root / 'index.php').write_text('<?php echo "Temporary backend test";')
        (cls.root / 'library.php').write_text('<?php require "includes/bootstrap.php"; az_require_auth(); echo "Private library";')
        with socket.socket() as probe:
            probe.bind(('127.0.0.1', 0))
            cls.port = probe.getsockname()[1]
        cls.base = f'http://127.0.0.1:{cls.port}'
        cls.log = (cls.root / 'server.log').open('ab')
        cls.server = None
        atexit.register(cls.stop_server)
        cls.start_server()
        cls.client = Client(cls.base)
        status, _, page = cls.client.request('install.php')
        assert status == 200, page[:300]
        token = re.search(rb'name="csrf" value="([a-f0-9]+)"', page)[1].decode()
        cls.password = 'IsolatedStudioTest42!'
        status, headers, body = cls.client.form('install.php', {
            'csrf': token, 'admin_name': 'Backend Tester', 'admin_email': 'test@example.invalid',
            'admin_password': cls.password, 'database_driver': 'sqlite',
        })
        assert status == 303, body.decode()[-5000:]
        assert headers['Location'] == '/library.php'
        cls.initial = cls.client.bootstrap()

    @classmethod
    def start_server(cls):
        cls.server = subprocess.Popen([
            cls.php, '-d', 'upload_max_filesize=128K', '-d', 'post_max_size=192K',
            '-d', 'session.save_path=' + str(cls.root / 'sessions'),
            '-d', 'display_errors=0', '-S', f'127.0.0.1:{cls.port}', '-t', str(cls.root),
        ], stdout=cls.log, stderr=cls.log)
        for _ in range(100):
            try:
                with urllib.request.urlopen(cls.base + '/index.php', timeout=0.3):
                    return
            except (OSError, urllib.error.URLError):
                if cls.server.poll() is not None:
                    raise AssertionError('Temporary PHP server failed to start.')
                time.sleep(0.03)
        raise AssertionError('Temporary PHP server did not become ready.')

    @classmethod
    def stop_server(cls):
        if cls.server and cls.server.poll() is None:
            cls.server.terminate()
            try:
                cls.server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                cls.server.kill()
                cls.server.wait(timeout=5)

    @classmethod
    def tearDownClass(cls):
        cls.stop_server()
        cls.log.close()
        cls.temporary.cleanup()

    def create(self, **extra):
        status, result = self.client.api('createInterview', {'title': 'Integration interview', 'participantName': 'Test Performer', **extra})
        self.assertEqual(status, 201, result)
        return result['data']

    def direct_take(self, interview_id, **extra):
        fields = {'interviewId': interview_id, 'questionId': 'q-test', 'questionText': 'A test question?', 'durationMs': '1200', 'answerStartMs': '200', 'isPrivate': 'true', **extra}
        content = make_wav()
        status, result = self.client.multipart('saveTake', fields, 'file', content, 'sample.wav', 'audio/wav')
        self.assertEqual(status, 201, result)
        return result['data'], content

    def test_01_installation_locked_and_password_hashed(self):
        status, _, body = self.client.request('install.php')
        self.assertEqual(status, 403)
        self.assertIn(b'installer is locked', body)
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as database:
            stored = database.execute('SELECT password_hash FROM users').fetchone()[0]
        self.assertNotEqual(stored, self.password)
        self.assertTrue(stored.startswith('$2y$') or stored.startswith('$argon2'))

    def test_02_authentication_and_csrf(self):
        anonymous = Client(self.base)
        status, result = anonymous.api('bootstrap')
        self.assertEqual(status, 401)
        self.assertEqual(result['error']['code'], 'authentication_required')
        status, headers, _ = anonymous.request('library.php')
        self.assertEqual(status, 303)
        self.assertEqual(headers['Location'], '/login.php')
        status, result = self.client.api('createInterview', {'title': 'Must not exist'}, csrf=False)
        self.assertEqual(status, 403)
        self.assertEqual(result['error']['code'], 'csrf_invalid')

    def test_03_state_roundtrip_revision_and_rejected_update(self):
        state = {'responses': {}, 'edit': {'segments': {}, 'order': []}, 'customQuestions': [], 'selectedQuestionId': 'q-1'}
        interview = self.create(notes='Private notes — quote " and apostrophe \'', state=state)
        self.assertEqual(interview['state'], state)
        status, result = self.client.api('updateInterview', {'id': interview['id'], 'revision': 1, 'title': 'Updated — façade', 'state': {'responses': {'q-1': {'text': 'Saved answer', 'choices': ['A', 'C']}}, 'customQuestions': []}})
        self.assertEqual(status, 200, result)
        self.assertEqual(result['data']['revision'], 2)
        self.assertEqual(result['data']['notes'], interview['notes'])
        status, result = self.client.api('updateInterview', {'id': interview['id'], 'revision': 1, 'title': 'Stale edit'})
        self.assertEqual(status, 409)
        status, result = self.client.api('updateInterview', {'id': interview['id'], 'state': ['not', 'an', 'object']})
        self.assertEqual(status, 400)
        status, result = self.client.api('interview', query={'id': interview['id']})
        self.assertEqual(result['data']['title'], 'Updated — façade')
        self.assertEqual(result['data']['state']['responses']['q-1']['text'], 'Saved answer')

    def test_04_archive_restore(self):
        interview = self.create()
        status, result = self.client.api('archiveInterview', {'id': interview['id']})
        self.assertEqual(result['data']['status'], 'archived')
        self.assertIsNotNone(result['data']['archivedAt'])
        status, result = self.client.api('archiveInterview', {'id': interview['id'], 'archived': False})
        self.assertEqual(status, 200)
        self.assertEqual(result['data']['status'], 'active')
        self.assertIsNone(result['data']['archivedAt'])

    def test_05_chunk_resume_idempotence_and_finalization(self):
        interview = self.create()
        chunk_size = self.initial['limits']['chunkBytes']
        self.assertLessEqual(chunk_size, 128 * 1024)
        recording = make_wav(chunk_size * 2 + 778)
        status, result = self.client.api('beginUpload', {'interviewId': interview['id'], 'questionId': 'q-4', 'questionText': 'What happens next?', 'fileName': 'large.wav', 'mimeType': 'audio/wav', 'totalBytes': len(recording), 'durationMs': 9000, 'answerStartMs': 1000, 'isPrivate': True})
        self.assertEqual(status, 201, result)
        upload = result['data']
        fields = {'uploadId': upload['uploadId'], 'index': '0'}
        status, result = self.client.multipart('uploadChunk', fields, 'chunk', recording[:chunk_size])
        self.assertEqual(status, 200, result)
        self.assertEqual(result['data']['nextIndex'], 1)
        status, result = self.client.multipart('uploadChunk', fields, 'chunk', recording[:chunk_size])
        self.assertEqual(status, 200, result)
        status, result = self.client.multipart('uploadChunk', fields, 'chunk', bytes(chunk_size))
        self.assertEqual(status, 409, result)
        status, result = self.client.api('finishUpload', {'uploadId': upload['uploadId']})
        self.assertEqual(status, 409, result)
        status, result = self.client.api('upload', query={'uploadId': upload['uploadId']})
        self.assertEqual(result['data']['receivedBytes'], chunk_size)
        status, result = self.client.multipart('uploadChunk', {'uploadId': upload['uploadId'], 'index': '2'}, 'chunk', recording[chunk_size:2 * chunk_size])
        self.assertEqual(status, 409, result)
        for index, start in enumerate(range(chunk_size, len(recording), chunk_size), 1):
            status, result = self.client.multipart('uploadChunk', {'uploadId': upload['uploadId'], 'index': str(index)}, 'chunk', recording[start:start + chunk_size])
            self.assertEqual(status, 200, result)
        status, result = self.client.api('finishUpload', {'uploadId': upload['uploadId']})
        self.assertEqual(status, 200, result)
        take = result['data']
        self.assertEqual(take['questionText'], 'What happens next?')
        self.assertEqual(take['answerStartMs'], 1000)
        self.assertTrue(take['isPrivate'])
        self.assertEqual(take['sizeBytes'], len(recording))
        status, result = self.client.api('finishUpload', {'uploadId': upload['uploadId']})
        self.assertEqual(result['data']['id'], take['id'])
        status, result = self.client.api('interview', query={'id': interview['id']})
        self.assertEqual(result['data']['takeCount'], 1)
        self.assertEqual(result['data']['totalDurationMs'], 9000)
        status, _, content = self.client.request(take['mediaUrl'].lstrip('/'))
        self.assertEqual(status, 200)
        self.assertEqual(content, recording)

    def test_06_ranges_download_and_media_authentication(self):
        interview = self.create()
        take, recording = self.direct_take(interview['id'])
        route = take['mediaUrl'].lstrip('/')
        status, headers, content = self.client.request(route, headers={'Range': 'bytes=7-21'})
        self.assertEqual(status, 206)
        self.assertEqual(content, recording[7:22])
        self.assertEqual(headers['Content-Range'], f'bytes 7-21/{len(recording)}')
        status, _, content = self.client.request(route, headers={'Range': 'bytes=-17'})
        self.assertEqual(status, 206)
        self.assertEqual(content, recording[-17:])
        status, _, content = self.client.request(route, headers={'Range': 'bytes=30-'})
        self.assertEqual(content, recording[30:])
        for invalid in ['bytes=999999-', 'bytes=-0', 'bytes=8-2', 'bytes=0-3,10-15']:
            status, headers, content = self.client.request(route, headers={'Range': invalid})
            self.assertEqual(status, 416, invalid)
            self.assertEqual(content, b'')
        status, headers, content = self.client.request(take['downloadUrl'].lstrip('/'), method='HEAD')
        self.assertEqual(status, 200)
        self.assertEqual(content, b'')
        self.assertEqual(headers['Content-Length'], str(len(recording)))
        self.assertIn('attachment;', headers['Content-Disposition'])
        self.assertEqual(Client(self.base).request(route)[0], 401)

    def test_07_delete_take_removes_file_and_metadata(self):
        interview = self.create()
        take, _ = self.direct_take(interview['id'])
        path = self.root / 'storage/media' / (take['id'] + '.bin')
        self.assertTrue(path.is_file())
        status, result = self.client.api('deleteTake', {'takeId': take['id']})
        self.assertEqual(status, 200, result)
        self.assertFalse(path.exists())
        self.assertEqual(self.client.request(take['mediaUrl'].lstrip('/'))[0], 404)
        status, result = self.client.api('interview', query={'id': interview['id']})
        self.assertEqual(result['data']['takeCount'], 0)

    def test_08_delete_interview_removes_recording_and_partial_upload(self):
        interview = self.create()
        take, _ = self.direct_take(interview['id'])
        chunk_size = self.initial['limits']['chunkBytes']
        recording = make_wav(chunk_size + 1024)
        status, result = self.client.api('beginUpload', {'interviewId': interview['id'], 'totalBytes': len(recording), 'mimeType': 'audio/wav'})
        upload_id = result['data']['uploadId']
        status, result = self.client.multipart('uploadChunk', {'uploadId': upload_id, 'index': '0'}, 'chunk', recording[:chunk_size])
        self.assertEqual(status, 200, result)
        path = self.root / 'storage/uploads' / (upload_id + '.part')
        self.assertTrue(path.is_file())
        status, result = self.client.api('deleteInterview', {'id': interview['id']})
        self.assertEqual(status, 200, result)
        self.assertFalse(path.exists())
        self.assertFalse((self.root / 'storage/media' / (take['id'] + '.bin')).exists())
        self.assertEqual(self.client.api('interview', query={'id': interview['id']})[0], 404)
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as database:
            self.assertEqual(database.execute('SELECT COUNT(*) FROM upload_chunks WHERE upload_id = ?', [upload_id]).fetchone()[0], 0)

    def test_09_unsupported_media_and_abandon(self):
        interview = self.create()
        fake = b'<!doctype html><script>alert("not media")</script>'
        status, result = self.client.api('beginUpload', {'interviewId': interview['id'], 'totalBytes': len(fake), 'mimeType': 'video/webm'})
        upload_id = result['data']['uploadId']
        self.assertEqual(self.client.multipart('uploadChunk', {'uploadId': upload_id, 'index': '0'}, 'chunk', fake)[0], 200)
        status, result = self.client.api('finishUpload', {'uploadId': upload_id})
        self.assertEqual(status, 415, result)
        status, result = self.client.api('abandonUpload', {'uploadId': upload_id})
        self.assertEqual(status, 200)
        self.assertFalse((self.root / 'storage/uploads' / (upload_id + '.part')).exists())

    def test_10_account_isolation(self):
        interview = self.create()
        take, _ = self.direct_take(interview['id'])
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as database:
            password_hash = database.execute('SELECT password_hash FROM users WHERE id = 1').fetchone()[0]
            database.execute('INSERT INTO users (name,email,password_hash,created_at) VALUES (?,?,?,?)', ['Second tester', 'second@example.invalid', password_hash, '2026-01-01T00:00:00Z'])
            database.execute("INSERT INTO user_roles (user_id,role) SELECT id,'admin' FROM users WHERE email='second@example.invalid'")
        other = Client(self.base)
        other.login('second@example.invalid', self.password)
        self.assertEqual(other.bootstrap()['interviews'], [])
        self.assertEqual(other.api('interview', query={'id': interview['id']})[0], 404)
        self.assertEqual(other.api('updateInterview', {'id': interview['id'], 'title': 'Forbidden'})[0], 404)
        self.assertEqual(other.api('deleteInterview', {'id': interview['id']})[0], 404)
        self.assertEqual(other.api('deleteTake', {'takeId': take['id']})[0], 404)
        self.assertEqual(other.request(take['mediaUrl'].lstrip('/'))[0], 404)

    def test_11_logout_requires_post_and_token(self):
        self.assertEqual(self.client.request('logout.php')[0], 200)
        self.assertEqual(self.client.api('bootstrap')[0], 200)
        self.assertEqual(self.client.form('logout.php', {'csrf': 'wrong'})[0], 403)
        self.assertEqual(self.client.api('bootstrap')[0], 200)
        self.assertEqual(self.client.form('logout.php', {'csrf': self.client.csrf})[0], 303)
        self.assertEqual(self.client.api('bootstrap')[0], 401)
        self.client.login('test@example.invalid', self.password)

    def test_12_server_restart_preserves_interview_and_media(self):
        interview = self.create(title='Persist after restart', state={'responses': {'q-2': {'text': 'A durable saved answer'}}})
        take, recording = self.direct_take(interview['id'])
        self.stop_server()
        self.start_server()
        status, result = self.client.api('interview', query={'id': interview['id']})
        self.assertEqual(status, 200, result)
        self.assertEqual(result['data']['state']['responses']['q-2']['text'], 'A durable saved answer')
        self.assertEqual(self.client.request(take['mediaUrl'].lstrip('/'))[2], recording)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--php', default='php')
    arguments, remaining = parser.parse_known_args()
    BackendIntegration.php = arguments.php
    unittest.main(argv=[__file__, *remaining], verbosity=2)
