#!/usr/local/sensei/py_venv/bin/python3
import sys
sys.path.append('/usr/local/sensei/py-lib-dyload')
from base64 import b64decode
import os
import datetime as dt
import signal
import requests
import time
import glob
import logging
import socket
import string
import json

from logging.handlers import TimedRotatingFileHandler
from requests import exceptions
from requests.auth import HTTPBasicAuth

import asyncio
import aiohttp
from aiohttp import BasicAuth
import aiofiles

import sqlite3
import random
import ipdr_util
import shutil
import tempfile

import urllib3
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

from configparser import ConfigParser
config = ConfigParser()

eastpect_root = '/usr/local/sensei'

if "EASTPECT_ROOT" in os.environ:
    eastpect_root = os.environ.get('EASTPECT_ROOT')

config.read(eastpect_root + '/etc/eastpect.cfg')
DB_TYPE = config.get('Database', 'type')
endpoint_doc = ''

logging.basicConfig(format='[%(asctime)s][%(levelname)s] %(message)s', datefmt='%Y-%m-%dT%H:%M:%S')

logger = logging.getLogger('ipdr streamer logger')
logger.setLevel(logging.DEBUG)
handler = TimedRotatingFileHandler(eastpect_root + '/log/active/ipdrstreamer.log', when='midnight', interval=1, backupCount=10)
logger.addHandler(handler)
logger.warning("Starting ipdr streamer with %s." % DB_TYPE)

# StreamReport syslog config.
stream_report_enabled = config.get('StreamReport', 'enabled')
syslog_server = config.get('StreamReport', 'server')
syslog_port = config.get('StreamReport', 'port')

# Stream data push to external elasitc.
stream_report_data_external_enabled = config.get('StreamReportExternal', 'enabled')
stream_report_data_external_uri = config.get('StreamReportExternal', 'uri')
stream_report_data_external_version = config.get('StreamReportExternal', 'version')
external_user = config.get('StreamReportExternal', 'uriUser')
external_pass = b64decode(config.get('StreamReportExternal', 'uriPass')[4:None]).decode('utf-8')

stream_es_auth = None
if external_user != '' and external_pass != '':
    stream_es_auth = BasicAuth(external_user, external_pass)

prefix = config.get('ElasticSearch', 'apiEndPointPrefix')
es_user = config.get('ElasticSearch', 'apiEndPointUser')
es_pass = config.get('ElasticSearch', 'apiEndPointPass')

if es_pass != '' and es_pass[0:4] == 'b64:':
    es_pass = b64decode(config.get('ElasticSearch', 'apiEndPointPass')[4:None]).decode('utf-8')

es_auth = None
if es_user != '' and es_pass != '':
    #es_auth = HTTPBasicAuth(es_user, es_pass)
    es_auth = BasicAuth(es_user, es_pass)


if DB_TYPE == 'ES' or stream_report_data_external_enabled == 'true':
    conn_index_name = config.get('ElasticSearch', 'connIndex')
    dns_index_name = config.get('ElasticSearch', 'dnsIndex')
    tls_index_name = config.get('ElasticSearch', 'tlsIndex')
    sip_index_name = config.get('ElasticSearch', 'sipIndex')
    alert_index_name = config.get('ElasticSearch', 'alertIndex')
    http_index_name = config.get('ElasticSearch', 'httpIndex')

if DB_TYPE == 'MN':
    sys.path.append('/usr/local/sensei/lib/pylib')
    import motor.motor_asyncio
    client = motor.motor_asyncio.AsyncIOMotorClient('mongodb://localhost:27017')
    db = client.sensei

if DB_TYPE == 'SQ':
    os.makedirs('/usr/local/datastore/sqlite/', exist_ok=True)
    sqlite_data_dir = '/usr/local/datastore/sqlite/'


