#!/usr/bin/env python3
"""Schema42: returns, immutable audit and payout allocation ownership constraints."""
from pathlib import Path
import re,sqlite3
src=(Path(__file__).resolve().parents[1]/'includes/commerce-returns.php').read_text()
tables=re.findall(r'\$db->exec\("CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\)\$suffix"\)',src,re.S)
expected=['commerce_returns','commerce_return_events',
          'commerce_payout_batches','commerce_payout_allocations']
assert [n for n,_ in tables]==expected,[n for n,_ in tables]
db=sqlite3.connect(':memory:')
db.execute('PRAGMA foreign_keys=ON')
db.executescript("""
CREATE TABLE users(id INTEGER PRIMARY KEY);
CREATE TABLE commerce_orders(id TEXT PRIMARY KEY);
INSERT INTO users VALUES(1),(3);
INSERT INTO commerce_orders VALUES('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
INSERT INTO commerce_orders VALUES('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
""")
for name,definition in tables:
    db.execute('CREATE TABLE '+name+' ('+definition.replace('$uid','INTEGER')+')')
order='a'*32;other='b'*32;return_id='c'*32;payout='d'*32
db.execute("""INSERT INTO commerce_returns
 (id,order_id,kind,quantity,reason,source,fingerprint,status,created_at,updated_at)
 VALUES(?,?,'refund',1,'Damaged goods','staff',?,'requested','now','now')""",
 (return_id,order,'1'*64))
db.execute("""INSERT INTO commerce_return_events
 (id,return_id,actor_id,action,detail,created_at)
 VALUES(?, ?,3,'requested','staff only','now')""",('e'*32,return_id))
db.execute("""INSERT INTO commerce_payout_batches
 (id,model_id,amount_cents,state,created_by,created_at,updated_at)
 VALUES(?,1,125,'draft',3,'now','now')""",(payout,))
db.execute("""INSERT INTO commerce_payout_allocations(batch_id,order_id,amount_cents)
 VALUES(?,?,125)""",(payout,order))
cases=[
 ('duplicate buyer request fingerprint',lambda:db.execute("""INSERT INTO commerce_returns
 (id,order_id,kind,quantity,reason,source,fingerprint,status,created_at,updated_at)
 VALUES(?,?,'refund',1,'Returned','staff',?,'requested','now','now')""",
 ('f'*32,order,'1'*64))),
 ('duplicate payout allocation',lambda:db.execute("""INSERT INTO commerce_payout_allocations
 (batch_id,order_id,amount_cents) VALUES(?,?,100)""",(payout,order))),
 ('duplicate settlement evidence',lambda:(
    db.execute("UPDATE commerce_payout_batches SET evidence_reference='bank-0001' WHERE id=?",(payout,)),
    db.execute("""INSERT INTO commerce_payout_batches
     (id,model_id,amount_cents,state,created_by,created_at,updated_at,evidence_reference)
     VALUES(?,1,125,'paid',3,'now','now','bank-0001')""",('g'*32,))
 )),
 ('orphan return event',lambda:db.execute("""INSERT INTO commerce_return_events
 (id,return_id,actor_id,action,created_at)
 VALUES(?, ?,3,'requested','now')""",('h'*32,'f'*32))),
 ('orphan payout allocation',lambda:db.execute("""INSERT INTO commerce_payout_allocations
 (batch_id,order_id,amount_cents) VALUES(?,?,125)""",('f'*32,order)))
]
for label,action in cases:
    try:
        action()
        raise AssertionError('Database admitted '+label)
    except sqlite3.IntegrityError:pass
assert db.execute('SELECT COUNT(*) FROM commerce_payout_allocations').fetchone()[0]==1
assert db.execute('SELECT COUNT(*) FROM commerce_returns').fetchone()[0]==1
print('Returns and creator payout schema42 FK, unique receipt/reference and allocation PASS')
