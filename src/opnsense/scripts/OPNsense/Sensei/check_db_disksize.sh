#!/bin/sh
if [ $# -ne 1 ]; then
  echo 0
  exit 0
fi
export BLOCKSIZE=1024
echo $(($(du -s $1 | awk '{print $1}')*1024))