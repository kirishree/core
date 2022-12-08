#!/usr/local/sensei/py_venv/bin/python3
import sys
from configparser import ConfigParser
from lib.add_index_to_alias import add_index_to_alias
from lib.create_index import create_index
from lib.get_indices import get_indices
from lib.get_aliases import get_aliases
from requests.auth import HTTPBasicAuth
from base64 import b64decode
import requests
import os
import time
import json
import logging
import urllib3
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

EASTPECT_ROOT = '/usr/local/sensei'
if 'EASTPECT_ROOT' in os.environ:
    EASTPECT_ROOT = os.environ.get('EASTPECT_ROOT')

'''  Exit Codes:
0 => Everything is OK
1 => Unhandled Error
2 => Elasticsearch service is not running (or can not be reached)
3 => Elasticsearch service response could not be json-decoded
4 => Elasticsearch service request return unhandled error
5 => Zenarmor configuration read error
'''

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
HEADERS = {'Content-Type': 'application/json; charset=UTF-8'}
ES_HOSTS = []
try:
    if os.path.exists(os.path.join(EASTPECT_ROOT, 'log', 'active','Senseigui.log')):
        LOG_FILE = os.path.join(EASTPECT_ROOT, 'log', 'active','Senseigui.log')
    else:
        LOG_FILE = '/tmp/Senseigui.log'
    logging.basicConfig(filename=LOG_FILE, level=logging.INFO, format='[%(asctime)s][%(levelname)s][TEMP] %(message)s', datefmt='%Y-%m-%dT%H:%M:%S')
    logging.info('create indices starting')

    SENSEI_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(BASE_DIR)))
    SENSEI_CFG = os.path.join(SENSEI_ROOT, 'etc', 'eastpect.cfg')

    config = ConfigParser()   
    config.read(SENSEI_CFG)

    INDICES = [config.get('ElasticSearch', '%sIndex' % i) for i in ['conn', 'dns', 'tls', 'sip', 'alert', 'http']]
    INDICES_PURE = [config.get('ElasticSearch', '%sIndex' % i) for i in ['conn', 'dns', 'tls', 'sip', 'alert', 'http']]
    ES_HOST_LOCAL = '%s:%s/' % (config.get('ElasticSearch', 'apiEndPointIP'), config.get('ElasticSearch', 'apiEndPointPort'))
    PREFIX = config.get('ElasticSearch', 'apiEndPointPrefix')
    INDICES = ['%s%s' % (PREFIX,x) for x in INDICES]
    ES_USER = config.get('ElasticSearch', 'apiEndPointUser')
    ES_PASS = config.get('ElasticSearch', 'apiEndPointPass')
    if ES_PASS != '' and ES_PASS[0:4] == 'b64:':
        ES_PASS = b64decode(config.get('ElasticSearch', 'apiEndPointPass')[4:None]).decode('utf-8')

    ES_AUTH = None
    if ES_USER != '' and ES_PASS != '':
        ES_AUTH = HTTPBasicAuth(ES_USER, ES_PASS)

    STREAM_EXTERNAL_USER = config.get('StreamReportExternal', 'uriUser')
    STREAM_EXTERNAL_PASS = config.get('StreamReportExternal', 'uriPass')
    if STREAM_EXTERNAL_PASS != '' and STREAM_EXTERNAL_PASS[0:4] == 'b64:':
        STREAM_EXTERNAL_PASS = b64decode(config.get('StreamReportExternal', 'uriPass')[4:None]).decode('utf-8')
    
    STREAM_ES_AUTH = None
    if STREAM_EXTERNAL_USER != '' and STREAM_EXTERNAL_PASS != '':
        STREAM_ES_AUTH = HTTPBasicAuth(STREAM_EXTERNAL_USER, STREAM_EXTERNAL_PASS)

    request = requests.get(ES_HOST_LOCAL, verify=False,timeout=10,auth=ES_AUTH if ES_AUTH != None else None, data='')
    if request.status_code > 199 or request.status_code < 210:
        es_info = json.loads(request.text)

        if 'version' in es_info and 'number' in es_info['version']:
            es_version = es_info['version']['number'].replace(".","")
            # for opensearch distribution settings
            if 'version' in es_info and 'distribution' in es_info['version'] and es_info['version']['distribution'] == 'opensearch':
                es_version = es_info['version']['minimum_wire_compatibility_version'].replace(".","")
            
            while len(es_version) < 5:
                es_version = es_version + "0"
            ES_HOSTS.append({'host': ES_HOST_LOCAL, 'version': es_version,'auth': ES_AUTH})
            logging.info('Host: %s version: %s' % (ES_HOST_LOCAL,es_version))
                         
    if config.get('StreamReportExternal', 'enabled') == 'true':
        ES_HOSTS.append({'host': config.get('StreamReportExternal', 'uri') + '/','version':config.get('StreamReportExternal', 'version'),'auth': STREAM_ES_AUTH})
        logging.info('StreamReportExternal Host %s', config.get('StreamReportExternal', 'uri'))

except Exception as e:
    logging.error('Zenarmor configuration read error: %s.' % repr(e))
    sys.exit(5)

for ES_HOST in ES_HOSTS:
    # ES_HOST = json.loads(ES_HOST)
    indices, index_names = get_indices(ES_HOST, HEADERS)
    aliases, alias_names = get_aliases(ES_HOST, HEADERS)

    for i in INDICES:
        indices_tmp = [t for t in index_names if t.startswith(i)]
        indices_tmp.sort()

        if '%s_write' % i in alias_names:
            print('%s write index already exists.' % i)
        else:
            create_index(ES_HOST, HEADERS, BASE_DIR, i, time.strftime('%y%m%d'),PREFIX)
            continue

        if '%s_all' % i in alias_names:
            print('%s read index already exists.' % i)
        else:
            if len(indices_tmp) > 0:
                add_index_to_alias(ES_HOST, HEADERS, indices_tmp[-1], '%s_all' % i)

logging.info('Create indices END.') 
if len(sys.argv) > 1:
    import re
    oldprefix = sys.argv[1]
    for ES_HOST in ES_HOSTS:
        for alias in aliases:
            x = re.match("^" + oldprefix + "(.*)\_all$", alias['alias'])
            if x != None:
                x_indices = x.group(1).replace("_","")
                if x_indices in INDICES_PURE:
                    add_index_to_alias(ES_HOST, HEADERS, alias['index'], '%s%s_all' % (PREFIX,x_indices))