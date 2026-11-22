#!/usr/bin/env python3
"""Schema43 exact SQLite: financial operation uniqueness and foreign keys."""
from pathlib import Path
import re,sqlite3
src=(Path(__file__).resolve().parents[1]/'includes/commerce-provider-operations.php').read_text()
tables=re.findall(r'\$db->exec\("CREATE TABLE IF NOT EXISTS (\w+)\((.*?)\)\$suffix"\)',src,re.S)
names=['commerce_provider_refunds','commerce_return_label_quotes','commerce_return_labels',
       'commerce_connect_accounts','commerce_connect_transfers']
assert [n for n,_ in tables]==names,[n for n,_ in tables]
db=sqlite3.connect(':memory:')
db.execute('PRAGMA foreign_keys=ON')
db.executescript("""
CREATE TABLE users(id INTEGER PRIMARY KEY);
CREATE TABLE commerce_orders(id TEXT PRIMARY KEY);
CREATE TABLE commerce_returns(id TEXT PRIMARY KEY);
CREATE TABLE commerce_payout_batches(id TEXT PRIMARY KEY);
INSERT INTO users VALUES(1),(3);
INSERT INTO commerce_orders VALUES('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
INSERT INTO commerce_returns VALUES('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
INSERT INTO commerce_payout_batches VALUES('cccccccccccccccccccccccccccccccc');
""")
for name,definition in tables:db.execute('CREATE TABLE '+name+' ('+definition.replace('$uid','INTEGER')+')')
rid='b'*32;oid='a'*32;batch='c'*32
db.execute("""INSERT INTO commerce_provider_refunds(
 return_id,order_id,payment_intent_id,amount_cents,idempotency_key,mode,status,initiated_by,created_at,updated_at)
 VALUES(?,?,'pi_test123456',1000,'azh-refund-1','test','pending',3,'now','now')""",(rid,oid))
db.execute("""INSERT INTO commerce_return_label_quotes(
 return_id,provider_shipment_id,rates_json,parcel_json,mode,created_at,expires_at,created_by)
 VALUES(?,'shp_test123456','[]','{}','test','now','later',3)""",(rid,))
db.execute("""INSERT INTO commerce_return_labels(
 return_id,shipment_id,rate_id,carrier,service,rate_cents,mode,status,purchased_by,created_at,updated_at)
 VALUES(?,'shp_test123456','rate_test123456','USPS','Priority',595,'test','pending',3,'now','now')""",(rid,))
db.execute("""INSERT INTO commerce_connect_accounts(model_id,account_id,mode,status,verified_at,verified_by)
 VALUES(1,'acct_test123456','test','verified','now',3)""")
db.execute("""INSERT INTO commerce_connect_transfers(
 batch_id,account_id,amount_cents,mode,transfer_group,status,authorized_by,created_at,updated_at)
 VALUES(?,'acct_test123456',1000,'test','azh-batch-id','pending',3,'now','now')""",(batch,))
for label,sql,args in [
 ('duplicate refund return',"INSERT INTO commerce_provider_refunds(return_id,order_id,payment_intent_id,amount_cents,idempotency_key,mode,status,initiated_by,created_at,updated_at) VALUES(?,?,'pi_test123456',1000,'new-key','test','pending',3,'now','now')",(rid,oid)),
 ('duplicate account',"INSERT INTO commerce_connect_accounts(model_id,account_id,mode,status,verified_at,verified_by) VALUES(3,'acct_test123456','test','verified','now',3)",()),
 ('duplicate transfer batch',"INSERT INTO commerce_connect_transfers(batch_id,account_id,amount_cents,mode,transfer_group,status,authorized_by,created_at,updated_at) VALUES(?,'acct_test123456',1000,'test','group2','pending',3,'now','now')",(batch,)),
 ('orphan return label',"INSERT INTO commerce_return_labels(return_id,shipment_id,rate_id,carrier,service,rate_cents,mode,status,purchased_by,created_at,updated_at) VALUES(?,'shp_new0000001','rate_new0000001','UPS','Ground',500,'test','pending',3,'now','now')",('e'*32,))
]:
    try:db.execute(sql,args);raise AssertionError(label+' admitted')
    except sqlite3.IntegrityError:pass
assert db.execute('SELECT COUNT(*) FROM commerce_connect_transfers').fetchone()[0]==1
print('Schema43 provider refunds, return labels and Connect operations FK/uniqueness PASS')
