#!/bin/sh

# usage: ./control_service.sh <service> <action>
# usage: ./control_service.sh eastpect start

if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi

if [ "$1" == "elasticsearch" -a "$2" == "start" -a -z "$(df | grep /proc)" ]; then
    mount -t fdescfs fdesc "/dev/fd" 2>/dev/null
    mount -t procfs proc "/proc" 2>/dev/null
fi

if [ "$1" == "mongod" -a "$2" == "start" -a -z "$(df | grep /proc)" ]; then
    mount -t fdescfs fdesc "/dev/fd" 2>/dev/null
    mount -t procfs proc "/proc" 2>/dev/null
fi

if [ "$1" == "eastpect" ]; then
    $EASTPECT_ROOT/scripts/service.sh $2 2>/dev/null
else
    service $1 one$2 2>/dev/null
    RET=$?
    if [ $RET -ne 0 ]; then
       # echo -n "ERR: Error occured during $1 $2 process"
       echo -n "ERR: Error happened while $1ing the $2 service."
    fi
fi

if [ "$1" == "elasticsearch" -a "$2" == "stop" -a -n "$(df | grep /proc)" ]; then
    umount "/dev/fd" 2>/dev/null
    umount "/proc" 2>/dev/null
fi

if [ "$1" == "mongod" -a "$2" == "stop" -a -n "$(df | grep /proc)" ]; then
    umount "/dev/fd" 2>/dev/null
    umount "/proc" 2>/dev/null
fi

exit 0
