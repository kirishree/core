#!/usr/local/sensei/py_venv/bin/python3
from ast import Try
from base64 import b64decode
from struct import unpack
import json
import os
import requests
from datetime import datetime, timedelta
import time
import sqlite3
import logging
import subprocess
import glob
from configparser import ConfigParser
import urllib3
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

SENSEI_ROOT = os.environ.get('EASTPECT_ROOT', '/usr/local/sensei')

SENSEI_DB_PKG_VERSION_FILE = "/usr/local/sensei-db/VERSION"
status, output = subprocess.getstatusoutput(f'{SENSEI_ROOT}/bin/eastpect -p')
if status == 0:
    SENSEI_DB_PKG_VERSION_FILE = output.strip() + "/VERSION"

HEARTBEAT_URL = "https://health.sunnyvalley.io/heartbeat.php"
CURRENT_VERSION = os.popen(SENSEI_ROOT + "/bin/eastpect -V | grep -i 'release' | cut -d' ' -f2").read().strip()
CURRENT_TIME = int(time.time())

AGENT_VERSION = ''
res = subprocess.run("pkg info os-sensei-agent | grep Version | awk -F ': ' '{ print $2 }'", shell=True, capture_output=True)
if res.returncode == 0:
    AGENT_VERSION = res.stdout.decode('utf-8').strip()

NODE_UUID = os.popen(SENSEI_ROOT + "/bin/eastpect -s").read().strip()

if os.path.exists(os.path.join(SENSEI_ROOT, 'log', 'active')):    
    LOG_FILE = os.path.join(SENSEI_ROOT, 'log', 'active','Senseigui.log')
else:
    LOG_FILE = '/tmp/Senseigui.log'    
logging.basicConfig(filename=LOG_FILE, level=logging.INFO, format='[%(asctime)s][%(levelname)s][TEMP] %(message)s', datefmt='%Y-%m-%dT%H:%M:%S')

EASTPECT_CFG = os.path.join(SENSEI_ROOT, 'etc', 'eastpect.cfg')
config = ConfigParser()
config.read(EASTPECT_CFG)
if config.get('Database','type'):
    DB_TYPE = config.get('Database','type')

CAMPAIGN_URL=''
if config.get('senpai','node-register-address'):
    CAMPAIGN_URL = '%s/api/v1/nodes/campaigns' % config.get('senpai','node-register-address')

PartnerID = ''
try:
    if os.path.exists(SENSEI_ROOT + "/etc/partner.json"):
        with open(SENSEI_ROOT + "/etc/partner.json") as f:
            p_content = f.read()
            p_content = json.loads(p_content)
            if 'id' in p_content:
                PartnerID = p_content['id']
except Exception as e:
    logging.info('Heaerbeat: Partner Info -> %s' % e)

EASTPECT_DB = os.path.join(SENSEI_ROOT, 'userdefined', 'config', 'settings.db')
conn = sqlite3.connect(EASTPECT_DB)
conn.row_factory = sqlite3.Row
cur_p = conn.cursor()
cur_e = conn.cursor()

values = {'NodeUuid': NODE_UUID,
          'PartnerID': PartnerID,
        'Subscription': False,
        'SubscriptionKey': '',
        'NumberOfDevices': 0,
        'Platform': os.popen('/usr/local/sbin/opnsense-version').read().strip(),
        'ZenarmorVersion':CURRENT_VERSION,
        'ZenarmorAgentVersion':AGENT_VERSION,
        'SignatureDatabaseVersion':'',
        'ReportingDatabase':DB_TYPE,
        'CloudStatus': {'Token':False,'TokenTimestamp':0 , 'ServiceStatus': False}}

STATS_DIR = os.path.join(SENSEI_ROOT, 'log','stat')
if os.path.exists(STATS_DIR):
    try:
        NumberOfDevices = 0
        workers = glob.glob('%s/worker*.stat' % STATS_DIR)
        for worker in workers:
            p = os.stat(worker)
            if int(p.st_mtime) > (int(time.time()) - 60):
                with open(worker) as f:
                    s_content = f.read()
                    s_content = json.loads(s_content)
                    if 'engine_stats' in s_content and 'devices' in s_content['engine_stats']:
                        NumberOfDevices += int(s_content['engine_stats']['devices'])

        values['NumberOfDevices'] = NumberOfDevices
    except Exception as e:
        logging.info('Heaerbeat: Stats Exception -> %s' % e)
        

