#!/usr/bin/env python3
"""Exact v39 DDL: product SKU/stock settings, reservation and private shipment ownership."""
from pathlib import Path
import re,sqlite3
src=(Path(__file__).resolve().parents[1]/'includes/commerce-fulfillment.php').read_text()
tables=re.findall(r'\$db->exec\("CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\)\$suffix"\)',src,re.S)
expected=['commerce_product_fulfillment','commerce_stock_reservations','commerce_inventory_events','commerce_shipments']
assert [name for name,_ in tables]==expected,[name for name,_ in tables]
db=sqlite3.connect(':memory:')
db.execute('PRAGMA foreign_keys=ON')
db.executescript("""
CREATE TABLE users(id INTEGER PRIMARY KEY);
CREATE TABLE commerce_products(id TEXT PRIMARY KEY);
CREATE TABLE commerce_orders(id TEXT PRIMARY KEY);
CREATE TABLE commerce_checkout_attempts(id TEXT PRIMARY KEY);
INSERT INTO users VALUES(3);
""")
for name,ddl in tables:
    db.execute('CREATE TABLE '+name+' ('+ddl.replace('$uid','INTEGER')+')')
p='a'*32;order='b'*32;attempt='c'*32
db.execute('INSERT INTO commerce_products VALUES(?)',(p,))
db.execute('INSERT INTO commerce_orders VALUES(?)',(order,))
db.execute('INSERT INTO commerce_checkout_attempts VALUES(?)',(attempt,))
db.execute("""INSERT INTO commerce_product_fulfillment
 (product_id,sku,stock_mode,on_hand,reserved,low_stock_threshold,shipping_enabled,shipping_rate,
 shipping_fee_cents,free_above_cents,weight_grams,length_mm,width_mm,height_mm,handling_days,ship_country,revision,updated_at)
 VALUES(?,'sku','tracked',2,1,1,1,'flat',375,5000,400,200,200,100,3,'US',1,'now')""",(p,))
db.execute("""INSERT INTO commerce_stock_reservations
 (stock_key,product_id,order_id,checkout_attempt_id,source,quantity,status,created_at,updated_at)
 VALUES(?,?,?,?,1,1,'held','now','now')""",('1'*64,p,order,attempt))
try:
    db.execute("""INSERT INTO commerce_stock_reservations
    (stock_key,product_id,order_id,checkout_attempt_id,source,quantity,status,created_at,updated_at)
    VALUES(?,?,?,?,1,1,'held','now','now')""",('1'*64,p,order,attempt))
    raise AssertionError('Duplicate stock key admitted')
except sqlite3.IntegrityError:pass
db.execute("INSERT INTO commerce_shipments(order_id,contact_ciphertext,product_title,shipping_fee_cents,status,created_at,updated_at) VALUES(?,'encrypted only','Parcel',375,'awaiting_dispatch','now','now')",(order,))
try:
    db.execute("INSERT INTO commerce_shipments(order_id,contact_ciphertext,product_title,shipping_fee_cents,status,created_at,updated_at) VALUES(?,'bad','Parcel',375,'awaiting_dispatch','now','now')",(order,))
    raise AssertionError('Multiple shipments for one paid order')
except sqlite3.IntegrityError:pass
try:
    db.execute("INSERT INTO commerce_shipments(order_id,contact_ciphertext,product_title,shipping_fee_cents,status,created_at,updated_at) VALUES(?,'bad','Parcel',375,'awaiting_dispatch','now','now')",('d'*32,))
    raise AssertionError('Orphan shipment admitted')
except sqlite3.IntegrityError:pass
assert db.execute('SELECT reserved,on_hand FROM commerce_product_fulfillment WHERE product_id=?',(p,)).fetchone()==(1,2)
assert db.execute('SELECT COUNT(*) FROM commerce_shipments WHERE order_id=?',(order,)).fetchone()[0]==1
print('Shipping/inventory exact SQLite DDL, unique reservations and shipment FK PASS')
