select count(*) as total,uri as label,sum(rsp_body_len) as total_rsp_body_len  from http_all where start_time>__GTE__ and start_time<__LTE__ __WHERE__ group by 2 order by 1 desc  limit __SIZE__