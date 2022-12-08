#!/usr/local/sensei/py_venv/bin/python3
"""
    Copyright (c) 2019 Hasan UCAK <hasan@sunnyvalley.io>
    All rights reserved from Zenarmor of Opnsense
    reload db and rules via socket
    send to whole deamons.
"""
import os
import socket
import traceback
import time
import sys
import glob
import logging
from logging.handlers import TimedRotatingFileHandler

EASTPECT_ROOT = '/usr/local/sensei'
if 'EASTPECT_ROOT' in os.environ:
    EASTPECT_ROOT = os.environ.get('EASTPECT_ROOT')

SENSEI_SOCK_PATH = EASTPECT_ROOT + '/run/'
SENSEI_SOCK_FILE = 'mgmt.sock.43*'
commands = ['reload rules','reload db']

LOG_FILE = os.path.join(EASTPECT_ROOT, 'log', 'active','Senseigui.log')
hl = TimedRotatingFileHandler(LOG_FILE, when='midnight', interval=1, backupCount=10)
logging.basicConfig(handlers=[hl], level=logging.INFO, format='[%(asctime)s][%(levelname)s][TEMP] %(message)s', datefmt='%Y-%m-%dT%H:%M:%S')

def exec_cmd(exec_commands, port:str):
    """ execute command using configd socket
    :param exec_command: command string
    :return: string
    """
    # Create and open unix domain socket
    try:
        logging.info('Connect to port: %s' % port)
        host = 'localhost'
        sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        sock.connect(port)
    except socket.error:
        logging.error('Unable to connect to socket (%s)' % port)
        return None
    try:
        for command in exec_commands:
            logging.info("Command : %s " % command)
            command = command + "\n"
            sock.send(bytes(command, 'utf-8'))
            line = ''
            while line.find('OK') == -1 and line.find('ERR') == -1:
                line = line + sock.recv(1024).decode('utf-8')
            logging.info("Command Result: %s " % line.replace('eastpect>', ''))
        return True
    except:
        logging.error('Error in communication %s' % traceback.format_exc())
    finally:
        sock.close()

if os.path.exists(SENSEI_SOCK_PATH):
    ports = glob.glob('%s%s*' % (SENSEI_SOCK_PATH,SENSEI_SOCK_FILE))
    for port in ports:
        exec_cmd(commands, port)