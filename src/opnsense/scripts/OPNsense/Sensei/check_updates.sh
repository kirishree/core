#!/bin/sh

if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi

ENGINE_PKG="os-sensei"
DATABASE_PKG="os-sensei-db"
CACHE_FILE="/tmp/zenarmor_updates.json"
CACHE_DB_FILE="/tmp/sensei_db_updates.date"
EASTPECT_CFG="$EASTPECT_ROOT/etc/eastpect.cfg"
CURRENT_VERSION="$($EASTPECT_ROOT/bin/eastpect -V | grep -i "release" | cut -d" " -f2)"
CURRENT_DB_VERSION="$(cat $EASTPECT_ROOT/db/VERSION)"
DB_VERSION_CONTROL="/usr/local/opnsense/scripts/OPNsense/Sensei/sensei-db-version.py"
DB_VERSION="/usr/local/opnsense/scripts/OPNsense/Sensei/sensei-db-version.sh"
# LICENSE="Freemium"
CONFIG_CTL="/usr/local/sbin/configctl"

# if [ -f $EASTPECT_ROOT/etc/license.data -a -n "$($EASTPECT_ROOT/bin/eastpect -x $EASTPECT_ROOT/etc/license.data | grep "License OK")" ]; then LICENSE="Premium"; fi

# curl "https://health.sunnyvalley.io/heartbeat.php?engine_version=${CURRENT_VERSION}&database_version=${CURRENT_DB_VERSION}&license=${LICENSE}" > /dev/null 2>&1 &

rm -f $CACHE_FILE

ENGINE_AVAILABLE="false"
ENGINE_VERSION="null"
DATABASE_AVAILABLE="false"
DATABASE_VERSION="null"

CN=1
COUNTER=0
while [ $CN -ne 0 ] && [ $COUNTER -lt 20 ] 
do
    CN=$(ps auxw | grep -v grep | grep -c pkg)
    sleep 1
    COUNTER=$((COUNTER+1))
done

pkg update -f

CN=1
COUNTER=0
while [ $CN -ne 0 ] && [ $COUNTER -lt 20 ] 
do
    CN=$(ps auxw | grep -v grep | grep -c pkg)
    sleep 1
    COUNTER=$((COUNTER+1))
done

ENGINE_STATUS="$(pkg install -n $ENGINE_PKG 2> /dev/null | grep "$ENGINE_PKG:" | cut -d ">" -f2 | cut -d "[" -f1 | tr -d '[:space:]')"

if [ -n "$ENGINE_STATUS" ]; then
    ENGINE_AVAILABLE="true"
    ENGINE_VERSION="\"$ENGINE_STATUS\""
fi

# DATABASE_STATUS="$(pkg install -n $DATABASE_PKG 2> /dev/null | grep "$DATABASE_PKG:" | cut -d ">" -f2 | cut -d "[" -f1 | tr -d '[:space:]')"
LINE_NO=$(grep -n "\[Updater\]" $EASTPECT_CFG|cut -d':' -f1)

DB_ENABLED=$(tail +$LINE_NO $EASTPECT_CFG| head -2|grep -c "signatureUpdatesEnabled = true")
# DB_AUTOCHECK=$(grep -c "dbautocheck = true" $EASTPECT_CFG)

    OUTPUT=$($DB_VERSION_CONTROL)
    RET=$?
    if [ $RET -eq 1 ]; then
        DATABASE_VERSION=$(echo -n $OUTPUT|cut -d ':' -f1)
        if [ ! -z "$1" -a "$1" == "cron" ]; then
            if [ $DB_ENABLED -gt 0 ]; then 
                $CONFIG_CTL sensei update-install os-sensei-db $DATABASE_VERSION
                DATABASE_AVAILABLE="false"
                date > $CACHE_DB_FILE
            else
               DATABASE_AVAILABLE="true"     
            fi
        else
            DATABASE_AVAILABLE="true"
        fi
    else
        DATABASE_VERSION=$($DB_VERSION)
    fi

if [ -f /usr/local/opnsense/scripts/OPNsense/Sensei/.first_check ]; then    
    ENGINE_AVAILABLE="true"
fi

echo "{
    \"engine\": {
        \"package\": \"$ENGINE_PKG\",
        \"available\": $ENGINE_AVAILABLE,
        \"version\": $ENGINE_VERSION
    },
    \"database\": {
        \"package\": \"$DATABASE_PKG\",
        \"available\": $DATABASE_AVAILABLE,
        \"version\": \"$DATABASE_VERSION\"
    }
}" > $CACHE_FILE

exit 0
