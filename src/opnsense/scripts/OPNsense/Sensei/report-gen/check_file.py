import json
import os

with open('indices.json') as file:
    reports_config = json.load(file)

for r in reports_config:
    print(r['name'])
    if not os.path.exists('queries_es/%s/%s.%s' % (r['index'],r['name'],'json')):
        print('file Not found : queries_es/%s/%s.%s' % (r['index'],r['name'],'json'))    
    
    if not os.path.exists('queries_mn/%s/%s.%s' % (r['index'],r['name'],'query')):
        print('file Not found : queries_mn/%s/%s.%s' % (r['index'],r['name'],'query'))            

        