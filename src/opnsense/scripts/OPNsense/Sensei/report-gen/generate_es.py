#!/usr/local/sensei/py_venv/bin/python3
import sys
python_version = sys.version
from jinja2 import Environment, FileSystemLoader
from datetime import datetime, timedelta
from configparser import ConfigParser
mail_config = ConfigParser()
config = ConfigParser()
import requests
import codecs
import pygal
import json
import time
import re
import os
import sqlite3
from base64 import b64decode
import xml.etree.ElementTree
import urllib3
from base64 import b64decode
from requests.auth import HTTPBasicAuth

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

SENSEI_CFG = os.path.join('/usr','local','sensei','etc', 'eastpect.cfg')
config.read(SENSEI_CFG)

ES_HOST_LOCAL = '%s:%s/' % (config.get('ElasticSearch', 'apiEndPointIP'), config.get('ElasticSearch', 'apiEndPointPort'))
PREFIX = config.get('ElasticSearch', 'apiEndPointPrefix')
ES_USER = config.get('ElasticSearch', 'apiEndPointUser')
ES_PASS = config.get('ElasticSearch', 'apiEndPointPass')
try:
    ES_VERSION = int(config.get('ElasticSearch', 'apiEndPointVersion'))
except:
    ES_VERSION = 56800

if ES_PASS != '' and ES_PASS[0:4] == 'b64:':
    ES_PASS = b64decode(config.get('ElasticSearch', 'apiEndPointPass')[4:None]).decode('utf-8')

ES_AUTH = None
if ES_USER != '' and ES_PASS != '':
    ES_AUTH = HTTPBasicAuth(ES_USER, ES_PASS)

CONFIG_XML = '/conf/config.xml'
config_tree= xml.etree.ElementTree.parse(CONFIG_XML)
timer = '45 0 * * *'
DURATION = 86400000
INTERVAL = '1h'
v_timedelta = 1
for node in config_tree.findall('.//Sensei/reports/generate'):
    timer = node.find('timer').text

if timer[-1] != '*':
   DURATION = 86400000 * 7 
   INTERVAL = '1d'
   v_timedelta = 7

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
TEMPLATE_ENGINE = Environment(autoescape=False, loader=FileSystemLoader(BASE_DIR), trim_blocks=False)
MAIL_CONFIG = os.path.join(BASE_DIR, 'mail.conf')
mail_config.read(MAIL_CONFIG)
CRITERIA = mail_config.get('general', 'Criteria')

PDF_URI = 'https://health.sunnyvalley.io/client_pdf_creator.php'
NOW = int(round(time.time() * 1000))
TZ = timedelta(hours=int(time.strftime('%z')[1:3]), minutes=int(time.strftime('%z')[3:5]))
if time.strftime('%z')[0] == '-':
    TZ = TZ * -1
TZ_STR = time.strftime('%z')[:3] + ':' + time.strftime('%z')[3:]
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


def set_time(query):
    query = query.replace('__LTE__',str(NOW))
    query = query.replace('__GTE__',str(NOW - DURATION))
    '''
    if ES_VERSION >= 80000 and ES_VERSION < 83000:
        query = query.replace('"interval"','"calendar_interval"')
    if ES_VERSION > 83000:
        query = query.replace('"interval"','"fixed_interval"')
    '''    
    if ES_VERSION >= 72000:
        query = query.replace('"interval"','"calendar_interval"')
    query = query.replace('__INTERVAL__',INTERVAL)
    query = query.replace('__TZ__','"%s"' % TZ_STR)
    return query


