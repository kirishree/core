#!/usr/local/sensei/py_venv/bin/python3
import sqlite3
import json
import os
import sys
import requests
from decimal import Decimal
import xml.etree.ElementTree
import time
import logging
from datetime import datetime
import urllib3
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

if 'EASTPECT_ROOT' in os.environ:
    EASTPECT_ROOT = os.environ.get('EASTPECT_ROOT')
else:
    EASTPECT_ROOT = '/usr/local/sensei'

CAPTIVE_DB_DIR = '/var/captiveportal/captiveportal.sqlite'
CAPTIVE_TMP = '/tmp/captive_list'
TOKEN_FILE = '/usr/local/sensei/userdefined/db/Usercache/tokens.json'
LAST_ID_FILE = '/tmp/captive_last_id'
CONFIG_XML = '/conf/config.xml'

# logging.info('Starting Userenricher')
LOG_FILE = os.path.join(EASTPECT_ROOT, 'log', 'active','Senseigui.log')
logging.basicConfig(filename=LOG_FILE, level=logging.INFO, format='[%(asctime)s][%(levelname)s][TEMP] %(message)s', datefmt='%Y-%m-%dT%H:%M:%S')

def getUrl():
    for webgui in config_tree.findall('.//system/webgui'):
        protocol = webgui.find('protocol').text
        port = webgui.find('port').text
    return '%s://localhost%s%s' % (protocol,':' + port if port != None else '','/api/sensei/userenricher')

def findGroups(username):
    userid = 0
    groups = []
    for user in config_tree.findall('.//system/user'):
        if user.find('name').text == username:  
           userid = user.find('uid').text
           break
    for group in config_tree.findall('.//system/group'):
        for member in group.findall('.//member'):
            if member.text == userid:
                groups.append(group.find('name').text)
    return ",".join(groups)

if (not os.path.isfile(CAPTIVE_DB_DIR)):
    # print('Captive Portal is not active')
    # logging.error('Captive Portal is not active')
    sys.exit(0)
try:
    auth_token = ''
    if (os.path.isfile(TOKEN_FILE)):
        with open(TOKEN_FILE) as file:
            tokens = json.load(file)
        for token in tokens:
            if 'status' in token and token['status'] == "true":
                auth_token = token['token']

    conn = sqlite3.connect(CAPTIVE_DB_DIR)
    conn.row_factory = sqlite3.Row
    cur_c = conn.cursor()
    cur_e = conn.cursor()

    start_id = '0'
    if (os.path.isfile(LAST_ID_FILE)):
        with open(LAST_ID_FILE) as file:
            start_id = file.readline().rstrip()

    headers = {'Content-Type': 'application/json; charset=UTF-8', 'Authorization': auth_token}

    # for deleted users
    new_list = []

    #get captive settings.
    config_tree= xml.etree.ElementTree.parse(CONFIG_XML)
    parameters = []
    for node in config_tree.findall('.//OPNsense/captiveportal/zones/zone'):
        idletimeout = 0
        if node.find('idletimeout') is not None:
            idletimeout = node.find('idletimeout').text
        idletimeout = (30 * 24 * 60 * 60) if int(idletimeout) == 0 else (int(idletimeout) * 60)
        hardtimeout = 0
        if node.find('hardtimeout') is not None:
            hardtimeout = node.find('hardtimeout').text
        hardtimeout = (30 * 24 * 60 * 60) if int(hardtimeout) == 0 else (int(hardtimeout) * 60)
        realtimeout = idletimeout if int(idletimeout)>int(hardtimeout) else hardtimeout
        realtimeout = int(time.time()) - int(realtimeout)
        zoneid = 0
        if node.find('zoneid') is not None:
            zoneid = node.find('zoneid').text
        parameters.append('(zoneid=%s and created>%s)' % (zoneid, realtimeout))
    if len(parameters) == 0:
        exit()
    cur_e.execute("select * from cp_clients where deleted=0 and (%s) order by created" % ' or '.join(parameters))
    for row_e in cur_e:
        if row_e['sessionid'] is not None:
            new_list.append(row_e['sessionid'])

    if (os.path.isfile(CAPTIVE_TMP)):
        with open(CAPTIVE_TMP) as f:
            lineList = f.readlines()
        for line in lineList:
            if line.rstrip() not in new_list:
                with requests.Session() as s:
                    data = {"logonid": line.rstrip(), "username": '', "ip": '', "action": 'logout'}
                    resp = s.post(getUrl(), headers=headers, data=json.dumps(data), timeout=10, verify=False)
        os.remove(CAPTIVE_TMP)

    #create new list.
    with open(CAPTIVE_TMP,"w+") as f:
        f.write("\n".join(new_list))

    # for new connections
    cur_c.execute("select * from cp_clients where created>%s and deleted=0 order by created" % start_id)
    with requests.Session() as s:
        for row_c in cur_c:
            action = 'login'
            groups = ''
            if row_c['deleted'] == 1:
                action = 'logout'

            if action == 'login' and row_c['authenticated_via'] is not None and row_c['authenticated_via'] == 'Local Database':
                groups = findGroups(row_c['username'])

            if row_c['sessionid'] is not None and row_c['username'] is not None and row_c['ip_address'] is not None:
                data = {"logonid": row_c['sessionid'], "username": row_c['username'], "groups": groups, "ip": row_c['ip_address'], "action": action}
                resp = s.post(getUrl(), headers=headers, data=json.dumps(data), timeout=10, verify=False)
            
            if row_c['created'] is not None:
                start_id=Decimal(row_c['created'])

    with open(LAST_ID_FILE,"w+") as f:
        f.write(str(start_id))
    cur_c.close()
    cur_e.close()
    conn.close()

except Exception as e:
    logging.error('ERROR: Userenrich %s' % e)

print('OK')
