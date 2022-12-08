#!/bin/sh
PATH=$PATH:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin

if [ -z $EASTPECT_ROOT ];then
        EASTPECT_ROOT="/usr/local/sensei/"
fi

RUN_DIR="${EASTPECT_ROOT}/run"
PID_FILE=$RUN_DIR/eastpect.pid
BIN_PATH="${EASTPECT_ROOT}/bin/"
PFSENSE_WEBUI_DIR="/usr/local/www/"
EASTPECT_WEBUI_DIR="${PFSENSE_WEBUI_DIR}eastpect"
WATCHDOG_FILE=$BIN_PATH/watchdog
BIN_FILE=$BIN_PATH/eastpect
SCRIPTS_DIR="${EASTPECT_ROOT}/scripts/"
DISTRO_OVERRIDE="opnsense/18.1"
CRASH_DIR="${EASTPECT_ROOT}/support/crash_dumps"
STAT_PATH=$(dirname "$0")"/check_statistics.php"
PHP_JVM_PATH=$(dirname "$0")"/check_jvm_options.php"
NRDP_TOKEN=HebeleHubeleASDFGHJKLzxcvbnm
SETTINGS_DB="/usr/local/sensei/userdefined/config/settings.db"
NOTICE_SQL="/usr/local/sensei/scripts/installers/opnsense/18.1/notices_tmpfs.sql"
CPU_SCORE_TMP=/usr/local/sensei/etc/sensei_cpu_score

TOP_LOG=''
EPOCH_NOW=$(date +'%s')
JUST_CREATED=0
TIMEOUT=1800
MAXLINES=30000
export BLOCKSIZE=1024

. "${EASTPECT_ROOT}"/scripts/health/$DISTRO_OVERRIDE/functions_eastpect.sh

CH=$(ps auxwww|grep -v grep|grep -c check_health.sh)
if [ $CH -gt 1 ];then

fi

LOG_FILE="$EASTPECT_ROOT/log/active/Periodical.log"
PID=$$
writelog() {
    MSG=$1
    DT=$(date +"%a, %d %b %y %T %z")
    # [Fri, 31 Jan 20 12:06:12 +0300][INFO] [93119][D:0] Starting Mongodb
    echo "[$DT][CHECK_HEALTH INFO] [$PID] $MSG">>$LOG_FILE
}
writelog "Zenarmor health check staring..."
check_passive_enabled

# check if eastpect is running
writelog "Checking engine status"
if [ -f $PID_FILE ]; then
	is_eastpect_running
	if [ $EASTPECT_RUNNING -eq "1" ]; then
		STATUS=0
		SOUT=""; for ff in $(ls "${EASTPECT_ROOT}"/log/active/worker*_`date +'%Y%m%d*'`* ); do SOUT=$SOUT$(cat $ff | grep Tput | tail -n 1 | sed -e 's/\]//g' | awk '{print $9,$10,$14,$15,"/ "}'); done
		TEXT="$SOUT"
	else
		STATUS=1
		TEXT="CRITICAL Eastpect not running!"
	fi
else
	RET=0
	STATUS=1
	TEXT="Eastpect not running!"
fi

STALLED_STATUS=0
STALLED_TEXT="OK"

writelog "Tmpfs Checking"
#tmpfs check
# dbpath of elastic and mongo under the /usr/local/datastore folder.
# doesnt need to warning.
# TMPVAR=$(mount -t tmpfs | grep -c "/var ")
# if [ $TMPVAR -eq 0 ]; then 
#	TMPVAR=$(mount -t tmpfs | grep -c "/var/db ")
#fi	

#if [ $TMPVAR -gt 0 ]; then 
#	CT=$(echo -n "select count(*) from user_notices where notice_name='var_tmpfs' and status=0;" | sqlite3 $SETTINGS_DB)
#	TM=$(date +%M)
#	if [ $CT -eq 0 -a "$TM" == "00" ]; then 
#	    sqlite3 $SETTINGS_DB<$NOTICE_SQL
#	fi
#fi
writelog "Swap Rate"
# get swap rate
SWAP_RATE=$(grep swapRate /conf/config.xml| cut -d ">" -f 2 | cut -d "<" -f 1)

writelog "Mtu Check"
# mtu check
#TM=$(date +%M)
#if [ "$TM" == "00" ]; then 
		/usr/local/sensei/scripts/health/opnsense/18.1/check_interface.py
#fi		
#-- mtu check

