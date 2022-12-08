#!/bin/sh

if [ -z "$EASTPECT_ROOT" ]; then
    EASTPECT_ROOT="/usr/local/sensei/"
fi

echo -n "Creating alert index..."
sh "${EASTPECT_ROOT}/scripts/installers/elasticsearch/alert.sh"

echo -n "Creating connection index..."
sh "${EASTPECT_ROOT}/scripts/installers/elasticsearch/conn.sh"

echo -n "Creating DNS index..."
sh "${EASTPECT_ROOT}/scripts/installers/elasticsearch/dns.sh"

echo -n "Creating HTTP index..."
sh "${EASTPECT_ROOT}/scripts/installers/elasticsearch/http.sh"

echo -n "Creating SIP index..."
sh "${EASTPECT_ROOT}/scripts/installers/elasticsearch/sip.sh"

echo -n "Creating TLS index..."
sh "${EASTPECT_ROOT}/scripts/installers/elasticsearch/tls.sh"
