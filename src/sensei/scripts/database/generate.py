#!/usr/local/sensei/py_venv/bin/python3
from jinja2 import Environment, FileSystemLoader
from database import Database
from hashlib import sha256
import os

TEMPLATES_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'templates')
TEMPLATE_ENGINE = Environment(autoescape=False, loader=FileSystemLoader(TEMPLATES_DIR), trim_blocks=True)

TEMPLATES = {
    'rc.conf.d/eastpect': '/etc/rc.conf.d/eastpect',
    'rc.conf.d/elasticsearch': '/etc/rc.conf.d/elasticsearch',
    'eastpect.cfg': '/usr/local/sensei/etc/eastpect.cfg',
    'workers.map.bak': '/usr/local/sensei/etc/workers.map.bak'
}


def sha256sum(input):
    return sha256(input.encode('utf-8')).hexdigest()


TEMPLATE_ENGINE.filters['sha256'] = sha256sum

configurations = {}
interfaces = []

with Database() as database:
    # Read configurations
    database.cursor.execute('SELECT key, value FROM configurations')
    configuration_records = database.cursor.fetchall()
    for record in configuration_records:
        configurations[record['key']] = record['value']
    # Read interfaces
    database.cursor.execute('SELECT interface, manage_port, cpu_index FROM interfaces')
    interfaces = database.cursor.fetchall()

for template in TEMPLATES.keys():
    with open(TEMPLATES[template], 'w') as file:
        file.write(TEMPLATE_ENGINE.get_template(template).render(configurations=configurations, interfaces=interfaces))
