<?php
/**
 * Created by PhpStorm.
 * User: ureyni
 * Date: 06.08.2019
 * Time: 20:02
 */

namespace OPNsense\Sensei\Api;

use \OPNsense\Base\ApiControllerBase;
use \OPNsense\Core\Backend;
use \OPNsense\Sensei\Sensei;


class UserenricherreportController extends ApiControllerBase
{
    private $sensei = null;
    private $db = null;
    const rootpath = '/usr/local/sensei';
    const user_dbfile = self::rootpath . '/userdefined/db/Usercache/userauth_cache.db';

    private function opendb()
    {
        try {
            $this->sensei = new Sensei();
            if (!file_exists(self::user_dbfile))
                return false;
            $this->db = new \SQLite3(self::user_dbfile);
            $this->db->busyTimeout(10000);
            $this->db->exec('PRAGMA journal_mode = wal;');
            return true;
        } catch (\Exception $e) {
            $this->sensei->logger(__METHOD__ . '::Exception::' . $e->getMessage());
            return false;
        }
    }

    public function listActiveAction()
    {
        try {
            if (!$this->opendb())
                return '0';
            $time = $this->request->getPost('time');
            // $stmt = $this->db->prepare('SELECT count(*) as total FROM users_cache WHERE created>:created and deleted=0');
            $stmt = $this->db->prepare('select count(*) as total from (select distinct username,ip_address  FROM users_cache WHERE deleted=0 and hostname=0)');
           //  $stmt->bindValue(':created', ($time / 1000));
            if (!$results = $stmt->execute())
                return '0';
            $row = $results->fetchArray($mode = SQLITE3_ASSOC);
            return "{$row['total']}";
        } catch (\Exception $e) {
            $this->sensei->logger(__METHOD__ . '::Exception::' . $e->getMessage());
            return '0';
        }
        catch (\Exception $e) {
            $this->sensei->logger(__METHOD__ . '::Exception::' . $e->getMessage());
            return '0';
        }
    }

    public function queryAction()
    {
        try {
            if (!$this->opendb())
                return ['data' => []];
            $query = $this->request->getPost('query', null, []);
            $sorter = $this->request->getPost('sorter', null, []);

            $params = [];
            $values = [];

            foreach ($query as $key => $value) {

                if ($key == 'gte') {
                    if (intval($value) > 0) {
                        $params[] = ' created>:gte ';
                        $values[':gte'] = $value / 1000;
                    }
                } else if ($key == 'lte') {
                    if (intval($value) > 0) {
                        $params[] = ' created<:lte ';
                        $values[':lte'] = $value / 1000;
                    }
                } else {
                    $params[] = $key . ':' . md5($key);
                    $values[':' . md5($key)] = str_replace('*', '%', $value);
                }


            }

            $sql = 'SELECT * FROM users_cache';
            if (count($params) > 0)
                $sql .= ' where ' . implode(' and ', $params);

            if (count($query) > 0)
                $sql .= ' order by  ' . $sorter['field'] . ' ' . $sorter['asc'];

            $stmt = $this->db->prepare($sql);

            foreach ($values as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            if (!$results = $stmt->execute())
                return '0';
            $response = [];
            while ($row = $results->fetchArray($mode = SQLITE3_ASSOC))
                $response[] = $row;
            return ['data' => $response];
        } catch (\Exception $e) {
            $this->sensei->logger(__METHOD__ . '::Exception::' . $e->getMessage());
            return ['data' => []];
        }
        catch (\Exception $e) {
            $this->sensei->logger(__METHOD__ . '::Exception::' . $e->getMessage());
            return ['data' => []];
        }
    }

}