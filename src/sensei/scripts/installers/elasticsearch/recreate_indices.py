#!/usr/local/sensei/py_venv/bin/python3
from lib.add_index_to_alias import add_index_to_alias
from lib.create_index import create_index
from requests.auth import HTTPBasicAuth
from base64 import b64decode
import requests
import sys
from configparser import ConfigParser
import os
import time
import json
import urllib3
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

'''  Exit Codes:
0 => Everything is OK
1 => Unhandled Error
2 => Elasticsearch service is not running (or can not be reached)
3 => Elasticsearch service response could not be json-decoded
4 => Elasticsearch service request return unhandled error
5 => Zenarmor configuration read error
'''

if len(sys.argv) == 1:
    print (sys.argv[0] + ' [es index name]')
    sys.exit(1)

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
HEADERS = {'Content-Type': 'application/json; charset=UTF-8'}
index_name = sys.argv[1]

def get_settings(host, index,headers):
    try:
        print(host['host'] + index) 
        indices = requests.get(host['host'] + index, headers=headers, timeout=10,verify=False)
        if indices.status_code < 200 or indices.status_code >= 400:
            print('Elasticsearch service request returned error: %s.' % indices.text)
            sys.exit(4)
    except requests.exceptions.ConnectionError:
        print('Connection could not be established with elasticsearch server(R).')
        sys.exit(2)
    except Exception as exc:
        print(exc)
        sys.exit(1)
    try:
        indices = indices.json()
        mappings = indices[index]['mappings']
        aliases = indices[index]['aliases']
    except ValueError:
        print('Elasticsearch response could not be decoded.')
        sys.exit(3)
    except Exception as exc:
        print(exc)
        sys.exit(1)
    return mappings, aliases


try:
    SENSEI_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(BASE_DIR)))
    SENSEI_CFG = os.path.join(SENSEI_ROOT, 'etc', 'eastpect.cfg')

    config = ConfigParser()   
    config.read(SENSEI_CFG)

    ES_HOSTS = []
    ES_HOST_LOCAL = '%s:%s/' % (config.get('ElasticSearch', 'apiEndPointIP'), config.get('ElasticSearch', 'apiEndPointPort'))
    PREFIX = config.get('ElasticSearch', 'apiEndPointPrefix')
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


    ES_HOSTS.append({'host': ES_HOST_LOCAL, 'version': config.get('ElasticSearch', 'apiEndPointVersion'),'auth': ES_AUTH})
    if config.get('StreamReportExternal', 'enabled') == 'true':
        ES_HOSTS.append({'host': config.get('StreamReportExternal', 'uri'),'version':config.get('StreamReportExternal', 'version'),'auth': STREAM_ES_AUTH})

except Exception as exc:
    print('Zenarmor configuration read error: %s.' % exc)
    sys.exit(5)

for ES_HOST in ES_HOSTS:
    mappings, aliases = get_settings(ES_HOST, index_name, HEADERS)
    resp = requests.delete(ES_HOST['host'] + index_name, headers=HEADERS, timeout=10,verify=False,auth=ES_HOST['auth'] if ES_HOST['auth'] != None else None)
    if resp.status_code != 200:
        print('% index cannot delete' % index_name)
        sys.exit(1)
    request = requests.put(ES_HOST['host'] + index_name,verify=False, headers=HEADERS, timeout=10,auth=ES_HOST['auth'] if ES_HOST['auth'] != None else None, data=json.dumps({
        'settings': {
            'number_of_shards': 1,
            "number_of_replicas": 0
        },
        'mappings': mappings,
        'aliases' : aliases
    }))

    if request.status_code < 200 or request.status_code >= 400:
        error_msg = request.text
        if 'already exists' in error_msg:
            print('%s index already exists.' % index_name)
        else:
            print('Elasticsearch service request returned error: %s.' % error_msg)
            sys.exit(4)
    else:
        print('OK')
sys.exit(0)