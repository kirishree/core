#!/bin/sh

if [ -f "/tmp/sensei_update.progress" ]; then
    rm -rf "/tmp/sensei_update.progress"
fi

PKG_PROGRESS_FILE=/tmp/zenarmor_update.progress
# Truncate upgrade progress file
: > ${PKG_PROGRESS_FILE}
echo "***GOT REQUEST TO CONFIG: SQLITE***" >> ${PKG_PROGRESS_FILE}

if [ -z $EASTPECT_ROOT ]; then EASTPECT_ROOT="/usr/local/sensei"; fi

DISTRO_OVERRIDE="opnsense/18.1"
KEEP_DATA="$1"
echo "SQLITE Package checking..." >> ${PKG_PROGRESS_FILE}
pkg info sqlite3>>${PKG_PROGRESS_FILE} 2>&1
RET=$?
if [ $RET -ne 0 ]; then
    echo "***ERROR*** Sqlite3 package could not installed : $RET" >> ${PKG_PROGRESS_FILE}
    exit 1
fi

echo "SQLITE PATH: $DATA_FOLDER " >> ${PKG_PROGRESS_FILE}
DATA_FOLDER="/usr/local/datastore/sqlite"
if [ ! -d "$DATA_FOLDER" ]; then
  echo "Create $DATA_FOLDER folder" >> ${PKG_PROGRESS_FILE}
  mkdir -p $DATA_FOLDER
  RET=$?
  if [ $RET -ne 0 ]; then
      echo "***ERROR*** Sqlite folder could not create : $RET" >> ${PKG_PROGRESS_FILE}
      exit 2
  fi
