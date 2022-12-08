<?php

namespace OPNsense\Sensei;

use Phalcon\Config\Adapter\Ini as ConfigIni;
use \OPNsense\Base\BaseModel;
use \OPNsense\Core\Backend;
use \OPNsense\Core\Config;
use OPNsense\Sensei\SenseiLogger;
use OPNsense\Sensei\SenseiConfig;

class Sensei extends BaseModel
{
    public $tlsWhitelistFileDefault = '/usr/local/sensei/policy/Rules/tlswhitelist.rules.default';
    public $tlsWhitelistFile = '/usr/local/sensei/policy/Rules/tlswhitelist.rules';
    public $certFile = '/usr/local/sensei/cert/ca/rootCA.pem';
    public $certKeyFile = '/usr/local/sensei/cert/ca/rootCA.key';
    public $landingPage = '/usr/local/sensei/userdefined/templates/block.template';
    public $configDoneFile = '/usr/local/sensei/etc/.configdone';
    public $webControlsJson = '/usr/local/sensei/db/webui/webcats.json';
    public $webControlsMigrationJson = '/usr/local/sensei/db/webui/webcats_migrate.json';
    public $appControlsJson = '/usr/local/sensei/db/webui/apps.json';
    public $cloudReputationServersFile = '/usr/local/sensei/db/Cloud/nodes.csv';
    public $updatesJson = '/tmp/zenarmor_updates.json';
    public $changelogDir = '/usr/local/sensei/scripts/installers/opnsense/18.1/changelog';
    public $udpateServerConf = '/root/.svn-update-server';
    public $dbServerConf = '/root/.sensei_db.conf';
    public $updateServerDefault = 'https://updates.sunnyvalley.io';
    public $scheduledReportsConfig = '/usr/local/opnsense/scripts/OPNsense/Sensei/report-gen/indices.json';
    public $nodesStatusJson = '/tmp/sensei_nodes_status.json';
    public $licenseData = '/usr/local/sensei/etc/license.data';
    public $cloudUri = 'https://sunnyvalley.cloud';
    public $cloudToken = '/usr/local/sensei/etc/token';
    public $cloudNodeCa = '/usr/local/sensei/cert/nabca.crt';
    public $cloudNodeCrt = '/usr/local/sensei/cert/nabnode.crt';
    public $cloudNodeKey = '/usr/local/sensei/cert/nabnode.key';
    public $cloudConnectStatus = '/senpai_connect_status';
    public $databaseStatus = true;
    const installInfoApi = 'https://sunnyvalley.cloud/api/v1/nodes/reports/install';
    const manageSock = '/usr/local/sensei/run/';
    const manageSockFile = 'mgmt.sock.';
    const serialPath = '/usr/local/sensei/etc/serial';
    const licenseServer = 'https://license.sunnyvalley.io';
    const freeTrialEndpoint = 'https://sunnyvalley.cloud/api/v1/register/free-trial';
    const restTokenFile = '/usr/local/sensei/userdefined/db/Usercache/tokens.json';
    const userCacheDir = '/usr/local/sensei/userdefined/db/Usercache/tmp/';
    const bypassHw = '/dev/bpmod';
    const bypassUtil = '/usr/local/sensei/bin/bpctl_util';
    const eastpect_config = '/usr/local/sensei/etc/eastpect.cfg';
    const logger_levels = ['CRITICAL' => 1, 'ERROR' => 3, 'WARNING' => 4, 'INFO' => 6, 'DEBUG' => 7, 'NOTSET' => 0, 'DEBUG1' => 7, 'DEBUG2' => 7, 'DEBUG3' => 7, 'DEBUG4' => 7, 'DEBUG5' => 7];
    const license_list = ['opnsense_premium' => 'Business', 'opnsense_business' => 'Business', 'opnsense_premium_demo' => 'Business Demo', 'opnsense_business_demo' => 'Business Demo', 'opnsense_home' => 'Home', 'opnsense_soho' => 'SOHO'];
    const reportDatabases = [
        'ES' => ['service' => 'elasticsearch', 'dbpath' => '/var/db/elasticsearch', 'name' => 'Elasticsearch'],
        'MN' => ['service' => 'mongod', 'dbpath' => '/var/db/mongodb', 'name' => 'Mongodb'],
        'SQ' => ['service' => 'SQ', 'dbpath' => '/usr/local/datastore/sqlite', 'name' => 'Sqlite'],
    ];

    const security_premium = ['Botnet C&C', 'Botnet DGA Domains', 'Dead Sites', 'Newly Registered Sites', 'Newly Recovered Sites', 'Proxy', 'Dynamic DNS Sites', 'Local IP', 'Malware+', 'DNS Tunneling'];
    const security_order = [
        'Malware/Virus' => 1, 'Phishing' => 2, 'Spam sites' => 3, 'Hacking' => 4, 'Parked Domains' => 5, 'Potentially Dangerous' => 6, 'Firstly Seen Sites' => 7, 'Undecided Not Safe' => 8,
        'Undecided Safe' => 9, 'Malware+' => 10, 'Botnet C&C' => 11, 'Proxy' => 12, 'Dead Sites' => 13, 'Dynamic DNS Sites' => 14, 'Local IP' => 15, 'Newly Registered Sites' => 16, 'Newly Recovered Sites' => 17,
        'Botnet DGA Domains' => 18, 'DNS Tunneling' => 19,
    ];
    // const security_coming_premium = ['Botnet C&C', 'Botnet DGA Domains', 'DNS Tunneling'];
    const security_coming_premium = ['Botnet DGA Domains', 'DNS Tunneling'];
    const security_new_premium = ['Botnet C&C'];