check_stalled() {
	# if passive mode is selected skip this test
	if [ $PASSIVE_MODE_ENABLED -eq "1" ]; then
		return
	fi

	# did we already take bypass action before, or eastpect is not running yet?
	if [ "$STATUS" -ne "0" ]; then
		return
	fi

	WATCHDOG_FILE="${EASTPECT_ROOT}"/log/active/watchdog.log

	OIFS=$IFS

	IFS="
	"

	# uncomment below line to disable check_stalled control
	# note that some drivers (especially virtual machine drivers) may be problematic with this check
	# if you encounter any errors, just disable this function by uncommenting below line
	# return

	INTERFACES=$(cat "${EASTPECT_ROOT}"/etc/workers.map | grep -v "^#" | grep @ | head -10 | cut -d"," -f3 | cut -d"@" -f2 | awk '{$1=$1};1')

	echo "check_stalled() : Interface is $INTERFACE" >> /tmp/send_nrpe.log

	for INTERFACE in $INTERFACES; do
		# inject watchdog packets
		"${EASTPECT_ROOT}"/bin/watchdog "${EASTPECT_ROOT}"/scripts/sunnyvalley_watchdog_icmp_100.pcap $INTERFACE &
		if [ $? -ne "0" ]; then
			echo "check_stalled() : watchdog failed"
			STALLED_STATUS=0
			STALLED_TEXT="watchdog Failed"
			return
		fi


		tail -1 $WATCHDOG_FILE | grep -q "RECEIVED 100 PACKETS"
		if [ $? -eq 0 ]; then # stalled
			truncate -s 0 $WATCHDOG_FILE
		else
			STALLED_STATUS=2
			STALLED_TEXT="Watchdog packets lost"
			break;
		fi

		sleep 2
	done

	IFS=$OIFS
}

DB_NAME="NA"
DB_STATUS=1
DB_TEXT=""
check_mongo() {
	service=mongod

	if [ $(ps -auxwww | grep -v grep | grep $service | wc -l) -gt 0 ]; then
		MONGO_STATUS=0
		MONGO_TEXT="OK"
	else
		MONGO_STATUS=1
		MONGO_TEXT="WARNING: Mongodb is not running!"
	fi
}

check_es() {
	service=elasticsearch

	if [ $(ps -auxwww | grep -v grep | grep $service | wc -l) -gt 0 ]; then
		ES_STATUS=0
		ES_TEXT="OK"
	else
		ES_STATUS=1
		ES_TEXT="WARNING: Elasticsearch is not running!"
	fi

}

check_db() {

	check_mongo
	check_es

	DB_TYPE=$(grep -c "type = ES" /usr/local/sensei/etc/eastpect.cfg)
	if [ $DB_TYPE -ne 0 ];then
		PK_CN=$(pkg info | grep -c elasticsearch | grep -v grep)
		if [ $PK_CN -eq 0 ]; then
			pkg install -y elasticsearch5>/dev/null 2>&1
			RET=$?
			if [ $RET -ne 0 ]; then
				ES_TEXT=" $ES_TEXT elasticsearch install error "
			else
				service elasticsearch onestart
			fi
		fi
	fi

   	if [ $ES_STATUS -eq 0 ];then
		DB_NAME="Elastic"
		DB_STATUS=$ES_STATUS
		DB_TEXT=$ES_TEXT
	else
		if [ $MONGO_STATUS -eq 0 ];then
			DB_NAME="Mongo"
			DB_STATUS=$MONGO_STATUS
			DB_TEXT=$MONGO_TEXT
		fi
	fi
	
	DB_TYPE=$(grep -c "type = SQ" /usr/local/sensei/etc/eastpect.cfg)
	if [ $DB_TYPE -ne 0 ];then
		DB_NAME="sqlite"
		DB_STATUS=""
		DB_TEXT=""
	fi
}

check_swap() {

   SWAP_TEXT="OK"
   SWAP_STATUS=0

  SWAP_TOTAL=$(swapinfo | tail -n 1 | awk '{printf "%d", $2}')
	SWAP_USED=$(swapinfo | tail -n 1 | awk '{printf "%d", $3}')
	SWAP_AVAIL=$(swapinfo | tail -n 1 | awk '{printf "%d", $4}')
	SWAP_PERCENT=0
	if [ "$SWAP_AVAIL" -ne 0 ]; then
		SWAP_PERCENT=$(echo "$SWAP_USED * 100 / $SWAP_TOTAL" | bc)
		if [ $SWAP_PERCENT -gt 2 ]; then
			SWAP_STATUS=1
			if [ $SWAP_PERCENT -gt $SWAP_RATE ]; then
				if [ $STOP_ENGINE_MEM_REASON -eq 1 ]; then
					SWAP_STATUS=2
				fi
			fi
			SWAP_TEXT="$SWAP_PERCENT--$SWAP_USED--$SWAP_TOTAL"
		fi
	fi
}

