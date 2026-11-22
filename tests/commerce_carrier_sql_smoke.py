#!/usr/bin/env python3
"""Exact v40 carrier SQL DDL: shipment ownership, label identity, idempotent webhook."""
from pathlib import Path
import re,sqlite3
src=(Path(__file__).resolve().parents[1]/'includes/commerce-carriers.php').read_text()
tables=re.findall(r'\$db->exec\("CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\)\$suffix"\)',src,re.S)
expected=['commerce_carrier_quotes','commerce_carrier_labels','commerce_carrier_events']
assert [name for name,_ in tables]==expected,[name for name,_ in tables]
db=sqlite3.connect(':memory:')
db.execute('PRAGMA foreign_keys=ON')
db.executescript("""
CREATE TABLE users(id INTEGER PRIMARY KEY);
CREATE TABLE commerce_shipments(order_id TEXT PRIMARY KEY);
INSERT INTO users VALUES(3);
INSERT INTO commerce_shipments VALUES('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
INSERT INTO commerce_shipments VALUES('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
""")
for name,ddl in tables:
    db.execute('CREATE TABLE '+name+' ('+ddl.replace('$uid','INTEGER')+')')
a='a'*32;b='b'*32
db.execute("""INSERT INTO commerce_carrier_quotes(order_id,provider_shipment_id,mode,rates_json,parcel_json,
quote_revision,created_at,expires_at,requested_by)
VALUES(?,'shp_mock11111111','test','[]','{}',1,'now','later',3)""",(a,))
db.execute("""INSERT INTO commerce_carrier_labels(order_id,provider_shipment_id,provider_rate_id,
rate_cents,carrier,service,mode,status,requested_by,created_at,updated_at)
VALUES(?,'shp_mock11111111','rate_mock11111111',750,'UPS','Ground','test','pending',3,'now','now')""",(a,))
db.execute("""INSERT INTO commerce_carrier_events(event_id,tracker_id,event_kind,received_at)
VALUES('evt_mock11111111','trk_mock11111111','tracker.updated','now')""")
cases=[
    ("duplicate quote shipment",lambda:db.execute("""INSERT INTO commerce_carrier_quotes(
       order_id,provider_shipment_id,mode,rates_json,parcel_json,created_at,expires_at,requested_by
       ) VALUES(?,'shp_mock11111111','test','[]','{}','now','later',3)""",(b,))),
    ("duplicate label shipment",lambda:db.execute("""INSERT INTO commerce_carrier_labels(
       order_id,provider_shipment_id,provider_rate_id,rate_cents,carrier,service,mode,requested_by,created_at,updated_at
       ) VALUES(?,'shp_mock11111111','rate_mock22222222',750,'UPS','Ground','test',3,'now','now')""",(b,))),
    ("duplicate event id",lambda:db.execute("""INSERT INTO commerce_carrier_events(event_id,tracker_id,event_kind,received_at)
       VALUES('evt_mock11111111','trk_mock22222222','tracker.updated','now')""")),
    ("orphan quote",lambda:db.execute("""INSERT INTO commerce_carrier_quotes(
       order_id,provider_shipment_id,mode,rates_json,parcel_json,created_at,expires_at,requested_by
       ) VALUES(?,'shp_mock22222222','test','[]','{}','now','later',3)""",('c'*32,))),
    ("orphan label",lambda:db.execute("""INSERT INTO commerce_carrier_labels(
       order_id,provider_shipment_id,provider_rate_id,rate_cents,carrier,service,mode,requested_by,created_at,updated_at
       ) VALUES(?,'shp_mock22222222','rate_mock33333333',750,'USPS','Priority','test',3,'now','now')""",('c'*32,)))
]
for label,fn in cases:
    try:fn();raise AssertionError('DB admitted '+label)
    except sqlite3.IntegrityError:pass
assert db.execute('SELECT COUNT(*) FROM commerce_carrier_events').fetchone()[0]==1
print('Carrier quote/label/event exact SQLite schema, idempotency and ownership PASS')