fi
echo "Connection index creating..." >> ${PKG_PROGRESS_FILE}
cat <<__EOF | sqlite3 $DATA_FOLDER/conn_all.sqlite >> ${PKG_PROGRESS_FILE}
CREATE TABLE IF NOT EXISTS conn_all (id integer,start_time integer,app_category text,app_id integer,app_name text,app_proto text,conn_uuid text,dst_dir text,dst_direction text,dst_hostname text,dst_nbytes integer,dst_npackets integer,dst_pbytes integer,dst_username text,encryption text,end_time integer,input integer,ip_dst_port integer,ip_dst_saddr text,ip_src_port integer,ip_src_saddr text,output integer,src_dir text,src_direction text,src_hostname text,src_nbytes integer,src_npackets integer,src_pbytes integer,src_username text,security_tags text,security_tags_len integer,tags text,transport_proto text,vlanid integer,policy text,policyid integer,cloud_policyid text,src_hwaddr text,dst_hwaddr text,interface text,is_blocked integer,is_local integer,dst_geoip_lat number,dst_geoip_lon number,dst_country_name text,src_country_name text,device_id text,device_name text,device_category text,device_vendor text,device_os text,device_osver text,PRIMARY KEY (id));
CREATE TABLE IF NOT EXISTS conn_all_security_tags (id integer,start_time integer,conn_uuid text,tag text,is_blocked integer,interface text,vlanid integer,dst_nbytes integer,dst_npackets integer,src_hwaddr text,dst_hwaddr text,cloud_policyid text,PRIMARY KEY (id));
CREATE INDEX IF NOT EXISTS conn_write_policyid_idx ON conn_all(policyid);
CREATE INDEX IF NOT EXISTS conn_write_app_category_idx ON conn_all(app_category);
CREATE INDEX IF NOT EXISTS conn_write_src_hwaddr_idx ON conn_all(src_hwaddr);
CREATE INDEX IF NOT EXISTS conn_write_conn_uuid_idx ON conn_all(conn_uuid);
CREATE INDEX IF NOT EXISTS conn_write_src_dir_idx ON conn_all(src_dir);
CREATE INDEX IF NOT EXISTS conn_write_ip_src_saddr_idx ON conn_all(ip_src_saddr);
CREATE INDEX IF NOT EXISTS conn_write_ip_dst_saddr_idx ON conn_all(ip_dst_saddr);
CREATE INDEX IF NOT EXISTS conn_write_is_blocked_idx ON conn_all(is_blocked);
CREATE INDEX IF NOT EXISTS conn_write_vlanid_idx ON conn_all(vlanid,interface);
CREATE INDEX IF NOT EXISTS conn_write_security_tags_idx ON conn_all(security_tags);
CREATE INDEX IF NOT EXISTS conn_write_ip_dst_port_idx ON conn_all(ip_dst_port);
CREATE INDEX IF NOT EXISTS conn_write_app_name_idx ON conn_all(app_name);
CREATE INDEX IF NOT EXISTS conn_write_start_time_idx ON conn_all(start_time);
CREATE INDEX IF NOT EXISTS conn_write_dst_hwaddr_idx ON conn_all(dst_hwaddr);
CREATE INDEX IF NOT EXISTS conn_write_security_tags_len_idx ON conn_all(security_tags_len);
CREATE INDEX IF NOT EXISTS conn_write_src_username_idx ON conn_all(src_username);
CREATE INDEX IF NOT EXISTS conn_write_lat_idx ON conn_all(dst_geoip_lat);
CREATE INDEX IF NOT EXISTS conn_write_lon_idx ON conn_all(dst_geoip_lon);
CREATE INDEX IF NOT EXISTS conn_security_tags_write_is_blocked_idx ON conn_all_security_tags(is_blocked);
CREATE INDEX IF NOT EXISTS conn_security_tags_write_conn_uuid_idx ON conn_all_security_tags(conn_uuid);
CREATE INDEX IF NOT EXISTS conn_security_tags_write_dst_hwaddr_idx ON conn_all_security_tags(dst_hwaddr);
CREATE INDEX IF NOT EXISTS conn_security_tags_write_src_hwaddr_idx ON conn_all_security_tags(src_hwaddr);
CREATE INDEX IF NOT EXISTS conn_security_tags_write_vlanid_idx ON conn_all_security_tags(interface,vlanid);
__EOF
echo "Block index creating..." >> ${PKG_PROGRESS_FILE}
cat <<__EOF | sqlite3 $DATA_FOLDER/alert_all.sqlite >> ${PKG_PROGRESS_FILE}
CREATE TABLE IF NOT EXISTS alert_all (id integer,start_time integer,alertinfo_action text,alertinfo_category text,alertinfo_gid integer,alertinfo_rev integer,alertinfo_severity integer,alertinfo_sid text,alertinfo_signature text,app_proto text,conn_uuid text,dst_hostname text,in_iface text,ip_dst_port integer,ip_dst_saddr text,ip_src_port integer,ip_src_saddr text,message text,src_hostname text,tags text,transport_proto text,vlanid integer,policy text,policyid integer,cloud_policyid text,interface text,src_hwaddr text,dst_hwaddr text,dst_username text,src_username text,src_dir text,is_blocked integer,is_local integer,dst_country_name text,src_country_name text,device_id text,device_name text,device_category text,device_vendor text,device_os text,device_osver text,PRIMARY KEY (id));
CREATE INDEX IF NOT EXISTS alert_write_src_hwaddr_idx ON alert_all(src_hwaddr);
CREATE INDEX IF NOT EXISTS alert_write_interface_idx ON alert_all(interface);
CREATE INDEX IF NOT EXISTS alert_write_policyid_idx ON alert_all(policyid);
CREATE INDEX IF NOT EXISTS alert_write_vlanid_idx ON alert_all(vlanid);
CREATE INDEX IF NOT EXISTS alert_write_ip_src_saddr_idx ON alert_all(ip_src_saddr);
CREATE INDEX IF NOT EXISTS alert_write_ip_dst_saddr_idx ON alert_all(ip_dst_saddr);
CREATE INDEX IF NOT EXISTS alert_write_start_time_idx ON alert_all(start_time);
CREATE INDEX IF NOT EXISTS alert_write_dst_hwaddr_idx ON alert_all(dst_hwaddr);
CREATE INDEX IF NOT EXISTS alert_write_src_dir_idx ON alert_all(src_dir);
__EOF

