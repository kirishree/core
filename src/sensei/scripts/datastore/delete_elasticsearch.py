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
    
config.read(EASTPECT_CFG)
ES_USER = config.get('ElasticSearch', 'apiEndPointUser')
ES_PASS = config.get('ElasticSearch', 'apiEndPointPass')

if ES_PASS != '' and ES_PASS[0:4] == 'b64:':
    ES_PASS = b64decode(config.get('ElasticSearch', 'apiEndPointPass')[4:None]).decode('utf-8')

ES_AUTH = None
if ES_USER != '' and ES_PASS != '':
    ES_AUTH = HTTPBasicAuth(ES_USER, ES_PASS)


INDICES.append(config.get('ElasticSearch','connIndex'))
INDICES.append(config.get('ElasticSearch','dnsIndex'))
INDICES.append(config.get('ElasticSearch','tlsIndex'))
INDICES.append(config.get('ElasticSearch','sipIndex'))
INDICES.append(config.get('ElasticSearch','alertIndex'))
INDICES.append(config.get('ElasticSearch','httpIndex'))
PREFIX = config.get('ElasticSearch', 'apiEndPointPrefix')
INDICES = ['%s%s' % (PREFIX,x) for x in INDICES]

LOG_FILE = os.path.join(EASTPECT_ROOT, 'log', 'active','Senseigui.log')
logging.basicConfig(filename=LOG_FILE, level=logging.INFO, format='[%(asctime)s][%(levelname)s][TEMP] %(message)s', datefmt='%Y-%m-%dT%H:%M:%S')
logging.info('[main] Starting elasticsearch indexes deleting...')

if len(sys.argv) < 2:
    logging.info('must be least one [day] argument...')
    sys.exit(1)

retireAfter = int(sys.argv[1])

class ElasticAliasIndices:
    def __init__(self, indicestext):
        self.error = False
        self.response = indicestext
        if 'error' in indicestext:
            self.error = True
            return
        o = json.loads(indicestext)
        self.indices = []
        for k in o.keys():
            self.indices.append(k)




def elasticsearch_rollover(index_name):
    # get all aliases
    aliases_endpoint = index_name + '*/_alias'
    headers = {'Content-Type': 'application/json; charset=UTF-8'}

    try:
        resp = requests.get('%s:%s/%s' % (config.get('ElasticSearch', 'apiEndPointIP'),config.get('ElasticSearch', 'apiEndPointPort'),aliases_endpoint), headers=headers, timeout=30,verify=False,auth=ES_AUTH)
        indices = ElasticAliasIndices(resp.text.rstrip())
        logging.info('[%s] Got aliases for index.' % (index_name))
    except Exception as e:
        logging.error('[%s] Requests exception while getting alias indices: %s' % (index_name, str(e)))
        return

    sorted_index = sorted(indices.indices)
    if len(sorted_index) < 2 and retireAfter > 0:
        return
    # sorted_index.pop()
    query = '{"size":1,"sort":[{"start_time":{"order":"desc","unmapped_type":"boolean"}}]}'

    for i in sorted_index:
        try:
            resp = requests.post('%s:%s/%s/_search' % (config.get('ElasticSearch', 'apiEndPointIP'),config.get('ElasticSearch', 'apiEndPointPort'),i), headers=headers, data=query, timeout=30,verify=False,auth=ES_AUTH).json()
            if len(resp['hits']['hits']) == 0:
                requests.delete('%s:%s/%s' % (config.get('ElasticSearch', 'apiEndPointIP'),config.get('ElasticSearch', 'apiEndPointPort'),i), headers=headers, timeout=30,verify=False,auth=ES_AUTH)
                logging.info('[%s] Deleted index: %s' % (index_name, i))
            else:
                latest_datetime = datetime.fromtimestamp(resp['hits']['hits'][0]['_source']['start_time'] / 1000)
                if (datetime.now() - latest_datetime) > timedelta(days=retireAfter):
                    requests.delete('%s:%s/%s' % (config.get('ElasticSearch', 'apiEndPointIP'),config.get('ElasticSearch', 'apiEndPointPort'),i), headers=headers, timeout=30,verify=False,auth=ES_AUTH)
                    logging.info('[%s] Deleted index: %s' % (index_name, i))
        except Exception as e:
            logging.error('[%s] Requests exception while deleting index: %s' % (index_name, str(e)))
            break


for i in INDICES:
    logging.info('[%s] looking index for delete...' % i)
    elasticsearch_rollover(i)

logging.info('[main] delete index finished.')