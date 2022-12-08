#!/usr/local/sensei/py_venv/bin/python3
import json
import os
import sys
import requests
from datetime import datetime
import logging
from logging.handlers import TimedRotatingFileHandler
import time
import subprocess
from packaging import version

SENSEI_ROOT = os.environ.get('EASTPECT_ROOT', '/usr/local/sensei')
OPNSENSE_ROOT = os.environ.get('OPNSENSE_ROOT', '/usr/local/opnsense')
EASTPECT_DB = os.path.join(SENSEI_ROOT, 'userdefined', 'config', 'settings.db')
LOG_FILE = os.path.join(SENSEI_ROOT, 'log', 'active', 'update_check.log')
update_db_uri = 'https://updates.sunnyvalley.io/updates/db/1.12.1/version_history.json';

DEV_DB_URI_CONF = '/root/.sensei_db.conf'
if os.path.exists(DEV_DB_URI_CONF):
    with open(DEV_DB_URI_CONF, 'rb') as file:
        update_db_uri = file.read().strip().decode("utf-8") + 'version_history.json'

now = datetime.now()
hl = TimedRotatingFileHandler(LOG_FILE, when='midnight', interval=1, backupCount=10)
logging.basicConfig(handlers=[hl], level=logging.INFO, format='[%(asctime)s][%(levelname)s][TEMP] %(message)s', datefmt='%Y-%m-%dT%H:%M:%S')
logging.info('Starting Update DB check: %s ' % now)

sensei_db_pkg_version=""
db_version = ''
status, output = subprocess.getstatusoutput(f'{SENSEI_ROOT}/bin/eastpect -p')
if status == 0:
    sensei_db_pkg_version = output.strip() + "/VERSION"

try:
    if os.path.exists(sensei_db_pkg_version):
        with open(sensei_db_pkg_version, 'rb') as file:
            db_version = file.read().strip()
    else:
        exit(1)
        
    with requests.Session() as s:
            resp = s.get(update_db_uri,timeout=10)
            if resp.status_code == 200:
                response = json.loads(resp.text)
            else:
                logging.info('Http Response Code: %s%s' % (update_db_uri,resp.status_code))                    
                exit()

    if len(sys.argv) == 1:
        curr_version = [a for a in response['versions'] if a['current'] == 'yes'][0]
        '''
        cver = curr_version['version']
        cver = cver[cver.rindex('.')+1:cver.rindex('.')+9]
        cver = datetime.strptime(cver,'%y%m%d%H' if cver[0:2] == '21' else '%Y%m%d')
        '''
        dbver = db_version.decode("utf-8")
        '''
        if '.' in dbver:
            dbver = dbver[dbver.rindex('.')+1:dbver.rindex('.')+9]
        else:
            dbver = dbver[:8]
        dbver = datetime.strptime(dbver,'%y%m%d%H' if dbver[0:2] == '21' else '%Y%m%d')
        '''
        if version.parse(curr_version['version']) > version.parse(dbver):
            print('%s:%s' % (curr_version['version'],curr_version['file']))
            logging.info('File:%s' % curr_version['file'])
            exit(1)        

    if len(sys.argv) > 1:
        curr_version = [a for a in response['versions'] if a['version'] == sys.argv[1]]
        if len(curr_version) > 0:
            print('%s:%s' % (curr_version[0]['version'],curr_version[0]['file']))
            logging.info('File:%s' % curr_version[0]['file'])
            exit(1)        

except Exception as e:
    print('External Exception : %s' % e)
    logging.info('Update db Exception : %s' % e)