echo "Web index creating..." >> ${PKG_PROGRESS_FILE}
cat <<__EOF | sqlite3 $DATA_FOLDER/http_all.sqlite >> ${PKG_PROGRESS_FILE}
CREATE TABLE IF NOT EXISTS http_all (id integer,start_time integer,browser text,category text,cli_hdr_names text,conn_uuid text,cookie_vars text,deviceversion text,dst_hostname text,encryption text,host text,ip_dst_port integer,ip_dst_saddr text,ip_src_port integer,ip_src_saddr text,method text,os text,osversion text,proto text,proxied text,referrer text,req_body_len integer,rsp_body_len integer,src_hostname text,srv_hdr_names text,status_msg text,transport_proto text,uri text,uri_vars text,user_agent text,version text,vlanid integer,policy text,policyid integer,cloud_policyid text,src_hwaddr text,dst_hwaddr text,interface text,dst_username text,src_username text,src_dir text,is_blocked integer,is_local integer,dst_country_name text,src_country_name text,device_id text,device_name text,device_category text,device_vendor text,device_os text,device_osver text,PRIMARY KEY (id));
CREATE INDEX IF NOT EXISTS http_write_vlanid_idx ON http_all(vlanid);
CREATE INDEX IF NOT EXISTS http_write_version_idx ON http_all(version);
CREATE INDEX IF NOT EXISTS http_write_interface_idx ON http_all(interface);
CREATE INDEX IF NOT EXISTS http_write_dst_hwaddr_idx ON http_all(dst_hwaddr);
CREATE INDEX IF NOT EXISTS http_write_policyid_idx ON http_all(policyid);
CREATE INDEX IF NOT EXISTS http_write_ip_src_hostname_idx ON http_all(src_hostname);
CREATE INDEX IF NOT EXISTS http_write_os_idx ON http_all(os);
CREATE INDEX IF NOT EXISTS http_write_category_idx ON http_all(category);
CREATE INDEX IF NOT EXISTS http_write_src_hwaddr_idx ON http_all(src_hwaddr);
CREATE INDEX IF NOT EXISTS http_write_ip_uri_idx ON http_all(uri);
CREATE INDEX IF NOT EXISTS http_write_status_msg_idx ON http_all(status_msg);
CREATE INDEX IF NOT EXISTS http_write_user_agent_idx ON http_all(user_agent);
CREATE INDEX IF NOT EXISTS http_write_ip_src_saddr_idx ON http_all(ip_src_saddr);
CREATE INDEX IF NOT EXISTS http_write_ip_dst_port_idx ON http_all(ip_dst_port);
CREATE INDEX IF NOT EXISTS http_write_start_time_idx ON http_all(start_time);
CREATE INDEX IF NOT EXISTS http_write_src_dir_idx ON http_all(src_dir);
__EOF

echo "DNS index creating..." >> ${PKG_PROGRESS_FILE}
cat <<__EOF | sqlite3 $DATA_FOLDER/dns_all.sqlite >> ${PKG_PROGRESS_FILE}
CREATE TABLE IF NOT EXISTS dns_all (id integer,start_time integer,conn_uuid text,ip_dst_port integer,ip_dst_saddr text,ip_src_port integer,ip_src_saddr text,is_AA integer,is_RA integer,is_RD integer,is_TC integer,is_request integer,is_response integer,proto text,qclass text,qtype text,query text,resp_code integer,encryption text,total_answers integer,trans_id integer,vlanid integer,policy text,policyid integer,cloud_policyid text,src_dir text,src_hwaddr text,dst_hwaddr text,interface text,dst_username text,src_username text,is_blocked integer,is_local integer,src_hostname text,dst_hostname text,dst_country_name text,src_country_name text,answers text,ttls text,device_id text,device_name text,device_category text,device_vendor text,device_os text,device_osver text, PRIMARY KEY (id));
CREATE INDEX IF NOT EXISTS dns_write_dst_hwaddr_idx ON dns_all(dst_hwaddr);
CREATE INDEX IF NOT EXISTS dns_write_src_dir_idx ON dns_all(src_dir);
CREATE INDEX IF NOT EXISTS dns_write_src_hwaddr_idx ON dns_all(src_hwaddr);
CREATE INDEX IF NOT EXISTS dns_write_policyid_idx ON dns_all(policyid);
CREATE INDEX IF NOT EXISTS dns_write_resp_code_idx ON dns_all(resp_code);
CREATE INDEX IF NOT EXISTS dns_write_query_idx ON dns_all(query);
CREATE INDEX IF NOT EXISTS dns_write_qtype_idx ON dns_all(qtype);
CREATE INDEX IF NOT EXISTS dns_write_interface_idx ON dns_all(interface);
CREATE INDEX IF NOT EXISTS dns_write_vlanid_idx ON dns_all(vlanid);
CREATE INDEX IF NOT EXISTS dns_write_ip_src_saddr_idx ON dns_all(ip_src_saddr);
CREATE INDEX IF NOT EXISTS dns_write_ip_dst_saddr_idx ON dns_all(ip_dst_saddr);
CREATE INDEX IF NOT EXISTS dns_write_start_time_idx ON dns_all(start_time);
__EOF

