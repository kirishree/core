#!/usr/local/sensei/py_venv/bin/python3
import sys
from . messages import ERROR_MSG, get_error_msg
import requests

def get_aliases(host, headers):
    try:
        aliases = requests.get(host['host'] + '_cat/aliases?format=json', headers=headers, timeout=10,verify=False,auth=host['auth'] if host['auth'] != None else None)
        if aliases.status_code < 200 or aliases.status_code >= 400:
            print(ERROR_MSG % 'Elasticsearch service request for aliases returned error: %s.' % get_error_msg(aliases))
            sys.exit(4)
    except requests.exceptions.ConnectionError:
        print(ERROR_MSG % 'Connection could not be established with elasticsearch server(GA).')
        sys.exit(0)
    except Exception as exc:
        print(ERROR_MSG % exc)
        sys.exit(0)

    try:
        aliases = aliases.json()
    except ValueError:
        print(ERROR_MSG % 'Elasticsearch response could not be decoded.')
        sys.exit(0)
    except Exception as exc:
        print(ERROR_MSG % exc)
        sys.exit(0)

    return (aliases, [a['alias'] for a in aliases])
