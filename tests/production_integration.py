#!/usr/bin/env python3
"""Production-workflow and legacy-upgrade checks against a disposable PHP app.

Run: python3 tests/production_integration.py --php /path/to/php
Uses the existing HTTP harness without rerunning its inherited test cases.
"""
from __future__ import annotations

import argparse
import base64
import copy
import json
import pathlib
import sqlite3
import unittest

import backend_integration as harness


PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jQWQAAAAASUVORK5CYII=')
QUESTIONS = [
    {'id': 'opening', 'text': 'How would you introduce the show?', 'category': 'Hosting', 'type': 'choice', 'choices': ['Warm introduction', 'A short character bit'], 'private': False},
    {'id': 'production', 'text': 'What should the production team know?', 'category': 'Casting', 'type': 'open', 'choices': [], 'private': True},
]


class ProductionIntegration(harness.BackendIntegration):
    other = None

    def result(self, action, data=None, query=None, expected=200, client=None):
        status, response = (client or self.client).api(action, data, query)
        self.assertEqual(status, expected, response)
        return response['data'] if expected < 400 else response

    def profile(self, **extra):
        return self.result('saveProfile', {'stageName': 'Alex Example', 'publicBio': 'A public-facing bio', 'privateNotes': 'Private production notes', 'socialHandles': '@alex.example', 'interests': 'Comedy, photography', 'availability': 'Weekends', **extra})

    def questionnaire(self, **extra):
        return self.result('saveQuestionnaire', {'name': 'First interview', 'description': 'A reusable interview set', 'questions': copy.deepcopy(QUESTIONS), **extra})

    def other_client(self):
        cls = type(self)
        if cls.other is None:
            with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
                password_hash = db.execute('SELECT password_hash FROM users WHERE id=1').fetchone()[0]
                db.execute('INSERT INTO users (name,email,password_hash,created_at) VALUES (?,?,?,?)', ['Other owner', 'production-other@example.invalid', password_hash, '2026-01-01T00:00:00Z'])
                db.execute("INSERT INTO user_roles (user_id,role) SELECT id,'admin' FROM users WHERE email='production-other@example.invalid'")
            cls.other = harness.Client(self.base)
            cls.other.login('production-other@example.invalid', self.password)
        return cls.other

    def begin_finished(self, interview_id, **extra):
        content = (pathlib.Path(__file__).resolve().parents[1] / 'qa/fixtures/answer-test.webm').read_bytes()
        upload = self.result('beginUpload', {'interviewId': interview_id, 'purpose': 'finished', 'title': 'Finished interview', 'description': 'A saved cut', 'status': 'review', 'sourceEdit': {'edit': {'order': ['source-take'], 'settings': {'cardDuration': 2.5}}, 'selectedTakeIds': ['source-take']}, 'fileName': 'finished.webm', 'mimeType': 'video/webm', 'durationMs': 6000, 'totalBytes': len(content), **extra}, expected=201)
        return upload, content

    def complete_finished(self, interview_id, **extra):
        upload, content = self.begin_finished(interview_id, **extra)
        for index, offset in enumerate(range(0, len(content), upload['chunkBytes'])):
            status, response = self.client.multipart('uploadChunk', {'uploadId': upload['uploadId'], 'index': index}, 'chunk', content[offset:offset + upload['chunkBytes']])
            self.assertEqual(status, 200, response)
        video = self.result('finishUpload', {'uploadId': upload['uploadId']})['video']
        return video, content, upload

    def test_production_01_profile_crud_photo_and_history(self):
        profile = self.profile()
        self.assertEqual(profile['publicBio'], 'A public-facing bio')
        self.assertEqual(profile['privateNotes'], 'Private production notes')
        self.assertIn(profile['id'], [p['id'] for p in self.result('profiles')])
        changed = self.result('saveProfile', {'id': profile['id'], 'revision': profile['revision'], 'stageName': 'Alex Updated'})
        self.assertEqual(changed['stageName'], 'Alex Updated')
        self.result('saveProfile', {'id': profile['id'], 'revision': profile['revision'], 'stageName': 'Stale name'}, expected=409)
        interview = self.create(modelId=profile['id'], participantName='Historical Stage Name')
        details = self.result('profile', query={'id': profile['id']})
        self.assertEqual(details['interviewCount'], 1)
        self.assertEqual(details['interviews'][0]['id'], interview['id'])
        status, response = self.client.multipart('uploadProfilePhoto', {'id': profile['id']}, 'photo', PNG, 'headshot.png', 'image/png')
        self.assertEqual(status, 200, response)
        photo_url = response['data']['photoUrl'].lstrip('/')
        status, headers, content = self.client.request(photo_url)
        self.assertEqual(status, 200)
        self.assertEqual(headers['Content-Type'], 'image/png')
        self.assertEqual(content, PNG)
        self.assertEqual(harness.Client(self.base).request(photo_url)[0], 401)
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            storage_name = db.execute('SELECT photo_storage_name FROM model_profiles WHERE id=?', [profile['id']]).fetchone()[0]
        photo_path = self.root / 'storage/media' / storage_name
        self.assertTrue(photo_path.is_file())
        # A replacement is validated before the existing good photo is removed.
        status, response = self.client.multipart('uploadProfilePhoto', {'id': profile['id']}, 'photo', b'<svg onload="alert(1)"></svg>', 'fake.png', 'image/png')
        self.assertEqual(status, 415, response)
        self.assertTrue(photo_path.is_file())
        self.assertEqual(self.client.request(photo_url)[2], PNG)
        self.result('archiveProfile', {'id': profile['id'], 'status': 'archived'})
        self.result('createInterview', {'title': 'Blocked archived profile', 'modelId': profile['id']}, expected=400)
        self.result('archiveProfile', {'id': profile['id'], 'status': 'active'})
        self.result('deleteProfile', {'id': profile['id']})
        remaining = self.result('interview', query={'id': interview['id']})
        self.assertIsNone(remaining['modelId'])
        self.assertEqual(remaining['participantName'], 'Historical Stage Name')
        self.assertFalse(photo_path.exists())
        self.result('profile', query={'id': profile['id']}, expected=404)

    def test_production_02_profile_and_template_ownership(self):
        profile, questionnaire, other = self.profile(), self.questionnaire(), self.other_client()
        for action, data, query in [
            ('profile', None, {'id': profile['id']}), ('saveProfile', {'id': profile['id'], 'stageName': 'Wrong owner'}, None),
            ('deleteProfile', {'id': profile['id']}, None), ('archiveProfile', {'id': profile['id'], 'status': 'archived'}, None),
            ('questionnaire', None, {'id': questionnaire['id']}), ('saveQuestionnaire', {'id': questionnaire['id'], 'name': 'Wrong owner'}, None),
            ('deleteQuestionnaire', {'id': questionnaire['id']}, None),
        ]:
            with self.subTest(action=action): self.result(action, data, query, expected=404, client=other)
        self.result('createInterview', {'title': 'Wrong model', 'modelId': profile['id']}, expected=404, client=other)
        self.result('createInterview', {'title': 'Wrong questionnaire', 'questionnaireId': questionnaire['id']}, expected=404, client=other)
        own_other = self.result('createInterview', {'title': 'Other interview'}, expected=201, client=other)
        self.result('updateInterview', {'id': own_other['id'], 'modelId': profile['id']}, expected=404, client=other)
        self.assertNotIn(profile['id'], [p['id'] for p in self.result('profiles', client=other)])
        self.assertNotIn(questionnaire['id'], [q['id'] for q in self.result('questionnaires', client=other)])
        self.assertEqual(self.client.api('saveProfile', {'stageName': 'No CSRF'}, csrf=False)[0], 403)
        self.result('createInterview', {'title': 'Missing model', 'modelId': '0' * 32}, expected=404)

    def test_production_03_questionnaire_validation_and_duplicate(self):
        invalid = [[], [{'id': 'one', 'type': 'choice', 'text': 'Only one option?', 'choices': ['One']}],
            [{'id': 'one', 'type': 'choice', 'text': 'Empty option?', 'choices': ['One', '']}],
            [{'id': 'one', 'type': 'open', 'text': ''}],
            [QUESTIONS[0], QUESTIONS[0]], [{'id': 'one', 'text': 'Wrong type', 'type': 'javascript'}]]
        for questions in invalid:
            with self.subTest(questions=questions):
                self.result('saveQuestionnaire', {'name': 'Invalid set', 'questions': questions}, expected=400)
        first = self.questionnaire()
        duplicate = self.questionnaire(name='Copy of first interview', questions=first['questions'])
        self.assertNotEqual(first['id'], duplicate['id'])
        self.assertEqual(first['questions'], duplicate['questions'])
        edited = copy.deepcopy(first['questions']); edited[0]['text'] = 'An updated opening?'
        changed = self.result('saveQuestionnaire', {'id': first['id'], 'revision': first['revision'], 'questions': edited})
        self.assertEqual(changed['revision'], first['revision'] + 1)
        self.result('saveQuestionnaire', {'id': first['id'], 'revision': first['revision'], 'questions': edited}, expected=409)
        self.assertEqual(self.result('questionnaire', query={'id': duplicate['id']})['questions'][0]['text'], QUESTIONS[0]['text'])

    def test_production_04_questionnaire_snapshot_is_historical(self):
        questionnaire = self.questionnaire()
        interview = self.create(questionnaireId=questionnaire['id'], state={'responses': {'opening': {'choices': [1], 'text': 'Saved answer'}}})
        snapshot = interview['questionnaireSnapshot']
        self.assertEqual(snapshot['questions'], QUESTIONS)
        changed_questions = copy.deepcopy(QUESTIONS); changed_questions.reverse(); changed_questions[0]['text'] = 'Changed after the interview'
        self.result('saveQuestionnaire', {'id': questionnaire['id'], 'name': 'New title', 'questions': changed_questions})
        self.result('archiveQuestionnaire', {'id': questionnaire['id'], 'status': 'archived'})
        self.result('createInterview', {'title': 'Archived set', 'questionnaireId': questionnaire['id']}, expected=400)
        original = self.result('interview', query={'id': interview['id']})
        self.assertEqual(original['questionnaireSnapshot'], snapshot)
        self.result('updateInterview', {'id': interview['id'], 'questionnaireId': None, 'questionnaireSnapshot': {'questions': []}})
        self.assertEqual(self.result('interview', query={'id': interview['id']})['questionnaireSnapshot'], snapshot)
        self.result('deleteQuestionnaire', {'id': questionnaire['id']})
        original = self.result('interview', query={'id': interview['id']})
        self.assertIsNone(original['questionnaireId'])
        self.assertEqual(original['questionnaireSnapshot'], snapshot)
        self.assertEqual(original['state']['responses']['opening']['text'], 'Saved answer')

    def test_production_05_take_review_preserves_edit_and_checks_time(self):
        interview = self.create(state={'edit': {'order': [], 'segments': {}}, 'responses': {'q-test': {'text': 'Original answer'}}})
        take, _ = self.direct_take(interview['id'])
        before = self.result('interview', query={'id': interview['id']})
        changed = self.result('updateTakeReview', {'takeId': take['id'], 'isFavorite': True, 'reviewStatus': 'shortlisted'})
        self.assertTrue(changed['isFavorite']); self.assertEqual(changed['reviewStatus'], 'shortlisted')
        self.result('addTakeNote', {'takeId': take['id'], 'atMs': 1000, 'text': 'Keep this reaction'})
        changed = self.result('addTakeNote', {'takeId': take['id'], 'atMs': 200, 'text': 'Trim this lead-in'})
        self.assertEqual([note['atMs'] for note in changed['reviewNotes']], [200, 1000])
        for at in [-1, 1201, 'invalid']:
            self.result('addTakeNote', {'takeId': take['id'], 'atMs': at, 'text': 'Invalid timestamp'}, expected=400)
        self.result('addTakeNote', {'takeId': take['id'], 'atMs': 0, 'text': ''}, expected=400)
        self.result('updateTakeReview', {'takeId': take['id'], 'reviewStatus': 'published'}, expected=400)
        after = self.result('interview', query={'id': interview['id']})
        self.assertEqual(after['revision'], before['revision'])
        self.assertEqual(after['state'], before['state'])
        self.assertTrue(after['takes'][0]['isFavorite'])
        self.assertEqual(after['takes'][0]['reviewNotes'], changed['reviewNotes'])
        other = self.other_client()
        self.result('updateTakeReview', {'takeId': take['id'], 'isFavorite': False}, expected=404, client=other)
        self.result('addTakeNote', {'takeId': take['id'], 'atMs': 0, 'text': 'Wrong owner'}, expected=404, client=other)
        self.result('deleteTakeNote', {'takeId': take['id'], 'noteId': changed['reviewNotes'][0]['id']}, expected=404, client=other)
        second, _ = self.direct_take(interview['id'], questionId='q-other')
        self.result('deleteTakeNote', {'takeId': second['id'], 'noteId': changed['reviewNotes'][0]['id']}, expected=404)
        result = self.result('deleteTakeNote', {'takeId': take['id'], 'noteId': changed['reviewNotes'][0]['id']})
        self.assertEqual(len(result['reviewNotes']), 1)
        self.result('deleteTake', {'takeId': take['id']})
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM take_notes WHERE take_id=?', [take['id']]).fetchone()[0], 0)

    def test_production_06_finished_upload_retry_versions_and_source(self):
        profile = self.profile(); interview = self.create(modelId=profile['id'])
        upload, content = self.begin_finished(interview['id'])
        self.assertEqual(upload['purpose'], 'finished')
        first = content[:upload['chunkBytes']]
        for _ in range(2):
            status, response = self.client.multipart('uploadChunk', {'uploadId': upload['uploadId'], 'index': 0}, 'chunk', first)
            self.assertEqual(status, 200, response)
        progress = self.result('upload', query={'uploadId': upload['uploadId']})
        self.assertEqual(progress['nextIndex'], 1); self.assertEqual(progress['receivedBytes'], len(first))
        self.assertEqual(progress['meta']['sourceEdit'], upload['meta']['sourceEdit'])
        self.result('finishUpload', {'uploadId': upload['uploadId']}, expected=409)
        for index, offset in enumerate(range(upload['chunkBytes'], len(content), upload['chunkBytes']), 1):
            status, response = self.client.multipart('uploadChunk', {'uploadId': upload['uploadId'], 'index': index}, 'chunk', content[offset:offset + upload['chunkBytes']])
            self.assertEqual(status, 200, response)
        video = self.result('finishUpload', {'uploadId': upload['uploadId']})['video']
        repeated = self.result('finishUpload', {'uploadId': upload['uploadId']})['video']
        self.assertEqual(video['id'], repeated['id']); self.assertEqual(video['version'], 1)
        self.assertEqual(video['modelId'], profile['id'])
        self.assertEqual(video['sourceEdit'], upload['meta']['sourceEdit'])
        complete = self.result('upload', query={'uploadId': upload['uploadId']})
        self.assertEqual(complete['videoId'], video['id']); self.assertIsNone(complete['takeId'])
        parent = self.result('interview', query={'id': interview['id']})
        self.assertEqual(parent['takeCount'], 0)
        self.assertEqual(parent['totalDurationMs'], 0)
        self.result('updateInterview', {'id': interview['id'], 'state': {'edit': {'order': ['changed-after-export']}}})
        self.result('updateVideo', {'id': video['id'], 'sourceEdit': {'changed': True}})
        self.assertEqual(self.result('video', query={'id': video['id']})['sourceEdit'], video['sourceEdit'])
        second, _, _ = self.complete_finished(interview['id'])
        self.assertEqual(second['version'], 2)
        self.result('deleteVideo', {'id': second['id']})
        third, _, _ = self.complete_finished(interview['id'])
        self.assertEqual(third['version'], 3, 'Deleted versions must not have their number reused')
        self.assertEqual(len(self.result('videos', query={'interviewId': interview['id']})), 2)
        self.result('deleteProfile', {'id': profile['id']})
        unlinked = self.result('video', query={'id': video['id']})
        self.assertIsNone(unlinked['modelId'])
        self.assertEqual(unlinked['participantName'], video['participantName'])

    def test_production_07_finished_metadata_archive_ranges_and_ownership(self):
        interview = self.create(); video, content, _ = self.complete_finished(interview['id'])
        changed = self.result('updateVideo', {'id': video['id'], 'revision': video['revision'], 'title': 'Ready episode', 'status': 'published', 'description': 'Final description', 'publishedUrl': 'https://example.com/episode/1'})
        self.assertEqual(changed['publishedUrl'], 'https://example.com/episode/1')
        self.result('updateVideo', {'id': video['id'], 'revision': video['revision'], 'title': 'Stale'}, expected=409)
        for invalid in ['javascript:alert(1)', 'data:text/html,hello', 'file:///etc/passwd', '/relative']:
            self.result('updateVideo', {'id': video['id'], 'publishedUrl': invalid}, expected=400)
        self.result('updateVideo', {'id': video['id'], 'status': 'unsupported'}, expected=400)
        archived = self.result('archiveVideo', {'id': video['id'], 'archived': True})
        self.assertTrue(archived['archived'])
        self.assertNotIn(video['id'], [item['id'] for item in self.result('videos', query={'status': 'active'})])
        self.assertIn(video['id'], [item['id'] for item in self.result('videos', query={'status': 'archived'})])
        restored = self.result('archiveVideo', {'id': video['id'], 'archived': False})
        self.assertFalse(restored['archived']); self.assertEqual(restored['status'], 'published')
        status, headers, data = self.client.request(video['mediaUrl'].lstrip('/'), headers={'Range': 'bytes=7-31'})
        self.assertEqual(status, 206); self.assertEqual(data, content[7:32]); self.assertEqual(headers['Content-Type'], 'video/webm')
        status, headers, data = self.client.request(video['downloadUrl'].lstrip('/'), method='HEAD')
        self.assertEqual(status, 200); self.assertEqual(data, b''); self.assertIn('attachment;', headers['Content-Disposition'])
        self.assertEqual(headers['Content-Length'], str(len(content)))
        self.assertEqual(harness.Client(self.base).request(video['mediaUrl'].lstrip('/'))[0], 401)
        other = self.other_client()
        self.result('video', query={'id': video['id']}, expected=404, client=other)
        self.result('videos', query={'interviewId': interview['id']}, expected=404, client=other)
        for action in ['updateVideo', 'archiveVideo', 'deleteVideo']:
            self.result(action, {'id': video['id']}, expected=404, client=other)
        self.assertEqual(other.request(video['mediaUrl'].lstrip('/'))[0], 404)
        self.assertNotIn(video['id'], [item['id'] for item in self.result('videos', client=other)])

    def test_production_08_delete_parent_cleans_originals_exports_and_uploads(self):
        interview = self.create(); take, _ = self.direct_take(interview['id']); video, _, saved_upload = self.complete_finished(interview['id'])
        self.result('addTakeNote', {'takeId': take['id'], 'atMs': 0, 'text': 'Will be deleted with parent'})
        pending, content = self.begin_finished(interview['id'])
        status, response = self.client.multipart('uploadChunk', {'uploadId': pending['uploadId'], 'index': 0}, 'chunk', content[:pending['chunkBytes']])
        self.assertEqual(status, 200, response)
        paths = [self.root / 'storage/media' / f"{take['id']}.bin", self.root / 'storage/media' / f"{video['id']}.bin", self.root / 'storage/uploads' / f"{pending['uploadId']}.part"]
        self.assertTrue(all(path.is_file() for path in paths))
        self.result('deleteInterview', {'id': interview['id']})
        self.assertTrue(all(not path.exists() for path in paths))
        for action, query in [('interview', {'id': interview['id']}), ('video', {'id': video['id']}), ('upload', {'uploadId': saved_upload['uploadId']}), ('upload', {'uploadId': pending['uploadId']})]:
            self.result(action, query=query, expected=404)
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM take_notes WHERE take_id=?', [take['id']]).fetchone()[0], 0)
            self.assertEqual(db.execute('SELECT COUNT(*) FROM upload_chunks WHERE upload_id=?', [pending['uploadId']]).fetchone()[0], 0)

    def test_production_09_finished_upload_rejects_fake_or_audio_files(self):
        interview = self.create()
        for content, mime, expected in [(b'<html>not video</html>', 'video/webm', 415), (harness.make_wav(), 'audio/wav', 415)]:
            upload = self.result('beginUpload', {'interviewId': interview['id'], 'purpose': 'finished', 'totalBytes': len(content), 'mimeType': mime}, expected=201)
            status, response = self.client.multipart('uploadChunk', {'uploadId': upload['uploadId'], 'index': 0}, 'chunk', content)
            self.assertEqual(status, 200, response)
            self.result('finishUpload', {'uploadId': upload['uploadId']}, expected=expected)
            self.result('abandonUpload', {'uploadId': upload['uploadId']})
        self.assertEqual(self.result('videos', query={'interviewId': interview['id']}), [])