echo "TLS index creating..." >> ${PKG_PROGRESS_FILE}
cat <<__EOF | sqlite3 $DATA_FOLDER/tls_all.sqlite >> ${PKG_PROGRESS_FILE}
CREATE TABLE IF NOT EXISTS tls_all (id integer,start_time integer,category text,conn_uuid text,dst_hostname text,encryption text,ip_dst_port integer,ip_dst_saddr text,ip_src_port integer,ip_src_saddr text,server_name text,session_id text,src_hostname text,transport_proto text,vlanid integer,policy text,policyid integer,cloud_policyid text,src_hwaddr text,dst_hwaddr text,interface text,dst_username text,src_username text,src_dir text,is_blocked integer,is_local integer,dst_country_name text,src_country_name text,device_id text,device_name text,device_category text,device_vendor text,device_os text,device_osver text,PRIMARY KEY (id));
CREATE INDEX IF NOT EXISTS tls_write_server_name_idx ON tls_all(server_name);
CREATE INDEX IF NOT EXISTS tls_write_category_idx ON tls_all(category);
CREATE INDEX IF NOT EXISTS tls_write_policyid_idx ON tls_all(policyid);
CREATE INDEX IF NOT EXISTS tls_write_src_hostname_idx ON tls_all(src_hostname);
CREATE INDEX IF NOT EXISTS tls_write_src_dir_idx ON tls_all(src_dir);
CREATE INDEX IF NOT EXISTS tls_write_ip_dst_port_idx ON tls_all(ip_dst_port);
CREATE INDEX IF NOT EXISTS tls_write_start_time_idx ON tls_all(start_time);
CREATE INDEX IF NOT EXISTS tls_write_interface_idx ON tls_all(interface);
CREATE INDEX IF NOT EXISTS tls_write_dst_hwaddr_idx ON tls_all(dst_hwaddr);
CREATE INDEX IF NOT EXISTS tls_write_src_hwaddr_idx ON tls_all(src_hwaddr);
CREATE INDEX IF NOT EXISTS tls_write_vlanid_idx ON tls_all(vlanid);
CREATE INDEX IF NOT EXISTS tls_write_ip_src_saddr_idx ON tls_all(ip_src_saddr);
CREATE INDEX IF NOT EXISTS tls_write_ip_dst_saddr_idx ON tls_all(ip_dst_saddr);
__EOF

echo "SIP index creating..." >> ${PKG_PROGRESS_FILE}
cat <<__EOF | sqlite3 $DATA_FOLDER/sip_all.sqlite >> ${PKG_PROGRESS_FILE}
CREATE TABLE IF NOT EXISTS sip_all (id integer,start_time integer,call_id text,encryption text,from_ext text,from_name text,from_user text,ip_dst_port integer,ip_dst_saddr text,ip_src_port integer,ip_src_saddr text,method text,proto text,referrer text,status text,status_msg text,to_ext text,to_name text,to_user text,user_agent text,vlanid integer,policy text,policyid integer,cloud_policyid text,src_hwaddr text,dst_hwaddr text,interface text,dst_username text,src_username text,is_blocked integer,is_local integer,device_id text,device_name text,device_category text,device_vendor text,device_os text,device_osver text,PRIMARY KEY (id));
__EOF

echo "***DONE***" >> ${PKG_PROGRESS_FILE}
exit 0
