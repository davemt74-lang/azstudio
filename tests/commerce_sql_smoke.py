#!/usr/bin/env python3
"""Check commerce's exact SQL migration DDL, FK isolation, uniqueness and replay-safe ledger."""
from pathlib import Path
import re,sqlite3
src=(Path(__file__).resolve().parents[1]/'includes/commerce.php').read_text()
definitions=re.findall(r'\$db->exec\("CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\)\$suffix"\)',src,re.S)
assert len(definitions)==7,[x[0] for x in definitions]
db=sqlite3.connect(':memory:')
db.execute('PRAGMA foreign_keys=ON')
db.executescript('''CREATE TABLE users(id INTEGER PRIMARY KEY);
CREATE TABLE model_videos(id TEXT PRIMARY KEY,user_id INTEGER NOT NULL);
CREATE TABLE az_live_rooms(id TEXT PRIMARY KEY);''')
for name,ddl in definitions: db.execute('CREATE TABLE '+name+' ('+ddl.replace('$uid','INTEGER')+')')
for i in (1,2,3):db.execute('INSERT INTO users VALUES(?)',(i,))
v='a'*32;p='b'*32;q='c'*32;room='d'*32
db.execute('INSERT INTO model_videos VALUES(?,?)',(v,1))
db.execute('INSERT INTO az_live_rooms VALUES(?)',(room,))
db.execute("INSERT INTO commerce_products (id,owner_id,title,description,price_cents,currency,state,revision,created_at,updated_at) VALUES(?,3,'Test','Test',1250,'USD','active',1,'now','now')",(p,))
db.execute("INSERT INTO commerce_briefs VALUES(?,'Brief','Notes','active',1,'now')",(p,))
db.execute("INSERT INTO commerce_interests VALUES(?,1,'approved','now','now')",(p,))
db.execute("INSERT INTO commerce_promotions(id,product_id,model_id,video_id,video_revision,state,created_at,updated_at) VALUES(?,?,?,?,1,'approved','now','now')",(q,p,1,v))
db.execute("INSERT INTO commerce_orders(id,external_reference,product_id,promotion_id,quantity,amount_cents,currency,commission_bps,state,recorded_by,created_at,updated_at) VALUES(?, 'reference-A', ?, ?, 1,1250,'USD',1000,'recorded',3,'now','now')",('e'*32,p,q))
try:
 db.execute("INSERT INTO commerce_orders(id,external_reference,product_id,quantity,amount_cents,currency,state,recorded_by,created_at,updated_at) VALUES(?,'reference-A',?,1,1250,'USD','recorded',3,'now','now')",('f'*32,p))
 raise AssertionError('duplicate external order reference admitted')
except sqlite3.IntegrityError:pass
try:
 db.execute("INSERT INTO commerce_promotions(id,product_id,model_id,video_id,video_revision,state,created_at,updated_at) VALUES(?,?,?,?,1,'pending','now','now')",('1'*32,p,1,'z'*32))
 raise AssertionError('unlinked video admitted')
except sqlite3.IntegrityError:pass
db.execute("INSERT INTO commerce_live_features VALUES(?,?,3,'now')",(room,p))
try:
 db.execute("INSERT INTO commerce_live_features VALUES(?,?,3,'now')",(room,p))
 raise AssertionError('two features for one live room')
except sqlite3.IntegrityError:pass
db.execute("INSERT INTO commerce_activity(event_key,actor_id,recipient_id,kind,title,detail,href,announce,created_at) VALUES(? ,3,1,'test','Test','Test','commerce.php',1,'now')",('0'*64,))
try:
 db.execute("INSERT INTO commerce_activity(event_key,actor_id,recipient_id,kind,title,detail,href,announce,created_at) VALUES(? ,3,1,'test','Test','Test','commerce.php',1,'now')",('0'*64,))
 raise AssertionError('duplicate outbox event admitted')
except sqlite3.IntegrityError:pass
assert db.execute('SELECT COUNT(*) FROM commerce_orders WHERE promotion_id=?',(q,)).fetchone()[0]==1
assert db.execute('SELECT COUNT(*) FROM commerce_briefs WHERE product_id=?',(p,)).fetchone()[0]==1
print('Commerce exact SQLite migration DDL, FK and idempotency fixture PASS')
