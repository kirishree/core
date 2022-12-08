<?php

namespace OPNsense\Sensei\Api;

use Phalcon\Config\Adapter\Ini as ConfigIni;
use \OPNsense\Base\ApiControllerBase;
use \OPNsense\Core\Backend;
use \OPNsense\Sensei\Sensei;
use \OPNsense\Core\Config;
use \OPNsense\Sensei\SenseiMongoDB;

class ServiceController extends ApiControllerBase
{
    private $log = [];

    const log_file = '/usr/local/sensei/log/active/Senseigui.log';

    public function indexAction()
    {
        try {
            $sensei = new Sensei();
            $backend = new Backend();
            $this->sessionClose();
            $response = [];
            $service = $this->request->getPost('service');
            $action = $this->request->getPost('action');
            //        if ($service == 'eastpect' and ($action == 'start' or $action == 'restart') and empty($sensei->getNodeByReference('interfaces')->getNodes())) {
            if ($service == 'eastpect' && ($action == 'start' || $action == 'restart')) {
                $workersCheck = exec('grep -c 4343 /usr/local/sensei/etc/workers.map', $output, $return);
                if ($return != 0) {
                    $response['message'] = 'No Interface Notification';
                } else if (intval($output[0]) == 0) {
                    $response['message'] = 'No Interface Notification';
                }
            }

            $remote = (string) $sensei->getNodeByReference('general.database.Remote');
            if ($service == 'elasticsearch' && $action == 'status' && $remote == 'true') {
                try {
                    $arrContextOptions = array(
                        "ssl" => array(
                            "verify_peer" => false,
                            "verify_peer_name" => false,
                        ),
                    );
                    $dbuser = (string) $sensei->getNodeByReference('general.database.User');
                    $dbpass = (string) $sensei->getNodeByReference('general.database.Pass');
                    if (substr($dbpass, 0, 4) == 'b64:')
                        $dbpass = base64_decode(substr($dbpass, 4));
                    if (!empty($dbuser) && !empty($dbpass)) {
                        $auth = base64_encode("$dbuser:$dbpass");
                        $arrContextOptions["http"] = [
                            "header" => "Authorization: Basic $auth",
                        ];
                    }
                    $context = stream_context_create($arrContextOptions);

                    $result = file_get_contents((string) $sensei->getNodeByReference('general.database.Host') . ':' . $sensei->getNodeByReference('general.database.Port'), false, $context);
                    $response['output'] = "$service is running..";
                } catch (\Exception $e) {
                    $sensei->logger(__METHOD__ . '=>' . $e->getMessage());
                    $response['output'] = "$service is not running...";
                }
            } else if (empty($response['message'])) {
                if ($service == 'sqlite') {
                    $response['output'] = 'is running';
                } else {
                    $response['output'] = $backend->configdRun('sensei service ' . $service . ' ' . $action);
                }
                if (strpos($response['output'], 'ERR:') !== false) {
                    $response['message'] = str_replace([PHP_EOL, "\t"], ' ', substr($response['output'], strpos($response['output'], 'ERR:') + 4));
                }
            }
            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return [];
        }
    }