if os.path.exists(SENSEI_DB_PKG_VERSION_FILE):
    with open(SENSEI_DB_PKG_VERSION_FILE, 'rb') as file:
        values['SignatureDatabaseVersion'] = file.read().decode('utf-8').strip()

TOKEN_PATH = os.path.join(SENSEI_ROOT, 'etc', 'token')
if os.path.exists(TOKEN_PATH) and os.path.getsize(TOKEN_PATH) > 0:
    values['CloudStatus']['Token'] = True
    values['CloudStatus']['TokenTimestamp'] = int(os.path.getctime(TOKEN_PATH))

if AGENT_VERSION != '':
    AGENT_SERVICE_STATUS = int(os.popen("service senpai status|grep -c 'is running'").read().strip())
    values['CloudStatus']['ServiceStatus'] = True if AGENT_SERVICE_STATUS > 0 else False

'''
AGENT_LOG_PATH = os.path.join(SENSEI_ROOT, 'log', 'active','cloud_agent.log')
if os.path.exists(AGENT_LOG_PATH) and os.path.getsize(AGENT_LOG_PATH) > 0:
    values['CloudStatus']['LastProcess'] = os.popen('tail -1 ' + AGENT_LOG_PATH).read().strip()
'''
    
data = {'premium': False}
try:
    if os.path.exists(SENSEI_ROOT + '/etc/license.data'):
        with open(SENSEI_ROOT + '/etc/license.data', 'rb') as file:
            license = file.readlines()
        packed = b64decode(license[1].rstrip())
        # l16s32sl32s16s32s
        unpacked = unpack('l64s64sl64s16s128s', packed)
        data['activation_key'] = unpacked[1].decode().replace('\x00', '')
        data['expire_time'] = int(unpacked[3])
        data['plan'] = unpacked[4].decode().replace('\x00', '')
        data['size'] = unpacked[5].decode().replace('\x00', '')
        data['premium'] = True if (data['expire_time'] + 1209600) > int(time.time()) else False
except Exception as e:
    logging.info('Heaerbeat: License read exception -> %s' % e)

if data['premium'] == True:
    values['Subscription'] = data['plan']
    values['SubscriptionKey'] = data['activation_key']

with requests.Session() as s:
    try:
        #URL = '%s?engine_version=%s&database_version=%s&license=%s&licenseKey=%s' % (HEARTBEAT_URL,CURRENT_VERSION, SENSEI_DB_PKG_VERSION,license['license'],license['licenseKey'])
        headers = {'Content-Type': 'application/json; charset=UTF-8'}
        resp = s.post(HEARTBEAT_URL, data=json.dumps(values),headers=headers,timeout=20)
        if CAMPAIGN_URL != '':
            resp = s.get(CAMPAIGN_URL, data=json.dumps(values),headers=headers,timeout=20)
            notice_data = json.loads(resp.text)
            for notice in notice_data:
                notice_time = time.mktime(datetime.strptime(notice['expirydate'], '%B %d, %Y').timetuple())
                if int(notice_time) > CURRENT_TIME:
                    cur_e.execute('select count(*) as total from user_notices where notice_name=:id',{'id': notice['id']})
                    notice_data = cur_e.fetchall()
                    if notice_data[0]['total'] == 0:
                        cur_p.execute('insert into user_notices(notice_name,notice,type) values(:notice_name,:notice,:type)',{'notice_name': notice['id'],'notice':notice['message'],'type':notice['type']})
                else:
                    cur_e.execute('update user_notices set status=1 where notice_name=:id',{'id': notice['id']})        
    except Exception as e:
        logging.info('Heaerbeat: Internal Exception -> %s' % e)

conn.commit()