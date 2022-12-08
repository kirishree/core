#!/bin/sh

if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi
if [ -z $OPNSENSE_ROOT ]; then OPNSENSE_ROOT="/usr/local/opnsense"; fi

MAIN_DB="$EASTPECT_ROOT/userdefined/config/settings.db"

DT=$(date +%s)
cp $MAIN_DB "$MAIN_DB.$DT"

RESTORE_DB=$1
DUMP_FILE='/tmp/restore_policies.dump'
RULES_SQL="$OPNSENSE_ROOT/scripts/OPNsense/Sensei/restore-policies.sql"
DROP_SQL="$OPNSENSE_ROOT/scripts/OPNsense/Sensei/restore-policies.sql"

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
echo -n "update policies set sort_number=1000 where id=0" | sqlite3 $MAIN_DB
echo 'OK'
exit 0