#!/bin/sh
PATH=$PATH:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin

ct=$(ps ax|grep userenrich.py|grep -v grep|wc -l)
if [ $ct -eq 0 ];then
   /usr/local/opnsense/scripts/OPNsense/Sensei/userenrich.py> /dev/null 2>&1 &
fi