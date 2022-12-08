#  App control page /api/sensei/rules/apps
# select id from application_categories order by name // main category
# select * from applications where application_category_id=21; // subcategory
#  web Control page /api/sensei/rules/web
# select * from web_categories order by name //web categories
# /api/sensei/rules/web20
# select applications.*,web_20_categories.name from applications,web_20_categories where applications.web_20_category_id=web_20_categories.id
# /api/sensei/rules/


# templates of rules under the /usr/local/opnsense/service/templates/OPNsense/Sensei
# tls.rules -> tls tab , security.rules -> security tab , appcontrol.rules -> app control tab , webcontrol.rules -> web controls tab
/*
//applicaton
select p.application_id,p.action,p.uuid,p.id,p.policy_id,a.name,c.name as category,a.web_20_category_id from policy_app_categories p, applications a,application_categories c
   where p.application_id=a.id and c.id = a.application_category_id and a.web_20_category_id is not null

// web categoru
select w.*,c.* from policy_web_categories w,web_categories c
where w.web_categories_id = c.id and c.is_security_category=0 and w.policy_id = 9 order by c.name

// web 20
select p.application_id,p.action,p.uuid,p.id,p.policy_id,a.name,w.name as web_20,a.web_20_category_id from policy_app_categories p, applications a,web_20_categories w
   where p.application_id=a.id and w.id = a.web_20_category_id and w.id=2

//custom web category
select * from policy_custom_web_categories p, custom_web_categories c
where p.custom_web_categories_id = c.id and p.policy_id=11

select c.name,s.site from custom_web_categories c,custom_web_category_sites
   where c.id = s.custom_web_categories_id and c.uuid=''
*/

DROP TABLE policies;
CREATE TABLE policies (id INTEGER PRIMARY KEY AUTOINCREMENT,uuid text,name TEXT,usernames TEXT,groups TEXT,interfaces TEXT,vlans TEXT,networks TEXT,directions TEXT);
insert into policies(uuid,name,usernames,groups,interfaces,vlans,networks,directions) values('0','Default','System','System','','','','');

DROP TABLE policy_app_categories;

CREATE TABLE policy_app_categories (id INTEGER PRIMARY KEY AUTOINCREMENT,policy_id TEXT,application_id INTEGER,uuid TEXT,action TEXT,writetofile TEXT);

DROP TABLE policy_web_categories;

CREATE TABLE policy_web_categories (id INTEGER PRIMARY KEY AUTOINCREMENT,policy_id TEXT,web_categories_id INTEGER,uuid TEXT,action TEXT);


DROP TABLE policy_custom_web_categories;

CREATE TABLE policy_custom_web_categories (id INTEGER PRIMARY KEY AUTOINCREMENT,policy_id INTEGER,custom_web_categories_id INTEGER);

DROP TABLE custom_web_categories;

CREATE TABLE custom_web_categories (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,uuid TEXT ,action TEXT);

DROP TABLE custom_web_category_sites;

CREATE TABLE custom_web_category_sites (id INTEGER PRIMARY KEY AUTOINCREMENT,custom_web_categories_id INTEGER,site TEXT,uuid TEXT);

DROP TABLE schedules;
CREATE TABLE schedules (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT UNIQUE ,
mon_day INTEGER  DEFAULT 0,
tue_day INTEGER  DEFAULT 0,
wed_day INTEGER  DEFAULT 0,
thu_day INTEGER  DEFAULT 0,
fri_day INTEGER  DEFAULT 0,
sat_day INTEGER  DEFAULT 0,
sun_day INTEGER  DEFAULT 0,
start_time text, stop_time text,
start_timestamp integer, stop_timestamp integer,
description text
);

DROP TABLE policies_schedules;
CREATE TABLE policies_schedules (id INTEGER PRIMARY KEY AUTOINCREMENT,policy_id INTEGER,schedule_id INTEGER);

select * from schedules s , policies_schedules p where s.id = p.schedule_id and p.policy_id=24

DROP TABLE tokens;
CREATE TABLE tokens (id INTEGER PRIMARY KEY AUTOINCREMENT,token TEXT,status INTEGER,create_date NUMERIC );

DROP TABLE active_directory_logins;
CREATE TABLE active_directory_logins (id INTEGER PRIMARY KEY AUTOINCREMENT,username TEXT,logonid TEXT,ip Text,groups Text,create_date NUMERIC);
create index active_directory_logins_idx on active_directory_logins(logonid);


DROP TABLE interface_settings;
CREATE TABLE interface_settings (id INTEGER PRIMARY KEY AUTOINCREMENT,mode TEXT,name text, lan_interface text,lan_desc text,lan_queue INTEGER,wan_interface text,wan_desc text
                                 ,wan_queue INTEGER, queue INTEGER , description text,cpu_index INTEGER ,manage_port INTEGER,create_date NUMERIC);

CREATE TABLE sensei_db_version(id INTEGER PRIMARY KEY AUTOINCREMENT,version text,create_date NUMERIC);