    const webcategory_list = [
        'moderate' => ['Ad Trackers', 'Adult', 'Advertisements', 'Hate/Violence/Illegal', 'Illegal Drugs', 'Pornography'],
        'high' => ['Ad Trackers', 'Adult', 'Advertisements', 'Alcohol', 'Blogs', 'Chats', 'Dating', 'Forums', 'Gambling', 'Games', 'Hate/Violence/Illegal', 'Illegal Drugs', 'Job Search', 'Online Storage', 'Online Video', 'Pornography', 'Social Networks', 'Software Downloads', 'Swimsuits and Underwear', 'Tobacco', 'Warez', 'Weapons and Military'],
    ];
    const flavorSizes = [
        '15' => 'Home',
        '25' => 'Small',
        '50' => 'Small II',
        '100' => 'Medium',
        '250' => 'Medium II',
        '500' => 'Large',
        '1000' => 'Xlarge',
        '2000' => 'XXlarge',
    ];
    const flavorSizes2 = [
        '15' => 'home',
        '25' => 'small',
        '50' => 'small2',
        '100' => 'medium',
        '250' => 'medium2',
        '500' => 'large',
        '1000' => 'xlarge',
        '2000' => 'xlarge',
    ];
    const sqlite_path = "/usr/local/datastore/sqlite/";
    const template_chart_path = "/usr/local/sensei/templates/charts/";
    const LEVEL_DEBUG = 6;
    const rootDir = '/usr/local/sensei/';
    public $reportDatabase = null;
    public $database = null;
    public $log4 = null;
    public $config = null;
    public $stream_timeout = 3;

    private $s = 0;
    private $processid = 0;
    private $logger_level = 4;

    private $haip = '';
    private $hausername = '';
    private $hapassword = '';

    protected function init()
    {
        $root = '/usr/local/sensei/';
        $this->s = microtime(true);
        $logfilename = $root . 'log/active/Senseigui.log';
        if (!file_exists(dirname($logfilename))) {
            $logfilename = '/tmp/Senseigui.log';
        }
        ini_set('error_log', $logfilename);

        if (file_exists($logfilename)) {
            $fp = fopen($logfilename, 'r');
            if ($fp !== false) {
                $fstat = fstat($fp);
                fclose($fp);
                if ($fstat !== false && is_array($fstat)) {
                    $stat = array_slice($fstat, 13);
                    if ($stat['atime'] < (time() - 86400) && file_exists($logfilename)) {
                        if (file_exists($logfilename))
                            rename($logfilename, $logfilename . '.' . date('Y-m-d', strtotime(date('Y-m-d') . ' -1 day')));
                    }
                }
            }
        }

        $config = [
            'database' => $root . 'userdefined/config/settings.db',
            'updateServer' => 'https://updates.sunnyvalley.io',
            'certs' => [
                'public' => $root . 'cert/ca/rootCA.pem',
                'private' => $root . 'cert/ca/rootCA.key',
            ],
            'dirs' => [
                'root' => $root,
                'stat' => $root . 'log/stat',
                'notification' => $root . 'log/active/notifications.json',
                'changelog' => '/usr/local/sensei/scripts/installers/opnsense/18.1/changelog',
            ],
            'files' => [
                'license' => $root . 'etc/license.data',
                'support' => $root . 'etc/support.data',
                'configDone' => $root . 'etc/.configdone',
                'mylocation' => $root . 'etc/mylocation.json',
                'isoconfig' => $root . 'etc/.isoconfig',
                'partner' => $root . 'etc/partner.json',
                'tlsWhitelist' => $root . 'policy/Rules/tlswhitelist.rules',
                'tlsWhitelistDefault' => $root . 'policy/Rules/tlswhitelist.rules.default',
                'landingPage' => $root . 'userdefined/templates/block.template',
                'cloudNodesCache' => '/tmp/sensei_nodes_status.json',
                'cloudNodesConfiguration' => $root . 'db/Cloud/nodes.csv',
                'updatesCache' => '/tmp/zenarmor_updates.json',
                'updatesDbCache' => '/tmp/sensei_db_updates.date',
                'updatesConfiguration' => '/root/.svn-update-server',
                'scheduledReportsConfiguration' => '/usr/local/opnsense/scripts/OPNsense/Sensei/report-gen/indices.json',
                'reportsConfig' => '/usr/local/sensei/userdefined/config/reportConfig.json',
                'ipsSignatures' => $root . '/userdefined/db/Threat/threat_file.db',
            ],
        ];

        $logger  = new SenseiLogger();
        $logger->logFileName = $logfilename;
        $this->log4 = $logger;
        $this->config = new SenseiConfig($config);

        try {
            if (file_exists($this->config->database)) {
                $this->database = new \SQLite3($this->config->database);
                $this->database->busyTimeout(5000);
                $this->database->exec('PRAGMA journal_mode = wal;');
            }
        } catch (\Exception $th) {
            $this->databaseStatus = false;
            $this->logger(__METHOD__ . ' Exception -> ' . $th->getMessage());
        }

        $this->cloudConnectStatus = sys_get_temp_dir() . $this->cloudConnectStatus;

        $this->processid = getmypid();
        if (file_exists(self::eastpect_config)) {
            $configIni = new ConfigIni(self::eastpect_config);
            try {
                if (isset(self::logger_levels[$configIni->Logger->severityLevel])) {
                    $this->logger_level = self::logger_levels[$configIni->Logger->severityLevel];
                }
                if (isset($configIni->senpai["node-register-address"])) {
                    $p = parse_url($configIni->senpai["node-register-address"]);
                    $this->cloudUri = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
                }
            } catch (\Exception $e) {
            }
        }
        try {
            $this->reportDatabase = (string) $this->getNodeByReference('general.database.Type');
        } catch (\Exception $th) {
            //throw $th;
        }
        if (empty($this->reportDatabase)) {
            $this->reportDatabase = (string) $this->getNodeByReference('general.databaseType');
        }
    }

