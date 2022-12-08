<?php

namespace OPNsense\Sensei\Api;

use Exception;
use Phalcon\Config\Adapter\Ini as ConfigIni;
use Phalcon\Mvc\Controller;
use \OPNsense\Sensei\Sensei;

class WidgetController extends Controller
{

    const log_file = '/usr/local/sensei/log/active/Senseigui.log';

    private function human_filesize($bytes, $decimals = 2)
    {
        $sz = 'BKMGTP';
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[$factor];
    }

    private function dbEngineStatus($dbtype = 'ES')
    {
        $engine_status = '<span class="text-danger">Stopped</span>';
        $db_status = '<span class="text-danger">Stopped</span>';
        exec('service eastpect onestatus', $output, $return_val);
        if ($return_val == 0) {
            if (strpos(strtolower($output[0]), "running") !== false) {
                $engine_status = '<span class="text-success">Running</span>';
            }
        }
        switch ($dbtype) {
            case 'ES':
                $db_deamon = 'elasticsearch';
                $db_name = 'Elasticsearch';
                break;
            case 'MN':
                $db_deamon = 'mongod';
                $db_name = 'Mongodb';
                break;
            case 'SQ':
                $db_deamon = 'sqlite';
                $db_name = 'SQLite';
                $db_status = '<span class="text-success">Running</span>';
                break;
            default:
                break;
        }
        if ($dbtype == 'MN' || $dbtype == 'ES') {
            exec('service ' . $db_deamon . ' onestatus', $output, $return_val);
            if ($return_val == 0) {
                if (strpos(strtolower($output[0]), "running") !== false) {
                    $db_status = '<span class="text-success">Running</span>';
                }
            }
        }
        return [$engine_status, $db_name, $db_status];
    }

    private function esProxy($url, $data, $config)
    {
        try {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $config->ElasticSearch->apiEndPointIP . '/' . $config->ElasticSearch->apiEndPointPrefix . $url);
            curl_setopt($curl, CURLOPT_PORT, $config->ElasticSearch->apiEndPointPort);
            curl_setopt($curl, CURLOPT_HEADER, 0);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 1);
            curl_setopt($curl, CURLOPT_TIMEOUT, 20);
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

            if ($results === false) {
                curl_close($curl);
                return false;
            } elseif (curl_getinfo($curl, CURLINFO_HTTP_CODE) >= 400) {
                curl_close($curl);
                return false;
            }

