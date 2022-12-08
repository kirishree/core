#!/usr/local/sensei/py_venv/bin/python3
"""
    Copyright (c) 2019 Hasan UCAK <hasan@sunnyvalley.io>
    All rights reserved from Zenarmor of Opnsense
    redefine sort numbers of policies
"""
import os
import sqlite3

EASTPECT_ROOT = '/usr/local/sensei'

EASTPECT_DB_DIR = os.path.join(EASTPECT_ROOT, 'userdefined', 'config')
EASTPECT_DB = os.path.join(EASTPECT_DB_DIR, 'settings.db')

conn = sqlite3.connect(EASTPECT_DB)
conn.row_factory = sqlite3.Row
cur_p = conn.cursor()
cur_e = conn.cursor()

cur_p.execute('SELECT id,sort_number from policies order by sort_number')
policies = cur_p.fetchall()

counter = 0
for policy in policies:
    # Create or select application category id
    cur_p.execute('update policies set sort_number=:sort_number where id=:id', {
        'sort_number': counter, 'id': policy['id']
    })
    counter = counter + 1 

cur_p.execute('update policies set sort_number=(select max(sort_number)+1 from policies) where id=0')
conn.commit()
conn.close()