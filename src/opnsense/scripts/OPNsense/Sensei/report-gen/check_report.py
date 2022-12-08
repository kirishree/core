#!/usr/local/sensei/py_venv/bin/python3
import json
import os

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
INDICES_JSON = os.path.join(BASE_DIR,'indices.json')
INDICES_JSON_ORIG = os.path.join(BASE_DIR,'indices.json.orig')

indices_list = []
new_indices_list = []

try:
    if os.path.exists(INDICES_JSON):
        with open(INDICES_JSON) as file:
            indices_list = json.load(file)
except:
    pass

if os.path.exists(INDICES_JSON_ORIG):
   with open(INDICES_JSON_ORIG) as file:
        new_indices_list = json.load(file)

for e in indices_list:
    for i,f in enumerate(new_indices_list):
        if f['name'] == e['name'] and f['index'] == e['index']:
            new_indices_list[i]['enabled'] = e['enabled']

with open(INDICES_JSON, 'w') as outfile:
    json.dump(new_indices_list, outfile)