select count(*) as total,src_username as label from http_all where start_time>__GTE__ and start_time<__LTE__ and src_dir="INGRESS" __WHERE__ group by src_username order by 1 desc limit __SIZE__