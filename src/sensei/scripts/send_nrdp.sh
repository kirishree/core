#!/usr/local/bin/bash
#
# check_nrdp.sh
#
# Copyright (c) 2010-2017 - Nagios Enterprises, LLC.
# Written by: Scott Wilkerson (nagios@nagios.org)
#

PROGNAME=$(basename $0)
RELEASE="Revision 0.5"

print_release() {
    echo "$RELEASE"
}

print_usage() {
    echo ""
    echo "$PROGNAME $RELEASE - Send NRPD script for Nagios"
    echo ""
    echo "Usage: send_nrdp.sh -u URL -t token [options]"
    echo ""
    echo "Usage: $PROGNAME -h display help"
    echo ""
}

print_help() {
        print_usage
        echo ""
        echo "This script is used to send NRPD data to a Nagios server"
        echo ""
        echo "Required:"
        echo "    -u","    URL of NRDP server.  Usually http://<IP_ADDRESS>/nrdp/"
        echo "    -t","    Shared token.  Must be the same token set in NRDP Server"
        echo ""
        echo "Options:"
        echo "    Single Check:"
        echo "        -H    host name"
        echo "        -s    service name"
        echo "        -S    State"
        echo "        -o     output"
        echo ""
        echo "    STDIN:"
        echo "        [-d    delimiter] (default -d \"\\t\")"
        echo "        With only the required parameters $PROGNAME is capable of"
        echo "        processing data piped to it either from a file or other"
        echo "        process.  By default, we use \t as the delimiter however this"
        echo "        may be specified with the -d option data should be in the"
        echo "        following formats one entry per line."
        echo "        For Host checks:"
        echo "        hostname    State    output"
        echo "        For Service checks"
        echo "        hostname    servicename    State    output"
        echo ""
        echo "    File:"
        echo "        -f /full/path/to/file"
        echo "        This file will be sent to the NRDP server specified in -u"
        echo "        The file should be an XML file in the following format"
        echo "        ##################################################"
        echo ""
        echo "        <?xml version='1.0'?>"
        echo "        <checkresults>"
        echo "          <checkresult type=\"host\" checktype=\"1\">"
        echo "            <hostname>YOUR_HOSTNAME</hostname>"
        echo "            <state>0</state>"
        echo "            <output>OK|perfdata=1.00;5;10;0</output>"
        echo "          </checkresult>"
        echo "          <checkresult type=\"service\" checktype=\"1\">"
        echo "            <hostname>YOUR_HOSTNAME</hostname>"
        echo "            <servicename>YOUR_SERVICENAME</servicename>"
        echo "            <state>0</state>"
        echo "            <output>OK|perfdata=1.00;5;10;0</output>"
        echo "          </checkresult>"
        echo "        </checkresults>"
        echo "        ##################################################"
        echo ""
        echo "    Directory:"
        echo "        -D /path/to/temp/dir"
        echo "        This is a directory that contains XML files in the format"
        echo "        above.  Additionally, if the -d flag is specified, $PROGNAME"
        echo "        will create temp files here if the server could not be reached."
        echo "        On additional calls with the same -D path, if a connection to"
        echo "        the server is successful, all temp files will be sent."
        exit 0
}

