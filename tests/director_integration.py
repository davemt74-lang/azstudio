#!/usr/bin/env python3
"""Director source/coverage/first-cut checks in a disposable four-worker install."""
from __future__ import annotations
import argparse
import concurrent.futures
import json
import pathlib
import sqlite3
import threading
import unittest
import uuid
import backend_integration as harness
from work_media_integration import WorkMediaIntegration


class DirectorIntegration(WorkMediaIntegration):
    def director(self, action, data=None, query=None, client=None, expected=200, csrf=True):
        return self.call(action, data, query, client, expected, csrf, 'director-api.php')

    def overview(self, project, **kwargs):
        return self.director('project', query={'projectId': project['id']}, **kwargs)

    def requirement(self, project, shot=None, **extra):
        body={'projectId':project['id'],'title':'Opening introduction','purpose':'opening',
              'minimumSelections':1,'targetDurationSeconds':0,'required':True,'sortOrder':0,
              'shotIds':[shot['id']] if shot else [],'requestId':'requirement:'+uuid.uuid4().hex,**extra}
        data=self.director('requirement.save',body)
        return next(r for r in data['requirements'] if r['title']==body['title'])

    def selection(self, project, requirement, accepted, **extra):
        body={'projectId':project['id'],'requirementId':requirement['id'],'takeId':accepted['takeId'],
              'label':'Usable answer','notes':'Confident opening line.','startMs':100,'endMs':1000,
              'preferred':False,'requestId':'selection:'+uuid.uuid4().hex,**extra}
        data=self.director('selection.save',body)
        return next(s for s in data['selections'] if s['label']==body['label'])

    def test_director_01_permissions_owner_scope_csrf_and_schema(self):
        p=self.project()
        self.overview(p,client=harness.Client(self.base),expected=401)
        for role in ('model','professional','fan'):
            _,member=self.member(role)
            self.overview(p,client=member,expected=403)
            self.director('requirement.save',{'projectId':p['id'],'title':'Forbidden'},client=member,expected=403)
        _,other=self.member('admin')
        self.overview(p,client=other,expected=404)
        self.director('requirement.save',{'projectId':p['id'],'title':'Missing CSRF'},csrf=False,expected=403)
        self.assertEqual(self.overview(p)['coverage']['status'],'unplanned')
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            self.assertGreaterEqual(db.execute('SELECT version FROM studio_schema').fetchone()[0],10)

    def test_director_02_requirement_receipts_revisions_link_scope_and_delete(self):
        p=self.project();shot=self.shot(p)
        body={'projectId':p['id'],'title':'Repeat-safe introduction','purpose':'opening','shotIds':[shot['id']],
              'minimumSelections':2,'targetDurationSeconds':4,'requestId':'requirement:'+uuid.uuid4().hex}
        first=self.director('requirement.save',body)
        repeated=self.director('requirement.save',body)
        self.assertEqual(len(repeated['requirements']),1)
        requirement=first['requirements'][0]
        self.assertEqual(repeated['requirements'][0]['id'],requirement['id'])
        self.director('requirement.save',{**body,'title':'Changed payload'},expected=409)
        bad_shot=self.shot(self.project())
        self.director('requirement.save',{'projectId':p['id'],'title':'Cross project','shotIds':[bad_shot['id']]},expected=404)
        self.director('requirement.save',{'projectId':p['id'],'id':requirement['id'],'title':'No revision'},expected=409)
        updated=self.director('requirement.save',{'projectId':p['id'],'id':requirement['id'],'revision':requirement['revision'],
                              'title':'Updated title','shotIds':[]})['requirements'][0]
        self.assertEqual(updated['revision'],2);self.assertEqual(updated['shotIds'],[])
        self.director('requirement.delete',{'projectId':p['id'],'id':updated['id'],'revision':1},expected=409)
        result=self.director('requirement.delete',{'projectId':p['id'],'id':updated['id'],'revision':2})
        self.assertEqual(result['requirements'],[])
        self.assertEqual(self.call('shot',query={'id':shot['id']})['id'],shot['id'])

    def test_director_03_selection_ranges_receipts_and_source_scope(self):
        p,shot,a,member,accepted=self.approved();requirement=self.requirement(p,shot)
        base={'projectId':p['id'],'requirementId':requirement['id'],'takeId':accepted['takeId'],
              'label':'Keep this','startMs':100,'endMs':1000,'preferred':True,'requestId':'selection:'+uuid.uuid4().hex}
        first=self.director('selection.save',base);again=self.director('selection.save',base)
        self.assertEqual(len(again['selections']),1)
        selected=first['selections'][0]
        self.assertTrue(selected['eligible']);self.assertEqual(selected['durationMs'],900)
        for bounds in ({'startMs':1000,'endMs':900},{'startMs':0,'endMs':1300},{'startMs':.1,'endMs':1000},{'startMs':0,'endMs':99}):
            self.director('selection.save',{**base,**bounds,'requestId':'bad:'+uuid.uuid4().hex},expected=400)
        other_p,other_shot,_,_,other_accepted=self.approved()
        self.director('selection.save',{**base,'takeId':other_accepted['takeId'],'requestId':'cross:'+uuid.uuid4().hex},expected=409)
        self.director('selection.save',{**base,'id':selected['id'],'revision':99,'requestId':'stale:'+uuid.uuid4().hex},expected=409)
        self.call('reviewSubmission',{'submissionId':accepted['id'],'revision':accepted['revision'],'decision':'retake','note':'Change the framing.'})
        changed=self.overview(p)
        self.assertFalse(changed['selections'][0]['eligible']);self.assertEqual(changed['eligibleTakes'],[])
        self.assertEqual(changed['candidates'],[])
        self.director('selection.save',{**base,'requestId':'withdrawn:'+uuid.uuid4().hex},expected=409)
        self.director('selection.delete',{'projectId':p['id'],'id':selected['id'],'revision':selected['revision']})
        self.assertEqual(self.overview(p)['selections'],[])

    def test_director_04_coverage_tracks_current_review_and_retake_state(self):
        p=self.project();shot,a,_,member=self.assigned(project=p);requirement=self.requirement(p,shot)
        self.assertEqual(self.overview(p)['requirements'][0]['coverage']['status'],'missing')
        result,*_=self.upload(a,member)
        assignment=self.call('assignment',query={'id':a['id']},client=member)
        self.call('submitAssignment',{'assignmentId':a['id'],'revision':assignment['revision']},client=member)
        waiting=self.overview(p)['requirements'][0]['coverage']
        self.assertEqual(waiting['status'],'awaiting_review');self.assertEqual(waiting['awaitingReviewCount'],1)
        sub=self.submission(a,self.client,result['submissionId'])
        retake=self.call('reviewSubmission',{'submissionId':sub['id'],'revision':sub['revision'],'decision':'retake','note':'Try the line again.'})
        self.assertEqual(self.overview(p)['requirements'][0]['coverage']['status'],'retake_needed')
        replacement,*_=self.upload(a,member)
        assignment=self.call('assignment',query={'id':a['id']},client=member)
        self.call('submitAssignment',{'assignmentId':a['id'],'revision':assignment['revision']},client=member)
        sub=self.submission(a,self.client,replacement['submissionId'])
        self.call('reviewSubmission',{'submissionId':sub['id'],'revision':sub['revision'],'decision':'accepted'})
        ready=self.overview(p)
        self.assertEqual(ready['coverage']['status'],'ready')
        self.assertEqual(ready['requirements'][0]['coverage']['retakeCount'],0)
        self.assertEqual(ready['requirements'][0]['coverage']['acceptedCount'],1)

    def test_director_05_coverage_unique_sources_overlap_union_and_unknown_duration(self):
        p,shot,_,_,accepted=self.approved()
        requirement=self.requirement(p,shot,minimumSelections=2,targetDurationSeconds=1)
        self.selection(p,requirement,accepted,label='First range',startMs=0,endMs=800)
        self.selection(p,requirement,accepted,label='Overlapping range',startMs=400,endMs=1200)
        overview=self.overview(p);coverage=overview['requirements'][0]['coverage']
        self.assertEqual(coverage['acceptedCount'],1);self.assertEqual(coverage['durationMs'],1200)
        self.assertEqual(coverage['missingCount'],1);self.assertEqual(coverage['status'],'missing')
        self.assertEqual(len(overview['candidates']),2)
        self.assertTrue(all(c['selectionId'] for c in overview['candidates']))
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            db.execute('UPDATE takes SET duration_ms=0 WHERE id=?',[accepted['takeId']])
        changed=self.overview(p)
        self.assertEqual(changed['requirements'][0]['coverage']['durationMs'],0)
        self.assertEqual(changed['requirements'][0]['coverage']['durationMissingMs'],1000)
        self.assertEqual(changed['candidates'],[])

    def test_director_06_concurrent_missing_shot_is_receipt_safe_and_scoped(self):
        p=self.project();requirement=self.requirement(p)
        other=harness.Client(self.base);other.login('test@example.invalid',self.password)
        payload={'projectId':p['id'],'requirementId':requirement['id'],'revision':requirement['revision'],
                 'requestId':'missing-shot:'+uuid.uuid4().hex}
        barrier=threading.Barrier(2)
        def create(client):
            barrier.wait()
            return self.director('requirement.shot',payload,client=client)
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            results=list(pool.map(create,[self.client,other]))
        self.assertEqual(results[0]['shot']['id'],results[1]['shot']['id'])
        self.assertEqual(len(self.call('project',query={'id':p['id']})['shots']),1)
        refreshed=self.overview(p)['requirements'][0]
        again=self.director('requirement.shot',{'projectId':p['id'],'requirementId':refreshed['id'],'revision':refreshed['revision']})
        self.assertFalse(again['created'])
        self.assertEqual(again['shot']['id'],results[0]['shot']['id'])

    def test_director_07_first_cut_prefers_curated_ranges_and_counts_cards(self):
        p,shot,_,_,accepted=self.approved(project=self.project(kind='interview'))
        requirement=self.requirement(p,shot)
        self.selection(p,requirement,accepted,startMs=300,endMs=1000,preferred=True)
        snapshot=self.overview(p)
        proposal=self.director('cut.propose',{'projectId':p['id'],'targetDurationSeconds':0,'fingerprint':snapshot['fingerprint']})
        self.assertEqual(proposal['edit']['settings']['cardMode'],'all')
        self.assertEqual(proposal['durationMs'],3700)
        self.assertEqual(proposal['items'][0]['allowedStartMs'],300)
        self.assertEqual(proposal['items'][0]['allowedEndMs'],1000)
        self.assertEqual(proposal['edit']['segments'][accepted['takeId']]['trimStart'],.3)
        shorter=self.director('cut.propose',{'projectId':p['id'],'targetDurationSeconds':3,'cardDuration':2.5})
        self.assertEqual(shorter['durationMs'],3000)
        self.assertEqual(shorter['items'][0]['endMs'],800)
        self.director('cut.propose',{'projectId':p['id'],'targetDurationSeconds':1},expected=400)
        too_long=self.director('cut.propose',{'projectId':p['id'],'targetDurationSeconds':60})
        self.assertEqual(too_long['durationMs'],3700)
        self.assertTrue(any('requested 60 seconds' in w for w in too_long['warnings']))
        self.director('requirement.save',{'projectId':p['id'],'id':requirement['id'],'revision':requirement['revision'],'title':'New introduction'})
        self.director('cut.propose',{'projectId':p['id'],'fingerprint':snapshot['fingerprint']},expected=409)

    def test_director_08_mixed_import_withdrawal_removes_coverage(self):
        p=self.project(kind='mixed');interview=self.create()
        take,_=self.direct_take(interview['id'],isPrivate='false')
        status,result=self.client.api('updateTakeReview',{'takeId':take['id'],'reviewStatus':'shortlisted'})
        self.assertEqual(status,200,result)
        imported=self.media_call('importSource',{'projectId':p['id'],'takeId':take['id']},expected=201)
        copied={'id':imported['takeId']}
        requirement=self.requirement(p)
        self.selection(p,requirement,{'takeId':copied['id']})
        self.assertEqual(self.overview(p)['coverage']['status'],'ready')
        status,result=self.client.api('updateTakeReview',{'takeId':take['id'],'reviewStatus':'private'})
        self.assertEqual(status,200,result)
        changed=self.overview(p)
        self.assertEqual(changed['coverage']['status'],'incomplete');self.assertFalse(changed['selections'][0]['eligible'])
        self.director('cut.propose',{'projectId':p['id']},expected=409)

    def test_director_09_completed_can_edit_archived_cannot_and_drafts_are_preserved(self):
        p,shot,_,_,accepted=self.approved();requirement=self.requirement(p,shot)
        completed=self.call('saveProject',{'id':p['id'],'revision':p['revision'],'status':'completed'})
        self.assertEqual(self.overview(p)['project']['status'],'completed')
        self.director('cut.propose',{'projectId':p['id']})
        section=self.selection(p,requirement,accepted)
        self.director('selection.delete',{'projectId':p['id'],'id':section['id'],'revision':section['revision']})
        self.director('requirement.save',{'projectId':p['id'],'title':'Closed'},expected=409)
        archived=self.call('saveProject',{'id':p['id'],'revision':completed['revision'],'status':'archived'})
        self.assertEqual(len(self.overview(p)['requirements']),1)
        self.director('cut.propose',{'projectId':p['id']},expected=409)
        self.director('requirement.delete',{'projectId':p['id'],'id':requirement['id'],'revision':requirement['revision']},expected=409)

    def test_director_10_fingerprint_ignores_timeline_but_tracks_editorial_evidence(self):
        p,shot,_,_,accepted=self.approved();self.requirement(p,shot)
        before=self.overview(p)['fingerprint']
        status,interview=self.client.api('interview',query={'id':p['interviewId']})
        self.assertEqual(status,200,interview)
        status,result=self.client.api('updateInterview',{'id':p['interviewId'],'revision':interview['data']['revision'],
                               'state':{'edit':{'order':[accepted['takeId']],'segments':{},'settings':{'cardMode':'none'}}}})
        self.assertEqual(status,200,result)
        self.assertEqual(self.overview(p)['fingerprint'],before)
        self.call('favoriteSubmission',{'submissionId':accepted['id'],'favorite':True})
        favorite=self.overview(p)['fingerprint'];self.assertNotEqual(before,favorite)
        self.call('addSubmissionNote',{'submissionId':accepted['id'],'atMs':400,'text':'Opening smile.'})
        noted=self.overview(p)['fingerprint'];self.assertNotEqual(favorite,noted)
        a=self.call('assignment',query={'id':accepted['assignmentId']})
        self.call('cancelAssignment',{'assignmentId':a['id'],'revision':a['revision'],'note':'Removed from production.'})
        after=self.overview(p)
        self.assertNotEqual(after['fingerprint'],noted);self.assertEqual(after['eligibleTakes'],[])

    def test_director_11_preferred_take_distinct_beats_and_mutation_receipt_after_close(self):
        p=self.project()
        _,shot,_,_,first=self.approved(project=p)
        _,second_shot,_,_,second=self.approved(project=p)
        requirement=self.requirement(p,shot)
        self.director('requirement.save',{'projectId':p['id'],'id':requirement['id'],'revision':requirement['revision'],
                      'shotIds':[shot['id'],second_shot['id']]})
        self.call('favoriteSubmission',{'submissionId':first['id'],'favorite':True})
        self.selection(p,requirement,second,label='Preferred over favorite',startMs=200,endMs=1000,preferred=True)
        proposal=self.director('cut.propose',{'projectId':p['id']})
        self.assertEqual(proposal['edit']['order'],[second['takeId']])
        self.assertEqual(proposal['items'][0]['reason'],'Preferred usable section')
        # The current editor supports one occurrence per take. Reusing a clip
        # for another beat must report missing material rather than duplicate it.
        closing=self.requirement(p,second_shot,title='Closing',purpose='closing',sortOrder=10)
        proposal=self.director('cut.propose',{'projectId':p['id']})
        self.assertEqual(len(proposal['edit']['order']),len(set(proposal['edit']['order'])))
        self.assertTrue(any('Closing:' in warning for warning in proposal['warnings']))
        body={'projectId':p['id'],'title':'Receipt before close','requestId':'close-receipt:'+uuid.uuid4().hex}
        self.director('requirement.save',body)
        self.call('saveProject',{'id':p['id'],'revision':p['revision'],'status':'completed'})
        recovered=self.director('requirement.save',body)
        self.assertEqual(sum(r['title']==body['title'] for r in recovered['requirements']),1)

    def test_director_12_uncurated_unknown_duration_cannot_make_zero_target_ready(self):
        p,shot,_,_,accepted=self.approved()
        self.requirement(p,shot,minimumSelections=1,targetDurationSeconds=0)
        self.assertEqual(self.overview(p)['coverage']['status'],'ready')
        for duration in (0,99):
            with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
                db.execute('UPDATE takes SET duration_ms=? WHERE id=?',[duration,accepted['takeId']])
            snapshot=self.overview(p)
            self.assertEqual(snapshot['selections'],[])
            self.assertEqual(snapshot['requirements'][0]['coverage']['acceptedCount'],0)
            self.assertEqual(snapshot['requirements'][0]['coverage']['status'],'missing')
            self.assertEqual(snapshot['coverage']['status'],'incomplete')
            self.assertEqual(snapshot['candidates'],[])
            self.director('cut.propose',{'projectId':p['id']},expected=409)


if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--php',default='php');args=parser.parse_args()
    DirectorIntegration.php=args.php
    suite=unittest.TestSuite(DirectorIntegration(name) for name in unittest.defaultTestLoader.getTestCaseNames(DirectorIntegration) if name.startswith('test_director_'))
    result=unittest.TextTestRunner(verbosity=2).run(suite)
    raise SystemExit(0 if result.wasSuccessful() else 1)
