#!/usr/local/sensei/py_venv/bin/python3
import sys
from configparser import ConfigParser
import logging
from logging.config import dictConfig
from datetime import datetime, timedelta
import requests
import json
import os
import time
from requests.auth import HTTPBasicAuth
from base64 import b64decode

EASTPECT_ROOT = '/usr/local/sensei'
if 'EASTPECT_ROOT' in os.environ:
    EASTPECT_ROOT = os.environ.get('EASTPECT_ROOT')

EASTPECT_CFG = os.path.join(EASTPECT_ROOT, 'etc', 'eastpect.cfg')
INDICES = []

config = ConfigParser()

if len(sys.argv) < 2:
    logging.info('must be least one [ip] argument...')
    sys.exit(1)

LOG_FILE = os.path.join(EASTPECT_ROOT, 'log', 'active','Senseigui.log')
logging.basicConfig(filename=LOG_FILE, level=logging.INFO, format='[%(asctime)s][%(levelname)s][TEMP] %(message)s', datefmt='%Y-%m-%dT%H:%M:%S')
logging.info('[main] Starting elasticsearch deleting with ip...')

config.read(EASTPECT_CFG)
ES_USER = config.get('ElasticSearch', 'apiEndPointUser')
ES_PASS = config.get('ElasticSearch', 'apiEndPointPass')

if ES_PASS != '' and ES_PASS[0:4] == 'b64:':
    ES_PASS = b64decode(config.get('ElasticSearch', 'apiEndPointPass')[4:None]).decode('utf-8')

ES_AUTH = None
if ES_USER != '' and ES_PASS != '':
    ES_AUTH = HTTPBasicAuth(ES_USER, ES_PASS)

headers = {'Content-Type': 'application/json; charset=UTF-8'}
try:
    resp = requests.get('%s:%s/_cat/indices?h=index' % (config.get('ElasticSearch', 'apiEndPointIP'),config.get('ElasticSearch', 'apiEndPointPort')), headers=headers, timeout=30,verify=False,auth=ES_AUTH)
    indices = resp.text.rstrip().split()
    logging.info('Got indexes:%s' % (resp.text))
except Exception as e:
    logging.error('Requests exception while getting indices %s' % (str(e)))
    sys.exit(0)

ip = sys.argv[1]
logging.info('IP is %s' % ip)

query = {"query": {"bool" : { "should" : [ { "term" : { "ip_src_saddr" : "%s" % ip} }, { "term" : { "ip_dst_saddr" : "%s" % ip} }],"minimum_should_match" : 1 }}}
for i in indices:
    try:
        resp = requests.post('%s:%s/%s/_delete_by_query' % (config.get('ElasticSearch', 'apiEndPointIP'),config.get('ElasticSearch', 'apiEndPointPort'),i), headers=headers, data=json.dumps(query),timeout=30,verify=False,auth=ES_AUTH)
        if resp.status_code == 200:
            result = json.loads(resp.text)
            logging.info('index:%s => deleted:%s' % (i,result['deleted']))
        else:
            logging.info('IP clean=> index:%s => Response:%s' % (i,resp.text))
    except Exception as e:
        logging.error('Requests exception while getting indices %s' % (str(e)))
        sys.exit(0)

logging.info('[main] delete data with ip finished.')