<?php

require_once('script/load_phalcon.php');

use \OPNsense\Sensei\Sensei;

class Aliases
{
    const rootpath = '/usr/local/sensei/';
    const database = '';
    const hostmap_dbfile = self::rootpath . '/userdefined/db/Usercache/hostmap_cache.db';
    const sql_timeout = 10;
    private $sensei;
    private $db;


    private function createTable()
    {
        $sqls = [
            'CREATE TABLE IF NOT EXISTS hostmap_cache (id INTEGER PRIMARY KEY AUTOINCREMENT,hostname varchar,ip_address varchar,mac_address varchar,created number,deleted number default 0)',
            'CREATE UNIQUE INDEX IF NOT EXISTS hostmap_cache_unique_idx on hostmap_cache(hostname,ip_address)'
        ];
        foreach ($sqls as $sql) {
            $op = $this->db->prepare($sql);
            $op->execute();
        }
    }

    public function dbcheck()
    {
        # error_reporting(E_ERROR);
        try {

            if (!file_exists(self::hostmap_dbfile)) {
                if (!file_exists(dirname(self::hostmap_dbfile)))
                    mkdir(dirname(self::hostmap_dbfile), 0755, true);
                $this->db = new \SQLite3(self::hostmap_dbfile);
                $this->db->busyTimeout(5000);
                $this->db->exec('PRAGMA journal_mode = wal;');
                $this->createTable();
            } else {
                try {
                    $this->db = new \SQLite3(self::hostmap_dbfile);
                    $this->db->busyTimeout(10000);
                    $this->db->exec('PRAGMA journal_mode = wal;');
                    $table_count = $this->db->querySingle("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='hostmap_cache'", false);
                    if ($table_count == 0)
                        $this->createTable();
                } catch (\Exception $e) {
                    $this->logger(__METHOD__ . '::Exception::' . $e->getMessage());
                }
            }
            return true;
        } catch (\Exception $e) {
            $this->logger(sprintf('Aliases Exception: %s => %s', __METHOD__ . '::Exception::', $e->getMessage()));
            return false;
        }
    }

    public function __construct()
    {

        $this->dbcheck();
        $this->s = microtime(true);
        $logfilename = self::rootpath . 'log/active/hostmap.log';
        if (!file_exists(dirname($logfilename))) {
            $logfilename = '/tmp/hostmap.log';
        }

        $this->sensei = new Sensei();
        $this->sensei->log4->logFileName = $logfilename;
    }

    private function logger($str = '', $level = 6 /*Logger::INFO*/)
    {
        $this->sensei->log4->log($level, $str);
    }

    private function crudData($insList = [], $delList = [])
    {
        try {
            $stmtIn = $this->db->prepare('delete from hostmap_cache where hostname=:name and ip_address=:ip');
            foreach ($delList as $l) {
                $stmtIn->bindValue(':name', $l['hostname']);
                $stmtIn->bindValue(':ip', $l['ip_address']);
                if (!$stmtIn->execute())
                    $this->logger(sprintf('Delete from db Exception: %s => %s', __METHOD__ . '::Exception::', $this->db->lastErrorMsg()));
            }

            $stmtIn = $this->db->prepare('delete from hostmap_cache where hostname=:name and ip_address=:ip');
            foreach ($insList as $l) {
                $stmtIn->bindValue(':name', $l['name']);
                $stmtIn->bindValue(':ip', $l['content']);
                if (!$stmtIn->execute())
                    $this->logger(sprintf('delete from hostmap_cache: %s => %s', __METHOD__ . '::Exception::', $this->db->lastErrorMsg()));
            }

            $stmtIn = $this->db->prepare('insert into hostmap_cache(hostname,ip_address,created) values(:name,:ip,:ts)');
            foreach ($insList as $l) {
                $stmtIn->bindValue(':name', $l['name']);
                $stmtIn->bindValue(':ip', $l['content']);
                $stmtIn->bindValue(':ts', time());
                if (!$stmtIn->execute())
                    $this->logger(sprintf('Insert to db Exception: %s => %s', __METHOD__ . '::Exception::', $this->db->lastErrorMsg()));
            }
        } catch (\Exception $e) {
            $this->logger(sprintf('Aliases Exception: %s => %s', __METHOD__ . '::Exception::', $e->getMessage()));
            return false;
        }
    }

    public function recognizeAliases()
    {
        try {
            $doc = new \DOMDocument;
            $doc->load('/conf/config.xml', LIBXML_COMPACT | LIBXML_PARSEHUGE);
            $xpath = new \DOMXPath($doc);
            $list = [];
            $items = $xpath->query("/opnsense/OPNsense/Firewall/Alias/aliases/alias");
            foreach ($items as $item) {
                $tmp = [];
                foreach ($item->childNodes as $child) {
                    $tmp[$child->nodeName] = $child->nodeValue;
                }

                $ip = $tmp['content'];
                $subnet = true;
                if (strpos($ip, '/') !== false) {
                    $l = explode('/', $ip);
                    $ip = $l[0];
                    if (!filter_var($l[1], FILTER_VALIDATE_INT)) {
                        $subnet = false;
                    } else {
                        if (intval($l[1]) < 1 || intval($l[1]) > 32) {
                            $subnet = false;
                        }
                    }
                }

                if ($tmp['enabled'] == '1' && $tmp['type'] == 'host' && filter_var($ip, FILTER_VALIDATE_IP) && $subnet)
                    $list[] = $tmp;
            }

            $results = $this->db->query('select * from hostmap_cache');
            $rows = [];
            while ($row = $results->fetchArray($mode = SQLITE3_ASSOC))
                $rows[] = $row;
            $insList = [];
            $delList = [];
            $commands = [];
            foreach ($list as $l) {
                $find = false;
                foreach ($rows as $row) {
                    if ($row['hostname'] == $l['name'] && $row['ip_address'] == $l['content']) {
                        $find = true;
                        break;
                    }
                }
                if (!$find) {
                    $commands[] = 'hostmap ' . $l['name'] . ' ' . $l['content'] . ' alias';
                    $insList[] = $l;
                }
            }

            foreach ($rows as $row) {
                $find = false;
                foreach ($list as $l) {
                    if ($row['hostname'] == $l['name'] && $row['ip_address'] == $l['content']) {
                        $find = true;
                        break;
                    }
                }
                if (!$find) {
                    $commands[] = 'unhostmap ' . $row['hostname'] . ' ' . $row['ip_address'] . ' alias';
                    $delList[] = $row;
                }
            }
            $this->crudData($insList, $delList);
            if (count($commands) == 0)
                return true;

            $response = $this->sensei->runCLI($commands);
            if (isset($response['message'])) {
                $this->logger('ERR-1:' . $response['message']);
            }
            if (isset($response[0])) {
                foreach ($response as $resp) {
                    if (isset($resp['error'])) {
                        $this->logger('ERR-2:' . $resp['error']);
                    } else if (isset($resp['result']) && $resp['result'] != 'OK') {
                        $this->logger('ERR-3:' . $resp['command']);
                    }
                }
            }
            return true;
        } catch (\Exception $e) {
            $this->logger(sprintf('Aliases Exception: %s => %s', __METHOD__ . '::Exception::', $e->getMessage()));
            return false;
        }
    }
}

$aliases = new Aliases();
$aliases->recognizeAliases();
