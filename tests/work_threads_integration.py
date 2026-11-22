#!/usr/bin/env python3
"""Private assignment conversations in a disposable four-worker HTTP install.

No real model accounts, external messages, providers, or production storage.
"""
from __future__ import annotations

import argparse
import concurrent.futures
import json
import sqlite3
import threading
import unittest
import uuid

import backend_integration as harness
from work_integration import WorkIntegration


class WorkThreadsIntegration(WorkIntegration):
    def thread_call(self, action, data=None, query=None, client=None, expected=200, csrf=True):
        return self.call(action, data=data, query=query, client=client, expected=expected,
                         csrf=csrf, endpoint='work-thread-api.php')

    def thread(self, assignment, client=None, before=None, expected=200):
        query = {'assignmentId': assignment['id'] if isinstance(assignment, dict) else assignment}
        if before is not None:
            query['beforeSequence'] = before
        return self.thread_call('thread', query=query, client=client, expected=expected)

    def send(self, assignment, text='Which framing should I use?', client=None, token=None, expected=200, csrf=True):
        body = {'assignmentId': assignment['id'], 'text': text,
                'requestId': token or 'message:' + uuid.uuid4().hex}
        return self.thread_call('send', body, client=client, expected=expected, csrf=csrf)

    def mark(self, assignment, through, client=None, expected=200):
        return self.thread_call('markRead', {'assignmentId': assignment['id'], 'throughSequence': through},
                                client=client, expected=expected)

    def message_count(self, assignment):
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            return db.execute('SELECT COUNT(*) FROM work_assignment_messages WHERE assignment_id=?',
                              [assignment['id']]).fetchone()[0]

    def notice_count(self, assignment):
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            return db.execute("SELECT COUNT(*) FROM work_notifications WHERE assignment_id=? AND kind='assignment_message'",
                              [assignment['id']]).fetchone()[0]

    def raw(self, action, body, client):
        status, _, raw = client.request('work-thread-api.php?action=' + action, json.dumps(body).encode(),
                                       {'Content-Type': 'application/json', 'X-CSRF-Token': client.csrf})
        return status, json.loads(raw)

    def test_threads_01_private_auth_roles_and_csrf(self):
        shot, assignment, _, model = self.assigned()
        _, outsider = self.member('model')
        _, other_admin = self.member('admin')
        _, fan = self.member('fan')
        for client, status in [(harness.Client(self.base), 401), (outsider, 404),
                               (other_admin, 404), (fan, 403)]:
            self.thread(assignment, client=client, expected=status)
            self.send(assignment, client=client, expected=status)
            self.mark(assignment, 0, client=client, expected=status)
        self.send(assignment, client=model, csrf=False, expected=403)
        self.assertEqual(self.message_count(assignment), 0)
        for client in [self.client, model]:
            data = self.thread(assignment, client=client)
            self.assertTrue(data['canPost'])
            self.assertEqual(data['messages'], [])
            self.assertEqual(data['latestSequence'], 0)
            self.assertEqual(data['csrf'], client.csrf)
            self.assertNotIn('email', json.dumps(data))
        self.assertEqual(self.call('assignment', query={'id': assignment['id']}, client=model)['questionUnreadCount'], 0)

    def test_threads_02_send_reply_unread_and_private_notification(self):
        _, assignment, user_id, model = self.assigned()
        before_revision = assignment['revision']
        first = self.send(assignment, 'Which outfit and framing should I use?', client=model)
        message = first['messages'][0]
        self.assertEqual(message['sequence'], 1)
        self.assertEqual(message['authorId'], user_id)
        self.assertTrue(message['isOwn'])
        self.assertEqual(first['sentMessageId'], message['id'])
        self.assertEqual(first['unreadCount'], 0)
        admin = self.thread(assignment)
        self.assertFalse(admin['messages'][0]['isOwn'])
        self.assertEqual(admin['unreadCount'], 1)
        self.assertEqual(self.call('assignment', query={'id': assignment['id']})['questionUnreadCount'], 1)
        notices = [n for n in self.call('notifications')['notifications']
                   if n['assignmentId'] == assignment['id'] and n['kind'] == 'assignment_message']
        self.assertEqual(len(notices), 1)
        self.assertNotIn('Which outfit', notices[0]['message'])
        self.assertIn('assignmentId=' + assignment['id'], notices[0]['url'])
        reply = self.send(assignment, 'Use the blue outfit and a portrait medium shot.')
        self.assertEqual([m['sequence'] for m in reply['messages']], [1, 2])
        self.assertEqual(reply['unreadCount'], 1)
        member = self.thread(assignment, client=model)
        self.assertEqual(member['unreadCount'], 1)
        self.assertEqual(self.mark(assignment, 2, client=model)['unreadCount'], 0)
        self.assertEqual(self.mark(assignment, 2)['unreadCount'], 0)
        self.assertEqual(self.call('assignment', query={'id': assignment['id']})['revision'], before_revision)
        self.assertEqual(self.notice_count(assignment), 2)

    def test_threads_03_retry_receipts_reject_changed_message_and_assignment(self):
        _, assignment, user_id, model = self.assigned()
        token = 'retry:' + uuid.uuid4().hex
        first = self.send(assignment, 'Should I deliver the line twice?', client=model, token=token)
        second = self.send(assignment, 'Should I deliver the line twice?', client=model, token=token)
        self.assertEqual(first['sentMessageId'], second['sentMessageId'])
        self.assertEqual(self.message_count(assignment), 1)
        self.assertEqual(self.notice_count(assignment), 1)
        error = self.send(assignment, 'Changed meaning', client=model, token=token, expected=409)
        self.assertEqual(error['error']['code'], 'request_conflict')
        _, other, _, _ = self.assigned(client=model, user_id=user_id)
        error = self.send(other, 'Should I deliver the line twice?', client=model, token=token, expected=409)
        self.assertEqual(error['error']['code'], 'request_conflict')
        self.assertEqual(self.message_count(other), 0)

    def test_threads_04_ordered_pagination_preserves_newer_unread(self):
        _, assignment, _, model = self.assigned()
        for index in range(65):
            self.send(assignment, 'Message ' + str(index + 1), client=model)
        latest = self.thread(assignment)
        self.assertEqual([m['sequence'] for m in latest['messages']], list(range(36, 66)))
        self.assertTrue(latest['hasMore'])
        self.assertEqual(latest['oldestSequence'], 36)
        self.assertEqual(latest['latestSequence'], 65)
        earlier = self.thread(assignment, before=latest['oldestSequence'])
        self.assertEqual([m['sequence'] for m in earlier['messages']], list(range(6, 36)))
        self.assertTrue(earlier['hasMore'])
        first = self.thread(assignment, before=earlier['oldestSequence'])
        self.assertEqual([m['sequence'] for m in first['messages']], list(range(1, 6)))
        self.assertFalse(first['hasMore'])
        self.assertEqual(self.mark(assignment, first['messages'][-1]['sequence'])['unreadCount'], 60)
        self.assertEqual(self.thread(assignment)['unreadCount'], 60)
        self.assertEqual(self.thread(assignment, before=1)['messages'], [])

    def test_threads_05_read_high_water_is_monotonic_and_never_future(self):
        _, assignment, _, model = self.assigned()
        self.send(assignment, 'First question', client=model)
        self.send(assignment, 'Second question', client=model)
        self.assertEqual(self.mark(assignment, 1)['unreadCount'], 1)
        self.assertEqual(self.mark(assignment, 0)['throughSequence'], 1)
        error = self.mark(assignment, 3, expected=409)
        self.assertEqual(error['error']['code'], 'read_sequence_ahead')
        self.assertEqual(self.thread(assignment)['unreadCount'], 1)
        self.assertEqual(self.mark(assignment, 2)['unreadCount'], 0)
        self.send(assignment, 'New message after the old page was read', client=model)
        self.assertEqual(self.mark(assignment, 2)['unreadCount'], 1)
        self.assertEqual(self.mark(assignment, 2)['throughSequence'], 2)

    def test_threads_06_removal_preserves_explicit_assignment_conversation(self):
        shot, assignment, user_id, model = self.assigned()
        self.send(assignment, 'Question before removal', client=model)
        roster = self.call('roster', query={'projectId': shot['projectId']})
        self.call('removeMember', {'projectId': shot['projectId'], 'revision': roster['revision'], 'userId': user_id})
        self.assertTrue(self.thread(assignment, client=model)['canPost'])
        reply = self.send(assignment, 'This existing assignment should still continue.')
        self.assertEqual(reply['latestSequence'], 2)
        self.send(assignment, 'Understood.', client=model)
        self.assertEqual(self.message_count(assignment), 3)

    def test_threads_07_current_roles_suspension_and_owner_access(self):
        _, assignment, user_id, model = self.assigned()
        token = 'live-access:' + uuid.uuid4().hex
        self.send(assignment, 'Existing member question', client=model, token=token)
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            db.execute("DELETE FROM user_roles WHERE user_id=? AND role='model'", [user_id])
        self.thread(assignment, client=model, expected=403)
        self.send(assignment, 'Existing member question', client=model, token=token, expected=403)
        self.assertFalse(self.thread(assignment)['canPost'])
        self.send(assignment, 'Cannot send to unavailable member', expected=409)
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            db.execute("INSERT INTO user_roles(user_id,role) VALUES(?,'model')", [user_id])
            db.execute("UPDATE users SET status='suspended' WHERE id=?", [user_id])
        self.thread(assignment, client=model, expected=401)
        self.assertFalse(self.thread(assignment)['canPost'])
        self.assertEqual(self.message_count(assignment), 1)

        owner_id, owner = self.member('admin')
        member_id, member = self.member('model')
        project = self.project(client=owner)
        self.include(project, member_id, client=owner)
        shot = self.shot(project, client=owner)
        other_assignment = self.call('assign', {'shotId': shot['id'], 'userId': member_id}, client=owner)
        self.send(other_assignment, 'A producer question', client=owner)
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            db.execute("DELETE FROM user_roles WHERE user_id=? AND role='admin'", [owner_id])
        self.thread(other_assignment, client=member, expected=404)
        self.send(other_assignment, client=member, expected=404)

    def test_threads_08_project_lifecycle_and_receipt_recovery(self):
        member_id, member = self.member('model')
        project = self.project(status='draft')
        self.include(project, member_id)
        shot = self.shot(project)
        assignment = self.call('assign', {'shotId': shot['id'], 'userId': member_id})
        token = 'draft-question:' + uuid.uuid4().hex
        sent = self.send(assignment, 'Question while preparing the production', client=member, token=token)
        self.assertTrue(sent['canPost'])
        for state in ['completed', 'archived']:
            project = self.call('saveProject', {'id': project['id'], 'revision': project['revision'], 'status': state})
            for client in [self.client, member]:
                self.assertFalse(self.thread(assignment, client=client)['canPost'])
                self.send(assignment, client=client, expected=409)
            recovered = self.send(assignment, 'Question while preparing the production', client=member, token=token)
            self.assertEqual(recovered['sentMessageId'], sent['sentMessageId'])
            self.assertEqual(self.message_count(assignment), 1)
            self.assertEqual(self.notice_count(assignment), 1)
            self.assertEqual(self.mark(assignment, 1)['unreadCount'], 0)
        self.call('saveProject', {'id': project['id'], 'revision': project['revision'], 'status': 'active'})
        self.assertTrue(self.thread(assignment, client=member)['canPost'])

    def test_threads_09_cancelled_declined_and_studio_threads_are_read_only(self):
        _, assignment, _, model = self.assigned()
        self.send(assignment, 'Keep this question in history', client=model)
        cancelled = self.call('cancelAssignment', {'assignmentId': assignment['id'], 'revision': assignment['revision']})
        self.assertEqual(cancelled['status'], 'cancelled')
        self.assertFalse(self.thread(assignment, client=model)['canPost'])
        self.send(assignment, client=model, expected=409)
        self.send(assignment, expected=409)
        self.assertEqual(self.message_count(assignment), 1)
        user_id, other = self.member('model')
        project = self.project()
        self.include(project, user_id)
        shot = self.shot(project)
        invitation = self.call('assign', {'shotId': shot['id'], 'userId': user_id})
        self.call('respond', {'assignmentId': invitation['id'], 'revision': invitation['revision'], 'decision': 'decline'}, client=other)
        self.assertFalse(self.thread(invitation, client=other)['canPost'])
        self.send(invitation, client=other, expected=409)
        studio = self.call('assign', {'shotId': shot['id'], 'userId': 1})
        self.assertFalse(self.thread(studio)['canPost'])
        self.send(studio, expected=409)
        self.assertEqual(self.notice_count(studio), 0)

    def test_threads_10_message_validation_plain_text_and_unicode(self):
        _, assignment, _, model = self.assigned()
        for bad in ['', '   ', None, ['wrong'], 'x' * 4001, '🙂' * 4001]:
            self.send(assignment, bad, client=model, expected=400)
        for token in ['', 'short', 'invalid token with spaces', 'x' * 161]:
            self.thread_call('send', {'assignmentId': assignment['id'], 'text': 'Hello', 'requestId': token},
                             client=model, expected=400)
        self.assertEqual(self.message_count(assignment), 0)
        text = '<script>alert("not executable")</script>\nA literal question & reply.'
        result = self.send(assignment, text, client=model)
        self.assertEqual(result['messages'][0]['text'], text)
        unicode_text = '🙂' * 4000
        result = self.send(assignment, unicode_text, client=model)
        self.assertEqual(result['messages'][-1]['text'], unicode_text)
        self.assertEqual(self.message_count(assignment), 2)

    def test_threads_11_concurrent_distinct_messages_get_unique_order(self):
        _, assignment, _, model = self.assigned()
        barrier = threading.Barrier(2)
        def send_one(item):
            client, text = item
            barrier.wait()
            return self.raw('send', {'assignmentId': assignment['id'], 'text': text,
                                     'requestId': 'concurrent:' + uuid.uuid4().hex}, client)
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            results = list(pool.map(send_one, [(model, 'Model asks'), (self.client, 'Producer replies')]))
        self.assertEqual([r[0] for r in results], [200, 200], results)
        thread = self.thread(assignment)
        self.assertEqual([m['sequence'] for m in thread['messages']], [1, 2])
        self.assertEqual({m['text'] for m in thread['messages']}, {'Model asks', 'Producer replies'})
        self.assertEqual(thread['unreadCount'], 1)
        self.assertEqual(self.thread(assignment, client=model)['unreadCount'], 1)

    def test_threads_12_concurrent_same_receipt_has_one_message_and_notice(self):
        _, assignment, _, model = self.assigned()
        barrier = threading.Barrier(2)
        body = {'assignmentId': assignment['id'], 'text': 'One question despite response retry',
                'requestId': 'duplicate:' + uuid.uuid4().hex}
        def repeat(_):
            barrier.wait()
            return self.raw('send', body, model)
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            results = list(pool.map(repeat, [1, 2]))
        self.assertEqual([r[0] for r in results], [200, 200], results)
        self.assertEqual(results[0][1]['data']['sentMessageId'], results[1][1]['data']['sentMessageId'])
        self.assertEqual(self.message_count(assignment), 1)
        self.assertEqual(self.notice_count(assignment), 1)

    def test_threads_13_read_ack_racing_new_message_keeps_new_message_unread(self):
        _, assignment, _, model = self.assigned()
        self.send(assignment, 'Already rendered', client=model)
        rendered = self.thread(assignment)['messages'][-1]['sequence']
        barrier = threading.Barrier(2)
        def run(kind):
            barrier.wait()
            if kind == 'read':
                return self.raw('markRead', {'assignmentId': assignment['id'], 'throughSequence': rendered}, self.client)
            return self.raw('send', {'assignmentId': assignment['id'], 'text': 'Arrived during acknowledgement',
                                     'requestId': 'read-race:' + uuid.uuid4().hex}, model)
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            results = list(pool.map(run, ['read', 'send']))
        self.assertEqual([r[0] for r in results], [200, 200], results)
        thread = self.thread(assignment)
        self.assertEqual(thread['unreadCount'], 1)
        self.assertEqual(thread['latestSequence'], 2)

    def test_threads_14_migration_replay_retains_messages_reads_and_receipts(self):
        _, assignment, _, model = self.assigned()
        token = 'migration:' + uuid.uuid4().hex
        first = self.send(assignment, 'Retained across migration replay', client=model, token=token)
        self.mark(assignment, 1)
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            db.execute('UPDATE studio_schema SET version=15 WHERE id=1')
        thread = self.thread(assignment)
        self.assertEqual(thread['messages'][0]['id'], first['sentMessageId'])
        self.assertEqual(thread['unreadCount'], 0)
        repeated = self.send(assignment, 'Retained across migration replay', client=model, token=token)
        self.assertEqual(repeated['sentMessageId'], first['sentMessageId'])
        self.assertEqual(self.message_count(assignment), 1)
        self.assertEqual(self.notice_count(assignment), 1)
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            self.assertEqual(db.execute('SELECT version FROM studio_schema').fetchone()[0], 16)


def load_tests(loader, tests, pattern):
    return unittest.TestSuite(WorkThreadsIntegration(name) for name in loader.getTestCaseNames(WorkThreadsIntegration)
                              if name.startswith('test_threads_'))


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--php', default='php')
    args, rest = parser.parse_known_args()
    WorkThreadsIntegration.php = args.php
    unittest.main(argv=[__file__, *rest], verbosity=2)
