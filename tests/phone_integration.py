#!/usr/bin/env python3
"""Actual HTTP pairing, upload, revocation and WebRTC-signaling checks in disposable SQLite storage."""
import argparse
import hashlib
import json
import pathlib
import sqlite3
import time
import unittest
import urllib.parse
import backend_integration as base


class PhoneIntegration(base.BackendIntegration):
    def request_phone(self, action, payload=None, *, phone=None, host=False, client=None, origin=True, query=None, csrf=True):
        client = client or (self.client if host else base.Client(self.base))
        args = {'role': 'host' if host else 'phone', 'action': action, **(query or {})}
        if phone and payload is None:
            args['id'] = phone['id']
        body = None
        headers = {'Accept': 'application/json'}
        if phone:
            headers['X-Phone-Token'] = phone['token']
        if payload is not None:
            payload = {**({'id': phone['id']} if phone else {}), **payload}
            body = json.dumps(payload).encode()
            headers['Content-Type'] = 'application/json'
            if origin:
                headers['Origin'] = self.base if origin is True else origin
            if host and csrf:
                headers['X-CSRF-Token'] = client.csrf
        status, _, raw = client.request('phone-api.php?' + urllib.parse.urlencode(args), body, headers)
        try:
            return status, json.loads(raw)
        except ValueError as e:
            raise AssertionError(f'Unexpected phone response {status}: {raw[:300]}') from e

    def invitation(self, interview=None):
        interview = interview or self.create()
        status, body = self.request_phone('invite', {'interviewId': interview['id']}, host=True)
        self.assertEqual(status, 200, body)
        invite = body['data']
        key = urllib.parse.parse_qs(urllib.parse.urlsplit(invite['url']).fragment)['key'][0]
        return interview, invite, key

    def paired(self, approved=True, interview=None):
        interview, invite, key = self.invitation(interview)
        status, body = self.request_phone('claim', {'inviteId': invite['id'], 'key': key, 'label': 'Test phone'})
        self.assertEqual(status, 201, body)
        phone = body['data']
        if approved:
            status, body = self.request_phone('approve', {'interviewId': interview['id'], 'id': phone['id']}, host=True)
            self.assertEqual(status, 200, body)
        return interview, phone

    def phone_chunk(self, phone, upload_id, index, content):
        boundary = 'AZHottiePhoneTestBoundary'
        pieces = []
        for name, value in {'id': phone['id'], 'uploadId': upload_id, 'index': index}.items():
            pieces.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
        pieces.append(f'--{boundary}\r\nContent-Disposition: form-data; name="chunk"; filename="chunk.bin"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode())
        pieces.extend([content, f'\r\n--{boundary}--\r\n'.encode()])
        status, _, raw = base.Client(self.base).request('phone-api.php?role=phone&action=uploadChunk', b''.join(pieces), {'Content-Type': f'multipart/form-data; boundary={boundary}', 'X-Phone-Token': phone['token'], 'Origin': self.base})
        return status, json.loads(raw)

    def begin(self, phone, content=None, **extra):
        content = content or base.make_wav(300000)
        payload = {'fileName': 'camera.wav', 'mimeType': 'audio/wav', 'totalBytes': len(content), 'durationMs': 4000, **extra}
        status, body = self.request_phone('beginUpload', payload, phone=phone)
        self.assertEqual(status, 201, body)
        return body['data'], content

    def test_phone_01_pairing_requires_approval_and_hides_secrets(self):
        interview, invite, key = self.invitation()
        status, claimed = self.request_phone('claim', {'inviteId': invite['id'], 'key': key, 'label': 'Camera one'})
        self.assertEqual(status, 201, claimed)
        phone = claimed['data']
        status, pending = self.request_phone('status', phone=phone)
        self.assertEqual(status, 200, pending)
        self.assertEqual(pending['data']['connection']['status'], 'pending')
        self.assertNotIn('notes', pending['data']['interview'])
        status, rejected = self.request_phone('beginUpload', {'totalBytes': 4}, phone=phone)
        self.assertEqual(status, 403, rejected)
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            ih = db.execute('SELECT secret_hash FROM phone_invites WHERE id=?', (invite['id'],)).fetchone()[0]
            ph = db.execute('SELECT token_hash FROM phone_connections WHERE id=?', (phone['id'],)).fetchone()[0]
        self.assertEqual(ih, hashlib.sha256(bytes.fromhex(key)).hexdigest())
        self.assertEqual(ph, hashlib.sha256(bytes.fromhex(phone['token'])).hexdigest())
        self.assertNotEqual(ih, key)
        status, listed = self.request_phone('list', host=True, query={'interviewId': interview['id']})
        self.assertEqual(status, 200, listed)
        self.assertNotIn('token', json.dumps(listed))

    def test_phone_02_origin_csrf_and_owner_boundaries(self):
        interview, invite, key = self.invitation()
        for origin in [False, 'https://unrelated.example']:
            status, body = self.request_phone('claim', {'inviteId': invite['id'], 'key': key, 'label': 'Blocked'}, origin=origin)
            self.assertEqual(status, 403, body)
        status, body = self.request_phone('invite', {'interviewId': interview['id']}, host=True, csrf=False)
        self.assertEqual(status, 403, body)
        status, body = self.request_phone('list', host=True, client=base.Client(self.base), query={'interviewId': interview['id']})
        self.assertEqual(status, 401, body)
        first, phone = self.paired()
        other = self.create()
        status, body = self.request_phone('revoke', {'interviewId': other['id'], 'id': phone['id']}, host=True)
        self.assertEqual(status, 404, body)
        status, body = self.request_phone('status', phone={**phone, 'token': '0' * 64})
        self.assertEqual(status, 403, body)

    def test_phone_03_invitation_rotation_expiration_and_request_expiration(self):
        interview, invite, key = self.invitation()
        self.invitation(interview)
        status, body = self.request_phone('claim', {'inviteId': invite['id'], 'key': key, 'label': 'Old QR'})
        self.assertEqual(status, 403, body)
        interview, invite, key = self.invitation(interview)
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            db.execute('UPDATE phone_invites SET expires_at=? WHERE id=?', (int(time.time())-1, invite['id']))
        status, body = self.request_phone('claim', {'inviteId': invite['id'], 'key': key, 'label': 'Expired QR'})
        self.assertEqual(status, 403, body)
        interview, phone = self.paired(False)
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            db.execute('UPDATE phone_connections SET expires_at=? WHERE id=?', (int(time.time())-1, phone['id']))
        status, body = self.request_phone('approve', {'interviewId': interview['id'], 'id': phone['id']}, host=True)
        self.assertEqual(status, 409, body)

    def test_phone_04_upload_scoped_private_chunk_resume_idempotent(self):
        interview, phone = self.paired()
        status, body = self.request_phone('context', {'interviewId': interview['id'], 'questionId': 'private-q', 'questionText': 'Casting-only prompt', 'isPrivate': True}, host=True)
        self.assertEqual(status, 200, body)
        other = self.create()
        upload, content = self.begin(phone, interviewId=other['id'], questionId='tampered', isPrivate=False, purpose='finished')
        self.assertEqual(upload['purpose'], 'take')
        chunk = upload['chunkBytes']
        status, saved = self.phone_chunk(phone, upload['uploadId'], 0, content[:chunk])
        self.assertEqual(status, 200, saved)
        status, replay = self.phone_chunk(phone, upload['uploadId'], 0, content[:chunk])
        self.assertEqual(status, 200, replay)
        status, resumed = self.request_phone('upload', phone=phone, query={'uploadId': upload['uploadId']})
        self.assertEqual(status, 200, resumed)
        self.assertEqual(resumed['data']['nextIndex'], 1)
        for index, offset in enumerate(range(chunk, len(content), chunk), 1):
            status, saved = self.phone_chunk(phone, upload['uploadId'], index, content[offset:offset+chunk])
            self.assertEqual(status, 200, saved)
        status, finished = self.request_phone('finishUpload', {'uploadId': upload['uploadId']}, phone=phone)
        self.assertEqual(status, 200, finished)
        self.assertTrue(finished['data']['saved'])
        self.assertNotIn('mediaUrl', finished['data'])
        status, again = self.request_phone('finishUpload', {'uploadId': upload['uploadId']}, phone=phone)
        self.assertEqual(status, 200, again)
        self.assertEqual(finished['data']['takeId'], again['data']['takeId'])
        status, updated = self.client.api('interview', query={'id': interview['id']})
        take = updated['data']['takes'][0]
        self.assertEqual(len(updated['data']['takes']), 1)
        self.assertEqual(take['questionId'], 'private-q')
        self.assertTrue(take['isPrivate'])
        status, _, raw = self.client.request(take['mediaUrl'].lstrip('/'))
        self.assertEqual(status, 200)
        self.assertEqual(raw, content)
        status, untouched = self.client.api('interview', query={'id': other['id']})
        self.assertEqual(untouched['data']['takes'], [])

    def test_phone_05_phones_cannot_resume_each_others_uploads_or_read_admin_media(self):
        interview, first = self.paired()
        _, second = self.paired(interview=interview)
        upload, content = self.begin(first)
        status, body = self.request_phone('upload', phone=second, query={'uploadId': upload['uploadId']})
        self.assertEqual(status, 404, body)
        status, body = self.phone_chunk(second, upload['uploadId'], 0, content[:upload['chunkBytes']])
        self.assertEqual(status, 404, body)
        status, body = self.request_phone('finishUpload', {'uploadId': upload['uploadId']}, phone=second)
        self.assertEqual(status, 404, body)
        status, body = base.Client(self.base).api('bootstrap')
        self.assertEqual(status, 401, body)

    def test_phone_06_revocation_stops_upload_and_live_signaling(self):
        interview, phone = self.paired()
        upload, content = self.begin(phone)
        status, body = self.request_phone('revoke', {'interviewId': interview['id'], 'id': phone['id']}, host=True)
        self.assertEqual(status, 200, body)
        for action, payload in [('beginUpload', {}), ('finishUpload', {'uploadId': upload['uploadId']}), ('liveOffer', {'nonce':'1'*32,'sdp':'v=0\r\ns=Example\r\nm=video 9 UDP/TLS/RTP/SAVPF 96\r\n'})]:
            status, body = self.request_phone(action, payload, phone=phone)
            self.assertEqual(status, 403, body)
        status, body = self.phone_chunk(phone, upload['uploadId'], 0, content[:upload['chunkBytes']])
        self.assertEqual(status, 403, body)

    def test_phone_07_live_offer_answer_nonce_and_host_presence(self):
        interview, phone = self.paired()
        sdp = 'v=0\r\ns=AZHottie test\r\nm=video 9 UDP/TLS/RTP/SAVPF 96\r\n'
        nonce = 'a' * 32
        status, body = self.request_phone('liveOffer', {'nonce':nonce,'sdp':sdp}, phone=phone)
        self.assertEqual(status, 201, body)
        status, listed = self.request_phone('list', host=True, query={'interviewId': interview['id']})
        self.assertEqual(listed['data']['offers'][0]['offer'], sdp)
        answer = sdp + 'a=recvonly\r\n'
        status, body = self.request_phone('answer', {'interviewId':interview['id'],'id':phone['id'],'nonce':nonce,'sdp':answer}, host=True)
        self.assertEqual(status, 200, body)
        status, polled = self.request_phone('livePoll', phone=phone, query={'nonce':nonce})
        self.assertEqual(status, 200, polled)
        self.assertEqual(polled['data']['answer'], answer)
        status, body = self.request_phone('liveOffer', {'nonce':'b'*32,'sdp':sdp}, phone=phone)
        self.assertEqual(status, 201, body)
        status, body = self.request_phone('answer', {'interviewId':interview['id'],'id':phone['id'],'nonce':nonce,'sdp':answer}, host=True)
        self.assertEqual(status, 409, body)
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            db.execute('UPDATE phone_rooms SET host_seen_at=? WHERE interview_id=?',(int(time.time())-40,interview['id']))
        status, body = self.request_phone('livePoll', phone=phone, query={'nonce':'b'*32})
        self.assertEqual(status, 409, body)
        self.assertEqual(body['error']['code'], 'host_offline')

    def test_phone_08_close_archive_delete_and_restore_do_not_reapprove(self):
        interview, phone = self.paired()
        status, body = self.request_phone('close', {'interviewId':interview['id']}, host=True)
        self.assertEqual(status, 200, body)
        status, body = self.request_phone('status', phone=phone)
        self.assertEqual(status, 403, body)
        interview, phone = self.paired()
        status, body = self.client.api('archiveInterview', {'id':interview['id']})
        self.assertEqual(status, 200, body)
        self.client.api('archiveInterview', {'id':interview['id'],'archived':False})
        status, body = self.request_phone('status', phone=phone)
        self.assertEqual(status, 403, body)
        self.client.api('deleteInterview', {'id':interview['id']})
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            for table in ['phone_rooms','phone_invites','phone_connections']:
                self.assertEqual(db.execute(f'SELECT COUNT(*) FROM {table} WHERE interview_id=?',(interview['id'],)).fetchone()[0],0)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--php', default='php')
    args = parser.parse_args()
    PhoneIntegration.php = args.php
    suite = unittest.TestSuite(PhoneIntegration(name) for name in unittest.defaultTestLoader.getTestCaseNames(PhoneIntegration) if name.startswith('test_phone_'))
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    raise SystemExit(0 if result.wasSuccessful() else 1)
