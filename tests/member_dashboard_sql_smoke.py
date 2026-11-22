#!/usr/bin/env python3
"""Lightweight SQLite logic check, not a production PHP/MySQL integration test."""
from pathlib import Path
import sqlite3,datetime
src=(Path(__file__).resolve().parents[1]/'includes/member-dashboard.php').read_text()
conn=sqlite3.connect(':memory:')
conn.row_factory=sqlite3.Row
conn.executescript('''
CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT);
CREATE TABLE member_profile_views_daily(user_id INTEGER,view_day TEXT,view_count INTEGER,PRIMARY KEY(user_id,view_day));
CREATE TABLE model_profiles(id TEXT,linked_user_id INTEGER,status TEXT);
CREATE TABLE interviews(id TEXT,owner_id INTEGER,status TEXT);
CREATE TABLE finished_videos(id TEXT,model_id TEXT,interview_id TEXT,fan_published_at TEXT,archived_at TEXT);
CREATE TABLE model_videos(user_id INTEGER,status TEXT);
CREATE TABLE production_projects(id TEXT,title TEXT,kind TEXT,status TEXT,updated_at TEXT,owner_id INTEGER);
CREATE TABLE production_members(project_id TEXT,user_id INTEGER,status TEXT);
CREATE TABLE production_shots(id TEXT,project_id TEXT,title TEXT,status TEXT,mode TEXT,due_at TEXT,max_contributors INTEGER,eligible_roles_json TEXT,sort_order INTEGER);
CREATE TABLE shot_assignments(id TEXT,shot_id TEXT,user_id INTEGER,status TEXT,updated_at TEXT);
CREATE TABLE schedule_appointments(id TEXT,type_name TEXT,start_at TEXT,location TEXT,status TEXT,customer_id INTEGER,professional_id INTEGER);
CREATE TABLE schedule_events(id TEXT,type TEXT,title TEXT,start_at TEXT,location TEXT,status TEXT,instructor_id INTEGER,allowed_roles_json TEXT);
CREATE TABLE schedule_registrations(event_id TEXT,user_id INTEGER,status TEXT);
''')
conn.executemany('INSERT INTO users VALUES(?,?)',[(1,'active'),(2,'active'),(3,'active')])
conn.executemany('INSERT INTO member_profile_views_daily VALUES(?,?,?)',[(1,'2026-11-21',3),(1,'2026-10-03',2),(2,'2026-11-21',27)])
conn.executemany('INSERT INTO model_profiles VALUES(?,?,?)',[('m1',1,'active'),('m2',2,'active')])
conn.executemany('INSERT INTO interviews VALUES(?,?,?)',[('i1',3,'active'),('i2',3,'active')])
conn.executemany('INSERT INTO finished_videos VALUES(?,?,?,?,?)',[
    ('v1','m1','i1','2026-11-21T01:00:00Z',None),('v2','m1','i1','2026-11-21T02:00:00Z',None),
    ('v3','m2','i2','2026-11-21T01:00:00Z',None)])
conn.executemany('INSERT INTO model_videos VALUES(?,?)',[(1,'draft'),(1,'pending_review'),(2,'published')])
conn.executemany('INSERT INTO production_projects VALUES(?,?,?,?,?,?)',[
    ('p1','Model project','interview','active','2026-11-21T03:00:00Z',3),
    ('p2','Other account','film','active','2026-11-21T03:00:00Z',2)])
conn.executemany('INSERT INTO production_members VALUES(?,?,?)',[('p1',1,'active'),('p2',2,'active')])
conn.executemany('INSERT INTO production_shots VALUES(?,?,?,?,?,?,?,?,?)',[
    ('s1','p1','Interview intro','active','assigned','2026-12-01T12:00:00Z',3,'["model"]',1),
    ('s2','p2','Private shot','active','assigned','2026-12-02T12:00:00Z',3,'["model"]',1)])
conn.executemany('INSERT INTO shot_assignments VALUES(?,?,?,?,?)',[
    ('a1','s1',1,'accepted','2026-11-21T03:00:00Z'),('a2','s2',2,'accepted','2026-11-21T03:00:00Z')])
conn.executemany('INSERT INTO schedule_appointments VALUES(?,?,?,?,?,?,?)',[
    ('app1','Model appointment','2026-12-03T12:00:00Z','Studio','confirmed',1,3),
    ('app2','Another member booking','2026-12-03T12:00:00Z','Studio','confirmed',2,3)])
conn.executemany('INSERT INTO schedule_events VALUES(?,?,?,?,?,?,?,?)',[
    ('evt1','class','Model class','2026-12-03T15:00:00Z','Studio','published',3,'["model"]'),
    ('evt2','event','Other private event','2026-12-03T15:00:00Z','Studio','published',3,'["model"]')])
