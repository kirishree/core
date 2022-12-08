#!/bin/sh

if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi

CURRENT_VERSION="$($EASTPECT_ROOT/bin/eastpect -V | grep -i "release" | cut -d" " -f2 | sed "s/beta//g")"

MIGRATE_SCRIPT=$EASTPECT_ROOT/scripts/updater/opnsense/18.1/migrate_$CURRENT_VERSION.py

if [ -f $MIGRATE_SCRIPT ];then
    echo "Migration is running ....."
    $MIGRATE_SCRIPT
fi

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

# if [ -f $JVM_FILE ]; then
#  change_elasticsearch_jvmoptions
# else
#    if [ -f /usr/local/lib/elasticsearch/config/jvm.options ]; then
#        JVM_FILE="/usr/local/lib/elasticsearch/config/jvm.options"
#        change_elasticsearch_jvmoptions
#    fi
#    if [ -f /usr/local/etc/elasticsearch/jvm.options]; then
#        JVM_FILE="/usr/local/etc/elasticsearch/jvm.options"
#        change_elasticsearch_jvmoptions
#    fi
# fi

# check port number 5353 instead 53
echo "Check Nodes Port..."
if [ -f /usr/local/sensei/db/Cloud/nodes.csv ];then
   port_53=$(grep -c ',53$' /usr/local/sensei/db/Cloud/nodes.csv)
   if [ $port_53 -gt 0 ];then
       /usr/local/opnsense/scripts/OPNsense/Sensei/nodes_status.py>/dev/null
       number_available=$(cat /tmp/sensei_nodes_status.json|grep -c '"available": true,')
       if [ $number_available -eq 0 ];then
          echo "I was not able to detect any available Cloud nodes"
          echo "After update is completed, please navigate to Zenarmor -> Cloud Threat Intel and reconfigure Cloud nodes"
       fi
   fi
fi
exit 0