    public function logger($str = '', $level = 6)
    {
        /*
        if ($level > $this->logger_level) {
            return true;
        }
        */

        $e = microtime(true);
        $add_str = "[" . $this->processid . "][D:" . round($e - $this->s, 2) . '] ' . $str;
        $this->log4->log($level, $add_str);
        $this->s = $e;
    }
    public static function getWebCatType($name)
    {
        $response = ['permissive'];
        foreach (self::webcategory_list as $key => $list) {
            if (in_array($name, $list)) {
                $response[] = $key;
            }
        }
        return $response;
    }

    public static function formatBytes($bytes, $precision = 2)
    {
        $unit = ["B", "KB", "MB", "GB"];
        $exp = floor(log($bytes, 1024)) | 0;
        return round($bytes / (pow(1024, $exp)), $precision) . $unit[$exp];
    }

    private function haGetConfig()
    {
        $doc = new \DOMDocument;
        $doc->load('/conf/config.xml', LIBXML_COMPACT | LIBXML_PARSEHUGE);
        $xpath = new \DOMXPath($doc);
        $ip = '';
        $username = '';
        $password = '';
        $items = $xpath->query("/opnsense/hasync");
        foreach ($items as $item) {
            foreach ($item->childNodes as $child) {
                if ($child->nodeName == 'synchronizetoip') {
                    $this->haip = substr($child->nodeValue, -1) == '/' ? substr($child->nodeValue, 0, -1) : $child->nodeValue;
                }

                if ($child->nodeName == 'username') {
                    $this->hausername = $child->nodeValue;
                }

                if ($child->nodeName == 'password') {
                    $this->hapassword = $child->nodeValue;
                }
            }
        }
        if (empty($this->haip)) {
            return ['success' => false, 'error' => 'Backup FW IP not defined'];
        }
        if (empty($this->hausername)) {
            return ['success' => false, 'error' => 'Backup FW Username not defined'];
        }
        if (empty($this->hapassword)) {
            return ['success' => false, 'error' => 'Backup FW Password not defined'];
        }

        if (filter_var($this->haip, FILTER_VALIDATE_IP)) {
            $protocol = 'http';
            $port = 80;
            $items = $xpath->query("/opnsense/system/webgui");
            foreach ($items as $item) {
                foreach ($item->childNodes as $child) {
                    if ($child->nodeName == 'protocol') {
                        $protocol = $child->nodeValue;
                    }

                    if ($child->nodeName == 'port') {
                        $port = $child->nodeValue;
                    }
                }
            }
            $this->haip = $protocol . '://' . $this->haip;
            if ($port != 80 && $port != 443) {
                $this->haip .= ':' . $port;
            }
        }
        return true;
    }
    /*
    JWT singing
     */

    private function jwt_sign($msg, $key, $alg = 'HS256')
    {
        list($function, $algorithm) = array('hash_hmac', 'SHA256');
        switch ($function) {
            case 'hash_hmac':
                return hash_hmac($algorithm, $msg, $key, true);
            case 'openssl':
                $signature = '';
                $success = openssl_sign($msg, $signature, $key, $algorithm);
                if (!$success) {
                    throw new Exception("OpenSSL unable to sign data");
                } else {
                    return $signature;
                }
        }
    }

    public function jwt_encode($payload, $key, $alg = 'HS256', $keyId = null, $head = null)
    {
        $header = array('typ' => 'JWT', 'alg' => $alg);

        if ($keyId !== null) {
            $header['kid'] = $keyId;
        }

        if (isset($head) && is_array($head)) {
            $header = array_merge($head, $header);
        }

        $segments = array();
        $segments[] = str_replace('=', '', strtr(base64_encode(json_encode($header)), '+/', '-_'));
        $segments[] = str_replace('=', '', strtr(base64_encode(json_encode($payload)), '+/', '-_'));
        $signing_input = implode('.', $segments);
        $signature = $this->jwt_sign($signing_input, $key, $alg);
        $segments[] = str_replace('=', '', strtr(base64_encode($signature), '+/', '-_'));

        return implode('.', $segments);
    }

