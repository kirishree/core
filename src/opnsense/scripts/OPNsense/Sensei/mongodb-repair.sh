#!/bin/sh

LOG_FILE="/usr/local/sensei/log/active/Senseigui.log"
MONGODB_DATA_PATH=$(/usr/local/sensei/scripts/datastore/get-db-path.sh MN)
PID=$$
writelog() {
    MSG=$1
    DT=$(date +"%a, %d %b %y %T %z")
    # [Fri, 31 Jan 20 12:06:12 +0300][INFO] [93119][D:0] Starting Mongodb
    echo "[$DT][INFO] [$PID] $MSG">>$LOG_FILE
}

# writelog "change sysctl setting kern.maxprocperuid ...."
# /sbin/sysctl -i kern.maxprocperuid=300000

writelog "Trying mongodb restart..."
service mongod onerestart
RET=$?
if [ $RET -ne 0 ]; then
    writelog "mongodb doesnt start....."
    writelog "mongodb run repair process....."
    mongod --dbpath $MONGODB_DATA_PATH --repair > /dev/null
    RET=$?
    if [ $RET -ne 0 ]; then
        writelog "mongodb repair process doesnt success ret:$RET ....."
        writelog "delete mongodb index files.."
        if [ ! -z $MONGODB_DATA_PATH ];then
            rm -rf "$MONGODB_DATA_PATH/*"
        fi 
    fi    
    chown -R mongodb:mongodb $MONGODB_DATA_PATH
    writelog "Trying mongodb restart again...."
    service mongod onerestart
    RET=$?
    if [ $RET -ne 0 ]; then
        writelog "mongodb doesn't start ret : $RET ....."
        writelog "deleting mongodb index....."
        if [ ! -z $MONGODB_DATA_PATH ];then
            rm -rf $MONGODB_DATA_PATH/*
        fi    
    else
       writelog "mongodb start successful..."
       exit 0    
    fi    
    writelog "Trying mongodb restart again...."
    service mongod onerestart
    RET=$?
    if [ $RET -ne 0 ]; then
        writelog "mongodb doesn't start ret : $RET ....."
    fi
else
    writelog "mongodb start successful..."
    exit 0        
fi