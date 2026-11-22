#!/usr/bin/env python3
"""Role, signup, account and model-link HTTP checks in a disposable installation."""
from __future__ import annotations
import argparse
import json
import re
import sqlite3
import urllib.parse
import unittest
import backend_integration as harness


def account_call(client, action, data=None, query=None, csrf=True):
    url = 'accounts-api.php?' + urllib.parse.urlencode({'action': action, **(query or {})})
    headers = {'Accept': 'application/json'}
    payload = None
    if data is not None:
        payload = json.dumps(data).encode()
        headers['Content-Type'] = 'application/json'
        if csrf:
            headers['X-CSRF-Token'] = client.csrf
    status, _, raw = client.request(url, payload, headers)
    try:
        return status, json.loads(raw)
    except ValueError as error:
        raise AssertionError(f'Invalid account JSON ({status}): {raw[:800]!r}') from error


class AccountIntegration(harness.BackendIntegration):
    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.member, cls.member_id = cls.signup_member('member@example.invalid', ['fan', 'customer'])
        cls.model, cls.model_id = cls.signup_member('model@example.invalid', ['model'])
        cls.professional, cls.professional_id = cls.signup_member('professional@example.invalid', ['customer', 'professional'])

    @classmethod
    def signup_member(cls, email, roles):
        client = harness.Client(cls.base)
        status, _, page = client.request('signup.php')
        assert status == 200, page[:500]
        token = re.search(rb'name="csrf" value="([a-f0-9]+)"', page)[1].decode()
        body = urllib.parse.urlencode({'csrf': token, 'name': email.split('@')[0].title(), 'email': email, 'password': cls.password, 'roles[]': roles, 'adultConfirmed': '1'}, doseq=True).encode()
        status, headers, page = client.request('signup.php', body, {'Content-Type':'application/x-www-form-urlencoded'})
        assert status == 303, page[:800]
        assert headers['Location'] == '/calendar.php'
        status, result = account_call(client, 'bootstrap')
        assert status == 200, result
        client.csrf = result['data']['csrf']
        return client, result['data']['user']['id']

    def result(self, action, data=None, query=None, expected=200, client=None, csrf=True):
        status, response = account_call(client or self.client, action, data, query, csrf)
        self.assertEqual(status, expected, response)
        return response['data'] if status < 400 else response

    def account(self, id):
        return self.result('account', query={'id':id})

    def update(self, id, **changes):
        current = self.account(id)
        return self.result('updateAccount', {'id':id,'revision':current['revision'],'roles':current['roles'],'status':current['status'],**changes})

    def member_login(self, email, password):
        client = harness.Client(self.base)
        status, _, page = client.request('login.php')
        self.assertEqual(status,200)
        token = re.search(rb'name="csrf" value="([a-f0-9]+)"', page)[1].decode()
        status, headers, body = client.form('login.php', {'csrf':token,'email':email,'password':password})
        self.assertEqual(status,303,body[:500])
        self.assertEqual(headers['Location'],'/calendar.php')
        boot = self.result('bootstrap',client=client)
        client.csrf = boot['csrf']
        return client

    def test_accounts_01_signup_multiple_roles_pending_and_private_boundary(self):
        member = self.result('bootstrap',client=self.member)
        self.assertEqual(member['user']['roles'],['customer','fan'])
        self.assertEqual(member['user']['requestedRoles'],[])
        model = self.result('bootstrap',client=self.model)
        self.assertEqual(model['user']['roles'],['customer'])
        self.assertEqual(model['user']['requestedRoles'],['model'])
        self.assertFalse(member['canManageAccounts'])
        for action in ['accounts','account','modelProfiles']:
            self.result(action,query={'id':1},client=self.member,expected=403)
        for action in ['bootstrap','profiles','questionnaires','videos']:
            self.assertEqual(self.member.api(action)[0],403)
        self.assertEqual(self.member.request('media.php?id='+'a'*32)[0],403)
        self.assertEqual(self.member.request('phone-api.php?role=host&action=list&interviewId='+'a'*32)[0],403)
        self.assertEqual(self.member.request('accounts.php')[0],403)
        self.assertEqual(self.member.request('account.php')[0],200)

    def test_accounts_02_no_escalation_csrf_and_last_admin(self):
        anonymous = harness.Client(self.base)
        self.assertEqual(account_call(anonymous,'bootstrap')[0],401)
        admin=self.account(1)
        self.result('updateAccount',{'id':1,'revision':admin['revision'],'roles':['customer'],'status':'active'},expected=409)
        self.result('updateAccount',{'id':1,'revision':admin['revision'],'roles':['admin'],'status':'suspended'},expected=409)
        self.result('updateAccount',{'id':self.member_id,'revision':1,'roles':['admin'],'status':'active'},client=self.member,expected=403)
        self.result('requestRoles',{'roles':['admin']},client=self.member,expected=400)
        member=self.result('bootstrap',client=self.member)['user']
        self.result('updateSelf',{'revision':member['revision'],'name':'Blocked'},client=self.member,csrf=False,expected=403)
        saved=self.result('updateSelf',{'revision':member['revision'],'name':'Member Updated','roles':['admin'],'status':'suspended'},client=self.member)
        self.assertEqual(saved['user']['roles'],['customer','fan'])
        self.assertEqual(saved['user']['status'],'active')
        self.result('updateSelf',{'revision':member['revision'],'name':'Stale'},client=self.member,expected=409)

    def test_accounts_03_approve_roles_and_bind_existing_model_safely(self):
        status, saved = self.client.api('saveProfile', {'stageName':'Existing Model','publicBio':'Public draft','privateNotes':'PRIVATE CASTING DETAILS','socialHandles':'@example','interests':'Photos','availability':'Weekends'})
        self.assertEqual(status,200,saved)
        profile_id=saved['data']['id']
        approved=self.update(self.model_id,roles=['customer','model'],modelProfileId=profile_id)
        self.assertEqual(approved['requestedRoles'],[])
        boot=self.result('bootstrap',client=self.model)
        self.assertEqual(boot['user']['roles'],['customer','model'])
        self.assertEqual(boot['modelProfile']['stageName'],'Existing Model')
        self.assertNotIn('privateNotes',boot['modelProfile'])
        self.assertNotIn('photoUrl',boot['modelProfile'])
        self.assertNotIn('PRIVATE CASTING',json.dumps(boot))
        body={'revision':boot['user']['revision'],'name':'Model Account','modelProfile':{**boot['modelProfile'],'stageName':'New Stage Name','privateNotes':'ATTEMPTED REPLACEMENT'}}
        self.result('updateSelf',body,client=self.model)
        admin_profile=self.client.api('profile',query={'id':profile_id})[1]['data']
        self.assertEqual(admin_profile['privateNotes'],'PRIVATE CASTING DETAILS')
        self.assertEqual(admin_profile['stageName'],'New Stage Name')
        current=self.account(self.member_id)
        self.result('updateAccount',{'id':self.member_id,'revision':current['revision'],'roles':['fan','customer','model'],'status':'active','modelProfileId':profile_id},expected=409)
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            self.assertEqual(db.execute('SELECT linked_user_id FROM model_profiles WHERE id=?',[profile_id]).fetchone()[0],self.model_id)

    def test_accounts_04_role_requests_are_reviewed_explicitly(self):
        pro=self.account(self.professional_id)
        self.assertEqual(pro['requestedRoles'],['professional'])
        saved=self.update(self.professional_id,name='Provider Waiting')
        self.assertEqual(saved['requestedRoles'],['professional'])
        saved=self.update(self.professional_id,declineRequested=True)
        self.assertEqual(saved['requestedRoles'],[])
        requested=self.result('requestRoles',{'roles':['professional','model']},client=self.professional)
        self.assertEqual(requested['requestedRoles'],['model','professional'])
        approved=self.update(self.professional_id,roles=['customer','professional'])
        self.assertEqual(approved['roles'],['customer','professional'])
        self.assertEqual(approved['requestedRoles'],['model'])
        boot=self.result('bootstrap',client=self.professional)
        self.assertIn('professional',boot['user']['roles'])

    def test_accounts_05_suspension_invalidates_existing_session(self):
        suspended=self.update(self.member_id,status='suspended')
        self.assertEqual(suspended['status'],'suspended')
        self.result('bootstrap',client=self.member,expected=401)
        client=harness.Client(self.base)
        _,_,page=client.request('login.php')
        token=re.search(rb'name="csrf" value="([a-f0-9]+)"',page)[1].decode()
        self.assertEqual(client.form('login.php',{'csrf':token,'email':'member@example.invalid','password':self.password})[0],401)
        self.update(self.member_id,status='active')
        type(self).member=self.member_login('member@example.invalid',self.password)
        self.assertEqual(self.result('bootstrap',client=self.member)['user']['name'],'Member Updated')

    def test_accounts_06_password_change_reauthenticates_other_sessions(self):
        member, _ = self.signup_member('password@example.invalid',['customer'])
        other=self.member_login('password@example.invalid',self.password)
        self.result('changePassword',{'currentPassword':'wrong','newPassword':'ReplacementPassword42!'},client=member,expected=400)
        changed=self.result('changePassword',{'currentPassword':self.password,'newPassword':'ReplacementPassword42!'},client=member)
        member.csrf=changed['csrf']
        self.assertEqual(self.result('bootstrap',client=member)['user']['email'],'password@example.invalid')
        self.result('bootstrap',client=other,expected=401)
        self.member_login('password@example.invalid','ReplacementPassword42!')

    def test_accounts_07_signup_validation_and_never_defaults_to_admin(self):
        client=harness.Client(self.base)
        _,_,page=client.request('signup.php')
        token=re.search(rb'name="csrf" value="([a-f0-9]+)"',page)[1].decode()
        fields={'csrf':token,'name':'Escalation','email':'escalation@example.invalid','password':self.password,'roles[]':['admin'],'adultConfirmed':'1'}
        status,_,_=client.request('signup.php',urllib.parse.urlencode(fields,doseq=True).encode(),{'Content-Type':'application/x-www-form-urlencoded'})
        self.assertEqual(status,400)
        fields['roles[]']=['customer'];fields['adultConfirmed']='0'
        self.assertEqual(client.request('signup.php',urllib.parse.urlencode(fields,doseq=True).encode(),{'Content-Type':'application/x-www-form-urlencoded'})[0],400)
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            password_hash=db.execute('SELECT password_hash FROM users WHERE id=1').fetchone()[0]
            db.execute('INSERT INTO users (name,email,password_hash,created_at) VALUES (?,?,?,?)',['No role','no-role@example.invalid',password_hash,'2026-01-01T00:00:00Z'])
        no_role=self.member_login('no-role@example.invalid',self.password)
        self.assertEqual(self.result('bootstrap',client=no_role)['user']['roles'],[])
        self.assertEqual(no_role.api('bootstrap')[0],403)

    def test_accounts_08_installer_roles_upgrade_and_link_unlink(self):
        admin=self.account(1)
        self.assertEqual(admin['roles'],['admin'])
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            self.assertGreaterEqual(db.execute('SELECT version FROM studio_schema WHERE id=1').fetchone()[0],4)
        model=self.account(self.model_id)
        self.assertTrue(model['modelProfileLinked'])
        profile_id=model['modelProfileId']
        self.update(self.model_id,roles=['customer'])
        self.assertIsNone(self.result('bootstrap',client=self.model)['modelProfile'])
        profile=self.client.api('profile',query={'id':profile_id})[1]['data']
        self.assertEqual(profile['privateNotes'],'PRIVATE CASTING DETAILS')
        self.assertEqual(profile['stageName'],'New Stage Name')
        self.update(self.model_id,roles=['customer','model'],createModelProfile=True)
        new_profile=self.result('bootstrap',client=self.model)['modelProfile']
        self.assertNotEqual(new_profile['id'],profile_id)
        self.assertEqual(new_profile['stageName'],'Model Account')


def load_tests(loader, tests, pattern):
    return unittest.TestSuite(AccountIntegration(name) for name in loader.getTestCaseNames(AccountIntegration) if name.startswith('test_accounts_'))


if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--php',default='php');args,extra=parser.parse_known_args();AccountIntegration.php=args.php
    unittest.main(argv=[__file__,*extra],verbosity=2)
