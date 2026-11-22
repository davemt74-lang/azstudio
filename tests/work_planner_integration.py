#!/usr/bin/env python3
"""Optional AI proposal boundaries. Provider responses are fixtures, never paid calls.

HTTP tests run against a disposable installation. The CLI helper seeds an already
received provider response in that installation, without a production test endpoint.
"""
import argparse
import json
import sqlite3
import subprocess
import unittest
import uuid
from work_integration import WorkIntegration

SHOT = {'title': 'Product close-up', 'brief': 'Frame the product label, then show the texture under soft light.',
        'orientation': 'portrait', 'targetDurationSeconds': 15, 'takesRequested': 2}

class PlannerIntegration(WorkIntegration):
    def planner(self, action, data=None, query=None, client=None, expected=200, csrf=True):
        return self.call(action, data, query, client, expected, csrf, 'work-planner-api.php')

    def configure(self, enabled=True, client=None, **changes):
        old = self.planner('settings', client=client)
        return self.planner('saveSettings', {'revision': old['revision'], 'enabled': enabled,
            'model': 'fixture-model-not-for-network', 'apiKey': 'fixture-key-no-real-credential',
            'dailyLimit': 100, **changes}, client=client)

    def php_case(self, operation, **data):
        code = r'''
        require $argv[1] . '/includes/bootstrap.php';
        $case=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
        $user=az_account_user((int)($case['ownerId']??1));$lock=az_community_lock();
        try {
          if($case['operation']==='prepare'){
            $job=az_work_planner_start(['projectId'=>$case['projectId'],'requestToken'=>$case['token'],'shotCount'=>count($case['shots'])],$user);
            $data=isset($job['existing'])?$job['existing']:az_work_planner_finish($job['id'],$user,['summary'=>'A fixture proposal for review.','shots'=>$case['shots']],null);
          }elseif($case['operation']==='parse'){
            $data=az_work_planner_parse_response($case['response']);
          }elseif($case['operation']==='payload'){
            $project=az_work_project_row($case['projectId'],$user,true);
            $data=az_work_planner_request_body(['model'=>'fixture-model','snapshot'=>az_work_planner_snapshot($project),'count'=>3,'direction'=>'Portrait launch']);
          }elseif($case['operation']==='decrypt'){
            $settings=az_work_planner_settings_row((int)$user['id']);
            $data=['roundtrip'=>hash_equals('fixture-key-no-real-credential',az_work_planner_decrypt($settings['key_ciphertext']))];
          }elseif($case['operation']==='fill'){
            for($i=0;$i<$case['count'];$i++)az_work_save_shot(['projectId'=>$case['projectId'],'title'=>'Existing '.$i,'brief'=>'Existing direction.'], $user);
            $data=['filled'=>$case['count']];
          }else throw new RuntimeException('Unknown test fixture');
          echo json_encode(['ok'=>true,'data'=>$data],JSON_THROW_ON_ERROR);
        }catch(AzHttpError $e){echo json_encode(['ok'=>false,'status'=>$e->status,'code'=>$e->errorCode]);}
        '''
        result = subprocess.run([self.php, '-r', code, str(self.root)], input=json.dumps({'operation': operation, **data}),
                                capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stderr)
        try:
            return json.loads(result.stdout)
        except ValueError:
            self.fail(result.stdout + result.stderr)

    def proposal(self, project, shots=None, token=None):
        result = self.php_case('prepare', projectId=project['id'], shots=shots or [SHOT], token=token or uuid.uuid4().hex)
        self.assertTrue(result['ok'], result)
        return result['data']

    def test_planner_01_optional_disabled_and_settings_private(self):
        initial = self.planner('settings')
        self.assertFalse(initial['enabled'])
        self.assertFalse(initial['configured'])
        p = self.project()
        self.planner('generate', {'projectId':p['id'], 'requestToken':uuid.uuid4().hex}, expected=409)
        saved = self.configure()
        self.assertTrue(saved['hasKey'])
        self.assertNotIn('fixture-key',json.dumps(saved))
        self.assertNotIn('ciphertext',json.dumps(saved))
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            value = db.execute("SELECT key_ciphertext FROM ai_provider_settings WHERE owner_id=1 AND provider='openai'").fetchone()[0]
            self.assertNotIn('fixture-key',value)
        self.assertTrue(self.php_case('decrypt')['data']['roundtrip'])
        self.planner('saveSettings', {'revision':initial['revision'], 'enabled':False}, expected=409)
        self.planner('saveSettings', {'revision':saved['revision'], 'enabled':False}, csrf=False, expected=403)

    def test_planner_02_roles_ownership_and_key_isolation(self):
        uid, member = self.member('model')
        self.planner('settings', client=member, expected=403)
        self.planner('saveSettings', {'enabled':True}, client=member, expected=403)
        owner, other = self.member('admin')
        self.assertFalse(self.planner('settings',client=other)['hasKey'])
        p = self.project()
        proposal = self.proposal(p)
        self.planner('latest',query={'projectId':p['id']},client=other,expected=404)
        self.planner('proposal',query={'id':proposal['id']},client=other,expected=404)
        self.planner('apply',{'proposalId':proposal['id'],'shots':[SHOT]},client=other,expected=404)

    def test_planner_03_schema_payload_and_response_failure_states(self):
        p = self.project()
        self.shot(p, title='Existing studio opening')
        payload=self.php_case('payload',projectId=p['id'])['data']
        self.assertEqual(payload['text']['format']['type'],'json_schema')
        self.assertTrue(payload['text']['format']['strict'])
        self.assertFalse(payload['text']['format']['schema']['additionalProperties'])
        self.assertFalse(payload['store'])
        self.assertEqual(payload['max_output_tokens'],6000)
        self.assertNotIn('owner_id',payload['input'])
        self.assertNotIn('storage_name',payload['input'])
        response={'status':'completed','output':[{'type':'message','content':[{'type':'output_text','text':json.dumps({'summary':'Ready','shots':[SHOT]})}]}]}
        self.assertEqual(self.php_case('parse',response=response)['data']['shots'],[SHOT])
        cases=[({'status':'incomplete','output':[]},'ai_incomplete'),
               ({'status':'completed','output':[{'content':[{'type':'refusal','refusal':'Fixture refusal'}]}]},'ai_refusal'),
               ({'status':'completed','output':[{'content':[{'type':'output_text','text':'not-json'}]}]},'ai_response'),
               ({'status':'completed','output':[{'content':[{'type':'output_text','text':json.dumps({'shots':[{**SHOT,'targetDurationSeconds':99999}]})}]}]},'ai_invalid_proposal')]
        for response,code in cases:
            self.assertEqual(self.php_case('parse',response=response)['code'],code)

    def test_planner_04_edit_apply_and_idempotent_retry(self):
        p=self.project(status='draft')
        token=uuid.uuid4().hex
        proposal=self.proposal(p,[SHOT,{**SHOT,'title':'Alternate angle'}],token)
        self.assertEqual(len(self.call('project',query={'id':p['id']})['shots']),0)
        self.assertEqual(self.proposal(p,[SHOT,{**SHOT,'title':'Alternate angle'}],token)['id'],proposal['id'])
        self.assertEqual(self.php_case('prepare',projectId=p['id'],shots=[SHOT],token=token)['code'],'request_conflict')
        edited=[{**SHOT,'title':'Reviewed title','brief':'Use a medium shot with clear product placement.'}]
        applied=self.planner('apply',{'proposalId':proposal['id'],'shots':edited})
        self.assertEqual(applied['state'],'applied')
        self.assertEqual(len(applied['appliedShotIds']),1)
        detail=self.call('project',query={'id':p['id']})
        self.assertEqual(detail['shots'][0]['title'],'Reviewed title')
        self.assertEqual(detail['shots'][0]['assignmentCount'],0)
        self.assertEqual(detail['shots'][0]['mode'],'assigned')
        retry=self.planner('apply',{'proposalId':proposal['id'],'shots':edited})
        self.assertEqual(retry['appliedShotIds'],applied['appliedShotIds'])
        self.assertEqual(len(self.call('project',query={'id':p['id']})['shots']),1)

    def test_planner_05_stale_brief_shot_changes_and_project_closure(self):
        p=self.project()
        proposal=self.proposal(p)
        self.shot(p)
        failed=self.planner('apply',{'proposalId':proposal['id'],'shots':[SHOT]},expected=409)
        self.assertEqual(failed['error']['code'],'proposal_stale')
        p=self.project(); proposal=self.proposal(p)
        self.call('saveProject',{'id':p['id'],'revision':p['revision'],'brief':'An updated production brief.'})
        self.planner('apply',{'proposalId':proposal['id'],'shots':[SHOT]},expected=409)
        p=self.project(); proposal=self.proposal(p)
        self.call('saveProject',{'id':p['id'],'revision':p['revision'],'status':'archived'})
        self.planner('apply',{'proposalId':proposal['id'],'shots':[SHOT]},expected=409)

    def test_planner_06_batch_rolls_back_when_project_limit_reached(self):
        p=self.project()
        self.assertTrue(self.php_case('fill',projectId=p['id'],count=199)['ok'])
        proposal=self.proposal(p,[SHOT,{**SHOT,'title':'Second shot'}])
        self.planner('apply',{'proposalId':proposal['id'],'shots':proposal['shots']},expected=409)
        self.assertEqual(len(self.call('project',query={'id':p['id']})['shots']),199)
        self.assertEqual(self.planner('proposal',query={'id':proposal['id']})['state'],'ready')

    def test_planner_07_validation_daily_limit_and_key_removal(self):
        p=self.project()
        proposal=self.proposal(p)
        self.planner('apply',{'proposalId':proposal['id'],'shots':[{**SHOT,'title':''}]},expected=400)
        self.assertEqual(len(self.call('project',query={'id':p['id']})['shots']),0)
        old=self.planner('settings')
        self.planner('saveSettings',{'revision':old['revision'],'model':old['model'],'enabled':True,'dailyLimit':1})
        failed=self.planner('generate',{'projectId':p['id'],'requestToken':uuid.uuid4().hex},expected=429)
        self.assertEqual(failed['error']['code'],'ai_daily_limit')
        old=self.planner('settings')
        cleared=self.planner('saveSettings',{'revision':old['revision'],'enabled':False,'removeKey':True,'model':old['model']})
        self.assertFalse(cleared['hasKey'])
        self.assertFalse(cleared['configured'])
        self.planner('generate',{'projectId':p['id'],'requestToken':uuid.uuid4().hex},expected=409)

    def test_planner_08_schema_replay_keeps_proposals_and_credentials(self):
        self.configure()
        p=self.project(); proposal=self.proposal(p)
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            db.execute('UPDATE studio_schema SET version=7 WHERE id=1')
        self.call('bootstrap')
        self.assertEqual(self.planner('proposal',query={'id':proposal['id']})['id'],proposal['id'])
        self.assertTrue(self.php_case('decrypt')['data']['roundtrip'])
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            self.assertGreaterEqual(db.execute('SELECT version FROM studio_schema').fetchone()[0],9)

    def test_planner_09_interrupted_requests_expire_without_creating_shots(self):
        p=self.project(); proposal=self.proposal(p)
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            db.execute("UPDATE work_ai_proposals SET state='pending',created_at='2020-01-01T00:00:00Z',proposal_json='{}' WHERE id=?",[proposal['id']])
        expired=self.planner('latest',query={'projectId':p['id']})
        self.assertEqual(expired['state'],'failed')
        self.assertIn('interrupted',expired['error'])
        self.assertEqual(len(self.call('project',query={'id':p['id']})['shots']),0)
        self.assertEqual(self.proposal(p)['state'],'ready')

def load_tests(loader,tests,pattern):
    return unittest.TestSuite(PlannerIntegration(name) for name in loader.getTestCaseNames(PlannerIntegration) if name.startswith('test_planner_'))

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--php',default='php');args,rest=parser.parse_known_args()
    PlannerIntegration.php=args.php
    unittest.main(argv=[__file__,*rest],verbosity=2)
