#!/usr/local/sensei/py_venv/bin/python3
import os
import sys
from configparser import ConfigParser
import logging
from logging.handlers import TimedRotatingFileHandler
from logging.config import dictConfig
from datetime import datetime, timedelta
from pymongo import MongoClient

EASTPECT_ROOT = '/usr/local/sensei'
if 'EASTPECT_ROOT' in os.environ:
    EASTPECT_ROOT = os.environ.get('EASTPECT_ROOT')

EASTPECT_CFG = os.path.join(EASTPECT_ROOT, 'etc', 'eastpect.cfg')
INDICES = []

LOGGING_CONFIG = {
    'formatters': {
        'brief': {
            'format': '%(asctime)s - %(levelname)s - %(message)s',
            'datefmt': '%Y.%m.%d - %H:%M:%S'
        },
    },
    'handlers': {
        'console': {
            'class': 'logging.StreamHandler',
            'level': 'DEBUG',
            'formatter': 'brief'
        },
        'file': {
            'class': 'logging.handlers.TimedRotatingFileHandler',
            'level': 'DEBUG',
            'formatter': 'brief',
            'filename': os.path.join(EASTPECT_ROOT, 'log', 'active', 'ipdr_retire.log'),
            'when': 'midnight',
            'interval': 1,
            'backupCount': 10
        }
    },
    'loggers': {
        'ipdr retire manager': {
            'propagate': False,
            'handlers': ['console', 'file'],
            'level': 'DEBUG'
        }
    },
    'version': 1
}

config = ConfigParser()

config.read(EASTPECT_CFG)
dictConfig(LOGGING_CONFIG)

logger = logging.getLogger('ipdr retire manager')
logger.info('[main] Starting ipdr retiring for MONGODB...')
try:
    mongo_client = MongoClient()
    db = mongo_client.sensei
    collection_list = db.list_collection_names()
    logger.info('Connection Mongodb get collection list.')
except Exception as e:
    logger.error('Connection to Mongodb failed : %s' % str(e))
    sys.exit(1)

for N in range(2,10):
    date_N_days_ago = datetime.now() - timedelta(days=N)
    time_part = date_N_days_ago.strftime('%y%m%d')
    old_collection = [collect for collect in collection_list if time_part in collect]
    for collect in old_collection:
        try:
            logger.info('%s will be drop.' % collect)
            db[collect].drop()
        except Exception as e:
            logger.error('%s could not drop : %s' % (collect, str(e)))
            pass