    public function sendDataCloud($action, $policyId)
    {
        $response = ['error' => false, 'message' => ''];
        try {
            if (file_exists($this->cloudToken)) {
                $this->logger("Send data cloud Action: $action , PolicyID: $policyId");
                $host_uuid = (string) $this->getNodeByReference('general.CloudManagementUUID');
                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, $this->cloudUri . '/api/v1/nodes/' . $host_uuid . '/policies');
                $this->logger('Send data URL: ' . $this->cloudUri . '/api/v1/nodes/' . $host_uuid . '/policies');
                $jwt = ["exp" => strtotime("+10 minute"), "user_id" => (string) $this->getNodeByReference('general.CloudManagementAdmin'), "node_uuid" => $host_uuid];
                $token = $this->jwt_encode($jwt, file_get_contents($this->cloudToken));
                $this->logger('Token: ' . $token);
                curl_setopt(
                    $curl,
                    CURLOPT_HTTPHEADER,
                    array(
                        'Authorization: Bearer ' . $token,
                        'Content-Type: application/json',
                    )
                );
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
                curl_setopt($curl, CURLOPT_TIMEOUT, 40);
                $stmt = $this->database->prepare('select * from policies where id=0');
                $results = $stmt->execute();
                $row = $results->fetchArray($mode = SQLITE3_ASSOC);
                if (empty($row['cloud_id'])) {
                    $this->logger("Policies did'nt take by Cloud");
                    return $response;
                }
                if ($row['is_centralized'] == 1) {
                    $this->logger("Central Policy did'nt send to  Cloud");
                    return $response;
                }

                $stmt = $this->database->prepare('select * from policies where id=:id');
                $stmt->bindValue(':id', $policyId);
                $results = $stmt->execute();
                $row = $results->fetchArray($mode = SQLITE3_ASSOC);
                if ($row === false) {
                    $this->logger("Policy records doesn't found , PolicyID: $policyId");
                    $response['error'] = true;
                    $response['message'] = "Policy records doesn't found";
                    return $response;
                }
                if (empty($row['cloud_id'])) {
                    $this->logger("Policy cloud_id doesn't found , PolicyID: $policyId");
                }
                //{"cloud_id": "NPf9DlveUb"}
                if ($action == 'delete') {
                    $data = ['cloud_id' => $row['cloud_id'], 'local_id' => $policyId];
                    $data_string = json_encode($data);
                    $this->logger('Send data ' . $data_string);
                    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
                    curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
                }
                # get policy data
                $policy = $row;
                $this->logger('Row: ' . var_export($row, true));
                if ($action == 'update') {
                    #networks...
                    $networks = array();
                    $stmt = $this->database->prepare("select * from policies_networks where type=1 and policy_id=:policy_id");
                    $stmt->bindValue(':policy_id', $policyId);
                    $results = $stmt->execute();
                    while ($row_networks = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                        $networks[] = ["value" => $row_networks['network'], "desc" => $row_networks['desc'], "is_disabled" => ($row_networks['status'] == 1 ? false : true)];
                    }
                    #macaddress...
                    $macaddresses = array();
                    $stmt = $this->database->prepare("select * from policies_macaddresses where policy_id=:policy_id");
                    $stmt->bindValue(':policy_id', $policyId);
                    $results = $stmt->execute();
                    while ($row_networks = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                        $macaddresses[] = ["value" => $row_networks['macaddresses'], "desc" => $row_networks['desc'], "is_disabled" => ($row_networks['status'] == 1 ? false : true)];
                    }

                    #policy data
                    //$policy['networks'] = strlen($row['networks']) == 0 ? [] : explode(',', $row['networks']);
                    $policy['networks'] = $networks;
                    $policy['vlans'] = strlen($row['vlans']) == 0 ? [] : explode(',', $row['vlans']);
                    $policy['interfaces'] = strlen($row['interfaces']) == 0 ? [] : explode(',', $row['interfaces']);
                    //$policy['mac_addresses'] = strlen($row['macaddresses']) == 0 ? [] : explode(',', $row['macaddresses']);
                    $policy['mac_addresses'] = $macaddresses;
                    $policy['usernames'] = strlen($row['usernames']) == 0 ? [] : explode(',', $row['usernames']);
                    $policy['groups'] = strlen($row['groups']) == 0 ? [] : explode(',', $row['groups']);
                    $policy['directions'] = ['inbound' => strpos($row['directions'], 'in') !== false ? true : false, 'outbound' => strpos($row['directions'], 'out') !== false ? true : false];

                    #security controls...
                    $stmt = $this->database->prepare("select p.web_categories_id,c.name from policy_web_categories p,web_categories c where c.id=p.web_categories_id and  c.is_security_category=1 and p.action='reject' and policy_id=:policy_id");
                    $stmt->bindValue(':policy_id', $policyId);
                    $results = $stmt->execute();
                    $policy['advanced_security'] = [];
                    $policy['essential_security'] = [];
                    while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                        if (in_array($row['name'], $this::security_premium, true)) {
                            $policy['advanced_security'][] = $row['web_categories_id'];
                        } else {
                            $policy['essential_security'][] = $row['web_categories_id'];
                        }
                    }
                    $policy['security'] = count($policy['essential_security']) + count($policy['advanced_security']) == 0 ? false : true;

                    #application controls.
                    $stmt = $this->database->prepare("select c.id,p.action,count(*) from policy_app_categories p, applications a, application_categories c where p.application_id=a.id and c.id=a.application_category_id and p.policy_id=:policy_id group by c.id,p.action order by c.id,p.action desc");
                    $stmt->bindValue(':policy_id', $policyId);
                    $results = $stmt->execute();
                    $application_categories = [];
                    while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                        $application_categories[] = $row;
                    }

                    $app_categories = [];
                    for ($i = 0; $i < count($application_categories) - 2; $i++) {
                        if ($application_categories[$i]['id'] != $application_categories[$i + 1]['id'] && $application_categories[$i]['action'] == 'reject') {
                            $app_categories[] = $application_categories[$i]['id'];
                        }
                    }
                    $tmp = end($application_categories);
                    if ($tmp['action'] == 'reject') {
                        $app_categories[] = $tmp['id'];
                    }

                    $stmt = $this->database->prepare("select p.application_id from policy_app_categories p, applications a, application_categories c where action='reject' and p.application_id=a.id and c.id=a.application_category_id and policy_id=:policy_id");
                    $stmt->bindValue(':policy_id', $policyId);
                    $results = $stmt->execute();
                    while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                        $app_categories[] = $row['application_id'];
                    }
                    $policy['app'] = count($app_categories) == 0 ? false : true;
                    $policy['app_controls'] = $app_categories;

