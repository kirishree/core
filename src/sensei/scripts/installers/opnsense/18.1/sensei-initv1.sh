#!/bin/sh
if [ $1 != 'ES']; then
  exit 0
fi
if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi

DISTRO_OVERRIDE="opnsense/18.1"

. $EASTPECT_ROOT/scripts/health/$DISTRO_OVERRIDE/functions_eastpect.sh

echo -n "Preparing java settings..."
if [ -z "$(grep 'fdesc' /etc/fstab)" ]; then echo "fdesc /dev/fd fdescfs rw 0 0" >> "/etc/fstab"; fi
if [ -z "$(grep 'proc' /etc/fstab)" ]; then echo "proc /proc procfs rw 0 0" >> "/etc/fstab"; fi
if [ -z "$(df | grep fdesc)" ]; then mount -t fdescfs fdesc "/dev/fd"; fi
if [ -z "$(df | grep proc)" ]; then mount -t procfs proc "/proc"; fi
echo "done"

if [ -z "$(pkg info elasticsearch5 2>/dev/null)" ]; then
    echo "Elasticsearch service has not been installed yet. Installing..."
    pkg -fy install elasticsearch5
else
    stop_elasticsearch
fi

sleep 3
echo -n "Setting up elasticsearch..."
mkdir -p /usr/local/lib/elasticsearch/plugins
chmod -R 755 /usr/local/lib/elasticsearch/plugins
sysrc elasticsearch_login_class="root" >/dev/null 2>&1
sed -i '' -E '/auto_create_index/d' /usr/local/etc/elasticsearch/elasticsearch.yml
echo "action.auto_create_index: false" >> /usr/local/etc/elasticsearch/elasticsearch.yml
/usr/bin/sed -i '' 's/opt\/eastpect\/run\/elasticsearch/var\/run\/elasticsearch/g' /usr/local/etc/rc.d/elasticsearch

change_elasticsearch_jvmoptions() {
    jvm_Xms=$(cat $JVM_FILE | grep  "^\-Xms")
    jvm_Xmx=$(cat $JVM_FILE | grep  "^\-Xmx")
    total_mem=$(sysctl hw.physmem|awk '{ print $2 }')
    echo "Check jvm.options of elasticksearch..."
    if [ $total_mem -gt 8000000000 ];then
        echo "Memory greater then 8g, jvm will be set 2gb"
        /usr/bin/sed -i '' "s/$jvm_Xms/-Xms2g/g" $JVM_FILE
        /usr/bin/sed -i '' "s/$jvm_Xmx/-Xmx2g/g" $JVM_FILE
    else
         echo "Memory less then 8g, jvm will be set 512m"
        /usr/bin/sed -i '' "s/$jvm_Xms/-Xms512m/g" $JVM_FILE
        /usr/bin/sed -i '' "s/$jvm_Xmx/-Xmx512m/g" $JVM_FILE
    fi

}

elastic_config=$(ps axuwww | grep elastic | awk -F 'Epath.conf=' '{ print $2 }' | awk '{ print $1 }')

JVM_FILE="$elastic_config/jvm.options"

if [ -f $JVM_FILE ]; then
    change_elasticsearch_jvmoptions

else

    if [ -f /usr/local/lib/elasticsearch/config/jvm.options ]; then
        JVM_FILE="/usr/local/lib/elasticsearch/config/jvm.options"
        change_elasticsearch_jvmoptions
    fi
    if [ -f /usr/local/etc/elasticsearch/jvm.options]; then
        JVM_FILE="/usr/local/etc/elasticsearch/jvm.options"
        change_elasticsearch_jvmoptions
    fi
fi


echo 'elasticsearch_enable="YES"' > /etc/rc.conf.d/elasticsearch
echo 'elasticsearch_env="JAVA_HOME=/usr/local/openjdk8"' >> /etc/rc.conf.d/elasticsearch
echo "done"

service elasticsearch onestart
echo "Waiting for elasticsearch to start..."
sleep 3
ES_STARTED=0
NUM_ES_TRIES=0

for i in $(seq 1 30); do
    if [ -n "$(curl --silent --max-time 1 -XGET localhost:9200 | grep 'You Know, for Search')" ]; then
        NUM_ES_TRIES=$(expr $NUM_ES_TRIES + 1)
        if [ $NUM_ES_TRIES -ge 3 ]; then
            echo "Elasticsearch service has been started."
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

if [ $ES_STARTED -eq 1 ]; then
    if [ -f ${EASTPECT_ROOT}/scripts/installers/elasticsearch/create_indices.py ]; then
        if [ ! -x ${EASTPECT_ROOT}/scripts/installers/elasticsearch/create_indices.py ]; then
            chmod +x ${EASTPECT_ROOT}/scripts/installers/elasticsearch/create_indices.py
        fi
        ${EASTPECT_ROOT}/scripts/installers/elasticsearch/create_indices.py
        EXIT_CODE=$?
        if [ $EXIT_CODE -eq 0 ]; then
            echo "***SUCCESSFUL***"
        else
            echo "***ERROR CODE:$EXIT_CODE***"
        fi
    else
        echo "***ERROR: Missing File: Elasticsearch indices create script!"
    fi
else
    echo "***ERROR: Elasticsearch service could not be started in 60 seconds!***"
    echo "***ERROR CODE:2***"
fi
