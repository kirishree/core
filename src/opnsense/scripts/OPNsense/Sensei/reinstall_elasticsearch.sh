#!/bin/sh

if [ -f "/tmp/sensei_update.progress" ]; then
    rm -rf "/tmp/sensei_update.progress"
fi

PKG_PROGRESS_FILE=/tmp/zenarmor_update.progress
# Truncate upgrade progress file
: > ${PKG_PROGRESS_FILE}
echo "***GOT REQUEST TO INSTALL: ELASTICSEARCH***" >> ${PKG_PROGRESS_FILE}

if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi

DISTRO_OVERRIDE="opnsense/18.1"
KEEP_DATA="$1"
START_ENGINE="$2"
echo "Elasticsearch installing with params keep data: $KEEP_DATA start engine: $START_ENGINE " >> ${PKG_PROGRESS_FILE}

. $EASTPECT_ROOT/scripts/health/$DISTRO_OVERRIDE/functions_eastpect.sh

echo -n "Preparing java settings..."
if [ -z "$(grep 'fdesc' /etc/fstab)" ]; then echo "fdesc /dev/fd fdescfs rw 0 0" >> "/etc/fstab"; fi
if [ -z "$(grep 'proc' /etc/fstab)" ]; then echo "proc /proc procfs rw 0 0" >> "/etc/fstab"; fi
if [ -z "$(df | grep fdesc)" ]; then mount -t fdescfs fdesc "/dev/fd"; fi
if [ -z "$(df | grep proc)" ]; then mount -t procfs proc "/proc"; fi
echo "done"

if [ "$KEEP_DATA" == "false" ]; then
    ${EASTPECT_ROOT}/scripts/installers/elasticsearch/delete_all.py
fi

ES_DATA_PATH_DEFAULT="/var/db/elasticsearch"
DATA_FOLDER="/usr/local/datastore"
ES_DATA_PATH="/usr/local/datastore/elasticsearch"
ES_CONF_FILE="/usr/local/etc/elasticsearch/elasticsearch.yml"

echo "Stoped deamon..." >> ${PKG_PROGRESS_FILE}
stop_elasticsearch

echo "Auto removing..." >> ${PKG_PROGRESS_FILE}
pkg info elasticsearch5>/dev/null 2>&1
RET=$?
if [ $RET -eq 0 ]; then
    pkg remove -fy elasticsearch5 >> ${PKG_PROGRESS_FILE} 2>&1
fi    
echo "Auto removing....." >> ${PKG_PROGRESS_FILE}
pkg info elasticsearch7>/dev/null 2>&1
RET=$?
if [ $RET -eq 0 ]; then
    pkg remove -fy elasticsearch7 >> ${PKG_PROGRESS_FILE} 2>&1
fi    
pkg autoremove -y >> ${PKG_PROGRESS_FILE} 2>&1