def set_criteria(query, index, type):
    if query.find('cardinality') > -1:
        return json.loads(query)
    query = json.loads(query)    
    if index == 'conn' and CRITERIA in ['volume', 'packets'] and type not in ['custom']:
        query['aggs']['sumtotal'] = {
            'sum': {
                'field': 'dst_nbytes' if CRITERIA == 'volume' else 'dst_npackets'
            }
        }
        if 'aggs' in query['aggs']['results'] and 'results' in query['aggs']['results']['aggs']:
            query['aggs']['results']['aggs']['results']['aggs'] = {
                'sumresults': {
                    'sum': {
                        'field': 'dst_nbytes' if CRITERIA == 'volume' else 'dst_npackets'
                    }
                }
            }
        if 'aggs' not in query['aggs']['results']:
            query['aggs']['results']['aggs'] = {}
        query['aggs']['results']['aggs']['sumresults'] = {
            'sum': {
                'field': 'dst_nbytes' if CRITERIA == 'volume' else 'dst_npackets'
            }
        }
        if 'terms' in query['aggs']['results']:
            query['aggs']['results']['terms']['order'] = {
                'sumresults': 'desc'
            }
    if index == 'http' and CRITERIA == 'volume':
        query['aggs']['sumtotal'] = {
            'sum': {
                'field': 'rsp_body_len'
            }
        }
        if 'aggs' in query['aggs']['results'] and 'results' in query['aggs']['results']['aggs']:
            query['aggs']['results']['aggs']['results']['aggs'] = {
                'sumresults': {
                    'sum': {
                        'field': 'rsp_body_len'
                    }
                }
            }
        if 'aggs' not in query['aggs']['results']:
            query['aggs']['results']['aggs'] = {}
        query['aggs']['results']['aggs']['sumresults'] = {
            'sum': {
                'field': 'rsp_body_len'
            }
        }
        query['aggs']['results']['terms']['order'] = {
            'sumresults': 'desc'
        }
    return query


def execute_query(query, index):
    index = config.get('ElasticSearch', 'apiEndPointPrefix') + index
    resp = requests.post('%s:%s/%s_all/_search' % (config.get('ElasticSearch', 'apiEndPointIP'),config.get('ElasticSearch', 'apiEndPointPort'),index), headers={'Content-Type': 'application/json'},data=json.dumps(query), timeout=30,verify=False,auth=ES_AUTH)
    return json.loads(resp.text)

def execute_graph(data, type):
    resp = requests.post('https://localhost/api/sensei/schedulereports/%s' % type, headers={'Content-Type': 'application/json'},
                         data=json.dumps(data), timeout=30)
    return json.loads(resp.text)


def byte_format(size):
    power = 2**10
    n = 0
    Dic_powerN = {0: 'B', 1: 'KB', 2: 'MB', 3: 'GB', 4: 'TB'}
    while size > power:
        size /= power
        n += 1
    return '%s %s' % (str(round(size, 2)), Dic_powerN[n])

