select count(*) as total,resp_code as label from dns_all where start_time>__GTE__ and start_time<__LTE__ __WHERE__ group by 2 order by 1 desc  limit __SIZE__