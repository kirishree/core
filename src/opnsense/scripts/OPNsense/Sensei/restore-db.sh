#!/bin/sh

if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi
if [ -z $OPNSENSE_ROOT ]; then OPNSENSE_ROOT="/usr/local/opnsense"; fi

LOG_FILE="$EASTPECT_ROOT/log/active/Senseigui.log"
PID=$$
writelog() {
    MSG=$1
    DT=$(date +"%a, %d %b %y %T %z")
    echo "[$DT][INFO] [$PID] $MSG">>$LOG_FILE
}

MAIN_DB="$EASTPECT_ROOT/userdefined/config/settings.db"
RESTORE_DB=$1
TS=$(date +%s)
if [ $# eq 2 ]; then 
    TS=$2
fi    

LAST_VERSION=$(echo -n "select version from sensei_version order by id desc limit 1;" | /usr/local/bin/sqlite3 $RESTORE_DB)
if [ $LAST_VERSION = "1.6" ];then 
   writelog "Error: DB version must be greater than (1.6). Backup db version is $LAST_VERSION"
   echo "Error: DB version must be greater than (1.6). Backup db version is $LAST_VERSION"
   exit 0
fi 

writelog "Backup original database"
cp $MAIN_DB "$MAIN_DB.$TS"
writelog "Deleting original database"
rm -rf $MAIN_DB
writelog "Copy $RESTORE_DB to $MAIN_DB "
rm -rf /tmp/full.dmp
echo ".output /tmp/full.dmp">/tmp/dump.sql
echo ".dump">>/tmp/dump.sql
echo ".exit">>/tmp/dump.sql
sqlite3 $RESTORE_DB</tmp/dump.sql
sqlite3 $MAIN_DB</tmp/full.dmp

if [ -f "$EASTPECT_ROOT/templates/settingsdb.sql" ]; then 
    sqlite3 $MAIN_DB<"$EASTPECT_ROOT/templates/settingsdb.sql"
fi    

echo -n "update policies set sort_number=-1 where id=0" | sqlite3 $MAIN_DB
writelog "Restore db finished"
echo 'OK'
exit 0