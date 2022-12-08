#!/usr/local/sensei/py_venv/bin/python3
"""
    Copyright (c) 2019 Hasan UCAK <hasan@sunnyvalley.io>
    All rights reserved from Zenarmor of Opnsense
    migration to every old version to 0.8.beta9
    check app category and web category of policies.
"""
import os
import json
import sqlite3
import uuid
from shutil import copyfile
import time
import subprocess
from pprint import pprint

EASTPECT_ROOT = '/usr/local/sensei'
SENSEI_DB_DIR = EASTPECT_ROOT + '/db'
status, output = subprocess.getstatusoutput(f'{EASTPECT_ROOT}/bin/eastpect -p')
if status == 0:
    SENSEI_DB_DIR = output.strip()

print(f'Application database base path is {SENSEI_DB_DIR}')
APPS_JSON = os.path.join(SENSEI_DB_DIR, 'webui', 'apps.json')
DB_VERSION = os.path.join(SENSEI_DB_DIR,'VERSION')
WEBCAT_JSON = os.path.join(SENSEI_DB_DIR, 'webui', 'webcats.json')
webcat_migration_file = os.path.join(SENSEI_DB_DIR, 'webui', 'webcats_migration.json')

EASTPECT_DB_DIR = os.path.join(EASTPECT_ROOT, 'userdefined', 'config')
EASTPECT_DB = os.path.join(EASTPECT_DB_DIR, 'settings.db')

conn = sqlite3.connect(EASTPECT_DB)
conn.row_factory = sqlite3.Row
cur_p = conn.cursor()
cur_e = conn.cursor()
cur_s = conn.cursor()

#check and add webcategory and application category
distinct_app_list = []
application_web20_list = []
with open(APPS_JSON) as file:
    applications = json.load(file)
    applications = applications['apps']

for application in applications:
    if application['category'] not in [data['category'] for data in distinct_app_list]:
        distinct_app_list.append(application)
    if application['web20'] != 'no' and application['web20'] not in [data['web20'] for data in application_web20_list]:
        application_web20_list.append(application)

# ----------------application category new id ------------------------------------------

# take max id because of it should'nt confilt ids.
cur_e.execute('select id,name from application_categories')
curr_application_categories = cur_e.fetchall()

distinct_app_list.sort(key = lambda x:x['category_id'], reverse = True)
app_category_max_id = int(distinct_app_list[0]['category_id']) + 1

curr_application_categories.sort(key = lambda x:x['id'], reverse = True)
app_category_max_id += int(curr_application_categories[0]['id']) if len(curr_application_categories) > 0 else 0

print(f'Application category max id is {app_category_max_id}')
cur_e.execute('update application_categories set id=id + :max_id',{'max_id': app_category_max_id})
cur_e.execute('update applications set application_category_id=application_category_id + :max_id',{'max_id': app_category_max_id})
cur_e.execute('update custom_applications set application_category_id=application_category_id + :max_id',{'max_id': app_category_max_id})

cur_e.execute('select id,name from application_categories')
curr_application_categories = cur_e.fetchall()
cat_names = [data['name'] for data in curr_application_categories]
count = 0
for application_category in distinct_app_list:
    if application_category['category'] not in cat_names:
        count += 1
        cur_e.execute("insert into application_categories(id,name,uuid) values(:id,:name,:uuid)",
            {'id': application_category['category_id'],
             'name': application_category['category'],
             'uuid': str(uuid.uuid4())
            })
    else:
        l = [a for a in curr_application_categories if a['name'] == application_category['category']]
        if len(l) > 0:
            old_id = l[0]['id']
            cur_e.execute("update application_categories set id=:new_id where id=:old_id",{'new_id': application_category['category_id'],'old_id': old_id}),
            cur_e.execute('update applications set application_category_id=:new_id where application_category_id=:old_id',{'new_id': application_category['category_id'],'old_id': old_id})
            cur_e.execute('update custom_applications set application_category_id=:new_id where application_category_id=:old_id',{'new_id': application_category['category_id'],'old_id': old_id})
        
conn.commit()

if count > 0:
    print("\n%d application categories added." % count)

# ----------------application web 2.0 new id ------------------------------------------

# take max id because of it should'nt confilt ids.
cur_e.execute('delete from web_20_categories')

