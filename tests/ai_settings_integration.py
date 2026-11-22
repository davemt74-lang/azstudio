#!/usr/bin/env python3
"""Admin AI settings in a disposable HTTP install. Provider responses are fixtures.

No live credentials, external requests or paid generation are used.
"""
import argparse
import json
import sqlite3
import subprocess
import unittest
import backend_integration as harness
from work_integration import WorkIntegration

OPENAI_KEY = 'fixture-openai-key-not-a-credential'
CLAUDE_KEY = 'fixture-claude-key-not-a-credential'


class AISettingsIntegration(WorkIntegration):
    def ai(self, action, data=None, **kwargs):
        return self.call(action, data, endpoint='ai-settings-api.php', **kwargs)

    def save(self, **changes):
        old = self.ai('settings')
        body = {key: old[key] for key in ['revision', 'enabled', 'dailyLimit', 'shotProvider', 'editProvider']}
        body['providers'] = {provider: {'model': row['model'], 'workspaceId': row['workspaceId']} for provider, row in old['providers'].items()}
        body.update(changes)
        return self.ai('save', body)

    def configure(self):
        return self.save(enabled=True, dailyLimit=75, shotProvider='anthropic', editProvider='openai', providers={
            'openai': {'model': 'gpt-4.1-mini', 'apiKey': OPENAI_KEY},
            'anthropic': {'model': 'claude-sonnet-4-6', 'apiKey': CLAUDE_KEY, 'workspaceId': 'wrkspc_fixture'},
        })

    def php_case(self, operation, **data):
        code = r'''
        require $argv[1].'/includes/bootstrap.php';
        $case=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
        $user=az_account_user((int)($case['ownerId']??1)); $lock=az_community_lock();
        try {
          if($case['operation']==='roundtrip'){
            $r=az_ai_provider_row((int)$user['id'],$case['provider']);
            $out=['matches'=>hash_equals($case['key'],az_work_planner_decrypt($r['key_ciphertext']))];
          }elseif($case['operation']==='legacy'){
            az_db()->prepare('DELETE FROM ai_settings WHERE owner_id=?')->execute([$user['id']]);
            az_db()->prepare('DELETE FROM ai_provider_settings WHERE owner_id=?')->execute([$user['id']]);
            az_db()->prepare('DELETE FROM work_ai_settings WHERE owner_id=?')->execute([$user['id']]);
            az_db()->prepare('INSERT INTO work_ai_settings (owner_id,enabled,model_id,key_ciphertext,daily_limit,revision,updated_at) VALUES (?,?,?,?,?,?,?)')->execute([$user['id'],1,'gpt-4.1-mini',az_work_planner_encrypt($case['key']),37,7,az_now()]);
            az_db()->exec('UPDATE studio_schema SET version=12 WHERE id=1');
            $out=['ready'=>true];
          }elseif($case['operation']==='candidates'){
            $out=az_ai_model_candidates($case['provider'],$case['response']);
          }elseif($case['operation']==='checked'){
            $job=az_ai_check_job(['provider'=>$case['provider']],$user); unset($job['key']);
            $failure=empty($case['fail'])?null:new AzHttpError(502,'Fixture connection failed.','ai_connection');
            $out=az_ai_check_finish($job,$user,[['id'=>'fixture-model','name'=>'Fixture']],$failure);
          }elseif($case['operation']==='staleCheck'){
            $job=az_ai_check_job(['provider'=>'openai'],$user);unset($job['key']);
            az_db()->prepare('UPDATE ai_settings SET revision=revision+1 WHERE owner_id=?')->execute([$user['id']]);
            $out=az_ai_check_finish($job,$user,[],null);
          }elseif($case['operation']==='task'){
            $r=az_ai_task_settings((int)$user['id'],$case['task']);unset($r['key_ciphertext']);$out=$r;
          }else{throw new RuntimeException('Unknown fixture');}
          echo json_encode(['ok'=>true,'data'=>$out],JSON_THROW_ON_ERROR);
        }catch(AzHttpError $error){echo json_encode(['ok'=>false,'status'=>$error->status,'code'=>$error->errorCode],JSON_THROW_ON_ERROR);}
        '''
        result = subprocess.run([self.php, '-r', code, str(self.root)], input=json.dumps({'operation': operation, **data}), capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def test_ai_settings_01_default_auth_roles_and_http_contract(self):
        self.ai('settings', client=harness.Client(self.base), expected=401)
        for role in ['model', 'professional', 'fan', 'customer']:
            _, member = self.member(role)
            self.ai('settings', client=member, expected=403)
            self.ai('save', {'revision': 0}, client=member, expected=403)
        initial = self.ai('settings')
        self.assertFalse(initial['enabled'])
        self.assertEqual(initial['shotProvider'], 'openai')
        self.assertEqual(initial['editProvider'], 'openai')
        self.assertEqual(initial['revision'], 0)
        self.assertFalse(initial['providers']['openai']['hasKey'])
        self.assertFalse(initial['providers']['anthropic']['hasKey'])
        self.assertEqual(initial['csrf'], self.client.csrf)
        self.ai('save', {'revision': 0}, csrf=False, expected=403)
        self.ai('models', {'provider': 'openai'}, expected=400)
        self.ai('models', query={'provider': 'openai'}, expected=404)
        status, _, body = self.client.request('ai-settings-api.php?action=settings', method='PUT')
        self.assertEqual(status, 405, body)

    def test_ai_settings_02_encrypted_keys_and_separate_provider_routing(self):
        saved = self.configure()
        self.assertTrue(saved['enabled'])
        self.assertTrue(saved['providers']['openai']['hasKey'])
        self.assertTrue(saved['providers']['anthropic']['hasKey'])
        self.assertEqual(saved['providers']['anthropic']['workspaceId'], 'wrkspc_fixture')
        serialized = json.dumps(saved)
        for value in [OPENAI_KEY, CLAUDE_KEY, 'key_ciphertext', 'apiKey']:
            self.assertNotIn(value, serialized)
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            rows = dict(db.execute('SELECT provider,key_ciphertext FROM ai_provider_settings WHERE owner_id=1'))
        self.assertEqual(set(rows), {'openai', 'anthropic'})
        self.assertNotIn(OPENAI_KEY, rows['openai'])
        self.assertNotIn(CLAUDE_KEY, rows['anthropic'])
        for provider, key in [('openai', OPENAI_KEY), ('anthropic', CLAUDE_KEY)]:
            self.assertTrue(self.php_case('roundtrip', provider=provider, key=key)['data']['matches'])
        self.assertEqual(self.php_case('task', task='shotPlanner')['data']['provider'], 'anthropic')
        self.assertEqual(self.php_case('task', task='editComposer')['data']['provider'], 'openai')

    def test_ai_settings_03_blank_key_preservation_and_optimistic_revision(self):
        old = self.ai('settings')
        saved = self.save(providers={'openai': {'apiKey': '', 'model': 'gpt-4.1'}, 'anthropic': {'apiKey': ''}})
        self.assertGreater(saved['revision'], old['revision'])
        self.assertTrue(self.php_case('roundtrip', provider='openai', key=OPENAI_KEY)['data']['matches'])
        self.assertTrue(self.php_case('roundtrip', provider='anthropic', key=CLAUDE_KEY)['data']['matches'])
        self.ai('save', {**old, 'enabled': False}, expected=409)
        self.ai('models', {'provider': 'openai', 'revision': old['revision']}, expected=409)

    def test_ai_settings_04_key_removal_is_atomic_and_requires_usable_selection(self):
        old = self.ai('settings')
        self.ai('save', {**old, 'providers': {'anthropic': {'removeKey': True}}}, expected=400)
        self.assertTrue(self.php_case('roundtrip', provider='anthropic', key=CLAUDE_KEY)['data']['matches'])
        cleared = self.save(enabled=False, providers={'anthropic': {'removeKey': True, 'apiKey': 'ignored-valid-fixture-key'}})
        self.assertFalse(cleared['providers']['anthropic']['hasKey'])
        self.assertTrue(cleared['providers']['openai']['hasKey'])
        self.assertEqual(cleared['providers']['anthropic']['connection']['status'], 'unknown')
        self.ai('models', {'provider': 'anthropic'}, expected=400)
        old = self.ai('settings')
        self.ai('save', {**old, 'enabled': True}, expected=400)
        self.save(enabled=True, shotProvider='openai')

    def test_ai_settings_05_each_admin_owns_its_credentials(self):
        other_id, other = self.member('admin')
        initial = self.ai('settings', client=other)
        self.assertEqual(initial['revision'], 0)
        self.assertFalse(initial['providers']['openai']['hasKey'])
        saved = self.ai('save', {'revision': 0, 'enabled': False, 'dailyLimit': 11, 'providers': {'openai': {'apiKey': 'other-admin-fixture-key', 'model': 'gpt-4.1-mini'}}}, client=other)
        self.assertTrue(saved['providers']['openai']['hasKey'])
        self.assertEqual(self.ai('settings')['dailyLimit'], 75)
        self.assertTrue(self.php_case('roundtrip', provider='openai', key=OPENAI_KEY)['data']['matches'])
        self.assertTrue(self.php_case('roundtrip', provider='openai', key='other-admin-fixture-key', ownerId=other_id)['data']['matches'])

    def test_ai_settings_06_invalid_fields_do_not_mutate_keys(self):
        old = self.ai('settings')
        changes = [
            {'providers': {'openai': {'apiKey': 'key\r\nInjected: header'}}},
            {'providers': {'openai': {'apiKey': 'null\u0000key'}}},
            {'providers': {'openai': {'apiKey': ' key '}}},
            {'providers': {'openai': {'apiKey': ['invalid']}}},
            {'providers': {'openai': {'model': '<invalid>'}}},
            {'providers': {'anthropic': {'workspaceId': 'workspace\nInjected'}}},
            {'shotProvider': 'custom-proxy'}, {'dailyLimit': 101}, {'providers': ['bad']},
        ]
        for change in changes:
            self.ai('save', {**old, **change}, expected=400)
        self.assertEqual(self.ai('settings')['revision'], old['revision'])
        self.assertTrue(self.php_case('roundtrip', provider='openai', key=OPENAI_KEY)['data']['matches'])

    def test_ai_settings_07_model_candidates_are_not_blanket_capability_claims(self):
        openai = self.php_case('candidates', provider='openai', response={'data': [
            {'id': 'gpt-4.1-mini'}, {'id': 'gpt-4o-2024-05-13'}, {'id': 'whisper-1'}, {'id': 'gpt-4o-audio-preview'},
            {'id': 'gpt-6-astra'}, {'id': 'gpt-4.1-retired', 'shutdown_date': '2020-01-01'}, {'id': '<script>'},
        ]})['data']
        self.assertEqual({row['id'] for row in openai}, {'gpt-4.1-mini', 'gpt-6-astra'})
        self.assertTrue(all(row['compatibility'] == 'unverified' for row in openai))
        claude = self.php_case('candidates', provider='anthropic', response={'data': [
            {'id': 'claude-fixture-a', 'display_name': 'Claude fixture A', 'capabilities': {'structured_outputs': {'supported': True}}},
            {'id': 'claude-fixture-b', 'capabilities': {'structured_outputs': {'supported': False}}},
            {'id': 'claude-fixture-c', 'lifecycle': 'retired'}, {'id': 'claude-fixture-d'},
        ]})['data']
        self.assertEqual({row['id'] for row in claude}, {'claude-fixture-a', 'claude-fixture-d'})
        self.assertEqual(next(row for row in claude if row['id'] == 'claude-fixture-a')['compatibility'], 'provider-reported')
        self.assertEqual(self.php_case('candidates', provider='openai', response={'data': 'invalid'})['code'], 'ai_response')

    def test_ai_settings_08_connection_status_resets_and_stale_result_is_rejected(self):
        checked = self.php_case('checked', provider='openai')['data']
        self.assertEqual(checked['settings']['providers']['openai']['connection']['status'], 'success')
        self.assertIn('generation compatibility', checked['message'])
        self.assertNotIn(OPENAI_KEY, json.dumps(checked))
        self.assertEqual(self.php_case('staleCheck')['code'], 'revision_conflict')
        self.save(providers={'openai': {'apiKey': OPENAI_KEY}})
        self.assertEqual(self.ai('settings')['providers']['openai']['connection']['status'], 'unknown')
        failed = self.php_case('checked', provider='openai', fail=True)
        self.assertEqual(failed['code'], 'ai_connection')
        self.assertEqual(self.ai('settings')['providers']['openai']['connection']['status'], 'failed')

    def test_ai_settings_09_legacy_credential_upgrade_and_replay_preserves_removal(self):
        owner, member = self.member('admin')
        self.assertTrue(self.php_case('legacy', ownerId=owner, key='legacy-fixture-key')['ok'])
        migrated = self.ai('settings', client=member)
        self.assertEqual(migrated['revision'], 7)
        self.assertTrue(migrated['enabled'])
        self.assertEqual(migrated['dailyLimit'], 37)
        self.assertEqual(migrated['shotProvider'], 'openai')
        self.assertEqual(migrated['editProvider'], 'openai')
        self.assertTrue(self.php_case('roundtrip', ownerId=owner, provider='openai', key='legacy-fixture-key')['data']['matches'])
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            self.assertEqual(db.execute('SELECT key_ciphertext FROM work_ai_settings WHERE owner_id=?', [owner]).fetchone()[0], '')
        self.ai('save', {**migrated, 'enabled': False, 'providers': {'openai': {'removeKey': True}}}, client=member)
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            db.execute('UPDATE studio_schema SET version=12 WHERE id=1')
        replay = self.ai('settings', client=member)
        self.assertFalse(replay['providers']['openai']['hasKey'])
        self.assertEqual(replay['revision'], 8)
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            self.assertEqual(db.execute('SELECT version FROM studio_schema').fetchone()[0], 16)

    def test_ai_settings_10_legacy_save_endpoint_preserves_claude_routes(self):
        settings = self.configure()
        before = settings['providers']['anthropic']
        updated = self.call('saveSettings', {'revision': settings['revision'], 'enabled': True, 'dailyLimit': 21, 'model': 'gpt-4.1-mini'}, endpoint='work-planner-api.php')
        after = self.ai('settings')
        self.assertEqual(after['shotProvider'], 'anthropic')
        self.assertEqual(after['providers']['anthropic'], before)
        self.assertEqual(after['dailyLimit'], 21)
        self.assertNotIn(CLAUDE_KEY, json.dumps(updated))

    def test_ai_settings_11_reload_recovers_from_a_stale_csrf_token(self):
        old_token = self.client.csrf
        self.client.csrf = '0' * 64
        try:
            settings = self.ai('settings')
            self.ai('save', {**settings, 'enabled': False}, expected=403)
            self.assertEqual(settings['csrf'], old_token)
            self.client.csrf = settings['csrf']
            saved = self.ai('save', {**settings, 'enabled': False})
            self.assertEqual(saved['csrf'], old_token)
            self.assertFalse(saved['enabled'])
        finally:
            self.client.csrf = old_token


def load_tests(loader, tests, pattern):
    return unittest.TestSuite(AISettingsIntegration(name) for name in loader.getTestCaseNames(AISettingsIntegration) if name.startswith('test_ai_settings_'))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(); parser.add_argument('--php', default='php'); args, rest = parser.parse_known_args()
    AISettingsIntegration.php = args.php
    unittest.main(argv=[__file__, *rest], verbosity=2)
