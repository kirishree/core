#!/bin/sh

if [ $# -ne 2 ];then
# must provide at least one argument: $ROOTDIR
  echo 10
  exit 1
fi

EASTPECT_ROOT=$1

HW_BYBASS=$EASTPECT_ROOT/bin/bpctl_util
HW_DEVICE=/dev/bpmod

hw_bypass_status=$(grep -i hardwareBypassEnable $EASTPECT_ROOT/etc/eastpect.cfg | awk '{ print $3 }')

arg_status=$2
sleep 1
if [ $hw_bypass_status = "true" ];then
   if [ -e $HW_DEVICE ];then
         if [ -f $HW_BYBASS ];then
            ret=$($HW_BYBASS all set_bypass $arg_status|head -1|grep -c "ok")
            if [ $ret -eq 0 ];then
            # return error
               echo 4
               exit 0
            fi
         else
         # command does not exist
           echo 3
           exit 0
         fi
   else
   # hwpybass card does not exist or driver not installed
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
