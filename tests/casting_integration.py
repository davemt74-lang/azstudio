#!/usr/bin/env python3
"""Public casting form, editable choices, private CRM and model invite integration checks."""
import json
import re
import sqlite3
import urllib.parse
import unittest
import shutil
import pathlib
import backend_integration as harness

class CastingIntegration(harness.BackendIntegration):
    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        source=pathlib.Path(__file__).resolve().parents[1]
        for name in ['index.php','apply.php','casting.php','model-invite.php','dashboard.php']:
            shutil.copy2(source/name,cls.root/name)
        (cls.root/'assets').mkdir(exist_ok=True)
        for name in ['casting.css','casting-builder.js','hero-angelic-studio.webp','universal-header.css','universal-header.js','landing.css','style.css','dashboard.css','member-layout.css','member-layout.js','favicon.svg']:
            shutil.copy2(source/'assets'/name,cls.root/'assets'/name)

    def csrf(self,client,route):
        status,_,body=client.request(route)
        self.assertEqual(status,200,body[:200]);return re.search(rb'name="csrf" value="([a-f0-9]+)"',body)[1].decode(),body

    def form(self,client,email='model.01@example.invalid',**changes):
        token,_=self.csrf(client,'apply.php')
        fields={'csrf':token,'revision':'1','name':'New AZHottie Model','stageName':'Moonlight','email':email,'city':'Phoenix','social':'@moonlight','phone':'','adultConfirmed':'1','contactConsent':'1',
           'answers[interests][]':['Fashion and swimwear','Comedy sketches and hosting'],
           'answers[experience]':'I regularly create social content',
           'answers[style]':'Playful and mischievous',
           'answers[comfort][]':['Swimwear'],
           'answers[collaboration]':'Both, depending on the project',
           'answers[availability][]':['Weekends'],
           'answers[first-project]':'An Arizona fashion shoot with a comedy sketch!'}
        fields.update(changes)
        return client.form('apply.php',fields)

    def admin_post(self,fields,route='casting.php'):
        data={'csrf':self.client.csrf,**fields}
        return self.client.form(route,data)

    def test_casting_01_public_page_and_separate_private_crm(self):
        anonymous=harness.Client(self.base)
        status,headers,body=anonymous.request('apply.php')
        self.assertEqual(status,200,body[:200]);self.assertIn(b'Private application',body);self.assertIn(b'question',body)
        self.assertNotIn(b'admin_notes',body)
        status,headers,body=anonymous.request('casting.php')
        self.assertEqual(status,303);self.assertEqual(headers.get('Location'),'/login.php')
        for route in ['assets/casting.css','assets/hero-angelic-studio.webp','assets/casting-builder.js','assets/landing.css']:
            self.assertEqual(anonymous.request(route)[0],200,route)
        self.assertEqual(anonymous.request('index.php')[0],200)
        self.assertIn(b'Model casting',anonymous.request('index.php')[2])

    def test_casting_02_public_submission_prevents_validation_bypass_and_duplicate(self):
        anon=harness.Client(self.base)
        bad=self.form(anon,'reject-01@example.invalid',adultConfirmed='0')
        self.assertEqual(bad[0],400)
        invalid=self.form(anon,'reject-02@example.invalid',**{'answers[experience]':'Forged unapproved option'})
        self.assertEqual(invalid[0],400)
        result=self.form(anon)
        self.assertEqual(result[0],200,result[2][:300]);self.assertIn(b'Application received',result[2])
        duplicate=self.form(anon)
        self.assertEqual(duplicate[0],200)
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            leads=db.execute('SELECT id,questions_json,answers_json,status FROM casting_leads WHERE email=?',['model.01@example.invalid']).fetchall()
            self.assertEqual(len(leads),1)
            self.assertEqual(leads[0][3],'new')
            self.assertIn('Fashion and swimwear',leads[0][2])

    def test_casting_03_questions_answers_add_remove_preserve_submission_snapshot(self):
        anon=harness.Client(self.base)
        self.form(anon,'snapshot@example.invalid')
        form=self.client.request('casting.php?tab=questions')
        self.assertEqual(form[0],200);self.assertIn(b'Add question',form[2]);self.assertIn(b'Add answer', (pathlib.Path(__file__).resolve().parents[1]/'assets/casting-builder.js').read_bytes())
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            old=db.execute('SELECT questions_json,answers_json FROM casting_leads WHERE email=?',['snapshot@example.invalid']).fetchone()
        oldquestions=json.loads(old[0]);data=json.loads(self.client.request('casting.php?tab=questions')[2].split(b'id="az-question-editor-data">')[1].split(b'</script>')[0])
        data[0]['options'].append('Brand new choice')
        data[0]['options'].remove('Fitness and product campaigns')
        data.append(dict(id='future_question',title='What is your creative superpower?',type='short',required=False,help='Optional',options=[]))
        status,headers,body=self.admin_post({'action':'save_questions','revision':'1','questions_json':json.dumps(data)},'casting.php?tab=questions')
        self.assertEqual(status,200,body[:200]);self.assertIn(b'questions saved',body)
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            new=json.loads(db.execute('SELECT questions_json FROM casting_questionnaire WHERE id=1').fetchone()[0]);self.assertEqual(len(new),9)
            self.assertEqual(json.loads(db.execute('SELECT questions_json FROM casting_leads WHERE email=?',['snapshot@example.invalid']).fetchone()[0]),oldquestions)
        stale=self.admin_post({'action':'save_questions','revision':'1','questions_json':json.dumps(data)},'casting.php?tab=questions')
        self.assertEqual(stale[0],409)

    def test_casting_04_admin_review_and_invite_create_model_account(self):
        anon=harness.Client(self.base);self.form(anon,'accept-01@example.invalid')
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            lead=db.execute('SELECT id,revision FROM casting_leads WHERE email=?',['accept-01@example.invalid']).fetchone()
        page=self.client.request('casting.php?lead='+lead[0]);self.assertEqual(page[0],200);self.assertIn(b'Moonlight',page[2])
        _,_,body=self.admin_post({'action':'save_lead','id':lead[0],'revision':str(lead[1]),'status':'approved','notes':'Private casting notes only.'},'casting.php?lead='+lead[0])
        self.assertIn(b'Application review saved',body)
        status,_,body=self.admin_post({'action':'invite','id':lead[0]},'casting.php?lead='+lead[0])
        self.assertEqual(status,200,body[:400]);self.assertIn(b'id="casting-invite-url"',body)
        token=re.search(rb'model-invite.php\?token=([a-f0-9]{64})',body)[1].decode()
        invite=harness.Client(self.base)
        csrf,_=self.csrf(invite,'model-invite.php?token='+token)
        status,headers,body=invite.form('model-invite.php?token='+token,{'csrf':csrf,'password':'ModelInvitePass123!'})
        self.assertEqual(status,303,body[:500]);self.assertEqual(headers['Location'],'/dashboard.php')
        boot=invite.bootstrap();self.assertIn('model',boot['user']['roles'])
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            self.assertEqual(db.execute('SELECT status FROM casting_leads WHERE id=?',[lead[0]]).fetchone()[0],'converted')
            self.assertIsNotNone(db.execute('SELECT id FROM model_profiles WHERE linked_user_id=?',[boot['user']['id']]).fetchone())
        self.assertEqual(invite.request('model-invite.php?token='+token)[0],200)
        self.assertIn(b'expired',invite.request('model-invite.php?token='+token)[2])

    def test_casting_05_existing_account_requires_matching_login(self):
        member=harness.Client(self.base)
        token,_=self.csrf(member,'signup.php')
        status,_,body=member.form('signup.php',{'csrf':token,'name':'Casting Member','email':'existingmodel@example.invalid','password':self.password,'roles[]':'customer','adultConfirmed':'1'})
        self.assertEqual(status,303,body[:500])
        leadClient=harness.Client(self.base);self.form(leadClient,'existingmodel@example.invalid')
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:lead=db.execute('SELECT id,revision FROM casting_leads WHERE email=?',['existingmodel@example.invalid']).fetchone()
        self.admin_post({'action':'save_lead','id':lead[0],'revision':str(lead[1]),'status':'approved','notes':''},'casting.php?lead='+lead[0])
        body=self.admin_post({'action':'invite','id':lead[0]},'casting.php?lead='+lead[0])[2]
        token=re.search(rb'model-invite.php\?token=([a-f0-9]{64})',body)[1].decode()
        anon=harness.Client(self.base);csrf,_=self.csrf(anon,'model-invite.php?token='+token)
        self.assertIn(b'Sign in',anon.request('model-invite.php?token='+token)[2])
        wrong=anon.form('model-invite.php?token='+token,{'csrf':csrf})
        self.assertEqual(wrong[0],403)
        csrf,_=self.csrf(member,'model-invite.php?token='+token)
        status,headers,body=member.form('model-invite.php?token='+token,{'csrf':csrf})
        self.assertEqual(status,303,body[:400]);self.assertIn('model',member.bootstrap()['user']['roles'])


def load_tests(loader, tests, pattern):
    return unittest.TestSuite(CastingIntegration(name) for name in loader.getTestCaseNames(CastingIntegration) if name.startswith('test_casting_'))

if __name__=='__main__':unittest.main(verbosity=2)
