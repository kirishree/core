<?php

namespace OPNsense\Sensei\Api;

use Phalcon\Config\Adapter\Ini as ConfigIni;
use \OPNsense\Base\ApiControllerBase;
use \OPNsense\Sensei\Sensei;
# use Phalcon\Mvc\Controller;
use \OPNsense\Sensei\SenseiMongoDB;

class QueryController extends ApiControllerBase
{
    private $log = [];

    const log_file = '/usr/local/sensei/log/active/Senseigui.log';

    const settingsdb = '/usr/local/sensei/userdefined/config/settings.db';

    const eastpectcfg = '/usr/local/sensei/etc/eastpect.cfg';

    private function execQuery($url, $data, $config, $s = 0)
    {
        $curl = null;

        try {
            if ($config->Database->type == 'SQ') {
                $response = [];
                $sensei = new Sensei();
                $total = 0;
                try {
                    $data = json_decode($data, true);
                    $index = explode("_", $url)[0];
                    $query = "";
                    //                    $sensei->logger(__METHOD__ . ' Starting...' . var_export($url, true) . ":" . var_export($data, true));
                    $sensei->logger(__METHOD__ . ' Starting...execQuery SQLITE');
                    if (isset($data['activity']) && $data['activity'] == true) {
                        $activity_type = 'app';
                        if (!empty($data['activity_type'])) {
                            $activity_type = $data['activity_type'];
                        }
                        $query = 'select count(*) as total,round(start_time/3600000) as interval,src_hostname,dst_hostname,' . ($activity_type == 'app' ? 'app_name' : 'tags') . ' as label,sum(src_nbytes) as byte_in,sum(dst_nbytes) as byte_out from conn_all where start_time>__GTE__ and start_time<__LTE__ and src_dir="EGRESS" and transport_proto in ("TCP","TCP6") group by 2,3,4,5 order by 3,5';
                    }
                    $chart_file = Sensei::template_chart_path . "queriesSq/" . (empty($data['index_path']) ? $index : $data['index_path']) . "/" . $data["chart"] . ".sql";

                    if ($data['explorer'] == true) {
                        $query = "select * from $index" . "_all where start_time>__GTE__ and start_time<__LTE__ __WHERE__ order by __ORDER__ limit __SIZE__ COLLATE NOCASE";
                    }

                    if ($data['chart'] != '') {
                        if (file_exists($chart_file)) {
                            $query = file_get_contents($chart_file);
                        } else {
                            $sensei->logger(__METHOD__ . ' SQ Exception -> ' . $chart_file . ' does not exist.');
                        }
                    }
                    if (empty($query)) {
                        $sensei->logger(__METHOD__ . ' SQ query is empty.');
                        return ['data' => $response, 'count' => count($response), 'total' => $total, 'error' => ''];
                    }

                    if (isset($data['sort'])) {
                        $query = str_replace('__ORDER__', $data['sort']['field'] . " " . $data['sort']['order'], $query);
                    } else {
                        $query = str_replace('__ORDER__', "1", $query);
                    }
                    if (isset($data['limit'])) {
                        $query = str_replace('__SIZE__', $data['limit'], $query);
                    }
                    if (isset($data['interval'])) {
                        $query = str_replace('__INTERVAL__', $data['interval'], $query);
                    }
                    if (isset($data['time']) && isset($data['time']['start_time']) && isset($data['time']['start_time']['__GTE__'])) {
                        $query = str_replace('__GTE__', $data['time']['start_time']['__GTE__'], $query);
                    }
                    if (isset($data['time']) && isset($data['time']['start_time']) && isset($data['time']['start_time']['__LTE__'])) {
                        $query = str_replace('__LTE__', $data['time']['start_time']['__LTE__'], $query);
                    }
                    if (isset($data['sum'])) {
                        if ($index == "conn") {
                            if ($data['sum'] == 'volume') {
                                $query = str_replace('count(*)', "sum(dst_nbytes)", $query);
                            }
                            if ($data['sum'] == 'packet') {
                                $query = str_replace('count(*)', "sum(dst_npackets)", $query);
                            }
                        }
                        if ($index == "http" && $data['sum'] == 'volume') {
                            $query = str_replace('count(*)', "sum(rsp_body_len)", $query);
                        }
                    }
                    $w = "";
                    $where = [];
                    if (isset($data['where'])) {
                        if (is_array($data['where'])) {
                            foreach ($data['where'] as $k => $v) {
                                if (is_string($v)) {
                                    if ($k == 'dst_hostname' || $k == 'src_hostname')
                                        $v = strtolower($v);

                                    //$where[] = $k . '="' . ucwords(strtolower($v)) . '"';
                                    $where[] = $k . '="' . $v . '"';
                                }
                                if (is_int($v)) {
                                    $where[] = $k . '=' . $v;
                                }
                            }
                        }
                    }
                    if (isset($data['where_not'])) {
                        if (is_array($data['where_not'])) {
                            foreach ($data['where_not'] as $k => $v) {
                                if (is_string($v)) {
                                    if ($k == 'dst_hostname' || $k == 'src_hostname')
                                        $v = strtolower($v);
                                    //$where[] = $k . '!="' . ucwords(strtolower($v)) . '"';
                                    $where[] = $k . '!="' . $v . '"';
                                }
                                if (is_int($v)) {
                                    $where[] = $k . '!=' . $v;
                                }
                            }
                        }
                    }

                    if (count($where) > 0) {
                        if ($index == 'conn' && strpos($query, 'conn_all_security_tags') !== false)
                            $w = " and conn_uuid in (select conn_uuid from conn_all where " . implode(' and ', $where) . ")";
                        else
                            $w = " and (" . implode(' and ', $where) . ")";
                    }

                    $query = str_replace('__WHERE__', $w, $query);
                    $db_path = Sensei::sqlite_path . $index . '_all.sqlite';
                    $sensei->logger($db_path . ":" . $query);
                    if (file_exists($db_path)) {
                        $sqlite = new \SQLite3($db_path);
                        $sqlite->busyTimeout(5000);
                        $sqlite->exec('PRAGMA journal_mode = wal;');
                        $rows = $sqlite->query($query);
                        if ($rows === false) {
                            return ['data' => $response, 'count' => count($response), 'total' => $total, 'error' => 'Query return value is false'];
                        }
                        while ($row = $rows->fetchArray($mode = SQLITE3_ASSOC)) {
                            $response[] = $row;
                            if (isset($row['total'])) {
                                $total += $row['total'];
                            }
                        }
                        $sqlite->close();
                        if (isset($data['activity']) && $data['activity'] == true) {
                            $activity_response = [];
                            foreach ($response as $k => $v) {
                            }
                        }
                    } else {
                        $sensei->logger(__METHOD__ . ' SQ Exception -> ' . $db_path . ' does not exist.');
                    }

                    return ['data' => $response, 'count' => count($response), 'total' => $total, 'error' => ''];
                } catch (\Exception $th) {
                    $sensei->logger(__METHOD__ . ' PATH: ' . $db_path . ' SQ Exception -> ' . $th->getMessage());
                    return ['data' => $response, 'count' => count($response), 'total' => $total, 'error' => $th->getMessage()];
                }
            }
            if ($config->Database->type == 'MN') {
                $senseiMongodb = new SenseiMongoDB();
                $e = microtime(true);
                $this->log[] = 'Loading Mongodb -> ' . round($e - $s, 2);
                return $senseiMongodb->executeQuery(explode('/', $url)[0], $data, $s);
            }
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $config->ElasticSearch->apiEndPointIP . '/' . $config->ElasticSearch->apiEndPointPrefix . $url);
            curl_setopt($curl, CURLOPT_PORT, $config->ElasticSearch->apiEndPointPort);
            curl_setopt($curl, CURLOPT_HEADER, 0);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($curl, CURLOPT_TIMEOUT, 40);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
            if (!empty($config->ElasticSearch->apiEndPointUser)) {
                $apiEndPointPass = $config->ElasticSearch->apiEndPointPass;
                if (substr($config->ElasticSearch->apiEndPointPass, 0, 4) == 'b64:') {
                    $apiEndPointPass = base64_decode(substr($config->ElasticSearch->apiEndPointPass, 4));
                }

                curl_setopt($curl, CURLOPT_USERPWD, $config->ElasticSearch->apiEndPointUser . ':' . $apiEndPointPass);
            }
            curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
            ));
            if ($data) {
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            }
            $results = curl_exec($curl);
            $e = microtime(true);
            $this->log[] = 'Curl Executed -> ' . round($e - $s, 2);
            $s = $e;

            if ($results === false) {
                $this->response->setStatusCode(504, 'Gateway Timeout');
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $results, FILE_APPEND);
                $results = 'Query timeout expired!';
            } elseif (curl_getinfo($curl, CURLINFO_HTTP_CODE) >= 400) {
                $this->response->setStatusCode(503, 'Service Unavailable');
            } else {
                $this->response->setHeader('Content-Type', 'application/json; charset=utf-8');
            }
            curl_close($curl);
            return $results;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            // TODO: i have to strong test
            if ($curl != null) {
                curl_close($curl);
            }

            return [];
        }
    }

    public function proxyAction()
    {
        try {
            $this->log = [];
            $s = microtime(true);

            $config = new ConfigIni(self::eastpectcfg);

            $e = microtime(true);
            $this->log[] = PHP_EOL . 'Proxy Loaded -> ' . round($e - $s, 2);
            if (!isset($_POST['url']) || !isset($_POST['data'])) {
                file_put_contents(self::log_file, __METHOD__ . '::WARNING::Missing POST parameters -> ' . var_export($_POST, true), FILE_APPEND);
                return [];
            }
            $url = $_POST['url'];
            $data = $_POST['data'];
            $results = $this->execQuery($url, $data, $config, $s);
            header('Content-type:application/json;charset=utf-8');
            if (is_array($results)) {
                echo json_encode($results);
            } else {
                echo $results;
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return [];
        }
    }

    private function activityES($httpList, $tlsList, $connList)
    {
        try {
            $list = [];
            $conn = [];
            $web = [];
            if (is_array($connList)) {
                foreach ($connList['hits']['hits'] as $key => $source) {
                    $conn[$source['_source']['conn_uuid']] = ['size_in' => $source['_source']['src_nbytes'], 'size_out' => $source['_source']['dst_nbytes'], 'start_time' => date('m/d/Y H:i:s', $source['_source']['start_time'] / 1000), 'duration' => gmdate("H:i:s", $source['_source']['end_time'] - $source['_source']['start_time'])];
                }
            }
            if (is_array($httpList)) {
                foreach ($httpList['hits']['hits'] as $key => $source) {
                    if (!empty($conn[$source['_source']['conn_uuid']])) {
                        $tmp = $conn[$source['_source']['conn_uuid']];
                        $tmp['host'] = $source['_source']['host'];
                        $tmp['proto'] = 'http';
                        $tmp['category'] = $source['_source']['category'];
                        $list[] = $tmp;
                        if (empty($web[$tmp['host']])) {
                            $web[$tmp['host']] = 1;
                        } else {
                            $web[$tmp['host']]++;
                        }
                    }
                }
            }
            if (is_array($tlsList)) {
                foreach ($tlsList['hits']['hits'] as $key => $source) {
                    if (!empty($conn[$source['_source']['conn_uuid']])) {
                        $tmp = $conn[$source['_source']['conn_uuid']];
                        $tmp['host'] = $source['_source']['server_name'];
                        $tmp['proto'] = 'https';
                        $tmp['category'] = $source['_source']['category'];
                        $list[] = $tmp;
                        if (empty($web[$tmp['host']])) {
                            $web[$tmp['host']] = 1;
                        } else {
                            $web[$tmp['host']]++;
                        }
                    }
                }
            }
            foreach ($list as $k => $v) {
                $list[$k]['visit'] = $web[$list[$k]['host']];
            }
            return $list;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return [];
        }
    }

    private function activityMN($httpList, $tlsList, $connList)
    {
    }

    public function proxyActivityAction()
    {
        try {
            $this->log = [];
            $s = microtime(true);

            $config = new ConfigIni(self::eastpectcfg);

            $e = microtime(true);
            $this->log[] = PHP_EOL . 'Proxy Activity Loaded -> ' . round($e - $s, 2);

            $url = 'http_all/_search';
            $data = $_POST['data'];

            $httpList = $this->execQuery($url, $data, $config, $s);
            $httpList = json_decode($httpList, true);
            $url = 'tls_all/_search';
            $tlsList = $this->execQuery($url, $data, $config, $s);
            $tlsList = json_decode($tlsList, true);
            $url = 'conn_all/_search';
            $connList = $this->execQuery($url, $data, $config, $s);
            $connList = json_decode($connList, true);
            if ($config->Database->type == 'MN') {
                return ['data' => $this->activityMN($httpList, $tlsList, $connList), 'error' => ''];
            }
            return ['data' => $this->activityES($httpList, $tlsList, $connList), 'error' => ''];
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return ['data' => '', 'error' => $e->getMessage()];
        }
    }

    public function policyAction()
    {
        try {
            $database = new \SQLite3(self::settingsdb);
            $database->busyTimeout(5000);
            $database->exec('PRAGMA journal_mode = wal;');
            $policies = $database->query('SELECT * FROM policies order by name');
            $response = [];
            while ($row = $policies->fetchArray($mode = SQLITE3_ASSOC)) {
                $response[] = ['name' => $row['name'], 'id' => $row['id']];
            }
            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return [];
        }
    }
    public function allPolicyAction()
    {
        try {
            $database = new \SQLite3(self::settingsdb);
            $database->busyTimeout(5000);
            $database->exec('PRAGMA journal_mode = wal;');
            $response = [];
            $policies = $database->query('SELECT * FROM policies');
            while ($row = $policies->fetchArray($mode = SQLITE3_ASSOC)) {
                $row['security'] = true;
                $row['app'] = false;
                $row['web'] = true;
                $row['tls'] = false;
                $schedules = $database->query('select s.name,s.description from policies_schedules p, schedules s where p.schedule_id=s.id and p.policy_id=' . $row['id']);
                $schedules_arr = [];
                while ($row_s = $schedules->fetchArray($mode = SQLITE3_ASSOC)) {
                    $schedules_arr[] = $row_s['name'] . '|' . $row_s['description'];
                }
                $row['schedules'] = implode('<br>', $schedules_arr);
                array_push($response, $row);
            }
            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return [];
        }
    }
}