application_web20_list.sort(key = lambda x:x['id'], reverse = True)

# cur_e.execute('update applications set web_20_category_id=web_20_category_id + :max_id',{'max_id': web20_category_max_id})
for application_web20 in application_web20_list:
    cur_e.execute("insert into web_20_categories(name) values(:name)",{'name': application_web20['web20']})
        
conn.commit()
print("%d web 2.0 categories added." % len(application_web20_list))

# ----------------applications new id ------------------------------------------
cur_e.execute('select id,name,application_category_id from applications')
curr_applications = cur_e.fetchall()

applications.sort(key = lambda x:x['id'], reverse = True)
app_max_id = int(applications[0]['id']) + 1
curr_applications.sort(key = lambda x:x['id'], reverse = True)
app_max_id += int(curr_applications[0]['id']) if len(curr_applications) > 0 else 0


cur_e.execute('update applications set id=id + :max_id',{'max_id': app_max_id})
cur_e.execute('update policy_app_categories set application_id=application_id + :max_id',{'max_id': app_max_id})

cur_e.execute('select id,name,application_category_id from applications')
curr_applications = cur_e.fetchall()

count = 0
last_application_id = 0
for application in applications:
    # Create or select application category id
    cur_p.execute('SELECT id, name FROM application_categories WHERE name=:name', {
        'name': application['category']
    })
    application_category = cur_p.fetchone()
    application_category_id = application_category['id']

    # Create or select web 2.0 category id (if this application is web 2.0 application)
    if application['web20'] != 'no':
        cur_p.execute('SELECT id, name FROM web_20_categories WHERE name=:prm_name', {
            'prm_name': application['web20']
        })
        web_20_category = cur_p.fetchone()
        web_20_category_id = web_20_category['id']
    else:
        web_20_category_id = None
    
    if application['name'] not in [data['name'] for data in curr_applications]:
        try:
            cur_e.execute("insert into applications(id,name,description,application_category_id,web_20_category_id) values(:id,:name,:description,:application_category_id,:web_20_category_id)",{
                    'id': application['id'],
                    'name': application['name'],
                    'description': application['description'],
                    'application_category_id': application_category_id,
                    'web_20_category_id': web_20_category_id
                })
            last_application_id = int(application['id'])
            curr_applications.append(application)    
            count += 1
        except Exception as e:
            print('Warning: insert application categories error:%s' % e)
    else:
        l = [a for a in curr_applications if a['name'] == application['name']]
        if len(l) > 0:
            old_id = l[0]['id']
            cur_e.execute("update applications set id=:new_id,web_20_category_id=:web_20_category_id where id=:old_id",{'web_20_category_id': web_20_category_id,'new_id': application['id'],'old_id': old_id}),
            cur_e.execute('update policy_app_categories set application_id=:new_id where application_id=:old_id',{'new_id': application['id'],'old_id': old_id})

cur_s.execute('delete from applications_last_id')
if last_application_id > 0:
    cur_s.execute('insert into applications_last_id values(%d)' % last_application_id)    



conn.commit()
if count > 0:
    print("%d applications added." % count)

# Migrate web categories control list
with open(WEBCAT_JSON) as file:
     web_categories = json.load(file)
     web_categories = web_categories['categories']

cur_e.execute('select * from policies')
curr_policies = cur_e.fetchall()
cur_e.execute('select * from web_categories')
curr_web_categories = cur_e.fetchall()


web_categories.sort(key = lambda x:x['id'], reverse = True)
web_max_id = int(web_categories[0]['id']) + 1
curr_web_categories.sort(key = lambda x:x['id'], reverse = True)
web_max_id += int(curr_web_categories[0]['id'])  if len(curr_web_categories) > 0 else 0

print(f'Web category max id is {web_max_id}')
cur_e.execute('update web_categories set id=id + :max_id',{'max_id': app_max_id})
cur_e.execute('update policy_web_categories set web_categories_id=web_categories_id + :max_id',{'max_id': app_max_id})
cur_e.execute('select * from web_categories')
curr_web_categories = cur_e.fetchall()
count = 0
new_ids = []