            curl_close($curl);
            return json_decode($results, true);
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return false;
        }
    }

    private function mnProxy($query, $return = false)
    {
        try {
            $random = uniqid('mongodb_dahsboard');
            $query_filename = '/tmp/' . $random . '.json';
            $result_filename = '/tmp/' . $random . '_result.json';
            $command = "mongo --quiet sensei < $query_filename > $result_filename";
            file_put_contents($query_filename, $query);
            system($command, $return_val);
            if ($return_val != 0) {
                if (file_exists($query_filename)) {
                    unlink($query_filename);
                }

                if (file_exists($result_filename)) {
                    unlink($result_filename);
                }

                return false;
            }
            $response = [];
            if (file_exists($result_filename) && $return_val == 0 && filesize($result_filename) > 0) {
                $result = trim(file_get_contents($result_filename));
                if ($return) {
                    return $result;
                }

                $list = explode(PHP_EOL, $result);
                foreach ($list as $key => $val) {
                    $obj = json_decode($val, true);
                    if (!empty($obj['_id'])) {
                        $response[] = $obj['_id'];
                    }
                }
            }
            if (file_exists($query_filename)) {
                unlink($query_filename);
            }

            if (file_exists($result_filename)) {
                unlink($result_filename);
            }

            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return [];
        }
    }

    public function sqProxy($table, $queries)
    {
        $repoDir = "/usr/local/datastore/sqlite/";
        $sqfile = $repoDir . $table . "_all.sqlite";
        $response = [];
        if (file_exists($sqfile)) {
            $db = new \SQLite3($sqfile);
            $db->busyTimeout(10000);
            if (is_array($queries)) {
                foreach ($queries as $query) {
                    $resp = [];
                    $stmt = $db->prepare($query);
                    if ($results = $stmt->execute()) {
                        while ($row = $results->fetchArray($mode = SQLITE3_NUM)) {
                            $resp[] = $row[0];
                        }
                    }
                    $response[] = $resp;
                }
            }
            if (is_string($queries)) {
                $stmt = $db->prepare($queries);
                if ($results = $stmt->execute()) {
                    while ($row = $results->fetchArray($mode = SQLITE3_NUM))
                        $resp[] = $row;
                }
                return $resp;
            }
            $db->close();
        }
        return $response;
    }

    public function getJson($result)
    {
        $response = [];
        try {
            $list = explode(PHP_EOL, $result);
            foreach ($list as $key => $val) {
                $obj = json_decode($val, true);
                if (!empty($obj['_id'])) {
                    $response[] = $obj['_id'];
                }
            }
            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return [];
        }
    }

    public function indexAction()
    {
        # error_reporting(E_ERROR);
        $sensei = new Sensei();
        $rootpath = '/usr/local/sensei';
        $user_dbfile = $rootpath . '/userdefined/db/Usercache/userauth_cache.db';
        $start_time_1 = time() - (1 * 60 * 60);
        $start_time_24 = time() - (24 * 60 * 60);
        $end_time = time() * 1000;
        $listActiveUser = 0;
        $rows = [
            'engine' => // 0
            [
                'key' => 'Zenarmor Packet Engine',
                'value' => null,
            ], 'topblocks' => [ // 1
                'key' => 'Top Blocks',
                'value' => '',
            ], 'topapps' => [ // 2
                'key' => 'Top Apps',
                'value' => '',
            ], 'topwebcategories' => [ // 3
                'key' => 'Top Web Categories',
                'value' => '',
            ], 'topauthuses' => [ // 4
                'key' => 'Top Auth Users',
                'value' => '',
            ], 'toplocalhost' => // 5
            [
                'key' => 'Top Local Hosts',
                'value' => 0,
            ], 'activeuses' => // 6
            [
                'key' => 'Active Users',
                'value' => 0,
            ], 'uniquelocalipaddress' => // 7
            [
                'key' => 'Unique Local Ip Address',
                'value' => 0,
            ], 'uniqueremoteipaddress' => [ // 8
                'key' => 'Unique Remote Ip Address',
                'value' => 0,
            ], 'database' => // 9
            [
                'key' => 'database',
                'value' => null,
            ], 'uniquelocaldevices' => // 10
            [
                'key' => 'Unique Local Devices',
                'value' => 0,
            ],
        ];
        try {
            if (file_exists($user_dbfile)) {
                $db = new \SQLite3($user_dbfile);
                $db->busyTimeout(10000);
                $db->exec('PRAGMA journal_mode = wal;');

                $stmt = $db->prepare('select count(*) as total from (select distinct username,ip_address  FROM users_cache WHERE deleted=0 and hostname=0)');
                //  $stmt->bindValue(':created', $start_time);
                if ($results = $stmt->execute()) {
                    $row = $results->fetchArray($mode = SQLITE3_ASSOC);
                    $listActiveUser = $row['total'];
                }
                $db->close();
            }

            $config = new ConfigIni('/usr/local/sensei/etc/eastpect.cfg');
            $tmp = $this->dbEngineStatus($config->Database->type);
            $rows['engine']['value'] = $tmp[0];
            $rows['database']['key'] = $tmp[1];
            $rows['database']['value'] = $tmp[2];
            $rows['activeuses']['value'] = $listActiveUser;

            if ($config->Database->type == 'MN') {
                system('rm -rf /tmp/mongodb_dahsboard*');
                $qf = '/usr/local/sensei/scripts/datastore/mongodb_widget.js';
                if (!file_exists($qf)) {
                    exit();
                }

                $query_content = file_get_contents($qf);
                $query_content = str_replace('__start_time_1__', $start_time_1, $query_content);
                $query_content = str_replace('__start_time_24__', $start_time_24, $query_content);
                $response = $this->mnProxy($query_content, true);
                if ($response === false || (is_array($response) && count($response) == 0)) {
                    return $this->response->setJsonContent($rows, JSON_UNESCAPED_UNICODE)->send();
                }

                $data_list = explode('--------', $response);
                $data_list = array_map('trim', $data_list);
                //top 5 ip
                $query = 'db.conn_all.aggregate([{"$match": {"start_time": { "$gte": ' . $start_time_1 . '000 }}},{"$group" : {_id:"$ip_src_saddr", count:{$sum:1}}},{$sort:{"count":-1}},{"$limit": 5}])';
                // $response = $this->mnProxy($query);
                $rows['toplocalhost']['value'] = implode(', ', $this->getJson($data_list[0]));
                //top block ip
                $query = 'db.alert_all.aggregate([{"$match": {"start_time": { "$gte": ' . $start_time_1 . '000 }}},{"$group" : {_id:"$message", count:{$sum:1}}},{$sort:{"count":-1}},{"$limit": 5}])';
                // $response = $this->mnProxy($query);
                $rows['topblocks']['value'] = implode(', ', $this->getJson($data_list[1]));
                // count of distinct src_saddr
                $query = 'db.conn_all.distinct("ip_src_saddr",{"start_time": { $gt: ' . $start_time_24 . '000 },"src_dir": "EGRESS"}).length';
                // $response = $this->mnProxy($query, true);
                $rows['uniquelocalipaddress']['value'] = $data_list[2];
                // count of distinct dst_saddr
                $query = 'db.conn_all.distinct("ip_dst_saddr",{"start_time": { $gt: ' . $start_time_24 . '000 },"src_dir": "EGRESS"}).length';
                // $response = $this->mnProxy($query, true);
                $rows['uniqueremoteipaddress']['value'] = $data_list[3];
                // distinct device
                // $query = 'db.conn_all.distinct("ip_src_saddr",{"start_time": { $gt: ' . $start_time_24 . '000 },"src_dir": "EGRESS","transport_proto": { $in: ["TCP","UDP"]}}).length';
                // $response = $this->mnProxy($query, true);
                $rows['uniquelocaldevices']['value'] = $sensei->getNumberofDevice();
                //top webcat
                $query = 'db.http_all.aggregate([{"$match": {"start_time": { "$gte": ' . $start_time_1 . '000 }}},{"$group" : {_id:"$category", count:{$sum:1}}},{$sort:{"count":-1}},{"$limit": 5}])';
                $response_http = $data_list[5]; // $this->mnProxy($query, true);
                $query = 'db.tls_all.aggregate([{"$match": {"start_time": { "$gte": ' . $start_time_1 . '000 }}},{"$group" : {_id:"$category", count:{$sum:1}}},{$sort:{"count":-1}},{"$limit": 5}])';
                $response_tls = $data_list[6]; //$this->mnProxy($query, true);
                $response = [];
                if (!empty($response_http)) {
                    $list = explode(PHP_EOL, $response_http);
                    foreach ($list as $key => $val) {
                        $obj = json_decode($val, true);
                        if (!empty($obj['_id'])) {
                            $response[$obj['_id']] = isset($response[$obj['_id']]) ? $response[$obj['_id']] + $obj['count'] : $obj['count'];
                        }
                    }
                }
                if (!empty($response_tls)) {
                    $list = explode(PHP_EOL, $response_tls);
                    foreach ($list as $key => $val) {
                        $obj = json_decode($val, true);
                        if (!empty($obj['_id'])) {
                            $response[$obj['_id']] = isset($response[$obj['_id']]) ? $response[$obj['_id']] + $obj['count'] : $obj['count'];
                        }
                    }
                }
                if (count($response) > 0) {
                    arsort($response);
                    $response = array_slice($response, 0, 5);
                    $rows['topwebcategories']['value'] = implode(', ', array_keys($response));
                }
                //top username
                $query = 'db.conn_all.aggregate([{"$match": {"start_time": { "$gte": ' . $start_time_1 . '000 }}},{"$group" : {_id:"$src_username", count:{$sum:1}}},{$sort:{"count":-1}},{"$limit": 5}])';
                // $response = $this->mnProxy($query);
                $rows['topauthuses']['value'] = implode(', ', $this->getJson($data_list[7]));
                //top appcategory
                $query = 'db.conn_all.aggregate([{"$match": {"start_time": { "$gte": ' . $start_time_1 . '000 }}},{"$group" : {_id:"$app_category", count:{$sum:1}}},{$sort:{"count":-1}},{"$limit": 5}])';
                // $response = $this->mnProxy($query);
                $rows['topapps']['value'] = implode(', ', $this->getJson($data_list[8]));
                return $this->response->setJsonContent($rows, JSON_UNESCAPED_UNICODE)->send();
            }
            // elasticsearch
            if ($config->Database->type == 'ES') {
                $rows['database']['value'] = '<span class="text-danger">Stopped</span>';
                $url = 'conn_all/_search';
                $data = '{"size":1}';
                $response = $this->esProxy($url, $data, $config);
                if ($response != false) {
                    $rows['database']['value'] = '<span class="text-success">Running</span>';
                }

                //{"size":100,"sort":[{}],"query":{"bool":{"must":[{"range":{"start_time":{"gte":1572390029706,"lte":1572994829706,"format":"epoch_millis"}}}]}},"aggs":{"results":{"terms":{"field":"message.keyword","size":"25","order":{"_count":"desc"}}}}};
                $url = 'conn_all/_search';
                $data = '{"size":100,"sort":[{}],"query":{"bool":{"must":[{"range":{"start_time":{"gte":' . $start_time_1 . '000, "format":"epoch_millis"}}}]}},"aggs":{"results":{"terms":{"field":"ip_src_saddr.keyword","size":"5","order":{"_count":"desc"}}}}}';
                $response = $this->esProxy($url, $data, $config);
                if ($response != false) {
                    $blocks = [];
                    foreach ($response['aggregations']['results']['buckets'] as $k => $block) {
                        $blocks[] = $block['key'];
                    }
                    $rows['toplocalhost']['value'] = implode(', ', $blocks);
                }
                $data = '{"size":100,"sort":[{}],"query":{"bool":{"must":[{"range":{"start_time":{"gte":' . $start_time_24 . '000,"lte": ' . $end_time . '000,"format":"epoch_millis"}}},{"match_phrase":{"src_dir":{"query":"EGRESS"}}}]}},"aggs":{"uniquelocalipaddress":{"cardinality":{"field":"ip_src_saddr.keyword"}},"uniqueremoteipaddress":{"cardinality":{"field":"ip_dst_saddr.keyword"}}}}';
                $response = $this->esProxy($url, $data, $config);
                if ($response != false) {
                    $rows['uniquelocalipaddress']['value'] = $response['aggregations']['uniquelocalipaddress']['value'];
                    $rows['uniqueremoteipaddress']['value'] = $response['aggregations']['uniqueremoteipaddress']['value'];
                }
                // top blocks
                $url = 'alert_all/_search';
                $data = '{"size":100,"sort":[{}],"query":{"bool":{"must":[{"range":{"start_time":{"gte":' . $start_time_1 . '000,"lte":' . $end_time . '000,"format":"epoch_millis"}}}]}},"aggs":{"results":{"terms":{"field":"message.keyword","size":"5","order":{"_count":"desc"}}}}}';
                $response = $this->esProxy($url, $data, $config);
                if ($response != false) {
                    $blocks = [];
                    foreach ($response['aggregations']['results']['buckets'] as $k => $block) {
                        $blocks[] = $block['key'];
                    }
                    $rows['topblocks']['value'] = implode(', ', $blocks);
                }
                // top web cats
                $url = 'http_all/_search';
                $data = '{"size":100,"sort":[{}],"query":{"bool":{"must":[{"range":{"start_time":{"gte":' . $start_time_1 . '000,"lte":' . $end_time . '000,"format":"epoch_millis"}}}]}},"aggs":{"results":{"terms":{"field":"category.keyword","size":"5","order":{"_count":"desc"}}}}}';
                $response = $this->esProxy($url, $data, $config);
                $webCats = [];
                if ($response != false) {
                    foreach ($response['aggregations']['results']['buckets'] as $k => $category) {
                        if (!empty($category['key'])) {
                            $webCats[$category['key']] = $category['doc_count'];
                        }
                    }
                }
                $url = 'tls_all/_search';
                $data = '{"size":100,"sort":[{}],"query":{"bool":{"must":[{"range":{"start_time":{"gte":' . $start_time_1 . '000,"lte":' . $end_time . '000,"format":"epoch_millis"}}}]}},"aggs":{"results":{"terms":{"field":"category.keyword","size":"5","order":{"_count":"desc"}}}}}';
                $response = $this->esProxy($url, $data, $config);
                if ($response != false) {
                    foreach ($response['aggregations']['results']['buckets'] as $k => $category) {
                        if (!empty($category['key'])) {
                            $webCats[$category['key']] = isset($webCats[$category['key']]) ? $webCats[$category['key']] + $category['doc_count'] : $category['doc_count'];
                        }
                    }
                }
                arsort($webCats);
                $webCats = array_slice($webCats, 0, 5);
                $rows['topwebcategories']['value'] = implode(', ', array_keys($webCats));
                // top app category
                $url = 'conn_all/_search';
                $data = '{"size":100,"sort":[{}],"query":{"bool":{"must":[{"range":{"start_time":{"gte":' . $start_time_1 . '000,"lte":' . $end_time . '000,"format":"epoch_millis"}}}]}},"aggs":{"results":{"terms":{"field":"app_category.keyword","size":"5","order":{"sumresults":"desc"}},"aggs":{"sumresults":{"sum":{"field":"dst_nbytes"}}}},"sumtotal":{"sum":{"field":"dst_nbytes"}}}}';
                $response = $this->esProxy($url, $data, $config);
                if ($response != false) {
                    $appCats = [];
                    foreach ($response['aggregations']['results']['buckets'] as $k => $category) {
                        $appCats[] = $category['key'];
                    }
                    $rows['topapps']['value'] = implode(', ', $appCats);
                }

                // top ip address
                $url = 'conn_all/_search';
                $data = '{"size" : 0,"aggs" : {"results" : {"terms" : {"field" : "ip_src_saddr.keyword", "size" : 5}}},"query": {"bool": {"must": [{"range": {"start_time": {"gte": ' . $start_time_1 . '000,"format": "epoch_millis"}}},{"match_phrase":{"src_dir":{"query":"EGRESS"}}}]}}}';
                $response = $this->esProxy($url, $data, $config);
                if ($response != false) {
                    $localTopIp = [];
                    foreach ($response['aggregations']['results']['buckets'] as $k => $ip) {
                        $localTopIp[] = $ip['key'];
                    }
                    $rows['toplocalhost']['value'] = implode(', ', $localTopIp);
                }

                // top username
                $url = 'conn_all/_search';
                $data = '{"size" : 0,"aggs" : {"results" : {"terms" : {"field" : "src_username.keyword", "size" : 6}}},"query": {"bool": {"must": [{"range": {"start_time": {"gte": ' . $start_time_1 . '000,"format": "epoch_millis"}}},{"match_phrase":{"src_dir":{"query":"EGRESS"}}}]}}}';
                $response = $this->esProxy($url, $data, $config);
                if ($response != false) {
                    $localTopUser = [];
                    foreach ($response['aggregations']['results']['buckets'] as $k => $user) {
                        if (!empty($user['key'])) {
                            $localTopUser[] = $user['key'];
                        }
                    }
                    if (count($localTopUser) > 5) {
                        unset($localTopUser[5]);
                    }

                    $rows['topauthuses']['value'] = implode(', ', $localTopUser);
                }
                // top devices
                $rows['uniquelocaldevices']['value'] = $sensei->getNumberofDevice();
                /*
                $url = 'conn_all/_search';
                $data = '{"size":0,"sort":[{}],"query":{"bool":{"must":[{"range":{"start_time":{"gte": ' . $start_time_24 . '000,"format":"epoch_millis"}}},{"match_phrase":{"src_dir":{"query":"EGRESS"}}},{
                "bool": {
                    "should": [
                        {
                            "term": {
                                "transport_proto.keyword": "TCP"
                            }
                        },
                        {
                            "term": {
                                "transport_proto.keyword": "UDP"
                            }
                        }
                    ]
                }
            }]}},"aggs":{"results":{"terms":{"field":"ip_src_saddr.keyword","size":2147483647}}}}';
                $response = $this->esProxy($url, $data, $config);
                
                if ($response != false) {
                    $rows['uniquelocaldevices']['value'] = count($response['aggregations']['results']['buckets']);
                }
                */
            }

            // SQLite
            if ($config->Database->type == 'SQ') {
                $rows['database']['value'] = '<span class="text-success">Running</span>';
                $rows['uniquelocaldevices']['value'] = $sensei->getNumberofDevice();
                $query = [];
                $start_time_1 *= 1000;
                $query[] = "select ip_src_saddr,count(*) from conn_all where start_time>$start_time_1 group by ip_src_saddr order by 2 desc limit 5";
                $query[] = "select count(distinct ip_src_saddr) from conn_all where start_time>$start_time_1 and src_dir='EGRESS'";
                $query[] = "select count(distinct ip_dst_saddr) from conn_all where start_time>$start_time_1 and src_dir='EGRESS'";
                $query[] = "select src_username,count(*) from conn_all where start_time>$start_time_1 group by src_username order by 2 desc limit 5";
                $query[] = "select app_category,count(*) from conn_all where start_time>$start_time_1 group by app_category order by 2 desc limit 5";
                $response = $this->sqProxy('conn', $query);
                $rows['toplocalhost']['value'] = implode(', ', $response[0]);
                $rows['uniquelocalipaddress']['value'] = $response[1][0];
                $rows['uniqueremoteipaddress']['value'] = $response[2][0];
                $rows['topauthuses']['value'] = implode(', ', $response[3]);
                $rows['topapps']['value'] = implode(', ', $response[4]);
                $query = [];
                $query[] = "select message,count(*) from alert_all where start_time>$start_time_1 group by message order by 2 desc limit 5";
                $response = $this->sqProxy('alert', $query);
                $rows['topblocks']['value'] = implode(', ', $response[0]);

                $query = "select category,count(*) from http_all where start_time>$start_time_1 group by category order by 2 desc limit 5";
                $resp_http = $this->sqProxy('http', $query);
                $query = "select category,count(*) from tls_all where start_time>$start_time_1 group by category order by 2 desc limit 5";
                $resp_tls = $this->sqProxy('tls', $query);
                $response = [];
                foreach ($resp_http as $k => $v) {
                    $response[$v[0]] = $v[1];
                }

                foreach ($resp_tls as $k => $v) {
                    if (isset($response[$v[0]]))
                        $response[$v[0]] += $v[1];
                    else
                        $response[$v[0]] = $v[1];
                }
                krsort($response);
                $response = array_slice($response, 0, 5);
                $response = array_filter($response, function ($v, $k) {
                    return !is_null($k) && $k !== '';
                }, ARRAY_FILTER_USE_BOTH);
                $rows['topwebcategories']['value'] = implode(', ', array_keys($response));
                return $this->response->setJsonContent($rows, JSON_UNESCAPED_UNICODE)->send();
            }
        } catch (Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }
        }

        return $this->response->setJsonContent($rows, JSON_UNESCAPED_UNICODE)->send();
    }
}
