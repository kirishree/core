#!/bin/sh

if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi
DT=$(date -u +"%Y-%m-%dT%H:%M:%S%z")
# sleep 3
for WORKER in `ps auxwww | grep zenarmor-agent | grep -v grep | grep -v zenarmor-agent-supervisor | awk '{ print $2 }'`; do
    echo "[$DT] [INFO] Send SINGUP signal to CLOUD PID: $WORKER">>$EASTPECT_ROOT/log/active/Senseigui.log
    kill -1 $WORKER
done
exit 0