conn.executemany('INSERT INTO schedule_registrations VALUES(?,?,?)',[('evt1',1,'registered'),('evt2',2,'registered')])

def query(text,parameters):
    assert text.strip() in src,'Query is no longer identical to member-dashboard.php:\n'+text
    return conn.execute(text,parameters).fetchall()

views='SELECT COALESCE(SUM(view_count),0) AS total,COALESCE(SUM(CASE WHEN view_day>=? THEN view_count ELSE 0 END),0) AS recent FROM member_profile_views_daily WHERE user_id=?'
assert tuple(query(views,['2026-11-01',1])[0])==(5,3)
assert tuple(query(views,['2026-11-01',2])[0])==(27,27)

pub="""SELECT COUNT(DISTINCT i.id) AS total,MAX(v.fan_published_at) AS latest
        FROM model_profiles mp JOIN finished_videos v ON v.model_id=mp.id
        JOIN interviews i ON i.id=v.interview_id JOIN users u ON u.id=i.owner_id
        WHERE mp.linked_user_id=? AND mp.status='active' AND """
assert pub in src
assert conn.execute(pub+"v.fan_published_at IS NOT NULL AND v.archived_at IS NULL AND i.status = 'active' AND u.status = 'active'",(1,)).fetchone()['total']==1
assert conn.execute(pub+"v.fan_published_at IS NOT NULL AND v.archived_at IS NULL AND i.status = 'active' AND u.status = 'active'",(2,)).fetchone()['total']==1

productions="""SELECT p.id,p.title,p.kind,p.status,p.updated_at FROM production_projects p JOIN users owner ON owner.id=p.owner_id
            WHERE owner.status='active' AND p.status='active' AND (p.owner_id=? OR EXISTS(
              SELECT 1 FROM production_members pm WHERE pm.project_id=p.id AND pm.user_id=? AND pm.status='active'))
            ORDER BY p.updated_at DESC,p.id DESC"""
assert [r['id'] for r in query(productions,[1,1])]==['p1']

assignments="""SELECT a.id,a.status,a.updated_at,s.title AS shot_title,s.due_at,p.title AS project_title
            FROM shot_assignments a JOIN production_shots s ON s.id=a.shot_id JOIN production_projects p ON p.id=s.project_id
            JOIN users owner ON owner.id=p.owner_id
            WHERE a.user_id=? AND p.status='active' AND s.status='active' AND owner.status='active'
              AND a.status IN ('assigned','accepted','submitted','changes_requested')
              AND (p.owner_id=? OR EXISTS(SELECT 1 FROM production_members pm
                  WHERE pm.project_id=p.id AND pm.user_id=? AND pm.status='active'))
            ORDER BY CASE a.status WHEN 'changes_requested' THEN 0 WHEN 'assigned' THEN 1 WHEN 'accepted' THEN 2 ELSE 3 END,
                CASE WHEN s.due_at IS NULL THEN 1 ELSE 0 END,s.due_at,a.updated_at DESC,a.id DESC"""
assert [r['id'] for r in query(assignments,[1,1,1])]==['a1']

bookings="""SELECT a.id,a.type_name,a.start_at,a.location,a.status,
            CASE WHEN a.customer_id=? THEN 'appointment' ELSE 'professional_appointment' END AS kind
        FROM schedule_appointments a WHERE a.start_at>=? AND a.status='confirmed'"""
assert bookings in src
normal=bookings+' AND a.customer_id=? ORDER BY a.start_at,a.id LIMIT 12'
professional=bookings+' AND (a.customer_id=? OR a.professional_id=?) ORDER BY a.start_at,a.id LIMIT 12'
assert [r['id'] for r in conn.execute(normal,(1,'2026-11-21T00:00:00Z',1))]==['app1']
assert [r['id'] for r in conn.execute(professional,(3,'2026-11-21T00:00:00Z',3,3))]==['app1','app2']

events="""SELECT e.*,r.status AS registration_status FROM schedule_events e
        JOIN schedule_registrations r ON r.event_id=e.id AND r.user_id=?
        WHERE e.start_at>=? AND e.status='published' AND r.status IN ('registered','waitlisted')
        ORDER BY e.start_at,e.id LIMIT 24"""
assert [r['id'] for r in query(events,[1,'2026-11-21T00:00:00Z'])]==['evt1']
print('PASS: profile aggregates; distinct published interviews; member-only projects and assignments; customer vs professional bookings; owner-scoped registered events')
