#!/bin/sh

if [ $# -ne 1 ];then
# must less one argument
  echo 10
  exit 1
fi


if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi

HW_BYBASS=$EASTPECT_ROOT/bin/bpctl_util
# test
# HW_DEVICE=/tmp/bpmod
HW_DEVICE=/dev/bpmod


hw_bypass_status=$(grep -i hardwareBypassEnable $EASTPECT_ROOT/etc/eastpect.cfg | awk '{ print $3 }')

arg_status=$1
sleep 2
if [ $hw_bypass_status == "true" ];then
   if [ -e $HW_DEVICE ];then
         if [ -f $HW_BYBASS ];then
            ret=$($HW_BYBASS all set_bypass $arg_status|head -1|grep -c "ok")
            if [ $ret -eq 0 ];then
            # return error
               echo 4
               exit 0
            fi
         else
         # not exists command
           echo 3
           exit 0
         fi
   else
   # not exists hwpybass card or not install driver.
      echo 2
      exit 0
   fi
else
# baypass status false (disable)
  echo 1
  exit 0
fi
echo 0
exit 0