#!/usr/bin/env python3
"""A production plan through casting, questions, real uploads, review and export.

Uses an isolated SQLite installation and synthetic media. No live AI, browser,
camera, public host or real recipient is involved.
"""
from __future__ import annotations
import argparse
import json
import unittest
import uuid
from work_integration import WorkIntegration
from work_media_integration import WorkMediaIntegration


class ProductionPlanFlowIntegration(WorkIntegration):
    finished_upload = WorkMediaIntegration.finished_upload

    def plan_call(self, action, data=None, query=None, client=None, expected=200):
        return self.call(action, data, query, client, expected, endpoint='production-plan-api.php')

    def thread_call(self, action, data=None, query=None, client=None, expected=200):
        return self.call(action, data, query, client, expected, endpoint='work-thread-api.php')

    def scene(self, title, distribution='unassigned', users=None):
        return {'id': uuid.uuid4().hex, 'title': title,
                'action': 'Introduce the collection, then hold the final pose.',
                'dialogue': 'Meet the studio collection.', 'props': 'One sample jacket.',
                'performerRole': 'Presenter', 'shots': [{
                    'id': uuid.uuid4().hex, 'title': title + ' camera take',
                    'framing': 'Eye-level medium shot.', 'instructions': 'Leave a beat at both ends.',
                    'orientation': 'portrait', 'targetDurationSeconds': 15, 'takesRequested': 2,
                    'distribution': distribution, 'userIds': users or [],
                    'eligibleRoles': ['model'], 'maxContributors': 1, 'dueLocal': None}]}

    def test_plan_flow_01_cast_questions_retakes_and_finished_source(self):
        model_a_id, model_a = self.member('model')
        model_b_id, model_b = self.member('model')
        _, outsider = self.member('model')
        project = self.project(status='draft')
        roster = self.include(project, model_a_id, model_b_id)
        scenes = [self.scene('Opening', 'assigned', [model_a_id]),
                  self.scene('Product detail', 'open')]
        plan_body = {'objective': 'Introduce the collection', 'productFocus': 'Studio clothing',
                     'format': 'portrait', 'targetDurationSeconds': 30,
                     'creativeDirection': 'Clear product detail and a friendly introduction.',
                     'deliverables': 'One main portrait video.', 'scenes': scenes}
        saved = self.plan_call('save', {'projectId': project['id'], 'revision': 0,
                                      'plan': plan_body, 'requestId': uuid.uuid4().hex})
        apply_body = {'projectId': project['id'], 'revision': saved['revision'],
                      'rosterRevision': roster['revision'], 'requestId': uuid.uuid4().hex}
        applied = self.plan_call('apply', apply_body)
        direct_id = applied['scenes'][0]['shots'][0]['shotId']
        open_id = applied['scenes'][1]['shots'][0]['shotId']
        retry = self.plan_call('apply', apply_body)
        self.assertEqual(retry['scenes'][0]['shots'][0]['shotId'], direct_id)
        self.assertEqual(len(self.call('project', query={'id': project['id']})['shots']), 2)
        direct = self.call('shot', query={'id': direct_id})
        assignment = direct['assignments'][0]
        self.assertEqual(assignment['userId'], model_a_id)
        self.assertEqual(assignment['status'], 'assigned')

        # The plan is private. An assignee gets only the relevant applied scene.
        self.plan_call('bootstrap', query={'projectId': project['id']}, client=model_a, expected=403)
        own = self.call('assignment', query={'id': assignment['id']}, client=model_a)
        self.assertIn('Meet the studio collection.', json.dumps(own['planScene']))
        self.assertNotIn('Product detail', json.dumps(own['planScene']))
        self.call('shot', query={'id': direct_id}, client=model_b, expected=404)

        question_body = {'assignmentId': assignment['id'], 'text': 'Should I show the jacket first?',
                         'requestId': uuid.uuid4().hex}
        question = self.thread_call('send', question_body, client=model_a)
        self.assertEqual(self.thread_call('send', question_body, client=model_a)['sentMessageId'],
                         question['sentMessageId'])
        self.assertEqual(self.call('assignment', query={'id': assignment['id']})['questionUnreadCount'], 1)
        self.thread_call('send', {'assignmentId': assignment['id'],
                                 'text': 'Yes. Show the fabric, then deliver the line.',
                                 'requestId': uuid.uuid4().hex})
        conversation = self.thread_call('thread', query={'assignmentId': assignment['id']}, client=model_a)
        self.assertEqual(len(conversation['messages']), 2)
        read = self.thread_call('markRead', {'assignmentId': assignment['id'],
                                'throughSequence': max(m['sequence'] for m in conversation['messages'])}, client=model_a)
        self.assertEqual(read['unreadCount'], 0)

        project = self.call('saveProject', {'id': project['id'], 'revision': project['revision'], 'status': 'active'})
        self.assertIn(project['id'], [p['id'] for p in self.call('myWork', client=model_b)['projects']])
        self.assertIn(open_id, [s['id'] for s in self.call('myWork', client=model_b)['open']])
        self.call('shot', query={'id': open_id}, client=outsider, expected=404)
        self.assertEqual(self.call('claim', {'shotId': open_id}, client=model_b)['status'], 'accepted')
        assignment = self.call('respond', {'assignmentId': assignment['id'],
                               'revision': assignment['revision'], 'decision': 'accept'}, client=model_a)

        first, *_ = self.upload(assignment, model_a)
        own = self.call('assignment', query={'id': assignment['id']}, client=model_a)
        self.call('submitAssignment', {'assignmentId': assignment['id'], 'revision': own['revision']}, client=model_a)
        first_submission = self.submission(assignment, self.client, first['submissionId'])
        self.call('reviewSubmission', {'submissionId': first_submission['id'],
                  'revision': first_submission['revision'], 'decision': 'retake', 'note': 'Hold the opening beat longer.'})
        revised = self.call('assignment', query={'id': assignment['id']}, client=model_a)
        self.assertEqual(revised['status'], 'changes_requested')
        second, *_ = self.upload(revised, model_a)
        revised = self.call('assignment', query={'id': assignment['id']}, client=model_a)
        self.call('submitAssignment', {'assignmentId': assignment['id'], 'revision': revised['revision']}, client=model_a)
        replacement = self.submission(revised, self.client, second['submissionId'])
        accepted = self.call('reviewSubmission', {'submissionId': replacement['id'],
                             'revision': replacement['revision'], 'decision': 'accepted'})

        # Roster removal keeps the explicit assignment, conversation and accepted source.
        current_roster = self.call('roster', query={'projectId': project['id']})
        self.call('removeMember', {'projectId': project['id'], 'revision': current_roster['revision'],
                                  'userId': model_a_id})
        retained = self.call('assignment', query={'id': assignment['id']}, client=model_a)
        self.assertEqual(len(retained['submissions']), 2)
        self.assertIn('Meet the studio collection.', json.dumps(retained['planScene']))
        self.assertEqual(len(self.thread_call('thread', query={'assignmentId': assignment['id']}, client=model_a)['messages']), 2)
        editor = self.client.api('interview', query={'id': project['interviewId']})[1]['data']
        self.assertEqual([take['id'] for take in editor['takes']], [accepted['takeId']])
        upload, _ = self.finished_upload(project, [accepted['takeId']])
        status, finished = self.client.api('finishUpload', {'uploadId': upload['uploadId']})
        self.assertEqual(status, 200, finished)
        self.assertEqual(finished['data']['video']['sourceEdit']['selectedTakeIds'], [accepted['takeId']])
        self.assertEqual(len(self.call('project', query={'id': project['id']})['shots']), 2)


def load_tests(loader, tests, pattern):
    return unittest.TestSuite(ProductionPlanFlowIntegration(name)
        for name in loader.getTestCaseNames(ProductionPlanFlowIntegration)
        if name.startswith('test_plan_flow_'))


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--php', default='php')
    args, rest = parser.parse_known_args()
    ProductionPlanFlowIntegration.php = args.php
    unittest.main(argv=[__file__, *rest], verbosity=2)
