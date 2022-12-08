#!/bin/sh

if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi

WORKERS="$(cat $EASTPECT_ROOT/etc/workers.map | grep -E 'netmap|pcap' | awk -F ',' '{print $1}')"
LAST_WORKER="$(cat $EASTPECT_ROOT/etc/workers.map | grep -E 'netmap|pcap' | awk -F ',' '{print $1}' | tail -n 1)"

echo -n "{\"interfaces\": ["
for WORKER in $WORKERS; do
    LOG_FILES="$EASTPECT_ROOT/log/active/worker${WORKER}_*.log"
    LOG_FILE="$(ls -t $LOG_FILES 2> /dev/null | head -n 1)"

    if [ -n "$LOG_FILE" ]; then
        INTERFACE="$(tail -n 100 $LOG_FILE | grep "Stats LAN" | tail -n 1 | cut -d ' ' -f5)"
        OCTETS="$(tail -n 100 $LOG_FILE | grep "Stats Octets" | tail -n 1)"
        PACKETS="$(tail -n 100 $LOG_FILE | grep "Stats Packets" | tail -n 1)"

        echo -n "{
            \"interface\": \"$INTERFACE\",
            \"bytes_out\": $(echo $OCTETS | cut -d' ' -f7),
            \"bytes_in\": $(echo $OCTETS | cut -d' ' -f12 | sed 's/Octets://g'),
            \"tput_out\": \"$(echo $OCTETS | cut -d' ' -f9-10 | sed 's/]//g')\",
            \"tput_in\": \"$(echo $OCTETS | cut -d' ' -f14-15 | sed 's/]//g')\",
            \"bps_out\": $(echo $PACKETS | cut -d' ' -f9 | sed 's/bps://g' | sed 's/]//g'),
            \"bps_in\": $(echo $PACKETS | cut -d' ' -f14 | sed 's/bps://g' | sed 's/]//g'),
            \"packets_out\": $(echo $PACKETS | cut -d' ' -f6 | sed 's/pkt://g'),
            \"packets_in\": $(echo $PACKETS | cut -d' ' -f11 | sed 's/pkt://g'),
            \"errors_out\": $(echo $PACKETS | cut -d' ' -f7 | sed 's/drp://g'),
            \"errors_in\": $(echo $PACKETS | cut -d' ' -f12 | sed 's/drp://g'),
            \"pps_out\": $(echo $PACKETS | cut -d' ' -f8 | sed 's/pps://g'),
            \"pps_in\": $(echo $PACKETS | cut -d' ' -f13 | sed 's/pps://g')
        }"
        if [ "$WORKER" != "$LAST_WORKER" ]; then
            echo -n ", "
        fi
    fi
done

echo -n "],
\"nodes\": ["

LOG_FILE="$EASTPECT_ROOT/log/active/worker0_*.log"
LOG_FILE="$(ls -t $LOG_FILE 2> /dev/null | head -n 1)"
COUNT=0

if [ -n "$LOG_FILE" ]; then
    CLOUDS="$(tail -n 100 $LOG_FILE | grep "Stats Cloud" | tail -n 1 | cut -d ' ' -f5- | cut -d ']' -f1)"
    TOTAL="$(echo $CLOUDS | tr '|' '\n' | wc -l | tr -d '[:space:]')"
    export IFS="|"
    if [ -n "$CLOUDS" ]; then
        for CLOUD in $CLOUDS; do
            CLOUD="$(echo $CLOUD | awk '{$1=$1};1')"
            STATUS="$(echo $CLOUD | grep -o "\(DOWN\|UP\)")"
            echo -n "{
                \"name\": \"$(echo $CLOUD | awk -F "$STATUS" '{print $1}' | awk '{$1=$1};1')\",
                \"status\": \"$STATUS\",
                \"response\": \"$(echo $CLOUD | awk -F "$STATUS" '{print $2}' | cut -d ' ' -f2)\",
                \"success\": \"$(echo $CLOUD | awk -F "$STATUS" '{print $2}' | cut -d ' ' -f3)\",
                \"details\": \"$(echo $CLOUD | awk -F "$STATUS" '{print $2}' | cut -d ' ' -f4)\"
            }"
            COUNT=$(expr $COUNT + 1)
            if [ $COUNT -ne $TOTAL ]; then
                echo -n ", "
            fi
        done
    fi
fi
echo "]}"

exit 0
