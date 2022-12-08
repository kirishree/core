#!/bin/sh
if [ "$#" -ne 1 ]; then
    echo "Must be least one parameters"
    exit 0
fi
DB=$1

if [ "$DB" = 'ES' ]; then
    configfile="/usr/local/etc/elasticsearch/elasticsearch.yml"
    if [ -f $configfile ]; then 
        data_path=$(grep "^path.data" $configfile)
        real_path=$(echo -n $data_path | awk -F':' '{ print $2 }')
        if [ -d $real_path ]; then
            echo $real_path
        else
            echo ''    
        fi
    fi    
    exit 0
fi
if [ "$DB" = 'MN' ]; then
    #configfile="/usr/local/etc/mongodb.conf"
    #data_path=$(grep "^  dbPath:" $configfile)
    #real_path=$(echo -n $data_path | awk -F':' '{ print $2 }')
    if [ -f /usr/local/etc/rc.d/mongod ]; then 
        # real_path=$(grep "mongod_dbpath=" /usr/local/etc/rc.d/mongod | grep -v "^#" | grep "^:" | awk -F'"' '{ print $2 }')
        real_path=$(grep "dbPath: " /usr/local/etc/mongodb.conf | awk '{ print $2 }')
        if [ -d $real_path ]; then
            echo $real_path
            exit 0
        fi
    fi    

fi

if [ "$DB" = 'SQ' ]; then
    SQ_PATH="/usr/local/datastore/sqlite"
    if [ -d $SQ_PATH ]; then 
        echo $SQ_PATH
        exit 0
    else 
        echo "ERROR:SQLITE data path does not found."
        exit 0
    fi
        
fi
echo "ERROR:Not Found"
exit 0
