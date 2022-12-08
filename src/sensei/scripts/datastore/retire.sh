#!/bin/sh
if [ "$#" -ne 1 ]; then
  echo "parameter missing!"
  echo "Parameters must be in [ES , MN, SQ ]"
  exit 1
fi

DBTYPE="$1"

if [ -z $EASTPECT_ROOT ]; then
  EASTPECT_ROOT="/usr/local/sensei"
fi

if [ $1 == 'ES' ]; then
  # create new index , delete old index.
  $EASTPECT_ROOT/scripts/datastore/retire_elasticsearch.py
  exit 0
fi

if [ $1 == 'MN' ]; then
  # create new collection
  $EASTPECT_ROOT/scripts/installers/mongodb/create_collection.py
  # delete old collection
  # $EASTPECT_ROOT/scripts/datastore/retire_mongodb.py
  MAXDAY=$(grep retireAfter $EASTPECT_ROOT/etc/eastpect.cfg | head -1 | awk -F' = ' '{ print $2 }')
  DT=$(date +%H)
  if [ $DT == '03' ]; then
    TS=$(date +%s)
    TS=$((TS - 86400 * $MAXDAY))
    TS=$TS"000"
    sed -e "s/__lttime__/$TS/g" $EASTPECT_ROOT/scripts/datastore/mongodb_bulk_delete.js >/tmp/$TS.js
    LOG=$(mongo sensei </tmp/$TS.js 2>&1)
    DT=$(date +"%F - %T")
    echo -e "\n$DT - INFO - $LOG" >>$EASTPECT_ROOT/log/active/ipdr_retire.log
    rm -rf /tmp/$TS.js
  fi
  exit 0
fi

if [ $1 == 'SQ' ]; then
  MAXDAY=$(grep retireAfter $EASTPECT_ROOT/etc/eastpect.cfg | head -1 | awk -F' = ' '{ print $2 }')
  DT=$(date +%H)
  if [ $DT == '03' ]; then
    TS=$(date +%s)
    TS=$((TS - 86400 * $MAXDAY))
    TS=$TS"000"
    date>>$EASTPECT_ROOT/log/active/ipdr_retire.log
    echo "Delete data with $TS">>$EASTPECT_ROOT/log/active/ipdr_retire.log
    if [ -f /usr/local/datastore/sqlite/conn_all.sqlite ];then 
        echo -n "delete from conn_all where start_time<$TS"|sqlite3 /usr/local/datastore/sqlite/conn_all.sqlite>>$EASTPECT_ROOT/log/active/ipdr_retire.log
        echo -n "delete from conn_all_security_tags where start_time<$TS"|sqlite3 /usr/local/datastore/sqlite/conn_all.sqlite>>$EASTPECT_ROOT/log/active/ipdr_retire.log
        echo -n "vacuum;"|sqlite3 /usr/local/datastore/sqlite/conn_all.sqlite>>$EASTPECT_ROOT/log/active/ipdr_retire.log
    fi  
    if [ -f /usr/local/datastore/sqlite/alert_all.sqlite ];then 
        echo -n "delete from alert_all where start_time<$TS"|sqlite3 /usr/local/datastore/sqlite/alert_all.sqlite>>$EASTPECT_ROOT/log/active/ipdr_retire.log
        echo -n "vacuum;"|sqlite3 /usr/local/datastore/sqlite/alert_all.sqlite>>$EASTPECT_ROOT/log/active/ipdr_retire.log
    fi  
    if [ -f /usr/local/datastore/sqlite/http_all.sqlite ];then 
        echo -n "delete from http_all where start_time<$TS"|sqlite3 /usr/local/datastore/sqlite/http_all.sqlite>>$EASTPECT_ROOT/log/active/ipdr_retire.log
        echo -n "vacuum;"|sqlite3 /usr/local/datastore/sqlite/http_all.sqlite>>$EASTPECT_ROOT/log/active/ipdr_retire.log
    fi  
    if [ -f /usr/local/datastore/sqlite/dns_all.sqlite ];then 
        echo -n "delete from dns_all where start_time<$TS"|sqlite3 /usr/local/datastore/sqlite/dns_all.sqlite>>$EASTPECT_ROOT/log/active/ipdr_retire.log
        echo -n "vacuum;"|sqlite3 /usr/local/datastore/sqlite/dns_all.sqlite>>$EASTPECT_ROOT/log/active/ipdr_retire.log
    fi  
    if [ -f /usr/local/datastore/sqlite/tls_all.sqlite ];then 
        echo -n "delete from tls_all where start_time<$TS"|sqlite3 /usr/local/datastore/sqlite/tls_all.sqlite>>$EASTPECT_ROOT/log/active/ipdr_retire.log
        echo -n "vacuum;"|sqlite3 /usr/local/datastore/sqlite/tls_all.sqlite>>$EASTPECT_ROOT/log/active/ipdr_retire.log
    fi  
    if [ -f /usr/local/datastore/sqlite/sip_all.sqlite ];then 
        echo -n "delete from sip_all where start_time<$TS"|sqlite3 /usr/local/datastore/sqlite/sip_all.sqlite>>$EASTPECT_ROOT/log/active/ipdr_retire.log
        echo -n "vacuum;"|sqlite3 /usr/local/datastore/sqlite/sip_all.sqlite>>$EASTPECT_ROOT/log/active/ipdr_retire.log
    fi  

    DT=$(date +"%F - %T")
    echo -e "\n$DT - INFO" >>$EASTPECT_ROOT/log/active/ipdr_retire.log
    echo -n "END SQ RETIRE." >>$EASTPECT_ROOT/log/active/ipdr_retire.log
  fi
  exit 0
fi

echo "Parameters must be in [ES , MN, SQ ]"
exit 2
