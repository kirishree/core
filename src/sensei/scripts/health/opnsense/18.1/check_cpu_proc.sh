#!/bin/sh
PATH=$PATH:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin

CRITICAL=0
WARNING=0
EXITCODE=0
MEMWARN=0
MEMCRIT=0

Usage() {
        echo "
        This script looks at a command and its processes and calculates its CPU and memory usage

        OPTIONS:
        -p - The process name to look for
        -w - The warning to use for the CPU percentage used
        -c - The critical to use for the CPU percentage used
        -m - The warning to use for the Memory percentage used
        -n - The critical to use for the Memory percentage used

        EXAMPLES:
                Check the usage for apache processes and alert warning if over 80% CPU utilised and critical if 90%
                ./check_cpu_proc.sh -p apache2 -w 80 -c 90

                Check the usage for nagios processes and alert warning if over 20% memory Utilised and critical if 30%
                ./check_cpu_proc.sh -p nagios -m 20 -n 30
"
        exit 3
}

while getopts "p:w:c:m:n:" OPTION
do
	case "$OPTION" in
		p)
			PROC=$OPTARG
		  ;;

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

if [ -z "$PROC" ]; then
	echo "Must specify a process name"
	Usage
fi

IFS="
"

output=`ps auxwww|grep $PROC | grep -v grep`

OVERALCPU="0.0"
OVERALMEM="0.0"
OVERALRSS="0.0"
OVERALVSZ="0.0"
count="0"
for LINE in $output; do
	CPU=$(echo $LINE | awk '{ print $3 }')
	COMMAND=$(echo $LINE | awk '{ print $11 }')
	MEM=$(echo $LINE | awk '{ print $4 }')
	RSS=$(echo $LINE | awk '{ print $6 }')
	VSZ=$(echo $LINE | awk '{ print $5 }')

	case "$COMMAND" in
		*"$PROC"*)	
			OVERALCPU=`echo ${OVERALCPU} + ${CPU} | bc`
			OVERALMEM=`echo ${OVERALMEM} + ${MEM} | bc`
			OVERALRSS=`echo ${OVERALRSS} + ${RSS} | bc`
			OVERALVSZ=`echo ${OVERALVSZ} + ${VSZ} | bc`
			ACTCOMMAND=$COMMAND
			count=`echo $count '+' 1 | bc`
		;;
	esac
done

if [ "$WARNING" -ne "0" ] || [ "$CRITICAL" -ne "0" ]; then
	if [ "$WARNING" -eq "0" ] || [ "$CRITICAL" -eq "0" ]; then
		echo "CPU Must Specify both warning and critical"
		Usage
	fi

	if [ `echo $OVERALCPU'>'$WARNING | bc -l` -eq "1" ]; then
		EXITCODE=1

		if [ `echo $OVERALCPU'>'$CRITICAL | bc -l` -eq "1" ]; then
			EXITCODE=2
		fi
	fi
fi

if [ "$MEMWARN" -ne "0" ] || [ "$MEMCRIT" -ne "0" ]; then
	if [ "$MEMWARN" -eq "0" ] || [ "$MEMCRIT" -eq "0" ]; then
		echo "Mem Must Specify both warning and critical"
		Usage
	fi

	if [ `echo $OVERALMEM'>'$MEMWARN | bc -l` -eq "1" ]; then
		EXITCODE=1

		if [ `echo $OVERALMEM'>'$MEMCRIT | bc -l` -eq "1" ]; then
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

echo "${EXITTEXT} ${ACTCOMMAND} CPU: ${OVERALCPU}% MEM: ${OVERALMEM}% over ${count} processes | proc=${count} mem=${OVERALMEM}% cpu=${OVERALCPU}% rss=${OVERALRSS}KB vsz=${OVERALVSZ}KB"

exit $EXITCODE
