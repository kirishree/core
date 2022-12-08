#!/bin/sh
PATH=$PATH:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin

echo "Check python version"
/usr/local/sensei/py_venv/bin/python3 -c "print('test')">/dev/null 2>&1
RET=$?
if [ $RET -ne 0 ];then 
   echo "\n!!!!!!!!!!! /usr/local/sensei/py_venv/bin/python3 file is missing......!!!!!!!!!!!!!!!!!!!!!!!  \n"
   ln -s `which python3` /usr/local/sensei/py_venv/bin/python3
   RET=$?
   if [ $RET -ne 0 ];then 
      echo "\n!!!!!!!!!!! /usr/local/sensei/py_venv/bin/python3 could not create ......!!!!!!!!!!!!!!!!!!!!!!!  \n"
      touch /usr/local/sensei/etc/.doneinstall
      exit 1
   fi
fi
if [ ! -d /usr/local/sensei/log/active ]; then
   echo "Create log path..."
   mkdir -p /usr/local/sensei/log/active
   mkdir -p /usr/local/sensei/log/archive
fi

echo "Preparing Settings Db..."
mkdir -p /usr/local/sensei/userdefined/config
if  [ -f /usr/local/sensei/userdefined/config/settings.db ]; then
  echo "Backup configurations..."
  TS=$(date +%s)
  cp /usr/local/sensei/userdefined/config/settings.db "/usr/local/sensei/userdefined/config/settings.db.$TS"
  cp /conf/config.xml "/usr/local/sensei/userdefined/config/config.xml.$TS"
fi

if [ -f /usr/local/sensei/etc/partner.json ]; then
   PARTNER_NAME=$(cat /usr/local/sensei/etc/partner.json | python3 -m json.tool | grep "name" | awk '{print $2}' | sed 's/"//g' | sed 's/,//g')
   sed -i "" "s/__partner_name__/$PARTNER_NAME/g" /usr/local/sensei/templates/settingsdb.sql
fi
/usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db < /usr/local/sensei/templates/settingsdb.sql

OUTPUT=$(/usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db < /usr/local/sensei/templates/settingsdb.sql 2>&1)

if [ $(echo -n $OUTPUT|grep -c "near line 47: UNIQUE constraint failed:") -eq 1 ]; then
echo "Error is fixing....."
/usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db  <<EOF
drop table if exists tmp;
create table if not exists tmp as select min(id) as id,custom_web_categories_id,site,count(*) as total from custom_web_category_sites group by custom_web_categories_id,site;
create table if not exists tmp (id integer,total integer default 0);
delete from custom_web_category_sites where id in (select id from tmp where total>1);
drop table if exists tmp;
CREATE UNIQUE INDEX IF NOT EXISTS custom_web_category_sites_unique_idx on custom_web_category_sites(custom_web_categories_id,site);
EOF

fi


RET=$?
if [ $RET -ne 0 ];then
  echo "Can not install settings Db."
else
   /usr/local/sensei/scripts/installers/opnsense/18.1/load_policy_categories.py
fi

echo "Checking Schedule Reports..."
/usr/local/opnsense/scripts/OPNsense/Sensei/report-gen/check_report.py

echo "Preparing Userenrich Db..."
mkdir -p /usr/local/sensei/userdefined/db/Usercache
/usr/local/bin/sqlite3 /usr/local/sensei/userdefined/db/Usercache/userauth_cache.db < /usr/local/sensei/scripts/installers/opnsense/18.1/userauthdb.sql >/dev/null 2>&1

# check hostname column.
ct=$(echo -n "PRAGMA table_info(users_cache);" | /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/db/Usercache/userauth_cache.db|grep -c hostname)
if [ $ct -eq 0 ];then
      echo -n "alter table users_cache add hostname int default(0);"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/db/Usercache/userauth_cache.db
      echo -n "CREATE INDEX IF NOT EXISTS users_cache_hostname ON users_cache (hostname);"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/db/Usercache/userauth_cache.db
fi

