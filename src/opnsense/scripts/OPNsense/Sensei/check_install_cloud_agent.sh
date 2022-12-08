#!/bin/sh
PKG_PROGRESS_FILE=/tmp/sensei_check_update.progress
AGENT_PKG=os-sensei-agent
# Truncate upgrade progress file
: > ${PKG_PROGRESS_FILE}

echo "***GOT REQUEST TO CHECK INSTALL: CLOUD AGENT***" >> ${PKG_PROGRESS_FILE}
dt=$(date)
echo "Starting Date: $dt" >> ${PKG_PROGRESS_FILE}

PKG_COUNT=$(pgrep pkg | wc -l)
if [ $PKG_COUNT -gt 0 ]; then
    echo "RETURN CODE::102::">> ${PKG_PROGRESS_FILE}
    exit 0
fi 

AGENT_STATUS="$(pkg install -n $AGENT_PKG 2> /dev/null | grep "$AGENT_PKG:" | cut -d ">" -f2 | cut -d "[" -f1 | tr -d '[:space:]')"

if [ -n "$AGENT_STATUS" ]; then
    echo "New version will be install..." >> ${PKG_PROGRESS_FILE} 2>&1
    echo "Auto remove process..." >> ${PKG_PROGRESS_FILE} 2>&1
    pkg autoremove -y >> ${PKG_PROGRESS_FILE} 2>&1
    echo "Cleaning packages..." >> ${PKG_PROGRESS_FILE}
    pkg clean -ay >> ${PKG_PROGRESS_FILE} 2>&1
    echo "Updateing new version of sensei agent..."
    pkg install -fy os-sensei-agent >> ${PKG_PROGRESS_FILE} 2>&1    
    RET=$?
    if [ $RET -ne 0 ];then 
        echo "Return Code is $RET" >> ${PKG_PROGRESS_FILE}
        echo '***ERROR***' >> ${PKG_PROGRESS_FILE}
    else 
        echo '***DONE***' >> ${PKG_PROGRESS_FILE}    
    fi
else
    echo "There isn't new version" >> ${PKG_PROGRESS_FILE}    
fi
