#!/usr/bin/env python3
"""Production roster authorization, membership, and concurrency in an isolated install.

No real users, provider calls, browser, or production storage are used.
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
from production_integration import PNG
from work_integration import WorkIntegration


class WorkRosterIntegration(WorkIntegration):
    def roster(self, project, client=None, expected=200):
        return self.call('roster', query={'projectId': project['id']}, client=client, expected=expected)

    def add_members(self, project, ids, **extra):
        revision = self.roster(project)['revision']
        return self.call('addMembers', {'projectId': project['id'], 'revision': revision,
                                       'userIds': ids, **extra})

    def remove_member(self, project, user_id, **extra):
        revision = self.roster(project)['revision']
        return self.call('removeMember', {'projectId': project['id'], 'revision': revision,
                                         'userId': user_id, **extra})

    def raw_post(self, action, body, client=None):
        client = client or self.client
        status, _, raw = client.request('work-api.php?action=' + action, json.dumps(body).encode(),
                                       {'Content-Type': 'application/json', 'X-CSRF-Token': client.csrf})
        return status, json.loads(raw)

    def project_ids(self, client):
        return [p['id'] for p in self.call('myWork', client=client)['projects']]

    def open_ids(self, client):
        return [s['id'] for s in self.call('myWork', client=client)['open']]

    def notification_count(self, project, user_id):
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            return db.execute('SELECT COUNT(*) FROM work_notifications WHERE project_id=? AND recipient_id=?',
                              [project['id'], user_id]).fetchone()[0]

    def reference(self, project, shot=None):
        boundary = 'AZHottieRosterReference'
        fields = {'projectId': project['id'], 'title': 'Roster reference'}
        if shot:
            fields['shotId'] = shot['id']
        pieces = [f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()
                  for key, value in fields.items()]
        pieces.extend([f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="roster.png"\r\nContent-Type: image/png\r\n\r\n'.encode(),
                       PNG, f'\r\n--{boundary}--\r\n'.encode()])
        status, _, raw = self.client.request('work-upload-api.php?action=uploadReference', b''.join(pieces),
                                            {'Content-Type': f'multipart/form-data; boundary={boundary}',
                                             'X-CSRF-Token': self.client.csrf})
        result = json.loads(raw)
        self.assertEqual(status, 201, result)
        return result['data']

    def test_roster_01_owner_roles_csrf_and_redacted_picker(self):
        project = self.project()
        model_id, model = self.member('model', 'customer')
        professional_id, _ = self.member('professional')
        fan_id, fan = self.member('fan')
        _, other_admin = self.member('admin')
        self.roster(project, client=harness.Client(self.base), expected=401)
        self.roster(project, client=model, expected=403)
        self.roster(project, client=fan, expected=403)
        self.roster(project, client=other_admin, expected=404)
        roster = self.roster(project)
        self.assertEqual(roster['projectId'], project['id'])
        self.assertEqual(roster['members'], [])
        self.assertEqual(roster['maxMembers'], 200)
        candidates = {row['id']: row for row in roster['candidates']}
        self.assertIn(model_id, candidates)
        self.assertIn(professional_id, candidates)
        self.assertNotIn(fan_id, candidates)
        self.assertTrue(all(set(row) == {'id', 'name', 'roles'} for row in candidates.values()))
        body = {'projectId': project['id'], 'revision': roster['revision'], 'userIds': [model_id]}
        self.call('addMembers', body, csrf=False, expected=403)
        self.call('addMembers', body, client=model, expected=403)
        self.call('addMembers', body, client=other_admin, expected=404)
        self.assertEqual(self.roster(project)['members'], [])

    def test_roster_02_membership_discovers_empty_production_without_claiming(self):
        project = self.project()
        a_id, a = self.member('model')
        b_id, b = self.member('model', 'professional')
        _, outsider = self.member('model')
        self.assertNotIn(project['id'], self.project_ids(a))
        result = self.add_members(project, [a_id, b_id])
        self.assertEqual({m['userId'] for m in result['members']}, {a_id, b_id})
        self.assertTrue(all(m['eligible'] for m in result['members']))
        self.assertEqual(self.call('project', query={'id': project['id']})['revision'], project['revision'])
        for client in [a, b]:
            self.assertIn(project['id'], self.project_ids(client))
            self.assertEqual(self.call('project', query={'id': project['id']}, client=client)['shots'], [])
        self.assertNotIn(project['id'], self.project_ids(outsider))
        shot = self.shot(project, mode='open', maxContributors=1)
        for client in [a, b]:
            self.assertIn(shot['id'], self.open_ids(client))
        self.assertNotIn(shot['id'], self.open_ids(outsider))
        detail = self.call('shot', query={'id': shot['id']})
        self.assertEqual(detail['assignments'], [])
        self.assertEqual(detail['slotsRemaining'], 1)
        self.assertEqual(self.call('project', query={'id': project['id']})['assignmentCount'], 0)

    def test_roster_03_open_shots_are_scoped_by_roster_and_eligible_role(self):
        project = self.project()
        model_id, model = self.member('model')
        pro_id, professional = self.member('professional')
        _, outsider = self.member('model', 'professional')
        self.add_members(project, [model_id, pro_id])
        model_shot = self.shot(project, mode='open', eligibleRoles=['model'])
        pro_shot = self.shot(project, mode='open', eligibleRoles=['professional'])
        private_shot = self.shot(project, mode='assigned', title='A private individual assignment')
        for client, visible, hidden in [(model, model_shot, pro_shot), (professional, pro_shot, model_shot)]:
            self.assertIn(visible['id'], self.open_ids(client))
            self.assertNotIn(hidden['id'], self.open_ids(client))
            detail = self.call('project', query={'id': project['id']}, client=client)
            self.assertEqual([s['id'] for s in detail['shots']], [visible['id']])
            self.assertNotIn('roster', detail)
            self.assertNotIn('interviewId', detail)
            self.assertNotIn('editorUrl', json.dumps(detail['shots']))
            self.call('shot', query={'id': hidden['id']}, client=client, expected=404)
            self.call('shot', query={'id': private_shot['id']}, client=client, expected=404)
        self.call('project', query={'id': project['id']}, client=outsider, expected=404)
        self.call('shot', query={'id': model_shot['id']}, client=outsider, expected=404)
        self.call('claim', {'shotId': model_shot['id']}, client=outsider, expected=404)

    def test_roster_04_direct_assignment_requires_member_but_owner_capture_is_exempt(self):
        project = self.project()
        model_id, model = self.member('model')
        other_id, other = self.member('model')
        shot = self.shot(project, maxContributors=1)
        rejected = self.call('assign', {'shotId': shot['id'], 'userId': model_id}, expected=409)
        self.assertEqual(rejected['error']['code'], 'roster_required')
        self.add_members(project, [model_id, other_id])
        assigned = self.call('assign', {'shotId': shot['id'], 'userId': model_id})
        self.assertEqual(assigned['status'], 'assigned')
        self.assertEqual([a['id'] for a in self.call('myWork', client=model)['assigned']], [assigned['id']])
        own_detail = self.call('shot', query={'id': shot['id']}, client=model)
        for field in ['slotsRemaining', 'contributorCount', 'assignmentCount', 'acceptedCount', 'approvedAssignmentCount']:
            self.assertIsNone(own_detail[field], field)
        self.call('assignment', query={'id': assigned['id']}, client=other, expected=404)
        self.call('shot', query={'id': shot['id']}, client=other, expected=404)
        owner = self.call('assign', {'shotId': shot['id'], 'userId': 1})
        self.assertEqual(owner['status'], 'accepted')
        detail = self.call('shot', query={'id': shot['id']})
        self.assertEqual(detail['contributorCount'], 1)
        self.assertEqual(detail['assignmentCount'], 2)
        self.assertEqual({m['userId'] for m in self.roster(project)['members']}, {model_id, other_id})

    def test_roster_05_remove_blocks_new_work_preserves_existing_assignment_and_upload(self):
        project = self.project()
        user_id, model = self.member('model')
        self.add_members(project, [user_id])
        shot = self.shot(project)
        assignment = self.call('assign', {'shotId': shot['id'], 'userId': user_id})
        assignment = self.call('respond', {'assignmentId': assignment['id'], 'revision': assignment['revision'],
                                            'decision': 'accept'}, client=model)
        open_shot = self.shot(project, mode='open')
        new_direct = self.shot(project)
        self.remove_member(project, user_id)
        self.assertEqual(self.roster(project)['members'], [])
        self.assertNotIn(open_shot['id'], self.open_ids(model))
        self.call('claim', {'shotId': open_shot['id']}, client=model, expected=404)
        self.call('assign', {'shotId': new_direct['id'], 'userId': user_id}, expected=409)
        again = self.call('assign', {'shotId': shot['id'], 'userId': user_id})
        self.assertEqual(again['id'], assignment['id'])
        self.assertTrue(self.call('assignment', query={'id': assignment['id']}, client=model)['canUpload'])
        saved, _, _, _ = self.upload(assignment, model)
        detail = self.call('assignment', query={'id': assignment['id']}, client=model)
        self.call('submitAssignment', {'assignmentId': assignment['id'], 'revision': detail['revision']}, client=model)
        self.assertEqual(self.submission(assignment, model, saved['submissionId'])['status'], 'submitted')
        self.assertEqual([s['id'] for s in self.call('project', query={'id': project['id']}, client=model)['shots']], [shot['id']])

    def test_roster_06_removed_cancelled_assignee_cannot_reclaim_old_open_shot(self):
        project = self.project()
        user_id, model = self.member('model')
        self.add_members(project, [user_id])
        shot = self.shot(project, mode='open')
        assignment = self.call('claim', {'shotId': shot['id']}, client=model)
        self.call('cancelAssignment', {'assignmentId': assignment['id'], 'revision': assignment['revision']})
        self.remove_member(project, user_id)
        self.assertNotIn(shot['id'], self.open_ids(model))
        detail = self.call('shot', query={'id': shot['id']}, client=model)
        self.assertFalse(detail['canClaim'])
        self.assertIsNone(detail['slotsRemaining'])
        self.assertIsNone(detail['contributorCount'])
        status, result = self.raw_post('claim', {'shotId': shot['id']}, model)
        self.assertIn(status, [404, 409], result)
        self.assertEqual(self.call('assignment', query={'id': assignment['id']})['status'], 'cancelled')
        rejected = self.call('assign', {'shotId': shot['id'], 'userId': user_id}, expected=409)
        self.assertEqual(rejected['error']['code'], 'roster_required')

    def test_roster_07_project_and_shot_references_obey_roster_visibility(self):
        project = self.project()
        user_id, model = self.member('model')
        other_id, other = self.member('model')
        _, stranger = self.member('model')
        self.add_members(project, [user_id, other_id])
        open_shot = self.shot(project, mode='open')
        private_shot = self.shot(project)
        self.call('assign', {'shotId': private_shot['id'], 'userId': user_id})
        project_ref = self.reference(project)
        open_ref = self.reference(project, open_shot)
        private_ref = self.reference(project, private_shot)
        for reference in [project_ref, open_ref]:
            url = reference['mediaUrl'].lstrip('/')
            self.assertEqual(model.request(url)[0], 200)
            self.assertEqual(other.request(url)[0], 200)
            self.assertEqual(stranger.request(url)[0], 404)
        self.assertEqual(other.request(private_ref['mediaUrl'].lstrip('/'))[0], 404)
        self.remove_member(project, other_id)
        self.assertEqual(other.request(project_ref['mediaUrl'].lstrip('/'))[0], 404)
        self.assertEqual(other.request(open_ref['mediaUrl'].lstrip('/'))[0], 404)

    def test_roster_08_request_replay_stale_revision_and_atomic_invalid_batch(self):
        project = self.project()
        a_id, _ = self.member('model')
        b_id, _ = self.member('model')
        fan_id, _ = self.member('fan')
        old = self.roster(project)
        token = 'add-members:' + uuid.uuid4().hex
        body = {'projectId': project['id'], 'revision': old['revision'], 'userIds': [a_id], 'requestId': token}
        saved = self.call('addMembers', body)
        notices = self.notification_count(project, a_id)
        replay = self.call('addMembers', body)
        self.assertEqual(replay['revision'], saved['revision'])
        self.assertEqual(self.notification_count(project, a_id), notices)
        duplicate = self.add_members(project, [a_id, a_id])
        self.assertEqual(duplicate['revision'], saved['revision'])
        self.assertEqual([m['userId'] for m in duplicate['members']], [a_id])
        self.assertEqual(self.notification_count(project, a_id), notices)
        self.call('addMembers', {**body, 'userIds': [b_id]}, expected=409)
        self.call('addMembers', {'projectId': project['id'], 'revision': old['revision'], 'userIds': [b_id]}, expected=409)
        self.call('addMembers', {'projectId': project['id'], 'revision': saved['revision'], 'userIds': [b_id, fan_id]}, expected=409)
        self.assertEqual([m['userId'] for m in self.roster(project)['members']], [a_id])
        removal = {'projectId': project['id'], 'revision': saved['revision'], 'userId': a_id,
                   'requestId': 'remove-member:' + uuid.uuid4().hex}
        removed = self.call('removeMember', removal)
        self.assertEqual(self.call('removeMember', removal)['revision'], removed['revision'])
        self.assertEqual(self.remove_member(project, a_id)['revision'], removed['revision'])
        after = self.call('addMembers', body)
        self.assertEqual(after['members'], [])
        self.assertEqual(after['revision'], removed['revision'])
        rejoined = self.add_members(project, [a_id])
        replayed_removal = self.call('removeMember', removal)
        self.assertEqual(replayed_removal['revision'], rejoined['revision'])
        self.assertEqual([m['userId'] for m in replayed_removal['members']], [a_id])

    def test_roster_09_active_status_and_role_changes_gate_discovery_and_claims(self):
        project = self.project(status='draft')
        user_id, model = self.member('model', 'customer')
        self.add_members(project, [user_id])
        shot = self.shot(project, mode='open')
        self.assertNotIn(project['id'], self.project_ids(model))
        self.call('project', query={'id': project['id']}, client=model, expected=404)
        project = self.call('saveProject', {'id': project['id'], 'revision': project['revision'], 'status': 'active'})
        self.assertIn(project['id'], self.project_ids(model))
        self.assertIn(shot['id'], self.open_ids(model))
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            db.execute("DELETE FROM user_roles WHERE user_id=? AND role='model'", [user_id])
        member = next(row for row in self.roster(project)['members'] if row['userId'] == user_id)
        self.assertFalse(member['eligible'])
        self.call('myWork', client=model, expected=403)
        self.call('claim', {'shotId': shot['id']}, client=model, expected=403)
        self.call('assign', {'shotId': shot['id'], 'userId': user_id}, expected=409)
        suspended_id, _ = self.member('model')
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            db.execute("INSERT INTO user_roles(user_id,role) VALUES(?,'model')", [user_id])
            db.execute("UPDATE users SET status='suspended' WHERE id IN (?,?)", [user_id, suspended_id])
        roster = self.roster(project)
        member = next(row for row in roster['members'] if row['userId'] == user_id)
        self.assertFalse(member['eligible'])
        self.assertEqual(member['accountStatus'], 'suspended')
        self.assertNotIn(suspended_id, [candidate['id'] for candidate in roster['candidates']])
        self.call('myWork', client=model, expected=401)
        self.call('assign', {'shotId': shot['id'], 'userId': user_id}, expected=409)
        self.call('addMembers', {'projectId': project['id'], 'revision': roster['revision'],
                                 'userIds': [suspended_id]}, expected=409)

    def test_roster_10_closed_projects_prevent_new_members_and_claims(self):
        for status in ['completed', 'archived']:
            with self.subTest(status=status):
                project = self.project()
                user_id, model = self.member('model')
                newcomer_id, _ = self.member('model')
                self.add_members(project, [user_id])
                shot = self.shot(project, mode='open')
                project = self.call('saveProject', {'id': project['id'], 'revision': project['revision'], 'status': status})
                roster = self.roster(project)
                self.call('addMembers', {'projectId': project['id'], 'revision': roster['revision'], 'userIds': [newcomer_id]}, expected=409)
                self.assertNotIn(shot['id'], self.open_ids(model))
                response_status, response = self.raw_post('claim', {'shotId': shot['id']}, model)
                self.assertIn(response_status, [404, 409], response)

    def test_roster_11_concurrent_members_share_one_claim_slot(self):
        project = self.project()
        a_id, a = self.member('model')
        b_id, b = self.member('model')
        self.add_members(project, [a_id, b_id])
        shot = self.shot(project, mode='open', maxContributors=1)
        barrier = threading.Barrier(2)
        def claim(client):
            barrier.wait()
            return self.raw_post('claim', {'shotId': shot['id']}, client)
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            results = list(pool.map(claim, [a, b]))
        self.assertEqual(sorted(r[0] for r in results), [200, 409], results)
        detail = self.call('shot', query={'id': shot['id']})
        self.assertEqual(detail['contributorCount'], 1)
        self.assertEqual(len(detail['assignments']), 1)
        self.assertEqual(len(self.roster(project)['members']), 2)

    def test_roster_12_remove_and_claim_have_a_serialized_non_destructive_outcome(self):
        project = self.project()
        user_id, model = self.member('model')
        roster = self.add_members(project, [user_id])
        shot = self.shot(project, mode='open')
        barrier = threading.Barrier(2)
        def remove():
            barrier.wait()
            return self.raw_post('removeMember', {'projectId': project['id'], 'revision': roster['revision'], 'userId': user_id})
        def claim():
            barrier.wait()
            return self.raw_post('claim', {'shotId': shot['id']}, model)
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            remove_future = pool.submit(remove)
            claim_future = pool.submit(claim)
            removed, claimed = remove_future.result(), claim_future.result()
        self.assertEqual(removed[0], 200, removed)
        self.assertIn(claimed[0], [200, 404, 409], claimed)
        self.assertEqual(self.roster(project)['members'], [])
        detail = self.call('shot', query={'id': shot['id']})
        if claimed[0] == 200:
            self.assertEqual(len(detail['assignments']), 1)
            self.assertEqual(detail['assignments'][0]['status'], 'accepted')
            self.assertTrue(self.call('assignment', query={'id': claimed[1]['data']['id']}, client=model)['canUpload'])
        else:
            self.assertEqual(detail['assignments'], [])
        self.assertNotIn(shot['id'], self.open_ids(model))

    def test_roster_13_migration_backfills_current_assignees_without_reactivating_removed_members(self):
        project = self.project()
        active_id, _ = self.member('model')
        cancelled_id, _ = self.member('model')
        declined_id, declined_client = self.member('model')
        self.add_members(project, [active_id, cancelled_id, declined_id])
        shot = self.shot(project, maxContributors=3)
        active = self.call('assign', {'shotId': shot['id'], 'userId': active_id})
        cancelled = self.call('assign', {'shotId': shot['id'], 'userId': cancelled_id})
        declined = self.call('assign', {'shotId': shot['id'], 'userId': declined_id})
        self.call('cancelAssignment', {'assignmentId': cancelled['id'], 'revision': cancelled['revision']})
        self.call('respond', {'assignmentId': declined['id'], 'revision': declined['revision'], 'decision': 'decline'}, client=declined_client)
        self.call('assign', {'shotId': shot['id'], 'userId': 1})
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            db.execute('DELETE FROM production_members WHERE project_id=?', [project['id']])
            db.execute('DELETE FROM production_rosters WHERE project_id=?', [project['id']])
            db.execute('UPDATE studio_schema SET version=13 WHERE id=1')
        self.call('bootstrap')
        restored = self.roster(project)
        self.assertEqual({m['userId'] for m in restored['members']}, {active_id})
        self.assertEqual(self.call('assignment', query={'id': active['id']})['status'], 'assigned')
        self.remove_member(project, active_id)
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            db.execute('UPDATE studio_schema SET version=13 WHERE id=1')
        self.call('bootstrap')
        self.assertEqual(self.roster(project)['members'], [])
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            self.assertEqual(db.execute('SELECT version FROM studio_schema').fetchone()[0], 16)
            self.assertEqual(db.execute('SELECT COUNT(*) FROM shot_assignments WHERE shot_id=?', [shot['id']]).fetchone()[0], 4)

    def test_roster_14_membership_and_open_shot_updates_are_in_app_and_retry_safe(self):
        project = self.project()
        user_id, model = self.member('model')
        self.add_members(project, [user_id])
        first_count = self.notification_count(project, user_id)
        self.assertGreater(first_count, 0)
        token = 'open-shot:' + uuid.uuid4().hex
        shot = self.shot(project, mode='open', requestId=token)
        with_shot = self.notification_count(project, user_id)
        self.assertGreater(with_shot, first_count)
        replay = self.shot(project, mode='open', requestId=token)
        self.assertEqual(shot['id'], replay['id'])
        self.assertEqual(self.notification_count(project, user_id), with_shot)
        notices = self.call('notifications', client=model)['notifications']
        own = [n for n in notices if n['projectId'] == project['id']]
        self.assertTrue(own)
        self.assertTrue(all('my-work.php?' in n['url'] for n in own))
        self.assertNotIn('email', json.dumps(own))
        self.assertTrue(any(n['kind'] == 'open_shot' for n in own))

        def open_notices():
            return [n for n in self.call('notifications', client=model)['notifications']
                    if n['projectId'] == project['id'] and n['kind'] == 'open_shot']

        shot = self.call('saveShot', {'id': shot['id'], 'revision': shot['revision'], 'mode': 'assigned'})
        self.assertEqual(open_notices(), [])
        shot = self.call('saveShot', {'id': shot['id'], 'revision': shot['revision'],
                                      'mode': 'open', 'eligibleRoles': ['professional']})
        self.assertEqual(open_notices(), [])
        shot = self.call('saveShot', {'id': shot['id'], 'revision': shot['revision'],
                                      'eligibleRoles': ['model'], 'status': 'closed'})
        self.assertEqual(open_notices(), [])
        self.call('saveShot', {'id': shot['id'], 'revision': shot['revision'], 'status': 'active'})
        self.assertTrue(open_notices())
        self.remove_member(project, user_id)
        self.assertEqual([n for n in self.call('notifications', client=model)['notifications']
                          if n['projectId'] == project['id']], [])


def load_tests(loader, tests, pattern):
    return unittest.TestSuite(WorkRosterIntegration(name) for name in loader.getTestCaseNames(WorkRosterIntegration)
                              if name.startswith('test_roster_'))


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--php', default='php')
    args, rest = parser.parse_known_args()
    WorkRosterIntegration.php = args.php
    unittest.main(argv=[__file__, *rest], verbosity=2)
