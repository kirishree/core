#!/bin/sh
HOSTUUID=$(/usr/local/sensei/bin/eastpect -s)
BOOTDIR="/tmp/$HOSTUUID"
if [ ! -d $BOOTDIR ];then 
    mkdir $BOOTDIR
fi
TAR_FILE="$BOOTDIR/sensei_config.tar"
rm -rf "$TAR_FILE*"
tar -cvf $TAR_FILE /usr/local/sensei/userdefined/*
tar -uvf $TAR_FILE /usr/local/sensei/etc/*
gzip $TAR_FILE