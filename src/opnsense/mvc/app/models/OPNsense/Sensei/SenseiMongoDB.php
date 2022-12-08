<?php

/**
 * Created by PhpStorm.
 * User: ureyni
 * Date: 24.04.2019
 * Time: 03:26
 */

namespace OPNsense\Sensei;

require_once "/usr/local/sensei/vendor/autoload.php";

# $loader = new \Phalcon\Loader();
# $loader->registerFiles([__DIR__ . "/vendor/autoload.php"]);

use Exception;
use MongoDB\Collection;
use MongoDB\Model\BSONDocument;
use MongoDB\BSON\ObjectId;

class SenseiMongoDB
{

    private $year;
    private $month;
    private $day;
    private $hour;
    private $minute;
    private $second;
    private $Collection;
    private $manager;

    const log_file = '/usr/local/sensei/log/active/Senseigui.log';

    private function convertInverval($parameters = '1s', $fieldName = 'start_time')
    {
        $this->year = ['$year' => ['$toDate' => $fieldName]];
        $this->month = ['$month' => ['$toDate' => $fieldName]];
        $this->day = ['$dayOfMonth' => ['$toDate' => $fieldName]];
        $this->hour = ['$hour' => ['$toDate' => $fieldName]];
        $this->minute = ['$minute' => ['$toDate' => $fieldName]];
        $this->second = ['$second' => ['$toDate' => $fieldName]];

        preg_match_all('/^(\d+)([A-za-z]{1})$/', $parameters, $match);
        $number = (int) $match[1][0];
        $interval = $match[2][0];


        switch ($interval) {
            case 's':
                if ($number > 1)
                    $this->second = ['$subtract' => [['$second' => ['$toDate' => $fieldName]], ['$mod' => [['$second' => ['$toDate' => $fieldName]], $number]]]];
                break;
            case 'm':
                $this->second = ['$second' => ['$toDate' => '1970-01-01']];
                if ($number > 1)
                    $this->minute = ['$subtract' => [['$minute' => ['$toDate' => $fieldName]], ['$mod' => [['$minute' => ['$toDate' => $fieldName]], $number]]]];
                break;
            case 'h':
                $this->second = ['$second' => ['$toDate' => '1970-01-01']];
                $this->minute = ['$minute' => ['$toDate' => '1970-01-01']];
                if ($number > 1)
                    $this->hour = ['$subtract' => [['$hour' => ['$toDate' => $fieldName]], ['$mod' => [['$hour' => ['$toDate' => $fieldName]], $number]]]];
                break;
            case 'd':
                $this->second = ['$second' => ['$toDate' => '1970-01-01']];
                $this->minute = ['$minute' => ['$toDate' => '1970-01-01']];
                $this->hour = ['$hour' => ['$toDate' => '1970-01-01']];
                if ($number > 1)
                    $this->day = ['$subtract' => [['$dayOfMonth' => ['$toDate' => $fieldName]], ['$mod' => [['$dayOfMonth' => ['$toDate' => $fieldName]], $number]]]];
                break;
            case 'M':
                $this->second = ['$second' => ['$toDate' => '1970-01-01']];
                $this->minute = ['$minute' => ['$toDate' => '1970-01-01']];
                $this->hour = ['$hour' => ['$toDate' => '1970-01-01']];
                $this->day = ['$dayOfMonth' => ['$toDate' => '1970-01-01']];
                if ($number > 1)
                    $this->month = ['$subtract' => [['$month' => ['$toDate' => $fieldName]], ['$mod' => [['$month' => ['$toDate' => $fieldName]], $number]]]];
                break;

            default:
                break;
        }
    }

