#!/bin/sh
PKG_PROGRESS_FILE=/tmp/sensei_package_update.log
 
PHPVER=$(php -v | head -1 | awk '{ print $2 }' | sed 's/\.//g' | awk '{print substr ($0, 0, 2)}')
if [ $PHPVER -eq 72 ];then 
  CN=$(pkg info | grep -c php72-pecl-mongodb)
  if [ $CN -eq 0 ];then 
        echo "php72-pecl-mongodb module..." >> ${PKG_PROGRESS_FILE}
        pkg install -fy php72-pecl-mongodb >> ${PKG_PROGRESS_FILE}  2>&1
   fi     
fi  
if [ $PHPVER -eq 73 ];then   
  CN=$(pkg info | grep -c php73-pecl-mongodb)
  if [ $CN -eq 0 ];then 
      echo "php73-pecl-mongodb module..." >> ${PKG_PROGRESS_FILE}
      pkg install -fy php73-pecl-mongodb >> ${PKG_PROGRESS_FILE} 2>&1
  fi    
fi
if [ $PHPVER -eq 74 ];then   
  CN=$(pkg info | grep -c php74-pecl-mongodb)
  if [ $CN -eq 0 ];then 
      echo "php74-pecl-mongodb module..." >> ${PKG_PROGRESS_FILE}
      pkg install -fy php74-pecl-mongodb >> ${PKG_PROGRESS_FILE} 2>&1
  fi    
fi

if [ $PHPVER -eq 80 ];then   
  CN=$(pkg info | grep -c php80-pecl-mongodb)
  if [ $CN -eq 0 ];then 
      echo "php80-pecl-mongodb module..." >> ${PKG_PROGRESS_FILE}
      pkg install -fy php80-pecl-mongodb >> ${PKG_PROGRESS_FILE} 2>&1
  fi    
fi
