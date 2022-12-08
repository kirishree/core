#!/bin/sh

if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi

DISTRO_OVERRIDE="opnsense/18.1"

. $EASTPECT_ROOT/scripts/health/$DISTRO_OVERRIDE/functions_eastpect.sh

while getopts ":d:f:" opt; do
    case "$opt" in
        d) REMOVE_DATA=$OPTARG ;;
        f) REMOVE_FOLDER=$OPTARG ;;
    esac
done

: > /tmp/sensei_uninstall.log

# if [ "$REMOVE_DATA" == "true" ]; then
#    ${EASTPECT_ROOT}/scripts/installers/elasticsearch/delete_all.py
#    ${EASTPECT_ROOT}/scripts/installers/mongodb/delete_all.py
# fi
echo "delete sensei nodes in config.xml"
/usr/local/opnsense/mvc/app/models/OPNsense/Sensei/CLI.php deletesettings

stop_elasticsearch
service mongod onestop

echo "Uninstalling database..."
pkg remove -fy elasticsearch5
pkg remove -fy mongodb40

pkg autoremove -y
pkg clean -ay

if [ "$REMOVE_DATA" == "true" ]; then
    echo "remove data";
    ${EASTPECT_ROOT}/scripts/installers/elasticsearch/delete_data_folder.sh
    ${EASTPECT_ROOT}/scripts/installers/mongodb/delete_data_folder.sh
fi

echo "Uninstalling Sensei..."
pkg info os-sensei-agent >/dev/null 2>&1
RET=$?
if [ $RET -eq 0 ];then 
    pkg remove -fy os-sensei-agent
fi
pkg remove -fy os-sensei
pkg remove -fy os-sensei-db
pkg remove -fy os-sensei-updater
pkg remove -fy os-sunnyvalley


if [ "$REMOVE_FOLDER" == "true" ]; then
    echo "remove folder";
    umount /dev/md43     
    rm -rf /usr/local/sensei
    rm -rf /usr/local/sensei-db
    rm -rf /usr/local/datastore
fi
echo "Dependencies are removed..."
pkg autoremove -y
pkg clean -ay
echo "Update Repo..."
pkg update -f
