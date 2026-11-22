#!/usr/bin/env python3
"""Database fixture: validates live SQL candidate joins, owner isolation & UNIQUE dedupe.
Uses SQLite because the build environment lacks PHP pdo_sqlite and pdo_mysql.
"""
from pathlib import Path
import re, sqlite3
src=Path(__file__).parents[1].joinpath('includes/agent-followthrough.php').read_text()
con=sqlite3.connect(':memory:'); con.execute('PRAGMA foreign_keys=ON');con.row_factory=sqlite3.Row
con.executescript('''
CREATE TABLE users(id INTEGER PRIMARY KEY, name TEXT NOT NULL, status TEXT NOT NULL);
CREATE TABLE production_projects(id TEXT PRIMARY KEY,owner_id INTEGER NOT NULL,title TEXT,status TEXT NOT NULL);
CREATE TABLE production_shots(id TEXT PRIMARY KEY,project_id TEXT NOT NULL,title TEXT,due_at TEXT,status TEXT NOT NULL);
CREATE TABLE production_members(project_id TEXT,user_id INTEGER,status TEXT NOT NULL,PRIMARY KEY(project_id,user_id));
CREATE TABLE shot_assignments(id TEXT PRIMARY KEY,shot_id TEXT,user_id INTEGER,status TEXT,revision INTEGER,updated_at TEXT,submitted_at TEXT);
CREATE TABLE agent_workspace_events(id TEXT PRIMARY KEY,recipient_id INTEGER NOT NULL,actor_id INTEGER NOT NULL,kind TEXT,title TEXT,detail TEXT,href TEXT,created_at TEXT,read_at TEXT,
 FOREIGN KEY(recipient_id) REFERENCES users(id), FOREIGN KEY(actor_id) REFERENCES users(id));
''')
# Validate and run the schema from the shipping PHP migration, not an independently invented table.
for table in ['agent_followthrough_deliveries','agent_followthrough_runs']:
    m=re.search(r'CREATE TABLE IF NOT EXISTS '+table+r' \((.*?)\)\$suffix',src,re.S)
    assert m, ('missing shipped SQL schema',table)
    sql=('CREATE TABLE IF NOT EXISTS '+table+' ('+m.group(1)+')').replace('$uid','INTEGER')
    con.execute(sql)
con.executemany('INSERT INTO users VALUES(?,?,?)',[(1,'Owner 1','active'),(2,'Model 1','active'),(3,'Owner 2','active'),(4,'Model 2','active'),(5,'Inactive','inactive')])
con.executemany('INSERT INTO production_projects VALUES(?,?,?,?)',[('p1',1,'Studio A','active'),('p2',3,'Studio B','active'),('p3',1,'Inactive project','archived')])
con.executemany('INSERT INTO production_shots VALUES(?,?,?,?,?)',[('s1','p1','Scene 1',None,'active'),('s2','p2','Scene 2',None,'active'),('s3','p3','Scene 3',None,'active')])
con.executemany('INSERT INTO production_members VALUES(?,?,?)',[('p1',2,'active'),('p2',4,'active'),('p3',2,'active')])
id=lambda n: f'{n:032x}'
con.executemany('INSERT INTO shot_assignments VALUES(?,?,?,?,?,?,?)',[(id(1),'s1',2,'assigned',1,'2026-11-18T00:00:00Z',None),(id(2),'s2',4,'assigned',1,'2026-11-18T00:00:00Z',None),(id(3),'s3',2,'assigned',1,'2026-11-18T00:00:00Z',None)])
assert 'AND a.id>?' in src and 'AND p.owner_id=?' in src
m=re.search(r'\$sql="(SELECT a\.id.*?ORDER BY a\.id LIMIT \?)";',src,re.S)
assert m,'Cannot locate current SQL query'
query=m.group(1).replace('".($ownerId!==null?\' AND p.owner_id=?\':\'\')."',' AND p.owner_id=?')
# Whitespace/escape check and a faithful scoped SQL run.
assert 'JOIN production_members' in query and 'JOIN users o' in query
assert [r['id'] for r in con.execute(query,('',1,50)).fetchall()]==[id(1)]
assert [r['id'] for r in con.execute(query,('',3,50)).fetchall()]==[id(2)]
assert [r['id'] for r in con.execute(query,('',999,50)).fetchall()]==[]
assert [r['id'] for r in con.execute(query,('',1,50)).fetchall()]!=[id(2)],'owner isolation'
con.execute("UPDATE production_members SET status='removed' WHERE project_id='p1' AND user_id=2")
assert not con.execute(query,('',1,50)).fetchall(),'inactive roster must be excluded'
con.execute("UPDATE production_members SET status='active' WHERE project_id='p1' AND user_id=2")
insert='INSERT INTO agent_followthrough_deliveries(id,assignment_id,recipient_id,rule,fingerprint,created_at) VALUES(?,?,?,?,?,?)'
con.execute(insert,(id(4),id(1),2,'unanswered_24h','a'*64,'2026-11-21T12:00:00Z'))
try:
    con.execute(insert,(id(5),id(1),2,'unanswered_24h','a'*64,'2026-11-21T12:00:00Z'))
    raise AssertionError('Duplicate reminder was accepted')
except sqlite3.IntegrityError as exc: assert 'UNIQUE' in str(exc)
con.execute(insert,(id(5),id(1),2,'unanswered_24h','b'*64,'2026-11-21T12:00:00Z'))
try:
    con.execute(insert,(id(6),id(1),999,'unanswered_24h','c'*64,'2026-11-21T12:00:00Z'))
    raise AssertionError('Invalid recipient was accepted')
except sqlite3.IntegrityError as exc: assert 'FOREIGN KEY' in str(exc)
print('SQLite integration fixture passed: owner isolation, roster status, active project, deduplication, new cycles, foreign keys.')
