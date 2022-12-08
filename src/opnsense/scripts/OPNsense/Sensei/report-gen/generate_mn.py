#!/usr/local/sensei/py_venv/bin/python3
import sys
python_version = sys.version
from jinja2 import Environment, FileSystemLoader
from datetime import datetime, timedelta
from configparser import ConfigParser
mail_config = ConfigParser()
import requests
import codecs
import pygal
import json
import time
import re
import os
import sqlite3
import xml.etree.ElementTree
import logging
import urllib3
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

EASTPECT_ROOT = '/usr/local/sensei'
if 'EASTPECT_ROOT' in os.environ:
    EASTPECT_ROOT = os.environ.get('EASTPECT_ROOT')

EASTPECT_DB = os.path.join(EASTPECT_ROOT, 'userdefined', 'config', 'settings.db')
conn = sqlite3.connect(EASTPECT_DB)
conn.row_factory = sqlite3.Row
cur_p = conn.cursor()
cur_p.execute("select * from policies order by sort_number")
policies = []
for row_p in cur_p:
    policies.append({'id': row_p['id'],'name': row_p['name']})

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
TEMPLATE_ENGINE = Environment(autoescape=False, loader=FileSystemLoader(BASE_DIR), trim_blocks=False)
MAIL_CONFIG = os.path.join(BASE_DIR, 'mail.conf')
mail_config.read(MAIL_CONFIG)
CRITERIA = mail_config.get('general', 'Criteria')
PDF_URI = 'https://health.sunnyvalley.io/client_pdf_creator.php'

QUERY_FILENAME = "/tmp/schedule_query.json"
RESULT_FILENAME = "/tmp/schedule_result.json"
NOW = int(round(time.time() * 1000))
TZ = timedelta(hours=int(time.strftime('%z')[1:3]), minutes=int(time.strftime('%z')[3:5]))
if time.strftime('%z')[0] == '-':
    TZ = TZ * -1
TZ_STR = time.strftime('%z')[:3] + ':' + time.strftime('%z')[3:]

CONFIG_XML = '/conf/config.xml'
config_tree= xml.etree.ElementTree.parse(CONFIG_XML)
timer = '45 0 * * *'
DURATION = 86400000
INTERVAL = 3600000
v_timedelta = 1
for node in config_tree.findall('.//Sensei/reports/generate'):
    timer = node.find('timer').text

if timer[-1] != '*':
   DURATION = 86400000 * 7 
   INTERVAL = 3600000 * 24
   v_timedelta = 7

X_AXIS = list(reversed([(datetime.now() - timedelta(hours=h)).strftime('%H:00') for h in range(24)]))
HOST_IP = 'your_firewall_ip'

try:
    HOST_IP = os.popen('ifconfig %s | grep -v inet6 | grep inet | awk \'{print $2}\'' % mail_config.get('general', 'LanInterface')).read().strip()
except:
    pass

SENSEI_STYLE = pygal.style.Style(
    background='transparent',
    plot_background='transparent',
    foreground='#7d7d7d',
    foreground_strong='#4d4d4d',
    foreground_subtle='#4b4b4b',
    opacity='.8',
    legend_font_size=20,
    no_data_font_size=24,
    font_family='\'Lato\',Tahoma,Verdana,Segoe,sans-serif',
    colors=('#3366cc', '#dc3912', '#ff9900', '#109618', '#990099', '#0099c6', '#dd4477', '#66aa00', '#b82e2e', '#316395', '#AEB6BF')
)


def set_time(query, chart):
    query = query.replace('___END_TIME___', str(NOW))
    query = query.replace('___START_TIME___', str(NOW - DURATION))
    query = query.replace('___INTERVAL___', str(INTERVAL))
    return query


def set_criteria(query, index, type):
    if CRITERIA == 'sessions':
        if '"total": "___SUM_FIELD___"' in query:
            query = query.replace(',"total": "___SUM_FIELD___"', '').replace('"$sum": "$total"','"$sum":1')
        else:
            query = query.replace('"___SUM_FIELD___"', '1')
    
    if index == 'conn' and CRITERIA in ['volume', 'packets'] and type not in ['custom']:
        query = query.replace('___SUM_FIELD___', '$dst_nbytes' if CRITERIA == 'volume' else '$dst_npackets')
       
    if index == 'http' and CRITERIA in ['volume', 'packets']:
        query = query.replace('___SUM_FIELD___', '$rsp_body_len')
    
    query = query.replace('"___SUM_FIELD___"', '1')    
    return query