# check webcategoryType column.
ct=$(echo -n "PRAGMA table_info(policies);" | /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db|grep -c webcategory_type)
if [ $ct -eq 0 ];then
      echo -n "alter table policies add webcategory_type text default 'permissive';"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db
      echo -n "update policies set webcategory_type='permissive';"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db
fi

# check is_centralized column.
ct=$(echo -n "PRAGMA table_info(policies);" | /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db|grep -c is_centralized)
if [ $ct -eq 0 ];then
   echo -n "alter table policies add is_centralized INTEGER default 0"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db
fi

# check is_sync column.
ct=$(echo -n "PRAGMA table_info(policies);" | /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db|grep -c is_sync)
if [ $ct -eq 0 ];then
   echo -n "alter table policies add is_sync INTEGER default 0;"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db
fi

# check is_cloud column.
ct=$(echo -n "PRAGMA table_info(policies);" | /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db|grep -c is_cloud)
if [ $ct -eq 0 ];then
   echo -n "alter table policies add is_cloud INTEGER default 0;"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db
fi

# check safe_search column.
ct=$(echo -n "PRAGMA table_info(policies);" | /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db|grep -c safe_search)
if [ $ct -eq 0 ];then
   echo -n "alter table policies add safe_search INTEGER DEFAULT 0;"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db
fi

# check tags column.
ct=$(echo -n "PRAGMA table_info(interface_settings);" | /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db|grep -c tags)
if [ $ct -eq 0 ];then
   echo -n "alter table interface_settings add tags TEXT;"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db
fi

# check sort number column.
ct=$(echo -n "PRAGMA table_info(policies);" | /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db|grep -c sort_number)
if [ $ct -eq 0 ];then
   echo -n "alter table policies add sort_number integer default 0;"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db
   SORT_EXEC=/usr/local/sensei/scripts/updater/opnsense/18.1/resort_policies.py
   if [ -f $SORT_EXEC ]; then
      $SORT_EXEC
   fi
fi
echo -n "update policies set sort_number=(select max(sort_number)+1 from policies) where id=0;"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db

# is_global 
ct=$(echo -n "PRAGMA table_info(custom_web_category_sites);" | /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db|grep -c is_global)
if [ $ct -eq 0 ];then
   echo -n "alter table custom_web_category_sites add is_global INTEGER default 0;"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db
fi

# cloud_id
ct=$(echo -n "PRAGMA table_info(policies);" | /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db|grep -c cloud_id)
if [ $ct -eq 0 ];then
   echo -n "alter table policies add cloud_id text;"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db
fi

# macaddresses
ct=$(echo -n "PRAGMA table_info(policies);" | /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db|grep -c macaddresses)
if [ $ct -eq 0 ];then
   echo -n "alter table policies add macaddresses text;"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db
fi

# tags
ct=$(echo -n "PRAGMA table_info(interface_settings);" | /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db|grep -c tags)
if [ $ct -eq 0 ];then
   echo -n "alter table interface_settings add tags text;"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db
fi

# category type 
ct=$(echo -n "PRAGMA table_info(custom_web_category_sites);" | /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db|grep -c category_type)
if [ $ct -eq 0 ];then
   echo -n "alter table custom_web_category_sites add category_type TEXT default 'domain'"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db
   echo -n "update custom_web_category_sites set category_type='domain' where category_type = ''"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db
fi

# port number 
ct=$(echo -n "PRAGMA table_info(custom_applications);" | /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db|grep -c port)
if [ $ct -eq 0 ];then
   echo -n "alter table custom_applications add port INTEGER;"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db
fi

# global_sites policy_id 
ct=$(echo -n "PRAGMA table_info(global_sites);" | /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db|grep -c policy_id)
if [ $ct -eq 0 ];then
   echo -n "alter table global_sites add policy_id INTEGER default 0;"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db
   echo -n "update global_sites set policy_id=0 where policy_id is null;"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db
fi

