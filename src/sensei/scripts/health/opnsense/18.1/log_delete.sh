	#!/bin/sh
PATH=$PATH:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin

if [ -z $EASTPECT_ROOT ];then
        EASTPECT_ROOT="/usr/local/sensei/"
fi
LOG_FILE="$EASTPECT_ROOT/log/active/Periodical.log"
DT=$(date)
echo "$DT Starting log delete operation...">>$LOG_FILE

EASTPECT_CFG="$EASTPECT_ROOT/etc/eastpect.cfg"

N_LINE=$(grep -n "Logger" $EASTPECT_CFG| cut -d':' -f1)
MAX_DAY=$(awk "NR > $N_LINE" $EASTPECT_CFG |grep "retire = " |awk '{ print $3 }')

if [ ! -z $1 ];then
    MAX_DAY=$1
fi

if [ -z $MAX_DAY ]; then
    MAX_DAY=3
fi

echo "$MAX_DAY is max day...">>$LOG_FILE
/usr/bin/find ${EASTPECT_ROOT}/log/active/ -type f -mtime +"$MAX_DAY"d  | xargs rm -f {}\;>>$LOG_FILE 2>&1
/usr/bin/find ${EASTPECT_ROOT}/log/archive/ -type f -mtime +"$MAX_DAY"d  | xargs rm -f {}\;>>$LOG_FILE 2>&1
/usr/bin/find ${EASTPECT_ROOT}/output/active/ -type f -mtime +"$MAX_DAY"d  | xargs rm -f {}\;>>$LOG_FILE 2>&1 
/usr/bin/find ${EASTPECT_ROOT}/output/archive/ -type f -mtime +"$MAX_DAY"d  | xargs rm -f {}\;>>$LOG_FILE 2>&1
if [ -d /var/log/elasticsearch ];then
    if [ $MAX_DAY -gt 7 ];then
        MAX_DAY=7
    fi    
	/usr/bin/find /var/log/elasticsearch -type f -mtime +"$MAX_DAY"d  | xargs rm -f {}\;>>$LOG_FILE 2>&1
fi	
DT=$(date)
echo "$DT End log delete operation...">>$LOG_FILE