def generate_es(PDF,logging):
    with open(os.path.join(BASE_DIR, 'indices.json')) as file:
        reports_config = json.load(file)
        # reports_config.sort(key=lambda x: x['order'] if 'order' in x else 99)

    reports = []
    reportsData = []
    conn_facts = []
    tables_data = []
    

    for r in reports_config:
        logging.info('preparing report %s', r['name'])
        if not r['enabled']:
            continue
        graphData = json.loads('{}')

        logging.info('Generated: %s => %s => %s' % (r['index'], r['name'],r['type']))
        filepath = os.path.join(BASE_DIR, 'queries_es/%s/%s.json' % (r['index'], r['name']))
        if not os.path.exists(filepath):
            logging.error(f"Report Name: {r['name']}, {filepath} not found.")
            continue    

        with open(filepath, 'r') as file:
            data = file.read()
            data = set_time(data)
            data = set_criteria(data, r['index'], r['type'])
            if r['name'] == 'conn_facts' and ES_VERSION > 80000:
                data["track_total_hits"] = True
            data = execute_query(data, r['index'] if r['index'] != 'threat' else 'conn')
        
        graphData['name'] = r['name']

        if r['name'] == 'conn_facts':
            #conn_facts.append(['Connections', '{:,}'.format(int(round(data['aggregations']['l']['buckets'][0]['doc_count'])))])
            if 'hits' in data and 'total' in data['hits'] and type(data['hits']['total']) == int:    
                conn_facts.append(['Connections', '{:,}'.format(int(round(data['hits']['total'])))])
            else:
                conn_facts.append(['Connections', '{:,}'.format(int(round(data['hits']['total']['value'])))])
            conn_facts.append(['Bytes Uploaded', byte_format(data['aggregations']['a']['value'])])
            conn_facts.append(['Bytes Downloaded', byte_format(data['aggregations']['b']['value'])])
            conn_facts.append(['Packets Uploaded', '{:,}'.format(int(round(data['aggregations']['c']['value'])))])
            conn_facts.append(['Packets Downloaded', '{:,}'.format(int(round(data['aggregations']['d']['value'])))])
            conn_facts.append(['Unique Local Hosts', '{:,}'.format(int(round(data['aggregations']['e']['value'])))])
            conn_facts.append(['Unique Remote Hosts', '{:,}'.format(int(round(data['aggregations']['f']['value'])))])
            conn_facts.append(['Unique Apps', '{:,}'.format(int(round(data['aggregations']['g']['value'])))])
            graphData['data'] = conn_facts
            tables_data.append({'title': 'Quick Facts (%s)' % mail_config.get('general', 'HostName'),'header':[],'value':conn_facts})
            reportsData.append(graphData)
            continue

        if r['name'] == 'conn_table_apps':
            conn_table_apps = []
            table_headers = ['Apps','Sessions','Unique Local Hosts','Unique Destinations','Bytes OUT','Bytes IN','Pkts OUT','Pkts IN']
            if 'aggregations' in data:
                for bucket in data['aggregations']['results']['buckets']:
                    conn_table_apps.append([bucket['key'], bucket['doc_count'],
                        bucket['a']['value'],bucket['b']['value'],
                        byte_format(bucket['c']['value']),
                        byte_format(bucket['d']['value']),bucket['e']['value'],bucket['f']['value']])

            tables_data.append({'title': 'Table of Apps','header':table_headers,'value':conn_table_apps})                        
            continue

        if r['name'] == 'conn_table_local_assets':
            conn_table_apps = []
            table_headers = ['Local Hosts','Sessions','Unique Remote Hosts','Unique Apps'
                ,'Bytes OUT','Bytes IN','Pkts OUT','Pkts IN']
            if 'aggregations' in data:
                for bucket in data['aggregations']['results']['buckets']:
                    conn_table_apps.append([bucket['key'], bucket['doc_count'],
                        bucket['a']['value'],bucket['b']['value'],
                        byte_format(bucket['c']['value']),
                        byte_format(bucket['d']['value']),bucket['e']['value'],bucket['f']['value']])

            tables_data.append({'title': 'Table of Local Assets','header':table_headers,'value':conn_table_apps})                        
            continue

        if r['name'] == 'conn_table_remote_hosts':
            conn_table_apps = []
            table_headers = ['Remote Hosts','Sessions','Unique Remote Hosts','Unique Apps'
                ,'Bytes OUT','Bytes IN','Pkts OUT','Pkts IN']
            if 'aggregations' in data:
                for bucket in data['aggregations']['results']['buckets']:
                    conn_table_apps.append([bucket['key'], bucket['doc_count'],
                        bucket['a']['value'],bucket['b']['value'],
                        byte_format(bucket['c']['value']),
                        byte_format(bucket['d']['value']),bucket['e']['value'],bucket['f']['value']])

            tables_data.append({'title': 'Table of Remote Assets','header':table_headers,'value':conn_table_apps})                        
            continue

        if r['name'] == 'http_table_sites':
            conn_table_apps = []
            table_headers = ['Host','Count','Local Hosts','Request Bytes','Response Bytes']
            if 'aggregations' in data:
                for bucket in data['aggregations']['results']['buckets']:
                    conn_table_apps.append([bucket['key'], bucket['doc_count'],
                        bucket['a']['value'],byte_format(bucket['b']['value']),
                        byte_format(bucket['c']['value'])])

            tables_data.append({'title': 'Web - Table of Sites','header':table_headers,'value':conn_table_apps})                        
            continue

        if r['name'] == 'http_table_uris':
            conn_table_apps = []
            table_headers = ['URIs','Number of Hits','Response Body Size']
            if 'aggregations' in data:
                for bucket in data['aggregations']['results']['buckets']:
                    conn_table_apps.append([bucket['key'], bucket['doc_count'],
                        byte_format(bucket['a']['value'])])

            tables_data.append({'title': 'Web - Table of URIs','header':table_headers,'value':conn_table_apps})                        
            continue


        graphData['type'] = r['type']
        if r['type'] == 'doughnut':
            _values = []
            chart = pygal.Pie(inner_radius=.4)
            sum = 0
            if 'aggregations' in data:
                for bucket in data['aggregations']['results']['buckets']:
                    label = str(bucket['key']) if str(bucket['key']) else 'blank'
                    if 'policies' in r['name']:
                        if label != 'blank':
                            policy_names = [x for x in policies if x['id'] == int(label)]
                            if len(policy_names) > 0:
                                label = policy_names[0]['name']
                    value = bucket['sumresults']['value'] if 'sumresults' in bucket else bucket['doc_count']
                    chart.add(label, value)
                    _values.append({'value':value,'label':label})
                    sum += value
                total = data['aggregations']['sumtotal']['value'] if 'sumtotal' in data['aggregations'] else data['hits']['total']['value'] if type(data['hits']['total']) is dict else data['hits']['total']
                if sum < total:
                    chart.add('OTHERS', total - sum)
                    _values.append({'label':'OTHERS','value':total - sum})
                    # for remote reports    
                graphData['data'] = json.loads('{"_values":""}')
                graphData['data']['_values'] = _values

        if r['type'] == 'doughnut-multi':
            _values = []
            chart = pygal.Pie(inner_radius=.4)
            sum = 0
            if 'aggregations' in data:
                value = []
                for bucket in data['aggregations']['results']['buckets']:
                    label = str(bucket['key']) if str(bucket['key']) else 'blank'
                    if 'vlans' in bucket:
                        for _sub_bucket in bucket['vlans']['buckets']:
                            sum += _sub_bucket['sumresults']['value'] if 'sumresults' in _sub_bucket else _sub_bucket['doc_count']
                            value.append(_sub_bucket['sumresults']['value'] if 'sumresults' in _sub_bucket else _sub_bucket['doc_count'])
                    else:
                        value.append(bucket['sumresults']['value'] if 'sumresults' in bucket else bucket['doc_count'])        
                        sum += bucket['sumresults']['value'] if 'sumresults' in bucket else bucket['doc_count']
                    # value = bucket['sumresults']['value'] if 'sumresults' in bucket else bucket['doc_count']
                    chart.add(label, value)
                    _values.append({'value':value,'label':label})
                    # for remote reports    
                graphData['data'] = json.loads('{"_values":""}')
                graphData['data']['_values'] = _values

        if r['type'] == 'line-1':
            chart = pygal.Line()
            chart.x_labels = X_AXIS
            graphData['data'] = json.loads('{"x_labels":"","chart_values":""}')
            graphData['data']['x_labels'] = X_AXIS
            graphData['data']['chart_values'] = []

            for bucket in data['aggregations']['results']['buckets']:
                values = {}
                chart_values = []
                for sub_bucket in bucket['results']['buckets']:
                    time_str = datetime.utcfromtimestamp(sub_bucket['key'] / 1000) + TZ
                    time_str = time_str.strftime('%H:00')
                    value = sub_bucket['sumresults']['value'] if 'sumresults' in sub_bucket else sub_bucket['doc_count']
                    values[time_str] = value
                for h in X_AXIS:
                    chart_values.append(values[h] if h in values else None)
                chart.add(str(bucket['key']) if str(bucket['key']) else 'BLANK', chart_values)
                #for remote 
                tmp = json.loads('{"key":"","value":""}')
                tmp['key'] = str(bucket['key']) if str(bucket['key']) else 'BLANK'
                tmp['value'] = chart_values
                graphData['data']['chart_values'].append(tmp)

        if r['type'] == 'line-2':
            chart = pygal.Line()
            chart.x_labels = X_AXIS

            graphData['data'] = json.loads('{"x_labels":"","chart_values":""}')
            graphData['data']['x_labels'] = X_AXIS

            if r['name'] == 'unique_remote_hosts':
                values = {}
                chart_values = []
                if 'aggregations' in data:
                    for bucket in data['aggregations']['results']['buckets']:
                        time_str = datetime.utcfromtimestamp(bucket['key'] / 1000) + TZ
                        time_str = time_str.strftime('%H:00')
                        values[time_str] = bucket['doc_count']
                for h in X_AXIS:
                    chart_values.append(values[h] if h in values else None)
                chart.add('New Connections', chart_values)
                graphData['data']['chart_values'] = chart_values


            values = {}
            chart_values = []
            if 'aggregations' in data:
                for bucket in data['aggregations']['results']['buckets']:
                    time_str = datetime.utcfromtimestamp(bucket['key'] / 1000) + TZ
                    time_str = time_str.strftime('%H:00')
                    values[time_str] = bucket['results']['value']
            for h in X_AXIS:
                chart_values.append(values[h] if h in values else None)
            
            tmp = json.loads('{"key":"","value":""}')
            if r['name'] == 'unique_local_hosts':
                chart.add('Unique Local Hosts', chart_values)
                graphData['data']['chart_values'] = chart_values

            if r['name'] == 'unique_remote_hosts':
                chart.add('Unique Remote Hosts', chart_values)
                graphData['data']['chart_values'] = chart_values

        if r['type'] == 'treemap':
            chart = pygal.Treemap()
            graphData['data'] = []

            values = {}
            if 'aggregations' in data:
                for bucket in data['aggregations']['results']['buckets']:
                    x_val = bucket['key'].split('---')[0]
                    y_val = bucket['key'].split('---')[1]
                    if x_val not in values:
                        values[x_val] = {
                            'values': []
                        }
                    values[x_val]['values'].append({
                        'label': y_val,
                        'value': bucket['doc_count']
                    })
            
            tmp = json.loads('{"key":"","value":""}')
            for k in values.keys():
                chart.add(str(k) if str(k) else 'BLANK', values[k]['values'])
                tmp['key'] = str(k) if str(k) else 'BLANK'
                tmp['value'] = values[k]['values']
                graphData['data'].append(tmp)


        if r['type'] == 'stacked':
            chart = pygal.StackedBar()

            y_labels = {}
            x_labels = {}
            graphData['data'] = json.loads('{"x_labels":"","y_labels":[]}')
            tmp = json.loads('{"key":"","value":""}')
            if 'aggregations' in data:
                for bucket in data['aggregations']['results']['buckets']:
                    x_val = bucket['key'].split('---')[0]
                    y_val = bucket['key'].split('---')[1]
                    y_val = y_val if y_val else 'BLANK'
                    if y_val not in y_labels:
                        y_labels[y_val] = {}
                    y_labels[y_val][x_val] = bucket['doc_count']
                    x_labels[x_val] = None
            
            for kk in y_labels.keys():
                for k in x_labels.keys():
                    if k not in y_labels[kk]:
                        y_labels[kk][k] = None
                chart.add(str(kk) if str(kk) else 'BLANK', y_labels[kk].values())
                #for remote
                tmp['key'] = str(k) if str(k) else 'BLANK'
                tmp['value'] = json.dumps(list(y_labels[kk].values()))
                graphData['data']['y_labels'].append(tmp)

            chart.x_labels = x_labels.keys()
            graphData['data']['x_labels'] = json.dumps(list(x_labels.keys()))

        if r['type'] == 'bar':
            chart = pygal.HorizontalBar()
            graphData['data'] = []
            tmp = json.loads('{"key":"","value":""}')
            if 'aggregations' in data:
                for bucket in data['aggregations']['results']['buckets']:
                    chart.add(str(bucket['key']) if str(bucket['key']) else 'BLANK', bucket['doc_count'])
                    #for remote
                    tmp['key'] = str(bucket['key']) if str(bucket['key']) else 'BLANK'
                    tmp['value'] = bucket['doc_count']
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