check_disk() {
	DISK_TEXT="OK"
	DISK_STATUS=0

	DISK_USED_PERCENT=$(df -h | grep -w "/" | awk '{print $5}' | cut -d "%" -f1)
	DISK_SIZE=$(df -h | grep -w "/" | awk '{print $2}' | cut -d "%" -f1)
	if [ $DISK_USED_PERCENT -gt 70 ]; then
		DISK_STATUS=1
		if [ $DISK_USED_PERCENT -gt 90 ]; then
			DISK_STATUS=2
		fi
		DISK_TEXT="$DISK_SIZE -- $DISK_USED_PERCENT"
	fi
}

CPU_SCORE=0
check_cpu_score() {

	if [ ! -f $CPU_SCORE_TMP ]; then
		if [ -f /usr/local/bin/ubench ]; then
			/usr/local/bin/ubench -c -s -t 120| grep "Single CPU" | awk '{print $4}' > $CPU_SCORE_TMP
		fi
		if [ -f $CPU_SCORE_TMP ]; then
	    	RESULT_C=$(cat $CPU_SCORE_TMP)
        	if [ $RESULT_C -eq 0 ]; then 
            	/usr/local/bin/ubench -c -s -t 120| grep "Single CPU" | awk '{print $4}' > $CPU_SCORE_TMP
        	fi
		fi	
	fi
	if [ -f $CPU_SCORE_TMP ]; then
		CPU_SCORE=$(cat $CPU_SCORE_TMP)
	fi
}

check_cpu_mem() {
	# eastpect
	EASTPECT_CPUMEM_TEXT=$(sh $SCRIPTS_DIR/health/$DISTRO_OVERRIDE/check_cpu_proc.sh -p eastpect -w 60 -c 90 -m 60 -n 90 )
	EASTPECT_CPUMEM_STATUS=$?

	if [ "$DB_NAME" = "Elastic" ]; then
		DB_CPUMEM_TEXT=$(sh $SCRIPTS_DIR/health/$DISTRO_OVERRIDE/check_cpu_proc.sh -p java -w 60 -c 90 -m 60 -n 80 )
		DB_CPUMEM_STATUS=$?
	else
		if [ "$DB_NAME" = "Mongo" ]; then
			DB_CPUMEM_TEXT=$(sh $SCRIPTS_DIR/health/$DISTRO_OVERRIDE/check_cpu_proc.sh -p mongod -w 60 -c 90 -m 60 -n 80 )
			DB_CPUMEM_STATUS=$?
		fi
	fi

	SYSTEM_CPUMEM_TEXT=$(sh $SCRIPTS_DIR/health/$DISTRO_OVERRIDE/check_cpu_mem.sh )
	SYSTEM_CPUMEM_STATUS=$?
	OVERALLCPU=$(echo -n "$SYSTEM_CPUMEM_TEXT"|awk -F'CPU: ' '{ print $2 }'|awk -F'%' '{ print $1 }')
	if [ $OVERALLCPU -gt 70 ];then
		TOP_LOG=$(ps -alxwww -m | sort -n -k8 -r | head -10|openssl base64 -A)
	fi
}

STOP_ENGINE_MEM_REASON=0
check_engine_memory_used(){
		ENGINE_MAX_MEM=$(grep maxmemoryusage /conf/config.xml| cut -d ">" -f 2 | cut -d "<" -f 1)	
		for mm in `top -bao res | grep 'eastpect: Eastpect Instance [0-9]' | awk '{ print $7 }'`
		do
			M=$(echo $mm|grep -c -i M)
			G=$(echo $mm|grep -c -i G)
			K=$(echo $mm|grep -c -i K)
			MEM_USED=0
			if [ $M -ne 0 ];then
				MEM_USED=$(echo $mm|sed -e 's/M//g')
			fi
			if [ $G -ne 0 ];then
				MEM_USED=$(echo $mm|sed -e 's/G//g')
					MEM_USED=$(echo "$MEM_USED * 1024"|bc )
			fi
			if [ $K -ne 0 ];then
				MEM_USED=0
			fi
			if [ $MEM_USED -gt $ENGINE_MAX_MEM ];then
					STOP_ENGINE_MEM_REASON=1

					#PORT=4343
					#for d in `ps x | grep 'eastpect: Eastpect Instance [0-9]'|awk '{ print $8 }'`
					#do
						#   R_PORT=$(echo "$PORT + $d"|bc)
	#nc -U /usr/local/sensei/run/mgmt.sock.$R_PORT<<END
	#set bypass true
	#quit
	#END
	#					done 
				fi
		done
}


