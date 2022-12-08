#!/usr/local/sensei/py_venv/bin/python3
"""
    Copyright (c) 2019 Hasan UCAK <hasan@sunnyvalley.io>
    All rights reserved from Zenarmor of Opnsense
    package : configd
    function: template handler, generate configuration files using templates
"""
import os
import jinja2
import sqlite3

OPNSENSE_ROOT = '/usr/local/opnsense'
SENSEI_ROOT = '/usr/local/sensei'
EASTPECT_ROOT = '/usr/local/sensei'
if 'EASTPECT_ROOT' in os.environ:
    EASTPECT_ROOT = os.environ.get('EASTPECT_ROOT')

EASTPECT_CFG = os.path.join(EASTPECT_ROOT, 'etc', 'eastpect.cfg')
EASTPECT_DB = os.path.join(EASTPECT_ROOT, 'userdefined', 'config','settings.db')

# opnsense/service/templates/OPNsense/Sensei
template_dir = os.path.join(OPNSENSE_ROOT, 'service', 'templates','OPNsense','Sensei')
workers_file = os.path.join(SENSEI_ROOT, 'etc', 'workers.map')

conn = sqlite3.connect(EASTPECT_DB)
conn.row_factory = sqlite3.Row
cur_p = conn.cursor()

env = jinja2.Environment(loader=jinja2.FileSystemLoader(template_dir), trim_blocks=True)
template_workers = env.get_template('workers.map')

#delete old workers map
if (os.path.isfile(workers_file)):
    os.remove(workers_file)


#start new workers
interfaces = []
cur_p.execute("select * from interface_settings order by id")
for row_p in cur_p:
    hat_start = ''
    hat_end = '^'
    drive = 'netmap'
    if row_p['tags'] != None and 'wan' in row_p['tags']:
        hat_start = '^'
        hat_end = ''
        
    if row_p['mode'] == 'passive':
        drive = 'pcap'
    if (row_p['mode'] != 'bridge'):
        interfaces.append({'drive': drive,'mode': row_p['mode'],'hat_start': hat_start, 'hat_end': hat_end, 'interface': row_p['lan_interface'], 'cpu_index': row_p['cpu_index'], 'manage_port': row_p['manage_port'], 'tags': row_p['tags']})
    if (row_p['mode'] == 'bridge'):
        #wan_interface = "%s%s" % (row_p['wan_interface'], ":%s" % row_p['wan_queue'] if row_p['wan_queue'] != '' and  row_p['wan_queue'] != None else '')
        #lan_interface = "%s%s" % (row_p['lan_interface'], ":%s" % row_p['lan_queue'] if row_p['lan_queue'] != '' and  row_p['lan_queue'] != None else '')
        wan_interface = row_p['wan_interface']
        lan_interface = row_p['lan_interface']
        interfaces.append({'drive': drive, 'mode': row_p['mode'],'hat_start': hat_start, 'hat_end': hat_end, 'wan_interface': wan_interface, 'lan_interface': lan_interface, 'cpu_index': row_p['cpu_index'], 'queue': row_p['queue'], 'manage_port': row_p['manage_port'], 'tags': row_p['tags']})

content = template_workers.render({'interfaces': interfaces})
f = open(workers_file, "w+")
f.write(content)
f.close()
cur_p.close()