if [ "$KEEP_DATA" == "false" ]; then
	echo "Removing elasticsearch data">> ${PKG_PROGRESS_FILE}
    if [ ! -z $ES_DATA_PATH ];then 
        rm -rf $ES_DATA_PATH/*
    fi    
    if [ ! -z $ES_DATA_PATH_DEFAULT ];then
        rm -rf $ES_DATA_PATH_DEFAULT/* 
    fi
fi

echo "Cleaning packages..." >> ${PKG_PROGRESS_FILE}
pkg clean -ay >> ${PKG_PROGRESS_FILE} 2>&1

echo "Update repository..." >> ${PKG_PROGRESS_FILE}
pkg update -f >> ${PKG_PROGRESS_FILE} 2>&1
echo "Installing database..." >> ${PKG_PROGRESS_FILE}

CN=$(find /usr/local/datastore/elasticksearch -type f|grep -v ".." |wc -l)
if [ $CN -gt 0 ]; then
    pkg install -fy elasticsearch5 >> ${PKG_PROGRESS_FILE} 2>&1
else
    pkg install -fy elasticsearch5 >> ${PKG_PROGRESS_FILE} 2>&1   
fi    

if [ ! -f /usr/local/etc/elasticsearch/jvm.options ]; then 
    if [ -f /usr/local/etc/elasticsearch/jvm.options.sample ]; then 
        cp -p /usr/local/etc/elasticsearch/jvm.options.sample /usr/local/etc/elasticsearch/jvm.options
    fi    
fi

if [ ! -f /usr/local/etc/elasticsearch/elasticsearch.yml ]; then 
    if [ -f /usr/local/etc/elasticsearch/elasticsearch.yml.sample ]; then 
        cp -p /usr/local/etc/elasticsearch/elasticsearch.yml.sample /usr/local/etc/elasticsearch/elasticsearch.yml
    fi   
else
   LN=$(cat /usr/local/etc/elasticsearch/elasticsearch.yml|wc -l)      
   if [ $LN -lt 10 ]; then
        if [ -f /usr/local/etc/elasticsearch/elasticsearch.yml.sample ]; then 
            cp -p /usr/local/etc/elasticsearch/elasticsearch.yml.sample /usr/local/etc/elasticsearch/elasticsearch.yml
        fi   
   fi
fi

if [ ! -f /usr/local/etc/elasticsearch/log4j2.properties ]; then 
    if [ -f /usr/local/etc/elasticsearch/log4j2.properties.sample ]; then 
        cp -p /usr/local/etc/elasticsearch/log4j2.properties.sample /usr/local/etc/elasticsearch/log4j2.properties
    fi    
fi

echo -n "Setting up elasticsearch..." >> ${PKG_PROGRESS_FILE}
mkdir -p /usr/local/lib/elasticsearch/plugins
chmod -R 755 /usr/local/lib/elasticsearch/plugins
sysrc elasticsearch_login_class="root" >/dev/null 2>&1
sed -i '' -E '/auto_create_index/d' $ES_CONF_FILE
echo "action.auto_create_index: false" >> $ES_CONF_FILE
/usr/bin/sed -i '' 's/opt\/eastpect\/run\/elasticsearch/var\/run\/elasticsearch/g' /usr/local/etc/rc.d/elasticsearch

change_elasticsearch_jvmoptions() {
    jvm_Xms=$(cat $JVM_FILE | grep  "^\-Xms")
    jvm_Xmx=$(cat $JVM_FILE | grep  "^\-Xmx")
    total_mem=$(sysctl hw.physmem|awk '{ print $2 }')
    echo "Check jvm.options of elasticksearch..." >> ${PKG_PROGRESS_FILE}
    if [ $total_mem -gt 8000000000 ];then
        echo "Memory greater then 8g, jvm will be set 2gb" >> ${PKG_PROGRESS_FILE}
        /usr/bin/sed -i '' "s/$jvm_Xms/-Xms2g/g" $JVM_FILE
        /usr/bin/sed -i '' "s/$jvm_Xmx/-Xmx2g/g" $JVM_FILE
    else
         echo "Memory less then 8g, jvm will be set 512m" >> ${PKG_PROGRESS_FILE}
        /usr/bin/sed -i '' "s/$jvm_Xms/-Xms512m/g" $JVM_FILE
        /usr/bin/sed -i '' "s/$jvm_Xmx/-Xmx512m/g" $JVM_FILE
    fi

}

if [ -f /usr/local/etc/elasticsearch/jvm.options]; then
    JVM_FILE="/usr/local/etc/elasticsearch/jvm.options"
    change_elasticsearch_jvmoptions
fi


echo 'elasticsearch_enable="YES"' > /etc/rc.conf.d/elasticsearch
echo 'elasticsearch_env="JAVA_HOME=/usr/local/openjdk8"' >> /etc/rc.conf.d/elasticsearch
echo "Starting elasticsearch service..." >> ${PKG_PROGRESS_FILE}

if [ ! -d $ES_DATA_PATH ]; then
    mkdir -p $ES_DATA_PATH
fi    
chmod 755 $DATA_FOLDER
chown -R elasticsearch:elasticsearch $ES_DATA_PATH
php -r "file_put_contents('$ES_CONF_FILE',str_replace('$ES_DATA_PATH_DEFAULT','$ES_DATA_PATH',file_get_contents('$ES_CONF_FILE')));"

chmod 755 /usr/local/lib/elasticsearch
service elasticsearch onestart

sleep 3
ES_STARTED=0
NUM_ES_TRIES=0

for i in $(seq 1 30); do
    if [ -n "$(curl --silent --max-time 1 -XGET localhost:9200 | grep 'You Know, for Search')" ]; then
        NUM_ES_TRIES=$(expr $NUM_ES_TRIES + 1)
        if [ $NUM_ES_TRIES -ge 3 ]; then
            echo "Elasticsearch service has been started." >> ${PKG_PROGRESS_FILE}
            ES_STARTED=1
            break
        fi
    fi
    printf '['
    for j in $(seq 1 $i); do printf '='; done
    for k in $(seq $(($i+1)) 30); do printf '.'; done
    printf ']\r'
    sleep 1
done

EASTPECT_STATUS=$(service eastpect status|grep -c "is running")
if [ $EASTPECT_STATUS -gt 0 ]; then 
    echo -n "Stoping engine..."
    service eastpect onestop
    echo "done"
fi 

if [ $ES_STARTED -eq 1 ]; then
    echo "Create Indexes..." >> ${PKG_PROGRESS_FILE}
    if [ -f ${EASTPECT_ROOT}/scripts/installers/elasticsearch/create_indices.py ]; then
        if [ ! -x ${EASTPECT_ROOT}/scripts/installers/elasticsearch/create_indices.py ]; then
            chmod +x ${EASTPECT_ROOT}/scripts/installers/elasticsearch/create_indices.py
        fi
        ${EASTPECT_ROOT}/scripts/installers/elasticsearch/create_indices.py >> ${PKG_PROGRESS_FILE}
        EXIT_CODE=$?
        if [ $EXIT_CODE -eq 0 ]; then
            echo "***SUCCESSFUL***"
            echo "***SUCCESSFUL***" >> ${PKG_PROGRESS_FILE}
        else
            echo "***ERROR*** CODE:$EXIT_CODE***"
            echo "***ERROR*** CODE:$EXIT_CODE***" >> ${PKG_PROGRESS_FILE}
            exit 0
        fi
    else
        echo "***ERROR***: Missing File: Elasticsearch indices create script!"
        echo "***ERROR***: Missing File: Elasticsearch indices create script!" >> ${PKG_PROGRESS_FILE}
        exit 0
    fi
else
    echo "***ERROR***: Elasticsearch service could not be started in 60 seconds!***"
    echo "***ERROR***: Elasticsearch service could not be started in 60 seconds!***" >> ${PKG_PROGRESS_FILE}
    echo "***ERROR*** CODE:2***"
    echo "***ERROR*** CODE:2***" >> ${PKG_PROGRESS_FILE}
    exit 0
fi

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
