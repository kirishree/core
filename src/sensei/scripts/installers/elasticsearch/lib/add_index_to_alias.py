#!/usr/local/sensei/py_venv/bin/python3
import sys
from . messages import ERROR_MSG, get_error_msg
import requests
import json

def add_index_to_alias(host, headers, index, alias):
    try:
        request = requests.post(host['host'] + '_aliases',verify=False,auth=host['auth'] if host['auth'] != None else None, headers=headers, timeout=10, data=json.dumps({
            'actions': [{
                'add': {
                    'index': index,
                    'alias': alias
                }
            }]
        }))
        if request.status_code < 200 or request.status_code >= 400:
            print(ERROR_MSG % 'Elasticsearch service request returned error: %s.' % get_error_msg(request))
            sys.exit(4)
        print('%s index added to %s alias.' % (index, alias))
    except requests.exceptions.ConnectionError:
        print(ERROR_MSG % 'Connection could not be established with elasticsearch server(AL).')
        sys.exit(0)
    except Exception as exc:
        print(ERROR_MSG % exc)
        sys.exit(0)