for web_category in web_categories:
    # Create new web category
    if web_category['name'] not in [data['name'] for data in curr_web_categories]:
        try:
            cur_e.execute('INSERT INTO web_categories (id,name,is_security_category) VALUES (:id,:name,:is_security_category)', {
                'id': web_category['id'],
                'name': web_category['name'],
                'is_security_category': 1 if web_category['security'] == 'yes' else 0
            })
            count += 1
            new_ids.append(cur_e.lastrowid)
        except Exception as e:
            print('Warning: insert web categories error:%s' % e)
    else:
        l = [a for a in curr_web_categories if a['name'] == web_category['name']]
        if len(l) > 0:
            old_id = l[0]['id']
            cur_e.execute("update web_categories set id=:new_id where id=:old_id",{'new_id': web_category['id'],'old_id': old_id}),
            cur_e.execute('update policy_web_categories set web_categories_id=:new_id where web_categories_id=:old_id',{'new_id': web_category['id'],'old_id': old_id})
        

cur_e.execute('update web_categories set is_security_category=0')
upt_list_s = [a for a in web_categories if a['security'] == 'yes']
upt_list = [data['name'] for data in upt_list_s]
for cat in upt_list:
    cur_e.execute('update web_categories set is_security_category=1 where name=:p_name',
                  {'p_name': cat})

conn.commit()
if count > 0:
    print("%d web categories added." % count)
    for policy in curr_policies:
        for new_id in new_ids:
            try:
                cur_e.execute('INSERT INTO policy_web_categories (policy_id, web_categories_id, uuid, action) VALUES (:policy_id, :web_categories_id, :uuid, :action)',
                              {'policy_id': policy['id'],'web_categories_id':new_id, 'uuid': str(uuid.uuid4()), 'action': 'accept'})
            except Exception as e:
                print('Warning: insert policy web categories error:%s' % e)

# cant be change , it must be add new categories to policies.
cur_e.execute('select * from web_categories')
curr_web_categories = cur_e.fetchall()
update_list = []

if os.path.exists(webcat_migration_file):
    with open(webcat_migration_file) as file:
        web_category_new = json.load(file)
        web_category_new = web_category_new['categories']
    for web_category in web_category_new:
        if len(web_category['list']) > 0:
            new_id = [a for a in curr_web_categories if a['name'] == web_category['name']]
            if len(new_id) == 0:
                continue
            new_id = new_id[0]
            for old_category in web_category['list']:
                old_id = [a for a in curr_web_categories if a['name'] == old_category]
                if len(old_id) > 0: 
                    old_id = old_id[0]
                    update_list.append({'old_id': old_id['id'], 'new_id': new_id['id']})

if (len(update_list) > 0):
    for policy in curr_policies:
        for update_ in update_list:
            cur_p.execute('select action from policy_web_categories where web_categories_id=:old_id and policy_id=:policy_id order by action desc limit 1 ',
                          {'old_id': update_['old_id'],'policy_id': policy['id']})
            action_value = cur_p.fetchone()
            if action_value is not None:
                cur_e.execute('update policy_web_categories set web_categories_id=:new_id,action=:action where web_categories_id=:old_id and policy_id=:policy_id',
                              {'new_id': update_['new_id'], 'action': action_value['action'],'old_id': update_['old_id'],'policy_id': policy['id']})
    while True:
        cur_p.execute('select policy_id,web_categories_id,count(*) from policy_web_categories group by policy_id,web_categories_id having count(*)>1')
        double_value = cur_p.fetchall();
        if len(double_value) == 0:
            break
        for one_value in double_value:
            cur_e.execute('select id from policy_web_categories where web_categories_id=:w_id and policy_id=:p_id order by action limit 1',
                          {'w_id': one_value['web_categories_id'], 'p_id':  one_value['policy_id']})  
            del_value = cur_e.fetchone();
            cur_e.execute('delete from policy_web_categories where id=:id',{'id':  del_value['id']})  
                          


#applications deleting not exists
cur_e.execute('select * from applications')
curr_applications = cur_e.fetchall()
application_names = [data['name'] for data in applications]
del_count = 0
del_list = []

for curr_application in curr_applications:
    if curr_application['name'] not in application_names:
        del_count += 1
        cur_e.execute('delete from applications where name=:name', {'name': curr_application['name']})
        del_list.append(curr_application['id'])

