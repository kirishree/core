select count(*) as total,dst_geoip_lat||":"||dst_geoip_lon as geoip from conn_all where start_time>__GTE__ and start_time<__LTE__ and src_dir="EGRESS" __WHERE__ group by 2 order by 1 desc limit __SIZE__