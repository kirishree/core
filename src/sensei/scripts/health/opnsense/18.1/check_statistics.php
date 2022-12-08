<?php

/**
 * Project Owner : sunnyvalley.io
 * Coder         : hasan@sunnyvalley.io
 * Date          : Feb 21, 2019
 */
function array_search_key($needle_key, $array)
{
    foreach ($array as $key => $value) {
        if ($needle_key == $key)
            return $value;
        if (is_array($value)) {
            $return = array_search_key($needle_key, $value);
            if ($return != -1)
                return $return;
        }
    }
    return -1;
}

function proxy($url, $data, $method = 'GET')
{
    $config = parse_ini_file("/usr/local/sensei/etc/eastpect.cfg", true, INI_SCANNER_RAW);
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $config['ElasticSearch']['apiEndPointIP'] . '/' . $config['ElasticSearch']['apiEndPointPrefix'] . $url);
    curl_setopt($curl, CURLOPT_PORT, $config['ElasticSearch']['apiEndPointPort']);
    curl_setopt($curl, CURLOPT_HEADER, 0);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($curl, CURLOPT_TIMEOUT, 40);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
    if (!empty($config['ElasticSearch']['apiEndPointUser'])) {
        $apiEndPointPass = $config['ElasticSearch']['apiEndPointPass'];
        if (substr($config['ElasticSearch']['apiEndPointPass'], 0, 4) == 'b64:')
            $apiEndPointPass = base64_decode(substr($config['ElasticSearch']['apiEndPointPass'], 4));
        curl_setopt($curl, CURLOPT_USERPWD, $config['ElasticSearch']['apiEndPointUser'] . ':' . $apiEndPointPass);
    }
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json'
    ));

    if ($data) {
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    }
    $results = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($http_code == 200) {
        return $results;
    } else {
        return -1;
    }
}

$pid_dir = "/var/spool/sensei";
$pid_file = $pid_dir . "/stat.pid";
// hour
$interval = 24;
// true / false
$getStat = false;

// catch error
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new RuntimeException($errstr . " on line " . $errline . " in file " . $errfile);
});

if (!file_exists($pid_dir)) {
    mkdir($pid_dir, 0755, true);
}

if (file_exists($pid_file)) {
    $finfo = stat($pid_file);
    if ($finfo['mtime'] + (1 * 60 * 60) < time()) {
        $getStat = true;
    }
} else {
    $getStat = true;
}

if ($getStat == false) {
    echo file_get_contents($pid_file);
    exit(0);
}

function elastic_query()
{
    global $interval, $pid_file;
    // distint source ips  for sourcer ips
    $data = '{
        "size" : 0,
        "aggs" : {
        "dist_count" : {
            "terms" : {
                "field" : "ip_src_saddr.keyword",
                "size": 2147483630
            }
        }
    },
    "query": {
        "bool": {
            "must": [{
                "range": {
                    "start_time": {
                        "gte": ' . ((time() - ($interval * 60 * 60)) * 1000) . ',
                                "format": "epoch_millis"
                            }
                        }
                    },{"match_phrase":{"src_dir":{"query":"EGRESS"}}}],
                    "should" : [
                        { "term" : { "transport_proto.keyword" : "XXX" } },
                        { "term" : { "transport_proto.keyword" : "YYY" } }
                    ],
                    "minimum_should_match" : 1
                }
            }
        }';

    // for search
    try {

        $count4 = 0;
        $count6 = 0;
        $distinct_ip_src_saddr = 'ip_src_saddr.keyword_count';
        $url = 'conn_all/_search';

        //get ipv4 distinct count
        $query = str_replace(['XXX', 'YYY'], ['TCP', 'UDP'], $data);
        var_export($query);
        $results = proxy($url, $query);
        if ($results != -1) {
            $resultObj = json_decode($results);
            $count4 = count($resultObj->aggregations->dist_count->buckets);
            file_put_contents($pid_file, "$count4,$count6");
        } else {
            print "$count4,$count6";
            exit($results);
        }

        //get ipv6 distinct count
        $query = str_replace(['XXX', 'YYY'], ['TCP6', 'UDP6'], $data);
        $results = proxy($url, $query);
        if ($results != -1) {
            $resultObj = json_decode($results);
            $count6 = count($resultObj->aggregations->dist_count->buckets);
            file_put_contents($pid_file, "$count4,$count6");
        } else {
            print "$count4,$count6";
            exit($results);
        }
        print "$count4,$count6";
    } catch (Exception $exc) {
        echo $exc->getMessage();
        exit(100);
    }
}

function mongo_query()
{
    global $interval;
    try {
        $query_filename = '/usr/local/sensei/scripts/health/opnsense/18.1/mongo_query.json';
        $command = "mongo --quiet sensei < /tmp/mongo_query.json > /tmp/mongo_result.json";
        $contents = file_get_contents($query_filename);
        $contents = str_replace("%%TIMESTAMP%%", (time() - ($interval * 60 * 60)) * 1000, $contents);
        file_put_contents("/tmp/mongo_query.json", $contents);
        system($command, $return_val);
        $count4 = $count6 = 0;
        if ($return_val == 0 && filesize('/tmp/mongo_result.json') > 0) {
            $result = trim(file_get_contents('/tmp/mongo_result.json'));
            $list = explode(PHP_EOL, $result);
            $count4 = isset($list[0]) ? $list[0] : 0;
            $count6 = isset($list[1]) ? $list[1] : 0;
        }
        print "$count4,$count6";
    } catch (Exception $exc) {
        echo $exc->getMessage();
        exit(100);
    }
}

function sqlite_query()
{
    global $interval;
    try {
        $count4 = $count6 = 0;
        $ts = (time() - ($interval * 60 * 60)) * 1000;
        $conn_file = '/usr/local/datastore/sqlite/conn_all.sqlite';
        if (file_exists($conn_file)) {
            $dbhandle = new SQLite3($conn_file);
            $query = sprintf("select count(distinct ip_src_saddr) as total from conn_all where start_time>%d and src_dir='EGRESS' and transport_proto in ('TCP','UDP')", $ts);
            $result = $dbhandle->query($query);
            $row = $result->fetchArray($mode = SQLITE3_ASSOC);
            if (!empty($row) && isset($row['total'])) {
                $count4 = $row['total'];
            }

            $query = sprintf("select count(distinct ip_src_saddr) as total from conn_all where start_time>%d and src_dir='EGRESS' and transport_proto in ('TCP6','UDP6')", $ts);
            $result = $dbhandle->query($query);
            $row = $result->fetchArray($mode = SQLITE3_ASSOC);
            if (!empty($row) && isset($row['total'])) {
                $count6 = $row['total'];
            }
        }
        print "$count4,$count6";
    } catch (Exception $exc) {
        echo $exc->getMessage();
        exit(100);
    }
}


if ($argc != 2) {
    print "0,0";
    exit(11);
}

if (strtolower($argv[1]) == 'elastic')
    elastic_query();

if (strtolower($argv[1]) == 'mongo')
    mongo_query();

if (strtolower($argv[1]) == 'sqlite')
    sqlite_query();