    public function proxyAction()
    {
        try {
            $this->log = [];
            $s = microtime(true);
            $stime = microtime(true);

            $config = new ConfigIni(Sensei::eastpect_config);

            $e = microtime(true);
            $this->log[] = PHP_EOL . 'Sensei Loaded -> ' . round($e - $s, 2);
            $s = $e;

            $url = $this->request->getPost('url');
            $data = $this->request->getPost('data');
            if ($config->Database->type == 'MN') {
                //   file_put_contents("/tmp/proxy.log", implode(PHP_EOL, $this->log),FILE_APPEND);
                return $this->mongodb($url, $data, $stime);
            }
            $e = microtime(true);
            $this->log[] = 'get parameters -> ' . round($e - $s, 2);
            $s = $e;

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $config->ElasticSearch->apiEndPointIP . '/' . $url);
            curl_setopt($curl, CURLOPT_PORT, $config->ElasticSearch->apiEndPointPort);
            curl_setopt($curl, CURLOPT_HEADER, 0);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($curl, CURLOPT_TIMEOUT, 20);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
            if (!empty($config->ElasticSearch->apiEndPointUser)) {
                $apiEndPointPass = $config->ElasticSearch->apiEndPointPass;
                if (substr($config->ElasticSearch->apiEndPointPass, 0, 4) == 'b64:')
                    $apiEndPointPass = base64_decode(substr($config->ElasticSearch->apiEndPointPass, 4));
                curl_setopt($curl, CURLOPT_USERPWD, $config->ElasticSearch->apiEndPointUser . ':' . $apiEndPointPass);
            }
            curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json'
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
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $url . ':' . $data . curl_getinfo($curl, CURLINFO_HTTP_CODE) . $results, FILE_APPEND);
                $this->response->setStatusCode(504, 'Gateway Timeout');
                $results = 'Query timeout expired!';
            } elseif (curl_getinfo($curl, CURLINFO_HTTP_CODE) >= 400) {
                $this->response->setStatusCode(503, 'Service Unavailable');
            } else {
                $this->response->setHeader('Content-Type', 'application/json; charset=utf-8');
            }
            curl_close($curl);
            $e = microtime(true);
            $this->log[] = 'Return Data -> ' . round($e - $s, 2);
            $s = $e;
            // file_put_contents("/tmp/proxy.log", implode(PHP_EOL, $this->log),FILE_APPEND);
            return $results;
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return [];
        }
    }

    private function mongodb($url, $data, $stime)
    {
        try {
            $s = microtime(true);
            $senseiMongodb = new SenseiMongoDB();
            $e = microtime(true);
            $this->log[] = 'Loading Mongodb -> ' . round($e - $s, 2);
            //  file_put_contents("/tmp/proxy.log", implode(PHP_EOL, $this->log),FILE_APPEND);
            return $senseiMongodb->executeQuery(explode('/', $url)[0], $data, $stime);
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return [];
        }
    }

    public function mailAction()
    {
        try {
            $backend = new Backend();
            $config = $this->request->getPost('config');
            $pdf = $this->request->getPost('pdf');
            $params = [$pdf, $config['server'], $config['port'], $config['secured'], $config['username'], $config['password'], $config['from'], $config['to'], $config['nosslverify']];
            $logArr = [$pdf, $config['server'], $config['port'], $config['secured'], $config['username'], "*******", $config['from'], $config['to'], $config['nosslverify']];
            $params = implode('" "', $params);
            // $response = $backend->configdRun('sensei mail-reports '.$pdf);
            // file_put_contents(self::log_file, __METHOD__ . 'Mail prepared mail-reports '.$response.PHP_EOL, FILE_APPEND);
            $response = $backend->configdRun('sensei mail-test "' . $params . '"');
            file_put_contents(self::log_file, __METHOD__ . 'Mail send for test mail-test ' . implode('" "', $logArr) . ' ' . $response . PHP_EOL, FILE_APPEND);
            $this->response->setHeader('Content-Type', 'application/json; charset=utf-8');
            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return [];
        }
    }

    public function scheduledReportsAction()
    {
        try {
            $sensei = new Sensei();
            $this->response->setHeader('Content-Type', 'application/json; charset=utf-8');
            return file_get_contents($sensei->scheduledReportsConfig);
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return [];
        }
    }

    public function reinstallElasticsearchAction()
    {
        try {
            $backend = new Backend();
            $keepData = $this->request->getQuery('keep_data');
            return $backend->configdRun('sensei reinstall elasticsearch ' . $keepData);
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return [];
        }
    }

    public function recreateIndexAction()
    {
        try {
            $backend = new Backend();
            $index = $this->request->getPost('index');
            if (!empty($index)) {
                $response['output'] = trim($backend->configdRun('sensei reindex elasticsearch ' . $index));
                return $response;
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return [];
        }
    }

    private function Proxy($url, $data, $base_uri = '', $port = '')
    {
        try {
            $config = new ConfigIni(Sensei::eastpect_config);
            if (empty($base_uri)) {
                $base_uri = $config->ElasticSearch->apiEndPointIP;
            }

            if (empty($port)) {
                $port = $config->ElasticSearch->apiEndPointPort;
            }

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $base_uri . $url);
            curl_setopt($curl, CURLOPT_PORT, $port);
            curl_setopt($curl, CURLOPT_HEADER, 0);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 1);
            curl_setopt($curl, CURLOPT_TIMEOUT, 20);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
            if ($data) {
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            }
            $results = curl_exec($curl);
            if ($results === false) {
                return [504, $results];
            } elseif (curl_getinfo($curl, CURLINFO_HTTP_CODE) >= 400) {
                return [503, $results];
            }
            curl_close($curl);
            return [200, $results];
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return [200, []];
        }
    }
    public function deleteDataAction()
    {
        try {
            $backend = new Backend();
            $backend->configdRun('sensei delete-data-folder ES');
            $backend->configdRun('sensei delete-data-folder MN');
            return 'OK';
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return 'error';
        }
    }

    public function hardresetEsAction()
    {
        try {
            $response = ['successful' => true, 'engine_start' => false];
            $recreateindex = $this->request->getPost('recreateindex', null, true);
            $sensei = new Sensei();
            $dbtype = (string) $sensei->getNodeByReference('general.database.Type');
            $dbname = $sensei::reportDatabases[$dbtype];
            $sensei->logger('Starting Hard Reset ' . $dbname['name'] . ' Database with option recreate-index ' . $recreateindex);
            $backend = new Backend();

            $response['engine_start'] = strpos($backend->configdRun('sensei service eastpect status'), 'is running') !== false;
            $sensei->logger('Stoping engine...');
            $backend->configdRun('sensei service eastpect stop');

            $sensei->logger('Stoping ' . $dbname['name']);
            $result = $backend->configdRun('sensei service ' . $dbname['service'] . ' stop');
            $sensei->logger('status : ' . (string) $result);
            sleep(5);
            $result = $backend->configdRun('sensei delete-data-folder ' . $dbtype);
            $sensei->logger('deleting ' . $dbname['name'] . ' path folder : ' . $result);
            //if wants to reinstall reportdatabase after reset reporting.
            if ($recreateindex == 'true') {
                $sensei->logger('Starting ' . $dbname['name']);
                $result = $backend->configdRun('sensei service ' . $dbname['service'] . ' start');
                sleep(15);
                $sensei->logger('Create ' . $dbname['name'] . ' indexes');
                $result = $backend->configdRun('sensei erase-reporting-data 0 ' . $dbtype);
                $sensei->logger('Created ' . $dbname['name'] . ' indexes : ' . $result);

                $sensei->logger('Starting engine...');
                if ($response['engine_start'] == true)
                    $backend->configdRun('sensei service eastpect start');
            } else {
                $sensei->logger('indexes did not created ');
            }

            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return ['successful' => false];
        }
    }

    public function eraseCronAction()
    {
        try {
            $ip = $this->request->getPost('ip', null, '');
            if (!empty($ip)) {
                $sensei = new Sensei();
                $dbtype = (string) $sensei->getNodeByReference('general.database.Type');
                $cron_path = '/usr/local/sensei/log/cron';
                if (!file_exists($cron_path)) {
                    mkdir($cron_path, 0755, true);
                }

                $filename = time() . '.txt';
                file_put_contents($cron_path . '/' . $filename, $dbtype . '#' . $ip);
                return ['successful' => true];
            }
            return ['successful' => false, 'message' => 'Error: Missing parameters'];
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return ['successful' => false, 'message' => 'Error:Exception occur!'];
        }
    }

    public function eraseReportingDataAction()
    {
        try {
            $day = $this->request->getPost('day');
            $backend = new Backend();
            $sensei = new Sensei();
            $dbtype = (string) $sensei->getNodeByReference('general.database.Type');
            $response = [];
            // delete all data
            if ($day == 0) {
                $response['output'] = $backend->configdRun('sensei erase-reporting-data 0 ' . $dbtype);
                $response['successful'] = !is_null($response['output']) and preg_match('/Execute error|Action not found/i', $response['output']) == 0;
                return $response;
            }
            $response['output'] = $backend->configdRun('sensei datastore-delete ' . $day . ' ' . $dbtype);
            $response['successful'] = !is_null($response['output']) and preg_match('/Execute error|Action not found/i', $response['output']) == 0;
            return $response;
            // get list indexes
            $json_data = $this->Proxy('_aliases?pretty=1', null);
            $list = json_decode($json_data[1], true);
            $listofIndexes = array_keys($list);

            // set query for delete
            $timestamp = time() - ($day * 60 * 60 * 24);
            $data = '{"query": {
                     "bool": {
                        "must": [{
                            "range": {
                             "start_time": {
                                 "lte": ' . ($timestamp * 1000) . ',
                                 "format": "epoch_millis"
                             }
                            }
                        }]
                        }
                     }
                }';

            $return = [];
            // delete result data of query with timestamp
            foreach ($listofIndexes as $key => $index) {
                $url = $index . '/_delete_by_query';
                $result = $this->Proxy($url, $data);
                $return[] = $result;
            }
            return json_encode($return);
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return [];
        }
    }
    public function externalElasticCheckAction()
    {
        try {
            //code...
            $msg = 'Remote Elastic Search Database (%s) cannot be reached. Please check your network connectivity and make sure the remote database is up and running.';
            $sensei = new Sensei();
            $sensei->logger('ELK:External Elasticsearch Test');
            $response = ['successful' => true];
            $uri = $this->request->getPost('uri');
            $dbuser = $this->request->getPost('user', null, '');
            $dbpass = $this->request->getPost('pass', null, '');
            $arrContextOptions = array(
                "ssl" => array(
                    "verify_peer" => false,
                    "verify_peer_name" => false,
                ),
            );
            if (!filter_var($uri, FILTER_VALIDATE_URL)) {
                $sensei->logger('ELK:Invalid Url->' . $uri);
                return ['successful' => false, 'message' => ' invalid url'];
            }
            if (!empty($dbuser) && !empty($dbpass)) {
                $auth = base64_encode($dbuser . ":" . $dbpass);
                $arrContextOptions["http"] = [
                    "header" => "Authorization: Basic $auth",

                ];
            }
            $context = stream_context_create($arrContextOptions);
            $dbinfo = file_get_contents($uri, false, $context);
            if ($dbinfo !== false) {
                $es_obj = json_decode($dbinfo);
                $json_data = json_decode($dbinfo, true);
                $es_version = str_replace('.', '', $es_obj->version->number);
                $es_version = $es_version . str_repeat('0', 5 - strlen($es_version));
                $dbType = (string) $sensei->getNodeByReference('general.database.Type');
                $dbClusterId = (string) $sensei->getNodeByReference('general.database.ClusterUUID');
                if ($dbType == 'ES' && $dbClusterId == $es_obj->cluster_uuid) {
                    return ['successful' => false, 'message' => 'Report database and external database must not be same.'];
                }
                $sensei->logger("ELK:Connection Test Successfull save to configuration file.:" . var_export($json_data, true));
                $sensei->setNodes(['streamReportDataExternal' => [
                    'enabled' => "true",
                    'uri' => $uri,
                    'Version' => $es_version,
                    'User' => $dbuser,
                    'Pass' => 'b64:' . base64_encode($dbpass),
                    'ClusterUUID' => $es_obj->cluster_uuid
                ]]);
                $sensei->saveChanges();
                $backend = new Backend();
                $backend->configdRun('template reload OPNsense/Sensei');
                $result = $backend->configdRun('sensei reporting-index-create');
                $sensei->logger('sensei reporting-index-create ' . $result);
                $result = $backend->configdRun('sensei restart-ipdrstreamer');
                $sensei->logger('sensei restart-ipdrstreamer ' . $result);
                return ['successful' => true, 'version' => $es_version, 'message' => 'succesfull'];
            } else {
                $sensei->logger("ELK:Connection Test Not Successfull Error : " . var_export($json_data, true));
                return ['successful' => false, 'message' => is_bool($json_data[1]) ? sprintf($msg, $uri) : $json_data[1]];
            }
        } catch (\Exception $e) {
            $sensei->logger(__METHOD__ . " -> ELK:Connection Test Not Successfull Error: ::Exception::" . $e->getMessage());
            return ['successful' => false, 'message' => "Not Connection to external reports server."];
        }
    }

    public function getUserGroupsAction()
    {
        try {
            $sensei = new Sensei();
            $p_user = $this->request->getPost('user', null, '');
            $p_group = $this->request->getPost('group', null, '');
            $sensei->logger('getUserGroups Starging for ' . $p_user . ':' . $p_group);
            # get localuser name and password.
            $config = Config::getInstance()->object();
            $a_users = $config->system->user;
            $a_groups = $config->system->group;
            $response = ['users' => [], 'groups' => []];
            foreach ($a_users as $user) {
                $sensei->logger('User: ' . var_export(((array)$user)['name'], true));
                $response['users'][] = ((array)$user)['name'];
            }

            foreach ($a_groups as $group) {
                $response['groups'][] = ((array)$group)['name'];
            }

            # get ldap users and groups.
            $ldap_servers = [];
            if (isset($config->system->authserver) && is_array($config->system->authserver)) {
                foreach ($config->system->authserver as $authcfg) {
                    $authcfg = auth_get_authserver($authcfg['name']);
                    if ($authcfg['type'] == 'ldap' || $authcfg['type'] == 'ldap-totp') {
                        $ldap_server = $authcfg;
                        if (!isset($ldap_server['ldap_full_url'])) {
                            if (strstr($ldap_server['ldap_urltype'], "Standard") || strstr($ldap_server['ldap_urltype'], "StartTLS")) {
                                $ldap_server['ldap_full_url'] = "ldap://";
                            } else {
                                $ldap_server['ldap_full_url'] = "ldaps://";
                            }
                            $ldap_server['ldap_full_url'] .= is_ipaddrv6($authcfg['host']) ? "[{$authcfg['host']}]" : $authcfg['host'];
                            if (!empty($ldap_server['ldap_port'])) {
                                $ldap_server['ldap_full_url'] .= ":{$authcfg['ldap_port']}";
                            }
                        }
                        $ldap_servers[] = $ldap_server;
                    }
                }
            }

            # get ldap users and groups END.
            # connect to ldap servers
            foreach ($ldap_servers as $ldap_server) {
                if (!empty($p_user)) {
                    try {
                        $authenticator = (new \OPNsense\Auth\AuthenticationFactory())->get($ldap_server['name']);
                        // search ldap
                        $ldap_is_connected = $authenticator->connect(
                            $ldap_server['ldap_full_url'],
                            $ldap_server['ldap_binddn'],
                            $ldap_server['ldap_bindpw']
                        );

                        if ($ldap_is_connected) {
                            // search ldap
                            $result = $authenticator->searchUsers($p_user . '*', $ldap_server['ldap_attr_user'], $ldap_server['ldap_extended_query']);
                            if (is_array($result)) {
                                foreach ($result as $data) {
                                    if (isset($data['name'])) {
                                        $response['users'][] = $data['name'];
                                        continue;
                                    }
                                    if (isset($data['dn'])) {
                                        $list = explode(',', $data['dn']);
                                        foreach ($list as $dn) {
                                            $cn = explode('=', $dn);
                                            if (strtolower($cn[0]) == 'cn' && isset($cn[1])) {
                                                $response['users'][] = $cn[1];
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    } catch (Exception $exc) {
                        $sensei->logger('LDAP Error: ' . $exc->getTraceAsString());
                    }
                }
                if (!empty($p_group)) {

                    if (isset($ldap_server['ldap_basedn']) && isset($ldap_server['host'])) {
                        $ldap_authcn = isset($ldap_server['ldap_authcn']) ? explode(";", $ldap_server['ldap_authcn']) : array();
                        if (isset($ldap_server['ldap_urltype']) && (strstr($ldap_server['ldap_urltype'], "Standard") || strstr($ldap_server['ldap_urltype'], "StartTLS"))) {
                            $ldap_full_url = "ldap://";
                        } else {
                            $ldap_full_url = "ldaps://";
                        }
                        $ldap_full_url .= is_ipaddrv6($ldap_server['host']) ? "[{$ldap_server['host']}]" : $ldap_server['host'];
                        if (!empty($ldap_server['ldap_port'])) {
                            $ldap_full_url .= ":{$ldap_server['ldap_port']}";
                        }

                        $ldap_auth = new \OPNsense\Auth\LDAP($ldap_server['ldap_basedn'], isset($ldap_server['proto']) ? $ldap_server['proto'] : 3);
                        if (isset($ldap_server['ldap_cert'])) {
                            $ldap_auth->setupCaEnv($ldap_server['ldap_cert']);
                        }
                        $ldap_is_connected = $ldap_auth->connect(
                            $ldap_full_url,
                            !empty($ldap_server['ldap_binddn']) ? $ldap_server['ldap_binddn'] : null,
                            !empty($ldap_server['ldap_bindpw']) ? $ldap_server['ldap_bindpw'] : null
                        );

                        $ous = false;

                        if ($ldap_is_connected) {
                            $sensei->logger('Get Group List');
                            $ous = $ldap_auth->listOUs();
                        }

                        if ($ous !== false) {
                            foreach ($ous as $ou) {
                                $list_ou = explode(',', $ou);
                                foreach ($list_ou as $item) {
                                    $list_item = explode('=', $item);
                                    if (strtolower($list_item[0]) == 'ou' && stripos($list_item[1], $p_group) !== false) {
                                        $response['groups'][] = $list_item[1];
                                    }
                                }
                            }
                        }
                    }
                }
            }
            $response['groups'] = array_unique($response['groups']);
            $response['users'] = array_unique($response['users']);
            return $response;
        } catch (\Exception $e) {
            $sensei->logger(__METHOD__ . " ::Exception::" . $e->getMessage());
            return ['groups' => [], 'users' => []];
        }
    }
}
