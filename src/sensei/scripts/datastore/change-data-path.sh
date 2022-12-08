#!/bin/sh

LOG_FILE="/usr/local/sensei/log/active/Senseigui.log"
PID=$$
writelog() {
   MSG=$1
   DT=$(date +"%a, %d %b %y %T %z")
   # [Fri, 31 Jan 20 12:06:12 +0300][INFO] [93119][D:0] Starting Mongodb
   echo "[$DT][INFO] [$PID] CHANGE DATA PATH: $MSG" >>$LOG_FILE
}

PKG_PROGRESS_FILE="/tmp/sensei_data_path.progress"
export BLOCKSIZE=1024
rm -rf $PKG_PROGRESS_FILE

if [ "$#" -ne 3 ]; then
   echo "Error:Must be least two parameter [db type] [current path] [new path]" >>${PKG_PROGRESS_FILE}
   writelog "Error:Must be least two parameter [db type] [current path] [new path]"
   exit 0
fi

DB_TYPE=$1
OLD_DATA_PATH=$2
DATA_PATH=$3

# check same path
if [ "$OLD_DATA_PATH" = "$DATA_PATH" ]; then
   echo "Error:Current data path and new data path not must be same" >>${PKG_PROGRESS_FILE}
   writelog "Error:Current data path and new data path not must be same"
   exit 0
fi

# check old data path
if [ ! -d $OLD_DATA_PATH ]; then
   echo "Error:Could not find  $OLD_DATA_PATH folder" >>${PKG_PROGRESS_FILE}
   writelog "Error:Could not find  $OLD_DATA_PATH folder"
   exit 0
fi

# check new data path
if [ ! -d $DATA_PATH ]; then
   umask 022
   mkdir -p $DATA_PATH
   RET=$?
   if [ $RET -ne 0 ]; then
      echo "Error:Could not create $DATA_PATH folder" >>${PKG_PROGRESS_FILE}
      writelog "Error:Could not create $DATA_PATH folder"
      exit 0
   fi
fi

# file type check
FILE_SYSTEM=$(df -PTh $DATA_PATH | tail -1 | awk '{print $2}')
if [ "$FILE_SYSTEM" = "tmpfs" ]; then
   echo "Error:Could not create $DATA_PATH folder on TMPFS file system" >>${PKG_PROGRESS_FILE}
   writelog "Error:Could not create $DATA_PATH folder on TMPFS file system"
   exit 0
fi

#size check
OLD_DATA_PATH_SIZE=$(du -s $OLD_DATA_PATH | awk '{ print $1 }')
DATA_PATH_SIZE=$(df -T $DATA_PATH | tail -n 1 | awk '{ print $5 }')

if [ $OLD_DATA_PATH_SIZE -gt $DATA_PATH_SIZE ]; then
   echo "Data size big then new path  current size->$OLD_DATA_PATH_SIZE ,$DATA_PATH_SIZE size of $DATA_PATH ..." >>${PKG_PROGRESS_FILE}
   writelog "Data size big then new path  current size->$OLD_DATA_PATH_SIZE ,$DATA_PATH_SIZE size of $DATA_PATH ..."
   exit 0
fi

