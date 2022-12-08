# type = 1 -> ip - networks , type = 2 -> VLAN
DROP TABLE policies_networks;
CREATE TABLE policies_networks (id INTEGER PRIMARY KEY AUTOINCREMENT,policy_id INTEGER,type INTEGER,network TEXT,status INTEGER);
CREATE INDEX policies_networks_idx on policies_networks(policy_id);