DELETE FROM policy_web_categories where policy_id>0;
UPDATE  policy_web_categories set action='accept';
DELETE FROM policy_app_categories where policy_id>0;
UPDATE policy_app_categories set action='accept';
DELETE FROM policy_custom_web_categories where policy_id>0;
DELETE FROM custom_web_categories where id in (select custom_web_categories_id from policy_custom_web_categories where policy_id>0);
DELETE FROM custom_web_categories where name not in ('Whitelisted','Blacklisted');
DELETE FROM global_sites;
DELETE FROM custom_web_category_sites;
DROP TABLE policies;
DROP TABLE schedules;
DROP TABLE policies_schedules;
DROP TABLE interface_settings;
DROP TABLE policies_networks;
DROP TABLE shun_networks;
DROP TABLE user_configuration;
DROP TABLE user_notices;
CREATE TABLE IF NOT EXISTS custom_web_category_sites (id INTEGER PRIMARY KEY AUTOINCREMENT,custom_web_categories_id INTEGER,site TEXT,uuid TEXT);
CREATE TABLE IF NOT EXISTS policies (id INTEGER PRIMARY KEY,uuid text,name TEXT,usernames TEXT,groups TEXT,interfaces TEXT,vlans TEXT,networks TEXT,directions TEXT, delete_status INTEGER default 0,status INTEGER default 1,decision_is_block  INTEGER default 0,webcategory_type TEXT default 'permissive',sort_number integer default 0);
CREATE TABLE IF NOT EXISTS schedules (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT UNIQUE ,mon_day INTEGER  DEFAULT 0,tue_day INTEGER  DEFAULT 0,wed_day INTEGER  DEFAULT 0,thu_day INTEGER  DEFAULT 0,fri_day INTEGER  DEFAULT 0,sat_day INTEGER  DEFAULT 0,sun_day INTEGER  DEFAULT 0,start_time text, stop_time text,start_timestamp integer, stop_timestamp integer,description text);
CREATE TABLE IF NOT EXISTS policies_schedules (id INTEGER PRIMARY KEY AUTOINCREMENT,policy_id INTEGER,schedule_id INTEGER);
CREATE TABLE IF NOT EXISTS interface_settings (id INTEGER PRIMARY KEY AUTOINCREMENT,mode TEXT,name text, lan_interface text,lan_desc text,lan_queue INTEGER,wan_interface text,wan_desc text,wan_queue INTEGER, queue INTEGER , description text,cpu_index INTEGER ,manage_port INTEGER,create_date NUMERIC);
CREATE TABLE IF NOT EXISTS sensei_db_version(id INTEGER PRIMARY KEY AUTOINCREMENT,version text,creation_date NUMERIC);
CREATE TABLE IF NOT EXISTS policies_networks (id INTEGER PRIMARY KEY AUTOINCREMENT,policy_id INTEGER,type INTEGER,network TEXT,desc TEXT,status INTEGER);
CREATE TABLE IF NOT EXISTS shun_networks (id INTEGER PRIMARY KEY AUTOINCREMENT,type INTEGER,network TEXT,desc TEXT,status INTEGER);
CREATE TABLE IF NOT EXISTS user_configuration (id INTEGER PRIMARY KEY AUTOINCREMENT,user TEXT, key TEXT,value TEXT);
CREATE TABLE IF NOT EXISTS user_notices (id INTEGER PRIMARY KEY AUTOINCREMENT,notice_name TEXT, notice TEXT,type TEXT,status INTEGER  DEFAULT 0,create_date NUMERIC);
CREATE INDEX IF NOT EXISTS user_configuration_key_idx on user_configuration(key);
CREATE INDEX IF NOT EXISTS policy_web_categories_idx on policy_web_categories(policy_id);
CREATE INDEX IF NOT EXISTS policy_app_categories_idx  on policy_app_categories(policy_id);
CREATE INDEX IF NOT EXISTS policy_custom_web_categories_idx  on policy_custom_web_categories(policy_id);
CREATE INDEX IF NOT EXISTS policies_networks_idx on policies_networks(policy_id);
CREATE INDEX IF NOT EXISTS policy_app_categories_uuid_idx on policy_app_categories(uuid);
CREATE INDEX IF NOT EXISTS policy_web_categories_uuid_idx on policy_web_categories(uuid);
CREATE UNIQUE INDEX IF NOT EXISTS applications_unique_idx on applications(name,web_20_category_id,application_category_id);
DELETE FROM sensei_db_version where version='1.10';
INSERT INTO sensei_db_version(version,creation_date) VALUES('1.10',datetime('now'));
INSERT INTO policies(id,uuid,name,usernames,groups,status) select 0,'0','Default','system','system',1 where not exists(select 1 from policies where id=0);
vacuum;