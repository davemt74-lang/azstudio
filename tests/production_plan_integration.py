#!/usr/bin/env python3
"""Saved production plans, casting and provider fixtures in a disposable HTTP install.

No actual provider request, account, media file or key is used outside the temporary
test installation. Four PHP workers exercise the real mutation serialization path.
"""
from __future__ import annotations

import argparse
import concurrent.futures
import copy
import json
import sqlite3
import subprocess
import unittest
import uuid

import backend_integration as harness
from work_integration import WorkIntegration


AI_SHOT = {'title': 'Fabric detail', 'framing': 'Close shot in soft light.',
           'instructions': 'Turn the sleeve once, then hold still.', 'orientation': 'portrait',
           'targetDurationSeconds': 12, 'takesRequested': 2}
AI_SCENE = {'title': 'First look', 'action': 'Show the collection with a clear opening.',
            'dialogue': 'Here is the new studio collection.', 'props': 'One sample garment.',
            'performerRole': 'Presenter', 'shots': [AI_SHOT]}
AI_VALUE = {'summary': 'A short product introduction.', 'scenes': [AI_SCENE]}


class ProductionPlanIntegration(WorkIntegration):
    def plan_call(self, action, data=None, **kwargs):
        return self.call(action, data, endpoint='production-plan-api.php', **kwargs)

    def boot_plan(self, project):
        return self.plan_call('bootstrap', query={'projectId': project['id']})

    def plan_shot(self, **changes):
        return {'id': uuid.uuid4().hex, 'title': 'Planned opening', 'framing': 'Medium shot.',
                'instructions': 'Show the product and pause for the edit.', 'orientation': 'portrait',
                'targetDurationSeconds': 15, 'takesRequested': 2, 'distribution': 'unassigned',
                'userIds': [], 'eligibleRoles': ['model', 'professional'], 'maxContributors': 1,
                'dueLocal': None, **changes}

    def scene(self, shots=None, **changes):
        return {'id': uuid.uuid4().hex, 'title': 'Opening scene', 'action': 'Hold the product in frame.',
                'dialogue': 'A short introduction.', 'props': 'Product sample.',
                'performerRole': 'Presenter', 'shots': shots if shots is not None else [self.plan_shot()], **changes}

    def save_plan(self, project, scenes=None, current=None, **changes):
        current = current or self.boot_plan(project)['plan']
        payload = copy.deepcopy(current)
        if scenes is not None:
            payload['scenes'] = scenes
        payload.update(changes)
        return self.plan_call('save', {'projectId': project['id'], 'revision': current['revision'],
                                     'plan': payload, 'requestId': uuid.uuid4().hex})

    def apply_plan(self, project, plan, **changes):
        roster = self.call('roster', query={'projectId': project['id']})
        return self.plan_call('apply', {'projectId': project['id'], 'revision': plan['revision'],
                                      'rosterRevision': roster['revision'], 'requestId': uuid.uuid4().hex,
                                      **changes})

    def configure(self, provider='openai', enabled=True, daily_limit=100):
        old = self.call('settings', endpoint='ai-settings-api.php')
        return self.call('save', {'revision': old['revision'], 'enabled': enabled,
            'dailyLimit': daily_limit, 'shotProvider': provider, 'editProvider': 'openai',
            'providers': {'openai': {'model': 'gpt-4.1-mini', 'apiKey': 'fixture-plan-openai-not-a-key'},
                          'anthropic': {'model': 'claude-sonnet-4-6', 'apiKey': 'fixture-plan-claude-not-a-key',
                                        'workspaceId': 'wrkspc_fixture'}}}, endpoint='ai-settings-api.php')

    def php_case(self, operation, **values):
        code = r'''
        require $argv[1].'/includes/bootstrap.php';
        $case=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
        $user=az_account_user((int)($case['ownerId']??1));$lock=az_community_lock();
        try {
          if(in_array($case['operation'],['start','prepare'],true)){
            $job=az_production_plan_ai_start($case['body'],$user);
            if(isset($job['existing']))$data=['existing'=>$job['existing']];
            elseif($case['operation']==='prepare')$data=az_production_plan_ai_finish($job['id'],$user,$case['value'],null);
            else $data=['id'=>$job['id'],'provider'=>$job['provider'],'model'=>$job['model'],'payload'=>az_production_plan_ai_request_body($job)];
          }elseif($case['operation']==='finish'){
            $data=az_production_plan_ai_finish($case['id'],$user,$case['value']??null,empty($case['fail'])?null:new AzHttpError(502,'Fixture provider unavailable.','ai_provider_error'));
          }elseif($case['operation']==='payload'){
            $project=az_production_plan_project($case['projectId'],$user);$row=az_production_plan_row($case['projectId']);
            $snapshot=az_production_plan_ai_snapshot($project,$row);
            $snapshot['project']['owner_email']='private-email-sentinel';
            $snapshot['creative']['casting_notes']='private-casting-sentinel';
            $snapshot['roster']=[['id'=>98645,'name'=>'private-model-sentinel']];
            $job=['model'=>'fixture-text-model','provider'=>$case['provider']??'openai','snapshot'=>$snapshot,'sceneCount'=>1,'direction'=>'Portrait product film'];
            $data=az_production_plan_ai_request_body($job);
          }elseif($case['operation']==='parse'){
            $data=az_production_plan_ai_parse_response($case['response'],$case['provider']??'openai',$case['sceneCount']??1,$case['maxShots']??12);
          }elseif($case['operation']==='legacyStart'){
            $job=az_work_planner_start(['projectId'=>$case['projectId'],'requestToken'=>$case['token'],'shotCount'=>1],$user);
            $data=isset($job['existing'])?['existing'=>$job['existing']]:['id'=>$job['id']];
          }elseif($case['operation']==='usage'){
            $data=az_work_ai_usage((int)$user['id']);
          }else throw new RuntimeException('Unknown plan fixture');
          echo json_encode(['ok'=>true,'data'=>$data],JSON_THROW_ON_ERROR);
        }catch(AzHttpError $error){echo json_encode(['ok'=>false,'status'=>$error->status,'code'=>$error->errorCode,'message'=>$error->getMessage()],JSON_THROW_ON_ERROR);}
        '''
        result = subprocess.run([self.php, '-r', code, str(self.root)], input=json.dumps({'operation': operation, **values}),
                                capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr)
        try:
            return json.loads(result.stdout)
        except ValueError:
            self.fail(result.stdout + result.stderr)

    def ai_body(self, project, plan, **changes):
        return {'projectId': project['id'], 'revision': plan['revision'], 'sceneCount': 1,
                'direction': '', 'requestToken': uuid.uuid4().hex, **changes}

    def proposal(self, project, plan, value=None, **body_changes):
        result = self.php_case('prepare', body=self.ai_body(project, plan, **body_changes), value=value or AI_VALUE)
        self.assertTrue(result['ok'], result)
        self.assertEqual(result['data']['state'], 'ready', result)
        return result['data']

    def test_plan_01_default_authorization_saved_revision_and_csrf(self):
        p = self.project(status='draft')
        boot = self.boot_plan(p)
        self.assertEqual(boot['plan']['revision'], 0)
        self.assertEqual(boot['plan']['scenes'], [])
        self.assertTrue(boot['plan']['canEdit'])
        self.assertIn('csrf', boot)
        uid, member = self.member('model'); _, other = self.member('admin')
        self.plan_call('bootstrap', query={'projectId': p['id']}, client=member, expected=403)
        self.plan_call('bootstrap', query={'projectId': p['id']}, client=other, expected=404)
        self.plan_call('bootstrap', query={'projectId': p['id']}, client=harness.Client(self.base), expected=401)
        payload = {**boot['plan'], 'objective': 'A 60-second studio launch.', 'scenes': [self.scene()]}
        body = {'projectId': p['id'], 'revision': 0, 'plan': payload, 'requestId': uuid.uuid4().hex}
        self.plan_call('save', body, csrf=False, expected=403)
        saved = self.plan_call('save', body)
        self.assertEqual(saved['revision'], 1)
        self.assertEqual(saved['objective'], payload['objective'])
        self.assertEqual(self.plan_call('plan', query={'projectId': p['id']}), saved)
        self.assertEqual(self.plan_call('save', body), saved)
        self.assertEqual(self.call('project', query={'id': p['id']})['shots'], [])
        self.plan_call('save', {**body, 'requestId': uuid.uuid4().hex}, expected=409)
        body['plan']['objective'] = 'A different request.'
        self.plan_call('save', body, expected=409)

    def test_plan_02_three_distributions_and_private_scene_context(self):
        p = self.project(); a_id, a = self.member('model'); b_id, b = self.member('professional')
        self.include(p, a_id, b_id)
        direct = self.scene([self.plan_shot(distribution='assigned', userIds=[a_id], eligibleRoles=['model'], title='Private presenter shot')],
                            title='Private scene', dialogue='Private-script-sentinel')
        opened = self.scene([self.plan_shot(distribution='open', maxContributors=2, title='Open detail shot')], title='Product details')
        held = self.scene([self.plan_shot(title='Unassigned later shot')], title='Later scene')
        saved = self.save_plan(p, [direct, opened, held])
        applied = self.apply_plan(p, saved)
        self.assertEqual(applied['revision'], saved['revision'] + 1)
        self.assertTrue(all(scene['locked'] for scene in applied['scenes']))
        self.assertEqual([s['shots'][0]['progress'] for s in applied['scenes']], ['assigned', 'open', 'unassigned'])
        self.assertEqual(len(self.call('project', query={'id': p['id']})['shots']), 3)
        a_work = self.call('myWork', client=a); b_work = self.call('myWork', client=b)
        self.assertEqual(len([x for x in a_work['assigned'] if x['projectId'] == p['id']]), 1)
        self.assertEqual(len([x for x in b_work['open'] if x['projectId'] == p['id']]), 1)
        direct_id = applied['scenes'][0]['shots'][0]['shotId']; open_id = applied['scenes'][1]['shots'][0]['shotId']
        self.call('shot', query={'id': direct_id}, client=b, expected=404)
        context = self.call('shot', query={'id': direct_id}, client=a)['planScene']
        self.assertEqual(context['dialogue'], 'Private-script-sentinel')
        self.assertTrue(context['isCurrent'])
        for key in ['userIds', 'assignments', 'scenes', 'roster']:
            self.assertNotIn(key, context)
        self.assertNotIn('Private-script-sentinel', json.dumps(self.call('shot', query={'id': open_id}, client=b)))
        self.plan_call('plan', query={'projectId': p['id']}, client=a, expected=403)

    def test_plan_03_casting_batch_preflight_and_role_changes(self):
        p = self.project(); a_id, _ = self.member('model'); outsider_id, _ = self.member('model')
        self.include(p, a_id)
        scenes = [self.scene([self.plan_shot(distribution='assigned', userIds=[a_id]),
                              self.plan_shot(distribution='assigned', userIds=[outsider_id], title='Missing member')])]
        saved = self.save_plan(p, scenes)
        roster = self.call('roster', query={'projectId': p['id']})
        failed = self.plan_call('apply', {'projectId': p['id'], 'revision': saved['revision'],
                                        'rosterRevision': roster['revision']}, expected=409)
        self.assertEqual(failed['error']['code'], 'roster_required')
        self.assertEqual(self.call('project', query={'id': p['id']})['shots'], [])
        newer = self.include(p, outsider_id)
        stale = self.plan_call('apply', {'projectId': p['id'], 'revision': saved['revision'],
                                       'rosterRevision': roster['revision']}, expected=409)
        self.assertEqual(stale['error']['code'], 'roster_revision_conflict')
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            db.execute("UPDATE users SET status='suspended' WHERE id=?", [outsider_id])
        rejected = self.plan_call('apply', {'projectId': p['id'], 'revision': saved['revision'],
                                          'rosterRevision': newer['revision']}, expected=409)
        self.assertEqual(rejected['error']['code'], 'contributor_unavailable')
        self.assertEqual(self.plan_call('plan', query={'projectId': p['id']})['revision'], saved['revision'])

    def test_plan_04_capacity_and_producer_exemption(self):
        p = self.project(); a_id, _ = self.member('model'); b_id, _ = self.member('model')
        self.include(p, a_id, b_id)
        saved = self.save_plan(p, [self.scene([self.plan_shot(distribution='assigned', userIds=[a_id, b_id], maxContributors=1)])])
        roster = self.call('roster', query={'projectId': p['id']})
        failed = self.plan_call('apply', {'projectId': p['id'], 'revision': saved['revision'],
                                        'rosterRevision': roster['revision']}, expected=409)
        self.assertEqual(failed['error']['code'], 'capacity_below_assignments')
        changed = copy.deepcopy(saved['scenes']); changed[0]['shots'][0]['userIds'] = [1, a_id]
        saved = self.save_plan(p, changed, current=saved)
        applied = self.apply_plan(p, saved)
        shot = self.call('shot', query={'id': applied['scenes'][0]['shots'][0]['shotId']})
        self.assertEqual(shot['contributorCount'], 1)
        self.assertEqual(shot['slotsRemaining'], 0)
        self.assertEqual(len(shot['assignments']), 2)

    def test_plan_05_atomic_rollback_after_real_database_fault(self):
        p = self.project(); uid, _ = self.member('model'); roster = self.include(p, uid)
        saved = self.save_plan(p, [self.scene([self.plan_shot(distribution='assigned', userIds=[uid]),
                                             self.plan_shot(title='Fail after first')])])
        body = {'projectId': p['id'], 'revision': saved['revision'], 'rosterRevision': roster['revision'],
                'requestId': uuid.uuid4().hex}
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            before = db.execute('SELECT COUNT(*) FROM work_notifications WHERE project_id=?', [p['id']]).fetchone()[0]
            db.execute("CREATE TRIGGER plan_fixture_abort BEFORE INSERT ON production_shots WHEN NEW.title='Fail after first' BEGIN SELECT RAISE(ABORT,'fixture fault'); END")
        try:
            self.plan_call('apply', body, expected=500)
            self.assertEqual(self.call('project', query={'id': p['id']})['shots'], [])
            self.assertEqual(self.plan_call('plan', query={'projectId': p['id']})['revision'], saved['revision'])
            with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
                self.assertEqual(db.execute('SELECT COUNT(*) FROM production_plan_shots WHERE project_id=?', [p['id']]).fetchone()[0], 0)
                self.assertEqual(db.execute('SELECT COUNT(*) FROM work_notifications WHERE project_id=?', [p['id']]).fetchone()[0], before)
                self.assertEqual(db.execute('SELECT COUNT(*) FROM production_plan_requests WHERE client_token=?', [body['requestId']]).fetchone()[0], 0)
        finally:
            with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
                db.execute('DROP TRIGGER plan_fixture_abort')
        self.plan_call('apply', body)
        self.assertEqual(len(self.call('project', query={'id': p['id']})['shots']), 2)

    def test_plan_06_immutable_scenes_append_and_original_script(self):
        p = self.project(); uid, member = self.member('model'); self.include(p, uid)
        saved = self.save_plan(p, [self.scene([self.plan_shot(distribution='assigned', userIds=[uid])])])
        applied = self.apply_plan(p, saved); created = applied['scenes'][0]['shots'][0]['shotId']
        for change in ['modify', 'remove', 'prepend']:
            candidate = copy.deepcopy(applied)
            if change == 'modify': candidate['scenes'][0]['dialogue'] = 'Overwritten line'
            if change == 'remove': candidate['scenes'] = []
            if change == 'prepend': candidate['scenes'].insert(0, self.scene())
            result = self.plan_call('save', {'projectId': p['id'], 'revision': applied['revision'], 'plan': candidate}, expected=409)
            self.assertEqual(result['error']['code'], 'plan_scene_locked')
        expanded = self.save_plan(p, applied['scenes'] + [self.scene(title='Closing scene')], current=applied)
        again = self.apply_plan(p, expanded)
        self.assertEqual(again['scenes'][0]['shots'][0]['shotId'], created)
        self.assertEqual(len(self.call('project', query={'id': p['id']})['shots']), 2)
        shot = self.call('shot', query={'id': created})
        self.call('saveShot', {'id': created, 'revision': shot['revision'], 'brief': 'Updated filming instruction.'})
        visible = self.call('shot', query={'id': created}, client=member)
        self.assertEqual(visible['brief'], 'Updated filming instruction.')
        self.assertFalse(visible['planScene']['isCurrent'])
        self.assertEqual(visible['planScene']['dialogue'], 'A short introduction.')

    def test_plan_07_concurrent_apply_and_closed_receipt_recovery(self):
        p = self.project(status='draft'); saved = self.save_plan(p, [self.scene()])
        roster = self.call('roster', query={'projectId': p['id']})
        body = {'projectId': p['id'], 'revision': saved['revision'], 'rosterRevision': roster['revision'],
                'requestId': uuid.uuid4().hex}
        other = harness.Client(self.base); other.login('test@example.invalid', self.password)
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            futures = [pool.submit(self.plan_call, 'apply', body, client=c) for c in [self.client, other]]
            results = [f.result() for f in futures]
        self.assertEqual(results[0]['scenes'][0]['shots'][0]['shotId'], results[1]['scenes'][0]['shots'][0]['shotId'])
        self.assertEqual(len(self.call('project', query={'id': p['id']})['shots']), 1)
        current = self.call('project', query={'id': p['id']})
        self.call('saveProject', {'id': p['id'], 'revision': current['revision'], 'status': 'archived'})
        recovered = self.plan_call('apply', body)
        self.assertFalse(recovered['canEdit'])
        self.plan_call('save', {'projectId': p['id'], 'revision': recovered['revision'], 'plan': recovered}, expected=409)
        self.plan_call('apply', {**body, 'requestId': uuid.uuid4().hex, 'revision': recovered['revision']}, expected=409)

    def test_plan_08_validation_and_no_forged_links(self):
        p = self.project(); blank = self.boot_plan(p)['plan']
        scenarios = [([self.scene(), self.scene()], 'duplicate'), ([self.scene()], 'bad_role'),
                     ([self.scene()], 'open_people'), ([self.scene()], 'bad_due')]
        for scenes, kind in scenarios:
            if kind == 'duplicate': scenes[1]['id'] = scenes[0]['id']
            if kind == 'bad_role': scenes[0]['shots'][0]['eligibleRoles'] = ['admin']
            if kind == 'open_people': scenes[0]['shots'][0].update(distribution='open', userIds=[1])
            if kind == 'bad_due': scenes[0]['shots'][0]['dueLocal'] = 'not-a-date'
            self.plan_call('save', {'projectId': p['id'], 'revision': 0, 'plan': {**blank, 'scenes': scenes}}, expected=400)
        forged = self.scene(); forged['locked'] = True; forged['shots'][0]['shotId'] = uuid.uuid4().hex
        saved = self.save_plan(p, [forged])
        self.assertFalse(saved['scenes'][0]['locked'])
        self.assertIsNone(saved['scenes'][0]['shots'][0]['shotId'])
        self.assertEqual(self.call('project', query={'id': p['id']})['shots'], [])

    def test_plan_09_ai_disabled_and_provider_payload_privacy(self):
        p = self.project(); saved = self.save_plan(p, [], objective='A portrait introduction.')
        self.configure(enabled=False)
        result = self.plan_call('generate', self.ai_body(p, saved), expected=409)
        self.assertEqual(result['error']['code'], 'ai_disabled')
        existing = self.shot(p, title='Existing manual shot')
        for provider in ['openai', 'anthropic']:
            payload = self.php_case('payload', projectId=p['id'], provider=provider)['data']
            encoded = json.dumps(payload)
            self.assertNotIn('private-', encoded)
            self.assertNotIn(p['id'], encoded)
            self.assertNotIn(existing['id'], encoded)
            content = json.loads(payload['input'] if provider == 'openai' else payload['messages'][0]['content'])
            self.assertEqual(content['existingShotTitles'], ['Existing manual shot'])
            self.assertEqual(content['maximumNewShots'], 12)
            self.assertNotIn('userIds', encoded)
            if provider == 'openai':
                self.assertFalse(payload['store'])
                self.assertTrue(payload['text']['format']['strict'])
            else:
                self.assertEqual(payload['output_config']['format']['type'], 'json_schema')

    def test_plan_10_provider_response_validation(self):
        for provider in ['openai', 'anthropic']:
            response = {'status': 'completed', 'output': [{'content': [{'type': 'output_text', 'text': json.dumps(AI_VALUE)}]}]} if provider == 'openai' else {'type': 'message', 'role': 'assistant', 'stop_reason': 'end_turn', 'content': [{'type': 'text', 'text': json.dumps(AI_VALUE)}]}
            parsed = self.php_case('parse', provider=provider, response=response)
            self.assertTrue(parsed['ok'], parsed)
            self.assertEqual(parsed['data'], AI_VALUE)
        bad = copy.deepcopy(AI_VALUE); bad['scenes'][0]['shots'][0]['userIds'] = [1]
        invalids = [({'status': 'incomplete', 'output': []}, 'ai_incomplete'),
                    ({'status': 'completed', 'output': [{'content': [{'type': 'refusal'}]}]}, 'ai_refusal'),
                    ({'status': 'completed', 'output': [{'content': [{'type': 'output_text', 'text': 'bad-json'}]}]}, 'ai_response'),
                    ({'status': 'completed', 'output': [{'content': [{'type': 'output_text', 'text': json.dumps(bad)}]}]}, 'ai_invalid_proposal')]
        for response, code in invalids:
            self.assertEqual(self.php_case('parse', response=response)['code'], code)
        duplicate = copy.deepcopy(AI_VALUE); duplicate['scenes'][0]['shots'].append(copy.deepcopy(AI_SHOT))
        response = {'status': 'completed', 'output': [{'content': [{'type': 'output_text', 'text': json.dumps(duplicate)}]}]}
        self.assertEqual(self.php_case('parse', response=response, maxShots=1)['code'], 'ai_invalid_proposal')

    def test_plan_11_ai_review_adoption_and_retries_do_not_create_work(self):
        self.configure(); p = self.project(); saved = self.save_plan(p, [])
        token = uuid.uuid4().hex; proposal = self.proposal(p, saved, requestToken=token)
        self.assertEqual(self.plan_call('latest', query={'projectId': p['id']})['id'], proposal['id'])
        self.assertEqual(self.plan_call('plan', query={'projectId': p['id']})['scenes'], [])
        self.assertEqual(self.call('project', query={'id': p['id']})['shots'], [])
        retry = self.plan_call('generate', self.ai_body(p, saved, requestToken=token))
        self.assertEqual(retry['id'], proposal['id'])
        self.plan_call('generate', self.ai_body(p, saved, requestToken=token, direction='Changed'), expected=409)
        uid, _ = self.member('model'); self.include(p, uid)
        body = {'projectId': p['id'], 'proposalId': proposal['id'], 'revision': saved['revision'], 'requestId': uuid.uuid4().hex}
        adopted = self.plan_call('useProposal', body)
        self.assertEqual(adopted['revision'], saved['revision'] + 1)
        self.assertEqual(adopted['scenes'][0]['dialogue'], AI_SCENE['dialogue'])
        self.assertEqual(adopted['scenes'][0]['shots'][0]['distribution'], 'unassigned')
        self.assertEqual(adopted['scenes'][0]['shots'][0]['userIds'], [])
        self.assertEqual(self.call('project', query={'id': p['id']})['shots'], [])
        self.assertEqual(len(self.plan_call('useProposal', body)['scenes']), 1)
        self.assertEqual(len(self.plan_call('useProposal', {**body, 'requestId': uuid.uuid4().hex})['scenes']), 1)
        applied = self.apply_plan(p, adopted)
        self.assertIsNotNone(applied['scenes'][0]['shots'][0]['shotId'])

    def test_plan_12_stale_project_plan_and_existing_shot_proposals(self):
        self.configure()
        for kind in ['plan', 'project', 'shot']:
            p = self.project(); saved = self.save_plan(p, []); proposal = self.proposal(p, saved)
            if kind == 'plan': saved = self.save_plan(p, current=saved, objective='New objective.')
            elif kind == 'project': self.call('saveProject', {'id': p['id'], 'revision': p['revision'], 'brief': 'A changed brief.'})
            else: self.shot(p, title='A manual shot added after generation')
            result = self.plan_call('useProposal', {'projectId': p['id'], 'proposalId': proposal['id'], 'revision': saved['revision']}, expected=409)
            self.assertEqual(result['error']['code'], 'proposal_stale')
            self.assertEqual(self.plan_call('plan', query={'projectId': p['id']})['scenes'], [])

    def test_plan_13_provider_identity_pending_quota_and_expiry(self):
        self.configure('anthropic'); p = self.project(); saved = self.save_plan(p, [])
        body = self.ai_body(p, saved); job = self.php_case('start', body=body)['data']
        self.assertEqual(job['provider'], 'anthropic')
        self.configure('openai')
        replay = self.php_case('start', body=body)['data']['existing']
        self.assertEqual(replay['provider'], 'anthropic')
        blocked = self.php_case('legacyStart', projectId=p['id'], token=uuid.uuid4().hex)
        self.assertEqual(blocked['code'], 'ai_busy')
        complete = self.php_case('finish', id=job['id'], value=AI_VALUE)['data']
        self.assertEqual(complete['provider'], 'anthropic')
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            db.execute("UPDATE production_plan_ai_proposals SET state='pending',created_at='2020-01-01T00:00:00Z',proposal_json='{}' WHERE id=?", [job['id']])
        expired = self.plan_call('latest', query={'projectId': p['id']})
        self.assertEqual(expired['state'], 'failed')
        self.assertEqual(expired['errorCode'], 'ai_interrupted')
        # Late transport completion cannot restore an expired request.
        self.assertEqual(self.php_case('finish', id=job['id'], value=AI_VALUE)['data']['state'], 'failed')
        self.configure(daily_limit=1)
        quota = self.plan_call('generate', self.ai_body(p, saved), expected=429)
        self.assertEqual(quota['error']['code'], 'ai_daily_limit')
        self.configure()

    def test_plan_14_ai_capacity_rejected_before_reserving_and_budget_enforced(self):
        self.configure(); p = self.project()
        filled = self.save_plan(p, [self.scene(title=f'Scene {n}') for n in range(20)])
        before = self.php_case('usage')['data']
        result = self.plan_call('generate', self.ai_body(p, filled), expected=409)
        self.assertEqual(result['error']['code'], 'plan_limit')
        self.assertEqual(self.php_case('usage')['data'], before)
        near = self.project()
        scenes = [self.scene([self.plan_shot(title=f'Shot {s}-{n}') for n in range(12 if s < 4 else 11)], title=f'Scene {s}') for s in range(5)]
        saved = self.save_plan(near, scenes)
        result = self.plan_call('generate', self.ai_body(near, saved, sceneCount=2), expected=409)
        self.assertEqual(result['error']['code'], 'plan_limit')
        self.assertEqual(self.php_case('usage')['data'], before)
        job = self.php_case('start', body=self.ai_body(near, saved))['data']
        self.assertEqual(json.loads(job['payload']['input'])['maximumNewShots'], 1)
        oversized = copy.deepcopy(AI_VALUE); oversized['scenes'][0]['shots'].append(copy.deepcopy(AI_SHOT))
        failed = self.php_case('finish', id=job['id'], value=oversized)['data']
        self.assertEqual(failed['state'], 'failed')
        self.assertEqual(failed['errorCode'], 'ai_invalid_proposal')
        self.assertEqual(self.call('project', query={'id': near['id']})['shots'], [])

    def test_plan_15_schema_replay_preserves_plan_links_and_receipts(self):
        p = self.project(); saved = self.save_plan(p, [self.scene()]); roster = self.call('roster', query={'projectId': p['id']})
        body = {'projectId': p['id'], 'revision': saved['revision'], 'rosterRevision': roster['revision'], 'requestId': uuid.uuid4().hex}
        applied = self.plan_call('apply', body)
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            db.execute('UPDATE studio_schema SET version=14 WHERE id=1')
        self.call('bootstrap')
        recovered = self.plan_call('apply', body)
        self.assertEqual(recovered['scenes'][0]['shots'][0]['shotId'], applied['scenes'][0]['shots'][0]['shotId'])
        self.assertEqual(recovered['revision'], applied['revision'])
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            self.assertGreaterEqual(db.execute('SELECT version FROM studio_schema WHERE id=1').fetchone()[0], 15)

    def test_plan_16_ai_reserves_room_for_existing_uncreated_plan_shots(self):
        self.configure(); p = self.project(); original = self.shot(p)
        with sqlite3.connect(self.root / 'storage/interviews.sqlite') as db:
            db.row_factory = sqlite3.Row
            template = dict(db.execute('SELECT * FROM production_shots WHERE id=?', [original['id']]).fetchone())
            keys = list(template)
            for n in range(198):
                row = {**template, 'id': uuid.uuid4().hex, 'title': f'Existing shot {n}'}
                db.execute('INSERT INTO production_shots (' + ','.join(keys) + ') VALUES (' + ','.join('?' for _ in keys) + ')', [row[k] for k in keys])
        saved = self.save_plan(p, [self.scene()])
        before = self.php_case('usage')['data']
        blocked = self.plan_call('generate', self.ai_body(p, saved), expected=409)
        self.assertEqual(blocked['error']['code'], 'plan_limit')
        self.assertEqual(self.php_case('usage')['data'], before)
        # The already-written plan still fits the last real production place.
        applied = self.apply_plan(p, saved)
        self.assertIsNotNone(applied['scenes'][0]['shots'][0]['shotId'])


def load_tests(loader, tests, pattern):
    return unittest.TestSuite(ProductionPlanIntegration(name) for name in loader.getTestCaseNames(ProductionPlanIntegration) if name.startswith('test_plan_'))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(); parser.add_argument('--php', default='php'); args, rest = parser.parse_known_args()
    ProductionPlanIntegration.php = args.php
    unittest.main(argv=[__file__, *rest], verbosity=2)