detect_architecture(){
	OS_ARC=$(uname -r | cut -c1-2)
	MONGO_CNT=$(pkg info | grep -c mongodb40)
	ELASTIC_CNT=$(pkg info | grep -ci elasticsearch)
	
	if [ $MONGO_CNT -gt 0 ]; then 
		MONGO_ARC=$(pkg info mongodb40 | grep Arc | awk '{ print $3 }' | cut -d':' -f2)
		if [ $OS_ARC != $MONGO_ARC -a ! -f /usr/local/sensei/etc/.mongo_installed_arc ]; then 
		    touch /usr/local/sensei/etc/.mongo_installed_arc
			echo "insert into user_notices(notice_name,notice) values('mongodb_arc_ins','<p>We detected different os arhitecture. We installing mongodb for new os architecture. </p>');"| sqlite3 $SETTINGS_DB
			/usr/local/opnsense/scripts/OPNsense/Sensei/reinstall_mongodb.sh
		fi	
		if [ $OS_ARC = $MONGO_ARC ]; then 
			echo "update user_notices set status=1 where notice_name='mongodb_arc_ins';"| sqlite3 $SETTINGS_DB
		fi 
	fi
	if [ $ELASTIC_CNT -gt 0 ]; then 
		ELASTIC_ARC=$(pkg info elasticsearch5 | grep Arc | awk '{ print $3 }' | cut -d':' -f2)
		if [ $OS_ARC != $ELASTIC_ARC -a ! -f /usr/local/sensei/etc/.elastic_installed_arc ];then 
			touch /usr/local/sensei/etc/.elastic_installed_arc
			echo "insert into user_notices(notice_name,notice) values('elastic_arc_ins','<p>We detected different os arhitecture. We installing elasticsearch for new os architecture. </p>');"| sqlite3 $SETTINGS_DB
			/usr/local/opnsense/scripts/OPNsense/Sensei/reinstall_elasticsearch.sh
		fi	
		if [ $OS_ARC = $MONGO_ARC ]; then 
			echo "update user_notices set status=1 where notice_name='elastic_arc_ins';"| sqlite3 $SETTINGS_DB
		fi 

		
	fi
}
writelog "Architecture Detecting..."
detect_architecture

writelog "Delete null value in configuration..."
# delete null value in configuration.
echo -n "delete from user_configuration where value is null or value='';" | sqlite3 $SETTINGS_DB
echo -n "delete from report_configuration where value is null or value='';" | sqlite3 $SETTINGS_DB


BYPASS_STATUS=0
MONGODB_STATUS=0
MONGODB_TEXT=""
BYPASS_TEXT="OK"
BYPASS_REASON=""
BYPASS_EXTRA="-"

check_bypass() {
	if [ "$SWAP_STATUS" -eq "2" ]; then
		BYPASS_STATUS=2
		BYPASS_TEXT="Bypass enabled due to swap usage!"
		BYPASS_REASON="swap"
		BYPASS_EXTRA="$SWAP_TEXT"
	elif [ "$DISK_STATUS" -eq "2" ]; then
		BYPASS_STATUS=2
		BYPASS_TEXT="Bypass enabled due to disk usage!"
		BYPASS_REASON="disk"
		BYPASS_EXTRA="$DISK_TEXT"
	elif [ "$MONGODB_STATUS" -gt "0" ]; then
		BYPASS_STATUS=2
		BYPASS_TEXT="Mongodb stoped with failure!"
		BYPASS_REASON="DB"
		BYPASS_EXTRA="$MONGODB_TEXT"
	elif [ "$STALLED_STATUS" -ne "0" ]; then
		BYPASS_STATUS=2
		BYPASS_TEXT="engine stalled!"
		BYPASS_REASON="stalled"
	fi
}

#rss check 

RSS_CHECK=$(sysctl -a net.inet.rss.enabled|awk '{ print $2 }')
if [ "$RSS_CHECK" -eq "1" ]; then
	echo "insert into user_notices(notice_name,type,create_date) select 'rss','rss',datetime('now') where not exists(select 1 from user_notices where type='rss');"| sqlite3 $SETTINGS_DB
fi

# remove_old_logs() {
# 
# 	/usr/bin/find ${EASTPECT_ROOT}/log/active/ -type f -mtime +15d  | xargs rm -f {}\;
# 	/usr/bin/find ${EASTPECT_ROOT}/log/archive/ -type f -mtime +15d  | xargs rm -f {}\;
# 	/usr/bin/find ${EASTPECT_ROOT}/output/active/ -type f -mtime +15d  | xargs rm -f {}\;
# 	/usr/bin/find ${EASTPECT_ROOT}/output/archive/ -type f -mtime +15d  | xargs rm -f {}\;
# 	if [ -d /var/log/elasticsearch ];then
# 		/usr/bin/find /var/log/elasticsearch -type f -mtime +7d  | xargs rm -f {}\;
# 	fi	

# }