# schedule convert.
echo -n "update schedules set mon_day=IIF(mon_day = 'true',1,0),tue_day=IIF(tue_day = 'true',1,0),wed_day=IIF(wed_day = 'true',1,0),thu_day=IIF(thu_day = 'true',1,0),fri_day=IIF(fri_day = 'true',1,0),sat_day=IIF(sat_day = 'true',1,0),sun_day=IIF(sun_day = 'true',1,0) where mon_day='true' or tue_day='true' or wed_day='true' or thu_day='true' or fri_day='true' or sat_day='true' or sun_day='true';"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db

# update policy cloud_id , 1.11.3 must be update with policies and register and others.
echo -n "update policies set cloud_id='' where cloud_id='null' or cloud_id=null or cloud_id is null;"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db
echo -n "update policies set interfaces='' where interfaces='null' or interfaces=null or interfaces is null;"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db
echo -n "update policies set vlans='' where vlans='null' or vlans=null or vlans is null;"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db
echo -n "update policies set networks='' where networks='null' or networks=null or networks is null;"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db
echo -n "update policies set macaddresses='' where macaddresses='null' or macaddresses=null or macaddresses is null;"| /usr/local/bin/sqlite3 /usr/local/sensei/userdefined/config/settings.db
nodes_size=0
new_cloud_nodes=0

getFSize () {
  if [ -f /usr/local/sensei/db/Cloud/nodes.csv ]; then
     nodes_size=$(du -k /usr/local/sensei/db/Cloud/nodes.csv | awk '{print $1}')
  else
     nodes_size=100
  fi
}

setNewNodes () {
    echo -n "Setting new cloud nodes..."
    /usr/local/opnsense/scripts/OPNsense/Sensei/nodes_status.py > /dev/null 2>&1
    getFSize
    new_cloud_nodes=1
    echo "done"
}

echo -n "Checking Cloud Nodes..."
CN=0
if [ -f /usr/local/sensei/db/Cloud/nodes.csv ]; then
    cp /usr/local/sensei/db/Cloud/nodes.csv /usr/local/sensei/db/Cloud/nodes.csv.bak
fi
if [ -f /tmp/sensei_nodes_status.json ]; then
  CN=$(grep -c "176.53.43.57" /tmp/sensei_nodes_status.json)
  if [ $CN -ne 0 ]; then
    setNewNodes
  fi
else
    setNewNodes
fi

# ['Europe-1','Europe-2','US-Central-1','US-Central-2','US-West (Test)','Asia (Test)']
if [ $new_cloud_nodes -eq 1 ]; then
  if [ $nodes_size -lt 10 ]; then
      if [ -f /usr/local/sensei/db/Cloud/nodes.csv.bak ]; then
	      CN=$(grep -c 'Europe-' /usr/local/sensei/db/Cloud/nodes.csv.bak)
      fi
      if [ $CN -ne 0 ]; then
         cat /usr/local/sensei/db/Cloud/nodes.csv|grep -v "Europe">/usr/local/sensei/db/Cloud/nodes.csv
         echo "Europe,35.198.172.108,5355">>/usr/local/sensei/db/Cloud/nodes.csv
      fi
      if [ -f /usr/local/sensei/db/Cloud/nodes.csv.bak ]; then
      	CN=$(grep -c 'US-Central-' /usr/local/sensei/db/Cloud/nodes.csv.bak)
      fi
      if [ $CN -ne 0 ]; then
         cat /usr/local/sensei/db/Cloud/nodes.csv|grep -v "US-Central">/usr/local/sensei/db/Cloud/nodes.csv
         echo "US-Central,104.155.129.221,5355">>/usr/local/sensei/db/Cloud/nodes.csv
      fi
      if [ -f /usr/local/sensei/db/Cloud/nodes.csv.bak ]; then
          CN=$(grep -c 'US-West (Test)' /usr/local/sensei/db/Cloud/nodes.csv.bak)
      fi
      if [ $CN -ne 0 ]; then
         cat /usr/local/sensei/db/Cloud/nodes.csv|grep -v "US-West">/usr/local/sensei/db/Cloud/nodes.csv
         echo "US-East,34.74.12.235,5355">>/usr/local/sensei/db/Cloud/nodes.csv
      fi
      if [ -f /usr/local/sensei/db/Cloud/nodes.csv.bak ]; then
      	CN=$(grep -c 'Asia (Test)' /usr/local/sensei/db/Cloud/nodes.csv.bak)
      fi
      if [ $CN -ne 0 ]; then
         cat /usr/local/sensei/db/Cloud/nodes.csv|grep -v "Asia">/usr/local/sensei/db/Cloud/nodes.csv
         echo "Asia,34.92.15.156,5355">>/usr/local/sensei/db/Cloud/nodes.csv
      fi
  fi
  # echo -n "eastpect engine restarting..."
  # service eastpect onerestart
