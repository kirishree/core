PATH=$PATH:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin

if [ -z $EASTPECT_ROOT ];then
        EASTPECT_ROOT="/usr/local/sensei/"
fi

CRITICAL=80
WARNING=60
EXITCODE=0
MEMWARN=75
MEMCRIT=85

Usage() {
    echo "
    This script looks system CPU and memory usage

    OPTIONS:
    -w - The warning to use for the CPU percentage used
    -c - The critical to use for the CPU percentage used
    -m - The warning to use for the Memory percentage used
    -n - The critical to use for the Memory percentage used

    EXAMPLES:
        Check the cpu usage: warning if over 80% CPU utilised and critical if 90%
        ./check_cpu_mem.sh -w 80 -c 90

        Check the memory usage: warning if over 20% memory Utilised and critical if 30%
        ./check_cpu_mem.sh -m 20 -n 30
"
    exit 3
}

while getopts "w:c:m:n:" OPTION
do
    case $OPTION in
        w)
                WARNING=$OPTARG
          ;;

        c)
                CRITICAL=$OPTARG
          ;;

        m)
                MEMWARN=$OPTARG
          ;;

        n)
                MEMCRIT=$OPTARG
          ;;
    esac
done;

IDLE_LOC=1
for loc in $(iostat | grep id); do
        if [ $loc = "id" ]; then
                break
        fi
        IDLE_LOC=$(echo "$IDLE_LOC + 1" | bc)
done

ELASTIC_PID=$(ps aux | grep elasticsearch | grep -v grep | awk '{print $2}')
ES_ELAPSED_TIME=0
if [ ! -z "$ELASTIC_PID" ];then
    ES_ELAPSED_TIME=$(ps -o etimes= -p $ELASTIC_PID)
fi


OVERALLCPU=$(iostat 1 4 | tail -n 1 | awk -v id_loc="$IDLE_LOC" '{print 100-$id_loc}')

PERCENTMEM=$(sh "${EASTPECT_ROOT}/scripts/health/opnsense/18.1/freebsd-memory.sh" | cut -d' ' -f3)

# CPU
if [ $WARNING -ne "0" ] || [ $CRITICAL -ne "0" ]; then
	if [ $WARNING -eq "0" ] || [ $CRITICAL -eq "0" ]; then
		echo "Must Specify both warning and critical"
		Usage
	fi

	if [ $ES_ELAPSED_TIME -gt 300 -a `echo $OVERALLCPU'>'$WARNING | bc -l` -eq "1" ]; then
		EXITCODE=1

		if [ `echo $OVERALLCPU'>'$CRITICAL | bc -l` -eq "1" ]; then
				EXITCODE=2
		fi
	fi
fi

if [ $MEMWARN -ne "0" ] || [ $MEMCRIT -ne "0" ]; then
	if [ $MEMWARN -eq "0" ] || [ $MEMCRIT -eq "0" ]; then
		echo "Must Specify both warning and critical"
		Usage
	fi

	if [ $EXITCODE -lt "2" -a `echo $PERCENTMEM'>'$MEMWARN | bc -l` -eq "1" ]; then
		EXITCODE=1

		if [ `echo $PERCENTMEM'>'$MEMCRIT | bc -l` -eq "1" ]; then
				EXITCODE=2
		fi
	fi
fi

EXITTEXT="OK"

case "$EXITCODE" in
	1)
			EXITTEXT="WARNING"
	;;

	2)
			EXITTEXT="CRITICAL"
	;;

	3)
			EXITTEXT="UNKNOWN"
	;;
esac

# OVERALLCPU=$(echo "100 - $OVERALLCPU"|bc)
echo "${EXITTEXT} ${ACTCOMMAND} CPU: ${OVERALLCPU}% MEM: ${PERCENTMEM}%"

exit $EXITCODE
