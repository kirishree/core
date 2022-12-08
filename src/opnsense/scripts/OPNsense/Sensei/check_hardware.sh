#!/bin/sh

OPNSENSE_VERSION=$(opnsense-version | awk '{ print $2 }')
RAM=$(sysctl hw.realmem | awk '{printf "%s", $2}')
CPU=$(sysctl hw.model | cut -d':' -f2 | sed -e 's/^[ \t]*//')
NCPU=$(sysctl hw.ncpu | cut -d":" -f2 | awk '{$1=$1};1')
CPU_SCORE=0
CPU_PROPER=false
CPU_SCORE_TMP=/usr/local/sensei/etc/sensei_cpu_score

run_ubench() {
  if [ -f /usr/local/bin/ubench ]; then
      /usr/local/bin/ubench -c -s | grep "Single CPU" | awk '{print $4}' > $CPU_SCORE_TMP
      if [ -f $CPU_SCORE_TMP ]; then
            RESULT=$(cat $CPU_SCORE_TMP)
            if [ $RESULT -eq 0 ]; then 
                 /usr/local/bin/ubench -c -s | grep "Single CPU" | awk '{print $4}' > $CPU_SCORE_TMP
            fi
      fi  
  fi
}

if [ ! -f /usr/local/sensei/etc/.configdone ] || [ ! -f $CPU_SCORE_TMP ]; then
    if [ ! -f /usr/local/bin/ubench ]; then
        pkg install -fy os-sunnyvalley > /dev/null 2>&1
        pkg install -fy ubench > /dev/null 2>&1
        RET=$?
        if [ $RET -eq 0 ];then
            CPU_SCORE=-1
        fi
    fi
    run_ubench
fi

if [ -f $CPU_SCORE_TMP ]; then
	CPU_SCORE=$(cat $CPU_SCORE_TMP)
fi

if [ $CPU_SCORE -le 300000 ]; then
	if [ $CPU_SCORE -ge 200000 ] && [ $NCPU -ge 8 ] ; then
		CPU_PROPER="true"
	else
		CPU_PROPER="false"
	fi
else
       CPU_PROPER="true"
fi


echo "{
   \"memory\": {
       \"size\": $RAM,
       \"proper\": $(if [ $RAM -gt 4000000000 ]; then echo true; else echo false; fi)
   },
   \"cpu\": {
       \"model\": \"$CPU\",
       \"proper\": $CPU_PROPER,
       \"score\": $CPU_SCORE
   },
   \"opnsense_version\": \"$OPNSENSE_VERSION\"
}"