fi
# sensei db install.
# new line.....
if [ -f /usr/local/opnsense/mvc/script/run_migrations.php ]; then
    echo -n "Running OPNsense migration scripts..."
    # /usr/local/opnsense/mvc/script/run_migrations.php OPNsense/Sensei > /dev/null 2>&1
    echo "done"
fi

if [ ! -f /usr/local/sensei/etc/.isoconfig -a -f /usr/local/opnsense/mvc/app/models/OPNsense/Sensei/CLI.php ]; then
    /usr/local/opnsense/mvc/app/models/OPNsense/Sensei/CLI.php setretireafter
    /usr/local/opnsense/mvc/app/models/OPNsense/Sensei/CLI.php flavor
    /usr/local/opnsense/mvc/app/models/OPNsense/Sensei/CLI.php settimestamp
    /usr/local/opnsense/mvc/app/models/OPNsense/Sensei/CLI.php migrate
    /usr/local/opnsense/mvc/app/models/OPNsense/Sensei/CLI.php migratewebcat
    /usr/local/opnsense/mvc/app/models/OPNsense/Sensei/CLI.php bufsysctl
    /usr/local/opnsense/mvc/app/models/OPNsense/Sensei/CLI.php setClusterUUID
    /usr/local/opnsense/mvc/app/models/OPNsense/Sensei/CLI.php setswap
    /usr/local/opnsense/mvc/app/models/OPNsense/Sensei/CLI.php setlicensesize
    /usr/local/opnsense/mvc/app/models/OPNsense/Sensei/CLI.php checkOfLoading
fi

pkg info elasticsearch5> /dev/null 2>&1
ES_CHECK=$?

if [ $ES_CHECK -eq 0 ];then
	DB_TYPE=$(grep -c "type = ES" /usr/local/sensei/etc/eastpect.cfg)
	if [ $DB_TYPE -ne 0 ];then
        echo "http index reconfiguration for device..." 
        /usr/local/sensei/scripts/datastore/retire_elasticsearch.py "-1" "http"
    fi
fi

pkg info mongodb40> /dev/null 2>&1
MN_CHECK=$?
if [ $MN_CHECK -eq 0 ];then
	DB_TYPE=$(grep -c "type = MN" /usr/local/sensei/etc/eastpect.cfg)
	if [ $DB_TYPE -ne 0 ];then
        echo "Mongodb php package checking..." 
        /usr/local/opnsense/scripts/OPNsense/Sensei/reinstall_packages.sh
    fi
fi


if [ -f /usr/local/etc/rc.configure_plugins ]; then
    echo -n "Running OPNsense post install scripts..."
    /usr/local/etc/rc.configure_plugins POST_INSTALL > /dev/null 2>&1
    echo "done"
fi


if [ -f /usr/local/sbin/configctl ]; then
    echo -n "Generating Zenarmor configuration files..."
    /usr/local/sbin/configctl template reload OPNsense/Sensei > /dev/null 2>&1
    /usr/local/sbin/configctl sensei policy reload> /dev/null 2>&1
    echo "done"

    echo -n "Restarting OPNsense web gui..."
    /usr/local/sbin/configctl webgui restart > /dev/null 2>&1
    echo "done"
fi

if [ ! -f /usr/local/sensei/etc/.isoconfig -a -f /usr/local/opnsense/mvc/app/models/OPNsense/Sensei/CLI.php ]; then
        echo "Removing Zenarmor cron jobs...\n"
        /usr/local/bin/php /usr/local/opnsense/mvc/app/models/OPNsense/Sensei/CLI.php crons remove
        echo "Configuring Zenarmor cron jobs...\n"
        /usr/local/bin/php /usr/local/opnsense/mvc/app/models/OPNsense/Sensei/CLI.php crons configure
