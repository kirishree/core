#!/bin/sh
if [ "$#" -ne 2 ]; then
   echo "Must be least two parameters"
   exit 0
fi

if [ $1 == 'ES' ]; then
    /usr/local/sensei/scripts/installers/elasticsearch/delete_all.py $1
    /usr/local/sensei/scripts/installers/elasticsearch/create_indices.py
fi

if [ $1 == 'MN' ]; then
    /usr/local/sensei/scripts/installers/mongodb/delete_all.py $1
    /usr/local/sensei/scripts/installers/mongodb/create_collection.py
fi