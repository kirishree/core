    #!/bin/sh
    if [ $# -eq 0 ];then
        # must less one argument
        echo "Must be one argument"
        exit 1
    fi
    if [ -z $EASTPECT_ROOT ]; then
        EASTPECT_ROOT="/usr/local/sensei/"
    fi
       
    TMPFS_IPDR_DIRECTORY="${EASTPECT_ROOT}output/active/temp"
    
    if [ $1 == "backup" ];then 
        rm -rf "${EASTPECT_ROOT}output/active/temp.tar"
        if [ -d "${EASTPECT_ROOT}output/active/" ];then
            cd "${EASTPECT_ROOT}output/active/"
            tar -cvf temp.tar temp
            if [ $? -eq 0 ];then 
                echo "done"
            else
                echo "error"
            fi         
        fi
    fi

    if [ $1 == "restore" ];then 
        if [ -d "${EASTPECT_ROOT}output/active/" ];then
            cd "${EASTPECT_ROOT}output/active/"
            tar -xvf temp.tar temp
            if [ $? -eq 0 ];then 
                echo "done"
            else
                echo "error"
            fi    
        fi         
    fi

    if [ $1 == "changesize" ];then 
        /sbin/umount /dev/md43
        # sed -i "" -r -E "s/TMPFS_MEMORY=\"([0-9]+)m\"/TMPFS_MEMORY=\"$2m\"/g" /usr/local/etc/rc.d/eastpect
        if [ -c /dev/md43 ]; then
            /sbin/mdconfig -d -u md43
        fi
        /sbin/mdconfig -a -t swap -s $2m -u 43
        /sbin/newfs -U md43
        /sbin/mount /dev/md43 ${TMPFS_IPDR_DIRECTORY}
        if [ $? -eq 0 ];then 
            echo "done"
        else
            echo "error"
       fi         
    fi    
