select count(*) as total,host as label,src_hostname as labels from http_all where start_time>__GTE__ and start_time<__LTE__ __WHERE__ group by 2 order by 1 desc  limit __SIZE__