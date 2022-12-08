#!/usr/local/sensei/py_venv/bin/python3
"""
    Copyright (c) 2019 Hasan UCAK <hasan@sunnyvalley.io>
    All rights reserved from Zenarmor of Opnsense
    migration to every old version to 0.8.beta9
    check app category and web category of policies.
"""
import os
from subprocess import PIPE, Popen
import sqlite3

EASTPECT_ROOT = '/usr/local/sensei'

EASTPECT_DB_DIR = os.path.join(EASTPECT_ROOT, 'userdefined', 'config')
EASTPECT_DB = os.path.join(EASTPECT_DB_DIR, 'settings.db')
notice_message = '<p>It looks like you have jumbo frames enabled for __interface__ interface. We do not support jumbo frames at this moment. Kindly disable jumbo frames for this interface if you want it protected by Zenarmor.</p><p>Please see related FAQ here:</p>';
notice_message = notice_message + '<p><a target="_blank" href="https://www.sunnyvalley.io/docs/opnsense/articles/360025100613-FAQ">https://www.sunnyvalley.io/docs/opnsense/articles/360025100613-FAQ</a></p>'

notice_nonmessage = "<p>Interface __interface__ seems to be removed from your OS configuration but it is set to be protected by Zenarmor. Please remove it from Zenarmor's protected interfaces or re-configure the interface in the OS side</p>";
notice_nonmessage = notice_nonmessage + '<p><a target="_blank" href="/ui/sensei/#/configuration">zenarmor configuration</a></p>'

conn = sqlite3.connect(EASTPECT_DB)
conn.row_factory = sqlite3.Row
cur_e = conn.cursor()

cur_e.execute('select lan_interface,wan_interface from interface_settings')
interfaces = cur_e.fetchall()
interface_list=[]
interface_nonlist=[]
for interface in interfaces:
    try:
        #mtu_s = os.popen('/sbin/ifconfig %s| grep -i mtu | head -1 | awk -F\'mtu \' \'{ print $2 }\'' % interface['lan_interface']).read()
        p = Popen('/sbin/ifconfig %s| grep -i mtu | head -1 | awk -F\'mtu \' \'{ print $2 }\'' % interface['lan_interface'], shell=True, stdout=PIPE, stderr=PIPE)
        mtu_s, stderr = p.communicate()
        if 'does not exist' in str(stderr):
            interface_nonlist.append(interface['lan_interface'])
        mtu = int(mtu_s)
        if mtu > 1500:
            interface_list.append(interface['lan_interface'])
        if interface['wan_interface'] is not None:   
            #mtu_s = os.popen('/sbin/ifconfig %s| grep -i mtu | head -1 | awk -F\'mtu \' \'{ print $2 }\'' % interface['wan_interface']).read()
            p = Popen('/sbin/ifconfig %s| grep -i mtu | head -1 | awk -F\'mtu \' \'{ print $2 }\'' % interface['wan_interface'], shell=True, stdout=PIPE, stderr=PIPE)
            mtu_s, stderr = p.communicate()
            if 'does not exist' in str(stderr):
                interface_nonlist.append(interface['wan_interface'])
            mtu = int(mtu_s)
            if mtu > 1500:
                interface_list.append(interface['wan_interface'])
    except Exception as e:
        pass
if len(interface_list) > 0:
    notice_message = notice_message.replace('__interface__',','.join(interface_list))
    cur_e.execute("select * from user_notices where notice_name='mtu_notice' and status=0")
    row = cur_e.fetchone()
    if row is None:
        cur_e.execute("insert into user_notices(notice_name,notice,create_date) values(:notice_name,:notice,datetime('now'))",{'notice_name': 'mtu_notice','notice': notice_message})
        conn.commit()

if len(interface_nonlist) > 0:
    notice_nonmessage = notice_nonmessage.replace('__interface__',','.join(interface_nonlist))
    cur_e.execute("select * from user_notices where notice_name='if_nonexists' and notice like :notice",{'notice': notice_nonmessage})
    row = cur_e.fetchone()
    if row is None:
        cur_e.execute("insert into user_notices(notice_name,notice,create_date) values(:notice_name,:notice,datetime('now'))",{'notice_name': 'if_nonexists','notice': notice_nonmessage})
        conn.commit()
