#!/usr/bin/env python3
"""Metadata-only edit assistant tests. Provider output is a fixture; no paid calls."""
from __future__ import annotations
import argparse
import json
import sqlite3
import subprocess
import unittest
import uuid
from work_media_integration import WorkMediaIntegration


class ComposerIntegration(WorkMediaIntegration):
    def composer(self, action, data=None, query=None, client=None, expected=200, csrf=True):
        return self.call(action, data, query, client, expected, csrf, 'edit-composer-api.php')

    def director(self, action, data=None, query=None, expected=200):
        return self.call(action, data, query, expected=expected, endpoint='director-api.php')

    def edits(self, action, data=None, query=None, client=None, expected=200):
        return self.call(action, data, query, client, expected, endpoint='edit-api.php')

    def configure(self, enabled=True, daily_limit=100):
        old=self.composer('settings')
        return self.call('saveSettings',{'revision':old['revision'],'enabled':enabled,'model':'fixture-model','apiKey':'fixture-key-no-provider-call','dailyLimit':daily_limit},endpoint='work-planner-api.php')

    def ready(self, **changes):
        p,shot,a,member,take=self.approved(self.project(**changes))
        overview=self.director('requirement.save',{'projectId':p['id'],'title':'Opening introduction','purpose':'opening','minimumSelections':1,'targetDurationSeconds':0,'required':True,'sortOrder':0,'shotIds':[shot['id']],'requestId':uuid.uuid4().hex})
        return p,overview,a,member,take

    def php_case(self, operation, **data):
        source=r'''
        require $argv[1].'/includes/bootstrap.php';
        $case=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
        $user=az_account_user((int)($case['ownerId']??1));$lock=az_community_lock();
        try {
          if($case['operation']==='prepare'||$case['operation']==='start'){
            $job=az_edit_composer_start(['projectId'=>$case['projectId'],'requestToken'=>$case['token'],'targetDurationSeconds'=>$case['target']??60,'direction'=>$case['direction']??''], $user);
            if(isset($job['existing']))$data=$job['existing'];
            elseif($case['operation']==='start')$data=az_edit_composer_data(az_edit_composer_row($job['id'],$user),$user);
            else {
              $c=reset($job['snapshot']['candidates']);
              $clip=['candidateId'=>$c['candidateId'],'startMs'=>$c['startMs'],'endMs'=>$c['endMs'],'title'=>'A reviewed opening','showCard'=>false,'reason'=>'Producer-labeled opening.'];
              $proposal=az_edit_composer_validate(['summary'=>'Start with the approved introduction.','clips'=>[$clip],'missingCoverage'=>[]],$job);
              $data=az_edit_composer_finish($job['id'],$user,$proposal,null);
            }
          }elseif($case['operation']==='finish'){
            $row=az_edit_composer_row($case['id'],$user);$job=['model'=>$row['model_id'],'snapshot'=>json_decode($row['snapshot_json'],true)];
            $c=reset($job['snapshot']['candidates']);
            $proposal=az_edit_composer_validate(['summary'=>'Saved late.','clips'=>[['candidateId'=>$c['candidateId'],'startMs'=>$c['startMs'],'endMs'=>$c['endMs'],'title'=>'Opening','showCard'=>false,'reason'=>'Approved metadata.']],'missingCoverage'=>[]],$job);
            $data=az_edit_composer_finish($row['id'],$user,$proposal,null);
          }else{
            $snapshot=az_director_snapshot($case['projectId'],$user);
            $base=az_director_propose(['projectId'=>$case['projectId'],'targetDurationSeconds'=>60],$user);
            $job=['model'=>'fixture-model','snapshot'=>['project'=>$snapshot['project'],'requirements'=>$snapshot['requirements'],'coverage'=>$snapshot['coverage'],'candidates'=>az_edit_composer_candidates($snapshot),'settings'=>$base['edit']['settings'],'warnings'=>$base['warnings'],'request'=>['targetDurationSeconds'=>$case['target']??60,'direction'=>'Use producer notes.']]];
            if($case['operation']==='payload')$data=az_edit_composer_request_body($job);
            elseif($case['operation']==='parse')$data=az_edit_composer_parse_response($case['response'],$job);
            else{
              $c=reset($job['snapshot']['candidates']);
              $clip=['candidateId'=>$c['candidateId'],'startMs'=>$c['startMs'],'endMs'=>$c['endMs'],'title'=>'Opening','showCard'=>false,'reason'=>'Producer-labeled opening.'];
              if(isset($case['changes']))$clip=array_replace($clip,$case['changes']);
              $clips=[$clip];if(!empty($case['duplicate']))$clips[]=$clip;
              $data=az_edit_composer_validate(['summary'=>'Fixture','clips'=>$clips,'missingCoverage'=>[]],$job);
            }
          }
          echo json_encode(['ok'=>true,'data'=>$data],JSON_THROW_ON_ERROR);
        }catch(AzHttpError $e){echo json_encode(['ok'=>false,'status'=>$e->status,'code'=>$e->errorCode,'message'=>$e->getMessage()]);}
        '''
        result=subprocess.run([self.php,'-r',source,str(self.root)],input=json.dumps({'operation':operation,**data}).encode(),stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=True)
        self.assertFalse(result.stderr,result.stderr.decode())
        return json.loads(result.stdout)

    def proposal(self, p, **extra):
        self.configure()
        result=self.php_case('prepare',projectId=p['id'],token=extra.pop('token',uuid.uuid4().hex),**extra)
        self.assertTrue(result['ok'],result)
        return result['data']

    def save_proposal(self, p, proposal, **extra):
        body={'projectId':p['id'],'title':'Launch teaser','targetDurationSeconds':proposal['targetDurationMs']//1000,'requestToken':uuid.uuid4().hex,'edit':proposal['edit'],'directorFingerprint':proposal['fingerprint'],'proposalId':proposal['id'],**extra}
        return body

    def test_composer_01_disabled_role_and_csrf_boundaries(self):
        p,*_=self.ready();self.configure(False)
        self.assertFalse(self.composer('settings')['enabled'])
        self.composer('generate',{'projectId':p['id'],'requestToken':uuid.uuid4().hex},expected=409)
        self.composer('generate',{'projectId':p['id'],'requestToken':uuid.uuid4().hex},expected=403,csrf=False)
        _,member=self.member('model');self.composer('latest',query={'projectId':p['id']},client=member,expected=403)
        _,other=self.member('admin');self.composer('latest',query={'projectId':p['id']},client=other,expected=404)

    def test_composer_02_payload_is_text_only_and_structured(self):
        p,overview,a,member,take=self.ready()
        self.client.api('addTakeNote',{'takeId':take['takeId'],'atMs':400,'text':'Keep the introduction here.'})
        result=self.php_case('payload',projectId=p['id']);self.assertTrue(result['ok'],result)
        payload=result['data'];context=json.loads(payload['input'])
        self.assertFalse(payload['store']);self.assertEqual(payload['text']['format']['type'],'json_schema')
        self.assertTrue(payload['text']['format']['strict']);self.assertEqual(payload['max_output_tokens'],6000)
        self.assertEqual(context['project']['title'],p['title']);self.assertEqual(len(context['candidates']),1)
        self.assertEqual(context['candidates'][0]['reviewNotes'],[{'atMs':400,'text':'Keep the introduction here.','truncated':False}])
        for forbidden in ['mediaUrl','storage_name','owner_id','account','apiKey','file_name','reference']:
            self.assertNotIn(forbidden,payload['input'])

    def test_composer_03_provider_refusal_incomplete_and_bad_json(self):
        p,*_=self.ready()
        responses=[({'status':'incomplete','output':[]},'ai_incomplete'),({'status':'failed','output':[]},'ai_incomplete'),({'status':'completed','output':[{'content':[{'type':'refusal'}]}]},'ai_refusal'),({'status':'completed','output':[{'content':[{'type':'output_text','text':'not json'}]}]},'ai_response'),({'status':'completed','output':[{'content':[{'type':'output_text','text':'[]'}]}]},'ai_invalid_proposal')]
        for response,code in responses:
            with self.subTest(code=code):self.assertEqual(self.php_case('parse',projectId=p['id'],response=response)['code'],code)
        for response in [{'status':'completed','output':'bad'},{'status':'completed','output':[{'content':'bad'}]},{'status':'completed','output':[{'content':[None]}]},{'status':'completed','output':[],'error':{'message':'Failed'}}]:
            self.assertEqual(self.php_case('parse',projectId=p['id'],response=response)['code'],'ai_response')

    def test_composer_04_unknown_duplicate_invalid_and_out_of_curated_ranges(self):
        p,overview,a,member,take=self.ready()
        req=overview['requirements'][0]
        self.director('selection.save',{'projectId':p['id'],'requirementId':req['id'],'takeId':take['takeId'],'label':'Keep this section','notes':'Producer-reviewed range','startMs':300,'endMs':1000,'preferred':True,'requestId':uuid.uuid4().hex})
        self.assertTrue(self.php_case('validate',projectId=p['id'])['ok'])
        cases=[{'candidateId':'invented'}, {'startMs':0}, {'endMs':1500}, {'endMs':320}, {'startMs':999,'endMs':400}]
        for changes in cases:
            with self.subTest(changes=changes):self.assertFalse(self.php_case('validate',projectId=p['id'],changes=changes)['ok'])
        self.assertFalse(self.php_case('validate',projectId=p['id'],duplicate=True)['ok'])
        result=self.php_case('validate',projectId=p['id'],changes={'endMs':800})['data']
        self.assertEqual(result['durationMs'],500)
        self.assertTrue(any('0.5 of the requested 60' in note for note in result['warnings']))
        self.assertFalse(any('produces 0.7' in note for note in result['warnings']))
        p,*_=self.ready(kind='interview')
        self.assertFalse(self.php_case('validate',projectId=p['id'],target=1)['ok'])

    def test_composer_05_recovery_and_request_parameter_consistency(self):
        p,*_=self.ready();token=uuid.uuid4().hex
        first=self.proposal(p,token=token)
        second=self.proposal(p,token=token)
        self.assertEqual(first['id'],second['id'])
        recovered=self.composer('request',query={'projectId':p['id'],'requestToken':token})
        self.assertEqual(recovered['id'],first['id'])
        self.assertEqual(self.php_case('prepare',projectId=p['id'],token=token,target=30)['code'],'request_conflict')
        self.assertEqual(self.php_case('prepare',projectId=p['id'],token=token,direction='changed')['code'],'request_conflict')
        self.composer('request',query={'projectId':p['id'],'requestToken':uuid.uuid4().hex},expected=404)

    def test_composer_06_reviewed_proposal_creates_one_edit_and_preserves_receipt(self):
        p,*_=self.ready();proposal=self.proposal(p)
        self.assertEqual(proposal['state'],'ready');self.assertFalse(proposal['stale'])
        body=self.save_proposal(p,proposal)
        body['edit']['segments'][body['edit']['order'][0]]['title']='Producer revised card'
        saved=self.edits('timeline.create',body)
        self.assertEqual(saved['title'],'Launch teaser')
        self.assertEqual(self.edits('timeline.create',body)['id'],saved['id'])
        receipt=self.composer('proposal',query={'id':proposal['id']})
        self.assertEqual(receipt['state'],'applied');self.assertEqual(receipt['appliedTimelineId'],saved['id'])
        rejected=self.edits('timeline.create',{**body,'requestToken':uuid.uuid4().hex},expected=409)
        self.assertEqual(rejected['error']['code'],'proposal_not_ready')
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:self.assertEqual(db.execute('SELECT COUNT(*) FROM production_edits WHERE project_id=?',[p['id']]).fetchone()[0],1)

    def test_composer_07_stale_coverage_rolls_back_new_draft(self):
        p,overview,*_=self.ready();proposal=self.proposal(p)
        req=overview['requirements'][0]
        self.director('requirement.save',{**req,'projectId':p['id'],'title':'Updated editorial opening','requestId':uuid.uuid4().hex})
        stale=self.composer('proposal',query={'id':proposal['id']});self.assertTrue(stale['stale'])
        self.edits('timeline.create',self.save_proposal(p,proposal),expected=409)
        self.edits('timeline.create',self.save_proposal(p,proposal,directorFingerprint=self.director('project',query={'projectId':p['id']})['fingerprint']),expected=409)
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:self.assertEqual(db.execute('SELECT COUNT(*) FROM production_edits WHERE project_id=?',[p['id']]).fetchone()[0],0)

    def test_composer_08_withdrawal_and_late_provider_response(self):
        p,overview,a,member,take=self.ready();self.configure()
        pending=self.php_case('start',projectId=p['id'],token=uuid.uuid4().hex)['data']
        self.call('reviewSubmission',{'submissionId':take['id'],'revision':take['revision'],'decision':'retake','note':'Replace this source.'})
        result=self.php_case('finish',id=pending['id']);self.assertTrue(result['ok'],result)
        self.assertTrue(result['data']['stale'])
        self.edits('timeline.create',self.save_proposal(p,result['data']),expected=409)

    def test_composer_09_shared_pending_quota_and_interruption(self):
        p,*_=self.ready();self.configure()
        pending=self.php_case('start',projectId=p['id'],token=uuid.uuid4().hex)['data']
        self.assertEqual(self.php_case('start',projectId=p['id'],token=uuid.uuid4().hex)['code'],'ai_busy')
        self.call('generate',{'projectId':p['id'],'shotCount':1,'requestToken':uuid.uuid4().hex},expected=409,endpoint='work-planner-api.php')
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:db.execute("UPDATE edit_ai_proposals SET created_at='2020-01-01T00:00:00Z' WHERE id=?",[pending['id']])
        expired=self.composer('latest',query={'projectId':p['id']});self.assertEqual(expired['state'],'failed')
        self.configure(daily_limit=1)
        self.assertEqual(self.php_case('start',projectId=p['id'],token=uuid.uuid4().hex)['code'],'ai_daily_limit')
        self.configure()

    def test_composer_10_scope_upgrade_and_completed_production(self):
        p,*_=self.ready();proposal=self.proposal(p)
        _,other=self.member('admin');self.composer('proposal',query={'id':proposal['id']},client=other,expected=404)
        self.call('saveProject',{'id':p['id'],'revision':p['revision'],'status':'completed'})
        self.assertEqual(self.proposal(p)['state'],'ready')
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            before=db.execute('SELECT COUNT(*) FROM edit_ai_proposals').fetchone()[0]
            db.execute('UPDATE studio_schema SET version=11 WHERE id=1')
        self.composer('settings')
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            self.assertEqual(db.execute('SELECT version FROM studio_schema').fetchone()[0],16)
            self.assertEqual(db.execute('SELECT COUNT(*) FROM edit_ai_proposals').fetchone()[0],before)


def load_tests(loader, tests, pattern):
    return unittest.TestSuite(ComposerIntegration(name) for name in loader.getTestCaseNames(ComposerIntegration) if name.startswith('test_composer_'))


if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--php',default='php');args,rest=parser.parse_known_args()
    ComposerIntegration.php=args.php
    unittest.main(argv=[__file__,*rest],verbosity=2)