    private function getListofInterval($query)
    {
        $response = [];
        $aggrt = [];
        if (isset($query['$group']['_id']['_interval'])) {
            unset($query['$group']['_id']['_interval']);
            $keys = array_keys($query['$group']['_id']);
            $fileName = str_replace('$', '', $query['$group']['_id'][$keys[0]]);
            $aggrt[]['$match'] = $query['$match'];
            if (isset($query['$group']))
                $aggrt[]['$group'] = $query['$group'];
            if (isset($query['sort']) && is_array($query['sort']))
                $aggrt[]['$sort'] = $query['sort'][0];
            if (isset($query['limit']))
                $aggrt[]['$limit'] = (int) $query['limit'];
            $cursor = $this->Collection->aggregate($aggrt);
            $list = [];
            file_put_contents('/tmp/parameters.txt', 'list : ' . var_export($aggrt, true), FILE_APPEND);
            foreach ($cursor as $document) {
                $tmp = (array) $document->bsonSerialize();
                foreach ($tmp as $key => $value) {
                    if (is_a($value, 'MongoDB\Model\BSONDocument'))
                        $tmp[$key] = (array) $value;
                }
                if (isset($tmp['_id']) && is_array($tmp['_id']))
                    $list[] = $tmp['_id'][$keys[0]];
            }
            $response = ['name' => $fileName, 'list' => $list];
        }
        return $response;
    }

    private function getCollectionList($query, $collection_name)
    {
        $mintime = time() - 86400;
        $maxtime = time();
        $response = [];
        $collect_part = substr($collection_name, 0, strpos($collection_name, '_') + 1);
        if (isset($query['start_time']['$gt'])) {
            $mintime = round($query['start_time']['$gt'] / 1000);
            if (date('ymd', $mintime) == date('ymd', $maxtime)) {
                $response[] = $collect_part . date('ymd', $mintime);
                return $response;
            }
            for ($index = $maxtime; $index > $mintime; $index = $index - 86400) {
                $response[] = $collect_part . date('ymd', $index);
            }
            return $response;
        } else {
            $database = new \MongoDB\Database($this->manager, 'sensei');
            $collections = $database->listCollections([
                'filter' => [
                    'name' => new \MongoDB\BSON\Regex('^' . $collect_part . '*'),
                ],
            ]);
            foreach ($collections as $collectionInfo) {
                $response[] = $collectionInfo->getName();
            }
            return $response;
        }
    }

