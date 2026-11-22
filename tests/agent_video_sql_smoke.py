#!/usr/bin/env python3
"""Validate exact v1.28 SQLite migration SQL, scoped reads, dedup, and FK cascade."""
import re, sqlite3
from pathlib import Path
src=(Path(__file__).resolve().parents[1]/'includes/agent-video.php').read_text()
patterns=re.findall(r'\$db->exec\("CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\)\$suffix"\)',src,re.S)
assert len(patterns)==3,[x[0] for x in patterns]
db=sqlite3.connect(':memory:');db.execute('PRAGMA foreign_keys=ON')
db.executescript('''
CREATE TABLE users(id INTEGER PRIMARY KEY, name TEXT, status TEXT);
CREATE TABLE personal_media(id TEXT PRIMARY KEY,user_id INTEGER,duration_ms INTEGER,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE);
CREATE TABLE personal_edit_projects(id TEXT PRIMARY KEY,user_id INTEGER,timeline_json TEXT,revision INTEGER,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE);
''')
for name,ddl in patterns:
    sql=f'CREATE TABLE IF NOT EXISTS {name} ({ddl.replace("$uid","INTEGER").replace("$long","TEXT")})'
    db.execute(sql);db.execute(sql)
db.executemany('INSERT INTO users VALUES(?,?,?)',[(1,'Owner A','active'),(2,'Owner B','active')])
a,b,c,d,e,f=('a'*32,'b'*32,'c'*32,'d'*32,'e'*32,'f'*32)
db.executemany('INSERT INTO personal_media VALUES(?,?,?)',[(a,1,20000),(b,2,30000)])
db.executemany('INSERT INTO personal_edit_projects VALUES(?,?,?,?)',[(c,1,'{}',2),(d,2,'{}',1)])
db.execute('INSERT INTO video_agent_moments VALUES(?,?,?,?,?,?,?,?)',(e,1,a,1000,4000,'highlight','Owner A private note','2026-11-22T00:00:00Z'))
db.execute('INSERT INTO video_agent_moments VALUES(?,?,?,?,?,?,?,?)',(f,2,b,1000,3000,'caption','Owner B private note','2026-11-22T00:00:00Z'))
assert db.execute('SELECT note FROM video_agent_moments WHERE user_id=?',(1,)).fetchall()==[('Owner A private note',)]
assert db.execute('SELECT note FROM video_agent_moments WHERE user_id=?',(2,)).fetchall()==[('Owner B private note',)]
g,h='1'*32,'2'*32
rows=[(g,1,c,2,'0'*64,'{}','{}','highlight',30,'proposed',None,'2026-11-22T00:00:00Z','2026-11-22T00:00:00Z'),(h,2,d,1,'1'*64,'{}','{}','teaser',15,'proposed',None,'2026-11-22T00:00:00Z','2026-11-22T00:00:00Z')]
db.executemany('INSERT INTO video_agent_plans VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',rows)
assert db.execute('SELECT id FROM video_agent_plans WHERE id=? AND user_id=?',(g,2)).fetchone() is None
assert db.execute('SELECT id FROM video_agent_plans WHERE id=? AND user_id=?',(h,1)).fetchone() is None
assert db.execute('SELECT COUNT(*) FROM video_agent_plans WHERE user_id=? AND status=?',(1,'proposed')).fetchone()[0]==1
db.execute('INSERT INTO video_agent_deliveries VALUES(?,?,?,?,?,?)',('3'*32,1,g,'review_48h','z'*64,'2026-11-22T00:00:00Z'))
try:
    db.execute('INSERT INTO video_agent_deliveries VALUES(?,?,?,?,?,?)',('4'*32,1,g,'review_48h','z'*64,'2026-11-22T00:00:00Z'))
    raise AssertionError('duplicate notification accepted')
except sqlite3.IntegrityError:pass
# Cross-owner plan reference cannot be used by the application's owner-scoped SQL.
assert db.execute('SELECT * FROM video_agent_plans WHERE id=? AND user_id=?',(g,2)).fetchone() is None
db.execute('DELETE FROM users WHERE id=1')
assert db.execute('SELECT COUNT(*) FROM video_agent_moments WHERE user_id=1').fetchone()[0]==0
assert db.execute('SELECT COUNT(*) FROM video_agent_plans WHERE user_id=1').fetchone()[0]==0
assert db.execute('SELECT COUNT(*) FROM video_agent_deliveries WHERE user_id=1').fetchone()[0]==0
assert db.execute('SELECT COUNT(*) FROM video_agent_plans WHERE user_id=2').fetchone()[0]==1
print('Video specialist SQLite fixture PASS: schema, isolation, dedup, cascades')