                    #web controls...
                    $stmt = $this->database->prepare("select p.web_categories_id from policy_web_categories p,web_categories c where c.id=p.web_categories_id and  c.is_security_category=0 and p.action='reject' and policy_id=:policy_id");
                    $stmt->bindValue(':policy_id', $policyId);
                    $results = $stmt->execute();
                    $web_controls = [];
                    while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                        $web_controls[] = $row['web_categories_id'];
                    }
                    $policy['web'] = count($web_controls) == 0 ? false : true;
                    $policy['web_controls'] = $web_controls;

                    # tls
                    $policy['tls'] = false;

                    #exclusion controls...
                    $stmt = $this->database->prepare("select s.id as local_id,s.name,s.mon_day,s.tue_day,s.wed_day,s.thu_day,s.fri_day,s.sat_day,s.sun_day,s.start_time,s.stop_time,s.start_timestamp,s.stop_timestamp from policies_schedules p,schedules s where s.id=p.schedule_id and p.policy_id=:policy_id");
                    $stmt->bindValue(':policy_id', $policyId);
                    $results = $stmt->execute();
                    $schedules = [];
                    while ($t = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                        $t["mon_day"] = $t["mon_day"] == 1 ? true : false;
                        $t["tue_day"] = $t["tue_day"] == 1 ? true : false;
                        $t["wed_day"] = $t["wed_day"] == 1 ? true : false;
                        $t["thu_day"] = $t["thu_day"] == 1 ? true : false;
                        $t["fri_day"] = $t["fri_day"] == 1 ? true : false;
                        $t["sat_day"] = $t["sat_day"] == 1 ? true : false;
                        $t["sun_day"] = $t["sun_day"] == 1 ? true : false;
                        $schedules[] = $t;
                    }
                    $policy['schedules'] = $schedules;

                    #exclusion list.
                    $policy['exclusion_blacklist'] = [];
                    $policy['exclusion_whitelist'] = [];
                    $stmt = $this->database->prepare("select s.site,c.name,s.category_type,c.action,s.is_global from custom_web_category_sites s,custom_web_categories c,policy_custom_web_categories p  where is_global=0 and s.custom_web_categories_id = c.id and p.custom_web_categories_id = c.id and c.name='Blacklisted' and p.policy_id=:policy_id");
                    $stmt->bindValue(':policy_id', $policyId);
                    $results = $stmt->execute();
                    while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                        $policy['exclusion_blacklist'][] = ['value' => $row['site'], 'type' => $row['category_type'], 'is_global' => $row['is_global'] == 1 ? true : false];
                    }
                    $stmt = $this->database->prepare("select s.site,c.name,s.category_type,c.action,s.is_global from custom_web_category_sites s,custom_web_categories c,policy_custom_web_categories p  where is_global=0 and s.custom_web_categories_id = c.id and p.custom_web_categories_id = c.id and c.name='Whitelisted' and p.policy_id=:policy_id");
                    $stmt->bindValue(':policy_id', $policyId);
                    $results = $stmt->execute();
                    while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                        $policy['exclusion_whitelist'][] = ['value' => $row['site'], 'type' => $row['category_type'], 'is_global' => $row['is_global'] == 1 ? true : false];
                    }
                    $policy['exclusion_blacklist_global'] = [];
                    $policy['exclusion_whitelist_global'] = [];
                    $stmt = $this->database->prepare("select * from global_sites where status=1");
                    $results = $stmt->execute();
                    while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                        if ($row['action'] == 'reject') {
                            $policy['exclusion_blacklist_global'][] = ['value' => $row['site'], 'type' => $row['site_type'], 'is_global' => true];
                        }

                        if ($row['action'] == 'accept') {
                            $policy['exclusion_whitelist_global'][] = ['value' => $row['site'], 'type' => $row['site_type'], 'is_global' => true];
                        }
                    }

                    $data_string = json_encode($policy);
                    $this->logger('Send data ' . $data_string);
                    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
                    curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
                }
                $context = curl_exec($curl);
                $ret_http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                curl_close($curl);
                $this->logger('Send data: ' . var_export($policy, true) . ' Cloud Return Code: ' . $ret_http_code . ' : ' . var_export($context, true));
                if ($ret_http_code > 199 & $ret_http_code < 210) {
                    $return_obj = json_decode($context);
                    $stmt = $this->database->prepare('update policies set cloud_id=:cloud_id,is_sync=:is_sync where id=:id');
                    $stmt->bindValue(':id', $policyId);
                    $stmt->bindValue(':cloud_id', isset($return_obj->cloud_id) && !empty($return_obj->cloud_id) ? $return_obj->cloud_id : $policy['cloud_id']);
                    $stmt->bindValue(':is_sync', 0);
                    $results = $stmt->execute();
                    return $response;
                } else {
                    $this->logger("Cloud async :  http Return Code " . $ret_http_code);
                    $stmt = $this->database->prepare('update policies set is_sync=:is_sync where id=:id');
                    $stmt->bindValue(':id', $policyId);
                    $stmt->bindValue(':is_sync', 1);
                    $results = $stmt->execute();
                    $response['error'] = true;
                    $response['message'] = 'Http Error Code is ' . $ret_http_code;
                    return $response;
                }
            } else {
                return $response;
            }
        } catch (\Exception $e) {
            $this->logger("Cloud async :  " . __METHOD__ . '::Exception::' . $e->getMessage());
            $response['error'] = true;
            $response['message'] = $e->getMessage();
            return $response;
        }
    }

    public function haSendData($data)
    {
        if (empty($this->haip)) {
            if (($ret = $this->haGetConfig()) !== true) {
                return $ret;
            }
        }

        $curl = curl_init();
        try {
            curl_setopt($curl, CURLOPT_URL, $this->haip . '/api/sensei/hasync');
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt(
                $curl,
                CURLOPT_HTTPHEADER,
                array(
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($data),
                    'Authorization: Basic ' . base64_encode("{$this->hausername}:{$this->hapassword}"),
                )
            );
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($curl, CURLOPT_TIMEOUT, 40);
            if ($data) {
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            }
            $results = curl_exec($curl);
            $ret_http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            if ($results === false) {
                $this->logger("HA-> Connection Error :  " . $this->haip);
                return ['error' => 'Could not connect to backup FW: ' . $this->haip, 'success' => false];
            } elseif ($ret_http_code >= 400) {
                return ['error' => 'Unauthorized', 'success' => false];
            } else {
                $results = json_decode($results, true);
                $this->logger("HA-> Curl result :  " . $this->haip . '->' . var_export($results, true));
                return ['error' => '', 'success' => true, 'data' => $results];
            }
            return ['error' => 'Connection Error-', 'success' => false];
        } catch (\Exception $e) {
            $this->logger("HA-> Curl exception :  " . __METHOD__ . '::Exception::' . $e->getMessage());
            return ['error' => 'Connection Exception-', 'success' => false];
        }
    }

    public function hasyncPolicy($table = 'policies')
    {
        if (empty($this->haip)) {
            $this->haGetConfig();
        }

        try {
            $this->logger("HA-> Table:$table will be sync to : " . $this->haip);
            $response = [];
            $rows = $this->database->query('SELECT * FROM ' . $table);
            while ($row = $rows->fetchArray($mode = SQLITE3_ASSOC)) {
                $response[] = $row;
            }
            $this->logger("HA-> Table:$table row length " . count($response));
            $data = json_encode([
                'type' => 'settings', 'table' => $table, 'data' => $response,
            ]);
            $return = $this->haSendData($data);
            if (!$return['success']) {
                $this->logger("HA-> Table:$table sync process failed :  " . $return['error']);
            }
            return $return;
        } catch (\Exception $e) {
            $this->logger("HA-> Table:$table sync process exception :  " . __METHOD__ . '::Exception::' . $e->getMessage());
            $return['success'] = false;
            return $return;
        }
    }

    private function hasyncConfig()
    {
        try {
            $response = [];
            foreach (['logger', 'updater', 'anonymize', 'enrich', 'general', 'zenconsole', 'netflow', 'reports', 'shun', 'dns', 'tls', 'streamReportConfig', 'streamReportDataExternal', 'dnsEncrihmentConfig'] as $key) {
                $response[$key] = $this->getNodeByReference($key)->getNodes();
            }
            unset($response['general']['license']);
            unset($response['general']['support']);
            unset($response['general']['installTimestamp']);
            unset($response['general']['flavor']);
            unset($response['general']['database']['Type']);
            unset($response['general']['database']['Prefix']);
            $sensei = new Sensei();
            $landingPage = '';
            if (file_exists($sensei->landingPage) && filesize($sensei->landingPage) > 0) {
                $landingPage = base64_encode(file_get_contents($sensei->landingPage));
            }

            $data = json_encode([
                'type' => 'setConfig', 'data' => $response, 'landingPage' => $landingPage,
            ]);
            $this->logger('Sync of config.xml Starting.....');
            return $this->haSendData($data);
        } catch (\Exception $e) {
            $this->logger("HA-> HA sync config exception :  " . __METHOD__ . '::Exception::' . $e->getMessage());
            return ['error' => 'Connection Exception--', 'success' => false];
        }
    }

    public function haConfig()
    {
        $this->logger('HA config sync');
        # $haconfig_status = (string) $this->getNodeByReference('haconfig.enable');
        # if ($haconfig_status == 'true') {
        $this->logger('HA is active,config.xml will sync');
        $this->haGetConfig();
        return $this->hasyncConfig();
        # }
    }

    public function haNotice($notice_name = 'ha_status_notice_policy')
    {
        if ($this->haGetConfig() !== true) {
            return false;
        }

        $stmt = $this->database->prepare("select count(*) as total from user_notices where status=0 and notice_name=:notice_name");
        $stmt->bindValue(':notice_name', $notice_name);
        $results = $stmt->execute();
        $row = $results->fetchArray($mode = SQLITE3_ASSOC);
        if (intval($row['total']) == 0) {
            $stmt = $this->database->prepare("insert into user_notices(notice_name,notice,create_date) values(:notice_name,:notice,datetime('now'))");
            $stmt->bindValue(':notice_name', $notice_name);
            $stmt->bindValue(':notice', "<p>The changes have been applied successfully, remember to update your Zenarmor backup FW in System: <a href='/ui/sensei/#/configuration/haconfig'>zenarmor/Configuration/HA</a></p>");
            $results = $stmt->execute();
        }
    }

    public function isPremium()
    {
        $backend = new Backend();
        $license = $backend->configdRun('sensei license-details');
        $license = json_decode($license);
        return $license->premium;
    }

    public function saveChanges($ha = true)
    {
        $this->logger('Sensei save changes');
        $this->serializeToConfig();
        $ret = Config::getInstance()->save();
        if ($ha === true && $this->isPremium()) {
            $this->haNotice('ha_status_notice_config');
        }

        return $ret;
    }

    public function getUpdateServerUrl()
    {
        if (file_exists($this->udpateServerConf)) {
            return preg_replace('/\R+/', '', file_get_contents($this->udpateServerConf));
        } else {
            return $this->updateServerDefault;
        }
    }

    public function getUpdateServer()
    {
        if (file_exists($this->config->files->updatesConfiguration)) {
            return preg_replace('/\R+/', '', file_get_contents($this->config->files->updatesConfiguration));
        } else {
            return $this->config->updateServer;
        }
    }

    public function runCLI($commands = [], $pass = '')
    {
        $response = [];
        if (!$this->engineIsRunning()) {
            $response['message'] = 'Engine is not running';
            $this->logger($response['message']);
            return $response;
        }
        $result = $this->database->query('select manage_port from interface_settings');
        $manageports = [];
        while ($row = $result->fetchArray($mode = SQLITE3_ASSOC)) {
            $manageports[] = $row['manage_port'];
        }

        if (file_exists(self::manageSock)) {
            foreach ($manageports as $port) {
                $port = self::manageSock . self::manageSockFile . $port;
                $this->logger('Port is  ' . basename($port), 7 /*Logger::DEBUG*/);
                $response[] = $this->runTelnetCommands($port, '', '', $commands, $response);
            }
        } else {
            $response['message'] = 'Manage Socket folder not found';
            $this->logger($response['message']);
        }

        return $response;
    }

    private function engineIsRunning()
    {
        $backend = new Backend();
        return strpos($backend->configdRun('sensei service eastpect status'), 'is running') !== false;
    }

    private function eastpectConnection($remoteHost, $port, $stream_timeout)
    {
        # $fp = stream_socket_client("tcp://$remoteHost:$port", $errno, $errstr, $stream_timeout);
        if (!file_exists($remoteHost)) {
            $this->logger("Socket file not exits:$remoteHost", 3 /*Logger::ERROR*/);
            return false;
        }

        $fp = stream_socket_client("unix://$remoteHost", $errno, $errstr, $stream_timeout);
        if (!$fp) {
            $this->logger("Connection Error:$errstr ($errno)", 3 /*Logger::ERROR*/);
            return false;
        } else {
            return $fp;
        }
    }

    private function runCommand($socket, $command)
    {
        try {
            $context = '';
            fwrite($socket, $command);
            if ($command == "q") {
                fclose($socket);
                return 'OK';
            }
            if (strpos($command, 'pass') !== false) {
                $command = 'pass **************';
            }

            if (feof($socket)) {
                $this->logger($command . ' Socket was died ', 6 /*Logger::INFO*/);
                return 'ERR Socket was died';
            }
            while (!feof($socket)) {
                $context = fgets($socket, 1024);
                $this->logger($command . ' Result ->' . str_replace('eastpect>', '', $context), 6 /*Logger::INFO*/);
                if (strpos($context, 'OK') !== false) {
                    return 'OK ' . $context;
                }

                if (strpos($context, 'ERR') !== false) {
                    return 'ERR ' . $context;
                }
            }
            return $context;
        } catch (\Exception $e) {
            $this->logger($command . ' Exception ->' . $e->getMessage(), 3 /*Logger::ERROR*/);
            return false;
        }
    }

    private function runTelnetCommands($remoteHost, $port, $pass, $commands, $response)
    {
        $response = ['error' => false, 'port' => basename($remoteHost)];
        $command = '';
        try {
            //  $telnet = new Telnet($remoteHost, $port, 1, '', $this->stream_timeout);
            $telnet = $this->eastpectConnection($remoteHost, $port, $this->stream_timeout);
            if ($telnet !== false) {
                $this->logger($port . ' Port Opened.', 7 /*Logger::DEBUG*/);
                if (!empty($pass)) {
                    $command = 'pass ' . $pass;
                    $result = $this->runCommand($telnet, $command);
                    if (strpos($result, 'OK') !== false) {
                        $response[] = ['command' => 'pass **********', 'result' => 'OK'];
                        //$this->logger('pass **********' . '.-> ' . $result, 6 /*Logger::INFO*/);
                    } else {
                        $response[] = ['command' => 'pass **********', 'result' => 'ERR'];
                        $response['error'] = true;
                        return $response;
                        // $this->logger('pass **********' . '..-> ' . $result, 6 /*Logger::INFO*/);
                    }
                }
                foreach ($commands as $line) {
                    $command = $line;
                    $result = $this->runCommand($telnet, $command);
                    if (strpos($result, 'OK') !== false) {
                        $response[] = ['command' => $command, 'result' => 'OK'];
                        // $this->logger($command . ',-> ' . $result, 6 /*Logger::INFO*/);
                    } else {
                        $response[] = ['command' => $command, 'result' => 'ERR'];
                        $response['error'] = true;
                        // $this->logger($command . ',,-> ' . $result, 6 /*Logger::INFO*/);
                    }
                }
                $result = $this->runCommand($telnet, "q");
            } else {
                $response['error'] = true;
            }
        } catch (\Exception $e) {
            $response[] = ['command' => $command, 'result' => $e->getMessage()];
            $response['error'] = true;
            $this->logger($command . 'Exception ->' . $e->getMessage(), 3 /*Logger::ERROR*/);
        }
        return $response;
    }

    public function getUUID($node)
    {
        foreach ((array) $node as $key => $value) {
            if (strpos($key, 'internalAttributes') !== false and isset($value['uuid'])) {
                return $value['uuid'];
            }
        }
        return null;
    }

    public function sendJson($data, $uri)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $uri);
        curl_setopt($curl, CURLOPT_PORT, 443);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen(json_encode($data)),
        ));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_TIMEOUT, 180);
        if ($data) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        }
        $results = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($results === false) {
            $this->logger($uri . ' could not sended Gateway Timeout', 3 /*Logger::ERROR*/);
        } elseif ($http_code > 210) {
            $this->logger($uri . ' could not sended service Unavailable http code: ' . $http_code, 3 /*Logger::ERROR*/);
        } else {
            $this->logger($uri . ' could sended.', 6 /*Logger::INFO*/);
        }
        curl_close($curl);
    }

    public function getNumberofDevice()
    {
        $numberofdevices = 0;
        try {
            if (file_exists($this->config->dirs->stat)) {
                $files = glob($this->config->dirs->stat . '/worker*.stat');
                foreach ($files as $f) {
                    $finfo = stat($f);
                    if ($finfo !== false && ($finfo['mtime'] + 300) > time()) {
                        $content = file_get_contents($f);
                        $json = json_decode($content, true);
                        $numberofdevices += intval($json['engine_stats']['devices']);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger('getNumberofDevice: Exception ->' . $e->getMessage());
        }
        return $numberofdevices;
    }

    /**
     * generate a new UUID v4 number
     * @return string uuid v4 number
     */
    public function generateUUID()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * http://www.hurriyet.com.tr/sfdsdfa/fsdfsdfsd
     * www.hurriyet.com.tr
     * hurriyet.com.tr
     * 
     * @return string 
     */

    function get_domain($url)
    {
        try {
            $url_ = parse_url($url);
            if (isset($url_['host']))
                preg_match("/[a-z0-9\-]{1,63}\.[a-z\.]{2,6}$/", $url_['host'], $_domain_tld);

            else if (isset($url_['path']))
                preg_match("/[a-z0-9\-]{1,63}\.[a-z\.]{2,6}$/", $url_['path'], $_domain_tld);
            return $_domain_tld[0];
        } catch (\Exception $th) {
            $this->logger('get_domain exception: ' . $url . '->' . $th->getMessage());
            return $url;
        }
    }
}
