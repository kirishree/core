#!/bin/sh

SERVICE=$1
ENABLE=$2

if [ -z ${SERVICE} ]; then
	echo "service not found"
	exit 1
fi

if [ -z ${ENABLE} ]; then
	echo "action not found"
	exit 1
fi

if [ ${ENABLE} == "enable" ]; then
	echo "${SERVICE}_enable=\"YES\"" > /etc/rc.conf.d/${SERVICE}
else
	echo "${SERVICE}_enable=\"NO\"" > /etc/rc.conf.d/${SERVICE}
fi

if [ -f /usr/local/sbin/opnsense-version ]; then
	echo "Special OPNsense handling"
fi

exit 0