send_data() {
    pdata="token=$token&cmd=submitcheck"
    if [ ! "x$curl" == "x" ];then

        if [ $file ]; then
            fdata="--data-urlencode XMLDATA@$file"
            rslt=`curl -f --silent --insecure -d "$pdata" $fdata "$url/"`
        else
            pdata="$pdata&XMLDATA=$1"
            rslt=`curl -f --silent --insecure -d "$pdata" "$url/"`
        fi
        
        ret=$?
    else
        pdata="$pdata&XMLDATA=$1"
        rslt=`wget -q -O - --post-data="$pdata" "$url/"`
        ret=$?
    fi

    status=`echo $rslt | sed -n 's|.*<status>\(.*\)</status>.*|\1|p'`
    message=`echo $rslt | sed -n 's|.*<message>\(.*\)</message>.*|\1|p'`
    if [ $ret != 0 ];then
        echo "ERROR: could not connect to NRDP server at $url"
        # verify we are not processing the directory already and then write to the directory
        if [ ! "$2" ] && [ $directory ];then
            if [ ! -d "$directory" ];then
                mkdir -p "$directory"
            fi
            # This is where we write to the tmp directory
            echo $xml > `mktemp $directory/nrdp.XXXXXX`
        fi
        exit 1
    fi
    
    if [ "$status" != "0" ];then
        # This means we couldn't connect to NRPD server
        echo "ERROR: The NRDP Server said $message"
        # verify we are not processing the directory already and then write to the directory
        if [ ! "$2" ] && [ $directory ];then
            if [ ! -d "$directory" ];then
                mkdir -p "$directory"
            fi
            # This is where we write to the tmp directory
            echo $xml > `mktemp $directory/nrdp.XXXXXX`
        fi
        
        exit 2
    fi
    
    # If this was a directory call and was successful, remove the file
    if [ $2 ] && [ "$status" == "0" ];then
        rm -f "$2"
    fi

    # If we weren't successful error
    if [ $ret != 0 ];then
        echo "exited with error "$ret
        exit $ret
    fi
}

# Parse parameters

while getopts "u:t:H:s:S:o:f:d:c:D:hv" option
do
  case $option in
    u) url=$OPTARG ;;
    t) token=$OPTARG ;;
    H) host=$OPTARG ;;
    s) service=$OPTARG ;;
    S) State=$OPTARG ;;
    o) output=$OPTARG ;;
    f) file=$OPTARG ;;
    d) delim=$OPTARG ;;
    c) checktype=$OPTARG ;;
    D) directory=$OPTARG ;;
    h) print_help 0;;
    v) print_release
        exit 0 ;;
  esac
done

if [ ! $checktype ]; then
 checktype=1
fi
if [ ! $delim ]; then
 delim=`echo -e "\t"`
fi

if [ "x$url" == "x" -o "x$token" == "x" ]
then
  echo "Usage: send_nrdp -u url -t token"
  exit 1
fi
# detecting curl 
if [[ `which curl` =~ "/curl" ]]
 then curl=1; 
fi
# detecting wget if we don't have curl
if [[ `which wget` =~ "/wget" ]]
then
    wget=1;
fi

if [[ ! $curl && ! $wget ]];
then
  echo "Either curl or wget are required to run $PROGNAME"
  exit 1
fi

checkcount=0


if [ $host ]; then
    xml=""
    # we are not getting piped results
    if [ "$host" == "" ] || [ "$State" == "" ]; then
        echo "You must provide a host -H and State -S"
        exit 2
    fi
    if [ "$service" != "" ]; then
        xml="$xml<checkresult type='service' checktype='$checktype'><servicename>$service</servicename>"
    else
        xml="$xml<checkresult type='host' checktype='$checktype'>"
    fi
    
    # urlencode XML special chars
    output=${output//&/%26}
    output=${output//</%3C}
    output=${output//>/%3E}
    
    xml="$xml<hostname>$host</hostname><state>$State</state><output><![CDATA["$output"]]></output></checkresult>"
    checkcount=1


fi

if [ $file ]; then
    xml=`cat $file`
    send_data "$xml"
fi

if [ $directory ]; then
    #echo "Processing directory..."
    for f in `ls $directory`
    do
      #echo "Processing $f file..."
      # take action on each file. $f store current file name
      xml=`cat $directory/$f`
      send_data "$xml" "$directory/$f"
    done
fi

if [ "x$file" == "x" ] && [ "x$directory" == "x" ]; then
    xml="<?xml version='1.0'?><checkresults>$xml</checkresults>"
    send_data "$xml"
    echo "Sent $checkcount checks to $url"
fi
