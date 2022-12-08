#!/usr/local/sensei/py_venv/bin/python3
import argparse
import json
import sys
import os
from pythonping import ping

'''
write: query nodes, write nodes.csv and cached json file.
rewrite: just edit cached json file after node selection.
read: just read cached json file. if it does not exist, query nodes and write cached json file. (nodes.csv will not be rewritten)
recheck: query nodes and update cached json file (nodes.csv will not be rewritten)
'''

SENSEI_ROOT = os.environ.get('EASTPECT_ROOT', '/usr/local/sensei')
NODES_ALL = os.path.join(SENSEI_ROOT, 'db', 'Cloud', 'cloud.db.all')
NODES_CFG = os.path.join(SENSEI_ROOT, 'db', 'Cloud', 'nodes.csv')
STATUS_CACHE = '/tmp/sensei_nodes_status.json'
NODE_LIST = []
NODE_CUR = ''
NODE_CUR_LIST = []
NODES = {
    'successful': True,
    'availables': [],
    'unavailables': []
}

parser = argparse.ArgumentParser()
parser.add_argument('-m', '--mode', type=str, default='write')
args = parser.parse_args()

if os.path.exists(NODES_CFG):
    with open(NODES_CFG, 'r') as file:
        NODE_CUR = file.readlines()

for NODE in NODE_CUR:
    NODE_CUR_LIST.append(NODE.split(',')[0])


if args.mode == 'read' and os.path.exists(STATUS_CACHE):
    with open(STATUS_CACHE, 'r') as file:
        print(file.read())
    sys.exit()

if args.mode == 'rewrite':
    if os.path.exists(STATUS_CACHE):
        with open(STATUS_CACHE, 'r') as file:
            CURRENT_CFG = json.load(file)
        for n in CURRENT_CFG['availables']:
            n['enabled'] = n['name'] in NODE_CUR_LIST
        for n in CURRENT_CFG['unavailables']:
            n['enabled'] = n['name'] in NODE_CUR_LIST
        with open(STATUS_CACHE, 'w') as file:
            json.dump(CURRENT_CFG, file)
    sys.exit()


with open(NODES_ALL, 'r') as file:
    for row in file.readlines():
        recs = row.split(',')
        if len(recs) > 2:
            if len(recs) == 5:
                port_tmp = int(recs[2].rstrip())
                port = 5353 if port_tmp == 53 else port_tmp
                NODE_LIST.append({
                    'name': recs[0],
                    'ip': recs[1],
                    'port': port,
                    'response_times': [],
                    'response_time_average': 0,
                    'query_error': None,
                    'available': True,
                    'type': 4,
                    'enabled': recs[0] in NODE_CUR_LIST
                })
            # there is ipv6 format.
            if len(recs) == 6:
                port_tmp = int(recs[3].rstrip())
                port = 5353 if port_tmp == 53 else port_tmp
                NODE_LIST.append({
                    'name': recs[0],
                    'ip': recs[1],
                    'port': port,
                    'response_times': [],
                    'response_time_average': 0,
                    'query_error': None,
                    'available': True,
                    'type': 4,
                    'enabled': recs[0] in NODE_CUR_LIST
                })
                NODE_LIST.append({
                    'name': recs[0],
                    'ip': recs[2],
                    'port': port,
                    'response_times': [],
                    'response_time_average': 0,
                    'query_error': None,
                    'available': True,
                    'type': 6,
                    'enabled': recs[0] in NODE_CUR_LIST
                })

for node in NODE_LIST:
    try:
        response_list = ping(node['ip'], size=40, count=2,timeout=3)
        if response_list.rtt_avg_ms != '3000.0':
            node['response_time_average'] = response_list.rtt_avg_ms
            NODES['availables'].append(node)
        else:
            node['available'] = False
            NODES['unavailables'].append(node)
    except Exception as e:
        node['available'] = False
        NODES['unavailables'].append(node)

NODES['availables'].sort(key=lambda c: c['response_time_average'])

if args.mode == 'write':
    with open(NODES_CFG, 'w') as file:
        for node in NODES['availables'][:2]:
            node['enabled'] = True
            file.write('%s,%s,%d\n' % (node['name'], node['ip'], node['port']))

    for node in NODES['availables'][2:]:
        node['enabled'] = False

    for node in NODES['unavailables']:
        node['enabled'] = False

with open(STATUS_CACHE, 'w') as file:
    json.dump(NODES, file)
print(json.dumps(NODES))
