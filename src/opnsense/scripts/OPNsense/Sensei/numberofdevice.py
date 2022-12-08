#!/usr/local/sensei/py_venv/bin/python3
import json
import os
import time
import logging
import glob

SENSEI_ROOT = os.environ.get('EASTPECT_ROOT', '/usr/local/sensei')

LOG_FILE = os.path.join(SENSEI_ROOT, 'log', 'active','Senseigui.log')
logging.basicConfig(filename=LOG_FILE, level=logging.INFO, format='[%(asctime)s][%(levelname)s][TEMP] %(message)s', datefmt='%Y-%m-%dT%H:%M:%S')

STATS_DIR = os.path.join(SENSEI_ROOT, 'log','stat')
NumberOfDevices = 0
if os.path.exists(STATS_DIR):
    try:
        workers = glob.glob('%s/worker*.stat' % STATS_DIR)
        for worker in workers:
            p = os.stat(worker)
            if int(p.st_mtime) > (int(time.time()) - 60):
                with open(worker) as f:
                    s_content = f.read()
                    s_content = json.loads(s_content)
                    if 'engine_stats' in s_content and 'devices' in s_content['engine_stats']:
                        NumberOfDevices += int(s_content['engine_stats']['devices'])

    except Exception as e:
        logging.info('NumberofDevice: Stats Exception -> %s' % e)

print(NumberOfDevices)        