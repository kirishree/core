#!/bin/sh
PATH=$PATH:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin
if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi
USER_ENRICHER_DB="$EASTPECT_ROOT/userdefined/db/Usercache/userauth_cache.db"

if [ -f $USER_ENRICHER_DB ]; then
    DT=$(date +%s)
    DT=$((DT - 2592000))
    ct=$(echo -n "delete from users_cache where created<$DT and deleted>0;" | sqlite3 $USER_ENRICHER_DB)
    ct=$(echo -n "delete from users_cache where created<$DT;" | sqlite3 $USER_ENRICHER_DB)
fi