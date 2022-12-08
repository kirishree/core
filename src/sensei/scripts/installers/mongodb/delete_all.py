#!/usr/local/sensei/py_venv/bin/python3
import os
import sys
from configparser import ConfigParser
import logging
from logging.config import dictConfig
from datetime import datetime, timedelta
from pymongo import MongoClient

EASTPECT_ROOT = '/usr/local/sensei'
if 'EASTPECT_ROOT' in os.environ:
    EASTPECT_ROOT = os.environ.get('EASTPECT_ROOT')

EASTPECT_CFG = os.path.join(EASTPECT_ROOT, 'etc', 'eastpect.cfg')

LOG_FILE = os.path.join(EASTPECT_ROOT, 'log', 'active','Senseigui.log')
logging.basicConfig(filename=LOG_FILE, level=logging.INFO, format='[%(asctime)s][%(levelname)s][TEMP] %(message)s', datefmt='%Y-%m-%dT%H:%M:%S')
logging.info('Starting Delete Mongodb all collections process')

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
        logging.info('%s will be drop.' % collection)
        db[collection].drop()
    except Exception as e:
        logging.error('%s could not drop : %s' % (collection, str(e)))
        pass