#!/usr/local/sensei/py_venv/bin/python3
from base64 import b64decode
from struct import unpack
import json
import os
import time

SENSEI_ROOT = os.environ.get('EASTPECT_ROOT', '/usr/local/sensei')

data = {'premium': False}

try:
    if os.path.exists(SENSEI_ROOT + '/etc/license.data'):
        with open(SENSEI_ROOT + '/etc/license.data', 'rb') as file:
            license = file.readlines()
        packed = b64decode(license[1].rstrip())
        # l16s32sl32s16s32s
        unpacked = unpack('l64s64sl64s16s128s', packed)
        data['activation_key'] = unpacked[1].decode().replace('\x00', '')
        data['expire_time'] = int(unpacked[3])
        data['plan'] = unpacked[4].decode().replace('\x00', '')
        data['size'] = unpacked[5].decode().replace('\x00', '')
        data['extdata'] = unpacked[6].decode().replace('\x00', '')
        data['premium'] = True if (data['expire_time'] + 1209600) > int(time.time()) else False
except Exception as e:
    pass

print(json.dumps(data))
