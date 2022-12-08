<?php

namespace OPNsense\Sensei\Api;

use \OPNsense\Base\ApiControllerBase;
use \OPNsense\Core\Backend;
use \OPNsense\Core\Config;
use \OPNsense\Sensei\Sensei;
use Phalcon\Config\Adapter\Ini as ConfigIni;

class SettingsController extends ApiControllerBase
{

    const cpu_score_fname = '/usr/local/sensei/etc/sensei_cpu_score';

    const log_file = '/usr/local/sensei/log/active/Senseigui.log';

    public function haindexAction()
    {
        $sensei = new Sensei();
        $config = $this->request->getPost('config');
        $sensei->setNodes($config);
        $sensei->saveChanges(false);
        return 'OK';
    }

    public function indexAction()
    {
        try {
            $sensei = new Sensei();
            $backend = new Backend();
            if ($this->request->getMethod() == 'GET') {
                $response = [];
                $response['hwBypassShow'] = 'false';
                foreach (['general', 'netflow', 'anonymize', 'logger', 'updater', 'reports', 'dns', 'tls', 'enrich', 'streamReportConfig', 'streamReportDataExternal', 'dnsEncrihmentConfig', 'haconfig', 'zenconsole'] as $key) {
                    if ($key == 'reports') {
                        $reports = $sensei->getNodeByReference($key)->getNodes();
                        if (substr($reports['generate']['mail']['password'], 0, 4) == 'b64:')
                            $reports['generate']['mail']['password'] = base64_decode(substr($reports['generate']['mail']['password'], 4));
                        $response[$key] = $reports;
                    } else if ($key == 'streamReportDataExternal') {
                        $reports = $sensei->getNodeByReference($key)->getNodes();
                        if (substr($reports['Pass'], 0, 4) == 'b64:')
                            $reports['Pass'] = base64_decode(substr($reports['Pass'], 4));
                        $response[$key] = $reports;
                    } else if ($key == 'general') {
                        $reports = $sensei->getNodeByReference($key)->getNodes();
                        if (substr($reports['database']['Pass'], 0, 4) == 'b64:')
                            $reports['database']['Pass'] = base64_decode(substr($reports['database']['Pass'], 4));
                        $response[$key] = $reports;
                    } else {
                        $response[$key] = $sensei->getNodeByReference($key)->getNodes();
                    }
                }

                $sensei_version = $sensei->database->querySingle('select creation_date from sensei_version order by id desc', false);
                if (!empty($sensei_version)) {
                    $response['updater']['lastupdate'] = $sensei_version;
                } else if (file_exists($sensei->config['files']['updatesCache'])) {
                    $response['updater']['lastupdate'] = filectime($sensei->config['files']['updatesCache']);
                }

                $sensei_db_version = $sensei->database->querySingle('select creation_date from sensei_db_version order by id desc', false);
                if (!empty($sensei_db_version)) {
                    $response['updater']['lastdbupdate'] = $sensei_db_version;
                } else if (file_exists($sensei->config['files']['updatesDbCache'])) {
                    $response['updater']['lastdbupdate'] = filectime($sensei->config['files']['updatesDbCache']);
                } else {
                    $response['updater']['lastdbupdate'] = $response['updater']['lastupdate'];
                }

                if (file_exists(sensei::bypassHw) && file_exists(sensei::bypassUtil)) {
                    $response['hwBypassShow'] = 'true';
                }

                $this->view->disable();
                $memorysize = trim(shell_exec("sysctl -n hw.physmem"));
                $response["memorysize"] = intval($memorysize);
                header('Content-type:application/json;charset=utf-8');
                echo json_encode($response);
                // return $response;
            } elseif ($this->request->getMethod() == 'POST') {
                $config = $this->request->getPost('config');
                if (isset($config['general']['coreFileEnable'])) {
                    if ($config['general']['coreFileEnable'] == "true") {
                        //kern.corefile: %N.core
                        exec("/sbin/sysctl -i kern.corefile=/root/%N.%P.core", $output, $success);
                        if ($success == 0) {
                            $sensei->logger("Change kern.corefile as true parameter in kernel.");
                        } else {
                            $sensei->logger("could'not change kern.corefile  as true parameter in kernel.");
                        }
                    }
                    if ($config['general']['coreFileEnable'] == "false") {
                        exec("/sbin/sysctl -i kern.corefile=%N.core", $output, $success);
                        if ($success == 0) {
                            $sensei->logger("Change kern.corefile as false parameter in kernel.");
                        } else {
                            $sensei->logger("could'not change kern.corefile as false parameter in kernel.");
                        }
                    }
                }
                if (isset($config['reports']['generate']['mail']['password'])) {
                    $config['reports']['generate']['mail']['password'] = 'b64:' . base64_encode($config['reports']['generate']['mail']['password']);
                }

                if (isset($config['streamReportDataExternal']['Pass'])) {
                    $config['streamReportDataExternal']['Pass'] = 'b64:' . base64_encode($config['streamReportDataExternal']['Pass']);
                }
                /*
                if (isset($config['retireAfter'])) {
                    $config['general'] = ['database' => ['retireAfter' => $config['retireAfter']]];
                }
                */
                $sensei->setNodes($config);

                $mode = $this->request->getPost('mode', null, 'routed');

                $xml_config = Config::getInstance()->object();
                $wan_interfaces = [];
                $wan_interfaces_netstat = [];
                exec("netstat -4rn | grep default | awk '{print $4}'", $output, $return);
                if ($return == 0) {
                    $wan_interfaces_netstat = $output;
                }
                if ($xml_config->interfaces->count() > 0) {
                    foreach ($xml_config->interfaces->children() as $key => $node) {
                        $desc = strtoupper(!empty((string) $node->descr) ? (string) $node->descr : $key);
                        $interface = (string) $node->if;
                        if ($desc == 'WAN') {
                            $wan_interfaces[] = $interface;
                        }
                    }
                }

                if ($mode != 'bridge' && $this->request->hasPost('interfaces')) {
                    $this->configureInterfaces($sensei, $this->request->getPost('interfaces'), $mode, $wan_interfaces, $wan_interfaces_netstat);
                }

                if ($mode == 'bridge' && $this->request->hasPost('binterfaces')) {
                    $this->configureBInterfaces($sensei, $this->request->getPost('binterfaces'), $mode);
                }

                if ($this->request->hasPost('shunnetworks')) {
                    $this->configureShunNetworks($sensei, $this->request->getPost('shunnetworks'));
                }

                if ($this->request->hasPost('nodes')) {
                    $this->configureCloudNodes($sensei, $backend, $this->request->getPost('nodes'));
                }
                $this->generateTcpPasswordSha256($sensei);
                $sensei->saveChanges();

                if ($this->request->hasPost('retireAfterChanged') and $this->request->getPost('retireAfterChanged') == 'true') {
                    $dbtype = (string) $sensei->getNodeByReference('general.database.Type');
                    $backend->configdRun('sensei datastore-retire ' . $dbtype, true);
                }
                // $this->generateTcpPasswordSha256($sensei);
                $sensei->saveChanges();
                $result = $backend->configdRun('sensei setadmode ' . $mode);
                return $sensei->getNodeByReference('general')->getNodes();
                //return 'OK';
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return [];
        }
    }

    public function allAction()
    {
        try {
            $sensei = new Sensei();
            return $sensei->getNodes();
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return [];
        }
    }

    public function writeAction()
    {
        try {
            $backend = new Backend();
            $backend->configdRun('template reload OPNsense/Sensei');
            $backend->configdRun('sensei worker reload');
            $backend->configdRun('sensei cloud-sighup');
            return $backend->configdRun('sensei policy reload');
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return [];
        }
    }

    private function getInterfaceQueu($interface)
    {
        try {
            $number = '';
            $ifname = '';
            if (preg_match_all('/\d+/', $interface, $matches)) {
                $number = $matches[0][0];
                $ifname = substr($interface, 0, strlen($interface) - strlen($number));
            } else {
                $ifname = $interface;
            }

            exec('sysctl dev.' . $ifname . ($number != '' ? '.' . $number : '') . ' | grep rx_packets', $output, $return);
            if ($return == 0) {
                return count($output);
            }
            return 0;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return 0;
        }
    }

    // bride mode
    private function configureShunNetworks($sensei, $networks)
    {
        try {
            $stmt = $sensei->database->prepare('delete from shun_networks');
            $stmt->execute();

            //insert networks for bridge
            $stmt = $sensei->database->prepare('INSERT INTO shun_networks(type,network,desc,status) VALUES(1,:network,:desc,:status)');
            $network_str = [];
            if (isset($networks['networks']) && is_array($networks['networks'])) {
                foreach ($networks['networks'] as $net) {
                    $stmt->bindValue(':network', $net['network']);
                    $stmt->bindValue(':desc', $net['desc']);
                    $stmt->bindValue(':status', $net['status'] == 'true' ? 1 : 0);
                    $stmt->execute();
                    if ($net['status'] == 'true') {
                        $network_str[] = $net['network'];
                    }
                }
            }
            //insert networks for bridge
            $stmt = $sensei->database->prepare('INSERT INTO shun_networks(type,network,desc,status) VALUES(2,:network,:desc,:status)');
            $vlan_str = [];
            if (isset($networks['vlans']) && is_array($networks['vlans'])) {
                foreach ($networks['vlans'] as $net) {
                    $stmt->bindValue(':network', $net['network']);
                    $stmt->bindValue(':desc', $net['desc']);
                    $stmt->bindValue(':status', $net['status'] == 'true' ? 1 : 0);
                    $stmt->execute();
                    if ($net['status'] == 'true') {
                        $vlan_str[] = $net['network'];
                    }
                }
            }
            $node = $sensei->getNodeByReference('shun');
            $node->setNodes([
                'networks' => implode(',', $network_str),
                'vlans' => implode(',', $vlan_str),
            ]);
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return 'error';
        }
    }

    private function configureBInterfaces($sensei, $interfaces, $mode)
    {
        try {
            $backend = new Backend();
            $cpuCount = intval(trim($backend->configdRun('sensei cpu-count'), "\n"));
            $stmt = $sensei->database->prepare('delete from interface_settings');
            if (!$results = $stmt->execute()) {
                return false;
            }

            $dberrorMsg = [];
            $manage_port = 4343;
            $cpu_index = 0;
            foreach ($interfaces as $index => $interface) {
                // get queu info
                //  $lan_queue = $this->getInterfaceQueu($interface['lan']['interface']);
                //  $wan_queue = $this->getInterfaceQueu($interface['wan']['interface']);
                $tags = 'netmap;bridgemode';
                $stmt = $sensei->database->prepare('insert into interface_settings(mode,name,lan_interface,lan_desc,lan_queue,
                wan_interface,wan_desc,wan_queue,queue,cpu_index,manage_port,description,create_date,tags)' .
                    ' values(:mode,:name,:lan_interface,:lan_desc,:lan_queue,:wan_interface,:wan_desc,:wan_queue,:queue,:cpu_index,:manage_port,:description,datetime(\'now\'),:tags)');
                /*
                if ($lan_queue == $wan_queue && $wan_queue > 0) {
                for ($qindex = 0; $qindex < $lan_queue; $qindex++) {
                $stmt->bindValue(':mode', $mode);
                $stmt->bindValue(':name', $interface['bname']);
                $stmt->bindValue(':lan_interface', $interface['lan']['interface']);
                $stmt->bindValue(':lan_desc', $interface['lan']['description']);
                $stmt->bindValue(':wan_interface', $interface['wan']['interface']);
                $stmt->bindValue(':wan_desc', $interface['wan']['description']);
                $stmt->bindValue(':lan_queue', $qindex);
                $stmt->bindValue(':wan_queue', $qindex);
                $stmt->bindValue(':queue', $qindex);
                $stmt->bindValue(':description', $interface['description']);
                $stmt->bindValue(':manage_port', (string) $manage_port++);
                $stmt->bindValue(':cpu_index', $cpuCount > 1 ? (string) (($cpu_index % $cpuCount) + 1) : '0');
                $stmt->bindValue(':tags', $tags);
                if (!$stmt->execute()) {
                $dberrorMsg[] = $sensei->database->lastErrorMsg();
                }
                $cpu_index++;
                }
                } else {
                 */
                $stmt->bindValue(':mode', $mode);
                $stmt->bindValue(':name', $interface['bname']);
                $stmt->bindValue(':lan_interface', $interface['lan']['interface']);
                $stmt->bindValue(':lan_desc', $interface['lan']['description']);
                $stmt->bindValue(':wan_interface', $interface['wan']['interface']);
                $stmt->bindValue(':wan_desc', $interface['wan']['description']);
                $stmt->bindValue(':lan_queue', '');
                $stmt->bindValue(':wan_queue', '');
                $stmt->bindValue(':queue', $index);
                $stmt->bindValue(':description', $interface['description']);
                $stmt->bindValue(':manage_port', (string) $manage_port++);
                $stmt->bindValue(':cpu_index', $cpuCount > 1 ? (string) (($cpu_index % $cpuCount) + 1) : '0');
                $stmt->bindValue(':tags', $tags);
                if (!$stmt->execute()) {
                    $dberrorMsg[] = $sensei->database->lastErrorMsg();
                }
                $cpu_index++;
                //   }
            }
            if (count($dberrorMsg) > 0) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return false;
        }
    }

