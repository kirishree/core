#!/bin/sh
HOSTUUID=$(/usr/local/sensei/bin/eastpect -s)
BOOTDIR="/tmp/$HOSTUUID"
if [ ! -d $BOOTDIR ];then 
    mkdir $BOOTDIR
fi
TAR_FILE="$BOOTDIR/opnsense_files.tar"
rm -rf "$TAR_FILE*"
FNAME="/conf/config.xml"
if [ -f $FNAME ];then
    tar -cvf $TAR_FILE $FNAME
fi 

FNAME="/var/log/configd.log"
if [ -f $FNAME ];then
    tar -uvf $TAR_FILE $FNAME
fi 

if [ -d /var/log/configd ];then
    FNAME=$(ls -t /var/log/configd/configd* | head -1)
    if [ -f $FNAME ];then
        tar -uvf $TAR_FILE $FNAME
    fi 
fi


FNAME="/var/log/dhcpd.log"
if [ -f $FNAME ];then
    tar -uvf $TAR_FILE $FNAME
fi 

FNAME="/var/log/dmesg.today"
if [ -f $FNAME ];then
    tar -uvf $TAR_FILE $FNAME
fi 

FNAME="/var/run/dmesg.boot"
if [ -f $FNAME ];then
    tar -uvf $TAR_FILE $FNAME
fi 

FNAME="/var/log/dmesg.yesterday"
if [ -f $FNAME ];then
    tar -uvf $TAR_FILE $FNAME
fi 

FNAME="/var/log/lastlog"
if [ -f $FNAME ];then
    tar -uvf $TAR_FILE $FNAME
fi 

FNAME="/var/log/routing.log"
if [ -f $FNAME ];then
    tar -uvf $TAR_FILE $FNAME
fi 

FNAME="/var/log/suricata.log"
if [ -f $FNAME ];then
    tar -uvf $TAR_FILE $FNAME
fi 

FNAME="/var/log/system.log"
if [ -f $FNAME ];then
    tar -uvf $TAR_FILE $FNAME
fi 
if [ -d /var/log/system ];then
    FNAME=$(ls -t /var/log/system/system* | head -1)
    if [ -f $FNAME ];then
        tar -uvf $TAR_FILE $FNAME
    fi 
fi

FNAME="/tmp/pciconf.log"
pciconf -lv>$FNAME
if [ -f $FNAME ];then
    tar -uvf $TAR_FILE $FNAME
fi 

gzip $TAR_FILE