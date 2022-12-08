#!/bin/sh

if [ -z $EASTPECT_ROOT ];then
        EASTPECT_ROOT="/usr/local/sensei/"
fi

DISTRO_OVERRIDE="opnsense/18.1"
. $EASTPECT_ROOT/scripts/health/$DISTRO_OVERRIDE/functions_eastpect.sh

dir="$EASTPECT_ROOT/bin/"
cmd="eastpect"
user=""

pid_file="$EASTPECT_ROOT/run/eastpect.pid"

get_pid() {
    cat "$pid_file"
}

start() {
	service eastpect onestart
	RET=$?
	# worker_fname=$(ls -lrth /usr/local/sensei/log/active/worker* | tail -1f | awk '{ print $9 }')
	# sleep 2
	# ct=$(tail -100 $worker_fname | grep -cE "CRITICAL:|FATAL:|ERROR:")
	# if [ $ct -gt 0 ]; then
	#    output=$(tail -100 $worker_fname | grep -E "CRITICAL:|FATAL:|ERROR:"|tail -3)
	#    echo "ERR:$output"
	# fi
	return $RET
}

stop() {
	is_eastpect_running
	if [ $EASTPECT_RUNNING -eq 1 ]; then
		service eastpect onestop
		OUT=$?
		if [ $OUT -ne 0 ]; then
			kill_eastpect_byprocess
			rm -f $pid_file
		fi
	else
		echo "eastpect is not running"
	fi
}

restart() {
	stop
	sleep 1
	start
}

status() {
	is_eastpect_running
	if [ $EASTPECT_RUNNING -eq 0 ]; then
		echo "eastpect is not running"
	else
	    is_eastpect_bypass
		echo "eastpect is running bypass=$EASTPECT_BYPASS"
	fi

}

case "$1" in
    start)
        start
        ;;
    stop)
        stop
        ;;
    status)
        status
        ;;
    restart)
        restart
        ;;
    *)
        echo "Usage: $0 {start|stop|status|restart}"
        exit 1
        ;;
esac
exit $?
