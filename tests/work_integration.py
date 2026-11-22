#!/usr/bin/env python3
"""Production workflow checks against a disposable, four-worker HTTP installation.

Run: python3 tests/work_integration.py --php /path/to/php
The real application's storage stays uninstalled and untouched.
"""
from __future__ import annotations
import argparse
import concurrent.futures
import datetime as dt
import json
import os
import pathlib
import re
import shutil
import signal
import sqlite3
import subprocess
import threading
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
import uuid
import backend_integration as harness


class WorkIntegration(harness.BackendIntegration):
    member_sequence = 0

    @classmethod
    def start_server(cls):
        env = dict(os.environ)
        env['PHP_CLI_SERVER_WORKERS'] = '4'
        cls.server = subprocess.Popen([
            cls.php, '-d', 'session.save_path=' + str(cls.root / 'sessions'),
            '-d', 'display_errors=0', '-S', f'127.0.0.1:{cls.port}', '-t', str(cls.root),
        ], stdout=cls.log, stderr=cls.log, env=env, start_new_session=True)
        for _ in range(100):
            try:
                with urllib.request.urlopen(cls.base + '/index.php', timeout=.3):
                    return
            except (OSError, urllib.error.URLError):
                if cls.server.poll() is not None:
                    raise AssertionError('Production test server failed to start')
                time.sleep(.03)
        raise AssertionError('Production test server did not become ready')

    @classmethod
    def stop_server(cls):
        if cls.server and cls.server.poll() is None:
            os.killpg(cls.server.pid, signal.SIGTERM)
            try:
                cls.server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                os.killpg(cls.server.pid, signal.SIGKILL)
                cls.server.wait(timeout=5)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        source = pathlib.Path(__file__).resolve().parents[1]
        for path in source.glob('*.php'):
            if path.name != 'index.php':
                shutil.copy2(path, cls.root / path.name)

    def call(self, action, data=None, query=None, client=None, expected=200,
             csrf=True, endpoint='work-api.php'):
        c = client or self.client
        route = endpoint + '?' + urllib.parse.urlencode({'action': action, **(query or {})})
        headers = {'Accept': 'application/json'}
        payload = None
        if data is not None:
            payload = json.dumps(data, ensure_ascii=False).encode()
            headers['Content-Type'] = 'application/json'
            if csrf:
                headers['X-CSRF-Token'] = c.csrf
        status, _, body = c.request(route, payload, headers)
        try:
            result = json.loads(body)
        except ValueError:
            raise AssertionError(f'{endpoint} {action} returned {status}: {body[:1000]!r}')
        self.assertEqual(status, expected, (action, result))
        return result.get('data', result)

    def member(self, *roles):
        type(self).member_sequence += 1
        n = type(self).member_sequence
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            password = db.execute('SELECT password_hash FROM users WHERE id=1').fetchone()[0]
            cur = db.execute('INSERT INTO users(name,email,password_hash,created_at,status) VALUES(?,?,?,?,?)',
                             [f'Contributor {n}', f'work-{n}@example.invalid', password,
                              '2026-01-01T00:00:00Z', 'active'])
            uid = cur.lastrowid
            for role in roles:
                db.execute('INSERT INTO user_roles(user_id,role) VALUES(?,?)', [uid, role])
        c = harness.Client(self.base)
        status, _, page = c.request('login.php')
        self.assertEqual(status, 200)
        token = re.search(rb'name="csrf" value="([a-f0-9]+)"', page)[1].decode()
        status, _, body = c.form('login.php', {'csrf': token, 'email': f'work-{n}@example.invalid', 'password': self.password})
        self.assertEqual(status, 303, body)
        c.csrf = self.call('bootstrap', client=c, endpoint='schedule-api.php')['csrf']
        return uid, c

    def project(self, client=None, **extra):
        return self.call('saveProject', {'title': 'Wardrobe launch', 'brief': 'Record a concise product introduction.',
                         'productLine': 'Clothing', 'kind': 'production', 'status': 'active', **extra}, client=client)

    def shot(self, project=None, client=None, **extra):
        p = project or self.project(client=client)
        return self.call('saveShot', {'projectId': p['id'], 'title': 'Product detail',
                         'brief': 'Show fabric and fit in good light.', 'mode': 'assigned',
                         'maxContributors': 1, 'eligibleRoles': ['model'], 'orientation': 'portrait',
                         'targetDurationSeconds': 30, 'takesRequested': 2, **extra}, client=client)

    def include(self, project, *user_ids, client=None):
        """Explicitly enroll fixture contributors before assigning or claiming work."""
        project_id = project['id'] if isinstance(project, dict) else project
        roster = self.call('roster', query={'projectId': project_id}, client=client)
        existing = {member['userId'] for member in roster['members']}
        new_ids = [uid for uid in user_ids if uid not in existing]
        if new_ids:
            return self.call('addMembers', {'projectId': project_id, 'revision': roster['revision'],
                                           'userIds': new_ids}, client=client)
        return roster

    def assigned(self, client=None, user_id=None, project=None, **extra):
        if client is None:
            user_id, client = self.member('model')
        shot = self.shot(project=project, **extra)
        self.include(shot['projectId'], user_id)
        assignment = self.call('assign', {'shotId': shot['id'], 'userId': user_id})
        assignment = self.call('respond', {'assignmentId': assignment['id'], 'revision': assignment['revision'],
                                          'decision': 'accept'}, client=client)
        return shot, assignment, user_id, client

    def media_call(self, action, data=None, query=None, client=None, expected=200, csrf=True):
        return self.call(action, data, query, client, expected, csrf, 'work-upload-api.php')

    def chunk(self, upload, content, client=None, index=0, expected=200):
        c = client or self.client
        boundary = '----AZHottieWorkTestBoundary'
        fields = {'uploadId': upload['uploadId'], 'assignmentId': upload['assignmentId'], 'index': index}
        pieces = [f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode() for name, value in fields.items()]
        pieces += [f'--{boundary}\r\nContent-Disposition: form-data; name="chunk"; filename="part.bin"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode(), content, f'\r\n--{boundary}--\r\n'.encode()]
        status, _, body = c.request('work-upload-api.php?action=uploadChunk', b''.join(pieces),
                                   {'Content-Type': f'multipart/form-data; boundary={boundary}', 'X-CSRF-Token': c.csrf})
        result = json.loads(body)
        self.assertEqual(status, expected, result)
        return result.get('data', result)

    def begin(self, assignment, client, token=None, content=None, expected=201):
        content = content or harness.make_wav()
        token = token or 'recording:' + uuid.uuid4().hex
        body = {'assignmentId': assignment['id'], 'clientToken': token, 'fileName': 'wardrobe.wav',
                'mimeType': 'audio/wav', 'totalBytes': len(content), 'durationMs': 1200}
        return self.media_call('beginUpload', body, client=client, expected=expected), body, content

    def upload(self, assignment, client, token=None):
        upload, body, content = self.begin(assignment, client, token)
        self.chunk(upload, content, client)
        finish = self.media_call('finishUpload', {'uploadId': upload['uploadId'], 'assignmentId': assignment['id']}, client=client)
        return finish, upload, body, content

    def submission(self, assignment, client, submission_id):
        detail = self.call('assignment', query={'id': assignment['id']}, client=client)
        return next(s for s in detail['submissions'] if s['id'] == submission_id)

    def video_fixture(self, project, selected_take_ids, version=1):
        """Trusted rendered-record fixture for testing publication lifecycle checks."""
        video_id = uuid.uuid4().hex
        now = '2026-11-18T12:00:00Z'
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            db.execute('INSERT INTO finished_videos(id,interview_id,owner_id,participant_name,title,description,status,published_url,version,source_edit_json,mime_type,file_name,storage_name,size_bytes,duration_ms,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                       [video_id, project['interviewId'], 1, 'Team', 'Finished product video', '', 'draft', '', version,
                        json.dumps({'selectedTakeIds': selected_take_ids}), 'video/webm', 'finished.webm', video_id + '.bin', 32, 1200, now, now])
        (self.root / 'storage/media' / (video_id + '.bin')).write_bytes(b'rendered-fixture-for-lifecycle-test')
        return video_id

    def test_work_01_roles_authentication_and_schema(self):
        self.call('bootstrap', client=harness.Client(self.base), expected=401)
        uid, member = self.member('model', 'customer')
        fan_id, fan = self.member('fan')
        boot = self.call('bootstrap', client=member)
        self.assertEqual(boot['timezone'], 'America/Phoenix')
        self.assertFalse(boot['canManage'])
        self.assertEqual(boot['users'], [])
        self.call('bootstrap', client=fan, expected=403)
        self.call('saveProject', {'title': 'Blocked'}, client=member, expected=403)
        self.call('saveProject', {'title': 'Blocked'}, csrf=False, expected=403)
        self.call('users', client=member, expected=403)
        self.assertEqual(member.api('bootstrap')[0], 403)
        users = self.call('users')
        self.assertIn(uid, [u['id'] for u in users])
        self.assertNotIn(fan_id, [u['id'] for u in users])
        self.assertTrue(all(set(u) == {'id', 'name', 'roles'} for u in users))
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            self.assertGreaterEqual(db.execute('SELECT version FROM studio_schema').fetchone()[0], 8)
            self.assertNotIn('admin', [r[0] for r in db.execute('SELECT role FROM user_roles WHERE user_id=?', [uid])])

    def test_work_02_project_ownership_revision_and_create_retry(self):
        _, other = self.member('admin')
        token = 'project:' + uuid.uuid4().hex
        p = self.project(requestId=token)
        again = self.project(requestId=token)
        self.assertEqual(p['id'], again['id'])
        self.call('saveProject', {'title': 'Different intent', 'requestId': token}, expected=409)
        self.assertEqual(p['shotCount'], 0)
        self.assertNotEqual(p['id'], p['interviewId'])
        self.call('project', query={'id': p['id']}, client=other, expected=404)
        self.call('saveProject', {'id': p['id'], 'revision': 1, 'title': 'Cross owner'}, client=other, expected=404)
        self.call('saveProject', {'id': p['id'], 'title': 'Missing revision'}, expected=409)
        changed = self.call('saveProject', {'id': p['id'], 'revision': p['revision'], 'title': 'New title'})
        self.call('saveProject', {'id': p['id'], 'revision': p['revision'], 'title': 'Stale'}, expected=409)
        self.assertEqual(changed['revision'], 2)
        library = self.client.bootstrap()['interviews']
        self.assertNotIn(p['interviewId'], [i['id'] for i in library])
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            self.assertEqual(db.execute('SELECT project_id FROM interviews WHERE id=?', [p['interviewId']]).fetchone()[0], p['id'])

    def test_work_03_atomic_last_slot_and_idempotent_claim(self):
        a_id, a = self.member('model')
        b_id, b = self.member('model')
        p = self.project()
        self.include(p, a_id, b_id)
        shot = self.shot(p, mode='open', maxContributors=1)
        barrier = threading.Barrier(2)
        def claim(c):
            barrier.wait()
            status, _, raw = c.request('work-api.php?action=claim', json.dumps({'shotId': shot['id']}).encode(),
                                      {'Content-Type': 'application/json', 'X-CSRF-Token': c.csrf})
            return c, status, json.loads(raw)
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            results = list(pool.map(claim, [a, b]))
        self.assertEqual(sorted(r[1] for r in results), [200, 409], results)
        winner, _, result = next(r for r in results if r[1] == 200)
        claimed = result['data']
        self.assertEqual(claimed['status'], 'accepted')
        self.assertEqual(self.call('claim', {'shotId': shot['id']}, client=winner)['id'], claimed['id'])
        detail = self.call('shot', query={'id': shot['id']})
        self.assertEqual(detail['slotsRemaining'], 0)
        self.assertEqual(len(detail['assignments']), 1)
        self.assertEqual(len(self.call('shot', query={'id': shot['id']}, client=winner)['assignments']), 1)

    def test_work_04_private_assignments_response_cancel_reassign(self):
        a_id, a = self.member('model')
        b_id, b = self.member('model')
        p = self.project(status='draft')
        self.include(p, a_id, b_id)
        shot = self.shot(p, dueLocal='2027-03-12T14:30')
        assignment = self.call('assign', {'shotId': shot['id'], 'userId': a_id})
        self.assertEqual(assignment['dueAt'], '2027-03-12T21:30:00Z')
        self.assertEqual(self.call('assign', {'shotId': shot['id'], 'userId': a_id})['id'], assignment['id'])
        self.call('respond', {'assignmentId': assignment['id'], 'revision': 1, 'decision': 'accept'}, client=a, expected=409)
        self.call('assignment', query={'id': assignment['id']}, client=b, expected=404)
        self.call('project', query={'id': p['id']}, client=b, expected=404)
        self.call('saveProject', {'id': p['id'], 'revision': 1, 'status': 'active'})
        declined = self.call('respond', {'assignmentId': assignment['id'], 'revision': 1, 'decision': 'decline', 'note': 'Unavailable'}, client=a)
        self.assertEqual(declined['status'], 'declined')
        fresh = self.call('assign', {'shotId': shot['id'], 'userId': a_id})
        self.assertEqual(fresh['id'], assignment['id'])
        self.assertEqual(fresh['status'], 'assigned')
        self.call('respond', {'assignmentId': assignment['id'], 'revision': 1, 'decision': 'accept'}, client=a, expected=409)
        cancelled = self.call('cancelAssignment', {'assignmentId': fresh['id'], 'revision': fresh['revision'], 'note': 'Schedule changed'})
        self.assertEqual(cancelled['status'], 'cancelled')
        self.assertEqual(self.call('assign', {'shotId': shot['id'], 'userId': b_id})['status'], 'assigned')

    def test_work_05_scoped_upload_recovery_and_media_boundaries(self):
        shot, assignment, uid, c = self.assigned()
        _, other = self.member('model')
        upload, body, content = self.begin(assignment, c)
        self.assertNotIn('ownerId', upload)
        self.assertNotIn('interviewId', upload)
        duplicate = self.media_call('beginUpload', body, client=c, expected=201)
        self.assertEqual(duplicate['uploadId'], upload['uploadId'])
        self.media_call('upload', query={'uploadId': upload['uploadId'], 'assignmentId': assignment['id']}, client=other, expected=404)
        self.chunk(upload, content, c)
        self.chunk(upload, content, c)  # Lost chunk acknowledgement must be safe.
        finish = self.media_call('finishUpload', {'uploadId': upload['uploadId'], 'assignmentId': assignment['id']}, client=c)
        again = self.media_call('finishUpload', {'uploadId': upload['uploadId'], 'assignmentId': assignment['id']}, client=c)
        self.assertEqual(finish['submissionId'], again['submissionId'])
        detail = self.call('assignment', query={'id': assignment['id']}, client=c)
        self.assertEqual(len(detail['submissions']), 1)
        self.assertEqual(detail['revision'], assignment['revision'])
        self.assertEqual(detail['submissions'][0]['status'], 'draft')
        sub = detail['submissions'][0]
        self.assertEqual(sub['contributorId'], uid)
        self.assertNotIn('interviewId', detail['project'])
        self.assertNotIn('ownerId', sub)
        self.assertNotIn('storage_name', sub)
        status, headers, raw = c.request('work-media.php?type=submission&id=' + sub['id'])
        self.assertEqual(status, 200)
        self.assertEqual(raw, content)
        self.assertEqual(other.request('work-media.php?type=submission&id=' + sub['id'])[0], 404)
        self.assertEqual(c.request('media.php?id=' + sub['takeId'])[0], 403)
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            self.assertEqual(db.execute('SELECT is_private FROM takes WHERE id=?', [sub['takeId']]).fetchone()[0], 1)

    def test_work_06_submit_review_retake_history_and_notes(self):
        shot, assignment, uid, c = self.assigned()
        finish, *_ = self.upload(assignment, c)
        first = self.submission(assignment, c, finish['submissionId'])
        self.call('reviewSubmission', {'submissionId': first['id'], 'revision': first['revision'], 'decision': 'accepted'}, expected=409)
        request = 'submission:' + uuid.uuid4().hex
        body = {'assignmentId': assignment['id'], 'revision': assignment['revision'], 'note': 'First take', 'requestId': request}
        submitted = self.call('submitAssignment', body, client=c)
        self.assertEqual(self.call('submitAssignment', body, client=c)['id'], submitted['id'])
        first = self.submission(assignment, c, first['id'])
        self.call('reviewSubmission', {'submissionId': first['id'], 'revision': first['revision'], 'decision': 'retake'}, expected=400)
        self.call('reviewSubmission', {'submissionId': first['id'], 'revision': first['revision'], 'decision': 'accepted'}, client=c, expected=404)
        reviewed = self.call('reviewSubmission', {'submissionId': first['id'], 'revision': first['revision'], 'decision': 'retake', 'note': 'Show the product label clearly.'})
        assignment = self.call('assignment', query={'id': assignment['id']}, client=c)
        self.assertEqual(assignment['status'], 'changes_requested')
        self.assertTrue(assignment['canUpload'])
        self.assertEqual(assignment['latestReviewNote'], 'Show the product label clearly.')
        next_finish, *_ = self.upload(assignment, c)
        assignment = self.call('submitAssignment', {'assignmentId': assignment['id'], 'revision': assignment['revision']}, client=c)
        second = self.submission(assignment, c, next_finish['submissionId'])
        accepted = self.call('reviewSubmission', {'submissionId': second['id'], 'revision': second['revision'], 'decision': 'accepted', 'note': 'Ready for the edit.'})
        detail = self.call('assignment', query={'id': assignment['id']}, client=c)
        self.assertEqual(detail['status'], 'approved')
        self.assertEqual(len(detail['submissions']), 2)
        self.assertEqual({s['status'] for s in detail['submissions']}, {'retake', 'accepted'})
        self.assertEqual(detail['acceptedCount'], 1)
        favorite = self.call('favoriteSubmission', {'submissionId': accepted['id'], 'favorite': True})
        self.assertTrue(favorite['isFavorite'])
        note = {'submissionId': accepted['id'], 'atMs': 600, 'text': 'Use this product close-up.', 'requestId': 'review-note:' + uuid.uuid4().hex}
        noted = self.call('addSubmissionNote', note)
        self.assertEqual(len(self.call('addSubmissionNote', note)['reviewNotes']), 1)
        self.call('addSubmissionNote', {'submissionId': accepted['id'], 'atMs': 1201, 'text': 'Past end'}, expected=400)
        self.call('deleteSubmissionNote', {'submissionId': accepted['id'], 'noteId': noted['reviewNotes'][0]['id']}, client=c, expected=404)
        self.assertEqual(self.call('deleteSubmissionNote', {'submissionId': accepted['id'], 'noteId': noted['reviewNotes'][0]['id']})['reviewNotes'], [])
        p = self.call('project', query={'id': shot['projectId']})
        self.assertEqual(p['acceptedCount'], 1)
        self.assertEqual(p['approvedAssignmentCount'], 1)
        editor = self.media_call('prepareEditor', {'projectId': p['id']})
        self.assertEqual(editor['acceptedCount'], 1)

    def test_work_07_live_role_suspension_and_owner_boundaries(self):
        shot, assignment, uid, c = self.assigned()
        _, other_admin = self.member('admin')
        self.call('shot', query={'id': shot['id']}, client=other_admin, expected=404)
        self.call('assignment', query={'id': assignment['id']}, client=other_admin, expected=404)
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            db.execute("DELETE FROM user_roles WHERE user_id=? AND role='model'", [uid])
            db.execute("INSERT INTO user_roles(user_id,role) VALUES(?,'fan')", [uid])
        self.call('assignment', query={'id': assignment['id']}, client=c, expected=403)
        self.assertFalse(self.call('assignment', query={'id': assignment['id']})['canUpload'])
        self.media_call('beginUpload', {'assignmentId': assignment['id'], 'clientToken': uuid.uuid4().hex,
                                     'fileName': 'x.wav', 'mimeType': 'audio/wav', 'totalBytes': 100, 'durationMs': 100}, expected=409)
        # Revoked contributor roles must not strand administrator close/archive controls.
        self.assertEqual(self.call('saveShot', {'id': shot['id'], 'revision': shot['revision'], 'status': 'closed'})['status'], 'closed')
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            db.execute("UPDATE users SET status='suspended' WHERE id=?", [uid])
        self.call('myWork', client=c, expected=401)
        second_admin_id, second_admin = self.member('admin')
        foreign = self.project(client=second_admin)
        foreign_shot = self.shot(foreign, client=second_admin, mode='open')
        model_id, model = self.member('model')
        self.include(foreign, model_id, client=second_admin)
        self.call('shot', query={'id': foreign_shot['id']}, client=model)
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            db.execute("UPDATE users SET status='suspended' WHERE id=?", [second_admin_id])
        self.call('shot', query={'id': foreign_shot['id']}, client=model, expected=404)

    def test_work_08_pending_upload_revisions_and_multiple_drafts(self):
        shot, assignment, _, c = self.assigned()
        pending, _, content = self.begin(assignment, c)
        self.call('saveShot', {'id': shot['id'], 'projectId': shot['projectId'], 'revision': shot['revision'], 'brief': 'Updated camera direction'})
        self.chunk(pending, content, c, expected=409)
        self.media_call('abandonUpload', {'uploadId': pending['uploadId'], 'assignmentId': assignment['id']}, client=c)
        one, _, data1 = self.begin(assignment, c)
        two, _, data2 = self.begin(assignment, c)
        for up, data in [(one, data1), (two, data2)]:
            self.chunk(up, data, c)
            self.media_call('finishUpload', {'uploadId': up['uploadId'], 'assignmentId': assignment['id']}, client=c)
        detail = self.call('assignment', query={'id': assignment['id']}, client=c)
        self.assertEqual(len(detail['submissions']), 2)
        self.assertEqual(detail['revision'], assignment['revision'])
        pending, _, content = self.begin(assignment, c)
        self.call('cancelAssignment', {'assignmentId': assignment['id'], 'revision': assignment['revision']})
        self.chunk(pending, content, c, expected=409)

    def test_work_09_notification_idempotency_and_read_scope(self):
        uid, c = self.member('model')
        _, other = self.member('model')
        shot = self.shot()
        self.include(shot['projectId'], uid)
        initial_notifications = self.call('notifications', client=c)
        self.call('markNotificationsRead', {'ids': [n['id'] for n in initial_notifications['notifications']]}, client=c)
        body = {'shotId': shot['id'], 'userId': uid, 'requestId': 'assign:' + uuid.uuid4().hex}
        assignment = self.call('assign', body)
        self.call('assign', body)
        notes = self.call('notifications', client=c)
        assignment_notes = [n for n in notes['notifications'] if n['kind'] == 'assignment']
        self.assertEqual(len(assignment_notes), 1)
        note = assignment_notes[0]
        self.assertIn('assignmentId=' + assignment['id'], note['url'])
        self.call('markNotificationsRead', {'ids': [note['id']]}, client=other)
        self.assertEqual(self.call('notifications', client=c)['unreadCount'], 1)
        self.assertEqual(self.call('markNotificationsRead', {'ids': [note['id']]}, client=c)['unreadCount'], 0)

    def test_work_10_archive_and_restore_block_new_work(self):
        shot, assignment, _, c = self.assigned()
        p = self.call('project', query={'id': shot['projectId']})
        archived = self.call('saveProject', {'id': p['id'], 'revision': p['revision'], 'status': 'archived'})
        self.assertEqual(archived['status'], 'archived')
        detail = self.call('assignment', query={'id': assignment['id']}, client=c)
        self.assertFalse(detail['canUpload'])
        self.begin(assignment, c, expected=409)
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            self.assertEqual(db.execute('SELECT status FROM interviews WHERE id=?', [p['interviewId']]).fetchone()[0], 'archived')
        restored = self.call('saveProject', {'id': p['id'], 'revision': archived['revision'], 'status': 'active'})
        self.assertEqual(restored['videos'], [])
        self.assertTrue(self.call('assignment', query={'id': assignment['id']}, client=c)['canUpload'])

    def test_work_11_validation_capacity_and_producer_capture(self):
        a, ca = self.member('model')
        b, cb = self.member('professional')
        p = self.project()
        shot = self.shot(p, maxContributors=2, eligibleRoles=['model', 'professional'])
        self.include(p, a, b)
        self.call('assign', {'shotId': shot['id'], 'userId': a})
        self.call('assign', {'shotId': shot['id'], 'userId': b})
        self.call('saveShot', {'id': shot['id'], 'revision': 1, 'maxContributors': 1}, expected=409)
        self.call('saveShot', {'id': shot['id'], 'revision': 1, 'eligibleRoles': ['model']}, expected=409)
        self.call('saveShot', {'projectId': p['id'], 'title': 'Invalid date', 'dueLocal': '2027-02-30T12:00'}, expected=400)
        self.call('saveShot', {'projectId': p['id'], 'title': 'Invalid roles', 'eligibleRoles': ['fan']}, expected=400)
        self.call('saveShot', {'projectId': p['id'], 'title': 'Invalid format', 'orientation': 'panorama'}, expected=400)
        self.call('saveShot', {'projectId': p['id'], 'title': 'Invalid quantity', 'takesRequested': 0}, expected=400)
        host = self.call('assign', {'shotId': shot['id'], 'userId': 1})
        self.assertEqual(host['status'], 'accepted')
        self.assertEqual(host['userId'], 1)
        current = self.call('shot', query={'id': shot['id']})
        self.assertEqual(current['slotsRemaining'], 0)
        self.assertEqual(current['contributorCount'], 2)
        self.assertEqual(current['assignmentCount'], 3)
        self.upload(host, self.client)

    def test_work_12_schedule_link_scope_and_no_rescheduling(self):
        professional_id, professional = self.member('professional')
        model_id, model = self.member('model')
        type_id, booking_id, other_id = (uuid.uuid4().hex for _ in range(3))
        now = '2026-11-18T12:00:00Z'
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            db.execute('INSERT INTO appointment_types(id,professional_id,name,description,duration_minutes,buffer_before_minutes,buffer_after_minutes,location,min_lead_hours,max_advance_days,slot_step_minutes,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                       [type_id, professional_id, 'Wardrobe fitting', '', 60, 0, 0, 'Studio room', 0, 90, 15, 'active', now, now])
            for bid, customer in [(booking_id, 1), (other_id, model_id)]:
                db.execute('INSERT INTO schedule_appointments(id,type_id,type_name,professional_id,customer_id,start_at,end_at,busy_start_at,busy_end_at,duration_minutes,buffer_before_minutes,buffer_after_minutes,location,status,notes,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                           [bid, type_id, 'Wardrobe fitting', professional_id, customer, '2027-01-15T17:00:00Z', '2027-01-15T18:00:00Z', '2027-01-15T17:00:00Z', '2027-01-15T18:00:00Z', 60, 0, 0, 'Studio room', 'confirmed', 'Private booking details', now, now])
        links = self.call('scheduleLinks')
        self.assertIn(booking_id, [a['id'] for a in links['appointments']])
        self.assertNotIn(other_id, [a['id'] for a in links['appointments']])
        p = self.project(appointmentId=booking_id)
        self.include(p, model_id)
        self.assertEqual(p['schedule']['startLocal'], '2027-01-15T10:00')
        shot = self.shot(p, mode='open')
        member_project = self.call('project', query={'id': p['id']}, client=model)
        self.assertIsNone(member_project['appointmentId'])
        self.assertIsNone(member_project['schedule'])
        self.call('saveProject', {'title': 'Forbidden booking', 'appointmentId': other_id}, expected=404)
        self.call('saveProject', {'id': p['id'], 'revision': p['revision'], 'title': 'Updated project title'})
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            self.assertEqual(db.execute('SELECT start_at,notes FROM schedule_appointments WHERE id=?', [booking_id]).fetchone(), ('2027-01-15T17:00:00Z', 'Private booking details'))

    def test_work_13_migration_replay_preserves_history(self):
        shot, assignment, uid, c = self.assigned()
        self.upload(assignment, c)
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            before = db.execute('SELECT COUNT(*) FROM shot_submissions').fetchone()[0]
            db.execute('UPDATE studio_schema SET version=7 WHERE id=1')
        self.call('bootstrap')
        self.call('bootstrap')
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            self.assertGreaterEqual(db.execute('SELECT version FROM studio_schema').fetchone()[0], 8)
            self.assertEqual(db.execute('SELECT COUNT(*) FROM shot_submissions').fetchone()[0], before)
            self.assertEqual(db.execute('SELECT COUNT(*) FROM user_roles WHERE user_id=? AND role=?', [uid, 'admin']).fetchone()[0], 0)
        self.assertEqual(self.call('assignment', query={'id': assignment['id']}, client=c)['submissionCount'], 1)

    def test_work_14_withdrawal_unpublishes_and_archive_closes_phones(self):
        shot, assignment, _, c = self.assigned()
        finish, *_ = self.upload(assignment, c)
        self.call('submitAssignment', {'assignmentId': assignment['id'], 'revision': assignment['revision']}, client=c)
        submitted = self.submission(assignment, c, finish['submissionId'])
        accepted = self.call('reviewSubmission', {'submissionId': submitted['id'], 'revision': submitted['revision'], 'decision': 'accepted'})
        p = self.call('project', query={'id': shot['projectId']})
        now = '2026-11-18T12:00:00Z'

        def publication_fixture(version):
            # Lifecycle fixture: no rendered media is needed to verify that a
            # previously published record is withdrawn in the same transaction.
            video_id = uuid.uuid4().hex
            with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
                db.execute('INSERT INTO finished_videos(id,interview_id,owner_id,participant_name,title,description,status,published_url,version,source_edit_json,mime_type,file_name,storage_name,size_bytes,duration_ms,created_at,updated_at,fan_published_at,fan_updated_at,fan_title,fan_description,fan_credit) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                           [video_id, p['interviewId'], 1, 'Team', 'Finished product video', '', 'draft', '', version,
                            json.dumps({'selectedTakeIds': [accepted['takeId']]}), 'video/webm', 'finished.webm', video_id + '.bin', 100, 1200, now, now, now, now, 'Product preview', '', 'Team'])
            return video_id

        first_video = publication_fixture(1)
        retake = self.call('reviewSubmission', {'submissionId': accepted['id'], 'revision': accepted['revision'], 'decision': 'retake', 'note': 'Replace the old product packaging.'})
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            self.assertIsNone(db.execute('SELECT fan_published_at FROM finished_videos WHERE id=?', [first_video]).fetchone()[0])
        accepted = self.call('reviewSubmission', {'submissionId': accepted['id'], 'revision': retake['revision'], 'decision': 'accepted'})
        second_video = publication_fixture(2)
        current = self.call('assignment', query={'id': assignment['id']})
        self.call('cancelAssignment', {'assignmentId': assignment['id'], 'revision': current['revision']})
        connection, invite, nonce = (uuid.uuid4().hex for _ in range(3))
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            self.assertIsNone(db.execute('SELECT fan_published_at FROM finished_videos WHERE id=?', [second_video]).fetchone()[0])
            db.execute('INSERT INTO phone_rooms(interview_id,owner_id,enabled,question_id,question_text,host_seen_at) VALUES(?,?,?,?,?,?)', [p['interviewId'], 1, 1, shot['id'], shot['title'], int(time.time())])
            db.execute('INSERT INTO phone_connections(id,interview_id,owner_id,token_hash,label,status,expires_at,created_at) VALUES(?,?,?,?,?,?,?,?)', [connection, p['interviewId'], 1, 'a' * 64, 'Approved studio phone', 'approved', int(time.time()) + 600, now])
            db.execute('INSERT INTO phone_invites(id,interview_id,owner_id,secret_hash,expires_at,created_at) VALUES(?,?,?,?,?,?)', [invite, p['interviewId'], 1, 'b' * 64, int(time.time()) + 600, now])
            db.execute('INSERT INTO phone_live(connection_id,nonce,offer_sdp,updated_at) VALUES(?,?,?,?)', [connection, nonce, 'v=0', int(time.time())])
        archived = self.call('saveProject', {'id': p['id'], 'revision': p['revision'], 'status': 'archived'})
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            self.assertEqual(db.execute('SELECT enabled FROM phone_rooms WHERE interview_id=?', [p['interviewId']]).fetchone()[0], 0)
            self.assertEqual(db.execute('SELECT status FROM phone_connections WHERE id=?', [connection]).fetchone()[0], 'revoked')
            self.assertEqual(db.execute('SELECT COUNT(*) FROM phone_invites WHERE interview_id=?', [p['interviewId']]).fetchone()[0], 0)
            self.assertEqual(db.execute('SELECT COUNT(*) FROM phone_live WHERE connection_id=?', [connection]).fetchone()[0], 0)
        restored = self.call('saveProject', {'id': p['id'], 'revision': archived['revision'], 'status': 'active'})
        self.assertEqual(restored['videos'], [])
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            self.assertEqual(db.execute('SELECT enabled FROM phone_rooms WHERE interview_id=?', [p['interviewId']]).fetchone()[0], 0)

    def test_work_15_editor_autosave_and_stale_publication_rejected(self):
        shot, assignment, _, c = self.assigned()
        finish, *_ = self.upload(assignment, c)
        self.call('submitAssignment', {'assignmentId': assignment['id'], 'revision': assignment['revision']}, client=c)
        sub = self.submission(assignment, c, finish['submissionId'])
        accepted = self.call('reviewSubmission', {'submissionId': sub['id'], 'revision': sub['revision'], 'decision': 'accepted'})
        p = self.call('project', query={'id': shot['projectId']})
        interview = self.call('interview', query={'id': p['interviewId']}, endpoint='api.php')
        self.assertEqual([t['id'] for t in interview['takes']], [accepted['takeId']])
        state = {'responses': {}, 'edit': {'order': [accepted['takeId']], 'segments': {accepted['takeId']: {'included': True, 'trimStart': .2, 'trimEnd': 1.0}}, 'settings': {'cardMode': 'none'}}, 'customQuestions': []}
        # This is the exact legacy editor payload, including unchanged modelId.
        saved = self.call('updateInterview', {'id': interview['id'], 'title': interview['title'],
                         'participantName': '', 'modelId': None, 'notes': '', 'state': state,
                         'revision': interview['revision']}, endpoint='api.php')
        self.assertEqual(saved['state'], state)
        self.call('archiveInterview', {'id': interview['id']}, endpoint='api.php', expected=409)
        self.call('deleteInterview', {'id': interview['id']}, endpoint='api.php', expected=409)
        video = self.video_fixture(p, [accepted['takeId']])
        published = self.call('publishVideo', {'id': video, 'revision': 1, 'published': True}, endpoint='api.php')
        self.assertTrue(published['fanPublished'])
        self.call('reviewSubmission', {'submissionId': accepted['id'], 'revision': accepted['revision'], 'decision': 'retake', 'note': 'Replace this angle before publishing.'})
        withdrawn = self.call('video', query={'id': video}, endpoint='api.php')
        self.assertFalse(withdrawn['fanPublished'])
        blocked = self.call('publishVideo', {'id': video, 'revision': withdrawn['revision'], 'published': True}, endpoint='api.php', expected=409)
        self.assertEqual(blocked['error']['code'], 'export_source_changed')

    def test_work_16_source_interview_archive_delete_withdraw_imports(self):
        for operation in ['archiveInterview', 'deleteInterview']:
            with self.subTest(operation=operation):
                source = self.create(title='Source product interview')
                take, _ = self.direct_take(source['id'], isPrivate='false')
                self.call('updateTakeReview', {'takeId': take['id'], 'reviewStatus': 'shortlisted'}, endpoint='api.php')
                p = self.project(kind='mixed')
                imported = self.media_call('importSource', {'projectId': p['id'], 'takeId': take['id']}, expected=201)
                video = self.video_fixture(p, [imported['takeId']])
                self.call('publishVideo', {'id': video, 'revision': 1, 'published': True}, endpoint='api.php')
                self.call(operation, {'id': source['id']}, endpoint='api.php')
                withdrawn = self.call('video', query={'id': video}, endpoint='api.php')
                self.assertFalse(withdrawn['fanPublished'])
                blocked = self.call('publishVideo', {'id': video, 'revision': withdrawn['revision'], 'published': True}, endpoint='api.php', expected=409)
                self.assertEqual(blocked['error']['code'], 'export_source_changed')
                self.assertEqual(self.media_call('prepareEditor', {'projectId': p['id']})['acceptedCount'], 0)
                if operation == 'deleteInterview':
                    with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
                        self.assertEqual(db.execute('SELECT source_take_id,source_interview_id FROM work_source_takes WHERE take_id=?', [imported['takeId']]).fetchone(), (None, source['id']))

    def test_work_17_partial_acceptance_keeps_retakes_actionable(self):
        for accept_first in [True, False]:
            with self.subTest(accept_first=accept_first):
                shot, assignment, _, c = self.assigned()
                one, *_ = self.upload(assignment, c)
                two, *_ = self.upload(assignment, c)
                submitted = self.call('submitAssignment', {'assignmentId': assignment['id'], 'revision': assignment['revision']}, client=c)
                self.assertEqual(submitted['round'], 1)
                first = self.submission(assignment, c, one['submissionId'])
                second = self.submission(assignment, c, two['submissionId'])
                decisions = [(first, 'accepted'), (second, 'retake')]
                if not accept_first:
                    decisions.reverse()
                for sub, decision in decisions:
                    self.call('reviewSubmission', {'submissionId': sub['id'], 'revision': sub['revision'], 'decision': decision, 'note': 'Keep the whole product in frame.'})
                    if accept_first and decision == 'accepted':
                        pending = self.call('assignment', query={'id': assignment['id']}, client=c)
                        self.assertEqual(pending['status'], 'submitted')
                requested = self.call('assignment', query={'id': assignment['id']}, client=c)
                self.assertEqual(requested['status'], 'changes_requested')
                self.assertTrue(requested['canUpload'])
                self.assertEqual(requested['acceptedCount'], 1)
                replacement, *_ = self.upload(requested, c)
                again = self.call('submitAssignment', {'assignmentId': requested['id'], 'revision': requested['revision']}, client=c)
                self.assertEqual(again['round'], 2)
                new_take = self.submission(again, c, replacement['submissionId'])
                self.assertEqual(new_take['round'], 2)
                accepted = self.call('reviewSubmission', {'submissionId': new_take['id'], 'revision': new_take['revision'], 'decision': 'accepted'})
                final = self.call('assignment', query={'id': assignment['id']}, client=c)
                self.assertEqual(final['status'], 'approved')
                self.assertEqual(final['acceptedCount'], 2)
                self.assertEqual(len(final['submissions']), 3)
                old = next(s for s in final['submissions'] if s['id'] == second['id'])
                self.assertEqual(old['status'], 'retake')
                self.assertEqual(old['round'], 1)
                # Reversing a later review also works in the presence of old
                # retained retakes; old rounds never become new outstanding work.
                reopened = self.call('reviewSubmission', {'submissionId': accepted['id'], 'revision': accepted['revision'], 'decision': 'retake', 'note': 'Check this crop again.'})
                self.assertTrue(self.call('assignment', query={'id': assignment['id']}, client=c)['canUpload'])
                self.call('reviewSubmission', {'submissionId': reopened['id'], 'revision': reopened['revision'], 'decision': 'accepted'})
                self.assertEqual(self.call('assignment', query={'id': assignment['id']}, client=c)['status'], 'approved')


def load_tests(loader, tests, pattern):
    return unittest.TestSuite(WorkIntegration(name) for name in loader.getTestCaseNames(WorkIntegration) if name.startswith('test_work_'))


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--php', default='php')
    args, remaining = parser.parse_known_args()
    WorkIntegration.php = args.php
    unittest.main(argv=[__file__, *remaining], verbosity=2)
