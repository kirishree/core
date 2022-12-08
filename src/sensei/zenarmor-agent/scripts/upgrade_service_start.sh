#!/bin/sh

if [ -d "/usr/local/zenarmor" ]; then
    ZENARMOR_AGENT_ROOT="/usr/local/zenarmor"
    ZENARMOR_AGENT_SERVICE="zenarmor-agent"
else
    ZENARMOR_AGENT_ROOT="/usr/local/sensei"
    ZENARMOR_AGENT_SERVICE="senpai"
fi

echo "Checking to see if we have an unsupervised zenarmor-agent process..."
# kill old version old_IFS=$IFS
old_IFS=$IFS
IFS=$'\n'
CN=0
FOUND=0
# Allow the current zenarmor-agent process to go out of agent update and restart itself
# This is to prevent a race-condition
sleep 5
while [ $CN -lt 5 ];do
    for tt in `ps xao ppid,pid,comm | grep zenarmor-agent | grep -v zenarmor-agent-sup`
    do
        echo "...found a zenarmor-agent process: $tt"
        PPID=$(echo "$tt"|awk '{print $1}')
        if [ $PPID -eq 1 ];then
            echo "...looks like an unsupervised zenarmor-agent process $tt"
            PID=$(echo "$tt"|awk '{print $2}')
            echo "...killing unsupervised PID:$PID ..."
            kill -9 $PID 
	    FOUND=1
        fi
    done
    CN=$(echo "$CN + 1"|bc)
    sleep 1
done
IFS=$old_IFS
if [ $FOUND -eq 1 ]; then
	rm -f ${ZENARMOR_AGENT_ROOT}/run/zenarmor-agent.pid
	echo "...Done. Restarting the zenarmor-agent process under supervisor superserver..."	
	service ${ZENARMOR_AGENT_SERVICE} onerestart
fi
