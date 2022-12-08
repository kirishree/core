#!/usr/local/sensei/py_venv/bin/python3
import sys
from configparser import ConfigParser
import logging
from logging.handlers import TimedRotatingFileHandler
from logging.config import dictConfig
from datetime import datetime, timedelta
import requests
import json
import os
import time
from base64 import b64decode
from requests.auth import HTTPBasicAuth
import urllib3
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

EASTPECT_ROOT = '/usr/local/sensei'
if 'EASTPECT_ROOT' in os.environ:
    EASTPECT_ROOT = os.environ.get('EASTPECT_ROOT')

EASTPECT_CFG = os.path.join(EASTPECT_ROOT, 'etc', 'eastpect.cfg')
INDICES = []

LOGGING_CONFIG = {
    'formatters': {
        'brief': {
            'format': '%(asctime)s - %(levelname)s - %(message)s',
            'datefmt': '%Y.%m.%d - %H:%M:%S'
        },
    },
    'handlers': {
        'console': {
            'class': 'logging.StreamHandler',
            'level': 'DEBUG',
            'formatter': 'brief'
        },
        'file': {
            'class': 'logging.handlers.TimedRotatingFileHandler',
            'level': 'DEBUG',
            'formatter': 'brief',
            'filename': os.path.join(EASTPECT_ROOT, 'log', 'active', 'ipdr_retire.log'),
            'when': 'midnight',
            'interval': 1,
            'backupCount': 10
        }
    },
    'loggers': {
        'ipdr retire manager': {
            'propagate': False,
            'handlers': ['console', 'file'],
            'level': 'DEBUG'
        }
    },
    'version': 1
}

config = ConfigParser()
config.read(EASTPECT_CFG)

reportUri = '%s:%s/' % (config.get('ElasticSearch', 'apiEndPointIP'),config.get('ElasticSearch', 'apiEndPointPort'))
reportVersion = config.get('ElasticSearch', 'apiEndPointVersion')
retireAfter = int(config.get('Database','retireAfter'))
headers = {'Content-Type': 'application/json; charset=UTF-8'}

INDICES.append(config.get('ElasticSearch','connIndex'))
INDICES.append(config.get('ElasticSearch','dnsIndex'))
INDICES.append(config.get('ElasticSearch','tlsIndex'))
INDICES.append(config.get('ElasticSearch','sipIndex'))
INDICES.append(config.get('ElasticSearch','alertIndex'))
INDICES.append(config.get('ElasticSearch','httpIndex'))

new_prefix = sys.argv[1]

PREFIX = config.get('ElasticSearch', 'apiEndPointPrefix')
INDICES_OLD = ['%s%s' % (PREFIX,x) for x in INDICES]
INDICES_NEW = ['%s%s' % (new_prefix,x) for x in INDICES]

ES_USER = config.get('ElasticSearch', 'apiEndPointUser')
ES_PASS = config.get('ElasticSearch', 'apiEndPointPass')

if ES_PASS != '' and ES_PASS[0:4] == 'b64:':
    ES_PASS = b64decode(config.get('ElasticSearch', 'apiEndPointPass')[4:None]).decode('utf-8')

ES_AUTH = None
if ES_USER != '' and ES_PASS != '':
    ES_AUTH = HTTPBasicAuth(ES_USER, ES_PASS)

dictConfig(LOGGING_CONFIG)
logger = logging.getLogger('ipdr retire manager')
logger.info('[main] Starting ipdr retiring for ELASTICSEARCH...')

def _rename_alias(aName,bName):
    resp = requests.get(reportUri + '/_alias/%s' % aName, headers=headers, timeout=30,verify=False,auth=ES_AUTH)    
    new_alias_data = [] 
    str = resp.content
    y = json.loads(str)
    for _,k in enumerate(y):
        new_alias_data.append({"add":{"index": k,"alias": bName}})

    new_alias_data = {"actions":new_alias_data}
    resp = requests.post(reportUri + '_aliases', headers=headers, data=json.dumps(new_alias_data),timeout=30,verify=False,auth=ES_AUTH)
    if resp.status_code < 210:
        logger.info('[%s] Create New Alias for %s -> %s' % (aName, bName , json.dumps(new_alias_data)))
    if resp.status_code > 299:    
        logger.info('[%s] ERROR New Alias for %s -> %s -Error:%s' % (aName, bName , json.dumps(new_alias_data),resp.content))


def rename_alias(x):
    oldName = INDICES_OLD[x]
    newName = INDICES_NEW[x]
    _rename_alias('%s_all' % oldName, '%s_all' % newName)
    _rename_alias('%s_write' % oldName, '%s_write' % newName)

for x,i in enumerate(INDICES_OLD):
    logger.info('[%s] Rename alias to %s ' % (INDICES_OLD[x],INDICES_NEW[x]))
    rename_alias(x)

logger.info('[main] ipdr check external elasticsearch.')
if config.get('StreamReportExternal', 'enabled') == 'true':
    reportUri = '%s/' % config.get('StreamReportExternal', 'uri')
    reportVersion = config.get('StreamReportExternal', 'version')
    STREAM_EXTERNAL_USER = config.get('StreamReportExternal', 'uriUser')
    STREAM_EXTERNAL_PASS = config.get('StreamReportExternal', 'uriPass')
    if STREAM_EXTERNAL_PASS != '' and STREAM_EXTERNAL_PASS[0:4] == 'b64:':
        STREAM_EXTERNAL_PASS = b64decode(config.get('StreamReportExternal', 'uriPass')[4:None]).decode('utf-8')

    ES_AUTH = None
    if STREAM_EXTERNAL_USER != '' and STREAM_EXTERNAL_PASS != '':
        ES_AUTH = HTTPBasicAuth(STREAM_EXTERNAL_USER, STREAM_EXTERNAL_PASS)
    for x,i in enumerate(INDICES_OLD):
        logger.info('[%s] Rename alias to %s for EXTERNAL STREAM REPORT' % (INDICES_OLD[x],INDICES_NEW[x]))
        rename_alias(x)

logger.info('[main] rename alias manager finished.')

