#!/usr/bin/env python3
"""Verify v1.32's exact SQLite DDL: independent product settings and secret receipt uniqueness."""
from pathlib import Path
import re,sqlite3
src=(Path(__file__).resolve().parents[1]/'includes/commerce-local.php').read_text()
definitions=re.findall(r'\$db->exec\("CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\)\$suffix"\)',src,re.S)
assert [name for name,_ in definitions]==['commerce_local_product_settings','commerce_local_orders'],[name for name,_ in definitions]
db=sqlite3.connect(':memory:')
db.execute('PRAGMA foreign_keys=ON')
db.executescript("""
CREATE TABLE users(id INTEGER PRIMARY KEY);
CREATE TABLE commerce_products(id TEXT PRIMARY KEY,owner_id INTEGER);
CREATE TABLE commerce_orders(id TEXT PRIMARY KEY,product_id TEXT NOT NULL);
INSERT INTO users VALUES(1);
""")
for name,ddl in definitions:
    db.execute('CREATE TABLE '+name+' ('+ddl.replace('$uid','INTEGER')+')')
a='a'*32;b='b'*32;order='c'*32
db.execute("INSERT INTO commerce_products VALUES(?,1)",(a,))
db.execute("INSERT INTO commerce_products VALUES(?,1)",(b,))
db.execute("INSERT INTO commerce_orders VALUES(?,?)",(order,a))
db.execute("INSERT INTO commerce_local_product_settings(product_id,enabled,pickup_enabled,delivery_enabled,pickup_location,pickup_address,pickup_hours,pickup_instructions,delivery_zips,delivery_state,delivery_fee_cents,delivery_min_cents,delivery_instructions,revision,updated_at) VALUES(?,1,1,0,'A','Pickup at A','10-4','','[]','AZ',0,0,'',1,'now')",(a,))
db.execute("INSERT INTO commerce_local_product_settings(product_id,enabled,pickup_enabled,delivery_enabled,pickup_location,pickup_address,pickup_hours,pickup_instructions,delivery_zips,delivery_state,delivery_fee_cents,delivery_min_cents,delivery_instructions,revision,updated_at) VALUES(?,1,0,1,'','','','','[\"85002\"]','AZ',400,2000,'',1,'now')",(b,))
assert db.execute("SELECT pickup_address FROM commerce_local_product_settings WHERE product_id=?",(a,)).fetchone()[0]=='Pickup at A'
assert db.execute("SELECT delivery_fee_cents FROM commerce_local_product_settings WHERE product_id=?",(b,)).fetchone()[0]==400
db.execute("INSERT INTO commerce_local_orders(order_id,request_key,cart_hash,receipt_hash,method,channel,source_ref,delivery_zip,contact_ciphertext,pickup_snapshot,delivery_fee_cents,subtotal_cents,status,payment_status,created_at,updated_at) VALUES(?,?,?,?, 'pickup','shop',NULL,NULL,'cipher','pickup',0,1000,'pending','due','now','now')",(order,'1'*64,'2'*64,'3'*64))
for duplicate in ['request_key','receipt_hash']:
    newid='d'*32
    db.execute("INSERT INTO commerce_orders VALUES(?,?)",(newid,b))
    try:
        db.execute("INSERT INTO commerce_local_orders(order_id,request_key,cart_hash,receipt_hash,method,channel,source_ref,delivery_zip,contact_ciphertext,pickup_snapshot,delivery_fee_cents,subtotal_cents,status,payment_status,created_at,updated_at) VALUES(?,?,?,?, 'delivery','shop',NULL,'85002','cipher','',400,2000,'pending','due','now','now')",(newid,'1'*64 if duplicate=='request_key' else '4'*64,'5'*64,'3'*64 if duplicate=='receipt_hash' else '6'*64))
        raise AssertionError('Duplicate '+duplicate+' accepted')
    except sqlite3.IntegrityError: pass
    db.execute("DELETE FROM commerce_orders WHERE id=?",(newid,))
try:
    db.execute("INSERT INTO commerce_local_product_settings(product_id,enabled,pickup_enabled,delivery_enabled,delivery_zips,revision,updated_at) VALUES(?,1,1,0,'[]',1,'now')",('e'*32,))
    raise AssertionError('Orphan product settings admitted')
except sqlite3.IntegrityError:pass
db.execute('DELETE FROM commerce_products WHERE id=?',(a,))
assert db.execute('SELECT COUNT(*) FROM commerce_local_product_settings WHERE product_id=?',(a,)).fetchone()[0]==0
assert db.execute('SELECT COUNT(*) FROM commerce_local_product_settings WHERE product_id=?',(b,)).fetchone()[0]==1
print('Product-specific cash/pickup/delivery DDL, FK, receipt and cascade fixture PASS')