    // routed mode
    private function configureInterfaces($sensei, $interfaces, $mode, $wan_interfaces, $wan_interfaces_netstat)
    {
        try {
            $backend = new Backend();
            $cpuCount = intval(trim($backend->configdRun('sensei cpu-count'), "\n"));
            $stmt = $sensei->database->prepare('delete from interface_settings');
            if (!$results = $stmt->execute()) {
                return false;
            }

            $dberrorMsg = [];
            $index = 0;
            foreach ($interfaces as $index => $interface) {
                $tags = '';
                if ($mode == 'routed' || $mode == 'routedG') {
                    $tags = 'netmap;routedmode';
                    $sensei->logger(var_export($wan_interfaces, true));
                    $sensei->logger(var_export($wan_interfaces_netstat, true));
                    if (array_search($interface['interface'], $wan_interfaces) !== false || array_search($interface['interface'], $wan_interfaces_netstat) !== false) {
                        $tags = 'wan;' . $tags;
                    }
                }

                $stmt = $sensei->database->prepare('insert into interface_settings(mode,lan_interface,lan_desc,cpu_index,manage_port,create_date,tags)' .
                    ' values(:mode,:lan_interface,:lan_desc,:cpu_index,:manage_port,datetime(\'now\'),:tags)');
                $stmt->bindValue(':mode', $mode);
                $stmt->bindValue(':lan_interface', $interface['interface']);
                $stmt->bindValue(':lan_desc', $interface['description']);
                $stmt->bindValue(':manage_port', (string) (4343 + $index));
                $stmt->bindValue(':cpu_index', $cpuCount > 1 ? (string) (($index % ($cpuCount - 1)) + 1) : '0');
                $stmt->bindValue(':tags', $tags);
                if (!$stmt->execute()) {
                    $dberrorMsg[] = $sensei->database->lastErrorMsg();
                }
            }
            if (count($dberrorMsg) > 0) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return false;
        }
    }