fi  

find /usr/local/sensei/etc/.saved_configdone -amin -2>/dev/null 2>&1
RET=$?
if [ $RET -eq 0 ]; then
        echo -n "Existing Zenarmor configuration will be used..."
        mv /usr/local/sensei/etc/.saved_configdone /usr/local/sensei/etc/.configdone
        echo "done"
fi

echo -n "Adding new dashboard widget to OPNsense..."
/bin/rm -f  /usr/local/www/widgets/widgets/sensei.widget.php
sed -i -e "s/sensei-container\:/zenarmor-container\:/g" /conf/config.xml
cp  /usr/local/opnsense/mvc/app/models/OPNsense/Sensei/zenarmor.widget.php /usr/local/www/widgets/widgets/zenarmor.widget.php
echo "done"
rm -f /usr/local/sensei/etc/.saved_configdone

if [ ! -f /usr/local/sensei/etc/.configdone ]; then
    /usr/local/opnsense/scripts/OPNsense/Sensei/pkg_message.sh
fi

#if [ -f  /root/.sensei_devrepo.conf ]; then
#	rm -f  /root/.sensei_devrepo.conf
#fi
if [ ! -d  /usr/local/dbsensei/backup ]; then
  mkdir -p /usr/local/dbsensei/backup
  chmod 755 /usr/local/dbsensei/backup
fi

TEMP_NEW_SIZE=$(grep SenseiTempSize /conf/config.xml|cut -d ">" -f 2 | cut -d "<" -f 1)
if [ ! -z "$TEMP_NEW_SIZE" ];then
    if [ $TEMP_NEW_SIZE -gt 0 ];then
        echo "Zenarmor temp size will change with $TEMP_NEW_SIZE"
        echo "backup temp"
        /usr/local/sbin/configctl sensei backup-temp-folder
        STATUS=$(service eastpect onestatus|grep -c "is running")
        if [ $STATUS -gt 0 ];then 
           service eastpect onestop
        fi
        echo "change size"
        /usr/local/sbin/configctl sensei change-size-temp-folder $TEMP_NEW_SIZE
        echo "restore backup"
        /usr/local/sbin/configctl sensei restore-temp-folder
        if [ $STATUS -gt 0 ];then 
           service eastpect onestart
        fi

    fi    
fi

echo -n "Copy block tamplate ..."
if [ -f /usr/local/sensei/userdefined/templates/block.template.default ]; then
   if [ ! -f /usr/local/sensei/userdefined/templates/block.template ]; then
      cp /usr/local/sensei/userdefined/templates/block.template.default /usr/local/sensei/userdefined/templates/block.template
   fi
fi
echo "done"

echo -n "Generating Default CA keys and certificates..."
/usr/local/sensei/scripts/tls/gen_ca_key.sh
echo "done"

touch /usr/local/opnsense/scripts/OPNsense/Sensei/.first_check
find /tmp/sensei_updater_state -amin -10>/dev/null 2>&1
RET=$?
if [ $RET -eq 0 ]; then
     echo "Resuming Zenarmor packet engine, restarting with the new engine..."
	/usr/local/sensei/scripts/service.sh restart
fi
rm -f /tmp/sensei_updater_state

if [ ! -f /usr/local/sensei/etc/.isoconfig -a -f /usr/local/opnsense/scripts/firmware/register.php ]; then
     echo -n "Registering plug-in to the OPNsense firmware system..."
     /usr/local/opnsense/scripts/firmware/register.php install os-sensei
     echo "done"
fi

echo "Done & sync heartbeat ..."
if [ ! -f /usr/local/sensei/etc/.isoconfig -a  -f /usr/local/opnsense/scripts/OPNsense/Sensei/heartbeat.sh ]; then
    /usr/local/opnsense/scripts/OPNsense/Sensei/heartbeat.sh
fi
echo "done"
