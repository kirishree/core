#!/usr/local/sensei/py_venv/bin/python3
import sys
from . messages import ERROR_MSG, get_error_msg
import requests

def get_indices(host, headers):
    try:
        indices = requests.get(host['host'] + '_cat/indices?format=json', headers=headers, timeout=10,verify=False,auth=host['auth'] if host['auth'] != None else None)
        if indices.status_code < 200 or indices.status_code >= 400:
            print(ERROR_MSG % 'Elasticsearch service request for indexes returned error: %s.' % get_error_msg(indices))
            sys.exit(4)
    except requests.exceptions.ConnectionError:
        print(ERROR_MSG % 'Connection could not be established with elasticsearch server(GI).')
        sys.exit(0)
    except Exception as exc:
        print(ERROR_MSG % exc)
        sys.exit(0)

    try:
        indices = indices.json()
    except ValueError:
        print(ERROR_MSG % 'Elasticsearch response could not be decoded.')
        sys.exit(0)
    except Exception as exc:
        print(ERROR_MSG % exc)
        sys.exit(0)

    return (indices, [i['index'] for i in indices])
