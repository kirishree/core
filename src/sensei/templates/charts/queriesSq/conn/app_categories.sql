select count(*) as total,app_category as label from conn_all where start_time>__GTE__ and start_time<__LTE__ __WHERE__ group by app_category order by 1 desc  limit __SIZE__