#!/bin/sh
if [ ! -f /usr/local/sensei/py_venv/bin/python3 ]; then
    echo "....Missing  python3 link...."
    python_path=$(which python3)
    ln -s $python_path /usr/local/sensei/py_venv/bin/python3
fi
/usr/local/opnsense/scripts/OPNsense/Sensei/heartbeat.py
RET=$?
if [ $RET -ne 0 ]; then
    NODEUUID=$(/usr/local/sensei/bin/eastpect -s)
    curl -XGET -k https://health.sunnyvalley.io/heartbeat.php?uuid=$NODEUUID>/dev/null 2>&1
fi
/usr/local/opnsense/mvc/app/models/OPNsense/Sensei/CLI.php checkOfLoading