if stream_report_enabled == 'true' and syslog_server and syslog_port.isdigit():
    syslog_protocol = config.get('StreamReport', 'protocol')
    syslog_indexes = config.get('StreamReport', 'indexes')

    syslogger = logging.getLogger('SyslogLogger')
    syslogger.setLevel('INFO')
    syslog_handler = logging.handlers.SysLogHandler(address = (syslog_server, int(syslog_port)), socktype=(socket.SOCK_STREAM if syslog_protocol == 'TCP' else socket.SOCK_DGRAM), facility=19)
    syslog_handler.setFormatter(logging.Formatter('Zenarmor %(levelname)s %(message)s\n'))
    syslogger.addHandler(syslog_handler)
    logger.warning("Data will be send syslog(config : %s,%s,%s) server." % (syslog_server,syslog_port,syslog_protocol))


def signal_handler(signal, frame):
    global close_requested
    close_requested = True

# elasticsearch insert function
async def es_insert(data,write_to_local,endpoint_index,endpoint_doc):
    logger.warning("Staring ES...." + str(write_to_local))  
    headers = {'Content-Type': 'application/x-ndjson; charset=UTF-8'}
    headers2 = {'Content-Type': 'application/json; charset=UTF-8'}
    # clean non utf-8 characters.
    # data = ''.join(x for x in data if x in string.printable)
    endpoint = ''
    timeout = aiohttp.ClientTimeout(total=15)
    connector = aiohttp.TCPConnector(force_close=True,enable_cleanup_closed=True)
    async with aiohttp.ClientSession(timeout=timeout,connector=connector,auth=es_auth) as s:
        endpoint = prefix + endpoint_index + '_write/' + endpoint_doc
        endpoint_ext = prefix + endpoint_index + '_write/' + endpoint_doc
        try:
            if write_to_local == True:
                #if int(config.get('ElasticSearch', 'apiEndPointVersion')) > 59999:
                #    endpoint = prefix + endpoint_index + '_write'
                
                if int(config.get('ElasticSearch', 'apiEndPointVersion')) > 59999 and int(config.get('ElasticSearch', 'apiEndPointVersion')) < 67999:    
                    endpoint = prefix + endpoint_index + '_write/_doc'
                if int(config.get('ElasticSearch', 'apiEndPointVersion')) > 67999:
                    endpoint = prefix + endpoint_index + '_write'
                logger.warning(" Endpoint: " + endpoint)  
                duraction = time.perf_counter()
                uri = config.get('ElasticSearch', 'apiEndPointIP') +  ((':%s' % config.get('ElasticSearch', 'apiEndPointPort')) if config.get('ElasticSearch', 'apiEndPointPort') != '' else '')
                async with s.post('%s/%s/_bulk' % (uri,endpoint), headers=headers2, data=data, timeout=15,ssl=False) as resp:
                    content = await resp.text()
                elapsed = time.perf_counter() - duraction
                logger.warning(f"Inserting in {elapsed:0.2f} seconds.")
                if '"errors":true' in content:
                    logger.critical(" response: " + content)
            if stream_report_data_external_enabled == 'true':
                logger.warning("writing external elasticsearch")
                async with aiohttp.ClientSession(timeout=timeout,connector=connector,auth=stream_es_auth) as s:
                    if int(stream_report_data_external_version) < 60000:
                        uri = '%s/%s%s' % (stream_report_data_external_uri, endpoint_ext,'_bulk')
                        async with  s.post(uri, headers=headers, data=data,timeout=30,ssl=False) as resp:
                            content = await resp.text()
                    if int(stream_report_data_external_version) > 59999:
                        endpoint = prefix + endpoint_index + '_write'
                        uri = '%s/%s/%s' % (stream_report_data_external_uri, endpoint , '_bulk')
                        async with s.post(uri, headers=headers2, data=data,timeout=30,ssl=False) as resp:
                            content = await resp.text()
                logger.warning(uri + " sending bulk: " + path)
                if '"errors":true' in content:
                    logger.critical(" uri:%s response for external: " % uri + content)
                s.close()
            else:
                s.close()        

        except (
        exceptions.ConnectionError, exceptions.Timeout, exceptions.ConnectTimeout, exceptions.ReadTimeout) as e:
            logger.critical(" requests exception: " + str(e))
        s.close()    

