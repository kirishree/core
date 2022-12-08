#!/bin/sh

for configfile in `find / -iname elasticsearch.yml`
do
  data_path=$(grep "^path.data" $configfile)
  real_path=$(echo -n $data_path|awk -F':' '{ print $2 }')
  CN=$(find $real_path -d 1 -iname nodes | grep -c nodes)
  if [ $CN -ne 0 ];then 
    if [ -d $real_path ];then
      if [ ! -z $real_path ];then 
         rm -rf $real_path/*
      fi  
    fi
  fi  
  echo $real_path
done