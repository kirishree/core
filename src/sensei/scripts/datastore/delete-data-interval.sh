#!/bin/sh
if [ "$#" -ne 2 ]; then
   echo "Must be least two parameters"
   exit 0
fi

if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi

if [ $2 == 'ES' ]; then
   		$EASTPECT_ROOT/scripts/datastore/delete_elasticsearch.py $1
   		$EASTPECT_ROOT/scripts/installers/elasticsearch/create_indices.py
fi

if [ $2 == 'MN' ]; then
   $EASTPECT_ROOT/scripts/datastore/delete_mongodb.py $1
   $EASTPECT_ROOT/scripts/installers/mongodb/create_collection.py
fi

if [ $2 == 'SQ' ]; then
   DAYS=$1
   TS=$(date +%s)
   TS=$((TS - 86400 * $DAYS))
   TS=$TS"000"
   echo "Delete data older then $1 days...">>$EASTPECT_ROOT/log/active/ipdr_retire.log
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
   /usr/local/opnsense/scripts/OPNsense/Sensei/reinstall_sqlite.sh
fi