#!/usr/bin/env python3
"""Test actual v1.29 migration DDL in SQLite: FK ownership, replay, private schedule, promotion gating."""
from pathlib import Path
import re, sqlite3
src=(Path(__file__).resolve().parents[1]/'includes/agent-publishing.php').read_text()
match=re.findall(r'\$db->exec\("CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\)\$suffix"\)',src,re.S)
assert len(match)==2, [x[0] for x in match]
db=sqlite3.connect(':memory:');db.execute('PRAGMA foreign_keys=ON')
db.executescript('''CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT);CREATE TABLE model_videos(id TEXT PRIMARY KEY,user_id INTEGER NOT NULL,status TEXT,published_at TEXT,rights_confirmed INTEGER,revision INTEGER,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE);CREATE TABLE user_roles(user_id INTEGER,role TEXT);''')
for name,ddl in match:
    db.execute('CREATE TABLE '+name+' ('+ddl.replace('$uid','INTEGER')+')')
db.execute('CREATE INDEX publishing_due ON publishing_agent_schedules(state,publish_at)')
db.execute('CREATE INDEX publishing_features ON publishing_agent_promotions(state,featured_at)')
db.execute("INSERT INTO users VALUES(1,'active'),(2,'active'),(3,'active')")
db.execute("INSERT INTO user_roles VALUES(1,'model'),(2,'model'),(3,'admin')")
a,b='a'*32,'b'*32;db.execute('INSERT INTO model_videos VALUES(?,?,?,?,?,?)',(a,1,'scheduled',None,1,2));db.execute('INSERT INTO model_videos VALUES(?,?,?,?,?,?)',(b,2,'published','2026-11-22',1,2))
db.execute('INSERT INTO publishing_agent_schedules VALUES(?,?,?,?,?,?,?,?,?)',(a,1,3,2,'2026-11-23T10:00:00Z','scheduled','now','now',None))
try:
    db.execute('INSERT INTO publishing_agent_schedules VALUES(?,?,?,?,?,?,?,?,?)',(a,1,3,2,'2026-11-23T10:00:00Z','scheduled','now','now',None));raise AssertionError('duplicate schedule')
except sqlite3.IntegrityError:pass
promo='c'*32;db.execute('INSERT INTO publishing_agent_promotions VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',(promo,b,2,3,2,'onsite','draft','A story','Copy',None,'now','now'))
public='''SELECT p.headline FROM publishing_agent_promotions p JOIN model_videos v ON v.id=p.video_id JOIN users u ON u.id=v.user_id AND u.status='active' WHERE p.channel='onsite' AND p.state='featured' AND v.status='published' AND v.published_at IS NOT NULL AND v.rights_confirmed=1 AND p.video_revision=v.revision AND EXISTS(SELECT 1 FROM user_roles r WHERE r.user_id=v.user_id AND r.role='model')'''
assert db.execute(public).fetchall()==[]
db.execute("UPDATE publishing_agent_promotions SET state='featured' WHERE id=?",(promo,))
assert db.execute(public).fetchall()==[('A story',)]
db.execute('UPDATE model_videos SET revision=revision+1 WHERE id=?',(b,))
assert db.execute(public).fetchall()==[], 'stale featured copy cannot resurface after revision change'
db.execute('UPDATE model_videos SET revision=2 WHERE id=?',(b,))
db.execute("UPDATE model_videos SET status='draft' WHERE id=?",(b,))
assert db.execute(public).fetchall()==[]
assert db.execute('SELECT id FROM publishing_agent_promotions WHERE user_id=1').fetchall()==[]
assert db.execute('SELECT id FROM publishing_agent_promotions WHERE user_id=2').fetchall()==[(promo,)]
db.execute('DELETE FROM users WHERE id=2')
assert db.execute('SELECT COUNT(*) FROM publishing_agent_promotions').fetchone()[0]==0
assert db.execute('SELECT COUNT(*) FROM publishing_agent_schedules').fetchone()[0]==1
db.execute('DELETE FROM users WHERE id=1')
assert db.execute('SELECT COUNT(*) FROM publishing_agent_schedules').fetchone()[0]==0
print('Publishing specialist SQLite schema/permission/approval/cascade fixture PASS')
