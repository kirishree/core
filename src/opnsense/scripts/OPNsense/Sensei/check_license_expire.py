#!/usr/local/sensei/py_venv/bin/python3
from base64 import b64decode
from struct import unpack
import json
import os
import requests
from datetime import datetime, timedelta
import time
import jinja2
import hashlib
import sqlite3
import logging
from logging.handlers import TimedRotatingFileHandler
import urllib3
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

SENSEI_ROOT = os.environ.get('EASTPECT_ROOT', '/usr/local/sensei')
OPNSENSE_ROOT = os.environ.get('OPNSENSE_ROOT', '/usr/local/opnsense')
EASTPECT_DB = os.path.join(SENSEI_ROOT, 'userdefined', 'config', 'settings.db')
LOG_FILE = os.path.join(SENSEI_ROOT, 'log', 'active', 'license_check.log')
WARNING_FILE = '/tmp/license_warning'
CONFIG_XML = '/conf/config.xml'

license_server = 'https://license.sunnyvalley.io'
new_license_data = '/tmp/new_license.data'
check_license = '/usr/local/sbin/configctl sensei license'
cli_license = '/usr/local/opnsense/mvc/app/models/OPNsense/Sensei/CLI.php licensedel'
cli_license_activation = '/usr/local/opnsense/mvc/app/models/OPNsense/Sensei/CLI.php licenseActivation'
license_exp_template_dir = '/usr/local/opnsense/service/templates/OPNsense/Sensei'
license_revoked_message = "<p><strong>Your Premium subscription had revoked.</strong> Today, we\'ve downgraded your Subscription to Free Edition.</p><p>You can always re-purchase your Zenarmor Premium Subscription. In the meantime, you can enjoy Zenarmor with Free features.</p>"
license_canceled_message = "<p><strong>Your Premium subscription have been cancelled.</strong> Today, we\'ve downgraded your Subscription to Free Edition.</p><p>You can always re-purchase your Zenarmor Premium Subscription. In the meantime, you can enjoy Zenarmor with Free features.</p>"
license_conflict_message = "<p><strong>This license is active on another device.</strong> Today, we\'ve downgraded your Subscription to Free Edition.</p><p>You can always re-purchase your Zenarmor Premium Subscription. In the meantime, you can enjoy Zenarmor with Free features.</p>"

conn = sqlite3.connect(EASTPECT_DB)
conn.row_factory = sqlite3.Row
cur_p = conn.cursor()

env = jinja2.Environment(loader=jinja2.FileSystemLoader(license_exp_template_dir), trim_blocks=True)
template_exp = env.get_template('license_expired.temp')
template_exp_warning = env.get_template('license_expired_warning.temp')

data = {'premium': False}
headers = {'Content-Type': 'application/json; charset=UTF-8'}
now = datetime.now()

