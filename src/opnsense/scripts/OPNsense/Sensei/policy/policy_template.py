#!/usr/local/sensei/py_venv/bin/python3
"""
    Copyright (c) 2019 Hasan UCAK <hasan@sunnyvalley.io>
    All rights reserved from Zenarmor of Opnsense
    package : configd
    function: template handler, generate configuration files using templates
"""
import os
import glob
import jinja2
import sqlite3
import logging

EASTPECT_ROOT = '/usr/local/sensei'
if 'EASTPECT_ROOT' in os.environ:
    EASTPECT_ROOT = os.environ.get('EASTPECT_ROOT')

LOG_FILE = os.path.join(EASTPECT_ROOT, 'log', 'active','Senseigui.log')
EASTPECT_CFG = os.path.join(EASTPECT_ROOT, 'etc', 'eastpect.cfg')
EASTPECT_DB = os.path.join(EASTPECT_ROOT, 'userdefined', 'config', 'settings.db')
#application signature start with this number
START_ID = 100000

logging.basicConfig(filename=LOG_FILE, level=logging.INFO, format='[%(asctime)s][%(levelname)s][TEMP] %(message)s', datefmt='%Y-%m-%dT%H:%M:%S')
logging.info('Starting Zenarmor Policy Template')

policy_path = os.path.join(EASTPECT_ROOT, 'userdefined', 'policy', 'Definitions')
rules_path = os.path.join(EASTPECT_ROOT, 'userdefined', 'policy', 'Rules')
categories_path = os.path.join(EASTPECT_ROOT, 'userdefined', 'db', 'Webcat')
categories_db = os.path.join(EASTPECT_ROOT, 'userdefined', 'db', 'Webcat', 'policy_categories.db')
categories_default_db = os.path.join(EASTPECT_ROOT, 'userdefined', 'db', 'Webcat', 'categories.db')
exceptions_db = os.path.join(EASTPECT_ROOT, 'userdefined', 'db', 'Exception', 'exceptions.db')
custom_app_port = os.path.join(EASTPECT_ROOT, 'userdefined', 'db', 'DynamicClassifier', 'port','port.csv')

custom_application_path = os.path.join(EASTPECT_ROOT, 'userdefined', 'db', 'DynamicClassifier', 'ApplicationCatalog')
if not os.path.exists(custom_application_path):
    os.makedirs(custom_application_path,mode=0o755)
 
custom_hostname_path = os.path.join(EASTPECT_ROOT, 'userdefined', 'db', 'DynamicClassifier', 'hostname')
if not os.path.exists(custom_hostname_path):
    os.makedirs(custom_hostname_path,mode=0o755)

custom_ipaddr_path = os.path.join(EASTPECT_ROOT, 'userdefined', 'db', 'DynamicClassifier', 'ipaddr')
if not os.path.exists(custom_ipaddr_path):
    os.makedirs(custom_ipaddr_path,mode=0o755)

custom_app_port_path = os.path.join(EASTPECT_ROOT, 'userdefined', 'db', 'DynamicClassifier', 'port')
if not os.path.exists(custom_app_port_path):
    os.makedirs(custom_app_port_path,mode=0o755)

exceptions_db_path = os.path.join(EASTPECT_ROOT, 'userdefined', 'db', 'Exception')
if not os.path.exists(exceptions_db_path):
    os.makedirs(exceptions_db_path,mode=0o755)

'''
custom_url_path = os.path.join(EASTPECT_ROOT, 'userdefined', 'db', 'DynamicClassifier', 'url')
if not os.path.exists(custom_url_path):
    os.mkdir(custom_url_path,0755)
os.remove(custom_url_path + '/*')    
'''

conn = sqlite3.connect(EASTPECT_DB)
conn.row_factory = sqlite3.Row
cur_p = conn.cursor()
cur_w = conn.cursor()
cur_wc = conn.cursor()
cur_ap = conn.cursor()

template_dir = os.path.dirname(os.path.abspath(__file__)) + '/templates/'
env = jinja2.Environment(loader=jinja2.FileSystemLoader(template_dir), trim_blocks=True)
template_pol = env.get_template('policy.rules')
template_pol_def = env.get_template('systemdefault.rules')
template_pol_con = env.get_template('policyControl.rules')

