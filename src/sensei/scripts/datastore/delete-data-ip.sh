#!/bin/sh
if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi
CRONTAB="$EASTPECT_ROOT/log/cron"
ES_CRON="$EASTPECT_ROOT/scripts/datastore/delete_elasticsearch_by_ip.py"
MN_CRON="$EASTPECT_ROOT/scripts/datastore/mongodb-delete-ip.query"
LOG_FILE="$EASTPECT_ROOT/log/active/Senseigui.log"
if [ -d $CRONTAB ]; then
    for lst in $(ls $CRONTAB/*.txt); do
        DB=$(cat $lst|cut -d '#' -f1)
        IP=$(cat $lst|cut -d '#' -f2)
        if [ $DB == 'ES' ]; then
            $ES_CRON $IP
        fi
        if [ $DB == 'MN' ]; then
            sed "s/__IP_ADDR__/$IP/g" $MN_CRON>/tmp/mn_delete_ip.query
            mongo --quiet sensei < /tmp/mn_delete_ip.query >> $LOG_FILE
        fi
        rm -rf $lst
    done
fi
