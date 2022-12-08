#!/bin/sh
HOSTUUID=$(/usr/local/sensei/bin/eastpect -s)
BOOTDIR="/tmp/$HOSTUUID"
if [ ! -d $BOOTDIR ];then 
    mkdir $BOOTDIR
fi
TAR_FILE="$BOOTDIR/sensei_log.tar"
rm -rf $TAR_FILE*
rm -rf /tmp/worker.log
DT=$(date +%Y%m%d)
find /usr/local/sensei/log/active -iname "main*.log" -type f -ctime -1 | xargs tar -cvf $TAR_FILE > /dev/null 2>&1
find /usr/local/sensei/log/archive -iname "worker*"$DT"*.log" -type f -exec grep -i "warning\|error\|critical\|debug\|fatal" {} \; -print >> /tmp/worker.log
find /usr/local/sensei/log/active -iname "worker*"$DT"*.log" -type f -exec grep -i "warning\|error\|critical\|debug\|fatal" {} \; -print >> /tmp/worker.log
tar -uvf $TAR_FILE /tmp/worker.log > /dev/null 2>&1

FNAME="/usr/local/sensei/log/active/Periodical.log"
if [ -f $FNAME ];then 
    rm -rf /tmp/periodical.log
    tail -200 $FNAME>>/tmp/periodical.log
    tar -uvf $TAR_FILE /tmp/periodical.log > /dev/null 2>&1
fi    
FNAME="/usr/local/sensei/log/active/schedule_reports.log"
if [ -f $FNAME ];then 
    rm -rf /tmp/schedule_reports.log
    tail -200 $FNAME>>/tmp/schedule_reports.log
    tar -uvf $TAR_FILE /tmp/schedule_reports.log > /dev/null 2>&1
fi    
FNAME="/usr/local/sensei/log/active/Senseigui.log"
if [ -f $FNAME ];then 
    rm -rf /tmp/Senseigui.log
    tail -500 $FNAME | grep -v "Starting Userenricher">>/tmp/Senseigui.log
    echo '---------------------EXCEPTION-----------------------'>>/tmp/Senseigui.log
    grep -i '::exception::' $FNAME>>/tmp/Senseigui.log
    tar -uvf $TAR_FILE /tmp/Senseigui.log > /dev/null 2>&1
fi    
FNAME="/usr/local/sensei/log/active/ipdr_retire.log"
if [ -f $FNAME ];then 
    rm -rf /tmp/ipdr_retire.log
    tail -200 $FNAME>>/tmp/ipdr_retire.log
    tar -uvf $TAR_FILE /tmp/ipdr_retire.log > /dev/null 2>&1
fi    

FNAME="/usr/local/sensei/log/active/ipdr_streamer.log"
if [ -f $FNAME ];then 
    rm -rf /tmp/ipdr_streamer.log
    tail -200 $FNAME>>/tmp/ipdr_streamer.log
    tar -uvf $TAR_FILE /tmp/ipdr_streamer.log > /dev/null 2>&1
fi    

FNAME="/usr/local/sensei/log/active/ipdrstreamer.log"
if [ -f $FNAME ];then 
    rm -rf /tmp/ipdrstreamer.log
    tail -200 $FNAME>>/tmp/ipdrstreamer.log
    tar -uvf $TAR_FILE /tmp/ipdrstreamer.log > /dev/null 2>&1
fi    

FNAME="/usr/local/sensei/log/active/license_check.log"
if [ -f $FNAME ];then 
    rm -rf /tmp/license_check.log
    tail -200 $FNAME>>/tmp/license_check.log
    tar -uvf $TAR_FILE /tmp/license_check.log > /dev/null 2>&1
fi    

FNAME="/usr/local/sensei/log/active/update_check.log"
if [ -f $FNAME ];then 
    rm -rf /tmp/update_check.log
    tail -200 $FNAME>>/tmp/update_check.log
    tar -uvf $TAR_FILE /tmp/update_check.log > /dev/null 2>&1
fi

FNAME="/tmp/send_health.log"
if [ -f $FNAME ];then 
    rm -rf /tmp/send_health_1.log
    tail -500 $FNAME>>/tmp/send_health_1.log
    tar -uvf $TAR_FILE /tmp/send_health_1.log > /dev/null 2>&1
fi    

FNAME="/usr/local/sensei/log/active/health_check.log"
if [ -f $FNAME ];then 
    rm -rf /tmp/health_check.log.log
    tail -500 $FNAME>>/tmp/health_check.log.log
    tar -uvf $TAR_FILE /tmp/health_check.log > /dev/null 2>&1
fi    

FNAME="/usr/local/sensei/log/active/notifications.json"
if [ -f $FNAME ];then 
    rm -rf /tmp/notifications.json
    tail -500 $FNAME>>/tmp/notifications.json
    tar -uvf $TAR_FILE /tmp/notifications.json > /dev/null 2>&1
fi    

for fname in `ls /tmp/bypass_fails*`
do
    tar -uvf $TAR_FILE $fname > /dev/null 2>&1
done

for fname in `ls /tmp/*.progress`
do
    tar -uvf $TAR_FILE $fname > /dev/null 2>&1
done

if [ -f /usr/local/sensei/log/active/cloud_agent.log ]; then
    grep -i "warning\|error\|critical\|debug\|fatal" /usr/local/sensei/log/active/cloud_agent.log > /tmp/cloud_agent.log
    tar -uvf $TAR_FILE /tmp/cloud_agent.log > /dev/null 2>&1
fi

ZENARMOR_AGENT_CRASH=""
for d in `ls -rt /usr/local/sensei/log/active/zenarmor_agent_crash.*`
do
    ZENARMOR_AGENT_CRASH=$d
done

if [ ! -z $ZENARMOR_AGENT_CRASH ]; then
    tar -uvf $TAR_FILE $ZENARMOR_AGENT_CRASH > /dev/null 2>&1
fi

IPDRSTREAMER_CRASH=""
for d in `ls -rt /usr/local/sensei/log/active/ipdrstreamer_crash.*`
do
    IPDRSTREAMER_CRASH=$d
done

if [ ! -z $IPDRSTREAMER_CRASH ]; then
    tar -uvf $TAR_FILE $IPDRSTREAMER_CRASH > /dev/null 2>&1
fi

if [ -f /var/log/elasticsearch/elasticsearch.log ]; then
    grep -i "warning\|error\|critical\|debug\|fatal" /var/log/elasticsearch/elasticsearch.log > /tmp/elasticsearch.log
    tar -uvf $TAR_FILE /tmp/elasticsearch.log > /dev/null 2>&1
fi

if [ -f /usr/local/datastore/mongodb/mongod.log ]; then
    tail -5000 /usr/local/datastore/mongodb/mongod.log | grep -i "warning\|error\|critical\|debug\|fatal" > /tmp/mongod.log
    tar -uvf $TAR_FILE /tmp/mongod.log > /dev/null 2>&1
fi

gzip $TAR_FILE