    private function configureCloudNodes($sensei, $backend, $nodes)
    {
        try {
            $config = [];
            foreach ($nodes as $node) {
                $node6 = '';
                $node4 = '';
                $port = 53;
                if (isset($node['inet6'])) {
                    //            if (isset($node['inet6']) && $node['inet6']['available'] == true)
                    $node6 = $node['inet6']['ip'] . ',';
                    $port = $node['inet6']['port'];
                }
                if (isset($node['inet4'])) {
                    $node4 = $node['inet4']['ip'] . ',';
                    $port = $node['inet4']['port'];
                }
                array_push($config, $node['name'] . ',' . $node4 . $node6 . $port);
            }
            file_put_contents($sensei->cloudReputationServersFile, implode("\n", $config) . "\n");
            $backend->configdRun('sensei nodes-status rewrite');
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return false;
        }
    }

    public function interfacesAction($mode = 'routed')
    {
        try {
            $interfaces = [];
            $sensei = new Sensei();
            $stmt = $sensei->database->prepare('SELECT distinct name,lan_interface,lan_desc,wan_interface,wan_desc,description FROM interface_settings WHERE mode=:mode order by id');
            $stmt->bindValue(':mode', $mode);
            if (!$results = $stmt->execute()) {
                return false;
            }

            while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                $interfaces[] = $row;
            }
            return $interfaces;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return [];
        }
    }

    public function interfacesModeAction()
    {
        try {
            $sensei = new Sensei();
            $deployment = $sensei->database->querySingle('select mode from interface_settings limit 1', false);
            return $deployment;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return [];
        }
    }

    public function landingPageAction()
    {
        try {
            $sensei = new Sensei();
            $landingPage = $sensei->landingPage;
            if ($this->request->getMethod() == 'GET') {
                if (file_exists($landingPage)) {
                    if ($this->request->hasQuery('download')) {
                        $this->response->setHeader('Content-Type', 'text/html; charset=utf-8');
                        $this->response->setHeader('Content-Disposition', 'attachment; filename="sensei_blocked_connection_template.html"');
                    }
                    return file_get_contents($landingPage);
                } else {
                    return '<html><head><title>Not Found!</title></head><body><h3>An HTML template has not been uploaded yet!</h3></body></html>';
                }
            } else {
                $landingPage = $sensei->landingPage;
                $landingPageDir = dirname($landingPage);
                if (!file_exists($landingPageDir)) {
                    mkdir($landingPageDir);
                }
                move_uploaded_file($_FILES['file']['tmp_name'], $landingPage);
                return 'OK';
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return 'OK';
        }
    }

    private function generateTcpPasswordSha256($sensei)
    {
        try {
            $tcpServicePsk = (string) $sensei->getNodeByReference('enrich.tcpServicePsk');
            $tcpServicePskSha256 = hash('sha256', $tcpServicePsk);
            $sensei->setNodes([
                'enrich' => [
                    'tcpServicePskSha256' => $tcpServicePskSha256,
                ],
            ]);
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return false;
        }
    }

    public function certsAction()
    {
        try {
            if ($this->request->getMethod() == 'GET') {
                $certs = [];
                $config = (array) Config::getInstance()->object();
                if (isset($config['ca'])) {
                    if (isset($config['ca']['descr'])) {
                        $cert = (array) $config['ca'];
                        if (!empty($cert['prv'])) {
                            array_push($certs, [
                                'id' => 0,
                                'name' => $cert['descr'],
                            ]);
                        }
                    } else {
                        foreach ($config['ca'] as $key => $node) {
                            $node = (array) $node;
                            if (!empty($node['prv'])) {
                                array_push($certs, [
                                    'id' => $key,
                                    'name' => $node['descr'],
                                ]);
                            }
                        }
                    }
                }
                return $certs;
            } elseif ($this->request->getMethod() == 'POST') {
                $sensei = new Sensei();
                if (!file_exists(dirname($sensei->certFile))) {
                    mkdir(dirname($sensei->certFile), 0700, true);
                }
                if ($this->request->getPost('cert') && $this->request->getPost('prvt')) {
                    file_put_contents($sensei->certFile, $this->request->getPost('cert'));
                    file_put_contents($sensei->certKeyFile, $this->request->getPost('prvt'));
                }
                return 'OK';
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return 'OK';
        }
    }

    public function tlsWhiteListAction()
    {
        try {
            $sensei = new Sensei();
            $response = [];
            if ($this->request->getMethod() == 'GET') {
                $response['enabled'] = file_exists($sensei->tlsWhitelistFile);
            } elseif ($this->request->getMethod() == 'POST') {
                $enabled = $this->request->getPost('enabled');
                $defaultFile = $sensei->tlsWhitelistFileDefault;
                $rulesFile = $sensei->tlsWhitelistFile;
                if ($enabled == 'true') {
                    if (!file_exists($rulesFile) && file_exists($defaultFile)) {
                        symlink($defaultFile, $rulesFile);
                    }
                } else {
                    if (file_exists($rulesFile)) {
                        unlink($rulesFile);
                    }
                }
                $response['enabled'] = file_exists($sensei->tlsWhitelistFile);
            }
            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return ['enabled' => false];
        }
    }

    public function reportsAction()
    {
        try {
            $sensei = new Sensei();
            if ($this->request->getMethod() == 'GET') {
                return $sensei->getNodeByReference('reports')->getNodes();
            } elseif ($this->request->getMethod() == 'POST') {
                $config = $this->request->getPost('config');
                $sensei->getNodeByReference('reports')->setNodes($config);
                $sensei->saveChanges();
                return 'OK';
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return 'Error';
        }
    }

    public function resetAction()
    {
        try {
            $backend = new Backend();
            $response = [];
            $response['output'] = $backend->configdRun('sensei reset');
            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return [];
        }
    }

    public function hardwareAction()
    {

        try {
            $this->response->setHeader('Content-Type', 'application/json; charset=utf-8');
            $backend = new Backend();
            $response =  $backend->configdRun('sensei hardware');
            $response = json_decode($response, true);
            exec('cat /usr/local/etc/pkg/repos/*.conf | grep -c "opn-repo.routerperformance.net"', $output, $retval);
            $response['mimugmail'] = 0;
            if ($retval  == 0 && intval($output[0]) > 0) {
                $response['mimugmail'] = true;
            }
            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            $json = '{
                "memory": {
                    "size": 0,
                    "proper": true
                },
                "cpu": {
                    "model": "XXX Processor",
                    "proper": true,
                    "score": 0
                },
                "opnsense_version": "0.0.0", 
                "mimugmail" : false
             }
             ';
            return json_decode($json, true);
        }
    }

    public function createTokenAction()
    {
        try {
            $token = bin2hex(random_bytes(16));
            $date = date('Y-m-d H:i:s');
            $tokenlist = [];
            if (!file_exists(dirname(Sensei::restTokenFile))) {
                mkdir(dirname(Sensei::restTokenFile), 0777, true);
            }
            if (file_exists(Sensei::restTokenFile)) {
                $tokenlist = json_decode(file_get_contents(Sensei::restTokenFile));
            }

            $tokenlist[] = ['token' => $token, 'status' => true, 'create_date' => $date];
            $size = file_put_contents(Sensei::restTokenFile, json_encode($tokenlist));
            if ($size == 0) {
                return ['token' => '', 'errorMsg' => 'Could not create new token. Please try again'];
            }

            return ['token' => $token, 'errorMsg' => ''];
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return ['token' => '', 'errorMsg' => $e->getMessage()];
        }
    }

    public function tokenListAction()
    {
        try {
            $response = [];
            if (file_exists(Sensei::restTokenFile)) {
                $response = json_decode(file_get_contents(Sensei::restTokenFile));
            }

            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return [];
        }
    }

    public function setTokenAction()
    {
        try {
            $p_token = $this->request->getPost('token');
            $p_status = $this->request->getPost('status');
            $p_del = $this->request->getPost('delete', null, 0);
            $tokenlist = json_decode(file_get_contents(Sensei::restTokenFile));
            $tmplist = [];
            foreach ($tokenlist as $key => $token) {
                if ($token->token == $p_token) {
                    if ($p_del == 0) {
                        $tokenlist[$key]->status = $p_status == 'true' ? true : false;
                        $tmplist[] = $tokenlist[$key];
                    }
                } else {
                    $tmplist[] = $token;
                }
            }
            $size = file_put_contents(Sensei::restTokenFile, json_encode($tmplist));
            if ($size == 0) {
                return ['errorMsg' => 'Could not create new token. Please try again'];
            }

            return ['result' => 'OK', 'errorMsg' => ''];
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return ['result' => 'Err', 'errorMsg' => $e->getMessage()];
        }
    }

    public function setUserSettingsAction()
    {
        try {
            $sensei = new Sensei();
            $key = $this->request->getPost('key', null, '');
            $value = $this->request->getPost('value', null, '');
            $default = $this->request->getPost('default', null, false);
            $sensei->logger(__METHOD__ . ' take values -> ' . var_export($default, true));
            $user = $_SESSION['Username'];

            if ($default) {
                $stmt = $sensei->database->prepare("delete from user_configuration where user=:user and key like '%Chart'");
                $stmt->bindValue(':user', $user);
                $stmt->execute();
                return 'OK';
            }
            if (empty($key) || empty($value)) {
                $sensei->logger(__METHOD__ . " : Null value $key , $value");
                return 'OK';
            }
            $stmt = $sensei->database->prepare("select * from user_configuration where user=:user and key=:key");
            $stmt->bindValue(':key', $key);
            $stmt->bindValue(':user', $user);
            $results = $stmt->execute();
            if ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                $stmt = $sensei->database->prepare("update user_configuration set value=:value where user=:user and key=:key");
            } else {
                $stmt = $sensei->database->prepare("insert into user_configuration(user,key,value) values(:user,:key,:value)");
            }
            $stmt->bindValue(':user', $user);
            $stmt->bindValue(':key', $key);
            $stmt->bindValue(':value', json_encode($value));
            $stmt->execute();
            return 'OK';
        } catch (\Exception $e) {
            $sensei->logger(__METHOD__ . ' ::Exception:: ' . $e->getMessage());
            return 'ERR';
        }
    }

    public function getUserSettingsAction()
    {
        try {
            $sensei = new Sensei();
            $user = $_SESSION['Username'];
            $stmt = $sensei->database->prepare("select key,value from user_configuration where user=:user");
            $stmt->bindValue(':user', $user);
            $results = $stmt->execute();
            $response = [];
            while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                $row['value'] = json_decode($row['value']);
                $response[] = $row;
            }
            $response[] = ['key' => 'cloudWebcatEnrich', 'value' => (string) $sensei->getNodeByReference('enrich.cloudWebcatEnrich')];
            header('Content-type:application/json;charset=utf-8');
            echo json_encode($response);
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return 'ERR';
        }
    }

    public function setUserReportAction()
    {
        try {
            $sensei = new Sensei();
            $id = $this->request->getPost('id', null, 0);
            $key = $this->request->getPost('key');
            $value = $this->request->getPost('value');
            $user = $_SESSION['Username'];
            if ($id > 0) {
                $stmt = $sensei->database->prepare("update report_configuration set value=:value,key=:key where id=:id");
                $stmt->bindValue(':id', $id);
            } else {
                $stmt = $sensei->database->prepare("insert into report_configuration(user,key,value) values(:user,:key,:value)");
            }
            $stmt->bindValue(':user', $user);
            $stmt->bindValue(':key', $key);
            $stmt->bindValue(':value', json_encode($value));
            $stmt->execute();
            return 'OK';
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return 'ERR';
        }
    }

    public function getUserReportAction()
    {
        try {
            $sensei = new Sensei();
            $user = $_SESSION['Username'];
            $stmt = $sensei->database->prepare("select id,key,value from report_configuration where user=:user");
            $stmt->bindValue(':user', $user);
            $results = $stmt->execute();
            $response = [];
            while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                $row['value'] = json_decode($row['value']);
                $response[] = $row;
            }
            // $response[] = ['key' => 'cloudWebcatEnrich', 'value' => (string) $sensei->getNodeByReference('enrich.cloudWebcatEnrich')];
            header('Content-type:application/json;charset=utf-8');
            echo json_encode($response);
        } catch (\Exception $e) {
            $sensei->logger(__METHOD__ . '::Exception::' . $e->getMessage());
            return 'ERR';
        }
    }

    public function deleteUserReportAction()
    {
        try {
            $sensei = new Sensei();
            $id = $this->request->getPost('id');
            $stmt = $sensei->database->prepare("delete from report_configuration where id=:id");
            $stmt->bindValue(':id', $id);
            $results = $stmt->execute();
            $lastId = $sensei->database->querySingle("select id from report_configuration order by id desc limit 1", true);
            return $lastId;
        } catch (\Exception $e) {
            $sensei->logger(__METHOD__ . '::Exception::' . $e->getMessage());
            return 'ERR';
        }
    }

    private function getinfo(&$data)
    {
        try {
            $sensei = new Sensei();
            $opnsense_version = trim(shell_exec('opnsense-version'));
            $hostuuid = "";
            $iflist = explode(' ', str_replace(PHP_EOL, '--', trim(shell_exec("ifconfig -lu"))));

            $stmt = $sensei->database->prepare("select lan_interface,wan_interface from interface_settings");
            $results = $stmt->execute();
            $workers = [];

            while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                if ($row['lan_interface'] == $row['wan_interface']) {
                    $workers[] = $row['lan_interface'];
                }
                if ($row['lan_interface'] != $row['wan_interface'] && $row['wan_interface'] != "") {
                    $workers[] = $row['lan_interface'];
                    $workers[] = $row['wan_interface'];
                }
                if ($row['wan_interface'] == "") {
                    $workers[] = $row['lan_interface'];
                }
            }

            foreach ($workers as $interface) {
                foreach ($iflist as $k => $v) {
                    if ($v == $interface) {
                        $iflist[$k] .= '*';
                    }
                }
            }

            $data['interfaces'] = implode(', ', $iflist);
            $data['platform_version'] = $opnsense_version;
            if (file_exists('/usr/local/sensei/bin/eastpect')) {
                $hostuuid = trim(shell_exec('/usr/local/sensei/bin/eastpect -s'));
                $data['zenarmor_version'] = trim(shell_exec('/usr/local/sensei/bin/eastpect -V|grep Release'));
            } else {
                $data['zenarmor_version'] = '';
            }

            $data['zenarmor_agent_version'] = '';
            exec("pkg info os-sensei-agent | grep Version | awk -F ': ' '{ print $2 }'", $output, $return);
            if ($return == 0) {
                $data['zenarmor_agent_version'] = count($output) > 0 ? $output[0] : '';
            }

            $data['hostuuid'] = $hostuuid;
            //$data['hostcpu'] = trim(shell_exec("sysctl hw.model | awk -F': ' '{print $2 }'"));
            $data['cpu'] = ['model' => trim(shell_exec("sysctl hw.model | awk -F': ' '{print $2 }'")), 'machine' => trim(shell_exec("sysctl hw.machine | awk -F': ' '{print $2 }'")), 'ncore' => trim(shell_exec("sysctl hw.ncpu | awk -F': ' '{print $2 }'"))];
            $data['meminfo'] = intval(trim(shell_exec("sysctl hw.realmem | awk -F': ' '{print $2 }'")));
            $data['license_key'] = '';
            $data['license_plan'] = '';
            $sensei = new Sensei();
            $data['ndevice'] = $sensei->getNumberofDevice();
            try {
                $data['license_key'] = (string) $sensei->getNodeByReference('general.license.key');
                $data['license_plan'] = (string) $sensei->getNodeByReference('general.license.plan');
            } catch (\Exception $th) {
            }
            if (empty($data['license_key'])) {
                $data['license_plan'] = 'free';
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return [];
        }
    }

    public function setClientEmailAction()
    {
        $sensei = new Sensei();
        try {
            $email = $this->request->getPost('email', null, '');
            $data = ['email' => $email];
            $cpu_score = 0;
            if (file_exists(self::cpu_score_fname)) {
                $cpu_score = trim(file_get_contents(self::cpu_score_fname));
            }
            $data['cpuscore'] = $cpu_score;
            $data['install_time'] = intval((string) $sensei->getNodeByReference('general.installTimestamp'));
            $this->getinfo($data);
            #$sensei->sendJson($data, 'https://health.sunnyvalley.io/email_sensei.php');
            $sensei->sendJson($data, Sensei::installInfoApi);
            $sensei->logger("Install Info: " . var_export($data, true));
            return ['result' => 'OK'];
        } catch (\Exception $e) {
            $sensei->logger(__METHOD__ . '::Exception::' . $e->getMessage());
            return ['result' => 'ERR'];
        }
    }

    public function sendClientReportAction()
    {
        $sensei = new Sensei();
        $hostuuid = trim(shell_exec('/usr/local/sensei/bin/eastpect -s'));
        $log_path = "/tmp/$hostuuid";
        $log_file_name = "$log_path.tar";
        shell_exec("rm -rf $log_path*");
        try {
            $data = $this->request->getPost('data', null, '');
            $cpu_score = 0;
            if (file_exists(self::cpu_score_fname)) {
                $cpu_score = trim(file_get_contents(self::cpu_score_fname));
            }
            $data['cpuscore'] = $cpu_score;
            $data['license_key'] = '';
            $data['license_plan'] = '';
            try {
                $data['license_key'] = (string) $sensei->getNodeByReference('general.license.key');
                $data['license_plan'] = (string) $sensei->getNodeByReference('general.license.plan');
            } catch (\Exception $th) {
            }
            if (empty($data['license_key'])) {
                $data['license_plan'] = 'freemium';
            }

            $data['log_binary'] = '';
            if ($data['log'] == 'true') {
                shell_exec('/usr/local/opnsense/scripts/OPNsense/Sensei/log_prepare.sh');
            }
            // $data['config_binary'] = '';
            if ($data['config'] == 'true') {
                shell_exec('/usr/local/opnsense/scripts/OPNsense/Sensei/config_prepare.sh');
            }
            if ($data['systemlog'] == 'true') {
                shell_exec('/usr/local/opnsense/scripts/OPNsense/Sensei/system_log_prepare.sh');
            }
            if (file_exists($log_path)) {
                shell_exec("tar -cvf $log_file_name $log_path;gzip $log_file_name");
                if (file_exists("$log_file_name.gz")) {
                    $data['binary'] = base64_encode(file_get_contents($log_file_name . ".gz"));
                }
            }

            $this->getinfo($data);
            $sensei->sendJson($data, 'https://health.sunnyvalley.io/client_report.php');
            return ['result' => 'OK'];
        } catch (\Exception $e) {
            $sensei->logger(__METHOD__ . '::Exception::' . $e->getMessage());
            return ['result' => 'ERR'];
        }
    }

    public function sendUninstallDataAction()
    {

        try {
            $sensei = new Sensei();
            if ($this->request->getMethod() == 'GET') {
                $email = (string) $sensei->getNodeByReference('general.clientemail');
                return ['email' => $email];
            }
            $data = $this->request->getPost('list', null, []);
            foreach ($data as $k => $v) {
                if ($v == "true" || $v == "false") {
                    $data[$k] = ($v == "true") ? true : false;
                }
            }
            $cpu_score = $this->request->getPost('cpuscore', null, 0);
            if (empty($cpu_score) && $cpu_score == '0') {
                if (file_exists(self::cpu_score_fname)) {
                    $cpu_score = intval(trim(file_get_contents(self::cpu_score_fname)));
                }
            }

            $data['cpuscore'] = $cpu_score;
            $data['install_time'] = intval((string) $sensei->getNodeByReference('general.installTimestamp'));
            $data['uninstall_time'] = time();
            $this->getinfo($data);
            $interfaces = trim(shell_exec('cat /usr/local/sensei/etc/workers.map | grep -v "^#" | grep @ | head -10 | cut -d"," -f3 | cut -d"@" -f2 | awk \'{$1=$1};1\''));
            if ($data['unsupported_adapter']) {
                $ifconfig = htmlspecialchars(trim(shell_exec('ifconfig -l')));
                $data['ifconfig'] = $ifconfig;
            }
            $interfaces = array_map(function ($v) {
                return str_replace('^', '', $v);
            }, explode(PHP_EOL, $interfaces));

            $data['interfaces'] = implode(',', $interfaces);
            if (empty($data['email'])) {
                $data['email'] = (string) $sensei->getNodeByReference('general.clientemail');
            }
            //$sensei->logger('JSON:' . var_export($data, true));
            // $sensei->sendJson($data, 'https://health.sunnyvalley.io/uninstall_sensei.php');
            $config = new ConfigIni(Sensei::eastpect_config);
            //$sensei->sendJson($data, 'https://health.sunnyvalley.io/uninstall_sensei.php');
            $sensei->sendJson($data, $config->senpai["node-register-address"] . "/api/v1/nodes/reports/uninstall");
            return 'OK';
        } catch (\Exception $e) {
            $sensei = new Sensei();
            $sensei->logger(__METHOD__ . '::Exception::' . $e->getMessage());
            return 'ERR';
        }
    }

    public function reportSettingsAction()
    {
        try {
            $sensei = new Sensei();
            $response = ['successful' => true];
            if ($this->request->getMethod() == 'GET') {
                if (file_exists($sensei->config->files->reportsConfig)) {
                    $contents = file_get_contents($sensei->config->files->reportsConfig);
                    $response['config'] = json_decode($contents, true);
                } else {
                    $response['config'] = [];
                }
            }
            if ($this->request->getMethod() == 'POST') {
                $size = file_put_contents($sensei->config->files->reportsConfig, json_encode($this->request->getPost('config', null, [])));
                if ($size == 0) {
                    $response['successful'] = false;
                    $response['message'] = 'File not saved';
                }
            }
            return $response;
        } catch (\Exception $e) {
            $sensei = new Sensei();
            $sensei->logger(__METHOD__ . '::Exception::' . $e->getMessage());
            return [];
        }
    }

    public function getversionAction()
    {
        $check = trim(substr(shell_exec('opnsense-version | sed "s/\.//g" | awk \'{ print $2 }\''), 0, 4));
        $check = preg_replace('/[a-zA-Z]/', '0', $check);
        $check .= str_repeat("0", 4 - strlen($check));
        return (int) $check;
    }

    public function dbinfoAction()
    {
        $sensei = new Sensei();
        $path = '';
        try {
            //code...
            $dbtype = (string) $sensei->getNodeByReference('general.database.Type');
            $prefix = (string) $sensei->getNodeByReference('general.database.Prefix');
            if ($dbtype == 'MN') {
                exec('grep -E "^  dbPath" /usr/local/etc/mongodb.conf', $path, $ret_val);
                if ($ret_val == 0) {
                    $path = trim(explode(':', $path[0])[1]);
                }

                return ['path' => $path, 'dbname' => $sensei::reportDatabases['MN']['name'], 'dbtype' => 'MN', 'dburi' => '', 'prefix' => $prefix];
            }
            if ($dbtype == 'ES') {
                exec('grep -E "^path.data" /usr/local/etc/elasticsearch/elasticsearch.yml', $path, $ret_val);
                if ($ret_val == 0) {
                    $path = trim(explode(':', $path[0])[1]);
                }

                $dburi = (string) $sensei->getNodeByReference('general.database.Host') . ':' . (string) $sensei->getNodeByReference('general.database.Port');
                return [
                    'path' => $path, 'dbname' => $sensei::reportDatabases['ES']['name'], 'dbtype' => 'ES', 'remote' => (string) $sensei->getNodeByReference('general.database.Remote') == 'true',
                    'dburi' => $dburi, 'user' => (string) $sensei->getNodeByReference('general.database.User'), 'pass' => (string) $sensei->getNodeByReference('general.database.Pass'), 'prefix' => $prefix,
                ];
            }
            if ($dbtype == 'SQ') {
                return ['path' => (string) $sensei->getNodeByReference('general.database.dbpath'), 'dbname' => $sensei::reportDatabases['SQ']['name'], 'dbtype' => 'SQ', 'dburi' => '', 'prefix' => ''];
            }
        } catch (\Exception $e) {
            $sensei->logger(__METHOD__ . '::Exception::' . $e->getMessage());
            return ['path' => $path];
        }
    }

    public function changeDbpathAction()
    {
        try {
            $dbtype = $this->request->getPost('dbtype', null, '');
            $newpath = $this->request->getPost('newpath', null, '');
            $oldpath = $this->request->getPost('oldpath', null, '');
            $dburi = $this->request->getPost('dburi', null, '');
            if ($newpath != '' && $oldpath != '' && $newpath != $oldpath && ($dbtype == 'ES' || $dbtype == 'MN' || $dbtype == 'SQ')) {
                $backend = new Backend();
                $result = $backend->configdRun("sensei change-data-path $dbtype $oldpath $newpath");
                $sensei = new Sensei();
                $sensei->getNodeByReference('general.database')->setNodes([
                    'dbpath' => $newpath,
                ]);
                $sensei->saveChanges();
                $backend = new Backend();
                $backend->configdRun('template reload OPNsense/Sensei');
                return 'OK';
            }

            return 'Error:missing parameters';
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return 'Error:' . $e->getMessage();
        }
    }

    private function setIndexes()
    {
        try {
            $sensei = new Sensei();
            $prefix = (string) $sensei->getNodeByReference('general.database.Prefix');
            if ($prefix == '') {
                return false;
            }

            $dbpass = (string) $sensei->getNodeByReference('general.database.Pass');
            if (substr($dbpass, 0, 4) == 'b64:') {
                $dbpass = base64_decode(substr($dbpass, 4));
            }

            $dbuser = (string) $sensei->getNodeByReference('general.database.User');
            $dbport = (string) $sensei->getNodeByReference('general.database.Port');
            $dburi = (string) $sensei->getNodeByReference('general.database.Host') . ($dbport != '' ? ':' . $dbport : '');

            $arrContextOptions = array(
                "ssl" => array(
                    "verify_peer" => false,
                    "verify_peer_name" => false,
                ),
                "http" => array(
                    "header" => "Content-type: application/json\r\n",
                ),
            );
            if (!empty($dbuser) && !empty($dbpass)) {
                $auth = base64_encode($dbuser . ":" . $dbpass);
                $arrContextOptions["http"]["header"] .= "Authorization: Basic $auth\r\n";
            }
            $hostuuid = trim(shell_exec('/usr/local/sensei/bin/eastpect -s'));

            $arrContextOptions['http']['method'] = 'POST';
            $arrContextOptions['http']['content'] = '{"hostuuid": "' . $hostuuid . '", "prefix": "' . $prefix . '"}';
            $context = stream_context_create($arrContextOptions);
            file_get_contents($dburi . "/indexes/_doc/" . $hostuuid, false, $context);
            return true;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return false;
        }
    }

    public function changePrefixAction()
    {
        $output = '/tmp/sensei_data_path.progress';
        try {
            $prefix = $this->request->getPost('prefix', null, '');
            if ($prefix == '')
                return 'OK';

            if (substr($prefix, -1, 1) != '_') {
                $prefix .= '_';
            }

            $sensei = new Sensei();
            $oldPrefix = (string) $sensei->getNodeByReference('general.database.Prefix');
            $remote = (string) $sensei->getNodeByReference('general.database.Remote');
            $sensei->getNodeByReference('general.database')->setNodes([
                'Prefix' => $prefix,
            ]);
            $sensei->saveChanges();
            $backend = new Backend();
            $backend->configdRun('template reload OPNsense/Sensei');
            $result_index = $backend->configdRun('sensei reporting-index-create ' . $oldPrefix);
            $sensei->logger('sensei reporting-index-create ' . $result_index);
            $result_ipdr = $backend->configdRun('sensei restart-ipdrstreamer');
            $sensei->logger('sensei restart-ipdrstreamer ' . $result_ipdr);
            if (strpos($result_index, 'ERROR') === false) {
                $result_index .= PHP_EOL . '***DONE***';
            }

            file_put_contents($output, $result_index);
            if ($remote == 'true') {
                $this->setIndexes();
            }

            return 'OK';
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return 'Error:' . $e->getMessage();
        }
    }

    public function dbUriSetEmptyAction()
    {
        try {
            $sensei = new Sensei();
            $sensei->setNodes(['general' => ["database" => [
                'Type' => '',
                'Port' => '',
                'Host' => '',
                'Version' => '',
                'Remote' => '',
                'Prefix' => '',
                'User' => '',
                'Pass' => '',
                'ClusterUUID' => '',
                'RetireAfter' => '2',
            ]]]);
            $sensei->saveChanges();
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return 'Error:' . $e->getMessage();
        }
    }

    public function changeDbUriAction()
    {
        try {
            $warning = '';
            $msg = 'Elastic Search Database (%s) cannot be reached. Please check your network connectivity and make sure the remote database is up and running.';
            $dbtype = $this->request->getPost('dbtype', null, '');
            $dburi = $this->request->getPost('dburi', null, '');
            $remote = $this->request->getPost('remote', null, 'false');
            $prefix = $this->request->getPost('prefix', null, '');
            $dbuser = $this->request->getPost('dbuser', null, '');
            $dbpass = $this->request->getPost('dbpass', null, '');
            if (!empty($dburi) && $dbtype == 'ES') {
                $backend = new Backend();
                $license = $backend->configdRun('sensei license-details');
                $licenseArr = (array) json_decode($license);
                if (isset($licenseArr['plan'])) {
                    if ($licenseArr['plan'] == 'opnsense_soho' || $licenseArr['plan'] == 'opnsense_premium' || $licenseArr['plan'] == 'opnsense_business' || (isset($licenseArr['extdata']) && strpos($licenseArr['extdata'], 'homesecops') !== false)) {
                        $hostuuid = trim(shell_exec('/usr/local/sensei/bin/eastpect -s'));
                        $prefix = sha1($hostuuid) . '_';
                    } else {
                        $prefix = '';
                    }
                } else {
                    $prefix = '';
                }

                /*    
            $hostuuid = trim(shell_exec('/usr/local/sensei/bin/eastpect -s'));
            if (!empty($dburi) && $dbtype == 'ES') {
                if (empty($prefix)) {
                    $prefix = 'zenarmor_' . $hostuuid . '_';
                }
            */
                try {
                    $sensei = new Sensei();
                    $sensei->logger('Prepare connect to report database...' . $dburi);
                    $curl = curl_init();
                    curl_setopt($curl, CURLOPT_URL, $dburi);
                    # curl_setopt($curl, CURLOPT_PORT, $config->ElasticSearch->apiEndPointPort);
                    curl_setopt($curl, CURLOPT_HEADER, 0);
                    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
                    curl_setopt($curl, CURLOPT_TIMEOUT, 40);
                    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
                    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
                    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                        'Content-Type: application/json',
                    ));
                    if (!empty($dbuser) && !empty($dbpass)) {
                        curl_setopt($curl, CURLOPT_USERPWD, $dbuser . ':' . $dbpass);
                    }

                    $sensei->logger('trying connect....');
                    $dbinfo = curl_exec($curl);
                    if ($dbinfo === false) {
                        $sensei->logger(sprintf('Error:' . $msg, $dburi));
                        return sprintf('Error:' . $msg, $dburi);
                    }
                    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                    if ($http_code == 401) {
                        $sensei->logger('Username or password is wrong.');
                        return sprintf('Error: Username or password is wrong.');
                    }
                    if ($http_code >= 401) {
                        $sensei->logger('Service Unavailable....Code is ' . $http_code);
                        return sprintf('Error: Service Unavailable');
                    }
                    curl_close($curl);
                    $sensei->logger('Connection is successfully established....');
                    if ($dbinfo !== false) {
                        $es_obj = json_decode($dbinfo);
                        $es_version = str_replace('.', '', $es_obj->version->number);
                        if (isset($es_obj->version->distribution) && $es_obj->version->distribution == 'opensearch') {
                            if (isset($es_obj->version->minimum_wire_compatibility_version))
                                $es_version = str_replace('.', '', $es_obj->version->minimum_wire_compatibility_version);
                        }
                        $es_version = $es_version . str_repeat('0', 5 - strlen($es_version));
                        $dbClusterId = (string) $sensei->getNodeByReference('streamReportDataExternal.ClusterUUID');
                        if ($dbClusterId != '' && $dbClusterId == $es_obj->cluster_uuid) {
                            $warning = 'warning:Report database and external database must not be same.Externel Report Database parameters deleted.';
                            $sensei->setNodes(['streamReportDataExternal' => [
                                'enabled' => 'false',
                                'uri' => '',
                                'server' => '',
                                'port' => '9200',
                                'esVersion' => '',
                                'User' => '',
                                'Pass' => '',
                                'ClusterUUID' => '',
                            ]]);
                        }
                        if (preg_match("/^(http|https):(.*):(\d+)/i", $dburi, $mathes)) {
                            $remote = ($mathes[2] == '//127.0.0.1' || $mathes[2] == '//localhost' ? 'false' : 'true');
                            $es_host = $mathes[1] . ':' . $mathes[2];
                            $es_port = $mathes[3];
                            $sensei->logger("databaseHost => $es_host, databasePort => $es_port, databaseVersion => $es_version, prefix => $prefix");
                            //                        $sensei->getNodeByReference('general')->setNodes(['databaseHost' => $es_host, 'databasePort' => $es_port, 'databaseVersion' => $es_version]);
                            $sensei->setNodes(['general' => ["database" => [
                                'Type' => $dbtype,
                                'Port' => $es_port,
                                'Host' => $es_host,
                                'Version' => $es_version,
                                'Remote' => $remote,
                                'Prefix' => $prefix,
                                'User' => $dbuser,
                                'Pass' => 'b64:' . base64_encode($dbpass),
                                'ClusterUUID' => $es_obj->cluster_uuid,
                                'RetireAfter' => 7,
                            ]]]);

                            $sensei->saveChanges();
                            if ($remote == 'true') {
                                $this->setIndexes();
                            }

                            $backend = new Backend();
                            $backend->configdRun('template reload OPNsense/Sensei');
                            $result_ipdr = $backend->configdRun('sensei restart-ipdrstreamer');
                            $sensei->logger('sensei restart-ipdrstreamer ' . $result_ipdr);
                            return $warning == '' ? 'OK' : $warning;
                        } else {
                            $sensei->logger('Error:' . $dburi . ' is invalid');
                            return 'Error:' . $dburi . ' is invalid';
                        }
                    } else {
                        $sensei->logger(sprintf('Error:' . $msg, $dburi));
                        return sprintf('Error:' . $msg, $dburi);
                    }
                } catch (\Exception $e) {
                    $sensei->logger('Exception ' . __METHOD__ . '->' . $e->getMessage());
                    return sprintf('Error:' . $msg, $dburi);
                }
            }
            return 'Error:missing parameters';
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return 'Error:' . $e->getMessage();
        }
    }

    public function reCreateIndexAction()
    {
        $backend = new Backend();
        return $backend->configdRun('sensei reporting-index-create');
    }

    public function changeDbStatusAction()
    {
        try {
            $fileName = '/tmp/sensei_data_path.progress';
            if (file_exists($fileName)) {
                return ['outputs' => file_get_contents($fileName)];
            } else {
                return ['outputs' => ''];
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return ['outputs' => ''];
        }
    }

    public function scheduleReportListAction()
    {
        try {
            $fileName = '/usr/local/opnsense/scripts/OPNsense/Sensei/report-gen/indices.json';
            if (file_exists($fileName)) {
                header('Content-type:application/json;charset=utf-8');
                echo '{"outputs": ' . file_get_contents($fileName) . '}';
            } else {
                return ['outputs' => ''];
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return ['outputs' => ''];
        }
    }

    public function scheduleReportSaveAction()
    {
        try {
            $fileName = '/usr/local/opnsense/scripts/OPNsense/Sensei/report-gen/indices.json';
            $reports = $this->request->getPost('reports', null, '');
            foreach ($reports as &$val) {
                $val['enabled'] = $val['enabled'] == "true";
            }
            if (!empty($reports)) {
                $size = file_put_contents($fileName, json_encode($reports));
                if ($size > 0) {
                    return ['error' => ''];
                } else {
                    return ['error' => 'Chart list not write to file.'];
                }
            }
            return ['error' => 'Chart list not empty'];
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return ['error' => $e->getMessage()];
        }
    }
    public function scheduleReportSaveOneAction()
    {
        try {
            $fileName = '/usr/local/opnsense/scripts/OPNsense/Sensei/report-gen/indices.json';
            $reports = file_get_contents($fileName);
            $reports = json_decode($reports);
            $reportName = $this->request->getPost('report_name', null, '');
            foreach ($reports as &$val) {
                if ($val->name == $reportName) {
                    $val->enabled = true;
                }
            }
            $size = file_put_contents($fileName, json_encode($reports));
            if ($size > 0) {
                return ['error' => ''];
            } else {
                return ['error' => 'Chart list not write to file.'];
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return ['error' => $e->getMessage()];
        }
    }

    public function tempinfoAction()
    {
        try {
            exec("export BLOCKSIZE=1024;df /dev/md43 | tail -1 | awk '{ print $2\"--\"$5 }'", $output, $return_var);
            if ($return_var == 0) {
                $out = [1024, '0%'];
                if (count($output) > 0 && strpos($output[0], '--') !== false) {
                    $out = explode('--', $output[0]);
                }

                return ['size' => round($out[0] / 1024), 'used' => $out[1], 'error' => ''];
            } else {
                return ['size' => 0, 'used' => 0, 'error' => ''];
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return ['error' => $e->getMessage()];
        }
    }

    public function changeTempSizeAction()
    {
        try {
            $backend = new Backend();
            $newSize = $this->request->getPost('newSize', null, 0);
            $output = '';
            if ($newSize != 0) {
                $output = $backend->configdRun('sensei change-size-temp-folder ' . $newSize);
                if ($output == 'error') {
                    return ['error' => 'could not change temp size.'];
                }

                $sensei = new Sensei();
                $sensei->getNodeByReference('general')->setNodes([
                    'SenseiTempSize' => $newSize,
                ]);
                $sensei->saveChanges();
            }
            return ['error' => ''];
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return ['error' => $e->getMessage()];
        }
    }

    public function logdeleteAction()
    {
        try {
            $backend = new Backend();
            $maxDay = $this->request->getPost('day', null, 30);
            $output = '';
            if ($maxDay > 1) {
                $output = $backend->configdRun('sensei log-delete ' . $maxDay);
                if ($output == 'error') {
                    return ['error' => 'could not log delete for ' . $maxDay];
                }
            }
            return ['error' => ''];
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return ['error' => $e->getMessage()];
        }
    }

    public function ipssignaturesAction()
    {
        try {
            $sensei = new Sensei();
            $lines = [];
            $data = [];
            if (file_exists($sensei->config->files->ipsSignatures)) {
                $lines = file_get_contents($sensei->config->files->ipsSignatures);
                $lines = explode(PHP_EOL, trim($lines));
            }
            foreach ($lines as $i => $line) {
                $l = explode(";", trim($line));
                $data[] = ["index" => $i, "type" => $l[0], "hash" => $l[1], "category" => $l[2], "message" => $l[3], "detail" => $l[4]];
            }
            return ['data' => $data, 'error' => ''];
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return ['data' => [], 'error' => $e->getMessage()];
        }
    }


    public function delsignatureAction()
    {
        try {
            $sensei = new Sensei();
            $index = $this->request->getPost('index', null, -1);
            $hash = $this->request->getPost('hash', null, '');
            $lines = [];
            $find = false;
            if (file_exists($sensei->config->files->ipsSignatures)) {
                $lines = file_get_contents($sensei->config->files->ipsSignatures);
                $lines = explode(PHP_EOL, trim($lines));
            }
            foreach ($lines as $i => $line) {
                $l = explode(";", trim($line));
                if ($i == $index && $l[1] == $hash) {
                    unset($lines[$i]);
                    $find = true;
                }
            }
            $size = file_put_contents($sensei->config->files->ipsSignatures, implode(PHP_EOL, $lines) . ' ' . PHP_EOL);
            if ($size > 0) {
                return ['find' => $find, 'error' => ''];
            }
            return ['find' => $find, 'error' => "Signature can not delete"];
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return ['find' => $find, 'error' => $e->getMessage()];
        }
    }
    public function savesignatureAction()
    {
        try {
            $sensei = new Sensei();
            $data = $this->request->getPost('signature', null, '');
            if (file_exists($sensei->config->files->ipsSignatures)) {
                $lines = file_get_contents($sensei->config->files->ipsSignatures);
                $lines = explode(PHP_EOL, trim($lines));
                foreach ($lines as $i => $line) {
                    $l = explode(";", trim($line));
                    if (count($l) > 1) {
                        if ($data['option'] == 'hash' && $l[1] == $data['hash']) {
                            return ['error' => sprintf('the hash already exists')];
                        }
                        if ($data['option'] == 'ipv4' && $l[1] == $data['ipv4']) {
                            return ['error' => sprintf('the ip already exists')];
                        }
                    }
                }
            }
            if ($data['option'] == 'hash')
                $data = ['filemd5', $data['hash'], $data['category'], isset($data['message']) ? $data['message'] : '', isset($data['detail']) ? $data['detail'] : ''];
            else if ($data['option'] == 'ipv4')
                $data = ['ip', $data['ipv4'], $data['category'], isset($data['message']) ? $data['message'] : '', isset($data['detail']) ? $data['detail'] : ''];

            $size = file_put_contents($sensei->config->files->ipsSignatures, implode(";", $data) . ' ' . PHP_EOL, FILE_APPEND);
            if ($size > 0) {
                return ['error' => ''];
            }
            return ['error' => "Signature can not save"];
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }

            return ['error' => $e->getMessage()];
        }
    }

    public function numberofdeviceAction()
    {
        try {
            $sensei = new Sensei();
            return ['numberofdevice' => $sensei->getNumberofDevice()];
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            }
        }
        return ['numberofdevice' => 0];
    }

    function preparezenarmorAction()
    {
        try {
            /*
            CREATE TABLE interface_settings (id INTEGER PRIMARY KEY AUTOINCREMENT,mode TEXT,name text, lan_interface text,lan_desc text,lan_queue INTEGER,wan_interface text,wan_desc text,wan_queue INTEGER, queue INTEGER , description text,cpu_index INTEGER ,manage_port INTEGER,create_date NUMERIC,tags TEXT);
INSERT INTO interface_settings VALUES(1,'routed',NULL,'em0','LAN (em0)',NULL,NULL,NULL,NULL,NULL,NULL,1,4343,'2022-06-28 17:50:00','wan;netmap;routedmode');
INSERT INTO sqlite_sequence VALUES('interface_settings',1);
*/
            $sensei = new Sensei();
            $backend = new Backend();
            $backend->configdRun('template reload OPNsense/Sensei');
            $result = $backend->configdRun('sensei reporting-index-create');
            $sensei->logger('sensei reporting-index-create ' . $result);
            $result = $backend->configdRun('sensei restart-ipdrstreamer');
            $sensei->logger('sensei restart-ipdrstreamer ' . $result);
            return ['successful' => true, 'message' => 'succesfull'];
        } catch (\Exception $e) {
            $sensei->logger(__METHOD__ . ' Exception ' . $e->getMessage());
            return ['successful' => false, 'message' => $e->getMessage()];
        }
    }

    public function tlsdownloadAction()
    {
        try {
            $path = Sensei::rootDir . '/cert/internal_ca.pem';
            if (file_exists($path)) {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename=internal_ca.pem');
                readfile($path);
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            print '';
        }
    }
}