# --- elasticsearch insert function

# sqlite3 insert function
async def sqlite_insert(path,data):
    logger.info('Starting sqlite insert function')
    try:
        if 'dns' in path:
            db_file = 'dns_all.sqlite'
            query = ipdr_util.dns_all_query
            parameter = ipdr_util.dns_all_parameter
        elif 'conn' in path:
            db_file = 'conn_all.sqlite'
            query = ipdr_util.conn_all_query
            parameter = ipdr_util.conn_all_parameter
        elif 'alert' in path:
            db_file = 'alert_all.sqlite'
            query = ipdr_util.alert_all_query
            parameter = ipdr_util.alert_all_parameter
        elif 'http' in path:
            db_file = 'http_all.sqlite'
            query = ipdr_util.http_all_query
            parameter = ipdr_util.http_all_parameter
        elif 'sip' in path:
            db_file = 'sip_all.sqlite'
            query = ipdr_util.sip_all_query
            parameter = ipdr_util.sip_all_parameter
        elif 'tls' in path:
            db_file = 'tls_all.sqlite'
            query = ipdr_util.tls_all_query
            parameter = ipdr_util.tls_all_parameter
        else:
            logger.critical(" unknown index: " + path)
            return False
        
        conn = sqlite3.connect(sqlite_data_dir + db_file)
        cur_e = conn.cursor()
        cur_e.execute('PRAGMA synchronous = OFF')
        cur_e.execute('PRAGMA locking_mode = EXCLUSIVE')
        cur_e.execute('PRAGMA journal_mode = OFF')
        lines = data.splitlines()
        start_time_list = []
        for l in lines:
            st = []
            q = json.loads(l) 
            if 'index' in q:
                continue
            
            if  'security_tags' in q and type(q['security_tags']) == list and len(q['security_tags']) > 0:
                st = q['security_tags']
            
            values = parameter
            for p in values:
                if p not in ['src_geoip','dst_geoip','geoip']:
                    if 'alertinfo' in p:
                        v = q['alertinfo'][p.replace('alertinfo_','')]
                        values[p] = ','.join(v) if type(v) == list else v
                    else:    
                        if p in q:
                            values[p] = ','.join(q[p]) if type(q[p]) == list else q[p]
                            if p == 'security_tags':
                               values['security_tags_len'] = len(q[p]) if type(q[p]) == list else 0
                               
                if p == 'dst_geoip_lat' and 'dst_geoip' in q:
                    values[p] = q['dst_geoip']['location']['lat']
                    values['dst_geoip_lon'] = q['dst_geoip']['location']['lon']

                if p == 'dst_country_name' and 'dst_geoip' in q:
                    values[p] = q['dst_geoip']['country_name']
                if p == 'src_country_name' and 'src_geoip' in q:
                    values[p] = q['src_geoip']['country_name']
                
                if p == 'dst_country_name' and 'geoip' in q:
                    values[p] = q['geoip']['country_name']
                if p == 'src_country_name' and 'geoip' in q:
                    values[p] = q['geoip']['country_name']
                
                # device part    
                if p == 'device_id' and 'device' in q and 'id' in q['device']:
                    values[p] = q['device']['id']

                if p == 'device_name' and 'device' in q and 'name' in q['device']:
                    values[p] = q['device']['name']
                
                if p == 'device_category' and 'device' in q and 'category' in q['device']:
                    values[p] = q['device']['category']

                if p == 'device_vendor' and 'device' in q and 'vendor' in q['device']:
                    values[p] = q['device']['vendor']

                if p == 'device_os' and 'device' in q and 'os' in q['device']:
                    values[p] = q['device']['os']

                if p == 'device_osver' and 'device' in q and 'osver' in q['device']:
                    values[p] = q['device']['osver']

            while values['start_time'] in start_time_list:
               values['start_time'] += ( 0.001 * random.randint(0,1000))
            start_time_list.append(values['start_time'])                 
            try:
                cur_e.execute(query,values)
                if len(st) > 0:
                    for i,t in enumerate(st):
                        vals = {'start_time': values['start_time'] + (0.001 * i),'conn_uuid': values['conn_uuid'],'tag': t, 'is_blocked' : values['is_blocked'],'interface': values['interface'],'vlanid': values['vlanid'],'dst_nbytes': values['dst_nbytes'],'dst_npackets': values['dst_npackets'],
                                'src_hwaddr': values['src_hwaddr'],'dst_hwaddr': values['dst_hwaddr'],'cloud_policyid':values['cloud_policyid']}
                        cur_e.execute('insert into conn_all_security_tags(start_time,conn_uuid,tag,is_blocked,interface,vlanid,dst_nbytes,dst_npackets,src_hwaddr,dst_hwaddr,cloud_policyid) values(:start_time,:conn_uuid,:tag,:is_blocked,:interface,:vlanid,:dst_nbytes,:dst_npackets,:src_hwaddr,:dst_hwaddr,:cloud_policyid)',vals)      
            except Exception as e:
                print(values['start_time'])
                logger.error(" Exception sqlite Execute: " + db_file + ":"+ path + ":"+ repr(e))
            
        conn.commit()    
        conn.close()
        logger.info('file:%s inserted %d records' % (db_file,len(lines)))

    except Exception as e:
        logger.error(" Exception sqlite: " + repr(e))


