#!/usr/bin/env python3
"""Exercise SQLite equivalents of the actual v1.26 migration SQL and role/record boundaries.
This does not replace a hosted MySQL migration test.
"""
from pathlib import Path
import re,sqlite3
src=(Path(__file__).resolve().parents[1]/'includes/agent-casting.php').read_text()
patterns=re.findall(r'\$db->exec\("CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\)\$suffix"\)',src,re.S)
assert len(patterns)==3
conn=sqlite3.connect(':memory:');conn.execute('PRAGMA foreign_keys=ON')
conn.executescript('''
CREATE TABLE users (id INTEGER PRIMARY KEY,name TEXT,status TEXT);
CREATE TABLE casting_leads (id CHAR(32) PRIMARY KEY,status TEXT,revision INTEGER,updated_at TEXT,converted_user_id INTEGER);
CREATE TABLE user_roles (user_id INTEGER,role TEXT);
CREATE TABLE model_profiles (id CHAR(32) PRIMARY KEY,linked_user_id INTEGER,owner_id INTEGER,status TEXT,public_bio TEXT,availability TEXT,stage_name TEXT,updated_at TEXT);
CREATE TABLE casting_invites (lead_id CHAR(32),expires_at INTEGER,used_at TEXT);
''')
for name,body in patterns:
    sql=f'CREATE TABLE IF NOT EXISTS {name} ({body.replace("$uid","INTEGER")})'
    conn.execute(sql);conn.execute(sql) # retry safe
conn.executemany('INSERT INTO users VALUES(?,?,?)',[(1,'Admin A','active'),(2,'Model A','active'),(3,'Model B','active'),(4,'Admin B','active')])
conn.executemany('INSERT INTO user_roles VALUES (?,?)',[(1,'admin'),(4,'admin'),(2,'model'),(3,'model')])
conn.executemany('INSERT INTO casting_leads VALUES (?,?,?,?,?)',[(('a'*32),'new',1,'2026-11-18T00:00:00Z',None),(('b'*32),'converted',2,'2026-11-19T00:00:00Z',2),(('c'*32),'converted',2,'2026-11-19T00:00:00Z',3)])
conn.executemany('INSERT INTO model_profiles VALUES (?,?,?,?,?,?,?,?)',[(('d'*32),2,1,'active','Has bio','Weekends','Model A','2026-11-21T00:00:00Z'),(('e'*32),3,4,'active','Has bio','','Model B','2026-11-21T00:00:00Z')])
conn.execute("INSERT INTO agent_casting_deliveries VALUES(?,?,?,?,?,?)",('f'*32,'a'*32,1,'review_24h','9'*64,'2026-11-21T00:00:00Z'))
try:
    conn.execute("INSERT INTO agent_casting_deliveries VALUES(?,?,?,?,?,?)",('7'*32,'a'*32,1,'review_24h','9'*64,'2026-11-21T00:00:00Z'))
    raise AssertionError('Duplicate delivery was accepted')
except sqlite3.IntegrityError:pass
conn.execute("INSERT INTO agent_casting_deliveries VALUES(?,?,?,?,?,?)",('8'*32,'a'*32,4,'review_24h','9'*64,'2026-11-21T00:00:00Z'))
assert conn.execute('SELECT COUNT(*) FROM agent_casting_deliveries WHERE recipient_id=?',(1,)).fetchone()[0]==1
assert conn.execute('SELECT COUNT(*) FROM agent_casting_deliveries WHERE recipient_id=?',(4,)).fetchone()[0]==1
conn.execute("INSERT INTO model_onboarding_checkpoints VALUES(?,?,?,?)",(2,'studio_briefing',1,'2026-11-21T00:00:00Z'))
assert conn.execute('SELECT COUNT(*) FROM model_onboarding_checkpoints WHERE model_user_id=?',(3,)).fetchone()[0]==0
try:
    conn.execute("INSERT INTO model_onboarding_checkpoints VALUES(?,?,?,?)",(2,'studio_briefing',4,'2026-11-21T00:00:00Z'))
    raise AssertionError('Duplicate model checkpoint was accepted')
except sqlite3.IntegrityError:pass
# Exact SQL shape for converted model records with MySQL-compatible grouped ordering.
rows=conn.execute("""SELECT l.converted_user_id FROM casting_leads l
  JOIN users u ON u.id=l.converted_user_id AND u.status='active'
  WHERE l.status='converted' AND l.converted_user_id IS NOT NULL
  GROUP BY l.converted_user_id ORDER BY MAX(l.updated_at) DESC LIMIT ?""",(75,)).fetchall()
assert set(x[0] for x in rows)=={2,3},rows
# At most the target model's stored acknowledgments are visible to that model.
assert conn.execute('SELECT checkpoint FROM model_onboarding_checkpoints WHERE model_user_id=?',(2,)).fetchall()==[('studio_briefing',)]
# FK cascade cleans the associated dedup ledger when applications are deleted.
conn.execute('DELETE FROM casting_leads WHERE id=?',('a'*32,))
assert conn.execute('SELECT COUNT(*) FROM agent_casting_deliveries').fetchone()[0]==0
print('Agent casting SQLite fixture PASS: 3 migration tables, replay, deduplication, scoped reads, grouped conversions and cascade')
