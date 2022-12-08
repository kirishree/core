#!/bin/sh
PKG_PROGRESS_FILE=/tmp/sensei_mongodb.progress
# Truncate upgrade progress file
: > ${PKG_PROGRESS_FILE}
echo "***GOT REQUEST TO INSTALL: MONGODB***" >> ${PKG_PROGRESS_FILE}

if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi

MONGO_DATA_PATH_DEFAULT="/var/db/mongodb"
DATA_FOLDER="/usr/local/datastore"
MONGO_DATA_PATH=$(grep -i dbpath /usr/local/etc/mongodb.conf | awk '{ print $2 }')
MONGO_CONF_FILE="/usr/local/etc/mongodb.conf"
MONGOD_PATH="/usr/local/etc/rc.d/mongod"

mongo_ps=$(ps aux|grep -c mongod|grep -v grep)
if [ $mongo_ps -ne 0 ]; then
  echo "Stoped deamon..." >> ${PKG_PROGRESS_FILE}
  service mongod onestop >> ${PKG_PROGRESS_FILE} 2>&1
fi

# echo "Installing Python Mongodb module..." >> ${PKG_PROGRESS_FILE}
# pkg install -fy py37-pymongo >> ${PKG_PROGRESS_FILE} 2>&1

echo "Installing Php Mongodb module..." >> ${PKG_PROGRESS_FILE}
PHPVER=$(php -v | head -1 | awk '{ print $2 }' | sed 's/\.//g' | awk '{print substr ($0, 0, 2)}')
if [ $PHPVER -eq 72 ];then 
  echo "php72-pecl-mongodb module..." >> ${PKG_PROGRESS_FILE}
  pkg install -fy php72-pecl-mongodb >> ${PKG_PROGRESS_FILE} 2>&1
fi  
if [ $PHPVER -eq 73 ];then   
  echo "php73-pecl-mongodb module..." >> ${PKG_PROGRESS_FILE}
  pkg install -fy php73-pecl-mongodb >> ${PKG_PROGRESS_FILE} 2>&1
fi
if [ $PHPVER -eq 74 ];then   
  echo "php74-pecl-mongodb module..." >> ${PKG_PROGRESS_FILE}
  pkg install -fy php74-pecl-mongodb >> ${PKG_PROGRESS_FILE} 2>&1
fi
if [ $PHPVER -eq 80 ];then   
  echo "php80-pecl-mongodb module..." >> ${PKG_PROGRESS_FILE}
  pkg install -fy php80-pecl-mongodb >> ${PKG_PROGRESS_FILE} 2>&1
fi

echo "Restart webgui ...." >> ${PKG_PROGRESS_FILE}
/usr/local/sbin/configctl webgui restart  >> ${PKG_PROGRESS_FILE} 2>&1

if [ ! -d $MONGO_DATA_PATH ]; then
    mkdir -p $MONGO_DATA_PATH
fi    
chmod 755 $DATA_FOLDER
chown -R mongodb:mongodb $MONGO_DATA_PATH

php -r "file_put_contents('$MONGO_CONF_FILE',str_replace('$MONGO_DATA_PATH_DEFAULT','$MONGO_DATA_PATH',file_get_contents('$MONGO_CONF_FILE')));"
php -r "file_put_contents('$MONGOD_PATH',str_replace('$MONGO_DATA_PATH_DEFAULT','$MONGO_DATA_PATH',file_get_contents('$MONGOD_PATH')));"

echo "Starting mongodb service..." >> ${PKG_PROGRESS_FILE}
service mongod onestart>> ${PKG_PROGRESS_FILE}
RET=$?
if [ $RET -ne 0 ]; then
  echo "***ERROR*** Mongodb service could not start : $RET" >> ${PKG_PROGRESS_FILE}
  exit 1
fi

echo "Setting mongodb autostart..." >> ${PKG_PROGRESS_FILE}
if [ ! -f /etc/rc.conf.d/mongod ]; then
    echo 'mongod_enable="YES"' > /etc/rc.conf.d/mongod
fi

echo "Create Indexes..." >> ${PKG_PROGRESS_FILE}
$EASTPECT_ROOT/scripts/installers/mongodb/create_collection.py >> ${PKG_PROGRESS_FILE}

echo '***DONE***' >> ${PKG_PROGRESS_FILE}
cat ${PKG_PROGRESS_FILE}
exit 0
