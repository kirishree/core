select count(*) as total,ip_dst_saddr as labels,ip_src_saddr as label from dns_all where start_time>__GTE__ and start_time<__LTE__ __WHERE__ group by 2,3 order by 1 desc  limit __SIZE__