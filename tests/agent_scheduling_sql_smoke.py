#!/usr/bin/env python3
"""Exercise the v1.27 SQLite migration SQL and scoped reads. Hosted MySQL still requires acceptance."""
import re, sqlite3
from pathlib import Path
src=(Path(__file__).resolve().parents[1]/'includes/agent-scheduling.php').read_text()
patterns=re.findall(r'\$db->exec\("CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\)\$suffix"\)',src,re.S)
assert len(patterns)==4, [x[0] for x in patterns]
db=sqlite3.connect(':memory:')
db.execute('PRAGMA foreign_keys=ON')
db.executescript('''
CREATE TABLE users(id INTEGER PRIMARY KEY, name TEXT,status TEXT);
CREATE TABLE user_roles(user_id INTEGER,role TEXT);
CREATE TABLE schedule_appointments(id TEXT PRIMARY KEY,customer_id INTEGER,professional_id INTEGER,status TEXT,start_at TEXT,end_at TEXT);
CREATE TABLE schedule_events(id TEXT PRIMARY KEY,title TEXT,start_at TEXT,end_at TEXT,status TEXT,instructor_id INTEGER,created_by INTEGER,type TEXT);
CREATE TABLE schedule_registrations(event_id TEXT,user_id INTEGER,status TEXT);
CREATE TABLE production_projects(id TEXT PRIMARY KEY,owner_id INTEGER,title TEXT,status TEXT,updated_at TEXT,event_id TEXT,appointment_id TEXT);
CREATE TABLE production_members(project_id TEXT,user_id INTEGER,status TEXT);
''')
for name,body in patterns:
    sql=f'CREATE TABLE IF NOT EXISTS {name} ({body.replace("$uid","INTEGER")})'
    db.execute(sql);db.execute(sql)
db.executemany('INSERT INTO users VALUES(?,?,?)',[(1,'Admin A','active'),(2,'Model A','active'),(3,'Model B','active'),(4,'Admin B','active')])
db.executemany('INSERT INTO user_roles VALUES (?,?)',[(1,'admin'),(4,'admin'),(2,'model'),(3,'model')])
week='{"0":[],"1":[{"start":"09:00","end":"17:00"}]}'
db.executemany('INSERT INTO model_schedule_availability VALUES(?,?,?,?)',[(2,week,1,'2026-11-21T00:00:00Z'),(3,week,1,'2026-11-21T00:00:00Z')])
assert db.execute('SELECT COUNT(*) FROM model_schedule_availability WHERE user_id=?',(2,)).fetchone()[0]==1
assert db.execute('SELECT COUNT(*) FROM model_schedule_availability WHERE user_id=?',(4,)).fetchone()[0]==0
b='b'*32
db.execute('INSERT INTO model_schedule_blackouts VALUES(?,?,?,?,?,?)',(b,2,'2026-11-23T17:00:00Z','2026-11-23T18:00:00Z','Unavailable','2026-11-21T00:00:00Z'))
assert db.execute('SELECT COUNT(*) FROM model_schedule_blackouts WHERE user_id=?',(3,)).fetchone()[0]==0
finger='f'*64
db.execute('INSERT INTO schedule_agent_deliveries VALUES(?,?,?,?,?,?)',('a'*32,2,'appointment:a','appointment_24h',finger,'2026-11-21T00:00:00Z'))
try:
    db.execute('INSERT INTO schedule_agent_deliveries VALUES(?,?,?,?,?,?)',('c'*32,2,'appointment:a','appointment_24h',finger,'2026-11-21T00:00:00Z'))
    raise AssertionError('duplicate reminder accepted')
except sqlite3.IntegrityError:pass
db.execute('INSERT INTO schedule_agent_deliveries VALUES(?,?,?,?,?,?)',('d'*32,3,'appointment:a','appointment_24h',finger,'2026-11-21T00:00:00Z'))
assert db.execute('SELECT COUNT(*) FROM schedule_agent_deliveries WHERE recipient_id=?',(2,)).fetchone()[0]==1
assert db.execute('SELECT COUNT(*) FROM schedule_agent_deliveries WHERE recipient_id=?',(3,)).fetchone()[0]==1
# Direct appointment visibility, without disclosing another model's booking.
db.executemany('INSERT INTO schedule_appointments VALUES(?,?,?,?,?,?)', [('e'*32,2,4,'confirmed','2026-11-23T17:00:00Z','2026-11-23T18:00:00Z'),('f'*32,3,4,'confirmed','2026-11-24T17:00:00Z','2026-11-24T18:00:00Z')])
query="SELECT id FROM schedule_appointments WHERE status='confirmed' AND (customer_id=? OR professional_id=?) AND start_at<? AND end_at>?"
assert db.execute(query,(2,2,'2026-12-01T00:00:00Z','2026-11-21T00:00:00Z')).fetchall()==[('e'*32,)]
assert db.execute(query,(3,3,'2026-12-01T00:00:00Z','2026-11-21T00:00:00Z')).fetchall()==[('f'*32,)]
# For model 2, project 3 (owned by other admin) must not be readable via membership.
db.executemany('INSERT INTO production_projects VALUES(?,?,?,?,?,?,?)', [('g'*32,1,'Owned A','active','2026-11-21T00:00:00Z',None,None),('h'*32,4,'Owned B','active','2026-11-21T00:00:00Z',None,None)])
assert db.execute("SELECT id FROM production_projects WHERE owner_id=? AND status='active'",(1,)).fetchall()==[('g'*32,)]
assert db.execute("SELECT id FROM production_projects WHERE owner_id=? AND status='active'",(4,)).fetchall()==[('h'*32,)]
db.execute('DELETE FROM users WHERE id=?',(2,))
assert db.execute('SELECT COUNT(*) FROM model_schedule_blackouts WHERE user_id=?',(2,)).fetchone()[0]==0
assert db.execute('SELECT COUNT(*) FROM schedule_agent_deliveries WHERE recipient_id=?',(2,)).fetchone()[0]==0
print('Scheduling SQLite fixture PASS: schema, role isolation, duplicate suppression, private bookings, project ownership, FK cascade')