if [ "$DB_TYPE" = "ES" ]; then
   ES_CONF_FILE="/usr/local/etc/elasticsearch/elasticsearch.yml"
   service elasticsearch onestop
   if [ -d $OLD_DATA_PATH ]; then
      CHECK_EMPTY=$(ls -A $OLD_DATA_PATH | grep -v '\.\.' | grep -v '\.\.\.' | wc -l)
      if [ $CHECK_EMPTY -gt 0 ]; then
         echo "Moving elasticsearch data from $OLD_DATA_PATH to $DATA_PATH..." >>${PKG_PROGRESS_FILE}
         writelog "Moving elasticsearch data from $OLD_DATA_PATH to $DATA_PATH..."
         mv $OLD_DATA_PATH/* $DATA_PATH/.
         RET=$?
         if [ $RET -ne 0 ]; then
            echo "Error:Move elasticsearch data unsuccessful $RET" >>${PKG_PROGRESS_FILE}
            writelog "Error:Move elasticsearch data unsuccessful $RET"
            exit 0
         fi
      fi
      chown -R elasticsearch:elasticsearch $DATA_PATH
      chmod 755 $DATA_PATH
      echo "Change elasticsearch data successful" >>${PKG_PROGRESS_FILE}
      writelog "Change elasticsearch data successful"
      php -r "file_put_contents('$ES_CONF_FILE',str_replace('$OLD_DATA_PATH','$DATA_PATH',file_get_contents('$ES_CONF_FILE')));"
      service elasticsearch onestart
      RET=$?
      if [ $RET -ne 0 ]; then
         echo "Error:Elasticsearch Service could not start ->$RET" >>${PKG_PROGRESS_FILE}
         writelog "Error:Elasticsearch Service could not start ->$RET"
      else
         echo "Elasticsearch Service started" >>${PKG_PROGRESS_FILE}
         writelog "Elasticsearch Service started"
      fi

   fi

fi

if [ "$DB_TYPE" = "MN" ]; then
   MONGO_CONF_FILE="/usr/local/etc/mongodb.conf"
   MONGOD_PATH="/usr/local/etc/rc.d/mongod"
   service mongod onestop
   if [ -d $OLD_DATA_PATH ]; then
      CHECK_EMPTY=$(ls -A $OLD_DATA_PATH | grep -v '\.\.' | grep -v '\.\.\.' | wc -l)
      if [ $CHECK_EMPTY -gt 0 ]; then
         echo "Moving mongodb data from $OLD_DATA_PATH to $DATA_PATH..." >>${PKG_PROGRESS_FILE}
         writelog "Moving mongodb data from $OLD_DATA_PATH to $DATA_PATH..."
         mv $OLD_DATA_PATH/* $DATA_PATH/.
         RET=$?
         if [ $RET -ne 0 ]; then
            echo "Error:Move mongodb data unsuccessful $RET" >>${PKG_PROGRESS_FILE}
            writelog "Error:Move mongodb data unsuccessful $RET"
            exit 0
         fi
      fi
      chown -R mongodb:mongodb $DATA_PATH
      chmod 755 $DATA_PATH
      echo "Move mongodb data successful" >>${PKG_PROGRESS_FILE}
      writelog "Move mongodb data successful"
      php -r "file_put_contents('$MONGO_CONF_FILE',str_replace('$OLD_DATA_PATH','$DATA_PATH',file_get_contents('$MONGO_CONF_FILE')));"
      php -r "file_put_contents('$MONGOD_PATH',str_replace('$OLD_DATA_PATH','$DATA_PATH',file_get_contents('$MONGOD_PATH')));"
      service mongod onestart
      RET=$?
      if [ $RET -ne 0 ]; then
         echo "Error:Mongodb Service could not start ->$RET" >>${PKG_PROGRESS_FILE}
         writelog "Error:Mongodb Service could not start ->$RET"
      else
         echo "Mongodb Service started" >>${PKG_PROGRESS_FILE}
         writelog "Mongodb Service started"
      fi
   fi
fi
if [ "$DB_TYPE" = "SQ" ]; then
   if [ -d $OLD_DATA_PATH ]; then
      CHECK_EMPTY=$(ls -A $OLD_DATA_PATH | grep -v '\.\.' | grep -v '\.\.\.' | wc -l)
      if [ $CHECK_EMPTY -gt 0 ]; then
         echo "Moving sqlite data from $OLD_DATA_PATH to $DATA_PATH..." >>${PKG_PROGRESS_FILE}
         echo "Zenarmor engine running check..." >>${PKG_PROGRESS_FILE}
         ENGINE_STATUS=$(service eastpect status|grep -c "is running")
         if [ $ENGINE_STATUS -gt 0 ]; then
            echo "Zenarmor engine stoping..." >>${PKG_PROGRESS_FILE}
            service eastpect stop
         fi
         writelog "Moving sqlite data from $OLD_DATA_PATH to $DATA_PATH..."
         mv $OLD_DATA_PATH/* $DATA_PATH/.
         RET=$?
         if [ $ENGINE_STATUS -gt 0 ]; then
            echo "Zenarmor engine starting..." >>${PKG_PROGRESS_FILE}
            service eastpect start
         fi
         if [ $RET -ne 0 ]; then
            echo "Error:Move sqlite data unsuccessful $RET" >>${PKG_PROGRESS_FILE}
            writelog "Error:Move sqlite data unsuccessful $RET"
            exit 0
         fi
      fi
   fi   
fi

echo "***DONE***" >>${PKG_PROGRESS_FILE}
echo "OK"