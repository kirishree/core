select count(*) as total,(start_time/__INTERVAL__),max(start_time) as start_time,src_hostname as label from http_all where start_time>__GTE__ and start_time<__LTE__ __WHERE__ group by 2 order by 1 desc  limit __SIZE__
