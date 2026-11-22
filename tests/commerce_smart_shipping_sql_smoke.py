#!/usr/bin/env python3
"""Exact schema41 SQLite SQL: private rate quote ownership and checkout selections."""
from pathlib import Path
import re,sqlite3
src=(Path(__file__).resolve().parents[1]/'includes/commerce-customer-shipping.php').read_text()
tables=re.findall(r'\$db->exec\("CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\)\$suffix"\)',src,re.S)
expected=['commerce_customer_shipping_quotes','commerce_customer_shipping_selections']
assert [name for name,_ in tables]==expected,[name for name,_ in tables]
db=sqlite3.connect(':memory:')
db.execute('PRAGMA foreign_keys=ON')
db.executescript("""
CREATE TABLE commerce_products(id TEXT PRIMARY KEY);
CREATE TABLE commerce_checkout_attempts(id TEXT PRIMARY KEY);
INSERT INTO commerce_products VALUES('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
INSERT INTO commerce_checkout_attempts VALUES('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
INSERT INTO commerce_checkout_attempts VALUES('cccccccccccccccccccccccccccccccc');
""")
for name,ddl in tables:db.execute('CREATE TABLE '+name+' ('+ddl+')')
p='a'*32;a='b'*32;c='c'*32;q='d'*32
db.execute("""INSERT INTO commerce_customer_shipping_quotes(
 id,request_key,session_binding,product_id,quantity,product_revision,fulfillment_revision,
 unit_cents,provider_mode,provider_shipment_id,address_ciphertext,address_hash,rates_json,
 created_at,expires_at) VALUES(?,?,?,?,1,1,1,1999,'test','shp_mock11111111','encrypted','e','[]','now','later')""",
 (q,'1'*64,'2'*64,p))
db.execute("""INSERT INTO commerce_customer_shipping_selections(
 attempt_id,quote_id,provider_shipment_id,rate_id,carrier,service,rate_cents,address_hash,provider_mode,created_at
 ) VALUES(?,?, 'shp_mock11111111','rate_mock11111111','USPS','Priority',595,'e','test','now')""",(a,q))
for label,action in [
 ('duplicate quote request',lambda:db.execute("""INSERT INTO commerce_customer_shipping_quotes(
 id,request_key,session_binding,product_id,quantity,product_revision,fulfillment_revision,
 unit_cents,provider_mode,provider_shipment_id,address_ciphertext,address_hash,rates_json,
 created_at,expires_at) VALUES(?,?,?,?,1,1,1,1999,'test','shp_mock22222222','encrypted','e','[]','now','later')""",
 ('f'*32,'1'*64,'2'*64,p))),
 ('duplicate selection',lambda:db.execute("""INSERT INTO commerce_customer_shipping_selections(
 attempt_id,quote_id,provider_shipment_id,rate_id,carrier,service,rate_cents,address_hash,provider_mode,created_at
 ) VALUES(?,?,'shp_mock11111111','rate_mock11111111','USPS','Priority',595,'e','test','now')""",(a,q))),
 ('orphan quote',lambda:db.execute("""INSERT INTO commerce_customer_shipping_quotes(
 id,request_key,session_binding,product_id,quantity,product_revision,fulfillment_revision,
 unit_cents,provider_mode,provider_shipment_id,address_ciphertext,address_hash,rates_json,
 created_at,expires_at) VALUES(?,?,?,?,1,1,1,1999,'test','shp_mock33333333','encrypted','e','[]','now','later')""",
 ('f'*32,'3'*64,'2'*64,'e'*32))),
 ('orphan selection',lambda:db.execute("""INSERT INTO commerce_customer_shipping_selections(
 attempt_id,quote_id,provider_shipment_id,rate_id,carrier,service,rate_cents,address_hash,provider_mode,created_at
 ) VALUES(?,?,'shp_mock11111111','rate_mock11111111','USPS','Priority',595,'e','test','now')""",(c,'z'*32)))
]:
    try:action();raise AssertionError(label+' accepted')
    except sqlite3.IntegrityError:pass
assert db.execute("SELECT COUNT(*) FROM commerce_customer_shipping_selections").fetchone()[0]==1
print('Customer rate quote and checkout selection SQLite DDL / uniqueness / FK PASS')