stop_and_disable_services() {
	# did we already take bypass action before?
	if [ "$STATUS" -ne "0" ]; then
		return
	fi
	TOP_LOG=$(ps -alxwww -m | sort -n -k8 -r | head -10|openssl base64 -A)

	if [ -f /usr/local/etc/rc.d/eastpect ]; then
		service eastpect stop
		configctl sensei onboot eastpect disable
	fi

	if [ -f /usr/local/etc/rc.d/elasticsearch ]; then
		service elasticsearch stop
	fi
}

writelog "Start take publib ip..."
if [ -z "$(find /tmp/ip.txt -amin -240)" ]; then
	# fetch -q 'https://api.ipify.org/?format=json' -o /tmp/ip.txt >/dev/null
	curl -s -m 5 'https://api.ipify.org/?format=json' -o /tmp/ip.txt >/dev/null
fi
writelog "End ip info..."
hostname=$(hostname -s)
if [ -f /tmp/ip.txt ]; then 
	hostname=$(hostname -s)_$(cat /tmp/ip.txt | cut -d":" -f2 | cut -d"\"" -f2 | sed 's/\./_/g' | tr -d '[:space:]' )
fi	

# get version to move core file under support/cores directory
writelog "Get Release info..."
if [ -f $BIN_FILE ]; then
	VERSION_LOCAL=$($BIN_FILE -V | grep Release | sed 's/ /_/g' | sed 's/#//g' | cut -d"_" -f2 | cut -d"-" -f1)
	RSTATUS_LOCAL=$($BIN_FILE -V | grep Release | sed 's/ /_/g' | sed 's/#//g' | cut -d"_" -f2 | cut -d"-" -f2)
fi

CORE_STATUS=0
CORE_TEXT="OK"
check_core_dump() {
	#check if we ever had a core file
	for i in /root/eastpect.*.core /root/zenarmor-agent.*.core "${EASTPECT_ROOT}"/bin/eastpect.*.core; do
		if [ ! -f $i ]; then
			continue
		fi
	#		echo "checking core file(s) (/root)"
		NUM_CORES=$(ls $CRASH_DIR | wc -l)
		# if we have more than 3 core files then delete oldest ones
		if [ $NUM_CORES -gt 4 ]; then
			NRM=`expr $NUM_CORES - 2`
			for n in `ls -t $CRASH_DIR | tail -n $NRM`; do
				rm -f $CRASH_DIR/$n
			done
			#rm $(ls -t $CRASH_DIR | tail -n $NRM)
		fi

		# check if directory exists
		if [ ! -d $CRASH_DIR ]; then  
			mkdir -p $CRASH_DIR
		fi
		CREATE_FILE_DATE=$(stat -f "%Sm" $i)
		CREATE_FILE_TS=$(php -r "print strtotime('$CREATE_FILE_DATE');")

		FNAME=$(basename $i)
		CORE_FILENAME=$(echo $FNAME-$CREATE_FILE_TS | sed 's/ //g')

		mv $i $CRASH_DIR/$CORE_FILENAME

		CORE_STATUS=2
		CORE_TEXT="CRITICAL: Core file detected!"
	done
}

check_mongodb_index(){
   DB_TYPE=$(cat /usr/local/sensei/etc/eastpect.cfg | grep -ic "type = MN")
   if [ $DB_TYPE -eq "1" ]; then
   	MONGODB_DATA_PATH=$(/usr/local/sensei/scripts/datastore/get-db-path.sh MN)
    if [ -f $MONGODB_DATA_PATH/mongod.log ]; then
       CN=$(tail -50 $MONGODB_DATA_PATH/mongod.log | grep -ic "aborting after fassert")
	   if [ $CN -gt 0 ]; then
	      echo ''>$MONGODB_DATA_PATH/mongod.log
	   	  /usr/local/opnsense/scripts/OPNsense/Sensei/mongodb-repair.sh
	   fi
	   CN=$(service mongod onestatus | grep -ic "not running")
       if [ $CN -gt 0 ]; then
          MONGODB_STATUS=12
          MONGODB_TEXT="Mongodb Index Problem"
      fi
    fi
  fi
}


I_LIST=""
get_interface(){
  	INTERFACES=$(cat "${EASTPECT_ROOT}"/etc/workers.map | grep -v "^#" | grep @ | head -10 | cut -d"," -f3 | cut -d"@" -f2 | awk '{$1=$1};1')
	  for INTERFACE in $INTERFACES; do
	    if [ -z "$I_LIST" ];then
	       I_LIST="$INTERFACE"
	    else
	      I_LIST="$INTERFACE,$I_LIST"
	    fi
	  done
}

writelog "Check Max Memory Usage..."
check_engine_memory_used

