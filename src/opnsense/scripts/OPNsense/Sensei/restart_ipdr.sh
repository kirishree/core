#!/bin/sh
# after create new index must be restart ipdrstreamer.py
if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi
LOG_FILE="$EASTPECT_ROOT/log/active/Senseigui.log"
PID=$$
writelog() {
    MSG=$1
    DT=$(date +"%a, %d %b %y %T %z")
    echo "[$DT][INFO] [$PID] $MSG">>$LOG_FILE
}
#STREAMER_MASTER_PID=$(ps xao pid,comm | grep ipdrstreamer|awk '{ print $1 }')
FOUND=0
for STREAMER_PID in `ps xao pid,comm | grep ipdrstreamer|awk '{ print $1 }'`
do 
    writelog "Found ipdrstream pid:$STREAMER_PID and killing"
    kill -9 $STREAMER_PID
    FOUND=1
done

if [ $FOUND -eq 0 ]; then
    writelog "Not Found ipdrstream pid"
    echo 'ERROR:not found ipdrstreamer'
fi
echo 'OK'