#!/usr/bin/env python3
"""Exercise the exact Live Agent v31 DDL and fresh joined-viewer query with SQLite.
PHP in this runner does not have pdo_sqlite/pdo_mysql, so this is an isolated
schema/query validation, not a claim of testing a hosted DB migration.
"""
import pathlib,re,sqlite3
root=pathlib.Path(__file__).resolve().parents[1]
src=(root/'includes/live-agent.php').read_text()
con=sqlite3.connect(':memory:')
con.execute('PRAGMA foreign_keys=ON')
con.execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)')
con.execute('CREATE TABLE az_live_rooms (id CHAR(32) PRIMARY KEY, owner_id INTEGER NOT NULL, status TEXT NOT NULL)')
con.execute('''CREATE TABLE az_live_viewers (id CHAR(32) PRIMARY KEY,room_id CHAR(32),user_id INTEGER,
 status TEXT NOT NULL,updated_epoch INTEGER NOT NULL,FOREIGN KEY(room_id) REFERENCES az_live_rooms(id) ON DELETE CASCADE)''')
for table in ('az_live_agent_samples','az_live_agent_incidents'):
    match=re.search(r'\$db->exec\("(CREATE TABLE IF NOT EXISTS '+table+r' \(.*?\))\$suffix"\);',src,re.S)
    assert match,table+' DDL missing'
    statement=match.group(1).replace('$uid','INTEGER')
    con.execute(statement)
con.executemany('INSERT INTO users VALUES (?,?)',[(1,'Producer'),(2,'Fan'),(3,'Other')])
a='a'*32;b='b'*32;viewer='c'*32;other_viewer='d'*32
con.executemany('INSERT INTO az_live_rooms VALUES (?,?,?)',[(a,1,'live'),(b,3,'live')])
con.executemany('INSERT INTO az_live_viewers VALUES (?,?,?,?,?)',[(viewer,a,2,'connected',100),(other_viewer,b,3,'connected',100)])
columns='room_id,source,connection_id,reporter_id,updated_epoch,video_state,decoded_fps'
insert=f'INSERT INTO az_live_agent_samples ({columns}) VALUES (?,?,?,?,?,?,?)'
con.execute(insert,(a,'director',a,1,100,'unknown',None))
con.execute(insert,(a,'viewer',viewer,2,100,'stalled',0.0))
con.execute(insert,(b,'viewer',other_viewer,3,100,'stalled',0.0))
q=re.search(r'\$q=\$db->prepare\("(SELECT s\.\* FROM az_live_agent_samples s.*?)"\);',src,re.S)
assert q,'Fresh authorized-viewer query missing'
rows=con.execute(q.group(1),(55,a,65)).fetchall()
assert len(rows)==2,len(rows)
# The reporter's other production must not leak into this room.
assert all(row[0]==a for row in rows)
con.execute('UPDATE az_live_viewers SET status=? WHERE id=?',('left',viewer))
assert len(con.execute(q.group(1),(55,a,65)).fetchall())==1,'Left viewer still diagnosed as frozen'
con.execute('UPDATE az_live_viewers SET status=?, updated_epoch=? WHERE id=?',('connected',20,viewer))
assert len(con.execute(q.group(1),(55,a,65)).fetchall())==1,'Expired viewer lease still counted'
try:con.execute(insert,(a,'director',a,1,101,'unknown',None));raise AssertionError('Expected duplicate PK rejection')
except sqlite3.IntegrityError:pass
try:con.execute(insert,('f'*32,'director','f'*32,1,101,'unknown',None));raise AssertionError('Expected FK rejection')
except sqlite3.IntegrityError:pass
con.execute('INSERT INTO az_live_agent_incidents(room_id,code,opened_epoch,last_seen_epoch,observations) VALUES(?,?,?,?,?)',(a,'viewer_freeze',100,100,1))
con.execute('DELETE FROM az_live_rooms WHERE id=?',(a,))
assert con.execute('SELECT COUNT(*) FROM az_live_agent_samples WHERE room_id=?',(a,)).fetchone()[0]==0
assert con.execute('SELECT COUNT(*) FROM az_live_agent_incidents WHERE room_id=?',(a,)).fetchone()[0]==0
assert con.execute('SELECT COUNT(*) FROM az_live_agent_samples WHERE room_id=?',(b,)).fetchone()[0]==1
print('Live Agent SQLite DDL/query fixture PASS: owner isolation, viewer lease, leave, uniqueness, FK, cascade')
