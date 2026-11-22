#!/usr/bin/env python3
"""SQLite compatibility and recipient privacy checks for the v1.23 event ledger.
Uses synthetic identities; does not claim to replace a hosted PHP/MySQL test.
"""
import sqlite3
from datetime import datetime, timezone

db=sqlite3.connect(':memory:')
db.execute('PRAGMA foreign_keys=ON')
db.executescript('''
CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL);
CREATE TABLE agent_workspace_events (
 id CHAR(32) PRIMARY KEY, recipient_id INTEGER NOT NULL, actor_id INTEGER NOT NULL,
 kind VARCHAR(48) NOT NULL, title VARCHAR(200) NOT NULL, detail VARCHAR(600) NOT NULL,
 href VARCHAR(240) NOT NULL, created_at VARCHAR(32) NOT NULL, read_at VARCHAR(32),
 FOREIGN KEY(recipient_id) REFERENCES users(id) ON DELETE CASCADE,
 FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX agent_workspace_recipient ON agent_workspace_events(recipient_id, created_at);
''')
db.executemany('INSERT INTO users VALUES(?,?)',[(1,'model A'),(2,'model B'),(3,'admin')])
now=datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
events=[
 ('a'*32,1,1,'video_created','My video draft','Own private draft','my-videos.php',now,now),
 ('b'*32,1,3,'video_changes_requested','Changes requested','Review response','my-videos.php',now,None),
 ('c'*32,2,2,'video_submitted','Sent for review','My submission','my-videos.php',now,now),
 ('d'*32,3,2,'video_submitted','Model video awaiting review','Pending','user-submissions.php',now,None),
 ('e'*32,2,3,'assignment_assigned','New assignment','Production','my-work.php',now,None),
]
db.executemany('INSERT INTO agent_workspace_events VALUES(?,?,?,?,?,?,?,?,?)',events)
counts=[db.execute('SELECT COUNT(*) FROM agent_workspace_events WHERE recipient_id=? AND read_at IS NULL',(id,)).fetchone()[0] for id in (1,2,3)]
assert counts==[1,1,1],counts
assert db.execute('SELECT COUNT(*) FROM agent_workspace_events WHERE recipient_id=1').fetchone()[0]==2
assert db.execute('SELECT COUNT(*) FROM agent_workspace_events WHERE recipient_id=2').fetchone()[0]==2
assert db.execute('SELECT COUNT(*) FROM agent_workspace_events WHERE recipient_id=3').fetchone()[0]==1
assert db.execute("UPDATE agent_workspace_events SET read_at=? WHERE id=? AND recipient_id=? AND read_at IS NULL",(now,'d'*32,1)).rowcount==0,'Model must not mark admin notification read'
assert db.execute("UPDATE agent_workspace_events SET read_at=? WHERE id=? AND recipient_id=? AND read_at IS NULL",(now,'b'*32,1)).rowcount==1
assert db.execute("SELECT COUNT(*) FROM agent_workspace_events WHERE recipient_id=1 AND read_at IS NULL").fetchone()[0]==0
assert db.execute("SELECT COUNT(*) FROM agent_workspace_events WHERE recipient_id=3 AND read_at IS NULL").fetchone()[0]==1
assert db.execute('SELECT actor_id FROM agent_workspace_events WHERE id=?',('d'*32,)).fetchone()[0]==2
print('PASS: event SQL schema, correct actor binding, self-event read state, unread counts, per-recipient history and read isolation')
