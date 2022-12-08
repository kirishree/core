#!/bin/sh
if [ -f "/tmp/sensei_update.progress" ]; then
    rm -rf "/tmp/sensei_update.progress"
fi

PKG_PROGRESS_FILE=/tmp/zenarmor_update.progress
DBTYPE=$1

if [ $DBTYPE == 'MN']; then
    service mongod onestop
    MONGO_DATA_PATH_DEFAULT="/var/db/mongodb"
    MONGO_DATA_PATH="/usr/local/datastore/mongodb"
    MONGO_CONF_FILE="/usr/local/etc/mongodb.conf"
    MONGOD_PATH="/usr/local/etc/rc.d/mongod"

    echo "Setting mongodb data path: $MONGO_DATA_PATH..." >>${PKG_PROGRESS_FILE}
    if [ ! -d $MONGO_DATA_PATH ]; then
        mkdir -p $MONGO_DATA_PATH
    fi
    chown -R mongodb:mongodb $MONGO_DATA_PATH
    php -r "file_put_contents('$MONGO_CONF_FILE',str_replace('$MONGO_DATA_PATH_DEFAULT','$MONGO_DATA_PATH',file_get_contents('$MONGO_CONF_FILE')));"
    php -r "file_put_contents('$MONGOD_PATH',str_replace('$MONGO_DATA_PATH_DEFAULT','$MONGO_DATA_PATH',file_get_contents('$MONGOD_PATH')));"

    service mongod onestart
fi

if [ $DBTYPE == 'ES']; then

    service elasticsearch onestop
    ES_DATA_PATH_DEFAULT="/var/db/elasticsearch"
    ES_DATA_PATH="/usr/local/datastore/elasticsearch"
    ES_CONF_FILE="/usr/local/etc/elasticsearch/elasticsearch.yml"

    echo "Setting elasticsearch data path: $ES_DATA_PATH..." >>${PKG_PROGRESS_FILE}
    if [ ! -d $ES_DATA_PATH ]; then
        mkdir -p $ES_DATA_PATH
    fi
    chown -R elasticsearch:elasticsearch $ES_DATA_PATH
    php -r "file_put_contents('$ES_CONF_FILE',str_replace('$ES_DATA_PATH_DEFAULT','$ES_DATA_PATH',file_get_contents('$ES_CONF_FILE')));"
    service elasticsearch onestart
fi
echo "done"