logging.info('Deleting old policy and rule files')
#delete old rules of policies
fileList = glob.glob(policy_path + '/policy_*.policy')
for filePath in fileList:
    os.remove(filePath)

fileList = glob.glob(rules_path + '/*.rules')
for filePath in fileList:
    os.remove(filePath)

fileList = glob.glob(categories_path + '/*.db')
for filePath in fileList:
    os.remove(filePath)

#start new policy and rules files.
categories_db_content = '#url,CategoryName,policyid\n'
exceptions_db_content = '#type,key,tag(s),policyid\n'
categories_db_content_default = '#url,CategoryName\n'
cur_p.execute("select * from policies where delete_status=0 and status = 1 order by sort_number")

cur_wc.execute("select * from global_sites where status=1")
for row_wc in cur_wc:
    exceptions_db_content += '%s,%s,%s,%s%s' % (row_wc['site_type'],row_wc['site'] , 'Whitelisted' if row_wc['action'] =='accept' else 'Blacklisted',1, '\n')

for row_p in cur_p:
    try:
        logging.info('%s policy preparing' % row_p['name'])
        # web categories
        webcategories = []
        customwebcategories = []
        exceptionscategories = []
        
        cur_w.execute("select w.uuid,c.name,w.action,w.policy_id,c.is_security_category from policy_web_categories w,web_categories c where w.action='reject' and w.web_categories_id = c.id  and w.policy_id =%s order by c.name" % row_p['id'])
        for row_w in cur_w:
            webcategories.append({'landingpage': 'false' if row_w['name'] in ['Ad Tracker','Ads','Ad Trackers','Advertisements'] else 'true','name': row_w['name'],'action': row_w['action'], 'id': row_w['uuid'],'policy_id': row_p['id'],'security': 'yes' if row_w['is_security_category']==1 else 'no'})
        cur_w.execute("select name,uuid,action,policy_id,c.id from policy_custom_web_categories p, custom_web_categories c where p.custom_web_categories_id=c.id and p.policy_id=%s" % row_p['id'])
        for row_w in cur_w:
            if row_w['name'] in ('Whitelisted','Blacklisted'):
                exceptionscategories.append({'landingpage': 'false' if row_w['name'] in ['Ad Tracker','Ads','Ad Trackers','Advertisements'] else 'true','name': row_w['name'],'tag': row_w['name'], 'action': row_w['action'], 'id': row_w['uuid'],'policy_id': row_p['id']})    
            else:    
                customwebcategories.append({'landingpage': 'false' if row_w['name'] in ['Ad Tracker','Ads','Ad Trackers','Advertisements'] else 'true','name': row_w['name'], 'action': row_w['action'], 'id': row_w['uuid'],'policy_id': row_p['id']})
            cur_wc.execute("select * from custom_web_category_sites where custom_web_categories_id=%d order by site" % row_w['id'])
            for row_wc in cur_wc:
                if row_w['name'] in ('Whitelisted','Blacklisted'):
                    exceptions_db_content += '%s,%s,%s,%s%s' % (row_wc['category_type'],row_wc['site'] , row_w['name'] ,row_p['id'], '\n')
                else:    
                    categories_db_content += '%s,%s,%s%s' % (row_wc['site'], row_w['name'], row_p['id'], '\n')

        logging.info('Preparing custom web categories')
        # app categories
        apps = []
        appcategories = []
        logging.info('Preparing Queries')
        query = '''select distinct * from (select distinct c.* from policy_app_categories p,applications a,application_categories c where p.application_id=a.id and a.application_category_id=c.id and p.policy_id=%d and p.action='reject' 
                union all 
                select distinct c.* from policy_custom_app_categories p,custom_applications a,application_categories c where p.custom_application_id=a.id and a.application_category_id=c.id and p.policy_id=%d and p.action='reject') p  order by p.name''' % (row_p['id'],row_p['id'])
        cur_w.execute(query)
        for row_w in cur_w:
            cur_wc.execute('''select count(*) as total,p.action from (select p.action from policy_app_categories p,applications a,application_categories c where p.application_id=a.id and a.application_category_id=c.id and p.policy_id=%d and c.id=%d  
                           union all 
                           select p.action from policy_custom_app_categories  p,custom_applications a,application_categories c where p.custom_application_id=a.id  and a.application_category_id=c.id and p.policy_id=%d and c.id=%d) p group by p.action order by 1 desc''' % (row_p['id'], row_w['id'],row_p['id'], row_w['id']))
            categories = cur_wc.fetchall()
            if len(categories) == 1 and categories[0]['action'] == 'reject':
                appcategories.append({'id': row_w['uuid'],'landingpage': 'false' if row_w['name'] in ['Ad Tracker','Ads','Ad Trackers','Advertisements'] else 'true', 'name': row_w['name'], 'action': 'reject', 'policy_id': row_p['id']})

            if len(categories) > 1 and categories[0]['action'] == 'reject':
