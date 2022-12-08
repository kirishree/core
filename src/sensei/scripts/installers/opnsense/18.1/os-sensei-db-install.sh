#!/bin/sh
echo "Install Zenarmor Application Database Package"
PKG_FILE="/tmp/os-sensei-db-last-version.txz"
PKG_RUNNING=0
while [ $PKG_RUNNING -ne 1 ]; do
    sleep 2
    PKG_RUNNING=$(ps aux | grep -c pkg)
done
pkg install -fy "$PKG_FILE"
RET=$?
if [ $RET -ne 0 ]; then
    echo "Error package could not install $RET"
fi
