#!/bin/sh
PACKAGE_MANAGER=$1
PKG_NAME=$2
OUTPUT_FNAME=$3
SERVICE_NAME=$4
PACKAGE_TYPE=$5
IS_ENGINE=0
IS_AGENT=0
CLOUD_LOG=$3

if [ -d "/usr/local/zenarmor" ]; then
	ZENARMOR_ROOT_DIR="/usr/local/zenarmor"
else
	ZENARMOR_ROOT_DIR="/usr/local/sensei"
fi

if [ -f ${ZENARMOR_ROOT_DIR}/log/active/cloud_agent.log ]; then
    CLOUD_LOG="${ZENARMOR_ROOT_DIR}/log/active/cloud_agent.log"
fi

# This needs to be the first log since it truncates the log file
echo "....... Starting update script ....... ">$OUTPUT_FNAME

if [ "$PACKAGE_TYPE" = "engine" ]; then
    IS_ENGINE=1
elif [ "$PACKAGE_TYPE" = "agent" ]; then
	IS_AGENT=1
else
    echo "RETURN CODE::119::">>"$OUTPUT_FNAME"
    exit 1
fi

ENGINE_VERSION="0"
AGENT_VERSION="0"
if [ $IS_ENGINE -gt 0 ];then
    if [ -f ${ZENARMOR_ROOT_DIR}/bin/eastpect ]; then
            ENGINE_VERSION=$(${ZENARMOR_ROOT_DIR}/bin/eastpect -V | grep -i "release" | cut -d" " -f2)
    fi
    echo "Engine version is $ENGINE_VERSION">>$OUTPUT_FNAME
elif [ $IS_AGENT -gt 0 ];then
    if [ -f ${ZENARMOR_ROOT_DIR}/zenarmor-agent/bin/zenarmor-agent ]; then
        AGENT_VERSION=$(${ZENARMOR_ROOT_DIR}/zenarmor-agent/bin/zenarmor-agent -V | cut -d" " -f4)
    fi
     echo "Agent version is $AGENT_VERSION">>$OUTPUT_FNAME
fi

UPDATE_INFO=""
if [ $PACKAGE_MANAGER = "bsd-pkg" ]; then 
    PKG_COUNT=$(pgrep pkg | wc -l)
    if [ $PKG_COUNT -gt 0 ]; then
        echo "RETURN CODE::102::">>"$OUTPUT_FNAME".tw
        exit 1
    fi
    UPDATE_INFO="The most recent versions of packages are already installed"
    # TODO OPNsense does not like timeout, pkg does not return until timeout expires, 
    # meanwhile OPNsense shuts down all OPNsense-related services including configd, 
    # dns, webui; so we do it the old way. For now. TODO
    if [ -f /usr/local/sbin/opnsense-version ]; then
        pkg install -y $PKG_NAME >>$OUTPUT_FNAME 2>&1
        PKGRET=$?
    else
        timeout 120s pkg install -y $PKG_NAME >>$OUTPUT_FNAME 2>&1
        PKGRET=$?
    fi
elif [ $PACKAGE_MANAGER = "yum" ]; then 
    PKG_COUNT=$(pgrep yum | wc -l)
    if [ $PKG_COUNT -gt 0 ]; then
        echo "RETURN CODE::102::">>"$OUTPUT_FNAME".tw
        exit 1
    fi 
    UPDATE_INFO="already installed and latest version"    
    timeout 120s yum --enablerepo=zenarmor clean metadata
    timeout 120s yum -y install $PKG_NAME >>$OUTPUT_FNAME 2>&1
    # It's important that we get the exit value of yum install here
    PKGRET=$?
    systemctl daemon-reload
elif [ $PACKAGE_MANAGER = "apt" ]; then 
    PKG_COUNT=$(pgrep apt | wc -l)
    if [ $PKG_COUNT -gt 0 ]; then
        echo "RETURN CODE::102::">>"$OUTPUT_FNAME".tw
        exit 1
    fi 
    UPDATE_INFO="is already the newest version"    
    timeout 120s apt update -y>/dev/null
    timeout 120s apt install -y $PKG_NAME >>$OUTPUT_FNAME 2>&1
    # It's important that we get the exit value of apt install here
    PKGRET=$?
    systemctl daemon-reload
else
    echo "RETURN CODE::129::">>"$OUTPUT_FNAME"
    exit 1    
fi
    
NEW_AGENT_VERSION="0"
if [ $IS_AGENT -ne 0 ]; then
    if [ -f ${ZENARMOR_ROOT_DIR}/zenarmor-agent/bin/zenarmor-agent ]; then
        NEW_AGENT_VERSION=$(${ZENARMOR_ROOT_DIR}/zenarmor-agent/bin/zenarmor-agent -V | cut -d" " -f4)
    fi
    if [ "$NEW_AGENT_VERSION" != "$ENGINE_VERSION" ];then
        echo "RETURN CODE::0::">>$OUTPUT_FNAME
        echo "NEW VERSION $NEW_AGENT_VERSION RETURN CODE::0::">>$CLOUD_LOG
        exit 0
    fi
    IS_NO_UPDATE=$(grep -c -i "$UPDATE_INFO" $OUTPUT_FNAME)
    if [ $IS_NO_UPDATE -gt 0 ]; then
        echo "$PKG_NAME is already the newest version">>$OUTPUT_FNAME
        exit 0
    fi
fi 
NEW_ENGINE_VERSION="0"
if [ $IS_ENGINE -gt 0 ];then
    if [ -f ${ZENARMOR_ROOT_DIR}/bin/eastpect ]; then
        NEW_ENGINE_VERSION=$(${ZENARMOR_ROOT_DIR}/bin/eastpect -V | grep -i "release" | cut -d" " -f2)
    fi
    if [ "$NEW_ENGINE_VERSION" != "$ENGINE_VERSION" ];then
        echo "RETURN CODE::0::">>$OUTPUT_FNAME
        echo "NEW VERSION $NEW_ENGINE_VERSION RETURN CODE::0::">>$CLOUD_LOG
        exit 0
    fi
fi

echo "RETURN CODE::$PKGRET::">>$CLOUD_LOG
echo "RETURN CODE::$PKGRET::">>$OUTPUT_FNAME
