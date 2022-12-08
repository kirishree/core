#!/bin/sh
if [ ! -f /usr/local/sensei/py_venv/bin/python3 ]; then
    echo "....Missing  python3 link...."
    ln -s `which python3` /usr/local/sensei/py_venv/bin/python3
    RET=$?
    if [ $RET -ne 0 ];then 
        echo "!!!!!!!! Please check file in /usr/local/sensei/py_venv/bin/python3....!!!!!!!!"
    else 
        if [ -f /usr/local/sensei/scripts/installers/opnsense/18.1/post-install.sh ]; then
            echo "Running Zenarmor post-install scripts..."
            if [ ! -d /usr/local/sensei/log/active ]; then
                mkdir -p /usr/local/sensei/log/active
                mkdir -p /usr/local/sensei/log/archive
            fi
            /usr/local/sensei/scripts/installers/opnsense/18.1/post-install.sh>>/usr/local/sensei/log/active/post-install.log 2>&1
        fi
        rm -rf /usr/local/sensei/etc/.doneinstall
    fi
fi
/usr/local/opnsense/scripts/OPNsense/Sensei/periodicals.py