var bulk = db.conn_all.initializeUnorderedBulkOp();
bulk.find({ start_time: { $lt:__lttime__ } }).remove();
print("Table : conn_all")
bulk.execute();
var bulk = db.alert_all.initializeUnorderedBulkOp();
bulk.find({ start_time: { $lt:__lttime__ } }).remove();
print("Table : alert_all")
bulk.execute();
var bulk = db.dns_all.initializeUnorderedBulkOp();
bulk.find({ start_time: { $lt:__lttime__ } }).remove();
print("Table : dns_all")
bulk.execute();
var bulk = db.http_all.initializeUnorderedBulkOp();
bulk.find({ start_time: { $lt:__lttime__ } }).remove();
print("Table : http_all")
bulk.execute();
var bulk = db.tls_all.initializeUnorderedBulkOp();
bulk.find({ start_time: { $lt:__lttime__ } }).remove();
print("Table : tls_all")
bulk.execute();
