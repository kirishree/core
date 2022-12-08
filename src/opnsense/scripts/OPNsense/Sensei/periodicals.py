#!/usr/local/sensei/py_venv/bin/python3
"""
    Copyright (c) 2019 Hasan UCAK <hasan@sunnyvalley.io>
    All rights reserved from Zenarmor of Opnsense
    package : configd
    function: run to special cron scripts.
"""
import sys
import os
import xml.etree.ElementTree
import lib.pycron as pycron
import logging
from logging.handlers import TimedRotatingFileHandler
from datetime import datetime, timedelta
from configparser import ConfigParser

OPNSENSE_ROOT = '/usr/local/opnsense'
SENSEI_ROOT = os.environ.get('EASTPECT_ROOT', '/usr/local/sensei')
EASTPECT_ROOT = '/usr/local/sensei'
EASTPECT_CFG = os.path.join(EASTPECT_ROOT, 'etc', 'eastpect.cfg')
LOG_FILE = os.path.join(EASTPECT_ROOT, 'log', 'active','Periodical.log')
config = ConfigParser()

config.read(EASTPECT_CFG)
dbtype = 'ES'
current_date_time = datetime.now()
if config.get('Database','type'):
    dbtype = config.get('Database','type')

if dbtype == 'MN':
    CURRENT_PATH = os.path.dirname(os.path.abspath(__file__))
    os.system(CURRENT_PATH + '/reinstall_packages.sh')        

hl = TimedRotatingFileHandler(LOG_FILE, when='midnight', interval=1, backupCount=10)
logging.basicConfig(handlers=[hl], level=logging.INFO, format='[%(asctime)s][%(levelname)s][TEMP] %(message)s', datefmt='%Y-%m-%dT%H:%M:%S')



logging.info('Starting Zenarmor CRON for %s ' % current_date_time)
if 'EASTPECT_ROOT' in os.environ:
    EASTPECT_ROOT = os.environ.get('EASTPECT_ROOT')

CONFIG_XML = '/conf/config.xml'
config_d = '/usr/local/sbin/configctl'

cron_list = [{'name':'health',
              'node': 'Sensei/general/healthCheck',
              'command': 'sensei check-health',
              'description': 'Sensei check health',
              'parameters': f'>>{LOG_FILE} 2>&1',
              'timer': 'Sensei/general/healthTimer'
             },
             {'name': 'update',
              'node': 'Sensei/updater/autocheck',
              'command': 'sensei check-updates cron',
              'description': 'Sensei check updates',
              'parameters': f'>>{LOG_FILE} 2>&1',
              'hours': '*',
              'minutes': '0'
              },
             {'name': 'retire',
              'node': True,
              'command': 'sensei datastore-retire',
              'description': 'Sensei datastore retire',
              'parameters': f'{dbtype} >>{LOG_FILE} 2>&1',
              'hours': '*',
              'minutes': '0'
              },
             {'name': 'userenrich',
              'node': True,
              'command': 'sensei userenrich',
              'description': 'Sensei user endrich',
              'parameters': f'>>{LOG_FILE} 2>&1',
              'hours': '*',
              'minutes': '*'
              },
             {'name': 'userenrichExpired',
              'node': True,
              'command': 'sensei userenrich-expire',
              'description': 'Delete user endrich in expired',
              'parameters': f'>>{LOG_FILE} 2>&1',
              'hours': '4',
              'minutes': '0'
              },
             {'name': 'reports',
              'node': 'Sensei/reports/generate/enabled',
              'command': 'sensei mail-reports',
              'description': 'Sensei email scheduled reports',
              'parameters': f'>>{LOG_FILE} 2>&1',
              'timer': 'Sensei/reports/generate/timer'
              },
             {'name': 'licensechecking',
              'node': True,
              'command': 'sensei license-check',
              'description': 'Sensei license checking with expire time',
              'parameters': f'>>{LOG_FILE} 2>&1',
              'hours': '19,23,4,9,14',
              'minutes': '0'
              },
             {'name': 'schedule-delete-ip',
              'node': True,
              'command': 'sensei delete-ip',
              'description': 'Sensei delete all data match to ip from report database.',
              'parameters': f'>>{LOG_FILE} 2>&1',
              'hours': '3',
              'minutes': '0'
              },
             {'name': 'hearbeat',
              'node': True,
              #'node': 'Sensei/general/heartbeatMonit',
              'command': 'sensei heartbeat',
              'description': 'check system heartbeat.',
              'parameters': f'>>{LOG_FILE} 2>&1',
              'timer': 'Sensei/general/heartbeatTimer'
              },
              {'name': 'aliases',
              'node': 'Sensei/dnsEncrihmentConfig/aliases',
              'command': 'sensei aliases',
              'description': 'Aliases recognize.',
              'parameters': f'>>{LOG_FILE} 2>&1',
              'hours': '*',
              'minutes': '*'
              },
             {'name': 'log-delete',
              'node': True,
              'command': 'sensei log-delete',
              'description': 'Sensei delete log file older then retire days.',
              'parameters': f'>>{LOG_FILE} 2>&1',
              'hours': '2',
              'minutes': '0'
              },
             ]


def my_cron(node,timer):
    try:
        logging.info('Cron Check : %s' % node['name'])
        if (timer != '' and pycron.is_now(timer,current_date_time)) or (timer == '' and pycron.is_now('%s %s * * *' % (node['minutes'],node['hours']),current_date_time)):
            logging.info('Running Command: %s %s' % (node['command'],node['parameters']))
            os.system('%s %s %s;echo %s>>%s' % (config_d, node['command'],node['parameters'],node['command'],LOG_FILE))
    except Exception as e:
      logging.error(f'Exception {repr(e)}')

config_tree= xml.etree.ElementTree.parse(CONFIG_XML)
for cron in cron_list:
    timer = ''
    if cron.get('timer') != None:
        for t in config_tree.findall('.//%s' % cron['timer']):
            timer = t.text
    if cron['node'] == True:
        my_cron(cron,timer)
    else:
        for node in config_tree.findall('.//%s' % cron['node']):
            if node.text == 'true':
                my_cron(cron,timer)
print('OK')