# mongodb insert function
async def mongo_insert(path,data):
    logger.info('Starting mongo insert function')
    global db
    try:
        if 'dns' in path:
            col = db.dns_all
        elif 'conn' in path:
            col = db.conn_all
        elif 'alert' in path:
            col = db.alert_all
        elif 'http' in path:
            col = db.http_all
        elif 'sip' in path:
            col = db.sip_all
        elif 'tls' in path:
            col = db.tls_all
        else:
            logger.critical(" unknown index: " + path)
            return False
        
        # time_part = time.strftime('%y%m%d')
        lines = data.splitlines()
        l = [json.loads(a) for a in lines if len(a) != 13]
        if "policyid" in l:
            l["policyid"] = int(l["policyid"])
        # col.insert_many(l)
        result = await col.insert_many(l)
        logger.info('col:%s inserted %d docs' % (col.name,len(result.inserted_ids),))

    except Exception as e:
        logger.error(" Exception Mongodb: " + repr(e))

# --- mongodb insert function

async def process_dist(file_list):
    
    for path in file_list:
        logger.warning('File: %s Size: %d' % (path, os.path.getsize(path)))
        t1 = time.perf_counter()
        if 'dns' in path:
            endpoint_index = dns_index_name if DB_TYPE == 'ES' else ''
            endpoint_doc = 'dns'
        elif 'conn' in path:
            endpoint_index = conn_index_name if DB_TYPE == 'ES' else ''
            endpoint_doc = 'conn'
        elif 'alert' in path:
            endpoint_index = alert_index_name if DB_TYPE == 'ES' else ''
            endpoint_doc = 'alert'
        elif 'http' in path:
            endpoint_index = http_index_name if DB_TYPE == 'ES' else ''
            endpoint_doc = 'http'
        elif 'sip' in path:
            endpoint_index = sip_index_name if DB_TYPE == 'ES' else ''
            endpoint_doc = 'sip'
        elif 'tls' in path:
            endpoint_index = tls_index_name if DB_TYPE == 'ES' else ''
            endpoint_doc = 'tls'
        else:
            logger.critical(" unknown index: " + path)
            continue
        
        if os.path.exists(path):
            async with aiofiles.open(path, mode='r') as f:
                data = await f.read()
        else:
            logger.info(f"file not found {path}")
            continue
                
        if 'http' in path:
            data = data.replace('\\', '\\\\')

        if DB_TYPE == "ES" or stream_report_data_external_enabled == 'true':
            logger.info("Elasticsearch insert")
            await es_insert(data,DB_TYPE == "ES",endpoint_index=endpoint_index, endpoint_doc=endpoint_doc)
        if DB_TYPE == "MN":
            logger.info("Mongdb insert")
            await mongo_insert(path,data)

        if DB_TYPE == "SQ":
            logger.info("sqlite insert")
            ipdrstreamer_stop = f"{tempfile.gettempdir()}/sqlite.retire"
            if not os.path.exists(ipdrstreamer_stop):
                await sqlite_insert(path,data)
            else:
                logger.warning("Sqlite retire process continue...")
                await asyncio.sleep(5)
                continue        


        if stream_report_enabled == 'true' and syslog_server and syslog_port.isdigit():
            logger.warning("send data to syslog server")
            if 'dns' in path:
                index_name = 'dns'
            elif 'conn' in path:
                index_name = 'conn'
            elif 'alert' in path:
                index_name = 'alert'
            elif 'http' in path:
                index_name = 'http'
            elif 'sip' in path:
                index_name = 'sip'
            elif 'tls' in path:
                index_name = 'tls'
            else:
                index_name = 'unknow'
                logger.critical(" unknown index: " + path)
            if index_name in syslog_indexes:
                data = data.replace(':','')
                # clean non utf-8 characters.
                # data = ''.join(x for x in data if x in string.printable)
                lines = data.splitlines()
                for line in lines:
                    syslogger.log(logging.INFO, 'index=%s, data=%s' % (index_name, line))
                logger.warning("send data to syslog server lines count %d of index %s" % (len(lines),index_name))    

        if os.path.exists(path):
            os.remove(path)
            #shutil.move(path,"/root/" + os.path.basename(path))
        elapsed = time.perf_counter() - t1
        logger.warning(f"Total time {elapsed:0.2f} seconds for {path}.")

