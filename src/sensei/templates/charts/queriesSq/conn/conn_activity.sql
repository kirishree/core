select count(*) as total,sum(src_nbytes) as src_nbytes,sum(dst_nbytes) as dst_nbytes,sum(src_npackets) as src_npackets,sum(dst_npackets) as dst_npackets,count(distinct ip_src_saddr) as ip_src_saddr,count(distinct ip_dst_saddr) as ip_dst_saddr,count(distinct app_name) as app_name,count(distinct src_username) as src_username from conn_all where start_time>__GTE__ and start_time<__LTE__ and src_dir="EGRESS" __WHERE__ order by 1 desc limit __SIZE__