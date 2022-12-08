#!/bin/sh

if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi
if [ ! -f $EASTPECT_ROOT/etc/workers.map ]; then 
   echo -n '{}'
   exit 0
fi
WORKERS="$(cat $EASTPECT_ROOT/etc/workers.map | grep -E 'netmap|pcap' | awk -F ',' '{print $1}')"
LAST_WORKER="$(cat $EASTPECT_ROOT/etc/workers.map | grep -E 'netmap|pcap' | awk -F ',' '{print $1}' | tail -n 1)"
echo -n "{\"interfaces\": ["
for WORKER in $WORKERS; do
    LOG_FILE="$EASTPECT_ROOT/log/stat/worker${WORKER}.stat"

    if [ -f "$LOG_FILE" ]; then
        #TS=cat $LOG_FILE | awk '{ print $1 }'
        DIFF=$(php -r "print(time() - stat('$LOG_FILE')['mtime']);")
        if [ $DIFF -lt 10 ]; then
            cat $LOG_FILE
            #cat $LOG_FILE | awk -F 'STATS ' '{ print $2 }'
        fi
        if [ "$WORKER" != "$LAST_WORKER" ]; then
            echo -n ", "
        fi
    fi
done
echo -n "]}"

exit 0
