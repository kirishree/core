select count(dst_hostname) as total,dst_hostname) as label,(start_time/__INTERVAL__) as _interval,max(start_time) as start_time  from conn_all where start_time>__GTE__ and start_time<__LTE__ __WHERE__ group by 2,3 order by 3 limit __SIZE__