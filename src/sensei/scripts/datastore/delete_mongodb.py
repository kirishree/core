#!/usr/local/sensei/py_venv/bin/python3
import sys
from configparser import ConfigParser
import logging
from logging.config import dictConfig
from datetime import datetime, timedelta
import os
import time
from pymongo import MongoClient

EASTPECT_ROOT = '/usr/local/sensei'
if 'EASTPECT_ROOT' in os.environ:
    EASTPECT_ROOT = os.environ.get('EASTPECT_ROOT')

EASTPECT_CFG = os.path.join(EASTPECT_ROOT, 'etc', 'eastpect.cfg')

config = ConfigParser()

LOG_FILE = os.path.join(EASTPECT_ROOT, 'log', 'active','Senseigui.log')
logging.basicConfig(filename=LOG_FILE, level=logging.INFO, format='[%(asctime)s][%(levelname)s][TEMP] %(message)s', datefmt='%Y-%m-%dT%H:%M:%S')
logging.info('[main] Starting mongodb indexes deleting...')

if len(sys.argv)< 2:
    logging.info('must be least one [day] argument...')
    sys.exit(1)

retireAfter = int(sys.argv[1])

try:
    mongo_client = MongoClient()
    db = mongo_client.sensei
    collection_list = db.list_collection_names()
    logging.info('Connection Mongodb get collection list.')
except Exception as e:
    logging.error('ERROR: %s' % e)
    sys.exit(1)

for collection in collection_list:
    try:
        for row in db[collection].find().sort("start_time", -1):
            latest_datetime = datetime.fromtimestamp(row['start_time'] / 1000)
            if (datetime.now() - latest_datetime) > timedelta(days=retireAfter):
                logging.info('%s will be drop.' % collection)
                db[collection].drop()
            break
    except Exception as e:
        logging.error('%s could not drop : %s' % (collection, str(e)))
        pass