class LegacyUpgradeIntegration(harness.BackendIntegration):
    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.stop_server()
        path = cls.root / 'storage/interviews.sqlite'
        with sqlite3.connect(path) as db:
            user = db.execute('SELECT id,name,email,password_hash,created_at,last_login_at FROM users WHERE id=1').fetchone()
        for suffix in ['', '-wal', '-shm']:
            pathlib.Path(str(path) + suffix).unlink(missing_ok=True)
        cls.legacy_id, cls.legacy_take = 'a' * 32, 'b' * 32
        cls.legacy_state = {'responses': {'q001': {'text': 'Saved in version 1.0.1', 'choices': [2]}}, 'edit': {'order': [cls.legacy_take], 'segments': {cls.legacy_take: {'included': True, 'trimStart': 0.2, 'trimEnd': 1.1}}}, 'customQuestions': []}
        cls.legacy_bytes = harness.make_wav()
        with sqlite3.connect(path) as db:
            db.executescript('''
                CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT,name VARCHAR(120) NOT NULL,email VARCHAR(254) NOT NULL UNIQUE,password_hash VARCHAR(255) NOT NULL,created_at VARCHAR(32) NOT NULL,last_login_at VARCHAR(32));
                CREATE TABLE interviews (id CHAR(32) PRIMARY KEY,owner_id INTEGER NOT NULL,title VARCHAR(200) NOT NULL,participant_name VARCHAR(200) NOT NULL,notes TEXT NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'active',state_json TEXT NOT NULL,revision INTEGER NOT NULL DEFAULT 1,created_at VARCHAR(32) NOT NULL,updated_at VARCHAR(32) NOT NULL,archived_at VARCHAR(32),FOREIGN KEY(owner_id) REFERENCES users(id));
                CREATE TABLE takes (id CHAR(32) PRIMARY KEY,interview_id CHAR(32) NOT NULL,owner_id INTEGER NOT NULL,question_id VARCHAR(160) NOT NULL,question_text TEXT NOT NULL,label VARCHAR(200) NOT NULL,mime_type VARCHAR(120) NOT NULL,file_name VARCHAR(250) NOT NULL,storage_name VARCHAR(80) NOT NULL UNIQUE,size_bytes BIGINT NOT NULL,duration_ms BIGINT NOT NULL,answer_start_ms BIGINT,is_private INTEGER NOT NULL DEFAULT 0,created_at VARCHAR(32) NOT NULL,FOREIGN KEY(interview_id) REFERENCES interviews(id) ON DELETE CASCADE,FOREIGN KEY(owner_id) REFERENCES users(id));
                CREATE TABLE uploads (id CHAR(32) PRIMARY KEY,interview_id CHAR(32) NOT NULL,owner_id INTEGER NOT NULL,question_id VARCHAR(160) NOT NULL,question_text TEXT NOT NULL,label VARCHAR(200) NOT NULL,mime_type VARCHAR(120) NOT NULL,file_name VARCHAR(250) NOT NULL,total_bytes BIGINT NOT NULL,received_bytes BIGINT NOT NULL DEFAULT 0,next_index INTEGER NOT NULL DEFAULT 0,chunk_bytes INTEGER NOT NULL,duration_ms BIGINT NOT NULL,answer_start_ms BIGINT,is_private INTEGER NOT NULL DEFAULT 0,status VARCHAR(16) NOT NULL DEFAULT 'pending',take_id CHAR(32),created_at VARCHAR(32) NOT NULL,updated_at VARCHAR(32) NOT NULL,FOREIGN KEY(interview_id) REFERENCES interviews(id) ON DELETE CASCADE,FOREIGN KEY(owner_id) REFERENCES users(id));
                CREATE TABLE upload_chunks (upload_id CHAR(32) NOT NULL,chunk_index INTEGER NOT NULL,size_bytes INTEGER NOT NULL,sha256 CHAR(64) NOT NULL,PRIMARY KEY(upload_id,chunk_index),FOREIGN KEY(upload_id) REFERENCES uploads(id) ON DELETE CASCADE);
                CREATE TABLE auth_attempts (attempt_key CHAR(64) PRIMARY KEY,attempt_count INTEGER NOT NULL,window_started BIGINT NOT NULL);
            ''')
            db.execute('INSERT INTO users VALUES (?,?,?,?,?,?)', user)
            db.execute('INSERT INTO interviews VALUES (?,?,?,?,?,?,?,?,?,?,?)', [cls.legacy_id,1,'Legacy interview','Original Performer','Keep these notes','archived',json.dumps(cls.legacy_state),7,'2026-01-01T00:00:00Z','2026-01-02T00:00:00Z','2026-01-02T00:00:00Z'])
            db.execute('INSERT INTO takes VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [cls.legacy_take,cls.legacy_id,1,'q001','Original question','Original take','audio/wav','original.wav',cls.legacy_take+'.bin',len(cls.legacy_bytes),1200,200,1,'2026-01-01T00:00:00Z'])
        (cls.root / 'storage/media' / (cls.legacy_take + '.bin')).write_bytes(cls.legacy_bytes)
        cls.start_server()

    def test_production_10_legacy_database_upgrades_without_data_loss(self):
        status, result = self.client.api('interview', query={'id': self.legacy_id})
        self.assertEqual(status, 200, result)
        interview = result['data']
        self.assertEqual(interview['state'], self.legacy_state)
        self.assertEqual(interview['revision'], 7)
        self.assertEqual(interview['status'], 'archived')
        self.assertEqual(interview['participantName'], 'Original Performer')
        self.assertIsNone(interview['modelId']); self.assertIsNone(interview['questionnaireSnapshot'])
        take = interview['takes'][0]
        self.assertFalse(take['isFavorite']); self.assertEqual(take['reviewStatus'], 'unreviewed'); self.assertEqual(take['reviewNotes'], [])
        self.assertTrue(take['isPrivate']); self.assertEqual(take['answerStartMs'], 200)
        self.assertEqual(self.client.request(take['mediaUrl'].lstrip('/'))[2], self.legacy_bytes)
        for _ in range(3):
            boot = self.client.bootstrap()
            self.assertEqual(boot['profiles'], []); self.assertEqual(boot['questionnaires'], [])
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            self.assertGreaterEqual(db.execute('SELECT version FROM studio_schema WHERE id=1').fetchone()[0], 2)
            self.assertEqual(db.execute('SELECT COUNT(*) FROM interviews').fetchone()[0], 1)
            self.assertEqual(db.execute('SELECT COUNT(*) FROM takes').fetchone()[0], 1)
            # Re-entering a partially recorded migration reruns only absent steps.
            db.execute('UPDATE studio_schema SET version=1 WHERE id=1')
        self.client.bootstrap()
        again = self.client.api('interview', query={'id': self.legacy_id})[1]['data']
        self.assertEqual(again['state'], self.legacy_state)
        self.assertEqual(again['revision'], 7)
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            columns = [row[1] for row in db.execute('PRAGMA table_info(interviews)')]
            self.assertEqual(len(columns), len(set(columns)))
            self.assertGreaterEqual(db.execute('SELECT version FROM studio_schema WHERE id=1').fetchone()[0], 2)
        self.stop_server(); self.start_server()
        self.assertEqual(self.client.api('interview', query={'id': self.legacy_id})[1]['data']['state'], self.legacy_state)
        self.assertEqual(self.client.request(take['mediaUrl'].lstrip('/'))[2], self.legacy_bytes)


def load_tests(loader, tests, pattern):
    return unittest.TestSuite(cls(name) for cls in [ProductionIntegration, LegacyUpgradeIntegration]
                              for name in loader.getTestCaseNames(cls) if name.startswith('test_production_'))


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--php', default='php')
    arguments, remaining = parser.parse_known_args()
    ProductionIntegration.php = LegacyUpgradeIntegration.php = arguments.php
    unittest.main(argv=[__file__, *remaining], verbosity=2)
