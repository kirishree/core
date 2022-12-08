#!/bin/sh

if [ -f "/tmp/sensei_update.progress" ]; then
    rm -rf "/tmp/sensei_update.progress"
fi

PKG_PROGRESS_FILE=/tmp/zenarmor_update.progress
# Truncate upgrade progress file
: > ${PKG_PROGRESS_FILE}

echo "***GOT REQUEST TO INSTALL: CLOUD AGENT***" >> ${PKG_PROGRESS_FILE}
dt=$(date)
echo "Starting Date: $dt" >> ${PKG_PROGRESS_FILE}
echo "Auto removing..." >> ${PKG_PROGRESS_FILE}
pkg remove -fy os-sensei-agent >> ${PKG_PROGRESS_FILE} 2>&1
pkg autoremove -y >> ${PKG_PROGRESS_FILE} 2>&1

echo "Cleaning packages..." >> ${PKG_PROGRESS_FILE}
pkg clean -ay >> ${PKG_PROGRESS_FILE} 2>&1

echo "Update repository..." >> ${PKG_PROGRESS_FILE}
pkg update -f >> ${PKG_PROGRESS_FILE} 2>&1
echo "Installing Agent..." >> ${PKG_PROGRESS_FILE}
pkg install -fy os-sensei-agent >> ${PKG_PROGRESS_FILE} 2>&1
RET=$?
if [ $RET -ne 0 ];then 
    echo "Return Code is $RET" >> ${PKG_PROGRESS_FILE}
    echo '***ERROR***' >> ${PKG_PROGRESS_FILE}
else 
    echo '***DONE***' >> ${PKG_PROGRESS_FILE}    
fi
