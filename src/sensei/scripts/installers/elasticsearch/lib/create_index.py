#!/usr/local/sensei/py_venv/bin/python3
import sys
from . messages import ERROR_MSG, get_error_msg
from . add_index_to_alias import add_index_to_alias
import requests
import json
import os


def create_index(host, headers, base_dir, index, order,prefix):
    try:
        # index_name = '%s-%06d' % (index, order)
        index_name = '%s-%s' % (index, order)
        pure_index = index.replace(prefix,'')
        with open(os.path.join(base_dir, 'mappings', '%s.json' % pure_index), 'rb') as f:
            mapping = json.load(f)
        if int(host['version']) > 59999 and int(host['version']) < 67999:
            mapping = {"_doc" : mapping[pure_index]}
        if int(host['version']) > 67999:
            mapping = mapping[pure_index]
        map_data = {
            "settings": {
                "number_of_shards": 1,
                "number_of_replicas": 0
            },
            "mappings": mapping
        }    
        request = requests.put(host['host'] + index_name, verify=False,headers=headers, timeout=10,auth=host['auth'] if host['auth'] != None else None, data=json.dumps(map_data))
        if request.status_code < 200 or request.status_code >= 400:
            error_msg = get_error_msg(request)
            if 'already exists' in error_msg:
                print('%s index already exists.' % index_name)
            else:
                print( ERROR_MSG % 'Elasticsearch service request returned error: %s.' % error_msg)
                sys.exit(4)
        else:
            print('%s index has been created.' % index_name)
        add_index_to_alias(host, headers, index_name, '%s_write' % index)
        add_index_to_alias(host, headers, index_name, '%s_all' % index)
    except requests.exceptions.ConnectionError:
        print(ERROR_MSG % 'Connection could not be established with elasticsearch server(CI).')
        sys.exit(0)
    except Exception as exc:
        print(ERROR_MSG % exc)
        sys.exit(0)
