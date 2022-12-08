#!/bin/sh
if [ -d "/usr/local/zenarmor" ]; then
        ZENARMOR_ROOT_DIR="/usr/local/zenarmor"
else
        ZENARMOR_ROOT_DIR="/usr/local/sensei"
fi

CAKEY=$ZENARMOR_ROOT_DIR/cert/internal_ca.key
CACERT=$ZENARMOR_ROOT_DIR/cert/internal_ca.pem

# if not exists random generation file 
if [ ! -f $HOME/.rnd ]; then 
	cd ~/; openssl rand -writerand .rnd
fi

if [ ! -f $CAKEY ]; then
	# Generate self signed root CA cert
	openssl req -nodes -x509 -newkey rsa:2048 -days 1825 -keyout $CAKEY -out $CACERT -subj "/C=US/ST=CA/L=Cupertino/O=zenarmor/OU=root/CN=`hostname -f`/emailAddress=hi@sunnyvalley.io"
fi
exit 0