    public function executeQuery($collection_name, $query, $stime)
    {
        error_reporting(0);
        $log = [];
        $s   = microtime(true);
        try {
            if (!class_exists('MongoDB\\Driver\\Manager')) {
                $log[] = 'MongoDB\Driver\Manager not exists it will be install';
                $version = substr(PHP_VERSION_ID, 0, 3);
                if ($version == '702')
                    $package = 'php72-pecl-mongodb';
                if ($version == '703')
                    $package = 'php73-pecl-mongodb';
                if ($version == '704')
                    $package = 'php74-pecl-mongodb';
                if (substr(PHP_VERSION_ID, 0, 2) == '80')
                    $package = 'php80-pecl-mongodb';
                exec('ps auxwww|grep pkg|grep -c ' . $package, $output, $retval);
                if ($retval == 0 && intval($output[0]) == 0)
                    exec('pkg install -y ' . $package . ';/usr/local/sbin/configctl webgui restart', $output, $retval);
                $log[] = implode(',', $output);
            }
            if (!class_exists('MongoDB\\Driver\\Manager')) {
                return ['data' => [], 'count' => 0, 'total' => 0, 'error' => ''];
            }

            $this->manager = new \MongoDB\Driver\Manager(
                "mongodb://localhost:27017",
                array('connectTimeoutMS' => 3000)
            );
            $e   = microtime(true);
            $log[] = 'Loading Manager -> ' . round($e - $s, 2);

            if (is_string($query))
                $query = json_decode($query, true);
            $options = [];
            $aggrt = [];
            $count = 0;
            $total = 0;
            $cursor = [];
            $cursors = [];
            $collectionList = [];
            if (isset($query['$match'])) {
                /*
                $aggrt = [];
                $aggrt[] =  array (    '$unwind' => '$security_tags');
                $aggrt[] =  array (    '$match' =>     array (      'start_time' =>       array (        '$gt' => 1571097272712,        '$lt' => 1601183672712,      ),    ),  );
                $aggrt[] =  array (    '$group' =>     array (      '_id' =>       array (        'security_tags' => '$security_tags',      ),      'total' =>       array (        '$sum' => 1,      ),    ),  );
                $aggrt[] =  array (    '$sort' =>     array (      'total' => -1,    ),  );
                $aggrt[] =  array (    '$limit' => 25  );
                $aggrt[] =  array (    '$skip' => 0  );
                */
                if (isset($query['$unwind']))
                    $aggrt[]['$unwind'] = $query['$unwind'];

                $aggrt[]['$match'] = $query['$match'];

                if (isset($query['$project'])) {
                    $aggrt[]['$project'] = $query['$project'];
                }
                if (isset($query['$group']))
                    $aggrt[]['$group'] = $query['$group'];

                if (isset($query['$groups']))
                    foreach ($query['$groups'] as $group)
                        $aggrt[]['$group'] = $group;

                if (isset($query['sort']) && is_array($query['sort']))
                    $aggrt[]['$sort'] = $query['sort'][0];
                if (isset($query['limit']))
                    $aggrt[]['$limit'] = (int) $query['limit'];
                if (isset($query['skip']))
                    $aggrt[]['$skip'] = $query['skip'];
                $e   = microtime(true);
                $log[] = 'Define variables -> ' . round($e - $s, 2);
                $this->Collection = new \MongoDB\Collection($this->manager, 'sensei', $collection_name);
                $list = $this->getListofInterval($query);
                if (count($list) > 0) {
                    $query['$match'][$list['name']] = ['$in' => $list['list']];
                    unset($query['limit']);
                }

                // file_put_contents('/tmp/match.txt', '----\n' . var_export($aggrt, true), FILE_APPEND);
                $cursors[] = $this->Collection->aggregate($aggrt);
                $e   = microtime(true);
                $log[] = 'End of Query 1 -> ' . round($e - $s, 2);
            } elseif (isset($query['find'])) {
                if (isset($query['sort']))
                    $options['sort'] = $query['sort'][0];
                if (isset($query['limit']))
                    $options['limit'] = $query['limit'];
                if (isset($query['skip']))
                    $options['skip'] = $query['skip'];
                if (isset($query['find']) && is_array($query['find']))
                    foreach ($query['find'] as $k => $v) {
                        if ($k == '_id')
                            $query['find']['_id'] = new ObjectId($v);
                        if (is_array($v) && isset($v['$regex']))
                            $query['find'][$k]['$regex'] = new \MongoDB\BSON\Regex($v['$regex'], 'i');
                    }

                $this->Collection = new \MongoDB\Collection($this->manager, 'sensei', $collection_name);
                $cursors[] = $this->Collection->find($query['find'], $options);
                file_put_contents(self::log_file, __METHOD__ . '::QUERY::' . var_export($query['find'], true), FILE_APPEND);
                $count += $this->Collection->count($query['find']);
                $log[] = 'End of Query 2 -> ' . round($e - $s, 2);
            }
            $response = [];
            foreach ($cursors as $cursor)
                foreach ($cursor as $document) {
                    $tmp = (array) $document->bsonSerialize();
                    foreach ($tmp as $key => $value) {
                        if (is_a($value, 'MongoDB\Model\BSONDocument'))
                            $tmp[$key] = (array) $value;
                        if (is_array($tmp[$key]))
                            foreach ($tmp[$key] as $k => $v) {
                                if (is_a($v, 'MongoDB\BSON\UTCDateTime')) {
                                    # convert to second
                                    $tmp[$key][$k] = $v->toDateTime()->format('U');
                                }
                            }
                    }
                    if (isset($tmp['total']))
                        $total += $tmp['total'];
                    $response[] = $tmp;
                }

            $e   = microtime(true);
            $log[] = 'Response -> ' . round($e - $s, 2);
            return ['data' => $response, 'count' => ($count == 0 ? count($response) : $count), 'total' => $total, 'error' => ''];
        } catch (Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return ['data' => [], 'count' => 0, 'total' => 0, 'error' => 'Error occurred during Mongodb Database Query.<br> To fix the problem, please reset your reporting via Zenarmor -> Configuration -> Reporting & Data.'];
        }
    }
}
