#!/usr/bin/env python3
"""Editable timeline integration checks against isolated PHP HTTP + SQLite."""
from __future__ import annotations
import argparse
import concurrent.futures
import copy
import json
import pathlib
import sqlite3
import unittest
import uuid
from work_media_integration import WorkMediaIntegration


class EditVersionsIntegration(WorkMediaIntegration):
    def edit_call(self, action, data=None, **kwargs):
        return self.call(action, data, endpoint='edit-api.php', **kwargs)

    def draft(self, p, take_id, **extra):
        body={'projectId':p['id'],'title':'Launch teaser','targetDurationSeconds':30,
              'requestToken':str(uuid.uuid4()),'edit':{'order':[take_id],
              'segments':{take_id:{'included':True,'title':'Opening','trimStart':.1,'trimEnd':1.1,'showCard':False}},
              'settings':{'cardMode':'none','cardDuration':3,'fadeDuration':.45,'aspect':'9:16','resolution':720,'fit':'contain','brand':'AZHOTTIE','modelName':''}},**extra}
        return self.edit_call('timeline.create',body),body

    def test_edits_01_named_cuts_keep_independent_ranges_and_legacy_edit(self):
        p,shot,a,member,take=self.approved()
        before=self.client.api('interview',query={'id':p['interviewId']})[1]['data']['state']
        one,body=self.draft(p,take['takeId'])
        two,_=self.draft(p,take['takeId'],title='Full interview',targetDurationSeconds=0)
        changed=copy.deepcopy(one['edit']);changed['segments'][take['takeId']]['trimStart']=.4
        saved=self.edit_call('timeline.save',{'id':one['id'],'revision':1,'edit':changed,'title':'Short teaser','requestToken':str(uuid.uuid4())})
        self.assertEqual(saved['revision'],2)
        self.assertEqual(saved['edit']['segments'][take['takeId']]['trimStart'],.4)
        self.assertEqual(self.edit_call('timeline',query={'id':two['id']})['edit']['segments'][take['takeId']]['trimStart'],.1)
        after=self.client.api('interview',query={'id':p['interviewId']})[1]['data']['state']
        self.assertEqual(before,after)
        self.assertEqual(len(saved['history']),2)

    def test_edits_02_retry_receipts_revision_conflicts_and_historical_restore(self):
        p,_,_,_,take=self.approved();one,body=self.draft(p,take['takeId'])
        self.assertEqual(self.edit_call('timeline.create',body)['id'],one['id'])
        self.edit_call('timeline.create',{**body,'title':'Different request'},expected=409)
        payload={'id':one['id'],'revision':1,'title':'New name','requestToken':str(uuid.uuid4())}
        second=self.edit_call('timeline.save',payload)
        self.assertEqual(self.edit_call('timeline.save',payload)['revision'],2)
        self.edit_call('timeline.save',{**payload,'requestToken':str(uuid.uuid4())},expected=409)
        old=self.edit_call('timeline',query={'id':one['id'],'revision':1})
        self.assertTrue(old['historical']);self.assertEqual(old['latestRevision'],2)
        self.assertEqual(old['title'],'Launch teaser')
        restored=self.edit_call('timeline.restore',{'id':one['id'],'revision':2,'restoreRevision':1,'requestToken':str(uuid.uuid4())})
        self.assertEqual(restored['revision'],3);self.assertEqual(restored['title'],'Launch teaser')
        self.assertEqual(self.edit_call('timeline',query={'id':one['id'],'revision':2})['title'],'New name')
        self.call('saveProject',{'id':p['id'],'revision':p['revision'],'status':'archived'})
        self.assertEqual(self.edit_call('timeline.create',body)['id'],one['id'],'Lost create acknowledgment remains recoverable after project closes')
        self.assertEqual(self.edit_call('timeline.save',payload)['revision'],2,'Lost save acknowledgment remains recoverable after project closes')
        self.edit_call('timeline.save',{'id':one['id'],'revision':3,'title':'New change after archive','requestToken':str(uuid.uuid4())},expected=409)

    def test_edits_03_member_other_admin_csrf_and_cross_project_denied(self):
        p,_,_,member,take=self.approved();one,body=self.draft(p,take['takeId'])
        _,admin=self.member('admin')
        self.edit_call('timeline',query={'id':one['id']},client=member,expected=403)
        self.edit_call('timeline',query={'id':one['id']},client=admin,expected=404)
        self.edit_call('list',query={'projectId':p['id']},client=admin,expected=404)
        self.edit_call('timeline.create',{**body,'requestToken':str(uuid.uuid4())},csrf=False,expected=403)
        other=self.project()
        self.edit_call('timeline.create',{**body,'projectId':other['id'],'requestToken':str(uuid.uuid4())},expected=409)

    def test_edits_04_source_withdrawal_is_readable_but_cannot_save_restore_or_export(self):
        p,_,_,_,take=self.approved();one,body=self.draft(p,take['takeId'])
        self.call('reviewSubmission',{'submissionId':take['id'],'revision':take['revision'],'decision':'retake','note':'New framing needed.'})
        stale=self.edit_call('timeline',query={'id':one['id']})
        self.assertEqual(stale['unavailableSelectedIds'],[take['takeId']])
        self.edit_call('timeline.save',{'id':one['id'],'revision':1,'edit':one['edit'],'requestToken':str(uuid.uuid4())},expected=409)
        self.edit_call('timeline.restore',{'id':one['id'],'revision':1,'restoreRevision':1,'requestToken':str(uuid.uuid4())},expected=409)
        archived=self.edit_call('timeline.save',{'id':one['id'],'revision':1,'status':'archived','requestToken':str(uuid.uuid4())})
        self.assertEqual(archived['status'],'archived')

    def test_edits_05_exact_export_revision_and_finished_return_link(self):
        p,_,_,_,take=self.approved();one,body=self.draft(p,take['takeId'])
        source={'timelineId':one['id'],'timelineRevision':1,'edit':one['edit'],'selectedTakeIds':[take['takeId']]}
        content=(pathlib.Path(__file__).resolve().parents[1]/'qa/fixtures/answer-test.webm').read_bytes()
        upload_body={'interviewId':p['interviewId'],'purpose':'finished','title':'Named render','status':'draft','sourceEdit':source,'fileName':'cut.webm','mimeType':'video/webm','durationMs':1000,'totalBytes':len(content)}
        bad=copy.deepcopy(upload_body);bad['sourceEdit']['edit']['segments'][take['takeId']]['trimStart']=.2
        status,result=self.client.api('beginUpload',bad);self.assertEqual(status,409,result)
        status,result=self.client.api('beginUpload',upload_body);self.assertEqual(status,201,result);upload=result['data']
        self.edit_call('timeline.save',{'id':one['id'],'revision':1,'title':'New current name','requestToken':str(uuid.uuid4())})
        for index,offset in enumerate(range(0,len(content),upload['chunkBytes'])):
            status,result=self.client.multipart('uploadChunk',{'uploadId':upload['uploadId'],'index':index},'chunk',content[offset:offset+upload['chunkBytes']]);self.assertEqual(status,200,result)
        status,result=self.client.api('finishUpload',{'uploadId':upload['uploadId']});self.assertEqual(status,200,result)
        video=result['data']['video'];self.assertIn('timelineRevision=1',video['editorUrl']);self.assertIn(one['id'],video['editorUrl'])
        listed=self.edit_call('timeline',query={'id':one['id']});self.assertEqual(listed['lastRender']['id'],video['id']);self.assertEqual(listed['lastRender']['timelineRevision'],1)
        self.edit_call('timeline.delete',{'id':one['id'],'revision':2,'requestToken':str(uuid.uuid4())},expected=409)

    def test_edits_06_invalid_ranges_settings_and_stale_director_proposal_rejected(self):
        p,_,_,_,take=self.approved();one,body=self.draft(p,take['takeId'])
        for start,end in [(-1,1),(1,1.01),(0,9000)]:
            bad=copy.deepcopy(body);bad['requestToken']=str(uuid.uuid4());bad['edit']['segments'][take['takeId']].update(trimStart=start,trimEnd=end)
            self.edit_call('timeline.create',bad,expected=400)
        for field,value in [('cardDuration',16),('fadeDuration',4),('aspect','4:3'),('resolution',800)]:
            bad=copy.deepcopy(body);bad['requestToken']=str(uuid.uuid4());bad['edit']['settings'][field]=value
            self.edit_call('timeline.create',bad,expected=400)
        self.edit_call('timeline.create',{**body,'requestToken':str(uuid.uuid4()),'directorFingerprint':'0'*64},expected=409)
        listed=self.edit_call('list',query={'projectId':p['id']})
        self.assertEqual([d['id'] for d in listed['timelines']],[one['id']])

    def test_edits_07_simultaneous_saves_preserve_one_revision_and_delete_receipt(self):
        p,_,_,_,take=self.approved();one,_=self.draft(p,take['takeId'])
        def save(n):
            body={'id':one['id'],'revision':1,'title':f'Edit {n}','requestToken':str(uuid.uuid4())}
            status,_,raw=self.client.request('edit-api.php?action=timeline.save',json.dumps(body).encode(),{'Content-Type':'application/json','X-CSRF-Token':self.client.csrf})
            return status,json.loads(raw)
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool: results=list(pool.map(save,[1,2]))
        self.assertEqual(sorted(r[0] for r in results),[200,409])
        saved=self.edit_call('timeline',query={'id':one['id']});self.assertEqual(saved['latestRevision'],2);self.assertEqual(len(saved['history']),2)
        body={'id':one['id'],'revision':2,'requestToken':str(uuid.uuid4())}
        self.assertTrue(self.edit_call('timeline.delete',body)['deleted'])
        self.assertTrue(self.edit_call('timeline.delete',body)['deleted'])
        self.edit_call('timeline',query={'id':one['id']},expected=404)

    def test_edits_08_director_proposal_cannot_extend_or_bridge_curated_ranges(self):
        p,shot,_,_,take=self.approved()
        overview=self.call('requirement.save',{'projectId':p['id'],'title':'Opening','purpose':'opening','shotIds':[shot['id']]},endpoint='director-api.php')
        req=overview['requirements'][0]
        overview=self.call('selection.save',{'projectId':p['id'],'requirementId':req['id'],'takeId':take['takeId'],'label':'First moment','startMs':100,'endMs':400,'preferred':True},endpoint='director-api.php')
        overview=self.call('selection.save',{'projectId':p['id'],'requirementId':req['id'],'takeId':take['takeId'],'label':'Second moment','startMs':700,'endMs':1000,'preferred':False},endpoint='director-api.php')
        base={'projectId':p['id'],'title':'Curated edit','directorFingerprint':overview['fingerprint'],'edit':{'order':[take['takeId']],'segments':{take['takeId']:{'included':True,'title':'Opening','trimStart':.1,'trimEnd':.9,'showCard':False}},'settings':{'cardMode':'none'}}}
        self.edit_call('timeline.create',{**base,'requestToken':str(uuid.uuid4())},expected=409)
        base['edit']['segments'][take['takeId']]['trimEnd']=.35
        saved=self.edit_call('timeline.create',{**base,'requestToken':str(uuid.uuid4())})
        self.assertEqual(saved['edit']['segments'][take['takeId']]['trimEnd'],.35)

    def test_edits_09_withdrawing_unused_bin_footage_does_not_invalidate_a_render(self):
        p,_,_,_,take=self.approved();_,_,_,_,unused=self.approved(project=p)
        one,body=self.draft(p,take['takeId'])
        edit=copy.deepcopy(one['edit']);edit['order'].append(unused['takeId']);edit['segments'][unused['takeId']]={'included':False,'title':'Unused alternate','trimStart':0,'trimEnd':1,'showCard':False}
        saved=self.edit_call('timeline.save',{'id':one['id'],'revision':1,'edit':edit,'requestToken':str(uuid.uuid4())})
        self.call('reviewSubmission',{'submissionId':unused['id'],'revision':unused['revision'],'decision':'retake','note':'Unused alternate needs another attempt.'})
        content=(pathlib.Path(__file__).resolve().parents[1]/'qa/fixtures/answer-test.webm').read_bytes()
        source={'timelineId':one['id'],'timelineRevision':saved['revision'],'edit':saved['edit'],'selectedTakeIds':[take['takeId']]}
        status,result=self.client.api('beginUpload',{'interviewId':p['interviewId'],'purpose':'finished','title':'Still valid render','sourceEdit':source,'fileName':'cut.webm','mimeType':'video/webm','durationMs':1000,'totalBytes':len(content)})
        self.assertEqual(status,201,result)
        self.assertEqual(self.edit_call('timeline',query={'id':one['id']})['unavailableSelectedIds'],[])


if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--php',default='php');args,remaining=parser.parse_known_args()
    EditVersionsIntegration.php=args.php
    suite=unittest.TestSuite(EditVersionsIntegration(n) for n in unittest.defaultTestLoader.getTestCaseNames(EditVersionsIntegration) if n.startswith('test_edits_'))
    result=unittest.TextTestRunner(verbosity=2).run(suite)
    raise SystemExit(0 if result.wasSuccessful() else 1)