conn.commit()
if del_count > 0:
    print("%d applications deleted." % del_count)
    del_list = map(str, del_list) 
    cur_e.execute('delete from policy_app_categories where application_id in (:ids)',
                      {'ids': ",".join(del_list)})

#application categories deleting not exists
cur_e.execute('select * from application_categories')
curr_application_categories = cur_e.fetchall()
cat_names = [data['category'] for data in distinct_app_list]
del_count = 0
del_list = []

for curr_application_category in curr_application_categories:
    if curr_application_category['name'] not in cat_names:
        del_count += 1
        cur_e.execute('delete from application_categories where name=:name', {'name': curr_application_category['name']})
        del_list.append(curr_application_category['id'])

conn.commit()
if del_count > 0:
    print("%d application categories deleted." % del_count)
    del_list = map(str, del_list) 
    cur_e.execute('delete from applications where application_category_id in (:ids)', {'ids': ",".join(del_list)})

# ---------------------------------------------------
# check each application category and subdetails.
del_count = 0
cur_e.execute('select * from application_categories')
curr_application_categories = cur_e.fetchall()
for curr_application_category in curr_application_categories:
    new_applications = [x for x in applications if x['category'] == curr_application_category['name']]
    new_applications = [data['name'] for data in new_applications]
    cur_p.execute('select * from applications where application_category_id=:id',{'id':curr_application_category['id']})
    curr_applications = cur_p.fetchall()
    for curr_application in curr_applications:
        if curr_application['name'] not in new_applications:
            cur_p.execute('delete from applications where application_category_id=:id and name=:name',{'id':curr_application_category['id'],'name': curr_application['name']})
            del_count += 1

if del_count > 0:
    print("%d sub application deleted." % del_count)

# ---------------------------------------------------
#web categories deleting not exists
cur_e.execute('select * from web_categories')
curr_web_categories = cur_e.fetchall()
web_category_names = [data['name'] for data in web_categories]
del_count = 0
del_list = []

for web_category in curr_web_categories:
    # delete web category
    if web_category['name'] not in web_category_names:
        del_count += 1
        cur_e.execute('delete from web_categories where name=:name and is_security_category=:is_security_category', {
            'name': web_category['name'],
            'is_security_category': web_category['is_security_category']
        })
        del_list.append(web_category['id'])

conn.commit()
if del_count > 0:
    print("%d web categories deleted." % del_count)
    del_list = map(str, del_list) 
    cur_e.execute('delete from policy_web_categories where web_categories_id in (:ids)',
                      {'ids': ",".join(del_list)})


'''
---------------------------------------------------------------------------------------------
'''

print("Prepared Default Policy")
cur_s.execute('select * from web_categories')
curr_web_categories = cur_s.fetchall()

cur_s.execute('select w.name from web_categories w,policy_web_categories p where p.web_categories_id = w.id and p.policy_id=0')
curr_policy_web_categories = [data['name'] for data in cur_s.fetchall()]

for web_category in curr_web_categories:
    if web_category['name'] not in curr_policy_web_categories:
        try:
            cur_e.execute(
                'INSERT INTO policy_web_categories (policy_id, web_categories_id, uuid, action) VALUES (:policy_id, :web_categories_id, :uuid, :action)',
                {'policy_id': 0, 'web_categories_id': web_category['id'], 'uuid': str(uuid.uuid4()), 'action': 'accept'})
        except Exception as e:
            print('Warning: insert default policy web categories error:%s' % e)


# check application list.
cur_s.execute('select * from applications')
curr_app_categories = cur_s.fetchall()

