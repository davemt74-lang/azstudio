#!/usr/bin/env python3
"""Provider routing/regression checks. All provider responses are local fixtures.

The HTTP app and database are disposable; this suite makes no provider API call.
"""
from __future__ import annotations
import argparse
import json
import pathlib
import sqlite3
import subprocess
import unittest
import uuid
from edit_composer_integration import ComposerIntegration


class AIRoutingIntegration(ComposerIntegration):
    def ai(self, action, data=None, query=None, client=None, expected=200):
        return self.call(action, data, query, client, expected, endpoint='ai-settings-api.php')

    def configure_routes(self, shot='openai', edit='anthropic', enabled=True, daily_limit=100):
        old = self.ai('settings')
        return self.ai('save', {
            'revision': old['revision'], 'enabled': enabled, 'dailyLimit': daily_limit,
            'shotProvider': shot, 'editProvider': edit,
            'providers': {
                'openai': {'model': 'gpt-fixture-model', 'apiKey': 'fixture-openai-no-network'},
                'anthropic': {'model': 'claude-fixture-model', 'apiKey': 'fixture-anthropic-no-network', 'workspaceId': ''},
            },
        })

    def routing_case(self, operation, task='shotPlanner', **data):
        fixture = pathlib.Path(__file__).with_name('ai-routing-fixture.php')
        process = subprocess.run([self.php, str(fixture), str(self.root)],
                                 input=json.dumps({'operation': operation, 'task': task, **data}),
                                 capture_output=True, text=True, timeout=30)
        self.assertEqual(process.returncode, 0, process.stderr)
        self.assertFalse(process.stderr, process.stderr)
        try:
            return json.loads(process.stdout)
        except ValueError:
            self.fail(process.stdout)

    def done(self, project, task='shotPlanner', **extra):
        result = self.routing_case('prepare', task, projectId=project['id'], token=extra.pop('token', uuid.uuid4().hex), **extra)
        self.assertTrue(result['ok'], result)
        return result['data']

    def snapshot(self, proposal_id, task='shotPlanner'):
        table = 'work_ai_proposals' if task == 'shotPlanner' else 'edit_ai_proposals'
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            row = db.execute(f'SELECT model_id,snapshot_json FROM {table} WHERE id=?', [proposal_id]).fetchone()
        return row[0], json.loads(row[1])

    def test_routing_01_tasks_choose_providers_independently(self):
        self.configure_routes()
        p, *_ = self.ready()
        shot = self.done(p)
        edit = self.done(p, 'editComposer')
        self.assertEqual((shot['provider'], shot['model']), ('openai', 'gpt-fixture-model'))
        self.assertEqual((edit['provider'], edit['model']), ('anthropic', 'claude-fixture-model'))
        self.assertEqual(self.call('settings', endpoint='work-planner-api.php')['provider'], 'openai')
        self.assertEqual(self.composer('settings')['provider'], 'anthropic')
        self.configure_routes(shot='anthropic', edit='openai')
        self.assertEqual(self.done(p)['provider'], 'anthropic')
        self.assertEqual(self.done(p, 'editComposer')['provider'], 'openai')

    def test_routing_02_shots_need_review_and_never_auto_assign(self):
        for provider in ['openai', 'anthropic']:
            with self.subTest(provider=provider):
                self.configure_routes(shot=provider)
                p = self.project()
                proposal = self.done(p)
                self.assertEqual(self.call('project', query={'id': p['id']})['shots'], [])
                result = self.call('apply', {'proposalId': proposal['id'], 'shots': proposal['shots']}, endpoint='work-planner-api.php')
                self.assertEqual(result['state'], 'applied')
                shot = self.call('project', query={'id': p['id']})['shots'][0]
                self.assertEqual(shot['assignmentCount'], 0)
                self.assertEqual(shot['mode'], 'assigned')
                self.assertEqual(sorted(shot['eligibleRoles']), ['model', 'professional'])

    def test_routing_03_pending_job_freezes_provider_model_and_recovers(self):
        for task in ['shotPlanner', 'editComposer']:
            with self.subTest(task=task):
                self.configure_routes(shot='anthropic', edit='anthropic')
                p, *_ = self.ready()
                token = uuid.uuid4().hex
                started = self.routing_case('start', task, projectId=p['id'], token=token)
                self.assertTrue(started['ok'], started)
                job = started['data']
                model, snapshot = self.snapshot(job['id'], task)
                self.assertEqual(model, 'claude-fixture-model')
                self.assertEqual(snapshot['ai']['provider'], 'anthropic')
                self.assertNotIn('fixture-anthropic-no-network', json.dumps(snapshot))
                self.configure_routes(shot='openai', edit='openai')
                receipt = self.routing_case('retry', task, projectId=p['id'], token=token)['data']['existing']
                self.assertEqual((receipt['id'], receipt['state'], receipt['provider'], receipt['model']),
                                 (job['id'], 'pending', 'anthropic', model))
                completed = self.routing_case('finishSaved', task, id=job['id'])
                self.assertTrue(completed['ok'], completed)
                self.assertEqual(completed['data']['provider'], 'anthropic')
                after = self.routing_case('retry', task, projectId=p['id'], token=token)['data']['existing']
                self.assertEqual((after['id'], after['state'], after['provider']), (job['id'], 'ready', 'anthropic'))

    def test_routing_04_provider_payloads_only_include_curated_metadata(self):
        p, *_ = self.ready()
        for task in ['shotPlanner', 'editComposer']:
            contexts = []
            for provider in ['openai', 'anthropic']:
                with self.subTest(task=task, provider=provider):
                    result = self.routing_case('payload', task, projectId=p['id'], provider=provider, taint=True)
                    self.assertTrue(result['ok'], result)
                    payload = result['data']
                    self.assertEqual(payload['model'], 'claude-fixture-model' if provider == 'anthropic' else 'gpt-fixture-model')
                    if provider == 'openai':
                        self.assertFalse(payload['store'])
                        self.assertEqual(payload['max_output_tokens'], 6000)
                        schema = payload['text']['format']
                        self.assertTrue(schema['strict'])
                        context = json.loads(payload['input'])
                        self.assertNotIn('output_config', payload)
                    else:
                        self.assertEqual(payload['max_tokens'], 6000)
                        schema = payload['output_config']['format']
                        context = json.loads(payload['messages'][0]['content'])
                        self.assertEqual(payload['messages'][0]['role'], 'user')
                        self.assertIsInstance(payload['system'], str)
                        self.assertNotIn('store', payload)
                        self.assertNotIn('input', payload)
                    self.assertEqual(schema['type'], 'json_schema')
                    self.assertFalse(schema['schema']['additionalProperties'])
                    contexts.append(context)
                    serialized = json.dumps(payload)
                    for forbidden in ['credential-sentinel', 'private-sentinel', 'password-sentinel',
                                      'storage-sentinel', 'private-contributor-sentinel', 'private-existing-shot-brief-sentinel']:
                        self.assertNotIn(forbidden, serialized)
            self.assertEqual(contexts[0], contexts[1])

    def test_routing_05_success_parsers_preserve_business_validation(self):
        p, *_ = self.ready()
        for provider in ['openai', 'anthropic']:
            for task, changes in [('shotPlanner', {'takesRequested': 0}),
                                  ('editComposer', {'candidateId': 'unavailable_candidate'})]:
                with self.subTest(provider=provider, task=task):
                    valid = self.routing_case('parse', task, projectId=p['id'], provider=provider)
                    self.assertTrue(valid['ok'], valid)
                    invalid = self.routing_case('parse', task, projectId=p['id'], provider=provider, changes=changes)
                    self.assertFalse(invalid['ok'], invalid)
                    self.assertEqual(invalid['code'], 'ai_invalid_proposal')

    def test_routing_06_claude_refusal_truncation_malformed_and_error(self):
        p, *_ = self.ready()
        cases = [
            ({'type': 'message', 'role': 'assistant', 'stop_reason': 'refusal', 'content': [{'type': 'text', 'text': 'Fixture refusal'}]}, 'ai_refusal'),
            ({'type': 'message', 'role': 'assistant', 'stop_reason': 'max_tokens', 'content': [{'type': 'text', 'text': '{}'}]}, 'ai_incomplete'),
            ({'type': 'message', 'role': 'assistant', 'stop_reason': 'pause_turn', 'content': []}, 'ai_incomplete'),
            ({'type': 'message', 'role': 'assistant', 'stop_reason': 'end_turn', 'content': 'bad-content'}, 'ai_response'),
            ({'type': 'message', 'role': 'assistant', 'stop_reason': 'end_turn', 'content': [None]}, 'ai_response'),
            ({'type': 'error', 'error': {'type': 'authentication_error', 'message': 'sensitive-provider-diagnostic'}}, 'ai_response'),
            ({'type': 'message', 'role': 'assistant', 'stop_reason': 'end_turn', 'content': [{'type': 'text', 'text': 'not-json'}]}, 'ai_response'),
        ]
        for task in ['shotPlanner', 'editComposer']:
            for response, code in cases:
                with self.subTest(task=task, code=code, response=response):
                    result = self.routing_case('parse', task, projectId=p['id'], provider='anthropic', response=response)
                    self.assertEqual(result['code'], code, result)
                    self.assertNotIn('sensitive-provider-diagnostic', json.dumps(result))

    def test_routing_07_openai_refusal_truncation_and_cross_provider_rejection(self):
        p, *_ = self.ready()
        cases = [
            ({'status': 'completed', 'output': [{'content': [{'type': 'refusal', 'refusal': 'Fixture refusal'}]}]}, 'ai_refusal'),
            ({'status': 'incomplete', 'output': []}, 'ai_incomplete'),
            ({'status': 'completed', 'output': 'invalid'}, 'ai_response'),
            ({'status': 'completed', 'output': [{'content': [False]}]}, 'ai_response'),
        ]
        for task in ['shotPlanner', 'editComposer']:
            for response, code in cases:
                with self.subTest(task=task, code=code):
                    self.assertEqual(self.routing_case('parse', task, projectId=p['id'], provider='openai', response=response)['code'], code)
            for provider, response in [('openai', {'type': 'message', 'role': 'assistant', 'stop_reason': 'end_turn', 'content': []}),
                                       ('anthropic', {'status': 'completed', 'output': []})]:
                self.assertFalse(self.routing_case('parse', task, projectId=p['id'], provider=provider, response=response)['ok'])

    def test_routing_08_legacy_receipts_default_to_openai_after_settings_switch(self):
        self.configure_routes(shot='openai', edit='openai')
        p, *_ = self.ready()
        for task in ['shotPlanner', 'editComposer']:
            token = uuid.uuid4().hex
            proposal = self.done(p, task, token=token)
            table = 'work_ai_proposals' if task == 'shotPlanner' else 'edit_ai_proposals'
            _, snapshot = self.snapshot(proposal['id'], task)
            snapshot.pop('ai', None)
            with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
                db.execute(f'UPDATE {table} SET snapshot_json=? WHERE id=?', [json.dumps(snapshot), proposal['id']])
            self.configure_routes(shot='anthropic', edit='anthropic')
            result = self.routing_case('retry', task, projectId=p['id'], token=token)
            self.assertTrue(result['ok'], result)
            self.assertEqual(result['data']['existing']['id'], proposal['id'])
            self.assertEqual(result['data']['existing']['provider'], 'openai')
            self.configure_routes(shot='openai', edit='openai')

    def test_routing_09_shared_quota_and_pending_across_providers(self):
        self.configure_routes()
        p, *_ = self.ready()
        pending = self.routing_case('start', projectId=p['id'], token=uuid.uuid4().hex)['data']
        busy = self.routing_case('start', 'editComposer', projectId=p['id'], token=uuid.uuid4().hex)
        self.assertEqual(busy['code'], 'ai_busy')
        failed = self.routing_case('failSaved', id=pending['id'])['data']
        self.assertEqual((failed['state'], failed['provider']), ('failed', 'openai'))
        self.configure_routes(daily_limit=1)
        limited = self.routing_case('start', 'editComposer', projectId=p['id'], token=uuid.uuid4().hex)
        self.assertEqual(limited['code'], 'ai_daily_limit')
        self.configure_routes()


def load_tests(loader, tests, pattern):
    return unittest.TestSuite(AIRoutingIntegration(name) for name in loader.getTestCaseNames(AIRoutingIntegration)
                              if name.startswith('test_routing_'))


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--php', default='php')
    args, rest = parser.parse_known_args()
    AIRoutingIntegration.php = args.php
    unittest.main(argv=[__file__, *rest], verbosity=2)
