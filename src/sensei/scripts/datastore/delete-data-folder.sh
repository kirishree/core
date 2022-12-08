#!/bin/sh
if [ "$#" -ne 1 ]; then
   echo "Must be least one parameter"
   exit 0
fi

if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi

if [ $1 == 'ES' ]; then
    /usr/local/sensei/scripts/installers/elasticsearch/delete_data_folder.sh
fi

if [ $1 == 'MN' ]; then
    MONGODB_DATA_PATH=$(/usr/local/sensei/scripts/datastore/get-db-path.sh MN)
    RET=$?
    rm -rf /var/db/mongodb/* /usr/local/datastore/mongodb/*
    if [ $RET -eq 0 ];then 
        if [ ! -z $MONGODB_DATA_PATH ]; then 
            rm -rf $MONGODB_DATA_PATH/*
        fi    
    fi
fi

if [ $1 == 'SQ' ]; then
    rm -rf /usr/local/datastore/sqlite/*
fi