#            if cur_wc.fetchone()['action'] == 'reject':
                appcategories.append({'id': row_w['uuid'],'landingpage': 'false' if row_w['name'] in ['Ad Tracker','Ads','Ad Trackers','Advertisements'] else 'true', 'name': row_w['name'], 'action': 'reject', 'policy_id': row_p['id']})
                cur_ap.execute('''select a.name,p.action,p.uuid from policy_app_categories p,applications a,application_categories c where p.application_id=a.id and a.application_category_id=c.id and p.action='accept' and p.policy_id=%d and c.id=%d 
                               union all 
                               select a.name,p.action,p.uuid from policy_custom_app_categories p,custom_applications a,application_categories c where p.custom_application_id=a.id and a.application_category_id=c.id and p.action='accept' and p.policy_id=%d and c.id=%d 
                               order by a.name''' % (row_p['id'], row_w['id'],row_p['id'], row_w['id']))
                for row_ap in cur_ap:
                    apps.append({'id': row_ap['uuid'],'landingpage': 'false' if row_ap['name'] in ['Ad Tracker','Ads','Ad Trackers','Advertisements'] else 'true', 'name': row_ap['name'], 'action': 'accept', 'policy_id': row_p['id']})

            if len(categories) > 1 and categories[0]['action'] == 'accept':
                cur_ap.execute('''select a.name,p.action,p.uuid from policy_app_categories p,applications a,application_categories c where p.application_id=a.id and a.application_category_id=c.id and p.action='reject' and p.policy_id=%d and c.id=%d 
                               union all
                               select a.name,p.action,p.uuid from policy_custom_app_categories p,custom_applications a,application_categories c where p.custom_application_id=a.id and a.application_category_id=c.id and p.action='reject' and p.policy_id=%d and c.id=%d
                               order by a.name''' % (row_p['id'], row_w['id'],row_p['id'], row_w['id']))
                for row_ap in cur_ap:
                    apps.append({'id': row_ap['uuid'], 'landingpage': 'false' if row_ap['name'] in ['Ad Tracker','Ads','Ad Trackers','Advertisements'] else 'true','name': row_ap['name'], 'action': 'reject', 'policy_id': row_p['id']})

        logging.info('Preparing application categories')
        # prepare appcontrol
        content = template_pol_con.render({'sec_webcategories': webcategories, 'policy_id': row_p['id'], 'decision_is_block': row_p['decision_is_block'],'webcategories': webcategories,'customwebcategories': customwebcategories,'exceptionscategories': exceptionscategories, 'apps': apps,'appcategories': appcategories})
        file_name = "%s%s%d%s" % (rules_path,'/policy_',row_p['id'],'.rules')
        f = open(file_name, "w+")
        f.write(content)
        f.close()
        # prepare policy config
        schedules = []
        cur_w.execute("select s.description From schedules s , policies_schedules p where s.id = p.schedule_id and p.policy_id=%d" % row_p['id'])
        for row_w in cur_w:
            schedules.append(row_w['description'])

        logging.info('Preparing policy files')
        if row_p['id'] == 0:
            content = template_pol_def.render({'id': row_p['id'], 'cloud_id': row_p['cloud_id']  if row_p['cloud_id'] != None else '', 'name': row_p['name'], 'username': row_p['usernames'], 'group': row_p['groups'], 'interface': row_p['interfaces'],'vlans': row_p['vlans'],'schedules': schedules,'networks': row_p['networks'],'direction': row_p['directions'],'tls_inspect_enable': 'no','tls_inspect_default': 'no','safe_search_enable': 'no'})
            file_name = "%s%s" % (policy_path, '/systemdefault.policy')
            f = open(file_name, "w+")
            f.write(content)
            f.close()
        if row_p['id'] != 0:
            content = template_pol.render({'id': row_p['id'], 'cloud_id': row_p['cloud_id']  if row_p['cloud_id'] != None else '', 'name': row_p['name'], 'username': row_p['usernames'], 'group': row_p['groups'], 'interface': row_p['interfaces'],'vlans': row_p['vlans'],'schedules': schedules,'networks': row_p['networks'],'macaddresses': row_p['macaddresses'],'direction': row_p['directions'],'tls_inspect_enable': 'no','tls_inspect_default': 'no','safe_search_enable': 'no'})
            file_name = "%s%s%d%s" % (policy_path, '/policy_', row_p['id'], '.policy')
            f = open(file_name, "w+")
            f.write(content)
            f.close()
    except Exception as e:
        logging.error('ERROR: %s' % e)

