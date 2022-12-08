#!/bin/sh

if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi

LICENSE="Freemium"
EDITED="false"
TLS_TAG="TlsVisibility"
MODEL_DIR="/usr/local/opnsense/mvc/app/models/OPNsense/Sensei"
MENU_TEMPLATE_P="$MODEL_DIR/Menu.xml"
MENU_DIR="$MODEL_DIR/Menu"
MENU_FILE="$MENU_DIR/Menu.xml"

if [ -f $EASTPECT_ROOT/etc/license.data ]; then
    if [ -n "$($EASTPECT_ROOT/bin/eastpect -x $EASTPECT_ROOT/etc/license.data | grep "License OK")" ];then
        LICENSE="Business"
    fi
fi

# if [ ! -d $MENU_DIR ]; then
#    mkdir -p $MENU_DIR
# fi

echo "Activating features for $LICENSE Edition..."
echo -n "Deleting OPNsense menu cache..."
/bin/rm -f /usr/local/opnsense/mvc/app/cache/*.php
/bin/rm -f /tmp/opnsense_menu_cache.xml
echo "done"

exit 0
