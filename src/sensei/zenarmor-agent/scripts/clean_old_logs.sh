#!/bin/sh

if [ -d "/usr/local/zenarmor" ]; then
	ZENARMOR_ROOT_DIR="/usr/local/zenarmor"
else
	ZENARMOR_ROOT_DIR="/usr/local/sensei"
fi

find ${ZENARMOR_ROOT_DIR}/log/ -mtime +3 -exec rm {} \;

if [ "$?" -ne "0" ]; then
	exit 1
fi
exit 0
