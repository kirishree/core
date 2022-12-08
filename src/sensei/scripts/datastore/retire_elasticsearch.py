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
index_extension = ''
if len(sys.argv) > 1:
     index_extension = sys.argv[1]

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

if len(sys.argv) > 2:
     INDICES.append(sys.argv[2])
else:
    INDICES.append(config.get('ElasticSearch','connIndex'))
    INDICES.append(config.get('ElasticSearch','dnsIndex'))
    INDICES.append(config.get('ElasticSearch','tlsIndex'))
    INDICES.append(config.get('ElasticSearch','sipIndex'))
    INDICES.append(config.get('ElasticSearch','alertIndex'))
    INDICES.append(config.get('ElasticSearch','httpIndex'))

PREFIX = config.get('ElasticSearch', 'apiEndPointPrefix')
INDICES = ['%s%s' % (PREFIX,x) for x in INDICES]

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


class RolloverStatus:
    def __init__(self, rollovertext):
        self.error = False
        self.response = rollovertext
        if 'error' in rollovertext:
            self.error = True
            self.old_index = None
            self.new_index = None
            self.rolled_over = False
            return
        o = json.loads(rollovertext)
        self.old_index = o['old_index']
        self.new_index = o['new_index']
        self.rolled_over = o['rolled_over']

class IndexStatus:
    def __init__(self, indextext):
        self.error = False
        self.response = indextext
        if 'error' in indextext:
            self.error = True
            return
        o = json.loads(indextext)
        keylist = list(o)
        if keylist[0] == 'error':
            self.error = True
            return
        self.index_name = keylist[0]
        self.create_date = o[keylist[0]]['settings']['index']['creation_date']
        self.number_of_replicas = o[keylist[0]]['settings']['index']['number_of_replicas']

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


def get_index_mapping(index_name):
    with open(os.path.join(EASTPECT_ROOT, 'scripts', 'installers', 'elasticsearch', 'mappings' , index_name.replace(PREFIX,'') + '.json'), 'r') as file:
        return json.load(file)


def delete_index(index_name,new_index_name):
    aliases_endpoint = index_name + '*/_alias'
    headers = {'Content-Type': 'application/json; charset=UTF-8'}

    try:
        resp = requests.get(reportUri + aliases_endpoint, headers=headers, timeout=30,verify=False,auth=ES_AUTH)
        indices = ElasticAliasIndices(resp.text.rstrip())
        logger.info('[%s] Got aliases for index.' % (index_name))
    except Exception as e:
        logger.error('[%s] Delete Requests exception while getting alias indices: %s' % (index_name, str(e)))
        return

    sorted_index = sorted(indices.indices)
    if len(sorted_index):
        sorted_index.pop()
    query = '{"size":1,"sort":[{"start_time":{"order":"desc","unmapped_type":"boolean"}}]}'
    for i in sorted_index:
        if i != new_index_name:
            try:
                logger.info('[%s] Sorted index.' % i)
                resp = requests.post(reportUri + i + '/_search', headers=headers, data=query,timeout=30,verify=False,auth=ES_AUTH).json()
                if len(resp['hits']['hits']) == 0:
                    requests.delete(reportUri + i, headers=headers,timeout=30,verify=False,auth=ES_AUTH)
                    logger.info('[%s] Deleted index for hits zero: %s' % (index_name, i))
                else:
                    latest_datetime = datetime.fromtimestamp(resp['hits']['hits'][0]['_source']['start_time'] / 1000)
                    if (datetime.now() - latest_datetime) > timedelta(days=retireAfter):
                        requests.delete(reportUri + i, headers=headers, timeout=30,verify=False,auth=ES_AUTH)
                        logger.info('[%s] Deleted index old save data: %s' % (index_name, i))
            except Exception as e:
                logger.error('[%s] Requests exception while deleting index: %s' % (index_name, str(e)))


