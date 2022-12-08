#!/bin/sh
if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi
CMD="$EASTPECT_ROOT/bin/eastpect -p"
DB_PATH=$($CMD)
RET=$?

if [ $RET = 0 ];then 
   SENSEI_DB_PKG_VERSION="$DB_PATH/VERSION"
else
   SENSEI_PKG_VERSION="$EASTPECT_ROOT/db/VERSION"
   SENSEI_DB_PKG_VERSION="/usr/local/sensei-db/VERSION"
fi

VERSION=''
DT=''

if [ -f $SENSEI_DB_PKG_VERSION ]; then 
      VERSION=$(cat $SENSEI_DB_PKG_VERSION)
      #DT=$(date -r $SENSEI_DB_PKG_VERSION -R +"%m/%d/%Y %H:%M")
      DT=$(date -r $SENSEI_DB_PKG_VERSION)

else 
   VERSION=$(cat $SENSEI_PKG_VERSION)
   DT=$(date -r $SENSEI_PKG_VERSION)
fi

if [ -z "$1" ]; then 
   echo $VERSION
   exit 0
fi
echo $DT