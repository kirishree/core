#!/bin/sh

if [ -z $EASTPECT_ROOT ];then
        EASTPECT_ROOT="/usr/local/sensei/"
fi

rm -f $EASTPECT_ROOT/log/active/*
rm -f $EASTPECT_ROOT/log/archive/*
rm -f $EASTPECT_ROOT/output/active/*.ipdr
rm -f $EASTPECT_ROOT/output/active/temp/*
rm -f $EASTPECT_ROOT/output/archive/*
