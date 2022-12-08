#!/bin/sh

if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi

HW_BYBASS=$EASTPECT_ROOT/bin/bpctl_util
# test
# HW_DEVICE=/tmp/bpmod
HW_DEVICE=/dev/bpmod

PASSIVE_MODE_ENABLED=0
check_passive_enabled() {
    LINES=$(cat "${EASTPECT_ROOT}/etc/workers.map")
    OIFS=$IFS
    IFS=""
    for next in $LINES; do
        case "$next" in
            \#*)
                ;;
            *)
                echo "$next" | cut -d "," -f 2 | grep "[ \t]*0[ \t]*"
                if [ $? -eq "0" ]; then
                    PASSIVE_MODE_ENABLED=1
                fi
                ;;
        esac
    done
    IFS=$OIFS
}

kill_eastpect_byprocess() {
    is_eastpect_running
    if [ $EASTPECT_RUNNING -eq 1 ]; then
        VN=$(uname -r | awk -F '.' '{ print $1 }')
        if [ $VN = "13" ]; then
            MASTER_PID=$(ps -xfwww | grep -v -E "grep|elasticsearch|health|(\.sh)|(\.py)" | grep "${name} -D" | cut -d" " -f1)
        fi    
        if [ $VN = "12" ]; then
            MASTER_PID=$(ps -xfwww | grep -v -E "grep|elasticsearch|health|*\.sh|*\.py" | grep "${name} -D" | cut -d" " -f1)
        fi    
        if [ ! -z $MASTER_PID ]; then
            kill $MASTER_PID
        fi
        sleep 2
    fi
    is_eastpect_running
    if [ $EASTPECT_RUNNING -eq 1 ]; then
        killall -9 eastpect
        STREAMER_MASTER_PID=$(ps -xfwww | grep -v grep | grep ipdrstreamer.py | cut -d" " -f1)
        if [ ! -z $STREAMER_MASTER_PID ]; then
            kill -9 $STREAMER_MASTER_PID
        fi
        sleep 2
    fi
}

is_eastpect_running() {
    VN=$(uname -r | awk -F '.' '{ print $1 }')
    if [ $VN = "13" ]; then
        CN=$(ps -auxwww | grep -v -E "grep|elasticsearch|health|(\.sh)|(\.py)" | grep eastpect | wc -l)
    fi    
    if [ $VN = "12" ]; then
        CN=$(ps -auxwww | grep -v -E "grep|elasticsearch|health|(*.sh)|(*.py)" | grep eastpect | wc -l)
    fi    
    if [ $CN -gt 0 ]; then
        EASTPECT_RUNNING=1
    else
        EASTPECT_RUNNING=0
    fi
}

is_eastpect_bypass() {
    enabled=0
    disabled=0

    # hw_bypass_status=$(grep -i hardwareBypassEnable $EASTPECT_ROOT/etc/eastpect.cfg | awk '{ print $3 }')
    if [ -f $HW_BYPASS ] && [ -e $HW_DEVICE ];then
       bp_status=$($HW_BYBASS all get_bypass|grep -c " on" )
       if [ $bp_status -gt 0 ];then
          EASTPECT_BYPASS="100:0"
       else
          EASTPECT_BYPASS="0:100"
       fi
    else
        # total_eastpect=$(sockstat -4l | grep eastpect|grep -c tcp)
        #total_eastpect=$(ls -lrth $EASTPECT_ROOT/run/mgmt.sock.43* | grep -c mgmt.sock)
        total_eastpect=$(cat $EASTPECT_ROOT/etc/workers.map | grep -v -e '^$' | grep -v -e '^#' | cut -d "," -f7|grep -c ^)
        cd $EASTPECT_ROOT/log/stat/
        for logfname in `ls -lrth worker*.stat|tail -$total_eastpect| awk '{print $9}'`
        do
            #bypass=$(tail -100 $logfname | grep "bypass" | tail -1 | grep -c '"bypass":true')
            bypass=$(grep -c '"bypass":true' $logfname)
            if [ "$bypass" -gt 0 ];then
               enabled=$((enabled+1))
            fi
            if [ "$bypass" == "disabled" ];then
               disabled=$((disabled+1))
            fi
        done
        EASTPECT_BYPASS="$enabled:$disabled"
    fi
}

stop_elasticsearch() {
    STOP_TRIES=0
    while [ -n "$(pgrep -u elasticsearch)" ]; do
        service elasticsearch forcestop &
        sleep 7
        for PID in $(pgrep -u elasticsearch); do
            kill -9 $PID
        done
        sleep 1
        STOP_TRIES=$(expr $STOP_TRIES + 1)
        if [ $STOP_TRIES -ge 3 ]; then
            break
        fi
    done
}

