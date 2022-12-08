#!/usr/local/sensei/py_venv/bin/python3
"""
    Copyright (c) 2019 Hasan UCAK <hasan@sunnyvalley.io>
    All rights reserved from Zenarmor of Opnsense
    migration to every old version to 0.8.beta9

"""
import os
import sys
import json
import sqlite3

EASTPECT_ROOT = '/usr/local/sensei'
if 'EASTPECT_ROOT' in os.environ:
    EASTPECT_ROOT = os.environ.get('EASTPECT_ROOT')

EASTPECT_DB_DIR = os.path.join(EASTPECT_ROOT, 'userdefined', 'config');
EASTPECT_DB = os.path.join(EASTPECT_DB_DIR, 'settings.db')
MIGRATE_SQL= os.path.join(EASTPECT_ROOT, 'scripts', 'updater', 'opnsense', '18.1', 'migrate_0.8.0.9.sql')

if not os.path.exists(EASTPECT_DB_DIR):
    os.makedirs(EASTPECT_DB_DIR)

conn = sqlite3.connect(EASTPECT_DB)
conn.row_factory = sqlite3.Row
cur_p = conn.cursor()
cur_e = conn.cursor()
try:
    cur_p.execute("select version from sensei_db_version order by id desc limit 1")
    config_version = cur_p.fetchone()['version']
except Exception as e:
    config_version = ""

print("Current config version :  %s" % config_version)

if config_version == "":
    print("Create new database")
    exists = os.path.isfile(MIGRATE_SQL)
    if exists:
        sql_file = open(MIGRATE_SQL, "r")
        for sql_line in sql_file:
            cur_p.execute(sql_line)
    else:
        print ("% File not found" % MIGRATE_SQL)
        sys.exit(2)

    # Migrate application controls list
    with open(os.path.join(EASTPECT_ROOT, 'db', 'webui', 'apps.json')) as file:
        applications = json.load(file)
        applications = applications['apps']

    # Delete old applications which do not exist in new applications list
    new_applications = [application['name'] for application in applications]
    cur_p.execute('SELECT name FROM applications')
    current_applications = [application['name'] for application in cur_p.fetchall()]

    for application in current_applications:
        if application not in new_applications:
            cur_e.execute('DELETE FROM applications WHERE name=:name', {
                'name': application
            })

    # Delete old application categories which do not exist in new applications list
    new_app_categories = list(set([application['category'] for application in applications]))
    cur_p.execute('SELECT name FROM application_categories')
    current_app_categories = [category['name'] for category in cur_p.fetchall()]

    for category in current_app_categories:
        if category not in new_app_categories:
            cur_e.execute('DELETE FROM application_categories WHERE name=:name', {
                'name': category
            })

    # Delete old web 2.0 categories which do not exist in new applications list
    new_web_20_categories = list(set([application['web20'] for application in applications if application['web20'] != 'no']))
    cur_p.execute('SELECT name FROM web_20_categories')
    current_web_20_categories = [category['name'] for category in cur_p.fetchall()]

    for category in current_web_20_categories:
        if category not in new_web_20_categories:
            cur_e.execute('DELETE FROM web_20_categories WHERE name=:name', {
                'name': category
            })

    # Add new applications to database
    for application in applications:

        # Create or select application category id
        cur_p.execute('SELECT id, name FROM application_categories WHERE name=:name', {
            'name': application['category']
        })
        application_category = cur_p.fetchone()
        if application_category is None:
            cur_e.execute('INSERT INTO application_categories (name) VALUES (:name)', {
                'name': application['category']
            })
            application_category_id = cur_p.lastrowid
        else:
            application_category_id = application_category['id']

        # Create or select web 2.0 category id (if this application is web 2.0 application)
        if application['web20'] != 'no':
            cur_p.execute('SELECT id, name FROM web_20_categories WHERE name=:name', {
                'name': application['web20']
            })
            web_20_category = cur_p.fetchone()
            if web_20_category is None:
                cur_e.execute('INSERT INTO web_20_categories (name) VALUES (:name)', {
                    'name': application['web20']
                })
                web_20_category_id = cur_p.lastrowid
            else:
                web_20_category_id = web_20_category['id']
        else:
            web_20_category_id = None

        # Check if any application exists with this name
        cur_p.execute('SELECT id FROM applications WHERE name=:name', {
            'name': application['name']
        })

        # Create new application if no application exists with this name
        if cur_p.fetchone() is None:
            cur_e.execute('INSERT INTO applications (name) VALUES (:name)', {
                'name': application['name']
            })

        # Update application record anyway
        cur_e.execute('''UPDATE applications SET description=:description, web_20_category_id=:web_20_category_id,
                                   application_category_id=:application_category_id WHERE name=:name''', {
            'name': application['name'],
            'description': application['description'],
            'web_20_category_id': web_20_category_id,
            'application_category_id': application_category_id
        })

    # Migrate web categories control list
    with open(os.path.join(EASTPECT_ROOT, 'db', 'webui', 'webcats.json')) as file:
        web_categories = json.load(file)
        web_categories = web_categories['categories']

    # Delete old web categories which do not exist in new web categories list
    new_web_categories = [category['name'] for category in web_categories]
    cur_p.execute('SELECT name FROM web_categories')
    current_web_categories = [category['name'] for category in cur_p.fetchall()]

    for category in current_web_categories:
        if category not in new_web_categories:
            cur_e.execute('DELETE FROM web_categories WHERE name=:name', {
                'name': category
            })

    # Add new web categories to database
    for category in web_categories:

        # Check if any web category exists with this name
        cur_p.execute('SELECT id FROM web_categories WHERE name=:name', {
            'name': category['name']
        })

        # Create new web category if no web category exists with this name
        if cur_p.fetchone() is None:
            cur_e.execute('INSERT INTO web_categories (name) VALUES (:name)', {
                'name': category['name']
            })

        # Update web category record anyway
        cur_e.execute('UPDATE web_categories SET is_security_category=:is_security_category WHERE name=:name', {
            'name': category['name'],
            'is_security_category': 1 if category['security'] == 'yes' else 0
        })

cur_p.close()
cur_e.close()
conn.commit()
conn.close()
sys.exit(0)