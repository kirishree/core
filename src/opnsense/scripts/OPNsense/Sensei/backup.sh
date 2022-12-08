#!/bin/sh
if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi
LOG_FILE="$EASTPECT_ROOT/log/active/Senseigui.log"
BACKUP_PATH="/usr/local/bpsensei"
PID=$$
writelog() {
    MSG=$1
    DT=$(date +"%a, %d %b %y %T %z")
    # [Fri, 31 Jan 20 12:06:12 +0300][INFO] [93119][D:0] Starting Mongodb
    echo "[$DT][INFO] [$PID] $MSG">>$LOG_FILE
}

writelog "Zenarmor backup starting..."

if [ ! -d $BACKUP_PATH ]; then 
    mkdir -p $BACKUP_PATH
fi

if [ ! -d $BACKUP_PATH ]; then 
    writelog "Error:$BACKUP_PATH folder could not create"
    echo "Error:$BACKUP_PATH folder could not create"
    exit 0
fi

TS=$(date +%s)
FN="/tmp/$TS"
mkdir $FN
RET=$?
if [ $RET -ne 0 ]; then
    writelog "Error:$FN folder could not create"
    echo "Error:$FN folder could not create"
    exit 0
fi

cp /conf/config.xml $FN/config.xml
RET=$?
if [ $RET -ne 0 ]; then
    writelog "Error: Configure file could not backup"
    echo "Error: Configure file could not backup"
    rm -rf $FN
    exit 0
fi

if [ -f "$EASTPECT_ROOT/etc/license.data" ]; then 
    cp "$EASTPECT_ROOT/etc/license.data" $FN/license.data
    RET=$?
    if [ $RET -ne 0 ]; then
        writelog "Error: License file could not backup"
        echo "Error: License file could not backup"
        rm -rf $FN
        exit 0
    fi    
fi

if [ -f "$EASTPECT_ROOT/etc/token" ]; then 
    cp "$EASTPECT_ROOT/etc/token" $FN/token
    RET=$?
    if [ $RET -ne 0 ]; then
        writelog "Error: Token file could not backup"
        echo "Error: Token file could not backup"
        rm -rf $FN
        exit 0
    fi    
fi

if [ -f "$EASTPECT_ROOT/cert/nabca.crt" ]; then 
    cp -rp "$EASTPECT_ROOT/cert" $FN/.
    RET=$?
    if [ $RET -ne 0 ]; then
        writelog "Error: Cert folder could not backup"
        echo "Error: Cert folder file could not backup"
        rm -rf $FN
        exit 0
    fi    
fi

cp "$EASTPECT_ROOT/userdefined/config/settings.db" $FN/settings.db
RET=$?
if [ $RET -ne 0 ]; then
    MSG="Error: Policies could not backup"
    writelog $MSG
    echo $MSG
    rm -rf $FN
    exit 0
fi
HOSTNAME=$(hostname)
BNAME="sensei-backup-$HOSTNAME-$TS"
tar -cvf /tmp/$BNAME.tar /tmp/$TS
RET=$?
if [ $RET -ne 0 ]; then
    writelog "Error: Package not created"
    echo "Error: Package not created"
    rm -rf $FN
    exit 0
fi

gzip /tmp/$BNAME.tar
RET=$?
if [ $RET -ne 0 ]; then
    writelog "Error: File not compressed"
    echo "Error: File not compressed"
    rm -rf $FN
    exit 0
fi

FNAME="/tmp/$BNAME.tar.gz"
if [ $# -eq 1 ]; then
    PASS=$1
    if [ ${#PASS} -gt 2 ]; then
        openssl enc -aes-256-cbc -salt -in $FNAME -out $FNAME.enc -k $PASS > /dev/null
        RET=$?
        if [ $RET -ne 0 ]; then
            writelog "Error: File could not encrypted"
            echo "Error: File could not encrypted"
            rm -rf $FN
            exit 0
        fi
        FNAME="$FNAME.enc" 
    fi    
fi

cp $FNAME $BACKUP_PATH/.
RET=$?
if [ $RET -ne 0 ]; then
    writelog "Error: Backup file not copied"
    echo "Error: Backup file not copied"
    rm -rf $FN
    exit 0
fi

echo 'OK'