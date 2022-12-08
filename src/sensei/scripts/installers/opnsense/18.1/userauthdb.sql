CREATE TABLE IF NOT EXISTS "users_cache" (policyid int,sessionid varchar,authenticated_via varchar,username varchar,groups varchar,ip_address varchar,mac_address varchar,created number,deleted integer default (0), hostname int default(0),primary key (policyid, sessionid));
CREATE INDEX IF NOT EXISTS users_cache_ip ON users_cache (ip_address);
CREATE INDEX IF NOT EXISTS users_cache_policy ON users_cache (policyid);
CREATE INDEX IF NOT EXISTS users_cache_session ON users_cache (sessionid);
CREATE INDEX IF NOT EXISTS users_cache_created ON users_cache (created);
CREATE INDEX IF NOT EXISTS users_cache_hostname ON users_cache (hostname);