writelog "Check DB..."
check_db
writelog "Check Swap..."
check_swap
writelog "Check Disk..."
check_disk
writelog "Check Cpu Mem..."
check_cpu_mem
writelog "Check Cpu Score..."
check_cpu_score
writelog "Check Core Dump ..."
check_core_dump
writelog "Check MN index ..."
check_mongodb_index
#check_stalled
writelog "Check Bybass ..."
check_bypass
# writelog "Remove old log files..."
# remove_old_logs

if [ $BYPASS_STATUS -ne "0" ]; then
	if [ ! -f /tmp/bypass_fails ] && [ "$STATUS" -eq "0" ]; then
		echo "$EPOCH_NOW,$BYPASS_REASON,$BYPASS_EXTRA" > /tmp/bypass_fails
		if [ $BYPASS_REASON == 'swap' ]; then
			top -bao res 5> /tmp/bypass_fails_extra
			ps -xao rss,comm,command | sort -rn -k 1 | head -5 > /tmp/bypass_fails_extra_exp
		fi 
		JUST_CREATED=1
	fi
	LAST_FAIL_TIME=0
  if [ -f /tmp/bypass_fails ]; then
	    LAST_FAIL_TIME=$(cat /tmp/bypass_fails | grep $BYPASS_REASON | tail -n 1 | cut -d"," -f1)
	fi
	FAIL_DIFF=$(expr $EPOCH_NOW - $LAST_FAIL_TIME)

	case $BYPASS_REASON in
		"swap")
			writelog "Case:Swap stop service..."
			stop_and_disable_services
			;;
		"disk")
			writelog "Case:Disk stop service..."
			stop_and_disable_services
			;;
		"cpumem")
			writelog "Case:Cpu Mem stop service..."
			if [ "$JUST_CREATED" -ne "1" ] && [ "$FAIL_DIFF" -lt "$TIMEOUT" ]; then
				BYPASS_TEXT="Bypass enabled due to excessive cpu/memory usage!"
				stop_and_disable_services
			fi
			;;
		"stalled")
			writelog "Case:Stalled stop service..."
			if [ "$JUST_CREATED" -ne "1" ] && [ "$FAIL_DIFF" -lt "$TIMEOUT" ]; then
				BYPASS_TEXT="Bypass enabled due to stalled engine!"
				stop_and_disable_services
			fi
			;;
		"core")
			writelog "Case:Core stop service..."
			if [ "$JUST_CREATED" -ne "1" ] && [ "$FAIL_DIFF" -lt "$TIMEOUT" ]; then
				BYPASS_TEXT="Bypass enabled due to core file detection!"
				stop_and_disable_services 
			fi
			;;
	esac

    # if already stoped , dont write
    if [ "$STATUS" -eq "0" ] && [ "$JUST_CREATED" -ne 1 ]; then
	    echo "$EPOCH_NOW,$BYPASS_REASON,$BYPASS_EXTRA" >> /tmp/bypass_fails
		if [ $BYPASS_REASON == 'swap' ]; then
			top -bao res 5> /tmp/bypass_fails_extra
			ps -xao rss,comm,command | sort -rn -k 1 | head -5 > /tmp/bypass_fails_extra_exp
		fi 
	fi

	# maintain number of records
	NUMLINES=$(cat /tmp/bypass_fails | wc -l)
	TODELETE=0
	if [ $NUMLINES -gt "$MAXLINES" ]; then
		TODELETE=$(expr $NUMLINES - $MAXLINES)
		sed -i -e 1,${TODELETE}d /tmp/bypass_fails
	fi
fi

UNIQUE_LOCAL_USERS=0
UNIQUE_SOURCE_IP=0
writelog "Get Uniq Users and IP..."

UNIQUE_SOURCE_IP=$(/usr/local/sbin/configctl sensei numberofdevice)

ACTIVATION_KEY=$(xmllint --xpath "string(//Sensei//license//key)" /conf/config.xml)

CLOUD_ENABLE=$(xmllint --xpath "string(//Sensei//general//CloudManagementEnable)" /conf/config.xml)
CLOUD_ADMIN=$(xmllint --xpath "string(//Sensei//general//CloudManagementAdmin)" /conf/config.xml)
CLOUD_UUID=$(xmllint --xpath "string(//Sensei//general//CloudManagementUUID)" /conf/config.xml)
CLOUD_VERSION=""
CLOUD_SERVICE_STATUS=0
CLOUD_IS_PACKAGE=$(pkg info os-sensei-agent>/dev/null 2>&1;echo $?)

writelog "Cloud Package Checking..."
if [ $CLOUD_IS_PACKAGE -eq 0 ];then 
	CLOUD_IS_PACKAGE="true"
	CLOUD_VERSION=$(pkg info os-sensei-agent | grep Version | awk -F': ' '{ print $2 }')
	CLOUD_SERVICE_STATUS=$(service senpai status | grep -c "is running")
	if [ $CLOUD_SERVICE_STATUS -eq 1 ];then
		CLOUD_SERVICE_STATUS="true"
	else 	
		CLOUD_SERVICE_STATUS="false"
	fi	
