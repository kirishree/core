#!/bin/sh

# delete old alert index
curl -s --max-time 10 -XDELETE 'localhost:9200/alert'

# add old indices to '*_all' aliases
curl -s --max-time 10 -X POST "localhost:9200/_aliases" -H 'Content-Type: application/json' -d'{"actions":[{"add":{"index":"conn","alias":"conn_all"}}]}'
curl -s --max-time 10 -X POST "localhost:9200/_aliases" -H 'Content-Type: application/json' -d'{"actions":[{"add":{"index":"dns","alias":"dns_all"}}]}'
curl -s --max-time 10 -X POST "localhost:9200/_aliases" -H 'Content-Type: application/json' -d'{"actions":[{"add":{"index":"http","alias":"http_all"}}]}'
curl -s --max-time 10 -X POST "localhost:9200/_aliases" -H 'Content-Type: application/json' -d'{"actions":[{"add":{"index":"sip","alias":"sip_all"}}]}'
curl -s --max-time 10 -X POST "localhost:9200/_aliases" -H 'Content-Type: application/json' -d'{"actions":[{"add":{"index":"tls","alias":"tls_all"}}]}'
