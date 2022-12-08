#!/bin/sh

if [ -f "/tmp/sensei_update.progress" ]; then
    rm -rf "/tmp/sensei_update.progress"
fi

PKG_PROGRESS_FILE=/tmp/zenarmor_update.progress
# Truncate upgrade progress file
: > ${PKG_PROGRESS_FILE}
echo "***GOT REQUEST TO INSTALL: MONGODB***" >> ${PKG_PROGRESS_FILE}

if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi

DISTRO_OVERRIDE="opnsense/18.1"
KEEP_DATA="$1"
START_ENGINE="$2"

echo "MongoDB installing with params keep data: $KEEP_DATA start engine: $START_ENGINE " >> ${PKG_PROGRESS_FILE}
. $EASTPECT_ROOT/scripts/health/$DISTRO_OVERRIDE/functions_eastpect.sh

# OPNSENSE_VERSION=$(opnsense-version | sed "s/\.//g" | awk '{ print $2 }')

# if [ $OPNSENSE_VERSION -lt 1978 ];then 
#    echo "Warning: Please make sure you are running the latest OPNsense version" > ${PKG_PROGRESS_FILE}
#    exit 1
# fi

MONGO_DATA_PATH_DEFAULT="/var/db/mongodb"
DATA_FOLDER="/usr/local/datastore"
MONGO_DATA_PATH="/usr/local/datastore/mongodb"
MONGO_CONF_FILE="/usr/local/etc/mongodb.conf"
MONGOD_PATH="/usr/local/etc/rc.d/mongod"

EASTPECT_STATUS=$(service eastpect status|grep -c "is running")
if [ $EASTPECT_STATUS -gt 0 ]; then 
    echo -n "Stoping engine..."
    service eastpect onestop
    echo "done"
fi 

mongo_ps=$(ps aux|grep -c mongod|grep -v grep)
if [ $mongo_ps -ne 0 ]; then
  echo "Stoped deamon..." >> ${PKG_PROGRESS_FILE}
  service mongod onestop >> ${PKG_PROGRESS_FILE} 2>&1
fi

echo "Auto removing..." >> ${PKG_PROGRESS_FILE}
pkg remove -fy mongodb40 >> ${PKG_PROGRESS_FILE} 2>&1
pkg autoremove -y
if [ -f /usr/local/etc/mongodb.conf ];then 
    rm -rf /usr/local/etc/mongodb.conf
fi

if [ "$KEEP_DATA" == "false" ]; then
    echo "Removing mongodb data">> ${PKG_PROGRESS_FILE}
    rm -rf $MONGO_DATA_PATH_DEFAULT/* $MONGO_DATA_PATH/*
fi

echo "Cleaning packages..." >> ${PKG_PROGRESS_FILE}
pkg clean -ay >> ${PKG_PROGRESS_FILE} 2>&1

echo "Update repository..." >> ${PKG_PROGRESS_FILE}
pkg update -f >> ${PKG_PROGRESS_FILE} 2>&1
echo "Installing database..." >> ${PKG_PROGRESS_FILE}
pkg install -fy mongodb40 >> ${PKG_PROGRESS_FILE} 2>&1
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
# php -r "file_put_contents('$MONGOD_PATH',str_replace('$MONGO_DATA_PATH_DEFAULT','$MONGO_DATA_PATH',file_get_contents('$MONGOD_PATH')));"
CN=$(grep -c "cacheSizeGB" $MONGO_CONF_FILE)
if [ $CN -eq 0 ]; then
    cahceSize=0.25
    memsize=$(sysctl -n hw.physmem)
    if [ $memsize -gt 2100000000 ];then
        cahceSize=0.5
    fi
    if [ $memsize -gt 3200000000 ];then
        cahceSize=1
    fi
    if [ $memsize -gt 4200000000 ];then
        cahceSize=1.5
    fi
    if [ $memsize -gt 5200000000 ];then
        cahceSize=2
    fi
    LN=$(grep -n wiredTiger /usr/local/etc/mongodb.conf | head -1 | cut -d ':' -f1)
    BCONTENT=$(head -$LN $MONGO_CONF_FILE)
    C=$(wc -l $MONGO_CONF_FILE|awk '{ print $1 }')
    DIFF=$(echo "$C - $LN" | bc)
    ECONTENT=$(tail -$DIFF $MONGO_CONF_FILE)
    echo "$BCONTENT" > $MONGO_CONF_FILE
    cat >> $MONGO_CONF_FILE << __EOF
  wiredTiger:
    engineConfig:
      cacheSizeGB: $cahceSize
__EOF
    echo "$ECONTENT" >> $MONGO_CONF_FILE
fi      
echo "Configuration /boot/loader.conf for mongo">> ${PKG_PROGRESS_FILE}
CN=$(grep -c "kern.maxproc=131072" /boot/loader.conf)
if [ $CN -eq 0 ]; then
cat >> /boot/loader.conf << __EOF

# FASTBOOT
  autoboot_delay=1

# MONGODB LIMITS
  kern.maxproc=131072

__EOF
fi      

echo "Configuration /etc/sysctl.conf for mongo">> ${PKG_PROGRESS_FILE}
CN=$(grep -c "kern.maxfiles=262144" $MONGO_CONF_FILE)
if [ $CN -eq 0 ]; then
cat >> /etc/sysctl.conf << __EOF

# MONGODB LIMITS
  kern.maxfiles=262144
  kern.maxfilesperproc=262144
  kern.maxprocperuid=131072

__EOF
echo "<b>Please reboot after configuration </b>">> ${PKG_PROGRESS_FILE}
fi      

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

if [ "$START_ENGINE" == "true" ]; then
    echo 'Restarting engine...' >> ${PKG_PROGRESS_FILE}
    service eastpect onerestart >> ${PKG_PROGRESS_FILE}
fi
echo '***DONE***' >> ${PKG_PROGRESS_FILE}

if [ $EASTPECT_STATUS -gt 0 ]; then 
    echo -n "Starting engine..."
    service eastpect onestart
    echo "done"
fi 

exit 0