else 
	CLOUD_IS_PACKAGE="false"	
fi
CLOUD_ERRORS=""
CLOUD_LOG_FILE=/usr/local/sensei/log/cloud_agent.log
CLOUD_TMP_FILE=/tmp/cloud_log.txt
if [ -f $CLOUD_LOG_FILE ]; then
    echo ''>$CLOUD_TMP_FILE
	grep "Senpai could not connected to NAB" $CLOUD_LOG_FILE|tail -f 2>>$CLOUD_TMP_FILE
	grep -i error $CLOUD_LOG|tail -f 2>>$CLOUD_TMP_FILE
	grep "Starting Senpai on deamon Version" $CLOUD_LOG_FILE|tail -f 2>>$CLOUD_TMP_FILE
	CLOUD_ERRORS=$(cat $CLOUD_TMP_FILE | openssl base64 | tr -d '\n')
fi

THEME=$(xmllint --xpath "string(//theme)" /conf/config.xml)
MAIN_FILE=$EASTPECT_ROOT/log/active/main_`date +'%Y%m%d*'`
M_FILE=main_`date +'%Y%m%d*'`
STALL_COUNT_1=0
CRASH_COUNT_1=0
CT=$(find $EASTPECT_ROOT/log/active/ -iname $M_FILE|wc -l)
if [ $CT -gt 0 ];then 
	STALL_COUNT_1=$(grep 'heartbeat for' $MAIN_FILE | wc -l | awk '{$1=$1};1')
	CRASH_COUNT_1=$(grep 'terminated with signal' $MAIN_FILE | wc -l | awk '{$1=$1};1')
fi	

PARTNER_ID=""
if [ -f /usr/local/sensei/etc/partner.json ]; then 
		PARTNER_ID=$(cat /usr/local/sensei/etc/partner.json | python3 -m json.tool | grep "id" | awk '{print $2}' | sed 's/"//g' | sed 's/,//g')
fi

