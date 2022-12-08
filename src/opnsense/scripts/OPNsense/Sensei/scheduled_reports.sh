#!/bin/sh

if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi

LOG_PATH="$EASTPECT_ROOT/log/active/schedule_reports.log"
dt=$(date)
echo "$dt : Starting schedule reports">>$LOG_PATH
echo "Parameters $@">>$LOG_PATH
rm -f /usr/local/opnsense/scripts/OPNsense/Sensei/report-gen/attachment.htm
rm -f /usr/local/opnsense/scripts/OPNsense/Sensei/report-gen/attachment.pdf
rm -f /usr/local/opnsense/scripts/OPNsense/Sensei/report-gen/body.htm

PDF="false"
if [ $# -gt 0 ];then 
    PDF=$1
fi

DB_TYPE=$(grep "type = " /usr/local/sensei/etc/eastpect.cfg|awk '{ print $3 }')
if [ $DB_TYPE == "ES" ]; then
    /usr/local/opnsense/scripts/OPNsense/Sensei/report-gen/generate_es.py $PDF>>$LOG_PATH 2>&1
fi
if [ $DB_TYPE == "MN" ]; then
    /usr/local/opnsense/scripts/OPNsense/Sensei/report-gen/generate_mn.py $PDF>>$LOG_PATH 2>&1
fi

/usr/local/opnsense/scripts/OPNsense/Sensei/report-gen/send.py>>$LOG_PATH 2>&1
dt=$(date)
echo "$dt : End schedule reports">>$LOG_PATH
exit 0
