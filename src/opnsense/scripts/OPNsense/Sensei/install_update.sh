#!/bin/sh

# usage: ./install_update.sh <package>
# usage: ./install_update.sh os-sensei
if [ -z $EASTPECT_ROOT ]; then 
    EASTPECT_ROOT="/usr/local/sensei" 
fi

SENSEI_UPDATER="/usr/local/sbin/sensei-updater"
DB_VERSION_CONTROL="/usr/local/opnsense/scripts/OPNsense/Sensei/sensei-db-version.py"
PKG_URL="https://updates.sunnyvalley.io/updates/db/1.12.1"
CLI_="/usr/local/opnsense/mvc/app/models/OPNsense/Sensei/CLI.php"

PKG_PROGRESS_FILE=/tmp/zenarmor_update.progress
if [ -f "/tmp/sensei_update.progress" ]; then
    rm -rf "/tmp/sensei_update.progress"
fi

# Truncate upgrade progress file
: > ${PKG_PROGRESS_FILE}

DEV_DB_URI_CONF='/root/.sensei_db.conf'
if [ -f $DEV_DB_URI_CONF ];then 
    PKG_URL=$(cat $DEV_DB_URI_CONF | tr -d '\r\n')
fi

SETTINGS_DB="/usr/local/sensei/userdefined/config/settings.db"

if [ -z "$1" ]; then
    echo "Package must be defined!">>${PKG_PROGRESS_FILE}
    exit 0
fi

PACKAGE="$1"

if [ "$PACKAGE" == "os-sensei" ]; then
    $SENSEI_UPDATER
fi

if [ "$PACKAGE" == "os-sensei-db" ]; then
        RESPONSE=$($DB_VERSION_CONTROL $2)
        PKG_FILE=$(echo -n $RESPONSE|cut -d':' -f2)
        #tarbal
        TAR_FILE=$(echo -n $PKG_FILE|sed "s/txz/tar.gz/g")
        NEW_VERSION=$(echo -n $RESPONSE|cut -d':' -f1)
        echo "$TAR_FILE will be installed">> ${PKG_PROGRESS_FILE} 2>&1
        if [ ! -z "$TAR_FILE" ]; then
            echo "$TAR_FILE downloading">> ${PKG_PROGRESS_FILE} 2>&1
            RS=$(curl -s -o "/tmp/$TAR_FILE" -w "%{http_code}" "$PKG_URL/$TAR_FILE")
            if [ $RS -eq 200 ]; then
                #pkg install -fy "/tmp/$PKG_FILE"
                cd /tmp
                pkg info os-sensei-db>/dev/null 2>&1
                RET=$?
                if [ $RET -eq 0 ] ; then    
                    echo "Remove sensei cloud db">> ${PKG_PROGRESS_FILE} 2>&1
                    pkg remove -fy os-sensei-db >> ${PKG_PROGRESS_FILE} 2>&1
                    pkg clean -y >> ${PKG_PROGRESS_FILE} 2>&1
                    if [ -f /usr/local/opnsense/version/sensei-db ]; then
                        mv /usr/local/opnsense/version/sensei-db /tmp/.
                    fi 
                    if [ -f /usr/local/opnsense/scripts/firmware/register.php ]; then 
                        /usr/local/opnsense/scripts/firmware/register.php remove os-sensei-db
                    fi
                fi
                
                echo "Extract zip file...">> ${PKG_PROGRESS_FILE} 2>&1
                tar -xvf $TAR_FILE >> /dev/null 2>&1
                RET=$?
                if [ $RET -ne 0 ] ; then    
                    echo "Error Tar ball not open $RET">> ${PKG_PROGRESS_FILE} 2>&1
                    exit 1
                fi
                chown -R root:wheel usr/local/* > /dev/null 2>&1
                cp -rf usr/local/* /usr/local/. > /dev/null 2>&1  
                
                RET=$?
                if [ $RET -ne 0 ] ; then    
                    echo "Error package could not install $RET">> ${PKG_PROGRESS_FILE} 2>&1
                else
                    echo -n "insert into user_notices(notice_name,notice) values('new-db-version','<p>Your application database has been updated to version $NEW_VERSION</p>');"|sqlite3 $SETTINGS_DB
                    if [ -f /usr/local/etc/rc.d/configd ]; then
                        echo -n "Restarting configd service...">> ${PKG_PROGRESS_FILE} 2>&1
                        /usr/local/etc/rc.d/configd restart > /dev/null 2>&1
                        echo "done">> ${PKG_PROGRESS_FILE} 2>&1
                    fi

                    if [ -f /usr/local/sensei/scripts/installers/opnsense/18.1/load_policy_categories.py ]; then
                        echo "Compare new and old applications and rules db...">> ${PKG_PROGRESS_FILE} 2>&1
                        /usr/local/sensei/scripts/installers/opnsense/18.1/load_policy_categories.py >> ${PKG_PROGRESS_FILE} 2>&1
                        echo "done">> ${PKG_PROGRESS_FILE} 2>&1
                    fi

                    if [ -f /usr/local/sbin/configctl ]; then
                        echo "Create template files for applications and rules ...">> ${PKG_PROGRESS_FILE} 2>&1
                        /usr/local/sbin/configctl template reload OPNsense/Sensei > /dev/null 2>&1
                        /usr/local/sbin/configctl sensei worker reload > /dev/null 2>&1
                        /usr/local/sbin/configctl sensei policy reload > /dev/null 2>&1
                        echo "done">> ${PKG_PROGRESS_FILE} 2>&1
                    fi


                    if [ -f $CLI_ ]; then
                        echo -n "Reloading applications and rules db...">> ${PKG_PROGRESS_FILE} 2>&1
                        $CLI_ reload>> ${PKG_PROGRESS_FILE} 2>&1
                        echo "done">> ${PKG_PROGRESS_FILE} 2>&1
                    fi
                fi
            else
                echo "$TAR_FILE could not download.$RS"   >> ${PKG_PROGRESS_FILE} 2>&1
                echo "$PKG_FILE downloading">> ${PKG_PROGRESS_FILE} 2>&1
                RS=$(curl -s -o "/tmp/$PKG_FILE" -w "%{http_code}" "$PKG_URL/$PKG_FILE")
                if [ $RS -eq 200 ]; then
                    pkg install -fy "/tmp/$PKG_FILE"
                    RET=$?
                    if [ $RET -eq 0 ] ; then    
                        echo "installed $PKG_FILE">> ${PKG_PROGRESS_FILE} 2>&1
                        echo -n "insert into user_notices(notice_name,notice) values('new-db-version','<p>Your application database has been updated to version $NEW_VERSION</p>');"|sqlite3 $SETTINGS_DB
                        $CLI_ reload
                    fi
                else
                    echo "$PKG_FILE could not download.$RS"   >> ${PKG_PROGRESS_FILE} 2>&1    
                fi    

            fi

        fi
fi
echo "***DONE***">> ${PKG_PROGRESS_FILE} 2>&1