hl = TimedRotatingFileHandler(LOG_FILE, when='W0', interval=1, backupCount=10)
logging.basicConfig(handlers=[hl], level=logging.INFO, format='[%(asctime)s][%(levelname)s][TEMP] %(message)s', datefmt='%Y-%m-%dT%H:%M:%S')
logging.info('Starting license check: %s ' % now)
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
        data['extdata'] = unpacked[6].decode().replace('\x00', '')
        data['premium'] = True if (data['expire_time'] + 1209600) > int(time.time())  else False
        expire_date = unpacked[3]
        activation_key = data['activation_key']
        cmdResult = os.popen('/usr/local/sensei/bin/eastpect -s').read()
        hwfingerprint = cmdResult.strip()
        license_expired = False
        license_status = "OK";
        with requests.Session() as s:
            data = {"api_key": 'HDNDHDN87763hh737', "hwfingerprint": hwfingerprint, "activation_key": activation_key}
            try:
                resp = s.post(license_server + '/api/v2/license/check', headers=headers, data=json.dumps(data), timeout=10, verify=False)
                logging.info('Result of License: %s' % resp.text)
                if resp.status_code == 200:
                   ret_data = json.loads(resp.text)
                   if ret_data['successful']:
                        logging.info('Return is Success')
                        if 'license' in ret_data:
                            f = open(new_license_data,'w+')
                            f.write(resp.text)
                            f.close()
                            output_new_license = os.system(cli_license_activation)
                            logging.info('created new license')
                            exit(0)

                        if 'expires_at_ts' in ret_data:
                            expires_at = int(ret_data['expires_at_ts']) + (14 * 24 * 60 * 60)
                            w_expires_at = int(ret_data['expires_at_ts']) + (60 * 60)
                            now = int(time.time())
                        else:    
                            if len(ret_data['expires_at']) == 19:
                                ret_data['expires_at'] = ret_data['expires_at'] + '.000000'    
                            expires_at = datetime.strptime(ret_data['expires_at'],'%Y-%m-%dT%H:%M:%S.%f') + timedelta(days=14)
                            w_expires_at = datetime.strptime(ret_data['expires_at'],'%Y-%m-%dT%H:%M:%S.%f') + timedelta(days=1)

                        if w_expires_at < now and expires_at > now:
                            logging.info('license was expired (warning)')
                            cur_p.execute("select * from user_notices where notice_name='license_expired_warning' and create_date>:now_date",{'now_date': time.strftime('%Y-%m-%d')})
                            row = cur_p.fetchone()
                            if row is None:
                                content = template_exp_warning.render({'expire_date': w_expires_at.strftime('%B %d,%Y') })
                                cur_p.execute("insert into user_notices(notice_name,notice,create_date) values(:notice_name,:notice,datetime('now'))",{'notice_name': 'license_expired_warning','notice': content})
                                conn.commit()
                        if expires_at < now:
                            logging.info('license was expired (EXPIRED)')
                            license_status = "EXPIRED"
                            license_expired = True
                            cur_p.execute("select * from user_notices where notice_name='license_expired' and create_date>:now_date",{'now_date': time.strftime('%Y-%m-%d')})
                            row = cur_p.fetchone()
                            if row is None:
                                content = template_exp.render({'expire_date': w_expires_at.strftime('%B %d,%Y') })
                                cur_p.execute("insert into user_notices(notice_name,notice,create_date) values(:notice_name,:notice,datetime('now'))",{'notice_name': 'license_expired','notice': content})
                                conn.commit()
                   else:
                       logging.info('license Not found')
                       license_status = "NOT_FOUND"
                       if ret_data['message'] == 'HW fingerprint conflict':
                            cur_p.execute("select * from user_notices where notice_name='license_conflict' and status=0")
                            row = cur_p.fetchone()
                            if row is None:
                                cur_p.execute("insert into user_notices(notice_name,notice,create_date) values(:notice_name,:notice,datetime('now'))",{'notice_name': 'license_conflict','notice': license_conflict_message})
                                conn.commit()
                       license_expired = True
                   if license_expired:
                       if ret_data['status'] == 9:
                           logging.info('license was Revoked')
                           cur_p.execute("select * from user_notices where notice_name='license_revoked' and status=0")
                           row = cur_p.fetchone()
                           if row is None:
                               cur_p.execute("insert into user_notices(notice_name,notice,create_date) values(:notice_name,:notice,datetime('now'))",{'notice_name': 'license_revoked','notice': license_revoked_message})
                               conn.commit()
                           license_status = "REVOKED"
                           print('License Revoked')
                       if ret_data['status'] == 101:
                           logging.info('license was canceled')
                           cur_p.execute("select * from user_notices where notice_name='license_canceled' and status=0")
                           row = cur_p.fetchone()
                           if row is None:
                               cur_p.execute("insert into user_notices(notice_name,notice,create_date) values(:notice_name,:notice,datetime('now'))",{'notice_name': 'license_canceled','notice': license_canceled_message})
                               conn.commit()
                           license_status = "CANCELED"
                           print('License Canceled 101')
                       print('License Canceled')
                       output_cli_license = os.system(cli_license)
                       output_check_license = os.system(check_license)
                       print('Checked License cli Return: %s' % output_cli_license)
                       print('Checked License Return: %s' % output_check_license)
                       logging.info('license was Deleted')
            except Exception as e:
                print('Internal Exception : %s' % e)
                logging.info('license Internal Exception : %s' % e)
                
    else:
        print('License doesn\'t exists')
        logging.info('license doesnt exists')
except Exception as e:
    print('External Exception : %s' % e)
    logging.info('license External Exception : %s' % e)

logging.info('End license check')