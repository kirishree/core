#!/bin/sh

if [ -z $EASTPECT_ROOT ];then
        EASTPECT_ROOT="/usr/local/sensei/"
fi

for f in $(ls $EASTPECT_ROOT/log/active/*); do
	truncate -s 0 $f
done

for f in $(ls $EASTPECT_ROOT/log/archive/*); do
	truncate -s 0 $f
done

for f in $(ls $EASTPECT_ROOT/output/active/*); do
	truncate -s 0 $f
done

for f in $(ls $EASTPECT_ROOT/output/active/temp/*); do
	truncate -s 0 $f
done

for f in $(ls $EASTPECT_ROOT/output/archive/*); do
	truncate -s 0 $f
done

