#!/bin/sh

if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi
if [ -z $OPNSENSE_ROOT ]; then OPNSENSE_ROOT="/usr/local/opnsense"; fi

MAIN_DB="$EASTPECT_ROOT/userdefined/config/settings.db"

TS=$(date +%s)
if [ $# eq 2 ]; then 
    TS=$2
fi    

cp $MAIN_DB "$MAIN_DB.$TS"

RESTORE_DB=$1
DUMP_FILE='/tmp/restore_rules.dump'
RULES_SQL="$OPNSENSE_ROOT/scripts/OPNsense/Sensei/restore-rules.sql"
DROP_SQL="$OPNSENSE_ROOT/scripts/OPNsense/Sensei/restore-drop.sql"

LAST_VERSION=$(echo -n "select version from sensei_version order by id desc limit 1;" | /usr/local/bin/sqlite3 $RESTORE_DB)
if [ $LAST_VERSION = "1.6" ];then 
   echo "Error: DB version must be greater than (1.6). Backup db version is $LAST_VERSION"
   exit 0
fi 

rm -rf $DUMP_FILE
sqlite3 $RESTORE_DB<$RULES_SQL
RET=$?
if [ $RET -ne 0 ];then 
   echo 'Error:While run rule sql.'
   exit 0
fi
sqlite3 $MAIN_DB<$DROP_SQL
RET=$?
if [ $RET -ne 0 ];then 
   echo 'Error:While run drop sql.'
   exit 0
fi

sqlite3 $MAIN_DB<$DUMP_FILE
RET=$?
if [ $RET -ne 0 ];then 
   echo 'Error:While run sql to current database.'
   exit 0
fi

if [ -f "$EASTPECT_ROOT/templates/settingsdb.sql" ]; then 
    sqlite3 $MAIN_DB<"$EASTPECT_ROOT/templates/settingsdb.sql"
fi    

echo -n "update policies set sort_number=1000 where id=0" | sqlite3 $MAIN_DB
echo 'OK'
exit 0