CPUINFO=$(sysctl hw.model hw.machine hw.ncpu | perl -p -e 's/\n/--/')
CPUINFO=${CPUINFO%??};
MEMINFO=$(sysctl hw.physmem hw.usermem hw.realmem | perl -p -e 's/\n/--/' | perl -p -e 's/\n/--/')
MEMINFO=${MEMINFO%??};
HOSTUUID=$(/usr/local/sensei/bin/eastpect -s)
ENGINERELEASE=$($EASTPECT_ROOT/bin/eastpect -V | grep -i "release" | cut -d" " -f2)
OPNSENSE_VERSION=$(opnsense-version)
writelog "Get Interface info..."
get_interface
writelog "Prepare JSON..."
NRDP_JSON_STR="{ \"version\": \"1\",\"firmware_version\": \"$OPNSENSE_VERSION\", \"hostname\": \"$hostname\", \"hostuuid\": \"$HOSTUUID\", \"activationkey\": \"$ACTIVATION_KEY\", \"ts\": \"$EPOCH_NOW\", \"hostcpu\": \"$CPUINFO\",\"hostcpu_score\": \"$CPU_SCORE\",\"hostmem\": \"$MEMINFO\",\"hostdisksize\": \"$DISK_USED_PERCENT/$DISK_SIZE\",\"hostswapsize\": \"$SWAP_USED/$SWAP_AVAIL $SWAP_RATE\",\"release\": \"$ENGINERELEASE\",\"interfaces\": \"$I_LIST\",\"crashcount\": \"$CRASH_COUNT_1\", \"stallcount\": \"$STALL_COUNT_1\", \"database\": \"$DB_NAME\", \"services\": [
	{\"type\":\"host\",\"state\": 0,\"output\":\"OK\"},
	{\"type\":\"service\",\"servicename\":\"Eastpect Engine\",\"state\":\"$STATUS\",\"output\":\"$TEXT\"},
	{\"type\":\"service\",\"servicename\":\"Eastpect Bridge Service\",\"state\":\"$STALLED_STATUS\",\"output\":\"$STALLED_TEXT\"},
	{\"type\":\"service\",\"servicename\":\"Eastpect Crash\",\"state\":\"$CORE_STATUS\",\"output\":\"$CORE_TEXT\"},
	{\"type\":\"service\",\"servicename\":\"Eastpect Database\",\"state\":\"$DB_STATUS\",\"output\":\"$DB_TEXT\"},
	{\"type\":\"service\",\"servicename\":\"SWAP Usage\",\"state\":\"$SWAP_STATUS\",\"output\":\"$SWAP_TEXT\"},
	{\"type\":\"service\",\"servicename\":\"Disk Usage\",\"state\":\"$DISK_STATUS\",\"output\":\"$DISK_TEXT\"},
	{\"type\":\"service\",\"servicename\":\"Eastpect CPU/Memory Usage\",\"state\":\"$EASTPECT_CPUMEM_STATUS\",\"output\":\"$EASTPECT_CPUMEM_TEXT\"},
	{\"type\":\"service\",\"servicename\":\"Database CPU/Memory Usage\",\"state\":\"$DB_CPUMEM_STATUS\",\"output\":\"$DB_CPUMEM_TEXT\"},
	{\"type\":\"service\",\"servicename\":\"System CPU/Memory Usage\",\"state\":\"$SYSTEM_CPUMEM_STATUS\",\"output\":\"$SYSTEM_CPUMEM_TEXT\"},
	{\"type\":\"service\",\"servicename\":\"Bypass\",\"state\":\"$BYPASS_STATUS\",\"output\":\"$BYPASS_TEXT\"},
	{\"type\":\"service\",\"servicename\":\"Unique Local Users\",\"state\":\"$UNIQUE_LOCAL_USERS\",\"output\":\"$UNIQUE_SOURCE_IP\"}
    ],\"theme\": \"$THEME\",\"top_process\": \"$TOP_LOG\",
	\"cloud\": {\"enable\":\"$CLOUD_ENABLE\", \"uuid\": \"$CLOUD_UUID\",\"partner_id\": \"$PARTNER_ID\", \"admin\": \"$CLOUD_ADMIN\", \"version\": \"$CLOUD_VERSION\", \"package_install\": \"$CLOUD_IS_PACKAGE\", \"service_is_running\": \"$CLOUD_SERVICE_STATUS\",\"errors\": \"$CLOUD_ERRORS\"}
}"

SEND_HEALTH=$(grep healthShare /conf/config.xml | cut -d'<' -f2 | cut -d'>' -f2)

if [ "$SEND_HEALTH" = "true" ];then 
	echo "$NRDP_JSON_STR" > /tmp/nrdp.json
	curl -XPOST -d @/tmp/nrdp.json https://health.sunnyvalley.io/stats_sensei.php
	writelog "Sended JSON..."
fi

find /tmp/ -size +10M -a -name "send_nrpe.log" -exec rm -f {} \;
echo "`date` : $NRDP_JSON_STR" | tee -a /tmp/send_health.log

change_elasticsearch_jvmoptions() {
    jvm_Xms=$(cat $JVM_FILE | grep  "^\-Xms")
    jvm_Xmx=$(cat $JVM_FILE | grep  "^\-Xmx")
    total_mem=$(sysctl hw.physmem|awk '{ print $2 }')
    result=$(php $PHP_JVM_PATH $total_mem $jvm_Xms)
	writelog "Elastic JVM Memory Check...$result"	
    if [ $result -eq 1 ]; then
        echo "Memory greater then 8g, jvm will be set 2gb">>/tmp/sensei_el_check.log
        /usr/bin/sed -i '' "s/$jvm_Xms/-Xms2g/g" $JVM_FILE
        /usr/bin/sed -i '' "s/$jvm_Xmx/-Xmx2g/g" $JVM_FILE
		writelog "Elastic Will be restart"
		service elasticsearch restart >/dev/null 2>&1
		RET=$?
		writelog "Elastic restart result is $RET"
        # /usr/local/sbin/configctl sensei service elasticsearch stop
        # /usr/local/sbin/configctl sensei service elasticsearch start
    fi

    if [ $result -eq 2 ]; then
        echo "Memory less then 8g, jvm will be set 512m">>/tmp/sensei_el_check.log
        /usr/bin/sed -i '' "s/$jvm_Xms/-Xms512m/g" $JVM_FILE
        /usr/bin/sed -i '' "s/$jvm_Xmx/-Xmx512m/g" $JVM_FILE
		service elasticsearch restart >/dev/null 2>&1
		RET=$?
		writelog "Elastic restart result is $RET"
        #/usr/local/sbin/configctl sensei service elasticsearch stop
        #/usr/local/sbin/configctl sensei service elasticsearch start
    fi

}

if [ -f /usr/local/lib/elasticsearch/config/jvm.options ]; then
		writelog "Elastic Checking 1 ..."
        JVM_FILE="/usr/local/lib/elasticsearch/config/jvm.options"
        change_elasticsearch_jvmoptions
fi
if [ -f /usr/local/etc/elasticsearch/jvm.options ]; then
		writelog "Elastic Checking 2 ..."
        JVM_FILE="/usr/local/etc/elasticsearch/jvm.options"
        change_elasticsearch_jvmoptions
fi