def execute_query(db,query, index):
    total = 0
    result = []
    try:
        cursor = eval(query,{'null':None,'db': db})
        for doc in cursor:
            if 'total' in doc:
                total += doc['total']
            result.append(doc)
    except Exception as e:
        logging.error(f'Mongo Query Error: {repr(e)}')
        pass        
    return result,total

def byte_format(size):
    power = 2**10
    n = 0
    Dic_powerN = {0: 'B', 1: 'KB', 2: 'MB', 3: 'GB', 4: 'TB'}
    while size > power:
        size /= power
        n += 1
    return '%s %s' % (str(round(size, 2)), Dic_powerN[n])

def generate_mn(PDF,logging):
    try:
        from pymongo import MongoClient
        mongo_client = MongoClient()
        db = mongo_client.sensei
    except Exception as e:
        logging.error(f'Mongo connection error {repr(e)}')
        return False

    with open(os.path.join(BASE_DIR, 'indices.json')) as file:
        reports_config = json.load(file)
        # reports_config.sort(key=lambda x: x['order'] if 'order' in x else 99)

    reports = []
    reportsData = []
    conn_facts = []
    tables_data = []

    for r in reports_config:
        logging.info('preparing report %s' % r['name'])
        if not r['enabled']:
            continue

        graphData = json.loads('{}')
        logging.info('Generated: %s => %s => %s' % (r['index'], r['name'],r['type']))
        filepath = os.path.join(BASE_DIR, 'queries_mn/%s/%s.query' % (r['index'], r['name']))
        if not os.path.exists(filepath):
            logging.error(f"Report Name: {r['name']}, {filepath} not found.")
            continue    
        
        with open(filepath, 'r') as file:
            query = file.read()
            query = set_time(query, r['type'])
            query = set_criteria(query, r['index'], r['type'])
            data,total = execute_query(db, query, r['index'])

        if len(data) == 0:
            logging.info(f"Zero data for graph {r['name']}")
            continue
        index = 0
        graphData['name'] = r['name']
        if r['name'] != 'conn_facts':
            for row in data:
                if 'label' in row['_id'] and type(row['_id']['label']) is int:
                    data[index]['_id']['label'] = '%d' % row['_id']['label']
                index += 1
        if r['name'] == 'conn_facts' and len(data) > 0:
            data = data[0]
            conn_facts.append(['Connections', '{:,}'.format(int(round(data['total'])))])
            conn_facts.append(['Bytes Uploaded', byte_format(data['a'])])
            conn_facts.append(['Bytes Downloaded', byte_format(data['b'])])
            conn_facts.append(['Packets Uploaded', '{:,}'.format(int(round(data['c'])))])
            conn_facts.append(['Packets Downloaded', '{:,}'.format(int(round(data['d'])))])
            conn_facts.append(['Unique Local Hosts', '{:,}'.format(int(len(data['e'])))])
            conn_facts.append(['Unique Remote Hosts', '{:,}'.format(int(len(data['f'])))])
            conn_facts.append(['Unique Apps', '{:,}'.format(int(len(data['g'])))])
            graphData['data'] = conn_facts
            continue

        if r['name'] == 'conn_table_apps':
            conn_table_apps = []
            table_headers = ['Apps','Sessions','Unique Local Hosts','Unique Destinations','Bytes OUT','Bytes IN','Pkts OUT','Pkts IN']
            for i,b in enumerate(data):
                conn_table_apps.append([b['_id']['app_name'], b['total'],
                    len(b['ip_src_saddr']),len(b['ip_dst_saddr']),
                    byte_format(b['total_src_nbytes']),
                    byte_format(b['total_dst_nbytes']),
                    b['total_src_npackets'],b['total_dst_npackets']])

            tables_data.append({'title': 'Table of Apps','header':table_headers,'value':conn_table_apps})                        
            continue

        if r['name'] == 'conn_table_local_assets':
            conn_table_apps = []
            table_headers = ['Local Hosts','Sessions','Unique Remote Hosts','Unique Apps'
                ,'Bytes OUT','Bytes IN','Pkts OUT','Pkts IN']
            for i,b in enumerate(data):
                key = f"{b['_id']['src_hostname']} ({b['_id']['ip_src_saddr']})" if  b['_id']['ip_src_saddr'] != b['_id']['src_hostname'] else b['_id']['src_hostname']
                conn_table_apps.append([key, b['total'],
                    len(b['src_hostname']),len(b['app_name']),
                    byte_format(b['total_src_nbytes']),
                    byte_format(b['total_dst_nbytes']),
                    b['total_src_npackets'],b['total_dst_npackets']])
                    
            tables_data.append({'title': 'Table of Local Assets','header':table_headers,'value':conn_table_apps})                        
            
            continue

        if r['name'] == 'conn_table_remote_hosts':
            conn_table_apps = []
            table_headers = ['Remote Hosts','Sessions','Unique Remote Hosts','Unique Apps'
                ,'Bytes OUT','Bytes IN','Pkts OUT','Pkts IN']
            for i,b in enumerate(data):
                key = f"{b['_id']['dst_hostname']} ({b['_id']['ip_dst_saddr']})" if  b['_id']['ip_dst_saddr'] != b['_id']['dst_hostname'] else b['_id']['dst_hostname']
                conn_table_apps.append([key, b['total'],
                    len(b['dst_hostname']),len(b['app_name']),
                    byte_format(b['total_src_nbytes']),
                    byte_format(b['total_dst_nbytes']),
                    b['total_src_npackets'],b['total_dst_npackets']])
                
            tables_data.append({'title': 'Table of Remote Assets','header':table_headers,'value':conn_table_apps})                        
            
            continue

        if r['name'] == 'http_table_sites':
            conn_table_apps = []
            table_headers = ['Host','Count','Local Hosts','Request Bytes','Response Bytes']
            for i,b in enumerate(data):
                conn_table_apps.append([b['_id']['host'],
                            b['total'],
                            len(b['src_hostname']),
                            b['total_req_body_len'],
                            b['total_rsp_body_len']])
            
            tables_data.append({'title': 'Web - Table of Sites','header':table_headers,'value':conn_table_apps})                        
            
            continue

        if r['name'] == 'http_table_uris':
            conn_table_apps = []
            table_headers = ['URIs','Number of Hits','Response Body Size']
            for i,b in enumerate(data):
                conn_table_apps.append([b['_id']['uri'],
                            b['total'],
                            b['total_rsp_body_len']])

            tables_data.append({'title': 'Web - Table of URIs','header':table_headers,'value':conn_table_apps})                        
            
            continue


        graphData['type'] = r['type']
        if r['type'] == 'doughnut':
            _values = []
            chart = pygal.Pie(inner_radius=.4)
            sum = 0

            for row in data:
                label = row['_id']['label'] if row['_id']['label'] != '' else 'anonymous' if 'src_username' in query or 'dst_username' in query else 'blank'
                if 'policies' in r['name']:
                    if label != 'blank':
                        policy_names = [x for x in policies if x['id'] == int(label)]
                        if len(policy_names) > 0:
                            label = policy_names[0]['name']
                
                value = row['total']
                if type(label) == float:
                    label = str(int(label))
                else:
                    label = str(label)
                chart.add(label, value)
                _values.append({'value':value,'label':label})
                sum += value
            graphData['data'] = json.loads('{"_values":""}')
            graphData['data']['_values'] = _values

        if r['type'] == 'doughnut-multi':
            _values = []
            chart = pygal.Pie(inner_radius=.4)
            sum = 0
            for row in data:
                label = f"{row['_id']['labels']}({row['_id']['label']})"
                value = row['total']
                chart.add(label, value)
                _values.append({'value':value,'label':label})
                sum += value
            graphData['data'] = json.loads('{"_values":""}')
            graphData['data']['_values'] = _values
                
        if r['type'] == 'line-1':
            chart = pygal.Line()
            chart.x_labels = X_AXIS
            graphData['data'] = json.loads('{"x_labels":"","chart_values":""}')
            graphData['data']['x_labels'] = X_AXIS
            graphData['data']['chart_values'] = []
            
            values = {}
            labels = []
            label_val = {}
            chart_values = []
            for row in data:
                if row['_id']['label'] not in labels:
                    tmp = {}
                    for h in X_AXIS:
                        tmp[h] = 0        
                    label_val[row['_id']['label']] = tmp;    
                    labels.append(row['_id']['label'])
                time_str = datetime.utcfromtimestamp(row['_id']['_interval'] * 36000) + TZ
                time_str = time_str.strftime('%H:00')
                label_val[row['_id']['label']][time_str] = row['total']
                values[time_str] = row['total']
            for (k, v) in label_val.items():
                tmp = []
                for (i, n) in v.items():
                    tmp.append(n)
                tmpout = json.loads('{"key":"","value":""}')
                tmpout['key'] = str(k)
                tmpout['value'] = tmp
                graphData['data']['chart_values'].append(tmpout)
                chart.add(str(k), tmp)        

        if r['type'] == 'line-2':
            chart = pygal.Line()
            chart.x_labels = X_AXIS

            graphData['data'] = json.loads('{"x_labels":"","chart_values":""}')
            graphData['data']['x_labels'] = X_AXIS
            graphData['data']['chart_values'] = []
            
            values = {}
            chart_values = []
            for row in data:
                time_str = datetime.utcfromtimestamp(row['_id']['_interval'] * 36000) + TZ
                time_str = time_str.strftime('%H:00')
                values[time_str] = len(row['label'])

            for h in X_AXIS:
                chart_values.append(values[h] if h in values else None)

            tmp = json.loads('{"key":"","value":""}')
            if r['name'] == 'unique_local_hosts':
                chart.add('Unique Local Hosts', chart_values)
                # tmp['key'] = 'Unique Local Hosts'
                # tmp['value'] = chart_values
                graphData['data']['chart_values'] = chart_values

            if r['name'] == 'unique_remote_hosts':
                chart.add('Unique Remote Hosts', chart_values)
                # tmp['key'] = 'Unique Local Hosts'
                # tmp['value'] = chart_values
                graphData['data']['chart_values'] = chart_values


        if r['type'] == 'stacked':
            chart = pygal.StackedBar()
            label_x = []
            label_y = []
            graphData['data'] = json.loads('{"x_labels":"","y_labels":[]}')
            for row in data:
                if row['_id']['label'] not in label_x:
                    label_x.append(row['_id']['label'])
                if row['_id']['label2'] not in label_y:
                    label_y.append(row['_id']['label2'])

            chart.x_labels = label_x
            graphData['data']['x_labels'] = label_x
            for l_y in label_y:
                val_y = []
                for x in label_x:
                    val_y.append(None)
                for row in data:
                    if row['_id']['label2'] == l_y:
                        index = label_x.index(row['_id']['label'])
                        val_y[index] = row['total']
                chart.add(l_y, val_y)
                graphData['data']['y_labels'].append({"key": l_y,"value": val_y})

        if r['type'] == 'bar':
            chart = pygal.HorizontalBar()
            graphData['data'] = []
            tmp = json.loads('{"key":"","value":""}')

            for row in data:
                chart.add(row['_id']['label'], row['total'])
                tmp['key'] = row['_id']['label']
                tmp['value'] = row['total']
                graphData['data'].append(tmp)


        svg = chart.render(is_unicode=True, x_label_rotation=-45, truncate_legend=24, min_scale=0, style=SENSEI_STYLE)

        reports.append({
            'title': r['title'],
            'data': re.sub('<script(.+)(script>|xlink:href(.+).js"/>)', '', svg).replace('<title>Pygal</title>', '')
        })
        graphData['title'] = r['title']
        reportsData.append(graphData)

    if PDF == True:
        tmp = {'reportsData': reportsData,'config':{'protocol':mail_config.get('general', 'HostProtocol'),'hostname':mail_config.get('general', 'HostName')},'date': {
                'from': (datetime.now() - timedelta(days=v_timedelta)).strftime('%B %d, %Y %H:%M'),
                'to': datetime.now().strftime('%B %d, %Y %H:%M'),
                'title': datetime.now().strftime('%m.%d.%Y')
            },'host_ip':HOST_IP}
        resp = requests.post(PDF_URI, headers={'Content-Type': 'application/json'},
                            data=json.dumps(tmp), timeout=120,verify=False)
        open(os.path.join(BASE_DIR, 'attachment.pdf'), 'wb').write(resp.content)
    else:                         
        with codecs.open(os.path.join(BASE_DIR, 'attachment.htm'), 'wb', 'utf-8') as file:
            file.write(TEMPLATE_ENGINE.get_template('attachment.template.htm').render({
                'reports': reports,
                'tables_data': tables_data,
                'conn_facts': conn_facts,
                'config': mail_config,
                'host_ip': HOST_IP,
                'date': {
                    'from': (datetime.now() - timedelta(days=v_timedelta)).strftime('%B %d, %Y %H:%M'),
                    'to': datetime.now().strftime('%B %d, %Y %H:%M'),
                    'title': datetime.now().strftime('%m.%d.%Y')
                }
            }))

    with codecs.open(os.path.join(BASE_DIR, 'body.htm'), 'wb', 'utf-8') as file:
        file.write(TEMPLATE_ENGINE.get_template('body.template.htm').render({
            'conn_facts': conn_facts,
            'config': mail_config,
            'date': (datetime.now() - timedelta(days=v_timedelta)).strftime('%m.%d.%Y')
        }))
