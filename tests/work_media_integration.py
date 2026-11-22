#!/usr/bin/env python3
"""Production media/editor/phone HTTP tests in a disposable installation."""
from __future__ import annotations
import argparse
import json
import pathlib
import sqlite3
import unittest
import uuid
import backend_integration as harness
from work_integration import WorkIntegration
from phone_integration import PhoneIntegration
from production_integration import PNG


class WorkMediaIntegration(WorkIntegration):
    request_phone = PhoneIntegration.request_phone
    invitation = PhoneIntegration.invitation
    paired = PhoneIntegration.paired
    phone_chunk = PhoneIntegration.phone_chunk

    def approved(self, project=None):
        p=project or self.project()
        shot,a,uid,member=self.assigned(project=p)
        result,upload,body,content=self.upload(a,member)
        detail=self.call('assignment',query={'id':a['id']},client=member)
        self.call('submitAssignment',{'assignmentId':a['id'],'revision':detail['revision']},client=member)
        submission=self.submission(a,self.client,result['submissionId'])
        accepted=self.call('reviewSubmission',{'submissionId':submission['id'],'revision':submission['revision'],'decision':'accepted'})
        return p,shot,a,member,accepted

    def finished_upload(self,p,ids):
        content=(pathlib.Path(__file__).resolve().parents[1]/'qa/fixtures/answer-test.webm').read_bytes()
        status,result=self.client.api('beginUpload',{'interviewId':p['interviewId'],'purpose':'finished','title':'Production edit','status':'draft','sourceEdit':{'selectedTakeIds':ids,'edit':{'settings':{'cardMode':'none'}}},'fileName':'production.webm','mimeType':'video/webm','durationMs':6000,'totalBytes':len(content)})
        self.assertEqual(status,201,result)
        upload=result['data']
        for index,offset in enumerate(range(0,len(content),upload['chunkBytes'])):
            status,result=self.client.multipart('uploadChunk',{'uploadId':upload['uploadId'],'index':index},'chunk',content[offset:offset+upload['chunkBytes']])
            self.assertEqual(status,200,result)
        return upload,content

    def test_media_01_accepted_only_editor_preserves_trim_and_card_defaults(self):
        p,shot,a,member,accepted=self.approved()
        _,pending,_,model=self.assigned(project=p)
        hidden,*_=self.upload(pending,model)
        status,data=self.client.api('interview',query={'id':p['interviewId']})
        self.assertEqual(status,200,data);edit=data['data']
        self.assertEqual([t['id'] for t in edit['takes']],[accepted['takeId']])
        self.assertEqual(edit['state']['edit']['settings']['cardMode'],'none')
        self.assertEqual(edit['production']['kind'],'production')
        self.assertIn(shot['id'],[q['id'] for q in edit['questionnaireSnapshot']['questions']])
        state={'edit':{'order':[accepted['takeId']],'segments':{accepted['takeId']:{'included':True,'trimStart':.1,'trimEnd':1.1,'title':'Keep the trim','showCard':False}},'settings':{'cardMode':'none','fadeDuration':.2}}}
        status,saved=self.client.api('updateInterview',{'id':p['interviewId'],'revision':edit['revision'],'state':state,'modelId':None})
        self.assertEqual(status,200,saved)
        refreshed=self.client.api('interview',query={'id':p['interviewId']})[1]['data']
        self.assertEqual(refreshed['state'],state)
        self.assertNotIn(hidden['takeId'],json.dumps(refreshed['takes']))
        self.call('reviewSubmission',{'submissionId':accepted['id'],'revision':accepted['revision'],'decision':'retake','note':'New framing required.'})
        stale=self.client.api('interview',query={'id':p['interviewId']})[1]['data']
        self.assertEqual(stale['takes'],[])
        self.assertEqual(stale['production']['unavailableSelectedIds'],[accepted['takeId']])

    def test_media_02_json_export_receipt_and_stale_finalize(self):
        p,shot,a,member,accepted=self.approved()
        upload,content=self.finished_upload(p,[accepted['takeId']])
        status,result=self.client.api('finishUpload',{'uploadId':upload['uploadId']})
        self.assertEqual(status,200,result)
        video=result['data']['video']
        self.assertEqual(video['interviewId'],p['interviewId'])
        self.assertEqual(video['sourceEdit']['selectedTakeIds'],[accepted['takeId']])
        self.assertEqual(self.client.request(video['mediaUrl'].lstrip('/'))[2],content)
        stale,_=self.finished_upload(p,[accepted['takeId']])
        self.call('reviewSubmission',{'submissionId':accepted['id'],'revision':accepted['revision'],'decision':'retake','note':'Withdraw this source from the edit.'})
        status,result=self.client.api('finishUpload',{'uploadId':stale['uploadId']})
        self.assertEqual(status,409,result);self.assertEqual(result['error']['code'],'export_source_changed')
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM finished_videos WHERE interview_id=?',[p['interviewId']]).fetchone()[0],1)

    def test_media_03_mixed_import_provenance_idempotency_and_withdrawal(self):
        p=self.project(kind='mixed')
        interview=self.create()
        take,content=self.direct_take(interview['id'],isPrivate='false')
        self.media_call('importSource',{'projectId':p['id'],'takeId':take['id']},expected=409)
        status,review=self.client.api('updateTakeReview',{'takeId':take['id'],'reviewStatus':'shortlisted','isFavorite':True})
        self.assertEqual(status,200,review)
        status,note=self.client.api('addTakeNote',{'takeId':take['id'],'atMs':400,'text':'Use this expression in the mixed edit.'})
        self.assertEqual(status,200,note)
        source_edit={'edit':{'order':[take['id']],'segments':{take['id']:{'included':True,'title':'Mixed source title','trimStart':.3,'trimEnd':.9,'showCard':True}}}}
        status,saved=self.client.api('updateInterview',{'id':interview['id'],'state':source_edit})
        self.assertEqual(status,200,saved)
        source=self.media_call('sourceInterviews',query={'projectId':p['id']})
        self.assertIn(take['id'],[t['id'] for group in source for t in group['takes']])
        imported=self.media_call('importSource',{'projectId':p['id'],'takeId':take['id']},expected=201)
        again=self.media_call('importSource',{'projectId':p['id'],'takeId':take['id']},expected=201)
        self.assertEqual(imported['takeId'],again['takeId']);self.assertFalse(again['imported'])
        editor=self.client.api('interview',query={'id':p['interviewId']})[1]['data']
        self.assertEqual(editor['state']['edit']['settings']['cardMode'],'perClip')
        self.assertEqual(editor['takes'][0]['sourceKind'],'interview')
        self.assertEqual(editor['state']['edit']['order'],[imported['takeId']])
        imported_config=editor['state']['edit']['segments'][imported['takeId']]
        self.assertEqual((imported_config['trimStart'],imported_config['trimEnd'],imported_config['title']),(.3,.9,'Mixed source title'))
        self.assertTrue(editor['takes'][0]['isFavorite'])
        self.assertEqual([(n['atMs'],n['text']) for n in editor['takes'][0]['reviewNotes']],[(400,'Use this expression in the mixed edit.')])
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            provenance=db.execute('SELECT source_take_id,source_interview_id FROM work_source_takes WHERE take_id=?',[imported['takeId']]).fetchone()
        self.assertEqual(provenance,(take['id'],interview['id']))
        self.assertEqual(self.client.request(editor['takes'][0]['mediaUrl'].lstrip('/'))[2],content)
        self.client.api('updateTakeReview',{'takeId':take['id'],'reviewStatus':'private'})
        removed=self.client.api('interview',query={'id':p['interviewId']})[1]['data']
        self.assertEqual(removed['takes'],[])
        uid,other=self.member('admin')
        self.media_call('sourceInterviews',query={'projectId':p['id']},client=other,expected=404)

    def test_media_04_phone_targets_host_selected_shot_and_producer_identity(self):
        p=self.project();shot,a,uid,member=self.assigned(project=p)
        other=self.shot(project=p,title='Different shot')
        _,phone=self.paired(interview={'id':p['interviewId']})
        status,data=self.request_phone('context',{'interviewId':p['interviewId'],'questionId':shot['id'],'questionText':'Forged title','isPrivate':False},host=True)
        self.assertEqual(status,200,data)
        content=harness.make_wav();token='phone:'+uuid.uuid4().hex
        body={'clientToken':token,'fileName':'phone.wav','mimeType':'audio/wav','totalBytes':len(content),'durationMs':1200,'questionId':other['id'],'assignmentId':a['id'],'contributorId':uid,'purpose':'finished','isPrivate':False}
        status,data=self.request_phone('beginUpload',body,phone=phone);self.assertEqual(status,201,data);upload=data['data']
        self.request_phone('context',{'interviewId':p['interviewId'],'questionId':other['id']},host=True)
        status,repeat=self.request_phone('beginUpload',body,phone=phone);self.assertEqual(status,200,repeat)
        self.assertEqual(repeat['data']['uploadId'],upload['uploadId'])
        status,data=self.phone_chunk(phone,upload['uploadId'],0,content);self.assertEqual(status,200,data)
        status,saved=self.request_phone('finishUpload',{'uploadId':upload['uploadId']},phone=phone);self.assertEqual(status,200,saved)
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            row=db.execute('SELECT t.question_id,t.is_private,s.contributor_id,s.uploaded_by,s.status FROM takes t JOIN shot_submissions s ON s.take_id=t.id WHERE t.id=?',[saved['data']['takeId']]).fetchone()
            self.assertEqual(row,(shot['id'],1,1,1,'draft'))
            owner_assignment=db.execute('SELECT assignment_id FROM shot_submissions WHERE take_id=?',[saved['data']['takeId']]).fetchone()[0]
        self.assertEqual(self.client.api('interview',query={'id':p['interviewId']})[1]['data']['takes'],[])
        detail=self.call('assignment',query={'id':owner_assignment})
        self.assertTrue(detail['canSubmit'])
        self.call('submitAssignment',{'assignmentId':owner_assignment,'revision':detail['revision']})
        submitted=self.call('assignment',query={'id':owner_assignment})['submissions'][0]
        self.call('reviewSubmission',{'submissionId':submitted['id'],'revision':submitted['revision'],'decision':'accepted'})
        self.assertEqual(len(self.client.api('interview',query={'id':p['interviewId']})[1]['data']['takes']),1)
        self.request_phone('revoke',{'interviewId':p['interviewId'],'id':phone['id']},host=True)
        self.assertEqual(self.request_phone('finishUpload',{'uploadId':upload['uploadId']},phone=phone)[0],403)

    def test_media_05_private_reference_ranges_and_role_boundary(self):
        p=self.project();shot,a,uid,member=self.assigned(project=p)
        boundary='ReferenceBoundary';fields={'projectId':p['id'],'shotId':shot['id'],'title':'Mood reference'}
        parts=[f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode() for k,v in fields.items()]
        parts += [f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="reference.png"\r\nContent-Type: image/png\r\n\r\n'.encode(),PNG,f'\r\n--{boundary}--\r\n'.encode()]
        status,_,raw=self.client.request('work-upload-api.php?action=uploadReference',b''.join(parts),{'Content-Type':f'multipart/form-data; boundary={boundary}','X-CSRF-Token':self.client.csrf})
        result=json.loads(raw);self.assertEqual(status,201,result);reference=result['data'];url=reference['mediaUrl'].lstrip('/')
        status,headers,content=member.request(url,headers={'Range':'bytes=0-7'})
        self.assertEqual(status,206);self.assertEqual(content,PNG[:8]);self.assertEqual(headers['Cache-Control'],'private, no-store')
        self.assertEqual(harness.Client(self.base).request(url)[0],401)
        _,stranger=self.member('model');self.assertEqual(stranger.request(url)[0],404)
        self.media_call('uploadReference',fields,client=member,expected=404)

    def test_media_06_finish_recovers_file_committed_before_submission_receipt(self):
        shot,a,uid,member=self.assigned()
        saved,upload,body,content=self.upload(a,member)
        # Simulate interruption after the durable take/finished upload commit,
        # before its submission transaction and link receipt became durable.
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            db.execute('UPDATE work_uploads SET submission_id=NULL WHERE upload_id=?',[upload['uploadId']])
            db.execute('DELETE FROM shot_submissions WHERE id=?',[saved['submissionId']])
        recovered=self.media_call('finishUpload',{'uploadId':upload['uploadId'],'assignmentId':a['id']},client=member)
        again=self.media_call('finishUpload',{'uploadId':upload['uploadId'],'assignmentId':a['id']},client=member)
        self.assertEqual(recovered,again);self.assertEqual(recovered['takeId'],saved['takeId'])
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM takes WHERE id=?',[saved['takeId']]).fetchone()[0],1)
            self.assertEqual(db.execute('SELECT COUNT(*) FROM shot_submissions WHERE take_id=?',[saved['takeId']]).fetchone()[0],1)
            self.assertEqual(db.execute('SELECT submission_id FROM work_uploads WHERE upload_id=?',[upload['uploadId']]).fetchone()[0],recovered['submissionId'])

    def test_media_07_studio_capture_begin_receipt_recovery_keeps_shot_and_private_review(self):
        p=self.project();shot=self.shot(project=p);other=self.shot(project=p,title='Other shot')
        content=harness.make_wav();body={'id':str(uuid.uuid4()),'interviewId':p['interviewId'],'questionId':shot['id'],'fileName':'studio.wav','mimeType':'audio/wav','durationMs':1200,'totalBytes':len(content)}
        status,result=self.client.api('beginUpload',body);self.assertEqual(status,201,result);upload=result['data']
        status,repeated=self.client.api('beginUpload',body);self.assertEqual(status,201,repeated)
        self.assertEqual(repeated['data']['uploadId'],upload['uploadId'])
        status,wrong=self.client.api('beginUpload',{**body,'questionId':other['id']});self.assertEqual(status,409,wrong)
        status,chunk=self.client.multipart('uploadChunk',{'uploadId':upload['uploadId'],'index':0},'chunk',content);self.assertEqual(status,200,chunk)
        status,saved=self.client.api('finishUpload',{'uploadId':upload['uploadId']});self.assertEqual(status,200,saved)
        self.assertTrue(saved['data']['isPrivate'])
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM work_uploads WHERE upload_id=?',[upload['uploadId']]).fetchone()[0],1)
            self.assertEqual(db.execute('SELECT status FROM shot_submissions WHERE take_id=?',[saved['data']['id']]).fetchone()[0],'draft')


if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--php',default='php');args,remaining=parser.parse_known_args()
    WorkMediaIntegration.php=args.php
    suite=unittest.TestSuite(WorkMediaIntegration(name) for name in unittest.defaultTestLoader.getTestCaseNames(WorkMediaIntegration) if name.startswith('test_media_'))
    result=unittest.TextTestRunner(verbosity=2).run(suite)
    raise SystemExit(0 if result.wasSuccessful() else 1)