def elasticsearch_rollover(index_name):
    rollover_index_name = index_name + '_write'
    # create index name for current day
    new_index_name= '%s-%s' % (index_name, time.strftime('%y%m%d')) + index_extension
    # create_date older then 1 minute
    rollover_data = '{"conditions":{"max_age":"1m"}}'
    set_numberof_replicas = '{"index": {"number_of_replicas": 0}}'
    # elastic url for new rollover index
    rollover_endpoint = rollover_index_name + '/_rollover/%s' % new_index_name
    new_alias_endpoint = '_aliases'
    headers = {'Content-Type': 'application/json; charset=UTF-8'}
    # check x_write alias for current index.
    delete_index(index_name,new_index_name)
    try:
        # check settings for  *_write alias.
        resp = requests.get(reportUri + '%s/_settings' % (rollover_index_name), headers=headers, data='', timeout=30,verify=False,auth=ES_AUTH)
        indexstat = IndexStatus(resp.text.rstrip())
    except Exception as e:
        logger.error('[%s] Rollover Requests exception while executing index settings over elasticsearch: %s' % (index_name, str(e)))
        return
    # if *_write index not found return .
    if  indexstat.error:
        logger.error('[%s] index not exists' % (index_name))
    else:
    # if last index name of *_write alias is current index_name
        if  indexstat.index_name == new_index_name:
            logger.info('[%s] index already exists ' % (indexstat.index_name))
            if indexstat.number_of_replicas != '0':
                logger.info('[%s] change number of replicas settings ' % (indexstat.index_name))
                resp = requests.put(reportUri + '%s_all/_settings' % (index_name), headers=headers, data=set_numberof_replicas, timeout=30,verify=False,auth=ES_AUTH)
            return

    try:
        # get *_write settings
        indices_name = ''
        resp = requests.get(reportUri + '_alias/' + rollover_index_name, headers=headers, timeout=30,verify=False,auth=ES_AUTH)
        if resp.status_code == 200:
            resp = resp.json()
            indices_name = list(resp)[0]
        # create new index via mapping aliasses
        pure_index = index_name.replace(PREFIX,'')
        mappings = get_index_mapping(index_name)
        if int(reportVersion) > 59999 and int(reportVersion) < 67999:
                mappings = {"_doc" : json.dumps(mappings[pure_index])}
        if int(reportVersion) > 67999:
            mappings = mappings[pure_index]
        aliases = '{"%s_all": {}}' % index_name
        index_control = requests.get(reportUri + new_index_name, headers=headers,timeout=30,verify=False,auth=ES_AUTH)
        if index_control.status_code == 404:
            request = requests.put(reportUri + new_index_name, headers=headers,timeout=30,verify=False,auth=ES_AUTH,data=json.dumps({
                'settings': {
                    'number_of_shards': 1,
                    "number_of_replicas": 0
                },
                'mappings': mappings,
                'aliases' : json.loads(aliases)
            }))
            if request.status_code != 200:
                logger.error('[%s] new index can not create: %s %s' % (new_index_name,request.status_code,request.text))
                return
            request = request.json()
        
        if index_control.status_code == 200:
            request = requests.put(reportUri + new_index_name + '/_alias/%s_all' % index_name, headers=headers,timeout=30,verify=False,auth=ES_AUTH)
            if request.status_code != 200:
                logger.error('[%s] new index can not create: %s %s' % (new_index_name,request.status_code,request.text))
                return
            request = request.json()
                
        # resp = requests.post(reportUri + rollo:ver_endpoint, headers=headers, data=rollover_data, timeout=10)
        # rollover = RolloverStatus(resp.text.rstrip())
    except Exception as e:
        logger.error('[%s] Rollover 2 Requests exception while executing rollover over elasticsearch: %s' % (index_name, str(e)))
        return
    # time is not end
    # if rollover.error:
    #     logger.error('[%s] Can not get rollover status from elasticsearch: %s' % (index_name, rollover.response))
    #    return

    # if not rollover.rolled_over:
    #     logger.info('[%s] Not rolling over because duraction time not elapsed.' % index_name)
    #    return


    try:
        logger.info('[%s_write] checking....' % index_name)
        resp = requests.get(reportUri + '_cat/indices/%s_write?format=json&pretty' % index_name, headers=headers, timeout=30,verify=False,auth=ES_AUTH)
        resp_text = resp.text
        if resp.status_code == 200:
            resp = resp.json()
            if type(resp) is list:
                resp = resp[0]
            if resp["index"] == '%s_write' % index_name:
                logger.info('[%s] indices exists. it will be delete' % index_name)
                resp = requests.delete(reportUri + '%s_write' % index_name, headers=headers, timeout=30,verify=False,auth=ES_AUTH)
                if resp.status_code != 200:
                    logger.info('[%s] indices exists. it could not delete: %s' % (index_name, resp.text))
        #else:
        #    logger.info('[%s] indices checking error : %s' % (index_name,resp_text))                    
    except Exception as e:
        logger.error('[%s] Requests exception while get write alias from elasticsearch: %s ' % (index_name, str(e)))
    try:
        if indices_name == '':
            new_alias_data = '{"actions":[{"add":{"index":"%s","alias":"%s_all"}},{"add":{"index":"%s","alias":"%s_write"}}]}' % (new_index_name, index_name,new_index_name, index_name)
        else:
            new_alias_data = '{"actions":[{"remove":{"index":"%s","alias":"%s_write"}},{"add":{"index":"%s","alias":"%s_write"}}]}' % (indices_name , index_name , new_index_name, index_name)
        resp = requests.post(reportUri + new_alias_endpoint, headers=headers, data=new_alias_data,timeout=30,verify=False,auth=ES_AUTH)
        logger.info('[%s] New index added to alias: %s_all , %s_write' % (new_index_name, index_name , index_name))
    except Exception as e:
        logger.error('[%s] Requests exception while adding new rolled over index to elasticsearch: %s ' % (index_name, str(e)))
        return



for i in INDICES:
    logger.info('[%s] Rolling over index...' % i)
    elasticsearch_rollover(i)

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
    for i in INDICES:
        logger.info('[%s] External Rolling over index...' % i)
        elasticsearch_rollover(i)

logger.info('[main] ipdr retire manager finished.')
logger.info('[main] Deleting archive files which older then 15 days.')

current_time = time.time()
folders = []
folders.append(os.path.join(EASTPECT_ROOT, 'log', 'active'))
folders.append(os.path.join(EASTPECT_ROOT, 'log', 'archive'))
for folder in folders:
    logger.info('[main] Deleting files under %s folder.' % folder)
    for f in os.listdir(folder):
        f_path = os.path.join(folder, f)
        creation_time = os.path.getmtime(f_path)
        if (current_time - creation_time) > (15 * 24 * 60 * 60):
            os.unlink(f_path)
            logger.info('%s removed', f_path)
