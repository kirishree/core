#!/usr/local/sensei/py_venv/bin/python3
from lib.messages import ERROR_MSG, get_error_msg
import sys
from configparser import ConfigParser
import requests
import os
import urllib3
from requests.auth import HTTPBasicAuth
from base64 import b64decode

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
SENSEI_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(BASE_DIR)))
SENSEI_CFG = os.path.join(SENSEI_ROOT, 'etc', 'eastpect.cfg')

config = ConfigParser()    
config.read(SENSEI_CFG)
ES_USER = config.get('ElasticSearch', 'apiEndPointUser')
ES_PASS = config.get('ElasticSearch', 'apiEndPointPass')

if ES_PASS != '' and ES_PASS[0:4] == 'b64:':
    ES_PASS = b64decode(config.get('ElasticSearch', 'apiEndPointPass')[4:None]).decode('utf-8')

ES_AUTH = None
if ES_USER != '' and ES_PASS != '':
    ES_AUTH = HTTPBasicAuth(ES_USER, ES_PASS)

HEADERS = {'Content-Type': 'application/json; charset=UTF-8'}
ES_HOST = '%s:%s/' % (config.get('ElasticSearch', 'apiEndPointIP'), config.get('ElasticSearch', 'apiEndPointPort'))
INDICES = [config.get('ElasticSearch', '%sIndex' % i) for i in ['conn', 'dns', 'tls', 'sip', 'alert', 'http']]

try:
    for index in INDICES:
        request = requests.delete(ES_HOST + index + '*', headers=HEADERS, timeout=30,verify=False,auth=ES_AUTH)
        if request.status_code < 200 or request.status_code >= 400:
            print(ERROR_MSG % 'Elasticsearch service request returned error: %s.' % get_error_msg(request))
            sys.exit(4)
        else:
            print('Deleted all child indices for %s.' % index)

except requests.exceptions.ConnectionError:
    print(ERROR_MSG % 'Connection could not be established with elasticsearch server(D).')
    sys.exit(2)

except Exception as exc:
    print(ERROR_MSG % exc)
    sys.exit(1)
