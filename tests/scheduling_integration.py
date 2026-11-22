#!/usr/bin/env python3
"""Actual multi-worker HTTP checks for private calendars, attendance and booking."""
from __future__ import annotations
import argparse, concurrent.futures, datetime as dt, json, os, pathlib, re, shutil, signal, sqlite3, subprocess, threading, time, unittest, urllib.parse, urllib.request, urllib.error
import backend_integration as harness

class SchedulingIntegration(harness.BackendIntegration):
    member_sequence=0
    @classmethod
    def start_server(cls):
        env=dict(os.environ);env['PHP_CLI_SERVER_WORKERS']='4'
        cls.server=subprocess.Popen([cls.php,'-d','session.save_path='+str(cls.root/'sessions'),'-d','display_errors=0','-S',f'127.0.0.1:{cls.port}','-t',str(cls.root)],stdout=cls.log,stderr=cls.log,env=env,start_new_session=True)
        for _ in range(100):
            try:
                with urllib.request.urlopen(cls.base+'/index.php',timeout=.3):return
            except (OSError,urllib.error.URLError):
                if cls.server.poll() is not None:raise AssertionError('Multi-worker test server failed')
                time.sleep(.03)
        raise AssertionError('Test server not ready')
    @classmethod
    def stop_server(cls):
        if cls.server and cls.server.poll() is None:
            os.killpg(cls.server.pid,signal.SIGTERM)
            try:cls.server.wait(timeout=5)
            except subprocess.TimeoutExpired:os.killpg(cls.server.pid,signal.SIGKILL);cls.server.wait(timeout=5)
    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        source=pathlib.Path(__file__).resolve().parents[1]
        shutil.copy2(source/'schedule-api.php',cls.root/'schedule-api.php')
    def call(self,action,data=None,query=None,client=None,expected=200,csrf=True):
        c=client or self.client;route='schedule-api.php?'+urllib.parse.urlencode({'action':action,**(query or {})});headers={'Accept':'application/json'};payload=None
        if data is not None:
            payload=json.dumps(data).encode();headers['Content-Type']='application/json'
            if csrf:headers['X-CSRF-Token']=c.csrf
        status,_,body=c.request(route,payload,headers)
        try:result=json.loads(body)
        except ValueError:raise AssertionError(f'{status}: {body[:600]!r}')
        self.assertEqual(status,expected,result)
        return result.get('data',result)
    def member(self,*roles,status='active'):
        type(self).member_sequence+=1;n=type(self).member_sequence
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            pw=db.execute('SELECT password_hash FROM users WHERE id=1').fetchone()[0]
            cur=db.execute('INSERT INTO users(name,email,password_hash,created_at,status) VALUES(?,?,?,?,?)',[f'Member {n}',f'member-{n}@example.invalid',pw,'2026-01-01T00:00:00Z',status]);uid=cur.lastrowid
            for role in roles:db.execute('INSERT INTO user_roles(user_id,role) VALUES(?,?)',[uid,role])
        c=harness.Client(self.base);s,_,page=c.request('login.php');self.assertEqual(s,200);token=re.search(rb'name="csrf" value="([a-f0-9]+)"',page)[1].decode()
        s,_,body=c.form('login.php',{'csrf':token,'email':f'member-{n}@example.invalid','password':self.password});self.assertEqual(s,303,body)
        boot=self.call('bootstrap',client=c);c.csrf=boot['csrf'];return uid,c
    def local(self,hour=10,days=10,minute=0):
        # Tests use Arizona calendar dates and validate that API storage is UTC.
        date=(dt.datetime.now(dt.timezone(dt.timedelta(hours=-7))).date()+dt.timedelta(days=days))
        return f'{date.isoformat()}T{hour:02d}:{minute:02d}'
    def event(self,**extra):
        return self.call('saveEvent',{'title':'Studio event','description':'Members only','startLocal':self.local(10),'endLocal':self.local(11),'location':'Studio A','minCapacity':1,'maxCapacity':2,'allowedRoles':['model','fan','customer','professional'],'status':'published',**extra})
    def professional(self):
        pid,client=self.member('professional');self.call('saveAvailability',{'professionalId':pid,'weekly':[{'weekday':d,'start':'09:00','end':'18:00'} for d in range(7)]},client=client);return pid,client
    def appointment_type(self,pid,client,**extra):
        return self.call('saveAppointmentType',{'professionalId':pid,'name':'Studio appointment','description':'A personal session','durationMinutes':60,'bufferBeforeMinutes':15,'bufferAfterMinutes':15,'location':'Studio B','minLeadHours':0,'maxAdvanceDays':90,'slotStepMinutes':15,'status':'active',**extra},client=client)
    def slot(self,typ,client,hour=10,days=10,minute=0,**extra):
        local=self.local(hour,days,minute);slots=self.call('slots',query={'typeId':typ['id'],'date':local[:10],**extra},client=client)['slots'];matches=[x for x in slots if x['startLocal']==local];self.assertTrue(matches,(local,slots));return matches[0]
    def test_schedule_01_login_roles_and_minimal_visibility(self):
        anonymous=harness.Client(self.base);self.call('events',client=anonymous,expected=401)
        model,m=self.member('model');fan,f=self.member('fan');p,pc=self.professional()
        boot=self.call('bootstrap',client=m);self.assertEqual(boot['timezone'],'America/Phoenix');self.assertTrue(all(set(row)=={'id','name'} for row in boot['professionals']))
        event=self.event(allowedRoles=['model']);self.assertEqual(event['startAt'][11:16],'17:00')
        self.assertIn(event['id'],[x['id'] for x in self.call('events',client=m)])
        self.assertNotIn(event['id'],[x['id'] for x in self.call('events',client=f)])
        self.call('event',query={'id':event['id']},client=f,expected=404)
        self.call('roster',query={'eventId':event['id']},client=m,expected=403)
        self.call('saveEvent',{'title':'Forbidden'},client=m,expected=403)
        self.call('registerEvent',{'eventId':event['id']},client=m,csrf=False,expected=403)
        self.assertEqual(m.api('bootstrap')[0],403)
        draft=self.event(title='Unpublished',status='draft');self.assertNotIn(draft['id'],[x['id'] for x in self.call('events',client=m)])
    def test_schedule_02_capacity_waitlist_minimum_and_attendance(self):
        a,ca=self.member('model');b,cb=self.member('fan');c,cc=self.member('customer');p,cp=self.professional()
        event=self.event(instructorId=p,minCapacity=2,maxCapacity=2)
        self.assertFalse(event['minimumMet']);self.assertEqual(event['minimumRemaining'],2)
        first=self.call('registerEvent',{'eventId':event['id']},client=ca);self.assertEqual(first['myRegistration']['status'],'registered');self.assertFalse(first['minimumMet'])
        second=self.call('registerEvent',{'eventId':event['id']},client=cb);self.assertTrue(second['minimumMet'])
        waiting=self.call('registerEvent',{'eventId':event['id']},client=cc);self.assertEqual(waiting['myRegistration']['status'],'waitlisted');self.assertEqual(waiting['registeredCount'],2)
        self.call('registerEvent',{'eventId':event['id']},client=cc);self.assertEqual(len(self.call('roster',query={'eventId':event['id']})['attendees']),3)
        self.call('saveEvent',{'id':event['id'],'revision':1,'maxCapacity':1,'minCapacity':1},expected=409)
        self.call('saveEvent',{'id':event['id'],'revision':1,'startLocal':self.local(12),'endLocal':self.local(13)},expected=409)
        self.call('cancelRegistration',{'eventId':event['id']},client=ca)
        self.assertEqual(self.call('event',query={'id':event['id']},client=cc)['myRegistration']['status'],'registered')
        roster=self.call('setAttendance',{'eventId':event['id'],'userId':c,'attendance':'present'},client=cp)
        self.assertEqual(next(x for x in roster['attendees'] if x['userId']==c)['attendance'],'present')
        self.call('setAttendance',{'eventId':event['id'],'userId':c,'attendance':'absent'},client=cc,expected=403)
        cancelled=self.call('saveEvent',{'id':event['id'],'revision':1,'status':'cancelled'});self.assertEqual(cancelled['registeredCount'],0)
        self.call('registerEvent',{'eventId':event['id']},client=ca,expected=409)
    def test_schedule_03_class_templates_sessions_and_atomic_batches(self):
        p,pc=self.professional();m,mc=self.member('model')
        cls=self.call('saveClass',{'title':'Camera presence','description':'A class','instructorId':p,'durationMinutes':60,'minCapacity':2,'maxCapacity':8,'allowedRoles':['model'],'location':'Studio','status':'active'})
        sessions=self.call('saveClassSessions',{'classId':cls['id'],'startLocal':self.local(10,20),'count':3,'intervalDays':7,'status':'published'})
        self.assertEqual(len(sessions),3);self.assertTrue(all(x['type']=='class' and x['classId']==cls['id'] for x in sessions))
        updated=self.call('saveClass',{'id':cls['id'],'revision':1,'title':'New class name','durationMinutes':90})
        original=self.call('event',query={'id':sessions[0]['id']},client=mc);self.assertEqual(original['title'],'Camera presence');self.assertEqual(original['endLocal'][-5:],'11:00')
        self.call('registerEvent',{'eventId':sessions[0]['id']},client=mc)
        self.call('setAttendance',{'eventId':sessions[0]['id'],'userId':m,'attendance':'excused'},client=pc)
        before=len(self.call('events',query={'classId':cls['id']}))
        # First generated session is free; second collides with an existing one. Neither may remain.
        self.call('saveClassSessions',{'classId':cls['id'],'startLocal':self.local(10,13),'count':2,'intervalDays':7,'status':'published'},expected=409)
        self.assertEqual(len(self.call('events',query={'classId':cls['id']})),before)
        self.call('saveClass',{'id':cls['id'],'revision':1,'title':'Stale'},expected=409)
        self.call('saveClass',{'id':cls['id'],'revision':updated['revision'],'status':'archived'})
        self.call('saveClassSessions',{'classId':cls['id'],'startLocal':self.local(10,40)},expected=409)
    def test_schedule_04_appointments_hours_buffers_and_member_booking(self):
        p,pc=self.professional();m,mc=self.member('model');f,fc=self.member('fan');typ=self.appointment_type(p,pc)
        slot=self.slot(typ,mc);booking=self.call('bookAppointment',{'typeId':typ['id'],'startAt':slot['startAt'],'notes':'Private booking note'},client=mc)
        self.assertEqual(booking['status'],'confirmed');self.assertEqual(booking['customerId'],m)
        duplicate=self.call('bookAppointment',{'typeId':typ['id'],'startAt':slot['startAt']},client=mc);self.assertEqual(booking['id'],duplicate['id'])
        self.call('bookAppointment',{'typeId':typ['id'],'startAt':slot['startAt']},client=fc,expected=409)
        other=self.appointment_type(p,pc,name='Another service',durationMinutes=30,bufferBeforeMinutes=0,bufferAfterMinutes=0)
        starts={s['startLocal'][-5:] for s in self.call('slots',query={'typeId':other['id'],'date':slot['startLocal'][:10]},client=fc)['slots']}
        self.assertNotIn('09:30',starts);self.assertNotIn('11:00',starts);self.assertIn('11:15',starts)
        self.assertEqual(self.call('appointments',client=fc),[])
        self.call('appointment',query={'id':booking['id']},client=fc,expected=404)
        self.call('availability',query={'professionalId':p},client=mc,expected=403)
        self.call('saveAvailability',{'professionalId':p,'weekly':[]},client=pc,expected=409)
        self.call('saveBlackout',{'professionalId':p,'startLocal':self.local(9,10,45),'endLocal':self.local(10,10,15),'reason':'Break'},client=pc,expected=409)
        self.call('saveEvent',{'title':'Conflicting assigned class','startLocal':self.local(10),'endLocal':self.local(11),'instructorId':p,'status':'published'},expected=409)
        outsider=self.event(startLocal=self.local(10),endLocal=self.local(11))
        self.call('registerEvent',{'eventId':outsider['id']},client=mc,expected=409)
        self.call('updateAppointmentStatus',{'id':booking['id'],'status':'completed'},client=mc,expected=403)
        self.call('updateAppointmentStatus',{'id':booking['id'],'status':'completed'},client=pc,expected=409)
    def test_schedule_05_reschedule_cancel_blackouts_and_snapshots(self):
        p,pc=self.professional();m,mc=self.member('customer');typ=self.appointment_type(p,pc);slot=self.slot(typ,mc)
        booking=self.call('bookAppointment',{'typeId':typ['id'],'startAt':slot['startAt']},client=mc)
        near=self.call('slots',query={'typeId':typ['id'],'date':slot['startLocal'][:10],'appointmentId':booking['id']},client=pc)['slots'];self.assertIn(slot['startAt'],[s['startAt'] for s in near])
        changed=self.call('saveAppointmentType',{'id':typ['id'],'revision':1,'professionalId':p,'durationMinutes':90,'location':'New room'},client=pc)
        replacement=next(s for s in near if s['startLocal']==self.local(12))
        moved=self.call('rescheduleAppointment',{'id':booking['id'],'startAt':replacement['startAt']},client=pc)
        self.assertEqual(moved['durationMinutes'],60);self.assertEqual(moved['location'],'Studio B');self.assertEqual(moved['endLocal'],self.local(13))
        block=self.call('saveBlackout',{'professionalId':p,'startLocal':self.local(15),'endLocal':self.local(17),'reason':'Unavailable'},client=pc)
        available=self.call('slots',query={'typeId':typ['id'],'date':slot['startLocal'][:10]},client=mc)['slots'];self.assertNotIn(self.local(15),[s['startLocal'] for s in available])
        self.call('deleteBlackout',{'id':block['id']},client=mc,expected=403)
        self.call('deleteBlackout',{'id':block['id']},client=pc)
        cancelled=self.call('cancelAppointment',{'id':booking['id']},client=mc);self.assertEqual(cancelled['status'],'cancelled')
        self.assertEqual(self.call('cancelAppointment',{'id':booking['id']},client=mc)['status'],'cancelled')
        self.call('rescheduleAppointment',{'id':booking['id'],'startAt':replacement['startAt']},client=mc,expected=409)
        self.call('saveAppointmentType',{'id':typ['id'],'revision':changed['revision'],'professionalId':p,'status':'archived'},client=pc)
        self.assertNotIn(typ['id'],[x['id'] for x in self.call('appointmentTypes',client=mc)])
        self.call('bookAppointment',{'typeId':typ['id'],'startAt':slot['startAt']},client=mc,expected=409)
    def test_schedule_06_role_loss_suspension_and_multirole_conflicts(self):
        p,pc=self.professional();p2,pc2=self.professional();m,mc=self.member('model');typ=self.appointment_type(p,pc);typ2=self.appointment_type(p2,pc2)
        slot=self.slot(typ2,pc);self.call('bookAppointment',{'typeId':typ2['id'],'startAt':slot['startAt']},client=pc)
        # A professional who attends another appointment cannot accept a client at that time.
        self.call('bookAppointment',{'typeId':typ['id'],'startAt':slot['startAt']},client=mc,expected=409)
        future_event=self.event(instructorId=p,startLocal=self.local(10,16),endLocal=self.local(11,16))
        future_class=self.call('saveClass',{'title':'Former instructor class','instructorId':p,'status':'active'})
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:db.execute('DELETE FROM user_roles WHERE user_id=? AND role=?',[p,'professional'])
        self.call('slots',query={'typeId':typ['id'],'date':self.local()[:10]},client=mc,expected=409)
        self.assertNotIn(p,[x['id'] for x in self.call('bootstrap',client=mc)['professionals']])
        # Removing a provider must not strand administrator cancellation/archive controls.
        self.assertEqual(self.call('saveEvent',{'id':future_event['id'],'revision':1,'instructorId':p,'status':'cancelled'})['status'],'cancelled')
        self.assertEqual(self.call('saveClass',{'id':future_class['id'],'revision':1,'instructorId':p,'status':'archived'})['status'],'archived')
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:db.execute("UPDATE users SET status='suspended' WHERE id=?",[m])
        self.call('events',client=mc,expected=401)
    def test_schedule_07_concurrent_last_seat_and_booking(self):
        a,ca=self.member('model');b,cb=self.member('fan');event=self.event(maxCapacity=1,minCapacity=1)
        barrier=threading.Barrier(2)
        def register(client):
            barrier.wait();return self.call('registerEvent',{'eventId':event['id']},client=client)['myRegistration']['status']
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:statuses=list(pool.map(register,[ca,cb]))
        self.assertEqual(sorted(statuses),['registered','waitlisted']);self.assertEqual(self.call('event',query={'id':event['id']})['registeredCount'],1)
        p,pc=self.professional();typ=self.appointment_type(p,pc);c,cc=self.member('customer');d,cd=self.member('customer');slot=self.slot(typ,cc,days=11)
        barrier=threading.Barrier(2)
        def book(client):
            barrier.wait();payload=json.dumps({'typeId':typ['id'],'startAt':slot['startAt']}).encode();s,_,body=client.request('schedule-api.php?action=bookAppointment',payload,{'Content-Type':'application/json','X-CSRF-Token':client.csrf});return s,json.loads(body)
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:results=list(pool.map(book,[cc,cd]))
        self.assertEqual(sorted(x[0] for x in results),[200,409],results)
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:self.assertEqual(db.execute("SELECT COUNT(*) FROM schedule_appointments WHERE professional_id=? AND status='confirmed'",[p]).fetchone()[0],1)
    def test_schedule_08_time_validation_and_migration_repeatability(self):
        self.call('saveEvent',{'title':'Bad date','startLocal':'2026-02-30T10:00','endLocal':'2026-03-01T11:00'},expected=400)
        self.call('saveEvent',{'title':'Backwards','startLocal':self.local(11),'endLocal':self.local(10)},expected=400)
        self.call('saveEvent',{'title':'Bad minimum','startLocal':self.local(10),'endLocal':self.local(11),'minCapacity':5,'maxCapacity':2},expected=400)
        p,pc=self.professional();self.call('saveAvailability',{'professionalId':p,'weekly':[{'weekday':1,'start':'10:00','end':'12:00'},{'weekday':1,'start':'11:00','end':'13:00'}]},client=pc,expected=400)
        event=self.event();self.call('events');self.call('events')
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            before=db.execute('SELECT COUNT(*) FROM schedule_events').fetchone()[0]
            db.execute('UPDATE studio_schema SET version=4 WHERE id=1')
        self.call('events')
        with sqlite3.connect(self.root/'storage/interviews.sqlite') as db:
            self.assertGreaterEqual(db.execute('SELECT version FROM studio_schema').fetchone()[0],5);self.assertEqual(db.execute('SELECT COUNT(*) FROM schedule_events').fetchone()[0],before)
        self.assertEqual(self.call('event',query={'id':event['id']})['title'],event['title'])

def load_tests(loader,tests,pattern):return unittest.TestSuite(SchedulingIntegration(name) for name in loader.getTestCaseNames(SchedulingIntegration) if name.startswith('test_schedule_'))
if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--php',default='php');args,remaining=p.parse_known_args();SchedulingIntegration.php=args.php;unittest.main(argv=[__file__,*remaining],verbosity=2)
