select count(*) as total,(start_time/__INTERVAL__),max(start_time) as start_time,src_hostname as label from conn_all where start_time>__GTE__ and start_time<__LTE__ and src_dir="EGRESS" __WHERE__ group by 2,4 order by 3  limit __SIZE__