async def async_main(f_list: dict,loop):
    tasks = []    
    tasks.append(loop.create_task(process_dist(f_list.get('dns'))))
    tasks.append(loop.create_task(process_dist(f_list.get('conn'))))
    tasks.append(loop.create_task(process_dist(f_list.get('alert'))))
    tasks.append(loop.create_task(process_dist(f_list.get('http'))))
    tasks.append(loop.create_task(process_dist(f_list.get('sip'))))
    tasks.append(loop.create_task(process_dist(f_list.get('tls'))))
    if len(tasks) > 0:
        await asyncio.gather(*tasks)


signal.signal(signal.SIGTERM, signal_handler)

pid = os.getpid()

close_requested = False

#Relative or absolute path to the directory
dir_path = eastpect_root + '/output/active/temp/'

last = dt.datetime.now()
loop = asyncio.get_event_loop()
while True:
    logger.info("----" + last.isoformat() + " waiting data...")
    time.sleep(1)

    if close_requested == True:
        break
    try:
        #all entries in the directory w/ stats
        data = (os.path.join(dir_path, fn) for fn in glob.glob(dir_path + '/*.ready'))
        data = ((os.path.getctime(path), path) for path in data)
        file_list = []
        for cdate, path in sorted(data):
            if dt.datetime.fromtimestamp(cdate) >= last:
                file_list.append(path)

        last = dt.datetime.now()
        f_index_list = {'dns':[],'conn': [], 'alert': [], 'http': [], 'sip': [], 'tls': []}
        for path in file_list:
            if 'dns' in path:
                f_index_list['dns'].append(path)
            elif 'conn' in path:
                f_index_list['conn'].append(path)
            elif 'alert' in path:
                f_index_list['alert'].append(path)
            elif 'http' in path:
                f_index_list['http'].append(path)
            elif 'sip' in path:
                f_index_list['sip'].append(path)
            elif 'tls' in path:
                f_index_list['tls'].append(path)
            logger.warning(" sending bulk: " + path)
        
        loop.run_until_complete(async_main(f_index_list,loop))
        
    except FileNotFoundError as e:    
        logger.critical('%s %s ' % (" ASYNC ERR: ", repr(e)))
        exit(1)
    except Exception as e:
        logger.critical('%s %s ' % (" IPDRSTREAM STOP: ", repr(e)))

print("%s Bulk inserter python finished.")
