#!/usr/bin/env python3
"""SQLite contract fixture for published-only, model-scoped engagement queries.
This does not replace a PHP PDO / hosted MySQL integration test.
"""
import sqlite3
from pathlib import Path
source=(Path(__file__).resolve().parents[1]/'includes/member-engagement.php').read_text()
conn=sqlite3.connect(':memory:'); conn.row_factory=sqlite3.Row
conn.executescript('''
CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT);
CREATE TABLE user_roles(user_id INTEGER,role TEXT);
CREATE TABLE model_profiles(id TEXT,linked_user_id INTEGER,status TEXT);
CREATE TABLE interviews(id TEXT,owner_id INTEGER,status TEXT);
CREATE TABLE finished_videos(id TEXT,model_id TEXT,interview_id TEXT,owner_id INTEGER,fan_published_at TEXT,archived_at TEXT);
CREATE TABLE model_videos(id TEXT,user_id INTEGER,status TEXT,published_at TEXT,rights_confirmed INTEGER);
CREATE TABLE member_profile_views_daily(user_id INTEGER,view_day TEXT,view_count INTEGER);
CREATE TABLE published_video_engagement_daily(video_kind TEXT,video_id TEXT,view_day TEXT,play_starts INTEGER,player_completions INTEGER,watched_seconds INTEGER,PRIMARY KEY(video_kind,video_id,view_day));
''')
conn.executemany('INSERT INTO users VALUES(?,?)',[(1,'active'),(2,'active'),(3,'active'),(4,'inactive')]);conn.executemany('INSERT INTO user_roles VALUES(?,?)',[(1,'model'),(2,'model'),(4,'model')]);
conn.executemany('INSERT INTO model_profiles VALUES(?,?,?)',[('m1',1,'active'),('m2',2,'active'),('m4',4,'active')]);
conn.executemany('INSERT INTO interviews VALUES(?,?,?)',[('i1',3,'active'),('i2',3,'active'),('i3',3,'archived')]);
conn.executemany('INSERT INTO finished_videos VALUES(?,?,?,?,?,?)',[('p1','m1','i1',3,'2026-11-21',None),('p2','m2','i2',3,'2026-11-21',None),('p3','m1','i3',3,'2026-11-21',None),('p4','m1','i1',3,None,None)]);
conn.executemany('INSERT INTO model_videos VALUES(?,?,?,?,?)',[('c1',1,'published','2026-11-21',1),('c2',2,'published','2026-11-21',1),('c3',1,'published','2026-11-21',0),('c4',1,'draft',None,0),('c5',4,'published','2026-11-21',1)]);
conn.executemany('INSERT INTO published_video_engagement_daily VALUES(?,?,?,?,?,?)',[
('creator','c1','2026-11-21',10,4,600),('creator','c2','2026-11-21',70,30,4000),('creator','c3','2026-11-21',55,25,2000),
('creator','c4','2026-11-21',100,50,3000),('creator','c5','2026-11-21',300,100,5000),
('production','p1','2026-11-21',15,7,900),('production','p2','2026-11-21',140,40,2500),('production','p3','2026-11-21',200,80,7000),('production','p4','2026-11-21',160,40,7000)])
conn.executemany('INSERT INTO member_profile_views_daily VALUES(?,?,?)',[(1,'2026-11-21',11),(2,'2026-11-21',42)])
creators="""SELECT d.view_day,d.play_starts,d.player_completions,d.watched_seconds
        FROM published_video_engagement_daily d
        JOIN model_videos p ON p.id=d.video_id AND d.video_kind='creator'
        JOIN users u ON u.id=p.user_id
        WHERE p.user_id=? AND d.view_day>=? AND p.status='published' AND p.published_at IS NOT NULL
          AND p.rights_confirmed=1 AND u.status='active' AND EXISTS(
            SELECT 1 FROM user_roles ur WHERE ur.user_id=p.user_id AND ur.role='model')"""
interviews="""SELECT d.view_day,d.play_starts,d.player_completions,d.watched_seconds
        FROM published_video_engagement_daily d
        JOIN finished_videos v ON v.id=d.video_id AND d.video_kind='production'
        JOIN model_profiles mp ON mp.id=v.model_id
        JOIN interviews i ON i.id=v.interview_id
        JOIN users u ON u.id=v.owner_id
        WHERE mp.linked_user_id=? AND mp.status='active' AND d.view_day>=?
          AND v.fan_published_at IS NOT NULL AND v.archived_at IS NULL AND i.status = 'active' AND u.status = 'active'"""
assert creators in source
assert "JOIN finished_videos v ON v.id=d.video_id AND d.video_kind='production'" in source
assert 'AND ".az_public_video_conditions()' in source
for user,expected in [(1,(25,11,1500)),(2,(210,70,6500)),(4,(0,0,0))]:
    rows=list(conn.execute(creators,(user,'2026-10-01')))+list(conn.execute(interviews,(user,'2026-10-01')))
    actual=tuple(sum(r[field] for r in rows) for field in ('play_starts','player_completions','watched_seconds'))
    assert actual==expected,(user,actual,expected)
# Confirm source samples stay empty for withdrawn, private and foreign accounts.
conn.execute("UPDATE model_videos SET status='draft',published_at=NULL WHERE id='c1'")
conn.execute("UPDATE finished_videos SET archived_at='2026-11-21' WHERE id='p1'")
assert not list(conn.execute(creators,(1,'2026-10-01')))
assert not list(conn.execute(interviews,(1,'2026-10-01')))
# Confirm daily metrics are atomic sums on SQLite (MySQL variant uses ON DUPLICATE KEY).
stmt='''INSERT INTO published_video_engagement_daily(video_kind,video_id,view_day,play_starts,player_completions,watched_seconds) VALUES(?,?,?,?,?,?) ON CONFLICT(video_kind,video_id,view_day) DO UPDATE SET play_starts=play_starts+excluded.play_starts,player_completions=player_completions+excluded.player_completions,watched_seconds=watched_seconds+excluded.watched_seconds'''
assert stmt in source
conn.execute(stmt,('creator','c2','2026-11-21',1,0,15))
assert tuple(conn.execute("SELECT play_starts,player_completions,watched_seconds FROM published_video_engagement_daily WHERE video_id='c2'").fetchone())==(71,30,4015)
print('PASS: owner+model credited analytics, publication and consent gates, inactive account exclusions, withdrawn videos, atomic counters')
