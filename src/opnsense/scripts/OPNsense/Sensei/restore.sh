#!/bin/sh
if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi
LOG_FILE="$EASTPECT_ROOT/log/active/Senseigui.log"
BACKUP_FILE=$1
PID=$$
writelog() {
    MSG=$1
    DT=$(date +"%a, %d %b %y %T %z")
    echo "[$DT][INFO] [$PID] $MSG">>$LOG_FILE
}

writelog "Zenarmor restore starting..."

if [ ! -f $BACKUP_FILE ]; then 
    writelog "Error:$BACKUP_FILE not exists"
    echo "Error:$BACKUP_FILE not exists"
    exit 0
fi

TS=$(date +%s)
MAIN_FOLDER="/tmp/$TS"
mkdir $MAIN_FOLDER
RET=$?
if [ $RET -ne 0 ]; then
    writelog "Error:$MAIN_FOLDER folder could not create"
    echo "Error:$MAIN_FOLDER folder could not create"
    exit 0
fi

writelog "Zenarmor backup currenct configuration..."
if [ -f /conf/config.xml ]; then
    cp /conf/config.xml "$EASTPECT_ROOT/userdefined/config/config.xml.$TS"
fi 
if [ -f $EASTPECT_ROOT/userdefined/config/settings.db ]; then
    cp $EASTPECT_ROOT/userdefined/config/settings.db "$EASTPECT_ROOT/userdefined/config/settings.db.$TS"
fi 

if [ -f $EASTPECT_ROOT/etc/license.data ]; then
    cp $EASTPECT_ROOT/etc/license.data "$EASTPECT_ROOT/etc/license.data.$TS"
fi 

if [ -f $EASTPECT_ROOT/etc/token ]; then
    cp $EASTPECT_ROOT/etc/token "$EASTPECT_ROOT/etc/token.$TS"
fi 

if [ -f $EASTPECT_ROOT/cert/nabca.crt ]; then
    cp $EASTPECT_ROOT/cert/nabca.crt "$EASTPECT_ROOT/cert/nabca.crt.$TS"
fi 

if [ -f $EASTPECT_ROOT/cert/nabnode.crt ]; then
    cp $EASTPECT_ROOT/cert/nabnode.crt "$EASTPECT_ROOT/cert/nabnode.crt.$TS"
fi 

if [ -f $EASTPECT_ROOT/cert/nabnode.key ]; then
    cp $EASTPECT_ROOT/cert/nabnode.key "$EASTPECT_ROOT/cert/nabnode.key.$TS"
fi 


FNAME=$(basename $BACKUP_FILE)
FN="$MAIN_FOLDER/$FNAME"
ENC=$4
if [ "$ENC" == "true" ]; then
        PASS=$5
        openssl enc -aes-256-cbc -d -in $BACKUP_FILE -out $FN -k $PASS > /dev/null
        RET=$?
        if [ $RET -ne 0 ]; then
            writelog "Error: Backup File could not be decrypted. Please double check your backup password."
            echo "Error: Backup File could not be decrypted. Please double check your backup password."
            rm -rf $FN
            exit 0
        fi
else
   FN="$BACKUP_FILE"         
fi

cd $MAIN_FOLDER
tar -xvf $FN
RET=$?
if [ $RET -ne 0 ]; then
    writelog "Error:$FN folder could not create"
    echo "Error:$FN folder could not create"
    exit 0
fi

LE=$2
if [ "$LE" == "false" ]; then
    LICENSE_FILE=$(find $MAIN_FOLDER -type f -name "license.data") 
    writelog "$LICENSE_FILE will be load"
    if [ -f $LICENSE_FILE ]; then 
        cp $LICENSE_FILE /tmp/sensei-license.data
    fi    
fi

CLOUD_EX=$3
if [ "$CLOUD_EX" == "false" ]; then
    CERT_DIR=$(find $MAIN_FOLDER -type d -name "cert" -d 1) 
    writelog "$CERT_DIR will be load"
    if [ -d $CERT_DIR ]; then 
        cp -r $CERT_DIR $EASTPECT_ROOT/.
    fi
    TOKEN_FILE=$(find $MAIN_FOLDER -type f -name "token" -d 1) 
    writelog "$TOKEN_FILE will be load"
    if [ -f $TOKEN_FILE ]; then 
        cp  $TOKEN_FILE $EASTPECT_ROOT/etc/.
    fi     
fi

writelog "Success"
find $MAIN_FOLDER -type f \( -name "*.xml" -or -name "*.db" \)
exit 0