logging.info('Creating Custom category files')
#create files for custom category 
try:
    cur_p.execute("select a.*,c.name as cname from custom_applications a,application_categories c where a.application_category_id=c.id")
    apps = []
    hostnames = []
    ippaddrs = []
    h_index = START_ID
    i_index = START_ID
    for i,row_p in enumerate(cur_p):
        apps.append('%d,%s,%s,%s,%s,0,0,0,0,0,0,0,0,0,0,0,0' % (START_ID + i,row_p['name'],'https',row_p['description'],row_p['cname']))
        if row_p['hostnames'] != '':
            hlist = row_p['hostnames'].split('\n')
            for l in hlist:
                if l != '':
                    hostnames.append('%d,%s,%s,%s,,' % (h_index,row_p['name'],l,l))
                    h_index = h_index + 1
        if row_p['ip_addrs'] != '':
            ilist = row_p['ip_addrs'].split('\n')
            for l in ilist:
                if l != '':
                    ippaddrs.append('%d,%s,%s' % (i_index,l,row_p['name']))
                    i_index = i_index + 1
    
    logging.info('Apps creating')
    fname = custom_application_path + '/app.db'
    if (os.path.exists(fname)):
        os.remove(fname)
    f = open(fname, "w+")
    f.write('\n'.join(apps))
    f.close()

    logging.info('hostname creating')
    fname = custom_hostname_path + '/hostname.csv'
    if (os.path.exists(fname)):
        os.remove(fname)
    f = open(fname, "w+")
    f.write('\n'.join(hostnames))
    f.close()

    logging.info('ipaddr creating')
    fname = custom_ipaddr_path + '/ip.csv'
    if (os.path.exists(fname)):
        os.remove(fname)
    f = open(fname, "w+")
    f.write('\n'.join(ippaddrs))
    f.close()

except Exception as e:    
    logging.error('ERROR: %s' % e)

#create files for custom app port 
try:
    cur_p.execute("select * from custom_applications where port!=''")
    custom_apps = []
    for i,row_p in enumerate(cur_p):
        custom_apps.append('%d,%s,%s,0,%d,%d' % (row_p['id'],row_p['port'],row_p['name'],1 if row_p['protocol'] != 'UDP' else 0,1 if row_p['protocol'] == 'UDP' else 0))

    
    logging.info('Custom port creating')
    if (os.path.exists(custom_app_port)):
        os.remove(custom_app_port)
    f = open(custom_app_port, "w+")
    f.write('\n'.join(custom_apps))
    f.close()

except Exception as e:    
    logging.error('ERROR: %s' % e)

logging.info('Preparing category db')

if (os.path.exists(categories_default_db)):
    os.remove(categories_default_db)

f = open(categories_default_db, "a+")
f.write(categories_db_content)
f.close()

if (os.path.exists(exceptions_db)):
    os.remove(exceptions_db)

f = open(exceptions_db, "a+")
f.write(exceptions_db_content)
f.close()


cur_p.close()
cur_w.close()
cur_wc.close()
cur_ap.close()
logging.info('Template finished')