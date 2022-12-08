#!/usr/local/sensei/py_venv/bin/python3
import sys
import os
import time
import json
from pymongo import MongoClient
mongo_client = MongoClient()
db = mongo_client.sensei
time_part = time.strftime('%y%m%d')
col = db['conn_all']
#create conn_all index
db['conn_all'].create_index("app_category")
db['conn_all'].create_index("app_name")
db['conn_all'].create_index("app_proto")
db['conn_all'].create_index("conn_uuid")
db['conn_all'].create_index("dst_geoip.city_name")
db['conn_all'].create_index("src_hostname")
db['conn_all'].create_index("src_dir")
db['conn_all'].create_index("ip_src_saddr")
db['conn_all'].create_index("ip_dst_saddr")
db['conn_all'].create_index("src_username")
db['conn_all'].create_index("dst_hostname")
db['conn_all'].create_index("dst_username")
db['conn_all'].create_index("start_time")
db['conn_all'].create_index("end_time")
db['conn_all'].create_index("transport_proto")
db['conn_all'].create_index("policyid")
db['conn_all'].create_index("cloud_policyid")
db['conn_all'].create_index("src_hwaddr")
db['conn_all'].create_index("dst_hwaddr")

#create alert_all index
col = db['alert_all']
db['alert_all'].create_index("cloud_policyid")
db['alert_all'].create_index("policyid")
db['alert_all'].create_index("start_time")
db['alert_all'].create_index("category")
db['alert_all'].create_index("alertinfo")
db['alert_all'].create_index("signature")
db['alert_all'].create_index("dst_hostname")
db['alert_all'].create_index("src_hostname")
db['alert_all'].create_index("ip_src_saddr")
db['alert_all'].create_index("ip_dst_saddr")
db['alert_all'].create_index("ip_dst_port")
db['alert_all'].create_index("ip_src_port")
db['alert_all'].create_index("src_hwaddr")
db['alert_all'].create_index("dst_hwaddr")


#create dns_all index
col = db['dns_all']
db['dns_all'].create_index("cloud_policyid")
db['dns_all'].create_index("src_hwaddr")
db['dns_all'].create_index("dst_hwaddr")
db['dns_all'].create_index("policyid")
db['dns_all'].create_index("start_time")
db['dns_all'].create_index("answers")
db['dns_all'].create_index("dst_hostname")
db['dns_all'].create_index("src_hostname")
db['dns_all'].create_index("ip_src_saddr")
db['dns_all'].create_index("ip_dst_saddr")
db['dns_all'].create_index("ip_dst_port")
db['dns_all'].create_index("ip_src_port")

#create http_all index
col = db['http_all']
db['http_all'].create_index("cloud_policyid")
db['http_all'].create_index("policyid")
db['http_all'].create_index("src_hwaddr")
db['http_all'].create_index("dst_hwaddr")
db['http_all'].create_index("start_time")
db['http_all'].create_index("browser")
db['http_all'].create_index("category")
db['http_all'].create_index("os")
db['http_all'].create_index("uri")
db['http_all'].create_index("req_body_len")
db['http_all'].create_index("rsp_body_len")
db['http_all'].create_index("dst_hostname")
db['http_all'].create_index("src_hostname")
db['http_all'].create_index("ip_src_saddr")
db['http_all'].create_index("ip_dst_saddr")
db['http_all'].create_index("ip_dst_port")
db['http_all'].create_index("ip_src_port")

#create tls_all index
col = db['tls_all']
db['tls_all'].create_index("cloud_policyid")
db['tls_all'].create_index("policyid")
db['tls_all'].create_index("src_hwaddr")
db['tls_all'].create_index("dst_hwaddr")
db['tls_all'].create_index("start_time")
db['tls_all'].create_index("category")
db['tls_all'].create_index("server_name")
db['tls_all'].create_index("dst_hostname")
db['tls_all'].create_index("src_hostname")
db['tls_all'].create_index("ip_src_saddr")
db['tls_all'].create_index("ip_dst_saddr")
db['tls_all'].create_index("ip_dst_port")
db['tls_all'].create_index("ip_src_port")