for policy in curr_policies:
    cur_s.execute('select a.name,a.application_category_id from policy_app_categories p,applications a where p.application_id = a.id and p.policy_id=:policy_id',{'policy_id': policy['id']})
    curr_policy_app_categories = [data['name'] for data in cur_s.fetchall()]
    
    #check reject list.
    cur_p.execute('select count(*),p.action,a.application_category_id from policy_app_categories p,applications a where p.application_id = a.id and p.policy_id=:policy_id  group by p.action,a.application_category_id order by p.action',{'policy_id': policy['id']})
    all_records = cur_p.fetchall()
    app_categories_accept = [e['application_category_id'] for e in all_records if e['action']=='accept']
    app_categories_reject = [e['application_category_id'] for e in all_records if e['action']=='reject']
   
    count = 0
    for application_category in curr_app_categories:
        if application_category['name'] not in curr_policy_app_categories:
            try:
                cur_e.execute(
                    'INSERT INTO policy_app_categories (policy_id, application_id,uuid,action ,writetofile) VALUES(:policy_id, :application_id, :uuid, :action ,:writetofile)',
                    {'policy_id': policy['id'], 'application_id': application_category['id'],'uuid': str(uuid.uuid4()), 'action': 'accept','writetofile':'on'})
                count = count + 1    
            except Exception as e:
                print('Warning: insert default policy app categories error:%s' % e)
    if count > 0:           
        for reject_id in app_categories_reject:
            if reject_id not in app_categories_accept:
                cur_p.execute('update policy_app_categories set action=:action where policy_id=:policy_id and application_id in (select id from applications where application_category_id=:cat_id)',{'action': 'reject', 'policy_id': policy['id'],'cat_id': reject_id})


# not exists category id of applications
cur_s.execute('select * from applications where application_category_id not in (select id from application_categories)')
curr_apps = cur_s.fetchall()
cur_e.execute('select * from application_categories')
curr_app_categories = cur_e.fetchall()
for app in curr_apps:
    category = [e['category'] for e in applications if e['name']==app['name']]
    if len(category) > 0:
        category_id = [e['id'] for e in curr_app_categories if e['name']==category[0]]
        if len(category_id) > 0:
            cur_p.execute('update applications set application_category_id=:category_id where id=:id',{'category_id':category_id[0],'id':app['id']})

cur_s.execute('select c.name from custom_web_categories c,policy_custom_web_categories p where p.custom_web_categories_id = c.id and p.policy_id=0')
curr_policy_custom_categories = [data['name'] for data in cur_s.fetchall()]
custom_list = [{'name': 'Whitelisted', 'action': 'accept'}, {'name': 'Blacklisted', 'action': 'reject'}]
for custom in custom_list:
    if custom['name'] not in curr_policy_custom_categories:
        try:
            cur_e.execute(
                'INSERT INTO custom_web_categories (name,uuid,action) VALUES(:name, :uuid,:action)',
                {'name': custom['name'], 'uuid': str(uuid.uuid4()),'action': custom['action']})
            cur_s.execute('select seq from sqlite_sequence where name="custom_web_categories"')
            custom_web_categories_id = cur_s.fetchone()
            cur_e.execute(
                'INSERT INTO policy_custom_web_categories(policy_id,custom_web_categories_id)  VALUES(:policy_id,:custom_web_categories_id)',
                {'policy_id': 0, 'custom_web_categories_id': custom_web_categories_id['seq']})
        except Exception as e:
            print('Warning: insert default policy custom web categories error:%s' % e)

'''
 END ---------------------------------------------------------------------------------------------
'''
conn.commit()

# load current network configuration
# empty
#tokens table move to tokens.json
cur_e.execute('PRAGMA table_info(tokens)')
columns = cur_e.fetchall()
if len(columns) > 0:
    try:
        cur_p.execute('select token,status,create_date from tokens')
        tokens = cur_p.fetchall()
        json_data = []
        for token in tokens:
            json_data.append({'token':token['token'], 'status': 'true' if token['status']==1 else 'false', 'create_date': token['create_date']})

        TOKENS_DIR = os.path.join(EASTPECT_ROOT, 'userdefined', 'db','Usercache')
        if not os.path.isdir(TOKENS_DIR):
            os.makedirs(TOKENS_DIR)
        TOKENS_FILE = os.path.join(TOKENS_DIR, 'tokens.json')
        f = open(TOKENS_FILE, "w+")
        f.write(json.dumps(json_data))
        f.close()
        cur_p.execute('drop table tokens')
    except Exception as e:
        print('Warning: Tokens can not write to tokens.json file error:%s' % e)

f = open(DB_VERSION)        
version = f.read()        
cur_p.execute("INSERT INTO sensei_db_version(version,creation_date) VALUES(:version,:date_val);", {'version': version, 'date_val': int(time.time())})
conn.commit()
conn.close()